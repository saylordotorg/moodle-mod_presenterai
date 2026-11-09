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

/**
 * The sources a teacher gives learners to cite (0.7.0).
 *
 * Stored as plain text on the activity, one source per line, with the number
 * a learner must cite beside it. A fixed list keeps the scoring honest: the AI
 * can't check that a source a learner found for themselves is real, but it can
 * check that a learner cited one of these, with its author or organization and
 * its year. No list means learners choose their own, as before 0.7.0.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sources {
    /** @var int Most sources kept; lines past this are dropped. */
    public const MAX_SOURCES = 10;

    /** @var int Most characters kept from one source line. */
    public const MAX_CHARS = 500;

    /**
     * The sources in stored or submitted text, one per non-blank line.
     *
     * @param string|null $text The text, one source per line.
     * @return string[] Trimmed, with runs of white space collapsed, at most MAX_SOURCES.
     */
    public static function parse(?string $text): array {
        $out = [];
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $out[] = \core_text::substr($line, 0, self::MAX_CHARS);
            if (count($out) >= self::MAX_SOURCES) {
                break;
            }
        }
        return $out;
    }

    /**
     * The text to store: the parsed sources one per line, or null for none.
     *
     * @param string|null $text The submitted text.
     * @return string|null
     */
    public static function clean(?string $text): ?string {
        $list = self::parse($text);
        return empty($list) ? null : implode("\n", $list);
    }

    /**
     * How many sources a learner must cite: the activity's minimum, never more
     * than the sources it gives.
     *
     * @param \stdClass $instance The presenterai row.
     * @return int 0 when there's no list or no minimum.
     */
    public static function required(\stdClass $instance): int {
        $count = count(self::parse($instance->sources ?? null));
        return max(0, min((int) ($instance->minsources ?? 0), $count));
    }

    /**
     * Context for the learner's sources step in templates/view.mustache.
     *
     * Each line is escaped and its web addresses made into links, so a
     * teacher can paste a citation with its URL. The links are made here, not
     * left to the URL-to-link filter, which a site may have turned off.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context $context The activity's context.
     * @return array ['hassources' => bool, 'intro' => string, 'items' => array[] of ['html' => string]]
     */
    public static function export_for_view(\stdClass $instance, \context $context): array {
        $list = self::parse($instance->sources ?? null);
        $required = self::required($instance);
        $items = [];
        foreach ($list as $line) {
            $items[] = ['html' => self::linkify($line)];
        }
        $intro = $required > 0
            ? get_string('sources_intro_min', 'mod_presenterai', (object) ['min' => $required, 'count' => count($list)])
            : get_string('sources_intro', 'mod_presenterai');
        return [
            'hassources' => !empty($items),
            'intro' => $intro,
            'items' => $items,
        ];
    }
    /**
     * One source as HTML: escaped, with each http(s) address made into a link
     * that opens in a new tab. Punctuation that ends a citation (a full stop
     * after the URL) stays outside the link.
     *
     * @param string $line One source.
     * @return string
     */
    public static function linkify(string $line): string {
        $out = '';
        $parts = preg_split('~(https?://[^\s<>"\']+)~iu', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= s($part);
                continue;
            }
            $trail = '';
            if (preg_match('~[.,;:!?)\]]+$~u', $part, $m)) {
                $trail = $m[0];
                $part = \core_text::substr($part, 0, \core_text::strlen($part) - \core_text::strlen($trail));
            }
            $out .= \html_writer::link($part, s($part), ['target' => '_blank', 'rel' => 'noopener']) . s($trail);
        }
        return $out;
    }
}
