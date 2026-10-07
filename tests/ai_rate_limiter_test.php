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

namespace mod_presenterai;

use mod_presenterai\local\ai\rate_limiter;

/**
 * The per user rate limiter.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\rate_limiter
 */
final class ai_rate_limiter_test extends \advanced_testcase {
    /**
     * Restore the clock.
     *
     * @return void
     */
    protected function tearDown(): void {
        rate_limiter::set_test_now(null);
        parent::tearDown();
    }

    /**
     * Ten vision passes are allowed, the eleventh is over, another user and
     * another bucket are unaffected, and the window resets.
     *
     * @return void
     */
    public function test_vision_window(): void {
        $this->resetAfterTest();
        $start = 1700000000;
        rate_limiter::set_test_now($start);
        for ($i = 1; $i <= rate_limiter::VISION_MAX; $i++) {
            $over = rate_limiter::hit('video_vision', 5, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW);
            $this->assertFalse($over, "hit $i");
        }
        $this->assertTrue(rate_limiter::hit('video_vision', 5, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW));
        $this->assertFalse(rate_limiter::hit('video_vision', 6, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW));
        $this->assertFalse(rate_limiter::hit('slide_vision', 5, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW));

        rate_limiter::set_test_now($start + rate_limiter::VISION_WINDOW - 1);
        $this->assertTrue(rate_limiter::hit('video_vision', 5, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW));

        rate_limiter::set_test_now($start + rate_limiter::VISION_WINDOW);
        $this->assertFalse(rate_limiter::hit('video_vision', 5, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW));
    }

    /**
     * The shipped limits are SOLA's.
     *
     * @return void
     */
    public function test_limits(): void {
        global $CFG;
        $this->assertSame([10, 600], [rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW]);
        $this->assertSame([12, 600], [rate_limiter::TRANSCRIBE_MAX, rate_limiter::TRANSCRIBE_WINDOW]);
        $this->assertSame([20, 3600], [rate_limiter::SCORE_MAX, rate_limiter::SCORE_WINDOW]);
        $definitions = [];
        require($CFG->dirroot . '/mod/presenterai/db/caches.php');
        $this->assertGreaterThanOrEqual(rate_limiter::SCORE_WINDOW, $definitions['ratelimit']['ttl']);
    }
}
