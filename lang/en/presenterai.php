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
 * English strings for mod_presenterai.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'PresenterAI';
$string['modulename'] = 'PresenterAI';
$string['modulenameplural'] = 'PresenterAI activities';
$string['modulename_help'] = 'PresenterAI asks a learner to record a spoken presentation, video or audio only, optionally alongside slides they advance while speaking. The recording is transcribed and scored against a rubric, and the learner reads written feedback on each criterion.

Recordings can be stored in Moodle\'s own file storage or in an S3-compatible bucket, and can be kept until someone removes them or deleted automatically after a set number of days.';
$string['pluginadministration'] = 'PresenterAI administration';

// Instance form.
$string['presenterainame'] = 'Activity name';
$string['presenterainame_help'] = 'The name learners see in the course.';

// Capabilities.
$string['presenterai:addinstance'] = 'Add a new PresenterAI activity';
$string['presenterai:view'] = 'View a PresenterAI activity';
$string['presenterai:submit'] = 'Record and submit a presentation';
$string['presenterai:downloadown'] = 'Download your own recording';
$string['presenterai:viewallattempts'] = 'View other people\'s recordings';
$string['presenterai:grade'] = 'Grade presentations';
$string['presenterai:deleteanyrecording'] = 'Delete any recording';
$string['presenterai:managerubrics'] = 'Manage scoring rubrics';
$string['presenterai:useai'] = 'Have AI feedback generated for your attempts';

// Index page.
$string['nopresenterais'] = 'There are no PresenterAI activities in this course.';

// Privacy. Declared now so the strings exist before the provider is written,
// and so nobody is tempted to ship a provider whose reasons are only in code.
$string['privacy:metadata:presenterai_recording'] = 'A recorded presentation attempt: the media reference, its length, its state and the transcript produced from it.';
$string['privacy:metadata:presenterai_recording:userid'] = 'The learner who made the recording.';
$string['privacy:metadata:presenterai_recording:transcript'] = 'The text transcribed from the recording. It is kept after the media is deleted, because it is the learner\'s record of what they said.';
$string['privacy:metadata:presenterai_score'] = 'One scored judgement of one attempt, by AI or by a teacher, with the per-criterion marks and written feedback.';
$string['privacy:metadata:presenterai_score:userid'] = 'The learner whose attempt was scored.';
