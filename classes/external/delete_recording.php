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
use mod_presenterai\event\recording_deleted;
use mod_presenterai\local\access;
use mod_presenterai\local\recording_manager;

/**
 * Delete a recording's media now, keeping the attempt, its score and its feedback.
 *
 * The learner's own delete (deleteownmedia) is the recommended answer to open
 * question 9.21; a staff delete of somebody else's media (deleteanyrecording)
 * is for takedown and support. The capability is checked in code rather than
 * in services.php because which one applies depends on whose recording it is.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_recording extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording id'),
        ]);
    }

    /**
     * Delete the media.
     *
     * @param int $recordingid Recording id.
     * @return array
     */
    public static function execute(int $recordingid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['recordingid' => $recordingid]);

        [$rec, , , , $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        if (!access::may_delete($rec, $context, (int) $USER->id)) {
            throw new \moodle_exception('error:cannotdelete', 'mod_presenterai');
        }

        $reason = (int) $rec->userid === (int) $USER->id ? 'learner' : 'manual';
        if (!recording_manager::drop_media($rec, $reason)) {
            throw new \moodle_exception('error:deletefailed', 'mod_presenterai');
        }
        recording_deleted::create_from_recording($rec, $context, $reason)->trigger();

        $after = $DB->get_record('presenterai_recording', ['id' => $rec->id], 'id, mediadeletedat, mediagonereason', MUST_EXIST);

        return [
            'recordingid' => (int) $after->id,
            'mediadeletedat' => (int) $after->mediadeletedat,
            'mediagonereason' => (string) $after->mediagonereason,
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
            'mediadeletedat' => new external_value(PARAM_INT, 'When the media was deleted'),
            'mediagonereason' => new external_value(PARAM_ALPHANUMEXT, 'Why: learner or manual'),
        ]);
    }
}
