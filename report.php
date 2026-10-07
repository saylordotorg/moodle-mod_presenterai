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
 * The submissions report for one PresenterAI activity.
 *
 * A row per learner the viewer may see, with the latest attempt's status and
 * scores, the overall percentage under the activity's grading method and
 * links to grade each finished attempt. The page is
 * \mod_presenterai\output\report_page; this file checks access and resolves
 * the active group.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'presenterai');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/presenterai:viewallattempts', $context);

$instance = $DB->get_record('presenterai', ['id' => $cm->instance], '*', MUST_EXIST);

$url = new moodle_url('/mod/presenterai/report.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_activity_record($instance);
$PAGE->set_title(get_string('submissions', 'mod_presenterai') . moodle_page::TITLE_SEPARATOR . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

// In separate groups core limits the active group to the viewer's own groups unless
// they hold accessallgroups, and visible_learner_ids() narrows the rows to it.
$groupselector = groups_print_activity_menu($cm, $url, true);
$groupid = (int) groups_get_activity_group($cm, true);

$page = new \mod_presenterai\output\report_page($instance, $course, $cm, $context, (int) $USER->id, $groupid, $groupselector);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('submissions', 'mod_presenterai'));
echo $OUTPUT->render_from_template('mod_presenterai/report', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
