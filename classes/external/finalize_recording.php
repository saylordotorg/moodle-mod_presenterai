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
use mod_presenterai\local\attempt_events;
use mod_presenterai\local\recording_manager;

/**
 * Confirm the recording arrived and make the attempt count.
 *
 * A port of Soapbox's soapbox_finalize_recording, which inserted the row; here
 * the row already exists and is updated (D16). No key is accepted: the store
 * is asked about the key the row holds, and the store's answer, not the
 * browser's, is the size recorded.
 *
 * The submitted event and the completion update follow only the call that
 * moves the attempt out of uploading, so a retried finalize never repeats them.
 *
 * visualoptout is the learner's D24 choice. The server honours it only where
 * the activity offers the opt out, and deletes any frame sheet that arrived.
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
            'attempttoken' => new external_value(PARAM_ALPHANUM, 'The token begin_attempt returned with this recording id'),
            'topicid' => new external_value(PARAM_INT, 'Chosen topic id, 0 for none', VALUE_DEFAULT, 0),
            'durationseconds' => new external_value(PARAM_INT, 'Recorded length in seconds', VALUE_DEFAULT, 0),
            'slidetimeline' => new external_value(PARAM_RAW, 'JSON slide-advance timeline', VALUE_DEFAULT, ''),
            'visualoptout' => new external_value(
                PARAM_INT,
                '1 when the learner opted this attempt out of body language feedback',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Finalize the attempt.
     *
     * @param int $recordingid Recording id.
     * @param string $attempttoken The token from begin_attempt.
     * @param int $topicid Topic id or 0.
     * @param int $durationseconds Recorded length.
     * @param string $slidetimeline JSON timeline or empty.
     * @param int $visualoptout 1 when the learner ticked the body language opt out (D24).
     * @return array
     */
    public static function execute(
        int $recordingid,
        string $attempttoken,
        int $topicid = 0,
        int $durationseconds = 0,
        string $slidetimeline = '',
        int $visualoptout = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordingid' => $recordingid,
            'attempttoken' => $attempttoken,
            'topicid' => $topicid,
            'durationseconds' => $durationseconds,
            'slidetimeline' => $slidetimeline,
            'visualoptout' => $visualoptout,
        ]);

        [$rec, $instance, $course, $cm, $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        require_capability('mod/presenterai:submit', $context);
        if ((int) $rec->userid !== (int) $USER->id) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        $transitioned = false;
        $rec = recording_manager::finalize(
            $rec,
            $instance,
            $course,
            $context,
            (int) $params['topicid'],
            (int) $params['durationseconds'],
            (string) $params['slidetimeline'],
            (string) $params['attempttoken'],
            $transitioned,
            !empty($params['visualoptout'])
        );

        // Only the request that made the transition fires it. Two requests
        // racing on one row both read it uploading, and only one wins the lock.
        if ($transitioned) {
            attempt_events::submitted($rec, $course, $cm, $context);
        }

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
