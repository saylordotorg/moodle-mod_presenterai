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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_presenterai\local\deck_renderer;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\store_factory;

/**
 * Render the learner's uploaded deck to page images for the recorder.
 *
 * A port of Soapbox's soapbox_render_deck, with the deck named by the row
 * rather than by a key the browser sends, so there is no key to validate.
 * Pages are returned as data URIs and not stored; phase 5 caches them.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render_deck extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording id whose deck to render'),
        ]);
    }

    /**
     * Commit the deck if it is still staged, then render it.
     *
     * @param int $recordingid Recording id.
     * @return array
     */
    public static function execute(int $recordingid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['recordingid' => $recordingid]);

        [$rec, , $course, , $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        require_capability('mod/presenterai:submit', $context);
        if ((int) $rec->userid !== (int) $USER->id) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        if ((string) $rec->status === recording_manager::STATUS_UPLOADING) {
            recording_manager::commit_pending_deck($rec, $context, $course);
        }
        if (empty($rec->deckkey)) {
            throw new \moodle_exception('error:nodeck', 'mod_presenterai');
        }

        // No Ghostscript is a site without slide images, not an error: the
        // learner can still record, and the browser says the slides cannot be
        // shown.
        if (!deck_renderer::is_available()) {
            return ['available' => false, 'pagecount' => 0, 'pages' => []];
        }

        // Read only. On the File API this can be Moodle's own content
        // addressed file, shared with every other copy of the same bytes.
        $path = store_factory::for_recording($rec)->fetch_to_file((string) $rec->deckkey, 'pdf');
        if ($path === null) {
            throw new \moodle_exception('error:nodeck', 'mod_presenterai');
        }
        $pages = deck_renderer::render_to_datauris($path);

        return ['available' => true, 'pagecount' => count($pages), 'pages' => $pages];
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'Whether this site can render slides at all'),
            'pagecount' => new external_value(PARAM_INT, 'Number of pages rendered'),
            'pages' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Page image as a data URI'),
                'Ordered page images'
            ),
        ]);
    }
}
