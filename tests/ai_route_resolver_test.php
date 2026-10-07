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

use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\ai\core_ai_client;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\stt_client;
use mod_presenterai\local\ai\stt_interface;

/**
 * The auto route, explicit routes, vision never on core, judge models,
 * transcription resolution, accepts_recordings and the test doubles.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\route_resolver
 */
final class ai_route_resolver_test extends \advanced_testcase {
    /**
     * A clean site: every AI setting empty, every double removed.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (
            ['airoute', 'claudeapikey', 'openaiapikey', 'geminiapikey', 'compatibleapikey', 'compatibleendpoint',
                'compatiblemodel', 'compatiblejudgemodel', 'sttendpoint', 'sttapikey', 'sttmodel'] as $name
        ) {
            unset_config($name, 'mod_presenterai');
        }
    }

    /**
     * Remove the doubles.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        core_ai_client::set_test_processor(null);
        parent::tearDown();
    }

    /**
     * Make core AI look available.
     *
     * @return void
     */
    private static function core_on(): void {
        core_ai_client::set_test_processor(fn() => ['success' => true, 'text' => '{}', 'errorcode' => 0]);
    }

    /**
     * The route chosen for scoring, or ''.
     *
     * @return string
     */
    private static function scoring(): string {
        return route_resolver::scoring_route();
    }

