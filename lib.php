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
 * Module API for mod_presenterai.
 *
 * Phase 0 skeleton: instance lifecycle and feature declaration only. The grade
 * functions arrive in phase 2 and are deliberately absent rather than stubbed,
 * because presenterai_grade_item_update() existing but doing nothing is worse
 * than it not existing: core calls it and believes it.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declare which optional features this module supports.
 *
 * @param string $feature One of the FEATURE_* constants.
 * @return mixed True or false for a boolean feature, a string for a constant, null if unknown.
 */
function presenterai_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_PURPOSE:
            // The learner produces something and is assessed on it.
            return MOD_PURPOSE_ASSESSMENT;

        // Phase 2. Declared false rather than omitted so the intent is visible:
        // these are coming, and the grade functions land with them.
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_COMPLETION_HAS_RULES:
            return false;

        // Deliberately false in v1. Our rubric carries per-criterion prompt text
        // the model reads, which core's grading form definitions cannot hold.
        case FEATURE_ADVANCED_GRADING:
            return false;

        default:
            return null;
    }
}

/**
 * Create a new activity instance.
 *
 * @param stdClass $data Form data from mod_form.
 * @param mod_presenterai_mod_form|null $mform The form, unused here.
 * @return int The new instance id.
 */
function presenterai_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    return (int) $DB->insert_record('presenterai', $data);
}

/**
 * Update an existing activity instance.
 *
 * @param stdClass $data Form data from mod_form, carrying the instance id in `instance`.
 * @param mod_presenterai_mod_form|null $mform The form, unused here.
 * @return bool Always true; a failed write throws.
 */
function presenterai_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('presenterai', $data);

    return true;
}

/**
 * Delete an activity instance and everything hanging off it.
 *
 * Children before parents, because the child rows resolve through the parent and
 * an orphaned child is unreachable from either side. Media objects are deleted
 * by the storage layer in phase 1; this function will call it rather than
 * leaving the bytes behind, which is the defect this project inherited from
 * Soapbox and is not going to repeat.
 *
 * @param int $id The instance id.
 * @return bool Always true.
 */
function presenterai_delete_instance($id) {
    global $DB;

    $instance = $DB->get_record('presenterai', ['id' => $id]);
    if (!$instance) {
        return true;
    }

    $recordingids = $DB->get_fieldset_select(
        'presenterai_recording',
        'id',
        'presenteraiid = :id',
        ['id' => $id]
    );
    if (!empty($recordingids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'rid');
        $DB->delete_records_select('presenterai_score', "recordingid {$insql}", $inparams);
    }

    $DB->delete_records('presenterai_recording', ['presenteraiid' => $id]);
    $DB->delete_records('presenterai_topic', ['presenteraiid' => $id]);
    $DB->delete_records('presenterai', ['id' => $id]);

    return true;
}

/**
 * Serve a file from one of this module's file areas.
 *
 * Follows folder_pluginfile() (mod/folder/lib.php:261-294) in shape, and departs
 * from it in three places that matter:
 *
 * 1. Authorisation is per request, which is the property that makes the
 *    filesystem backend different from a presigned S3 URL. A learner who is
 *    unenrolled, or whose capability is revoked, stops being able to play the
 *    recording on their next request rather than when a signature expires.
 * 2. The recording row is loaded and checked against the URL. The itemid and the
 *    filename in the URL are only a claim until the row that owns the media
 *    agrees with them.
 * 3. Cacheability is stated rather than left to default. With a lifetime of 0
 *    send_file() already emits a private, non-cacheable response
 *    (lib/filelib.php:2617-2627), so this is not what makes the response private
 *    today. It is stated because the default for a non-zero lifetime is public
 *    (lib/filelib.php:2601-2609), and a shared proxy holding a copy of a
 *    learner's face and voice is not a mistake worth leaving one edit away.
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module.
 * @param context $context The module context.
 * @param string $filearea The file area being requested.
 * @param array $args Remaining URL path segments: itemid then the filename.
 * @param bool $forcedownload Whether the file should download rather than play inline.
 * @param array $options Options passed through to send_stored_file().
 * @return bool False if the file is not found or not permitted; otherwise does not return.
 */
function mod_presenterai_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    global $CFG, $DB, $USER;

    require_once($CFG->libdir . '/filelib.php');

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // The column on the recording row that must agree with the requested
    // filename, per file area. An area not listed here is not ours to serve.
    $keycolumns = [
        'recording' => 'storagekey',
        'deck' => 'deckkey',
        'frames' => 'frameskey',
    ];
    if (!isset($keycolumns[$filearea])) {
        return false;
    }

    require_login($course, false, $cm);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    if ($itemid <= 0 || $filename === null || $filename === '') {
        return false;
    }
    // The fs_store backend writes every file at the root of its area, so anything left
    // between the itemid and the filename is not a path this module created.
    if (!empty($args)) {
        return false;
    }

    $recording = $DB->get_record(
        'presenterai_recording',
        ['id' => $itemid, 'presenteraiid' => $cm->instance],
        'id, userid, storagekey, deckkey, frameskey'
    );
    if (!$recording) {
        return false;
    }

    // The row is the authority on which bytes belong to this attempt. Without
    // this, any file that ever existed in the area could be fetched by naming it.
    if ((string) $recording->{$keycolumns[$filearea]} !== (string) $filename) {
        return false;
    }

    if ((int) $recording->userid !== (int) $USER->id) {
        require_capability('mod/presenterai:viewallattempts', $context);
    } else if ($forcedownload) {
        // Keeping a copy is a separate decision from watching it back, so a site
        // can show a learner their own recording without letting it leave.
        require_capability('mod/presenterai:downloadown', $context);
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_presenterai', $filearea, $itemid, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    if (!$forcedownload) {
        // The bytes came from a browser, so they are learner supplied. Served
        // inline they must not be able to reach anything else on the site.
        header("Content-Security-Policy: default-src 'none'; media-src 'self'; img-src 'self'");
    } else {
        // The fs_store key is a random token, which is not a filename anyone wants
        // saved. read_url() puts the intended name here and it is re-cleaned
        // rather than trusted, because it arrives from the URL.
        $downloadname = optional_param('dl', '', PARAM_FILE);
        if ($downloadname !== '') {
            $options['filename'] = $downloadname;
        }
    }

    $options['cacheability'] = 'private';

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
