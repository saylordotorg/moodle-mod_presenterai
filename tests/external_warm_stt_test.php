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

use core_external\external_api;
use mod_presenterai\external\warm_stt;
use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\rate_limiter;

/**
 * The warm up ping: off by default, a GET to the server base when on,
 * errors swallowed, and the submit capability required.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\warm_stt
 */
final class external_warm_stt_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \stdClass A learner. */
    private \stdClass $learner;

    /** @var \stdClass The course. */
    private \stdClass $course;

    /**
     * One course, one activity, one learner, and an HTTP seam that records.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => 'ok']);
    }

    /**
     * Remove the HTTP seam.
     *
     * @return void
     */
    protected function tearDown(): void {
        http_client::set_test_handler(null);
        parent::tearDown();
    }

    /**
     * Call through the external API layer, as the browser does.
     *
     * @return array
     */
    private function call(): array {
        $result = warm_stt::execute((int) $this->instance->cmid);
        return external_api::clean_returnvalue(warm_stt::execute_returns(), $result);
    }

    /**
     * Off by default: nothing is sent.
     *
     * @return void
     */
    public function test_off_by_default(): void {
        $this->setUser($this->learner);
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        $this->assertSame(['warmed' => false], $this->call());
        $this->assertSame([], http_client::last_requests());
    }

    /**
     * On: a short GET to the server base, no key sent.
     *
     * @return void
     */
    public function test_pings_base(): void {
        $this->setUser($this->learner);
        set_config('sttwarm', 1, 'mod_presenterai');
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        set_config('sttapikey', 'stt-secret', 'mod_presenterai');

        $this->assertSame(['warmed' => true], $this->call());
        $requests = http_client::last_requests();
        $this->assertCount(1, $requests);
        $this->assertSame('https://stt.example.com', $requests[0]['url']);
        $this->assertSame('GET', $requests[0]['options']['method']);
        $this->assertSame(4, $requests[0]['options']['timeout']);
        $this->assertSame(3, $requests[0]['options']['connecttimeout']);
        $this->assertSame([], $requests[0]['headers']);
    }

    /**
     * Only one ping a minute for the whole site, whoever asks.
     *
     * @return void
     */
    public function test_throttled_site_wide(): void {
        set_config('sttwarm', 1, 'mod_presenterai');
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $now = time();
        rate_limiter::set_test_now($now);
        try {
            $this->setUser($this->learner);
            $this->assertSame(['warmed' => true], $this->call());
            $this->assertSame(['warmed' => false], $this->call());
            $this->setUser($other);
            $this->assertSame(['warmed' => false], $this->call());
            $this->assertCount(1, http_client::last_requests());

            rate_limiter::set_test_now($now + warm_stt::INTERVAL);
            $this->assertSame(['warmed' => true], $this->call());
            $this->assertCount(2, http_client::last_requests());
        } finally {
            rate_limiter::set_test_now(null);
        }
    }

    /**
     * A failed ping is swallowed.
     *
     * @return void
     */
    public function test_errors_swallowed(): void {
        $this->setUser($this->learner);
        set_config('sttwarm', 1, 'mod_presenterai');
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        http_client::set_test_handler(fn() => ['status' => 0, 'body' => '', 'errno' => 28, 'error' => 'timeout']);
        $this->assertSame(['warmed' => true], $this->call());
        $this->assertDebuggingCalled();
    }

    /**
     * Someone who cannot submit is refused.
     *
     * @return void
     */
    public function test_requires_submit(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\require_login_exception::class);
        $this->call();
    }

    /**
     * A teacher, who holds view but not submit, is refused too.
     *
     * @return void
     */
    public function test_teacher_refused(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        $this->call();
    }
}
