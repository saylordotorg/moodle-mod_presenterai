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
 * Chunked upload endpoint for the Moodle file storage backend.
 *
 * Not a web service, on purpose (plan section 4.4): the AJAX transport is
 * JSON, so binary through it means base64 and a third more bytes on a
 * learner's uplink. The body is the raw chunk, application/octet-stream,
 * and everything else travels in the query string:
 *
 * POST ?id={cmid}&recordingid={id}&uploadid={id}&offset={bytes}&sesskey={key}
 * GET  ?id={cmid}&recordingid={id}&uploadid={id}&action=offset&sesskey={key}
 *
 * All the logic is in \mod_presenterai\local\upload_handler, which documents
 * the status codes. This file only authenticates and speaks HTTP.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use mod_presenterai\local\upload_handler;

$id = required_param('id', PARAM_INT);
$recordingid = required_param('recordingid', PARAM_INT);
$uploadid = required_param('uploadid', PARAM_ALPHANUM);
$offset = optional_param('offset', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('presenterai', $id, 0, false, MUST_EXIST);
$course = get_course((int) $cm->course);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_sesskey();
require_capability('mod/presenterai:submit', $context);

// A chunk can take a minute on a slow uplink, and holding the session lock that
// long would freeze every other tab the learner has open on the site.
\core\session\manager::write_close();

if ($action === 'offset') {
    [$code, $payload] = upload_handler::offset($cm, $context, (int) $USER->id, $recordingid, $uploadid);
} else if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        [$code, $payload] = [400, ['error' => 'badrequest']];
    } else {
        try {
            [$code, $payload] = upload_handler::chunk($cm, $context, (int) $USER->id, $recordingid, $uploadid, $offset, $stream);
        } finally {
            fclose($stream);
        }
    }
} else {
    [$code, $payload] = [400, ['error' => 'badrequest']];
}

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($code === 503) {
    header('Retry-After: 2');
}
echo json_encode($payload);
