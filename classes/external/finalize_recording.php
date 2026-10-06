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

namespace mod_presenterai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_presenterai\local\recording_manager;

/**
 * Confirm the recording arrived and make the attempt count.
 *
 * A port of Soapbox's soapbox_finalize_recording, which inserted the row; here
 * the row already exists and is updated (D16). No key is accepted: the store
 * is asked about the key the row holds, and the store's answer, not the
 * browser's, is the size recorded.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_recording extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording id from begin_attempt'),
            'topicid' => new external_value(PARAM_INT, 'Chosen topic id, 0 for none', VALUE_DEFAULT, 0),
            'durationseconds' => new external_value(PARAM_INT, 'Recorded length in seconds', VALUE_DEFAULT, 0),
            'slidetimeline' => new external_value(PARAM_RAW, 'JSON slide-advance timeline', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Finalize the attempt.
     *
     * @param int $recordingid Recording id.
     * @param int $topicid Topic id or 0.
     * @param int $durationseconds Recorded length.
     * @param string $slidetimeline JSON timeline or empty.
     * @return array
     */
    public static function execute(
        int $recordingid,
        int $topicid = 0,
        int $durationseconds = 0,
        string $slidetimeline = ''
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordingid' => $recordingid,
            'topicid' => $topicid,
            'durationseconds' => $durationseconds,
            'slidetimeline' => $slidetimeline,
        ]);

        [$rec, $instance, $course, , $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        require_capability('mod/presenterai:submit', $context);
        if ((int) $rec->userid !== (int) $USER->id) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        $rec = recording_manager::finalize(
            $rec,
            $instance,
            $course,
            $context,
            (int) $params['topicid'],
            (int) $params['durationseconds'],
            (string) $params['slidetimeline']
        );

        return [
            'recordingid' => (int) $rec->id,
            'status' => (string) $rec->status,
            'attemptnumber' => (int) $rec->attemptnumber,
            'expiresat' => (int) $rec->expiresat,
            'sizebytes' => (int) $rec->sizebytes,
        ];
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordingid' => new external_value(PARAM_INT, 'Recording id'),
            'status' => new external_value(PARAM_ALPHA, 'Attempt status'),
            'attemptnumber' => new external_value(PARAM_INT, 'Which attempt this is'),
            'expiresat' => new external_value(PARAM_INT, 'When the media is deleted, 0 for never'),
            'sizebytes' => new external_value(PARAM_INT, 'Stored size in bytes'),
        ]);
    }
}
