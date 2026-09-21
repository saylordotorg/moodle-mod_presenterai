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

/**
 * Main view for one PresenterAI activity.
 *
 * Phase 0: the intro only. The recorder, the attempt list and the feedback panel
 * arrive in phases 1 to 3.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$p  = optional_param('p', 0, PARAM_INT);

if ($p) {
    [$course, $cm] = get_course_and_cm_from_instance($p, 'presenterai');
} else {
    [$course, $cm] = get_course_and_cm_from_cmid($id, 'presenterai');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/presenterai:view', $context);

$instance = $DB->get_record('presenterai', ['id' => $cm->instance], '*', MUST_EXIST);

$event = \core\event\course_module_viewed::create([
    'objectid' => $cm->instance,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('presenterai', $instance);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/presenterai/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (trim(strip_tags($instance->intro ?? ''))) {
    echo $OUTPUT->box(format_module_intro('presenterai', $instance, $cm->id), 'generalbox', 'intro');
}

echo $OUTPUT->footer();
