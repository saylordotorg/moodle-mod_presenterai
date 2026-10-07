<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_presenterai\local;

/**
 * Carries the custom completion rules into the course module cache.
 *
 * Core's completion API reads custom rules from cm customdata rather than
 * from the instance row, so presenterai_get_coursemodule_info() has to put
 * them there, and only when completion is automatic. The pattern is
 * mod_assign's (mod/assign/lib.php assign_get_coursemodule_info()).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class completion_rules {
    /** @var string[] The rule columns on the presenterai table. */
    public const RULES = ['completionsubmit', 'completionminscore'];

    /**
     * The cached information for one course module.
     *
     * @param \stdClass $coursemodule The course module row, with instance, showdescription and completion.
     * @return \cached_cm_info|null Null when the instance row is missing.
     */
    public static function coursemodule_info(\stdClass $coursemodule): ?\cached_cm_info {
        global $DB;

        $instance = $DB->get_record(
            'presenterai',
            ['id' => $coursemodule->instance],
            'id, name, intro, introformat, completionsubmit, completionminscore'
        );
        if (!$instance) {
            return null;
        }

        $info = new \cached_cm_info();
        $info->name = $instance->name;
        if (!empty($coursemodule->showdescription)) {
            // Not filtered here: filters run when the cached text is displayed.
            $info->content = format_module_intro('presenterai', $instance, $coursemodule->id, false);
        }

        if ((int) ($coursemodule->completion ?? 0) === COMPLETION_TRACKING_AUTOMATIC) {
            $rules = [];
            foreach (self::RULES as $rule) {
                if ((int) $instance->$rule > 0) {
                    $rules[$rule] = (int) $instance->$rule;
                }
            }
            if (!empty($rules)) {
                $info->customdata['customcompletionrules'] = $rules;
            }
        }

        return $info;
    }

    /**
     * Descriptions of the rules in force, for the course completion settings.
     *
     * @param \cm_info|\stdClass $cm The course module, carrying customdata.
     * @return string[]
     */
    public static function active_rule_descriptions($cm): array {
        $customdata = $cm->customdata ?? [];
        $automatic = (int) ($cm->completion ?? 0) === COMPLETION_TRACKING_AUTOMATIC;
        if (!$automatic || empty($customdata['customcompletionrules'])) {
            return [];
        }

        $descriptions = [];
        foreach ($customdata['customcompletionrules'] as $rule => $value) {
            if (empty($value)) {
                continue;
            }
            switch ($rule) {
                case 'completionsubmit':
                    $descriptions[] = get_string('completiondetail:submit', 'mod_presenterai', (int) $value);
                    break;
                case 'completionminscore':
                    $descriptions[] = get_string('completiondetail:minscore', 'mod_presenterai', (int) $value);
                    break;
                default:
                    break;
            }
        }
        return $descriptions;
    }
}
