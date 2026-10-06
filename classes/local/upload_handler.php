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

namespace mod_presenterai\local;

use mod_presenterai\local\storage\store_factory;

/**
 * Everything upload.php does after login, sesskey and capability.
 *
 * Kept out of upload.php so it can be tested without an HTTP request: the
 * answers here are the protocol the uploader in the browser is written against,
 * and a wrong status code is a learner whose seven minute recording stalls.
 *
 * The protocol, per chunk: 200 with the new length; 409 with the true offset
 * when the chunk claims to start somewhere else, which is also how a client
 * resumes after a dropped connection; 413 when the recording has grown past
 * the ceiling; 503 when another request holds the upload's lock; 404 for
 * anything that is not this user's unfinished File API upload; 400 for the
 * rest. Chunks are sent one at a time. The plan's "three in flight" cannot
 * work against an append-only file with an offset check, because the second
 * and third would always arrive at the wrong offset.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upload_handler {
    /**
     * Append one chunk to this user's upload.
     *
     * @param \stdClass $cm The course module row the request named.
     * @param \context_module $ctx Its module context.
     * @param int $userid The logged in user.
     * @param int $recordingid The recording the chunk belongs to.
     * @param string $uploadid The upload id start_upload returned.
     * @param int $offset The byte offset the chunk claims to start at.
     * @param resource $stream Open read stream for the chunk body.
     * @return array [int $httpcode, array $payload]
     */
    public static function chunk(
        \stdClass $cm,
        \context_module $ctx,
        int $userid,
        int $recordingid,
        string $uploadid,
        int $offset,
        $stream
    ): array {
        $row = self::find_upload($cm, $userid, $recordingid, $uploadid);
        if ($row === null) {
            return [404, ['error' => 'notfound']];
        }

        try {
            $length = store_factory::for_recording($row)->accept_chunk($uploadid, $offset, $stream);
        } catch (\moodle_exception $e) {
            return self::map_exception($e);
        } catch (\Throwable $e) {
            return [400, ['error' => 'badrequest']];
        }

        return [200, ['offset' => $length]];
    }

    /**
     * How many bytes of this user's upload have landed, for a client resuming.
     *
     * @param \stdClass $cm The course module row the request named.
     * @param \context_module $ctx Its module context.
     * @param int $userid The logged in user.
     * @param int $recordingid The recording the upload belongs to.
     * @param string $uploadid The upload id start_upload returned.
     * @return array [int $httpcode, array $payload]
     */
    public static function offset(\stdClass $cm, \context_module $ctx, int $userid, int $recordingid, string $uploadid): array {
        $row = self::find_upload($cm, $userid, $recordingid, $uploadid);
        if ($row === null) {
            return [404, ['error' => 'notfound']];
        }

        try {
            $offset = store_factory::for_recording($row)->resume_offset($uploadid);
        } catch (\Throwable $e) {
            return [400, ['error' => 'badrequest']];
        }

        return [200, ['offset' => $offset]];
    }

    /**
     * The row this upload belongs to, or null if the request has no business with it.
     *
     * Every condition answers 404 rather than something more specific, because
     * the difference between "not yours" and "does not exist" is information
     * about another learner's attempt. The upload id is compared in constant
     * time: it is the one secret in the request that is not the session.
     *
     * @param \stdClass $cm The course module row the request named.
     * @param int $userid The logged in user.
     * @param int $recordingid The recording id from the request.
     * @param string $uploadid The upload id from the request.
     * @return \stdClass|null
     */
    private static function find_upload(\stdClass $cm, int $userid, int $recordingid, string $uploadid): ?\stdClass {
        global $DB;

        if ($recordingid <= 0 || $uploadid === '') {
            return null;
        }
        $row = $DB->get_record('presenterai_recording', [
            'id' => $recordingid,
            'presenteraiid' => (int) $cm->instance,
            'userid' => $userid,
        ]);
        if (!$row) {
            return null;
        }
        if ((string) $row->status !== recording_manager::STATUS_UPLOADING) {
            return null;
        }
        if ((string) $row->backend !== store_factory::BACKEND_FS) {
            return null;
        }
        if (empty($row->uploadid) || !hash_equals((string) $row->uploadid, $uploadid)) {
            return null;
        }

        return $row;
    }

    /**
     * Turn a store exception into the status code the uploader acts on.
     *
     * @param \moodle_exception $e The exception accept_chunk() threw.
     * @return array [int $httpcode, array $payload]
     */
    private static function map_exception(\moodle_exception $e): array {
        switch ($e->errorcode) {
            case 'error:chunkoffset':
                $actual = is_object($e->a) && isset($e->a->actual) ? (int) $e->a->actual : 0;
                return [409, ['error' => 'offset', 'offset' => $actual]];
            case 'error:uploadtoolarge':
                return [413, ['error' => 'toolarge', 'message' => $e->getMessage()]];
            case 'error:uploadbusy':
                return [503, ['error' => 'busy']];
            case 'error:uploadid':
                return [404, ['error' => 'notfound']];
            default:
                return [400, ['error' => 'badrequest']];
        }
    }
}
