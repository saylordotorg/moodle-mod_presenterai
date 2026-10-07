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

declare(strict_types=1);

namespace mod_presenterai\completion;

use mod_presenterai\local\grader;
use mod_presenterai\local\recording_manager;

/**
 * PresenterAI's two completion rules.
 *
 * completionsubmit counts attempts that were finalized, whatever happened to
 * them afterward: a scored attempt or one whose media expired was still
 * submitted. completionminscore reads the same aggregate the gradebook uses
 * (grader), so the two can't disagree about a learner's number, and it works
 * on an activity with no grade item, because the percentage comes from the
 * score rows rather than the gradebook. A gradebook override changes the grade,
 * not this.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends \core_completion\activity_custom_completion {
    /**
     * The completion state of one rule for the user.
     *
     * @param string $rule The rule name.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        $required = (int) ($rules[$rule] ?? 0);
        if ($required <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        if ($rule === 'completionsubmit') {
            [$notin, $params] = $DB->get_in_or_equal(recording_manager::COUNTED_EXCLUDED, SQL_PARAMS_NAMED, 'st', false);
            $params['presenteraiid'] = (int) $this->cm->instance;
            $params['userid'] = $this->userid;
            $count = $DB->count_records_select(
                'presenterai_recording',
                "presenteraiid = :presenteraiid AND userid = :userid AND status {$notin}",
                $params
            );
            return $count >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        $instance = $DB->get_record('presenterai', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $aggregate = grader::aggregate_for_user($instance, $this->userid);
        return ($aggregate !== null && $aggregate['pct'] >= $required) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The rules this module defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionsubmit', 'completionminscore'];
    }

    /**
     * What each rule asks of the learner, for the activity's completion details.
     *
     * @return string[] Rule name => description.
     */
    public function get_custom_rule_descriptions(): array {
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_presenterai', (int) ($rules['completionsubmit'] ?? 0)),
            'completionminscore' => get_string(
                'completiondetail:minscore',
                'mod_presenterai',
                (int) ($rules['completionminscore'] ?? 0)
            ),
        ];
    }

    /**
     * The order rules are shown in.
     *
     * Core throws when a rule that applies to the activity is missing here, and
     * the grade rules apply whenever the activity has a grade item, so they're
     * listed too.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionsubmit', 'completionminscore', 'completionusegrade', 'completionpassgrade'];
    }
}
