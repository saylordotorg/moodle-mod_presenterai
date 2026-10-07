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

namespace mod_presenterai\local\ai;

/**
 * One presenterai_aiusage row per AI call, for spend attribution (plan 3.6).
 *
 * The user is always an explicit argument and never $USER: scoring runs under
 * cron, and cron is not the spender. Route, model, provider and tokens come
 * from the client's last_usage() unless $extra overrides them, so a caller
 * records a call with one line after it, whether it succeeded or threw.
 *
 * estmicrocents is an estimate from a small price table, in millionths of a
 * cent (report_page divides by 1e8 to show dollars). A model the table does
 * not know, core AI among them, is recorded at zero rather than guessed.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage {
    /** @var string A transcription. */
    public const ACTION_TRANSCRIBE = 'transcribe';

    /** @var string A scoring call. */
    public const ACTION_SCORE = 'score';

    /** @var string A slide vision pass. */
    public const ACTION_SLIDE_VISION = 'slide_vision';

    /** @var string A body language vision pass. */
    public const ACTION_VIDEO_VISION = 'video_vision';

    /** @var string A visual summary judge call. */
    public const ACTION_JUDGE = 'judge';

    /**
     * @var array Model prefix => [input, output] microcents per token, from
     * the providers' published per million token prices: $3/$15 is 300/1500.
     */
    public const TOKEN_PRICES = [
        'claude-sonnet-5-5' => [300, 1500],
        'claude-haiku-4-5' => [100, 500],
        'gpt-4o-mini' => [15, 60],
        'gemini-2.5-flash' => [30, 250],
    ];

    /** @var array Model prefix => microcents per second of audio ($0.006 a minute). */
    public const AUDIO_PRICES = [
        'whisper-1' => 10000,
    ];

    /**
     * Record one call.
     *
     * @param object|null $client The client_interface or stt_interface that
     *     made the call, or null when $extra says everything.
     * @param string $action One of the ACTION_ constants.
     * @param int $presenteraiid The activity instance.
     * @param int $recordingid The recording, or 0.
     * @param int $userid The learner the call was made for.
     * @param array $extra Overrides: route, model, provider, prompttokens,
     *     completiontokens, imagecount, audioseconds.
     * @return int The new row's id.
     */
    public static function record(
        ?object $client,
        string $action,
        int $presenteraiid,
        int $recordingid,
        int $userid,
        array $extra = []
    ): int {
        global $DB;

        $row = [
            'route' => '',
            'model' => '',
            'provider' => '',
            'prompttokens' => 0,
            'completiontokens' => 0,
            'imagecount' => 0,
            'audioseconds' => 0,
        ];
        if ($client instanceof client_interface) {
            $last = $client->last_usage();
            $row['route'] = $client->route();
            $row['model'] = (string) ($last['model'] ?? '') !== '' ? (string) $last['model'] : $client->model();
            $row['provider'] = (string) ($last['provider'] ?? $client->route());
            $row['prompttokens'] = (int) ($last['prompttokens'] ?? 0);
            $row['completiontokens'] = (int) ($last['completiontokens'] ?? 0);
        } else if ($client instanceof stt_interface) {
            $row['route'] = $client->route();
            $row['model'] = $client->model();
            $row['provider'] = $client->route();
        }
        foreach (array_keys($row) as $key) {
            if (array_key_exists($key, $extra)) {
                $row[$key] = in_array($key, ['route', 'model', 'provider'], true)
                    ? (string) $extra[$key] : (int) $extra[$key];
            }
        }

        $record = (object) [
            'presenteraiid' => $presenteraiid,
            'recordingid' => $recordingid,
            'userid' => $userid,
            'action' => \core_text::substr($action, 0, 32),
            'route' => \core_text::substr($row['route'] !== '' ? $row['route'] : 'direct', 0, 16),
            'provider' => \core_text::substr($row['provider'], 0, 64),
            'model' => \core_text::substr($row['model'], 0, 64),
            'prompttokens' => max(0, $row['prompttokens']),
            'completiontokens' => max(0, $row['completiontokens']),
            'imagecount' => max(0, $row['imagecount']),
            'audioseconds' => max(0, $row['audioseconds']),
            'estmicrocents' => self::estimate(
                $row['model'],
                $row['prompttokens'],
                $row['completiontokens'],
                $row['audioseconds']
            ),
            'timecreated' => time(),
        ];
        return (int) $DB->insert_record('presenterai_aiusage', $record);
    }

    /**
     * Estimate a call's cost in microcents.
     *
     * @param string $model The model id.
     * @param int $prompttokens Input tokens.
     * @param int $completiontokens Output tokens.
     * @param int $audioseconds Seconds of audio transcribed.
     * @return int 0 when the model is not in the table.
     */
    public static function estimate(string $model, int $prompttokens, int $completiontokens, int $audioseconds = 0): int {
        $model = strtolower(trim($model));
        $audio = self::lookup(self::AUDIO_PRICES, $model);
        if ($audio !== null) {
            return max(0, $audioseconds) * (int) $audio;
        }
        $tokens = self::lookup(self::TOKEN_PRICES, $model);
        if ($tokens === null) {
            return 0;
        }
        return max(0, $prompttokens) * $tokens[0] + max(0, $completiontokens) * $tokens[1];
    }

    /**
     * The entry for the longest matching prefix, or null.
     *
     * @param array $table Prefix => price.
     * @param string $model The model id, lower case.
     * @return mixed
     */
    private static function lookup(array $table, string $model) {
        $best = null;
        $bestlength = 0;
        foreach ($table as $prefix => $price) {
            if ($model !== '' && str_starts_with($model, $prefix) && strlen($prefix) > $bestlength) {
                $best = $price;
                $bestlength = strlen($prefix);
            }
        }
        return $best;
    }
}
