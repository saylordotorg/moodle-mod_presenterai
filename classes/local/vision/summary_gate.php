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

use mod_presenterai\local\ai\route_resolver;

/**
 * The gate every learner facing string about body language passes through (D21).
 *
 * Design section 4. It covers the new summary and the two visual criterion
 * feedback strings, because the criterion channel already carried model prose
 * about a learner's body to the learner, unchecked (C6). A gate on the summary
 * alone would have been theatre.
 *
 * The test every layer serves: could the learner do this differently in their
 * next recording, without changing who they are or what they own?
 *
 * Layer 0, the evidence gate, runs before the scoring call and lives in
 * visual_pipeline::evidence(). This class runs layers 1 to 4 over a batch:
 *
 * 1. Length and the copy detector. Over 500 characters is rejected, never
 *    truncated, because a summary cut mid clause can invert its meaning. Any
 *    8 word run shared with the raw note is rejected: it catches the model
 *    pasting the note into the learner's field, and it is the one layer that
 *    works in every language, because it compares two strings without
 *    understanding either.
 * 2. The deny list. Hard terms reject outright; soft (setting) terms reject a
 *    sentence that carries no layer 3 anchor.
 * 3. The anchor. The string must name a criterion or an observable, which
 *    catches drift into character comment.
 * 4. The judge, on by default (visualsummaryjudge), over whatever passed 1 to 3.
 *
 * Layers 2 and 3 need a word list for the language the text was written in,
 * looked up with no fallback to English; without one they are skipped.
 *
 * An empty summary is not a rejection: it is how the model declines under a
 * strict schema, and the caller shows the fallback template for it.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class summary_gate {
    /** @var string The target name of the summary in a batch. */
    public const TARGET_SUMMARY = 'summary';

    /** @var int Layer of a judge outage, as presenterai_gatelog records it. */
    public const LAYER_UNAVAILABLE = 0;

    /** @var int Length and copy detector. */
    public const LAYER_FORM = 1;

    /** @var int Deny list. */
    public const LAYER_DENY = 2;

    /** @var int Rubric anchor. */
    public const LAYER_ANCHOR = 3;

    /** @var int The judge model. */
    public const LAYER_JUDGE = 4;

    /** @var int Longest string accepted, in characters (design 4.3). */
    public const MAX_CHARS = 500;

    /** @var int Shingle length of the copy detector, in words (design 4.3). */
    public const SHINGLE_WORDS = 8;

    /**
     * Check a batch of strings for one attempt.
     *
     * @param array $texts List of ['target' => 'summary' or a criterion name, 'text' => string].
     * @param array $context ['note' => the raw vision note, 'lang' => the generation language code,
     *     'criterionnames' => every criterion name written into the scoring prompt,
     *     'judge' => whether to run layer 4, and optionally 'presenteraiid', 'recordingid',
     *     'userid' for the judge's spend row].
     * @return array A list, in the order of $texts, of ['pass' => bool, 'layer' => int, 'rule' => string].
     *     A pass carries layer 0 and rule '' ('empty' for an empty string).
     * @throws judge_unavailable_exception When the judge is wanted and cannot give a verdict.
     */
    public static function check_batch(array $texts, array $context): array {
        $texts = array_values($texts);
        $note = (string) ($context['note'] ?? '');
        $list = self::denylist_class((string) ($context['lang'] ?? ''));
        $names = array_map('strval', (array) ($context['criterionnames'] ?? []));
        $shingles = self::shingles($note);

        $results = [];
        $forjudge = [];
        foreach ($texts as $i => $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                $results[$i] = ['pass' => true, 'layer' => 0, 'rule' => 'empty'];
                continue;
            }
            $verdict = self::deterministic($text, $shingles, $list, $names);
            $results[$i] = $verdict ?? ['pass' => true, 'layer' => 0, 'rule' => ''];
            if ($verdict === null) {
                $forjudge[$i] = $text;
            }
        }

        if (!empty($context['judge']) && !empty($forjudge)) {
            $client = route_resolver::client_for(route_resolver::PURPOSE_JUDGE);
            if ($client === null) {
                throw new judge_unavailable_exception('not_configured');
            }
            $usagectx = array_intersect_key($context, array_flip(['presenteraiid', 'recordingid', 'userid']));
            $verdicts = judge::verdicts(array_values($forjudge), $client, $usagectx);
            foreach (array_keys($forjudge) as $pos => $i) {
                $verdict = $verdicts[$pos] ?? ['pass' => false, 'rule' => 'judge_missing'];
                if (!$verdict['pass']) {
                    $results[$i] = ['pass' => false, 'layer' => self::LAYER_JUDGE, 'rule' => (string) $verdict['rule']];
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Layers 1 to 3 over one string.
     *
     * @param string $text The string, trimmed and not empty.
     * @param array $shingles The note's 8 word shingles, as keys.
     * @param string|null $list The deny list class for the language, or null.
     * @param string[] $names The criterion names written into the prompt.
     * @return array|null A rejection ['pass' => false, 'layer', 'rule'], or null when the string passed.
     */
    private static function deterministic(string $text, array $shingles, ?string $list, array $names): ?array {
        if (\core_text::strlen($text) > self::MAX_CHARS) {
            return ['pass' => false, 'layer' => self::LAYER_FORM, 'rule' => 'length'];
        }
        if (!empty($shingles)) {
            foreach (array_keys(self::shingles($text)) as $shingle) {
                if (isset($shingles[$shingle])) {
                    return ['pass' => false, 'layer' => self::LAYER_FORM, 'rule' => 'copy'];
                }
            }
        }

        if ($list === null) {
            return null;
        }

        // Layer 2, hard tier.
        foreach ($list::hard() as $category => $terms) {
            if (self::find_term($text, $terms) !== null) {
                return ['pass' => false, 'layer' => self::LAYER_DENY, 'rule' => (string) $category];
            }
        }

        // Layer 2, soft tier: a setting word is allowed only in a sentence
        // that is anchored to something the learner did.
        $observables = $list::observables();
        foreach (self::sentences($text) as $sentence) {
            if (self::find_term($sentence, $list::soft()) !== null && !self::anchored($sentence, $names, $observables)) {
                return ['pass' => false, 'layer' => self::LAYER_DENY, 'rule' => 'setting'];
            }
        }

        // Layer 3.
        if (!self::anchored($text, $names, $observables)) {
            return ['pass' => false, 'layer' => self::LAYER_ANCHOR, 'rule' => 'no_anchor'];
        }

        return null;
    }

    /**
     * The deny list class for a language, or null when none exists.
     *
     * "en_us" finds "en", which is the same language. Nothing falls back to
     * English from another language.
     *
     * @param string $lang A Moodle language code.
     * @return string|null A class name with static hard(), soft() and observables().
     */
    public static function denylist_class(string $lang): ?string {
        $lang = strtolower(trim(str_replace('-', '_', $lang)));
        if ($lang === '' || !preg_match('/^[a-z]{2,3}(_[a-z0-9]+)*$/', $lang)) {
            return null;
        }
        $candidates = [$lang];
        if (str_contains($lang, '_')) {
            $candidates[] = substr($lang, 0, strpos($lang, '_'));
        }
        foreach ($candidates as $code) {
            $class = '\\mod_presenterai\\local\\vision\\denylist\\' . $code;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Whether a string names a criterion or an observable.
     *
     * @param string $text The string.
     * @param string[] $names Criterion names.
     * @param string[] $observables The observables list.
     * @return bool
     */
    public static function anchored(string $text, array $names, array $observables): bool {
        if (self::find_term($text, $observables) !== null) {
            return true;
        }
        $normalisedtext = ' ' . self::normalise_name($text) . ' ';
        foreach ($names as $name) {
            $normalised = self::normalise_name($name);
            if ($normalised !== '' && str_contains($normalisedtext, ' ' . $normalised . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first of $terms found in $text as a whole word, or null.
     *
     * Case insensitive and Unicode aware. A term of several words matches
     * across any run of white space.
     *
     * @param string $text The text to search.
     * @param string[] $terms The terms.
     * @return string|null
     */
    public static function find_term(string $text, array $terms): ?string {
        foreach ($terms as $term) {
            $parts = preg_split('/\s+/u', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($parts)) {
                continue;
            }
            $quoted = array_map(fn($part) => preg_quote($part, '/'), $parts);
            $pattern = '/(?<![\p{L}\p{N}])' . implode('\s+', $quoted) . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text)) {
                return (string) $term;
            }
        }

        return null;
    }

    /**
     * A criterion name in the form names are compared in.
     *
     * Uses the rubric's own normaliser when it exists, so the anchor and the
     * criterion allowlist agree about what a name is; otherwise the same rule
     * here: lower case, anything that is not a letter or digit to a space,
     * spaces collapsed.
     *
     * @param string $name A name or any text.
     * @return string
     */
    public static function normalise_name(string $name): string {
        $manager = '\\mod_presenterai\\local\\rubric_manager';
        if (method_exists($manager, 'normalise_name')) {
            $name = (string) $manager::normalise_name($name);
        }
        $name = \core_text::strtolower($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Every 8 word run of a text, as array keys.
     *
     * @param string $text The text.
     * @return array shingle => true
     */
    public static function shingles(string $text): array {
        $words = self::words($text);
        $out = [];
        for ($i = 0; $i + self::SHINGLE_WORDS <= count($words); $i++) {
            $out[implode(' ', array_slice($words, $i, self::SHINGLE_WORDS))] = true;
        }

        return $out;
    }

    /**
     * A text lower cased and split into words, in any script.
     *
     * @param string $text The text.
     * @return string[]
     */
    public static function words(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * A text split into sentences.
     *
     * @param string $text The text.
     * @return string[]
     */
    private static function sentences(string $text): array {
        $sentences = preg_split('/(?<=[.!?;])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $sentences === false ? [$text] : $sentences;
    }
}
