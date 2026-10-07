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
 * Per user limits on paid AI calls (D20, plan section 5.6).
 *
 * Core's own limiter is per provider and shared with every AI consumer on the
 * site, and off by default; this one is PresenterAI's, per user and per kind
 * of call. The limits are SOLA's shipped ones: 10 vision passes and 12
 * transcriptions per 10 minutes, plus 20 scoring calls an hour.
 *
 * A port of SOLA's rate_limiter: a fixed window stored as one cache entry,
 * counted under a lock per key so parallel requests cannot all read the same
 * count. Contention for a key means the same user already has a call in
 * flight, so a lock that cannot be had counts as over the limit. The cache
 * ttl (db/caches.php) must be at least the longest window or an entry would
 * expire mid window and silently reset the count.
 *
 * A refused call isn't counted. Counting it would let a task that retries
 * while limited keep pushing its own count up, so the window it waits for
 * would never come.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /** @var int Vision passes allowed per window. */
    public const VISION_MAX = 10;

    /** @var int Vision window, seconds. */
    public const VISION_WINDOW = 600;

    /** @var int Transcriptions allowed per window. */
    public const TRANSCRIBE_MAX = 12;

    /** @var int Transcription window, seconds. */
    public const TRANSCRIBE_WINDOW = 600;

    /** @var int Scoring calls allowed per window. */
    public const SCORE_MAX = 20;

    /** @var int Scoring window, seconds. */
    public const SCORE_WINDOW = 3600;

    /** @var int|null Test clock. */
    private static ?int $testnow = null;

    /**
     * Count one call and say whether the user is now over the limit.
     *
     * @param string $bucket video_vision, slide_vision, transcribe or score.
     * @param int $userid The user the call is made for.
     * @param int $max Calls allowed per window.
     * @param int $windowseconds The window.
     * @return bool True when this call is over the limit.
     */
    public static function hit(string $bucket, int $userid, int $max, int $windowseconds): bool {
        $key = self::key($bucket, $userid);
        try {
            $factory = \core\lock\lock_config::get_lock_factory('mod_presenterai_ratelimit');
        } catch (\Throwable $e) {
            debugging('mod_presenterai rate limiter has no lock factory; counting without one.', DEBUG_DEVELOPER);
            return self::count($key, $max, $windowseconds);
        }
        $lock = false;
        try {
            $lock = $factory->get_lock($key, 2);
        } catch (\Throwable $e) {
            debugging('mod_presenterai rate limiter lock error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if ($lock === false) {
            return true;
        }
        try {
            return self::count($key, $max, $windowseconds);
        } finally {
            $lock->release();
        }
    }

    /**
     * Move the limiter's clock (tests only); null restores time().
     *
     * @param int|null $now A timestamp, or null.
     * @return void
     */
    public static function set_test_now(?int $now): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('rate_limiter::set_test_now() is for unit tests only');
        }
        self::$testnow = $now;
    }

    /**
     * Seconds until a user's window for a bucket ends and calls are allowed again.
     *
     * @param string $bucket The bucket, as passed to hit().
     * @param int $userid The user.
     * @param int $windowseconds The window.
     * @return int 0 when no window is open.
     */
    public static function seconds_until_reset(string $bucket, int $userid, int $windowseconds): int {
        $data = \cache::make('mod_presenterai', 'ratelimit')->get(self::key($bucket, $userid));
        if (!is_array($data) || !isset($data['start'])) {
            return 0;
        }
        $now = self::$testnow ?? time();
        return max(0, (int) $data['start'] + $windowseconds - $now);
    }

    /**
     * The cache key for a bucket and user.
     *
     * @param string $bucket The bucket.
     * @param int $userid The user.
     * @return string
     */
    private static function key(string $bucket, int $userid): string {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $bucket) . '_' . $userid;
    }

    /**
     * The fixed window count itself.
     *
     * @param string $key The cache key.
     * @param int $max Calls allowed per window.
     * @param int $windowseconds The window.
     * @return bool True when over.
     */
    private static function count(string $key, int $max, int $windowseconds): bool {
        $cache = \cache::make('mod_presenterai', 'ratelimit');
        $now = self::$testnow ?? time();
        $data = $cache->get($key);
        if (!is_array($data) || !isset($data['start'], $data['count']) || ($now - (int) $data['start']) >= $windowseconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        if ((int) $data['count'] >= $max) {
            // Over: refused, and not counted.
            return true;
        }
        $data['count'] = (int) $data['count'] + 1;
        $cache->set($key, $data);
        return false;
    }
}
