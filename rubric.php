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
 * The rubric editor: list, create, edit and delete rubrics from an activity.
 *
 * Reached from the activity, so the page always has a course module to log
 * in against. A rubric is created in the activity's context, or with
 * level=course in the course's when the user holds
 * mod/presenterai:managerubrics there. A rubric id in the URL is checked by
 * rubrics_page::require_editable() against those two contexts and nothing
 * else, so a teacher can't reach another course's rubric by guessing an id.
 *
 * Deleting asks first, and the deletion itself is a POST carrying the
 * sesskey. "Start from a preset" is a no-submit button: the page reloads with
 * the preset's criteria filled in, and nothing is saved until Save.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_presenterai\form\rubric_form;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\output\rubrics_page;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$rubricid = optional_param('rubricid', 0, PARAM_INT);
$level = optional_param('level', rubrics_page::LEVEL_MODULE, PARAM_ALPHA);
$level = $level === rubrics_page::LEVEL_COURSE ? rubrics_page::LEVEL_COURSE : rubrics_page::LEVEL_MODULE;

[$course, $cm] = get_course_and_cm_from_cmid($id, 'presenterai');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/presenterai:managerubrics', $context);
$instance = $DB->get_record('presenterai', ['id' => $cm->instance], '*', MUST_EXIST);

$baseurl = new moodle_url('/mod/presenterai/rubric.php', ['id' => $cm->id]);
$pageurl = new moodle_url($baseurl);
if ($action !== '') {
    $pageurl->param('action', $action);
}
if ($rubricid > 0) {
    $pageurl->param('rubricid', $rubricid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_activity_record($instance);
$title = get_string('rubrics', 'mod_presenterai');
$PAGE->set_title($title . moodle_page::TITLE_SEPARATOR . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add($title, $baseurl);

if ($action === 'delete') {
    $row = rubrics_page::require_editable($rubricid, $context, (int) $USER->id);
    if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted()) {
        rubrics_page::delete((int) $row->id, $context, (int) $USER->id);
        redirect($baseurl, get_string('rubricdeleted', 'mod_presenterai'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    $continue = new moodle_url($baseurl, [
        'action' => 'delete',
        'rubricid' => (int) $row->id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('rubricdelete', 'mod_presenterai'));
    echo $OUTPUT->confirm(
        get_string('rubricdeleteconfirm', 'mod_presenterai', format_string($row->title, true, ['context' => $context])),
        $continue,
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'edit') {
    $row = null;
    if ($rubricid > 0) {
        $row = rubrics_page::require_editable($rubricid, $context, (int) $USER->id);
    } else {
        // Checks the capability in the context the new rubric will belong to.
        rubrics_page::target_context($context, $level, (int) $USER->id);
    }

    $preset = optional_param('preset', '', PARAM_ALPHANUMEXT);
    $preset = in_array($preset, rubric_manager::LEVELS, true) ? $preset : '';
    $type = $row ? (string) $row->type : optional_param('type', '', PARAM_ALPHA);
    $type = in_array($type, [rubric_manager::TYPE_SPEECH, rubric_manager::TYPE_VIDEO], true) ? $type : '';

    $criteria = [];
    if ($preset !== '') {
        $criteria = rubric_manager::preset_criteria($preset, $type === rubric_manager::TYPE_VIDEO);
    } else if ($row) {
        $criteria = rubric_manager::normalise_criteria((string) $row->criteria);
    }

    $hidden = ['id' => (int) $cm->id, 'action' => 'edit', 'rubricid' => $row ? (int) $row->id : 0, 'level' => $level];
    $editurl = new moodle_url($baseurl, ['action' => 'edit']);
    $form = new rubric_form($editurl, [
        'rows' => rubric_form::rows_for($criteria),
        'fixedtype' => $row ? (string) $row->type : '',
        'hidden' => $hidden,
    ]);

    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($form->no_submit_button_pressed() && optional_param('applypreset', '', PARAM_RAW) !== '') {
        require_sesskey();
        // Reload as a GET, so the preset's rows aren't overridden by the empty ones just posted.
        redirect(new moodle_url($editurl, [
            'rubricid' => $row ? (int) $row->id : 0,
            'level' => $level,
            'preset' => optional_param('preset', '', PARAM_ALPHANUMEXT),
            'type' => $row ? (string) $row->type : optional_param('type', '', PARAM_ALPHA),
            'title' => optional_param('title', '', PARAM_TEXT),
            'active' => optional_param('active', 1, PARAM_INT),
        ]));
    }
    if ($data = $form->get_data()) {
        $criteria = $form->to_criteria($data);
        if ($row) {
            rubric_manager::update((int) $row->id, (string) $data->title, $criteria, !empty($data->active));
        } else {
            $target = rubrics_page::target_context($context, $level, (int) $USER->id);
            $newid = rubric_manager::create((int) $target->id, $form->to_type($data), (string) $data->title, $criteria);
            if (empty($data->active)) {
                rubric_manager::update($newid, (string) $data->title, $criteria, false);
            }
        }
        redirect($baseurl, get_string('rubricsaved', 'mod_presenterai'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($row && $preset === '') {
        $form->set_rubric((string) $row->title, (string) $row->type, !empty($row->active), $criteria);
    } else if ($row) {
        $form->set_rubric(
            optional_param('title', (string) $row->title, PARAM_TEXT),
            (string) $row->type,
            (bool) optional_param('active', (int) $row->active, PARAM_INT),
            $criteria
        );
    } else {
        $form->set_rubric(
            optional_param('title', '', PARAM_TEXT),
            $type,
            (bool) optional_param('active', 1, PARAM_INT),
            $criteria
        );
    }

    $heading = get_string($row ? 'rubricedittitle' : 'rubricnew', 'mod_presenterai');
    $PAGE->navbar->add($heading);
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    if (!$row && $level === rubrics_page::LEVEL_COURSE) {
        echo html_writer::tag('p', get_string('rubricnew_course', 'mod_presenterai'));
    }
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$page = new rubrics_page($context, (int) $USER->id);
echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template('mod_presenterai/rubrics', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
