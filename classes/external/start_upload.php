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
 * Tell the browser where to send one piece of media for an unfinished attempt.
 *
 * The key is minted and written to the row here and is not in the response.
 * The response carries only what the browser needs to send bytes: a presigned
 * PUT on S3, or upload.php with an upload id and a chunk size on the File API.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_upload extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording id from begin_attempt'),
            'kind' => new external_value(PARAM_ALPHA, 'What is being uploaded: recording or deck'),
            'ext' => new external_value(PARAM_ALPHANUM, 'File extension without the dot'),
            'sizebytes' => new external_value(PARAM_INT, 'Size the browser is about to send, in bytes'),
            'attempttoken' => new external_value(PARAM_ALPHANUM, 'The token begin_attempt returned with this recording id'),
        ]);
    }

    /**
     * Mint the key and return the upload target.
     *
     * @param int $recordingid Recording id.
     * @param string $kind recording or deck.
     * @param string $ext File extension.
     * @param int $sizebytes Declared size.
     * @param string $attempttoken The token from begin_attempt.
     * @return array
     */
    public static function execute(int $recordingid, string $kind, string $ext, int $sizebytes, string $attempttoken): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordingid' => $recordingid,
            'kind' => $kind,
            'ext' => $ext,
            'sizebytes' => $sizebytes,
            'attempttoken' => $attempttoken,
        ]);

        [$rec, $instance, $course, , $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        require_capability('mod/presenterai:submit', $context);
        // Only the learner who began the attempt may upload into it. The same
        // answer as a missing row, so a recording id says nothing about
        // whether somebody else's attempt exists.
        if ((int) $rec->userid !== (int) $USER->id) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        return recording_manager::start_upload(
            $rec,
            $instance,
            $course,
            $context,
            (string) $params['kind'],
            (string) $params['ext'],
            (int) $params['sizebytes'],
            (string) $params['attempttoken']
        );
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'method' => new external_value(PARAM_ALPHA, 'HTTP method: PUT for S3, POST for chunked upload'),
            'url' => new external_value(PARAM_RAW, 'Where to send the bytes'),
            'uploadid' => new external_value(PARAM_ALPHANUM, 'Chunked upload id, empty on S3'),
            'chunkbytes' => new external_value(PARAM_INT, 'Chunk size to start with, 0 on S3'),
            'expires' => new external_value(PARAM_INT, 'Seconds the URL stays valid, 0 when it does not expire'),
            'maxbytes' => new external_value(PARAM_INT, 'Largest upload accepted, in bytes'),
        ]);
    }
}
