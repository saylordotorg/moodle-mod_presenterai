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

use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\ai\json_parser;
use mod_presenterai\local\ai\usage;

/**
 * Layer 4 of the visual feedback gate: a second, small model as judge.
 *
 * Design 3.5 and 4.6. One call per attempt, carrying the strings that passed
 * layers 1 to 3 as a numbered list and nothing else. The judge is never given
 * the learner's name, the transcript, the raw note or the scores: it doesn't
 * need them, and every place a learner's name travels is a place it can leak.
 *
 * It exists because the euphemism ("you don't come across as someone used to
 * doing this") carries no listed term and passes the deterministic layers as
 * soon as it mentions hands or gaze. That failure is English first, which is
 * why the setting defaults on everywhere rather than only where a word list is
 * missing.
 *
 * A missing verdict is a rejection: a judge that skipped an item has not
 * passed it. No client, an ai_exception or an unparseable reply is an outage,
 * not a verdict, and throws judge_unavailable_exception (design 4.7).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class judge {
    /** @var int Output budget: about 40 tokens a verdict, with room to spare. */
    private const MAX_TOKENS = 400;

    /** @var int Longest rule kept, the width of presenterai_gatelog.rule. */
    private const RULE_MAX = 64;

    /**
     * Ask the judge about each text.
     *
     * @param string[] $texts The candidate strings, in order. Keys are ignored.
     * @param client_interface $client The judge client (route_resolver::PURPOSE_JUDGE).
     * @param array $usagectx Optional ['presenteraiid', 'recordingid', 'userid'] for the spend row.
     * @return array A list, in the order of $texts, of ['pass' => bool, 'rule' => string].
     */
    public static function verdicts(array $texts, client_interface $client, array $usagectx = []): array {
        $texts = array_values(array_map('strval', $texts));
        if (empty($texts)) {
            return [];
        }

        try {
            $raw = $client->generate_text(visual_prompts::judge(), self::user_message($texts), [
                'schema' => visual_prompts::judge_schema(),
                'max_tokens' => self::MAX_TOKENS,
            ]);
        } catch (\mod_presenterai\local\ai\ai_exception $e) {
            throw new judge_unavailable_exception($e->reason, get_class($e));
        } catch (\Throwable $e) {
            throw new judge_unavailable_exception('network', get_class($e));
        } finally {
            // Spend is recorded whether or not the call succeeded: a refused or
            // failed call can still have cost tokens.
            self::record_usage($client, $usagectx);
        }

        $decoded = json_parser::decode_object((string) $raw, ['verdicts']);
        if ($decoded === null || !is_array($decoded['verdicts'])) {
            throw new judge_unavailable_exception('bad_response');
        }

        $byn = [];
        foreach ($decoded['verdicts'] as $verdict) {
            if (!is_array($verdict) || !isset($verdict['n']) || !array_key_exists('pass', $verdict)) {
                continue;
            }
            $n = (int) $verdict['n'];
            // The first verdict for a number stands; a reply that numbers an
            // item twice does not get to change its mind.
            if ($n >= 1 && $n <= count($texts) && !isset($byn[$n])) {
                $byn[$n] = $verdict;
            }
        }

        $out = [];
        foreach ($texts as $i => $unused) {
            $verdict = $byn[$i + 1] ?? null;
            if ($verdict === null) {
                $out[] = ['pass' => false, 'rule' => 'judge_missing'];
                continue;
            }
            $pass = $verdict['pass'] === true;
            $rule = $pass ? '' : self::clean_rule((string) ($verdict['rule'] ?? ''));
            $out[] = ['pass' => $pass, 'rule' => $rule];
        }

        return $out;
    }

    /**
     * The user message: the candidates, numbered, and nothing else.
     *
     * Each candidate is flattened onto one line so a newline inside one cannot
     * look like the start of the next item.
     *
     * @param string[] $texts The candidates, as a list.
     * @return string
     */
    public static function user_message(array $texts): string {
        $lines = [];
        foreach (array_values($texts) as $i => $text) {
            $lines[] = ($i + 1) . '. ' . trim(preg_replace('/\s+/u', ' ', (string) $text));
        }

        return implode("\n", $lines);
    }

    /**
     * The judge's rule as something safe to store and show to staff.
     *
     * The rule is model output, so it's cut to the column and to one line.
     *
     * @param string $rule The rule the judge gave.
     * @return string
     */
    private static function clean_rule(string $rule): string {
        $rule = trim(preg_replace('/\s+/u', ' ', $rule));
        if ($rule === '') {
            return 'judge';
        }

        return \core_text::substr($rule, 0, self::RULE_MAX);
    }

    /**
     * Record the judge call's spend, never failing the gate over it.
     *
     * @param client_interface $client The judge client.
     * @param array $usagectx ['presenteraiid', 'recordingid', 'userid'].
     * @return void
     */
    private static function record_usage(client_interface $client, array $usagectx): void {
        try {
            usage::record(
                $client,
                usage::ACTION_JUDGE,
                (int) ($usagectx['presenteraiid'] ?? 0),
                (int) ($usagectx['recordingid'] ?? 0),
                (int) ($usagectx['userid'] ?? 0)
            );
        } catch (\Throwable $e) {
            debugging('mod_presenterai could not record judge usage: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }
}
