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
 * Score one attempt by hand: the recording, its transcript and the rubric form.
 *
 * The recording id in the URL is only a claim until
 * access::require_gradable_recording() has confirmed the row belongs to this
 * course module's instance, that group mode lets this grader reach the learner
 * and that the attempt finished uploading. Saving goes through
 * score_manager::save_teacher_score(), which owns the score row, the
 * gradebook, completion, the event and the learner's message, and then
 * through grading_outcomes for any outcomes attached to the activity.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_presenterai\form\grade_form;
use mod_presenterai\local\access;
use mod_presenterai\local\grading_outcomes;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\score_manager;

$id = required_param('id', PARAM_INT);
$recordingid = required_param('recordingid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'presenterai');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/presenterai:grade', $context);

$rec = access::require_gradable_recording($cm, $context, $recordingid, (int) $USER->id);
$instance = $DB->get_record('presenterai', ['id' => $cm->instance], '*', MUST_EXIST);

$url = new moodle_url('/mod/presenterai/grade.php', ['id' => $cm->id, 'recordingid' => $rec->id]);
$reporturl = new moodle_url('/mod/presenterai/report.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_activity_record($instance);

$rubric = rubric_manager::resolve($instance, $context);
$current = score_manager::current_score((int) $rec->id);
$outcomes = grading_outcomes::for_user($course, $instance, (int) $rec->userid);

$form = new grade_form($url, [
    'cmid' => (int) $cm->id,
    'recordingid' => (int) $rec->id,
    'criteria' => $rubric['criteria'],
    'outcomes' => $outcomes,
]);
$form->set_prefill($current);

if ($form->is_cancelled()) {
    redirect($reporturl);
} else if ($data = $form->get_data()) {
    score_manager::save_teacher_score(
        $rec,
        $instance,
        $context,
        (int) $USER->id,
        (int) $rubric['rubricid'],
        $form->to_criteria($data),
        (string) ($data->feedback ?? '')
    );
    grading_outcomes::save($course, $instance, (int) $rec->userid, $form->to_outcomes($data));

    redirect($url, get_string('grade_saved', 'mod_presenterai'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$learner = $DB->get_record('user', ['id' => $rec->userid], '*', MUST_EXIST);
$title = get_string('gradeattempt', 'mod_presenterai', (object) [
    'name' => fullname($learner),
    'attempt' => (int) $rec->attemptnumber,
]);
$PAGE->set_title($title . moodle_page::TITLE_SEPARATOR . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('submissions', 'mod_presenterai'), $reporturl);
$PAGE->navbar->add($title);

// Only the selector: the recording id and the player label travel as data
// attributes on the root, which keeps the call under js_call_amd()'s 1024
// character warning.
$PAGE->requires->js_call_amd('mod_presenterai/grading', 'init', ['#' . \mod_presenterai\output\grade_page::ROOT_ID]);

$page = new \mod_presenterai\output\grade_page($instance, $rec, $course, $cm, $context, (int) $USER->id, $form->render());

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_presenterai/grade', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
