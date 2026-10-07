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
 * The module API, with grade, completion and reset logic living in classes/local.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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

        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;

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

    $data = \mod_presenterai\local\instance_manager::normalise($data, null);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = (int) $DB->insert_record('presenterai', $data);

    \mod_presenterai\local\topic_manager::save_from_form(
        $data,
        (int) $data->id,
        \context_module::instance($data->coursemodule)
    );

    presenterai_grade_item_update($data);

    return $data->id;
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

    $existing = $DB->get_record('presenterai', ['id' => $data->instance], '*', MUST_EXIST);
    $data = \mod_presenterai\local\instance_manager::normalise($data, $existing);
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('presenterai', $data);

    \mod_presenterai\local\topic_manager::save_from_form(
        $data,
        (int) $data->instance,
        \context_module::instance($data->coursemodule)
    );

    $instance = $DB->get_record('presenterai', ['id' => $data->instance], '*', MUST_EXIST);
    $instance->cmidnumber = $data->cmidnumber ?? '';
    presenterai_grade_item_update($instance);

    // D28: with review switched off nobody is going to release what's held,
    // so it all goes to the learners now, with the grade, completion and the
    // message each would have had.
    if (!empty($existing->reviewbeforerelease) && empty($instance->reviewbeforerelease)) {
        \mod_presenterai\local\score_manager::release_all($instance, \context_module::instance($data->coursemodule));
    }

    presenterai_update_grades($instance, 0, false);

    // Core resets stored completion only when the completion settings change.
    if ((string) $existing->gradingmethod !== (string) $instance->gradingmethod) {
        \mod_presenterai\local\completion_rules::refresh_minscore_state($instance, (int) $data->coursemodule);
    }

    return true;
}

/**
 * Delete an activity instance and everything hanging off it.
 *
 * Children before parents, because the child rows resolve through the parent and
 * an orphaned child is unreachable from either side. Media objects are deleted
 * through the storage layer first, while the rows that reference them still
 * exist, rather than leaving the bytes behind, which is the defect this project
 * inherited from Soapbox and is not going to repeat.
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

    presenterai_grade_item_delete($instance);

    // Before any row goes: fs_store finds a file through the row that names it.
    // Best effort per row, and it never throws.
    \mod_presenterai\local\recording_manager::delete_all_media_for_instance((int) $instance->id);

    $recordingids = $DB->get_fieldset_select(
        'presenterai_recording',
        'id',
        'presenteraiid = :id',
        ['id' => $id]
    );
    if (!empty($recordingids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'rid');
        $DB->delete_records_select('presenterai_score', "recordingid {$insql}", $inparams);
        // The strings the visual gate withheld are about these attempts' learners.
        $DB->delete_records_select('presenterai_gatelog', "recordingid {$insql}", $inparams);
    }

    $DB->delete_records('presenterai_recording', ['presenteraiid' => $id]);

    // Spend rows are kept so cost totals still add up, but without the learner.
    // Once the course module is gone the privacy provider can't reach them, so
    // leaving a userid here would strand personal data. Course reset does the same.
    $DB->set_field('presenterai_aiusage', 'userid', 0, ['presenteraiid' => $id]);
    $DB->set_field('presenterai_aiusage', 'recordingid', 0, ['presenteraiid' => $id]);

    \mod_presenterai\local\topic_manager::delete_all((int) $id);

    // Rubrics defined in this activity's own context go with it. Course and
    // category rubrics belong to their contexts and stay.
    $cm = get_coursemodule_from_instance('presenterai', $id, 0, false, IGNORE_MISSING);
    $modctx = $cm ? \context_module::instance($cm->id, IGNORE_MISSING) : false;
    if ($modctx) {
        $DB->delete_records('presenterai_rubric', ['contextid' => $modctx->id]);
    }

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

    // Topic briefs are teacher content, not attempt media, so they are served
    // to anyone who can view the activity and have no recording row to check.
    if ($filearea === \mod_presenterai\local\topic_manager::FILEAREA) {
        require_login($course, false, $cm);
        require_capability('mod/presenterai:view', $context);
        return \mod_presenterai\local\topic_manager::serve_file($context, $args, (bool) $forcedownload, $options);
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
        '*'
    );
    // An uploading row can already carry keys (start_upload mints them before
    // the bytes arrive), so it is not a finished attempt with media to serve.
    if (!$recording || $recording->status === 'uploading') {
        return false;
    }

    // The row is the authority on which bytes belong to this attempt. Without
    // this, any file that ever existed in the area could be fetched by naming it.
    if ((string) $recording->{$keycolumns[$filearea]} !== (string) $filename) {
        return false;
    }

    // Keeping a copy is a separate decision from watching it back (D22), and
    // only the recording itself is ever offered as a download.
    if ($forcedownload) {
        if ($filearea !== 'recording' || !\mod_presenterai\local\access::may_download($recording, $context, (int) $USER->id)) {
            return false;
        }
    } else if (!\mod_presenterai\local\access::may_view($recording, $context, (int) $USER->id)) {
        return false;
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

    if ($forcedownload) {
        // Design 7.5: every download leaves a trace.
        \mod_presenterai\event\recording_downloaded::create_from_recording($recording, $context)->trigger();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Create or update the activity's grade item.
 *
 * @param stdClass $presenterai The instance row, with cmidnumber when known.
 * @param mixed $grades Null, 'reset', or grade objects to push with the item.
 * @return int A GRADE_UPDATE_* constant.
 */
