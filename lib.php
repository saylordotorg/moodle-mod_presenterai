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
