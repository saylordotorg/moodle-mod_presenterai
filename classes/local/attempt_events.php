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
 * What happens once an attempt is submitted.
 *
 * Kept out of recording_manager::finalize(), which runs under the attempt lock
 * and returns early on a retry, so the event and the completion update happen
 * once, in the caller that knows the attempt has just moved from uploading to
 * finalized.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_events {
    /**
     * Fire recording_submitted and update the learner's completion.
     *
     * @param \stdClass $rec The presenterai_recording row, as finalized.
     * @param \stdClass $course The course row.
     * @param \stdClass $cm The course module.
     * @param \context_module $ctx The activity's module context.
     * @return void
     */
    public static function submitted(\stdClass $rec, \stdClass $course, \stdClass $cm, \context_module $ctx): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        \mod_presenterai\event\recording_submitted::create_from_recording($rec, $ctx)->trigger();

        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, (int) $rec->userid);
        }
    }
}