function presenterai_grade_item_update($presenterai, $grades = null) {
    return \mod_presenterai\local\gradebook::grade_item_update($presenterai, $grades);
}

/**
 * Push grades for one learner, or for everyone, to the gradebook.
 *
 * @param stdClass $presenterai The instance row.
 * @param int $userid One learner, or 0 for all.
 * @param bool $nullifnone Push a null grade for a learner who has nothing to count.
 * @return void
 */
function presenterai_update_grades($presenterai, $userid = 0, $nullifnone = true) {
    \mod_presenterai\local\gradebook::update_grades($presenterai, (int) $userid, (bool) $nullifnone);
}

/**
 * The grades this activity would push, keyed by user id.
 *
 * @param stdClass $presenterai The instance row.
 * @param int $userid One learner, or 0 for all.
 * @return array Grade objects keyed by user id.
 */
function presenterai_get_user_grades($presenterai, $userid = 0) {
    return \mod_presenterai\local\gradebook::get_user_grades($presenterai, (int) $userid);
}

/**
 * Delete the activity's grade item.
 *
 * @param stdClass $presenterai The instance row.
 * @return int A GRADE_UPDATE_* constant.
 */
function presenterai_grade_item_delete($presenterai) {
    return \mod_presenterai\local\gradebook::grade_item_delete($presenterai);
}

/**
 * Whether one activity grades with a scale.
 *
 * @param int $presenteraiid The instance id.
 * @param int $scaleid The scale id.
 * @return bool
 */
function presenterai_scale_used($presenteraiid, $scaleid) {
    return \mod_presenterai\local\gradebook::scale_used((int) $presenteraiid, (int) $scaleid);
}

/**
 * Whether any activity of this module grades with a scale.
 *
 * @param int $scaleid The scale id.
 * @return bool
 */
function presenterai_scale_used_anywhere($scaleid) {
    return \mod_presenterai\local\gradebook::scale_used_anywhere((int) $scaleid);
}

/**
 * Cached course module information, carrying the custom completion rules.
 *
 * @param stdClass $coursemodule The course module row.
 * @return cached_cm_info|null
 */
function presenterai_get_coursemodule_info($coursemodule) {
    return \mod_presenterai\local\completion_rules::coursemodule_info($coursemodule);
}

/**
 * Descriptions of the active custom completion rules.
 *
 * @param cm_info|stdClass $cm The course module.
 * @return array Rule descriptions.
 */
function mod_presenterai_get_completion_active_rule_descriptions($cm) {
    return \mod_presenterai\local\completion_rules::active_rule_descriptions($cm);
}

/**
 * Add the submissions report and the rubric editor to the activity's settings navigation.
 *
 * @param settings_navigation $settings The settings navigation.
 * @param navigation_node $presenterainode The activity's node.
 * @return void
 */
function presenterai_extend_settings_navigation(settings_navigation $settings, navigation_node $presenterainode) {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/presenterai:viewallattempts', $context)) {
        $presenterainode->add(
            get_string('submissions', 'mod_presenterai'),
            new moodle_url('/mod/presenterai/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_presenterai_submissions'
        );
    }
    if (has_capability('mod/presenterai:managerubrics', $context)) {
        $presenterainode->add(
            get_string('rubrics', 'mod_presenterai'),
            new moodle_url('/mod/presenterai/rubric.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_presenterai_rubrics'
        );
    }
}

/**
 * Add this module's elements to the course reset form.
 *
 * @param MoodleQuickForm $mform The reset form.
 * @return void
 */
function presenterai_reset_course_form_definition(&$mform) {
    \mod_presenterai\local\course_reset::form_definition($mform);
}

/**
 * Default values for this module's course reset form elements.
 *
 * @param stdClass $course The course being reset.
 * @return array Element name => default value.
 */
function presenterai_reset_course_form_defaults($course) {
    return \mod_presenterai\local\course_reset::form_defaults();
}

/**
 * Delete learner work when a course is reset, if the form asked for it.
 *
 * @param stdClass $data The reset form data, carrying courseid.
 * @return array Status rows for the reset report.
 */
function presenterai_reset_userdata($data) {
    return \mod_presenterai\local\course_reset::reset_userdata($data);
}

/**
 * Reset every PresenterAI grade in a course.
 *
 * @param int $courseid The course id.
 * @param string $type Unused; this module has one grade item type.
 * @return void
 */
function presenterai_reset_gradebook($courseid, $type = '') {
    \mod_presenterai\local\course_reset::reset_gradebook((int) $courseid);
}
