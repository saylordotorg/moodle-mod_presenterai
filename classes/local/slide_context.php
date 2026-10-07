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

namespace mod_presenterai\local;

use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\usage;
use mod_presenterai\local\storage\store_factory;

/**
 * What the scoring prompt is told about an attempt's slides.
 *
 * A port of SOLA's soapbox_scorer::build_slide_context() and
 * soapbox_slide_vision (classes/soapbox_scorer.php, classes/soapbox_slide_vision.php):
 * each slide's text with the seconds the speaker spent on it, and optionally
 * one short design note from a vision pass over the rendered pages.
 *
 * Everything here is best effort. A deck that can't be fetched, read or
 * rendered, a vision route that isn't configured or is rate limited, and a
 * vision call that fails all leave the scoring to go ahead on the transcript
 * alone, because the slides are context for the score and not part of it.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class slide_context {
    /** @var int Most characters of slide context put in the prompt. */
    public const MAX_TEXT_CHARS = 8000;

    /** @var int Most characters of one slide's text. */
    public const MAX_SLIDE_CHARS = 600;

    /** @var int Most slide images sent in the one vision pass, which bounds its cost. */
    public const MAX_VISION_SLIDES = 12;

    /** @var int Most characters kept from the vision pass's note. */
    public const MAX_NOTE_CHARS = 800;

    /**
     * The slide context for one attempt.
     *
     * Only an activity with slides on and an attempt with a deck has any.
     * The vision pass runs only when the activity also has slidevision on.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @param \stdClass $course The course row.
     * @return array ['text' => string (at most MAX_TEXT_CHARS), 'count' => int, 'visionnote' => string]
     */
    public static function build(\stdClass $rec, \stdClass $instance, \context_module $ctx, \stdClass $course): array {
        $out = ['text' => '', 'count' => 0, 'visionnote' => ''];
        if (empty($instance->slidesenabled) || empty($rec->deckkey)) {
            return $out;
        }

        try {
            $store = store_factory::for_recording($rec);
            $path = $store->fetch_to_file((string) $rec->deckkey, 'pdf', recording_manager::MAX_DECK_BYTES);
            if ($path === null) {
                return $out;
            }

            $texts = deck_renderer::extract_text($path);
            if (!empty($texts)) {
                $timeline = recording_manager::normalise_timeline((string) ($rec->slidetimeline ?? ''), count($texts));
                $out['text'] = self::context_text($texts, $timeline, (int) ($rec->durationseconds ?? 0));
                $out['count'] = count($texts);
            }

            if (!empty($instance->slidevision)) {
                $out['visionnote'] = self::vision_note($path, $rec, $instance, $ctx);
            }
        } catch (\Throwable $e) {
            // No key, path or message: a store failure can carry either.
            debugging('mod_presenterai slide_context: the deck could not be read (' . get_class($e) . ').', DEBUG_DEVELOPER);
        }

        return $out;
    }

    /**
     * Slide text with the time spent on each slide, for the prompt. Pure.
     *
     * Time on a slide is the gap to the next advance, or to the end of the
     * recording for the last one. A slide that's shown more than once adds up.
     *
     * @param string[] $texts Per-slide text, index 0 being slide 1.
     * @param array $timeline Normalized [{t, i}] advances, in time order.
     * @param int $duration Total recording length in seconds.
     * @return string At most MAX_TEXT_CHARS characters, or '' with no slides.
     */
    public static function context_text(array $texts, array $timeline, int $duration): string {
        $texts = array_values($texts);
        $count = count($texts);
        if ($count === 0) {
            return '';
        }

        $spent = array_fill(0, $count, 0);
        $events = array_values($timeline);
        for ($k = 0; $k < count($events); $k++) {
            $i = max(0, min($count - 1, (int) ($events[$k]['i'] ?? 0)));
            $start = (int) ($events[$k]['t'] ?? 0);
            $end = ($k + 1 < count($events)) ? (int) ($events[$k + 1]['t'] ?? $start) : max($start, $duration);
            $spent[$i] += max(0, $end - $start);
        }

        $lines = ["The presentation used {$count} slide(s). Slide text and time spent per slide:"];
        for ($i = 0; $i < $count; $i++) {
            $text = trim((string) $texts[$i]);
            if ($text === '') {
                $text = '(no text on this slide)';
            }
            $lines[] = 'Slide ' . ($i + 1) . ' (~' . $spent[$i] . 's): ' . \core_text::substr($text, 0, self::MAX_SLIDE_CHARS);
        }
        $lines[] = 'Consider slide design and clarity, whether the spoken content matches the slide it is on, '
            . 'and pacing (roughly even time per slide, not rushing the last slides).';

        return \core_text::substr(implode("\n", $lines), 0, self::MAX_TEXT_CHARS);
    }

    /**
     * Pick at most MAX_VISION_SLIDES items spread evenly across a deck, first and last included. Pure.
     *
     * @param array $items Ordered slide images.
     * @return array At most MAX_VISION_SLIDES of them, in order.
     */
    public static function sample(array $items): array {
        $items = array_values($items);
        $n = count($items);
        if ($n <= self::MAX_VISION_SLIDES) {
            return $items;
        }
        $picked = [];
        for ($k = 0; $k < self::MAX_VISION_SLIDES; $k++) {
            $idx = (int) round($k * ($n - 1) / (self::MAX_VISION_SLIDES - 1));
            $picked[$idx] = $items[$idx];
        }
        ksort($picked);
        return array_values($picked);
    }

    /**
     * The system prompt for the slide design pass, from SOLA's soapbox_slide_vision.
     *
     * @param string $ptype The presentation type.
     * @return string
     */
    public static function vision_prompt(string $ptype): string {
        $ptype = trim($ptype) !== '' ? trim($ptype) : 'informative';
        return "You are a supportive presentation-design coach. You are shown the slide images from a "
            . "learner's {$ptype} presentation (a bounded sample if the deck is long). Comment ONLY on the "
            . "visual design of the slides: layout, visual hierarchy, text density, readability, consistency, "
            . "and use of visuals. Do not judge the spoken delivery or transcribe the text. Give two or three "
            . "sentences of encouraging, concrete feedback naming one clear strength and the single "
            . "highest-leverage visual improvement. Plain prose, no headings, no lists.";
    }

    /**
     * One short design note from the rendered pages, or '' when there's none.
     *
     * The spend is recorded against the recording's owner, passed explicitly:
     * this runs in cron, and cron isn't the spender.
     *
     * @param string $path Local path to the deck PDF.
     * @param \stdClass $rec The presenterai_recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @return string At most MAX_NOTE_CHARS characters.
     */
    private static function vision_note(string $path, \stdClass $rec, \stdClass $instance, \context_module $ctx): string {
        $client = route_resolver::client_for(route_resolver::PURPOSE_SLIDE_VISION);
        if ($client === null || !$client->supports_images()) {
            return '';
        }
        $userid = (int) $rec->userid;
        if (rate_limiter::hit('slide_vision', $userid, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW)) {
            return '';
        }

        $images = [];
        foreach (self::sample(deck_renderer::render_to_datauris($path)) as $uri) {
            $comma = strpos($uri, ',');
            if ($comma === false) {
                continue;
            }
            $images[] = ['mime' => 'image/png', 'base64' => substr($uri, $comma + 1)];
        }
        if (empty($images)) {
            return '';
        }

        $note = '';
        try {
            $note = $client->generate_text(
                self::vision_prompt((string) ($instance->ptype ?? '')),
                'Give the slide visual-design note now.',
                [
                    'images' => $images,
                    'max_tokens' => 300,
                    'contextid' => (int) $ctx->id,
                    'userid' => $userid,
                ]
            );
        } catch (\Throwable $e) {
            $note = '';
        } finally {
            usage::record($client, usage::ACTION_SLIDE_VISION, (int) $instance->id, (int) $rec->id, $userid, [
                'imagecount' => count($images),
            ]);
        }

        $note = trim((string) $note);
        return $note === '' ? '' : \core_text::substr($note, 0, self::MAX_NOTE_CHARS);
    }
}
