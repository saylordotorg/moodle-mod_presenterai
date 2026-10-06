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
 * Start an attempt, or resume the one this learner left unfinished.
 *
 * Replaces Soapbox's get_upload_url (D16). It creates the row and nothing else:
 * no key is minted here, because the recording's key is asked for only when
 * the learner presses Stop, so an S3 upload URL can never expire while they are
 * still speaking. start_upload mints keys, one kind at a time.
 *
 * The only function in this set that takes a cmid, because it is the only one
 * with no row to derive a context from yet.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class begin_attempt extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the PresenterAI activity'),
        ]);
    }

    /**
     * Create or resume the attempt.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid((int) $params['cmid'], 'presenterai');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/presenterai:view', $context);
        require_capability('mod/presenterai:submit', $context);

        $instance = $DB->get_record('presenterai', ['id' => $cm->instance], '*', MUST_EXIST);
        $result = recording_manager::begin($instance, $cm->get_course_module_record(), $context, (int) $USER->id);
        $rec = $result['recording'];

        return [
            'recordingid' => (int) $rec->id,
            'resumed' => (bool) $result['resumed'],
            'hasdeck' => !empty($rec->deckkey),
            'backend' => (string) $rec->backend,
        ];
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordingid' => new external_value(PARAM_INT, 'The attempt\'s recording id'),
            'resumed' => new external_value(PARAM_BOOL, 'Whether an unfinished attempt was picked up rather than a new one made'),
            'hasdeck' => new external_value(PARAM_BOOL, 'Whether the resumed attempt already has a slide deck'),
            'backend' => new external_value(PARAM_ALPHANUMEXT, 'Storage backend the attempt uploads to: fs or s3'),
        ]);
    }
}
