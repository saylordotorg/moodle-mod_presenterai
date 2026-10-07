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
 * The visual feedback gate's rejection log, for site administrators.
 *
 * Design 5.3: the strings the gate withheld from learners in the last seven
 * days, with the layer and rule that withheld each, so the word lists can be
 * tuned. Site administrators only, because the text is unreviewed model
 * output about named learners from every course on the site.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$page = optional_param('page', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/mod/presenterai/gatelog.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('gatelog', 'mod_presenterai'));
$PAGE->set_heading(get_string('gatelog', 'mod_presenterai'));

$renderable = new \mod_presenterai\output\gatelog_page($page);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gatelog', 'mod_presenterai'));
echo $OUTPUT->render_from_template('mod_presenterai/gatelog', $renderable->export_for_template($OUTPUT));
echo $OUTPUT->footer();