    /**
     * Auto tries claude, openai, gemini, compatible, then core, in that order.
     *
     * @return void
     */
    public function test_auto_order(): void {
        $this->assertSame('', self::scoring());
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_SCORE));

        self::core_on();
        $this->assertSame('core', self::scoring());

        set_config('compatibleendpoint', 'https://llm.example.com/v1', 'mod_presenterai');
        $this->assertSame('core', self::scoring(), 'An endpoint without a model is not configured');
        set_config('compatiblemodel', 'llama-3', 'mod_presenterai');
        $this->assertSame('compatible', self::scoring());

        set_config('geminiapikey', 'g', 'mod_presenterai');
        $this->assertSame('gemini', self::scoring());

        set_config('openaiapikey', 'o', 'mod_presenterai');
        $this->assertSame('openai', self::scoring());

        set_config('claudeapikey', 'c', 'mod_presenterai');
        $client = route_resolver::client_for(route_resolver::PURPOSE_SCORE);
        $this->assertSame('claude', $client->route());
        $this->assertSame('claude-sonnet-5-5', $client->model());
    }

    /**
     * An explicit route resolves to that route or to nothing; no fallback.
     *
     * @return void
     */
    public function test_explicit_routes_do_not_fall_back(): void {
        set_config('openaiapikey', 'o', 'mod_presenterai');
        set_config('airoute', 'claude', 'mod_presenterai');
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_SCORE));
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_VISION));

        set_config('airoute', 'openai', 'mod_presenterai');
        $this->assertSame('openai', self::scoring());

        set_config('airoute', 'core', 'mod_presenterai');
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_SCORE), 'Core is not available');
        self::core_on();
        $this->assertSame('core', self::scoring());

        set_config('airoute', 'gemini', 'mod_presenterai');
        set_config('geminimodel', 'gemini-2.5-pro', 'mod_presenterai');
        set_config('geminiapikey', 'g', 'mod_presenterai');
        $this->assertSame('gemini-2.5-pro', route_resolver::client_for(route_resolver::PURPOSE_SCORE)->model());

        set_config('airoute', 'nonsense', 'mod_presenterai');
        $this->assertSame('openai', self::scoring(), 'An unknown value is read as auto');
    }

    /**
     * Vision, slide vision and the judge never use core, and never fall back
     * to a vendor key when scoring runs on core (D26).
     *
     * @return void
     */
    public function test_vision_never_core(): void {
        $purposes = [route_resolver::PURPOSE_VISION, route_resolver::PURPOSE_SLIDE_VISION, route_resolver::PURPOSE_JUDGE];
        self::core_on();
        foreach ($purposes as $p) {
            $this->assertNull(route_resolver::client_for($p), $p);
        }
        set_config('airoute', 'core', 'mod_presenterai');
        set_config('claudeapikey', 'c', 'mod_presenterai');
        set_config('openaiapikey', 'o', 'mod_presenterai');
        set_config('geminiapikey', 'g', 'mod_presenterai');
        set_config('compatibleendpoint', 'https://llm.example.com/v1', 'mod_presenterai');
        set_config('compatiblemodel', 'big', 'mod_presenterai');
        $this->assertSame('core', self::scoring());
        $this->assertTrue(route_resolver::scoring_on_core());
        foreach ($purposes as $p) {
            $this->assertNull(route_resolver::client_for($p), $p . ' fell back to a vendor key under core.');
        }

        // Core chosen but unavailable: scoring is off, and still nothing falls back.
        core_ai_client::set_test_processor(null);
        $this->assertSame('', self::scoring());
        foreach ($purposes as $p) {
            $this->assertNull(route_resolver::client_for($p), $p);
        }
    }

    /**
     * Under auto, a site whose scoring settles on core gets no keyed vision
     * either, even when a judge only model would build (D26).
     *
     * @return void
     */
    public function test_auto_settling_on_core_blocks_vision(): void {
        self::core_on();
        // A compatible endpoint with a judge model but no scoring model: the
        // judge would build, scoring falls through to core.
        set_config('compatibleendpoint', 'https://llm.example.com/v1', 'mod_presenterai');
        set_config('compatiblejudgemodel', 'small', 'mod_presenterai');
        $this->assertSame('core', self::scoring());
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_JUDGE));

        // With a keyed scoring route, auto serves the other purposes as before.
        set_config('compatiblemodel', 'big', 'mod_presenterai');
        $this->assertSame('compatible', self::scoring());
        $this->assertFalse(route_resolver::scoring_on_core());
        $this->assertSame('compatible', route_resolver::client_for(route_resolver::PURPOSE_JUDGE)->route());
        $this->assertSame('compatible', route_resolver::client_for(route_resolver::PURPOSE_SLIDE_VISION)->route());
    }

    /**
     * The judge uses each route's judge model, with defaults.
     *
     * @return void
     */
    public function test_judge_models(): void {
        set_config('claudeapikey', 'c', 'mod_presenterai');
        $this->assertSame('claude-haiku-4-5', route_resolver::client_for(route_resolver::PURPOSE_JUDGE)->model());
        $this->assertSame('claude-sonnet-5-5', route_resolver::client_for(route_resolver::PURPOSE_VISION)->model());
        set_config('claudejudgemodel', 'claude-haiku-5', 'mod_presenterai');
        $this->assertSame('claude-haiku-5', route_resolver::client_for(route_resolver::PURPOSE_JUDGE)->model());

        set_config('airoute', 'compatible', 'mod_presenterai');
        set_config('compatibleendpoint', 'https://llm.example.com/v1', 'mod_presenterai');
        set_config('compatiblemodel', 'big', 'mod_presenterai');
        $this->assertSame('big', route_resolver::client_for(route_resolver::PURPOSE_JUDGE)->model());
        set_config('compatiblejudgemodel', 'small', 'mod_presenterai');
        $this->assertSame('small', route_resolver::client_for(route_resolver::PURPOSE_JUDGE)->model());
    }

    /**
     * Scoring on core AI reports no body language and no slide design
     * feedback, even with a vendor key set (design 3.6, D26).
     *
     * @return void
     */
    public function test_readiness_core_scoring_has_no_body_language(): void {
        self::core_on();
        set_config('airoute', 'core', 'mod_presenterai');
        set_config('openaiapikey', 'o', 'mod_presenterai');

        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_VISION));
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_SLIDE_VISION));
        $readiness = route_resolver::readiness();
        $this->assertSame('core', $readiness['route']);
        $this->assertFalse($readiness['vision']);
        $this->assertFalse($readiness['slidevision']);
        $this->assertTrue($readiness['transcription'], 'Transcription still uses the OpenAI key.');
        $this->assertContains(get_string('aireadiness_novisioncore', 'mod_presenterai'), $readiness['messages']);
        $this->assertContains(get_string('aireadiness_noslidevisioncore', 'mod_presenterai'), $readiness['messages']);

        // On a keyed route neither message appears and slide vision is there.
        set_config('airoute', 'openai', 'mod_presenterai');
        $readiness = route_resolver::readiness();
        $this->assertTrue($readiness['slidevision']);
        $this->assertNotContains(get_string('aireadiness_noslidevisioncore', 'mod_presenterai'), $readiness['messages']);
    }

    /**
     * Transcription: a custom endpoint first, then OpenAI's with the OpenAI key.
     *
     * @return void
     */
    public function test_stt_resolution(): void {
        $this->assertNull(route_resolver::stt());
        set_config('claudeapikey', 'c', 'mod_presenterai');
        $this->assertNull(route_resolver::stt(), 'Claude cannot transcribe');

        set_config('openaiapikey', 'o', 'mod_presenterai');
        $stt = route_resolver::stt();
        $this->assertSame(stt_client::OPENAI_ENDPOINT, $stt->endpoint());
        $this->assertSame('openai', $stt->route());
        $this->assertSame('whisper-1', $stt->model());

        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        set_config('sttmodel', 'large-v3', 'mod_presenterai');
        $stt = route_resolver::stt();
        $this->assertSame('https://stt.example.com/v1/audio/transcriptions', $stt->endpoint());
        $this->assertSame('compatible', $stt->route());
        $this->assertSame('large-v3', $stt->model());
    }

    /**
     * Readiness warns when OpenAI transcription can't take the longest recording the site allows.
     *
     * @return void
     */
    public function test_readiness_warns_about_openai_size_limit(): void {
        set_config('openaiapikey', 'o', 'mod_presenterai');
        set_config('quality', 'high_720p', 'mod_presenterai');
        set_config('maxrecordingseconds', 720, 'mod_presenterai');
        $minutes = (int) floor(stt_client::openai_max_seconds('video') / 60);
        $warning = get_string('aireadiness_sttsizelimit', 'mod_presenterai', $minutes);
        $this->assertContains($warning, route_resolver::readiness()['messages']);

        // A self hosted endpoint has no such limit.
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        $this->assertNotContains($warning, route_resolver::readiness()['messages']);

        // Nor does a short enough maximum on OpenAI.
        set_config('sttendpoint', '', 'mod_presenterai');
        set_config('maxrecordingseconds', 60, 'mod_presenterai');
        $this->assertNotContains($warning, route_resolver::readiness()['messages']);

        // With ffmpeg the server can extract and cut what doesn't fit, so there's nothing to warn about.
        set_config('maxrecordingseconds', 720, 'mod_presenterai');
        $this->assertContains($warning, route_resolver::readiness()['messages']);
        $ffmpeg = make_request_directory() . '/ffmpeg';
        file_put_contents($ffmpeg, '#!/bin/sh');
        chmod($ffmpeg, 0755);
        set_config('ffmpegpath', $ffmpeg, 'mod_presenterai');
        $this->assertNotContains($warning, route_resolver::readiness()['messages']);
    }

    /**
     * accepts_recordings is false only with scoring and no transcription.
     *
     * @return void
     */
    public function test_accepts_recordings_truth_table(): void {
        // No AI at all: a hand graded activity, recordings accepted.
        $this->assertTrue(route_resolver::accepts_recordings());
        $this->assertFalse(route_resolver::ai_ready());

        // Core only: scoring without transcription, refused (plan 5.1).
        self::core_on();
        $this->assertFalse(route_resolver::accepts_recordings());
        $this->assertFalse(route_resolver::ai_ready());
        $readiness = route_resolver::readiness();
        $this->assertTrue($readiness['scoring']);
        $this->assertFalse($readiness['transcription']);
        $this->assertFalse($readiness['vision']);
        $this->assertSame('core', $readiness['route']);
        $this->assertContains(get_string('aireadiness_blocked', 'mod_presenterai'), $readiness['messages']);

        // Transcription without scoring: accepted, not ready.
        core_ai_client::set_test_processor(null);
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        $this->assertTrue(route_resolver::accepts_recordings());
        $this->assertFalse(route_resolver::ai_ready());

        // Both: accepted and ready.
        set_config('openaiapikey', 'o', 'mod_presenterai');
        $this->assertTrue(route_resolver::accepts_recordings());
        $this->assertTrue(route_resolver::ai_ready());
    }

    /**
     * readiness names an unconfigured explicit route.
     *
     * @return void
     */
    public function test_readiness_messages(): void {
        $readiness = route_resolver::readiness();
        $this->assertContains(get_string('aireadiness_noscoring', 'mod_presenterai'), $readiness['messages']);
        $this->assertContains(get_string('aireadiness_notranscription', 'mod_presenterai'), $readiness['messages']);

        set_config('airoute', 'gemini', 'mod_presenterai');
        $readiness = route_resolver::readiness();
        $this->assertContains(get_string(
            'aireadiness_routeunconfigured',
            'mod_presenterai',
            get_string('airoute_gemini', 'mod_presenterai')
        ), $readiness['messages']);
    }

    /**
     * Doubles drive client_for, stt, ai_ready, accepts_recordings and scoring_route.
     *
     * @return void
     */
    public function test_doubles(): void {
        $client = new class implements client_interface {
            /**
             * Generate.
             *
             * @param string $system System.
             * @param string $user User.
             * @param array $opts Options.
             * @return string
             */
            public function generate_text(string $system, string $user, array $opts = []): string {
                return '{}';
            }

            /**
             * Images.
             *
             * @return bool
             */
            public function supports_images(): bool {
                return true;
            }

            /**
             * Schema.
             *
             * @return bool
             */
            public function supports_json_schema(): bool {
                return true;
            }

            /**
             * Route.
             *
             * @return string
             */
            public function route(): string {
                return 'gemini';
            }

            /**
             * Model.
             *
             * @return string
             */
            public function model(): string {
                return 'fake';
            }

            /**
             * Usage.
             *
             * @return array
             */
            public function last_usage(): array {
                return ['prompttokens' => 0, 'completiontokens' => 0, 'model' => 'fake', 'provider' => 'gemini'];
            }
        };
        $stt = new class implements stt_interface {
            /**
             * Transcribe.
             *
             * @param string $filepath Path.
             * @param string $mime Type.
             * @return array
             */
            public function transcribe(string $filepath, string $mime): array {
                return ['text' => 'hi', 'model' => 'fake'];
            }

            /**
             * Model.
             *
             * @return string
             */
            public function model(): string {
                return 'fake';
            }

            /**
             * Route.
             *
             * @return string
             */
            public function route(): string {
                return 'compatible';
            }
        };

        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $this->assertSame($client, route_resolver::client_for(route_resolver::PURPOSE_SCORE));
        $this->assertSame('gemini', route_resolver::scoring_route());
        $this->assertFalse(route_resolver::accepts_recordings());
        $this->assertFalse(route_resolver::ai_ready());

        route_resolver::set_test_stt($stt);
        $this->assertSame($stt, route_resolver::stt());
        $this->assertTrue(route_resolver::accepts_recordings());
        $this->assertTrue(route_resolver::ai_ready());

        // A null double forces "none" even when a key is configured.
        set_config('openaiapikey', 'o', 'mod_presenterai');
        route_resolver::set_test_client(route_resolver::PURPOSE_VISION, null);
        $this->assertNull(route_resolver::client_for(route_resolver::PURPOSE_VISION));
        route_resolver::set_test_stt(null);
        $this->assertNull(route_resolver::stt());

        route_resolver::reset_test_doubles();
        $this->assertSame('openai', route_resolver::client_for(route_resolver::PURPOSE_VISION)->route());
        $this->assertNotNull(route_resolver::stt());
    }
}
