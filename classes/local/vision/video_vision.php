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

namespace mod_presenterai\local\vision;

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\ai\json_parser;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\store_factory;

/**
 * The vision pass over one attempt's contact sheet, returning D18 JSON.
 *
 * A port of SOLA's soapbox_gesture_vision::observe(), with three changes. The
 * reply is JSON carrying the note, a confidence and a count of unusable
 * frames, rather than prose (D18). The pixels are checked for darkness before
 * any model sees them (design 4.2). And the prompt is design 3.3's, which
 * forbids "throughout", ignores anyone else in shot and never names a fact
 * about the room.
 *
 * The sheet is read through the store with a ceiling checked before the read,
 * because it is learner supplied and an oversized object read whole is a
 * memory limit fatal that no catch block intercepts. Its type is sniffed from
 * the bytes, not trusted from the key.
 *
 * Failures throw and visual_pipeline::evidence() turns them into a state;
 * this class never decides what the learner is told.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class video_vision {
    /** @var int Largest sheet read, 8 MB, the same ceiling start_upload enforces. */
    public const MAX_FRAMES_BYTES = recording_manager::MAX_FRAMES_BYTES;

    /** @var int Characters of the note kept, as SOLA's MAX_NOTE_CHARS. */
    public const MAX_NOTE_CHARS = 900;

    /** @var string[] Confidence values the gate understands; anything else is low. */
    public const CONFIDENCES = ['high', 'medium', 'low'];

    /** @var int Output budget for the JSON reply. */
    private const MAX_TOKENS = 600;

    /**
     * Look at the sheet and report what the model saw.
     *
     * @param \stdClass $rec The recording row, carrying frameskey and backend.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The module context.
     * @param client_interface $client The vision client.
     * @param bool|null $called Set to true once the client has been called, so the caller
     *     records spend even when the call then throws.
     * @return array ['note' => string, 'confidence' => 'high'|'medium'|'low', 'unusable_frames' => int,
     *     'dark' => int|null (dark cells, null when the check could not run), 'badimage' => bool]
     * @throws \moodle_exception When the sheet cannot be read; anything the client throws.
     */
    public static function observe(
        \stdClass $rec,
        \stdClass $instance,
        \context_module $ctx,
        client_interface $client,
        ?bool &$called = null
    ): array {
        $called = false;
        $result = ['note' => '', 'confidence' => 'low', 'unusable_frames' => 0, 'dark' => null, 'badimage' => false];

        $key = (string) ($rec->frameskey ?? '');
        if ($key === '') {
            throw new \moodle_exception('error:framesdisabled', 'mod_presenterai');
        }
        $bytes = store_factory::for_recording($rec)->read_bytes($key, self::MAX_FRAMES_BYTES);
        if ($bytes === null || $bytes === '') {
            // The key is on the row and the object can't be read: storage
            // trouble, not something the learner did.
            throw new \moodle_exception('error:uploadmissing', 'mod_presenterai');
        }

        $mime = self::sniff($bytes);
        if ($mime === null) {
            // The browser sent something that isn't a picture. Nothing to look at.
            $result['badimage'] = true;
            return $result;
        }

        $lum = luminance::check($bytes);
        if ($lum === null) {
            debugging('mod_presenterai skipped the luminance check on recording ' . (int) $rec->id
                . ': GD is unavailable or could not decode the sheet.', DEBUG_DEVELOPER);
        } else {
            $result['dark'] = (int) $lum['dark'];
            if (luminance::too_dark($lum)) {
                // Layer 0's objective half: no model call for a sheet nobody could read.
                return $result;
            }
        }

        $called = true;
        $raw = $client->generate_text(visual_prompts::vision(), visual_prompts::vision_user(), [
            'schema' => visual_prompts::vision_schema(),
            'images' => [['mime' => $mime, 'base64' => base64_encode($bytes)]],
            'max_tokens' => self::MAX_TOKENS,
        ]);

        $decoded = json_parser::decode_object((string) $raw, ['note', 'unusable_frames', 'confidence']);
        if ($decoded === null) {
            throw new ai_exception('bad_response', false, 'vision reply did not parse');
        }

        $note = is_string($decoded['note']) ? trim($decoded['note']) : '';
        $confidence = is_string($decoded['confidence']) ? strtolower(trim($decoded['confidence'])) : '';
        $result['note'] = \core_text::substr($note, 0, self::MAX_NOTE_CHARS);
        $result['confidence'] = in_array($confidence, self::CONFIDENCES, true) ? $confidence : 'low';
        $result['unusable_frames'] = max(0, (int) $decoded['unusable_frames']);

        return $result;
    }

    /**
     * The image type from the bytes' magic numbers, or null for anything else.
     *
     * JPEG is what frames.js produces; PNG is accepted because a browser that
     * cannot encode JPEG falls back to it.
     *
     * @param string $bytes The object.
     * @return string|null 'image/jpeg' or 'image/png'.
     */
    public static function sniff(string $bytes): ?string {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }

        return null;
    }
}
