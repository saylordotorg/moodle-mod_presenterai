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
 * Download one recording, if the requester may take a copy away.
 *
 * The decision is \mod_presenterai\local\access::may_download(), design 7.5.
 * Two backends, two mechanisms. On S3 the browser is sent to a presigned GET
 * with Content-Disposition signed into it, and the event fires here, because
 * nothing on this server sees the bytes. On the File API the browser is sent
 * to pluginfile with forcedownload, which re-checks may_download() on that
 * request and fires the event there, so a pluginfile URL handed to somebody
 * else is not a way round the check.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_presenterai\event\recording_downloaded;
use mod_presenterai\local\access;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\store_factory;

$id = required_param('id', PARAM_INT);

// Everything is derived from the row, never from a cmid in the URL.
[$rec, $instance, $course, $cm, $context] = recording_manager::load($id);

require_login($course, false, $cm);

if ((string) $rec->status === recording_manager::STATUS_UPLOADING) {
    throw new moodle_exception('error:recordingnotfound', 'mod_presenterai');
}

if (empty($rec->storagekey)) {
    throw new moodle_exception('error:nomedia', 'mod_presenterai');
}
if (!access::may_download($rec, $context, (int) $USER->id)) {
    throw new moodle_exception('error:cannotdownload', 'mod_presenterai');
}

$target = recording_manager::download_target($rec);
if ($target['url'] === '') {
    throw new moodle_exception('error:nomedia', 'mod_presenterai');
}

if ($target['kind'] === store_factory::BACKEND_S3) {
    recording_downloaded::create_from_recording($rec, $context)->trigger();
    // Sent raw, never through \core\url, moodle_url or redirect(). All three
    // re-encode the query string, the SigV4 signature covers the query string
    // exactly as signed, and a re-encoded one is answered with
    // SignatureDoesNotMatch.
    header('Cache-Control: no-store');
    header('Location: ' . $target['url'], true, 302);
    die();
}

redirect($target['url']);
