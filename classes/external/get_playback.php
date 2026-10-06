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
use mod_presenterai\local\access;
use mod_presenterai\local\deck_renderer;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\store_factory;

/**
 * Everything the player needs: the media URL, the slides and the timeline.
 *
 * A port of Soapbox's soapbox_get_playback with two changes. Media that has
 * gone is an answer, not an error: Soapbox threw "assignment not found" for a
 * recording retention had deleted, so a planned deletion looked like a broken
 * link (design 8.5). And the S3 URL lives 300 seconds rather than 3600, with
 * the player asking again when it stops working (plan 4.6).
 *
 * The signed URL is in this response and nowhere else: not in a debugging
 * line, not in an event, not in a log. It is a bearer token for a learner's
 * face for as long as it lives.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_playback extends external_api {
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
     * Return the playback details.
     *
     * @param int $recordingid Recording id.
     * @return array
     */
    public static function execute(int $recordingid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['recordingid' => $recordingid]);

        [$rec, , , , $context] = recording_manager::load((int) $params['recordingid']);
        self::validate_context($context);
        if (!access::may_view($rec, $context, (int) $USER->id)) {
            throw new \required_capability_exception($context, 'mod/presenterai:viewallattempts', 'nopermissions', '');
        }
        if ((string) $rec->status === recording_manager::STATUS_UPLOADING) {
            // Not an attempt yet, so there is nothing to play.
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        $result = [
            'recordingid' => (int) $rec->id,
            'mode' => (string) $rec->mode,
            'mediaavailable' => false,
            'mediaurl' => '',
            'ttl' => 0,
            'pages' => [],
            'timeline' => '[]',
            'mediadeletedat' => (int) $rec->mediadeletedat,
            'mediagonereason' => (string) ($rec->mediagonereason ?? ''),
        ];

        if (empty($rec->storagekey)) {
            return $result;
        }

        $store = store_factory::for_recording($rec);
        $url = $store->read_url((string) $rec->storagekey, recording_manager::PLAYBACK_TTL);
        if ($url === '') {
            // The row says the media exists and the store cannot find it. Not
            // written back here: deciding a recording is missing is the
            // reconcile task's job (phase 3), and a read path that edits rows
            // is a migration nobody asked for. The learner is told it is
            // missing rather than shown a broken player.
            $result['mediagonereason'] = 'missing';
            return $result;
        }
        $result['mediaavailable'] = true;
        $result['mediaurl'] = $url;
        $result['ttl'] = $store->supports_direct_upload() ? recording_manager::PLAYBACK_TTL : 0;

        if (!empty($rec->deckkey) && !empty($rec->slidetimeline) && deck_renderer::is_available()) {
            $path = $store->fetch_to_file((string) $rec->deckkey, 'pdf', recording_manager::MAX_DECK_BYTES);
            if ($path !== null) {
                $result['pages'] = deck_renderer::render_to_datauris($path);
            }
        }
        if (!empty($rec->deckkey)) {
            $timeline = recording_manager::normalise_timeline((string) ($rec->slidetimeline ?? ''), count($result['pages']));
            $result['timeline'] = json_encode($timeline);
        }

        return $result;
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recordingid' => new external_value(PARAM_INT, 'Recording id'),
            'mode' => new external_value(PARAM_ALPHA, 'video or audio'),
            'mediaavailable' => new external_value(PARAM_BOOL, 'Whether the media can be played'),
            'mediaurl' => new external_value(PARAM_RAW, 'URL of the media, empty when it is gone'),
            'ttl' => new external_value(PARAM_INT, 'Seconds the URL stays valid, 0 when it does not expire'),
            'pages' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Slide page image as a data URI'),
                'Ordered slide page images'
            ),
            'timeline' => new external_value(PARAM_RAW, 'JSON array of {t, i} slide-advance events'),
            'mediadeletedat' => new external_value(PARAM_INT, 'When the media was deleted, 0 if it was not'),
            'mediagonereason' => new external_value(PARAM_ALPHANUMEXT, 'Why the media is gone, empty if it is not'),
        ]);
    }
}
