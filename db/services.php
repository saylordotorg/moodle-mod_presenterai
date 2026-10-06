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
 * Web service functions for mod_presenterai.
 *
 * Every function checks the recording's ownership in code; the capability
 * listed here is only the first gate.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_presenterai_begin_attempt' => [
        'classname' => 'mod_presenterai\external\begin_attempt',
        'description' => 'Start, or resume, an attempt for the current user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/presenterai:submit',
    ],
    'mod_presenterai_start_upload' => [
        'classname' => 'mod_presenterai\external\start_upload',
        'description' => 'Get the upload target for an attempt\'s recording or slides.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/presenterai:submit',
    ],
    'mod_presenterai_render_deck' => [
        'classname' => 'mod_presenterai\external\render_deck',
        'description' => 'Render an attempt\'s slide deck to page images.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/presenterai:submit',
    ],
    'mod_presenterai_finalize_recording' => [
        'classname' => 'mod_presenterai\external\finalize_recording',
        'description' => 'Finish an attempt once its recording has uploaded.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/presenterai:submit',
    ],
    'mod_presenterai_get_playback' => [
        'classname' => 'mod_presenterai\external\get_playback',
        'description' => 'Get what is needed to play back a recording.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/presenterai:view',
    ],
    'mod_presenterai_delete_recording' => [
        'classname' => 'mod_presenterai\external\delete_recording',
        'description' => 'Delete a recording\'s media, keeping the attempt and its score.',
        'type' => 'write',
        'ajax' => true,
        // Checked in code: mod/presenterai:deleteownmedia for one's own,
        // mod/presenterai:deleteanyrecording for anyone else's.
        'capabilities' => '',
    ],
];
