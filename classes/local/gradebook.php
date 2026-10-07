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
 * The activity's side of the gradebook.
 *
 * The lib.php grade functions are one-line calls into here, so the rules can
 * be read and tested in one place. The shape is the standard one mod_assign
 * and mod_quiz use, with two decisions worth stating:
 *
 * - A grade of 0 means no grade item at all (IMPLEMENTATION-PLAN 6). An
 *   activity that never had an item doesn't get one created just because
 *   grades were pushed; one that had an item has it switched to "none".
 * - A gradebook override is never touched. Core already keeps an overridden
 *   finalgrade when a raw grade is pushed, so nothing here works around it,
 *   and a test proves it stays that way.
 *
 * Only numbers are pushed. Feedback stays in PresenterAI, where the learner
 * reads it alongside the attempt it belongs to.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class gradebook {
    /**
     * Create or update the grade item, optionally pushing grades with it.
     *
     * @param \stdClass $instance The presenterai row, with cmidnumber when known.
     * @param mixed $grades Null, the string 'reset', or grade objects keyed by user id.
     * @return int A GRADE_UPDATE_* constant.
     */
    public static function grade_item_update(\stdClass $instance, $grades = null): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $params = ['itemname' => clean_param((string) $instance->name, PARAM_NOTAGS)];
        if (isset($instance->cmidnumber) && $instance->cmidnumber !== '') {
            $params['idnumber'] = $instance->cmidnumber;
        }

        $grade = (int) ($instance->grade ?? 0);
        if ($grade > 0) {
            $params['gradetype'] = GRADE_TYPE_VALUE;
            $params['grademax'] = $grade;
            $params['grademin'] = 0;
        } else if ($grade < 0) {
            $params['gradetype'] = GRADE_TYPE_SCALE;
            $params['scaleid'] = -$grade;
        } else {
            // No grade. Never create an item for it; only switch off one that exists.
            if (!self::fetch_item($instance)) {
                return GRADE_UPDATE_OK;
            }
            $params['gradetype'] = GRADE_TYPE_NONE;
        }

        if ($grades === 'reset') {
            $params['reset'] = true;
            $grades = null;
        }

        return grade_update(
            'mod/presenterai',
            (int) $instance->course,
            'mod',
            'presenterai',
            (int) $instance->id,
            0,
            $grades,
            $params
        );
    }

    /**
     * Push the grades of one learner, or of everyone, to the gradebook.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int $userid One learner, or 0 for everyone with a counted attempt.
     * @param bool $nullifnone For one learner with nothing to count, push a null grade.
     * @return void
     */
    public static function update_grades(\stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if ((int) ($instance->grade ?? 0) === 0) {
            self::grade_item_update($instance);
            return;
        }

        $grades = self::get_user_grades($instance, $userid);
        if (!empty($grades)) {
            self::grade_item_update($instance, $grades);
        } else if ($userid && $nullifnone) {
            self::grade_item_update($instance, [$userid => (object) ['userid' => $userid, 'rawgrade' => null]]);
        } else {
            self::grade_item_update($instance);
        }
    }

    /**
     * The grades this activity would push, one per learner with a counted attempt.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int $userid One learner, or 0 for everyone with a recording.
     * @return \stdClass[] userid => (object) ['userid', 'rawgrade', 'dategraded', 'datesubmitted', 'usermodified'].
     */
    public static function get_user_grades(\stdClass $instance, int $userid = 0): array {
        global $DB;

        if ($userid > 0) {
            $userids = [$userid];
        } else {
            $userids = $DB->get_fieldset_select(
                'presenterai_recording',
                'DISTINCT userid',
                'presenteraiid = :id',
                ['id' => (int) $instance->id]
            );
        }

        $aggregates = array_filter(grader::aggregate_for_users($instance, $userids));
        if (empty($aggregates)) {
            return [];
        }

        $chosenids = array_map(function (array $aggregate): int {
            return (int) $aggregate['recordingid'];
        }, $aggregates);
        $scores = score_manager::current_scores($chosenids);
        $submitted = [];
        foreach (array_chunk(array_values($chosenids), grader::IN_CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rid');
            $submitted += $DB->get_records_select_menu('presenterai_recording', "id {$insql}", $params, '', 'id, timecreated');
        }

        $grades = [];
        foreach ($aggregates as $uid => $aggregate) {
            $rawgrade = grader::to_rawgrade((float) $aggregate['fraction'], $instance);
            if ($rawgrade === null) {
                continue;
            }
            $recid = (int) $aggregate['recordingid'];
            $score = $scores[$recid] ?? null;
            $graderid = $score ? (int) $score->graderid : 0;
            $grades[$uid] = (object) [
                'userid' => (int) $uid,
                'rawgrade' => $rawgrade,
                'dategraded' => $score ? (int) $score->timecreated : time(),
                'datesubmitted' => (int) ($submitted[$recid] ?? 0),
                'usermodified' => $graderid > 0 ? $graderid : (int) $uid,
            ];
        }
        return $grades;
    }

    /**
     * Delete the grade item.
     *
     * @param \stdClass $instance The presenterai row.
     * @return int A GRADE_UPDATE_* constant.
     */
    public static function grade_item_delete(\stdClass $instance): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        return grade_update(
            'mod/presenterai',
            (int) $instance->course,
            'mod',
            'presenterai',
            (int) $instance->id,
            0,
            null,
            ['deleted' => 1]
        );
    }

    /**
     * Whether one activity grades with a scale.
     *
     * @param int $presenteraiid The instance id.
     * @param int $scaleid The scale id.
     * @return bool
     */
    public static function scale_used(int $presenteraiid, int $scaleid): bool {
        global $DB;
        return $scaleid > 0 && $DB->record_exists('presenterai', ['id' => $presenteraiid, 'grade' => -$scaleid]);
    }

    /**
     * Whether any activity of this module grades with a scale.
     *
     * @param int $scaleid The scale id.
     * @return bool
     */
    public static function scale_used_anywhere(int $scaleid): bool {
        global $DB;
        return $scaleid > 0 && $DB->record_exists('presenterai', ['grade' => -$scaleid]);
    }

    /**
     * Whether the gradebook hides this learner's grade from them.
     *
     * A teacher who hides the grade item, sets it hidden until a date, or hides
     * one learner's grade is holding the result back, and the activity mustn't
     * show it on its own pages or announce it either. mod_assign makes the same
     * check. Someone who can see hidden grades sees through it, and an activity
     * with no grade item has nothing to hide.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int $userid The learner, who is also the one looking.
     * @return bool
     */
    public static function hidden_from(\stdClass $instance, int $userid): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if ((int) ($instance->grade ?? 0) === 0) {
            return false;
        }
        if (has_capability('moodle/grade:viewhidden', \context_course::instance((int) $instance->course), $userid)) {
            return false;
        }
        $info = grade_get_grades((int) $instance->course, 'mod', 'presenterai', (int) $instance->id, $userid);
        $item = $info->items[0] ?? null;
        if (!$item) {
            return false;
        }
        if (!empty($item->hidden)) {
            return true;
        }
        $grade = $item->grades[$userid] ?? null;
        return $grade !== null && !empty($grade->hidden);
    }

    /**
     * The activity's main grade item, if it has one.
     *
     * @param \stdClass $instance The presenterai row.
     * @return \grade_item|false
     */
    private static function fetch_item(\stdClass $instance) {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'presenterai',
            'iteminstance' => (int) $instance->id,
            'itemnumber' => 0,
            'courseid' => (int) $instance->course,
        ]);
    }
}
