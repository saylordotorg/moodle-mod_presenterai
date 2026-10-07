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
 * Core outcomes on the grading screen, set by hand the way mod_assign sets them.
 *
 * An outcome is attached to the activity, not to an attempt, so its value is
 * one per learner in the gradebook and the grading screen of any of their
 * attempts shows and sets that same value. Core creates and owns the outcome
 * grade items (itemnumber 1000 upward) from the standard activity form; this
 * class only reads them and writes values a grader chose. Nothing here is
 * derived from a score or from AI output: docs/SPIKE-outcomes.md explains why
 * mapping rubric criteria onto mastery outcomes is deferred.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grading_outcomes {
    /**
     * The outcomes attached to the activity, with the learner's current value.
     *
     * @param \stdClass $course The course row.
     * @param \stdClass $instance The presenterai row.
     * @param int $userid The learner.
     * @return array List of ['itemnumber' => int, 'name' => string,
     *               'options' => [int => string], 'current' => int]. Empty when
     *               outcomes are off for the site or none are attached.
     */
    public static function for_user(\stdClass $course, \stdClass $instance, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if (empty($CFG->enableoutcomes)) {
            return [];
        }

        $info = grade_get_grades((int) $course->id, 'mod', 'presenterai', (int) $instance->id, $userid);
        if (empty($info->outcomes)) {
            return [];
        }

        $out = [];
        foreach ($info->outcomes as $itemnumber => $outcome) {
            // Below 1 is "no outcome" to grade_update_outcomes(), so 0 is the
            // empty choice and the scale items are numbered from 1.
            $options = [0 => get_string('nooutcome', 'grades')];
            $scale = \grade_scale::fetch(['id' => (int) $outcome->scaleid]);
            if ($scale) {
                $scale->load_items();
                foreach (array_values($scale->scale_items) as $i => $item) {
                    $options[$i + 1] = format_string($item);
                }
            }

            $current = 0;
            if (isset($outcome->grades[$userid]) && $outcome->grades[$userid]->grade !== false) {
                $current = (int) $outcome->grades[$userid]->grade;
            }

            $out[] = [
                'itemnumber' => (int) $itemnumber,
                'name' => format_string($outcome->name),
                'options' => $options,
                'current' => $current,
            ];
        }

        return $out;
    }

    /**
     * Save the outcome values a grader chose, sending only the ones that changed.
     *
     * Sending an unchanged value would still rewrite the grade's usermodified
     * and timemodified, so mod_assign compares first and so does this.
     *
     * @param \stdClass $course The course row.
     * @param \stdClass $instance The presenterai row.
     * @param int $userid The learner.
     * @param array $submitted itemnumber => value; a value below 1 means none.
     * @return void
     */
    public static function save(\stdClass $course, \stdClass $instance, int $userid, array $submitted): void {
        $changed = [];
        foreach (self::for_user($course, $instance, $userid) as $outcome) {
            $itemnumber = $outcome['itemnumber'];
            if (!array_key_exists($itemnumber, $submitted)) {
                continue;
            }
            $value = max(0, (int) $submitted[$itemnumber]);
            if (!array_key_exists($value, $outcome['options'])) {
                // Not a value of this outcome's scale: ignore it rather than store it.
                continue;
            }
            if ($value !== $outcome['current']) {
                $changed[$itemnumber] = $value;
            }
        }

        if ($changed) {
            grade_update_outcomes(
                'mod/presenterai',
                (int) $course->id,
                'mod',
                'presenterai',
                (int) $instance->id,
                $userid,
                $changed
            );
        }
    }
}
