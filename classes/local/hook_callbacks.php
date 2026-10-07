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
 * Callbacks for core hooks.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks {
    /**
     * Delete the course level rubrics of a course that is being deleted.
     *
     * Activity level rubrics go with their activity in
     * presenterai_delete_instance(). A course level rubric belongs to no
     * activity, so without this it would outlive its course, pointing at a
     * context id that no longer exists. Rubrics are created only at module
     * and course level, so there is no category level to clean up.
     *
     * @param \core_course\hook\before_course_deleted $hook The hook.
     * @return void
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        global $DB;

        $context = \context_course::instance((int) $hook->course->id, IGNORE_MISSING);
        if ($context) {
            $DB->delete_records('presenterai_rubric', ['contextid' => (int) $context->id]);
        }
    }
}
