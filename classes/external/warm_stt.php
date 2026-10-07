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
use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\stt_client;

/**
 * A best effort ping that wakes a scale to zero transcription server.
 *
 * A port of SOLA's warm_stt. The recorder calls it when a recording starts,
 * so a self hosted Whisper server (on Cloud Run, say) boots while the learner
 * is still talking and the real transcription does not wait for a cold
 * start. It does nothing unless the admin turned sttwarm on and entered a
 * transcription endpoint. The ping is a GET to the server's base, through
 * http_client so the SSRF check and pinning apply, with short timeouts, and
 * every error is swallowed: warming is never worth failing a request for.
 * Nothing about the endpoint or its key goes back to the browser.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class warm_stt extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Ping the transcription server if warming is on.
     *
     * @param int $cmid Course module id.
     * @return array ['warmed' => bool]
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cm = get_coursemodule_from_id('presenterai', (int) $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance((int) $cm->id);
        self::validate_context($context);
        require_capability('mod/presenterai:submit', $context);

        if (!stt_client::warm_enabled()) {
            return ['warmed' => false];
        }
        $endpoint = trim((string) get_config('mod_presenterai', 'sttendpoint'));
        $base = preg_replace('~/v\d+/audio/transcriptions/?$~', '', $endpoint);
        try {
            http_client::get($base, [], ['connecttimeout' => 3, 'timeout' => 4]);
        } catch (\Throwable $e) {
            debugging('mod_presenterai: transcription warm up ping failed, which is harmless.', DEBUG_DEVELOPER);
        }
        return ['warmed' => true];
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'warmed' => new external_value(PARAM_BOOL, 'Whether a warm up ping was sent'),
        ]);
    }
}
