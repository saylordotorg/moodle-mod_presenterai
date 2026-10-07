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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\stt_client;

/**
 * Transcription: the multipart request, the 300 second timeout, the result
 * and the failures.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\stt_client
 */
final class ai_stt_client_test extends \advanced_testcase {
    /** @var string The sample file. */
    private const SAMPLE = __DIR__ . '/fixtures/ai/sample.webm';

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
     * Multipart file and model, Bearer key, 300 seconds, trimmed text back.
     *
     * @return void
     */
    public function test_multipart_request(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => [
            'status' => 200,
            'body' => file_get_contents(__DIR__ . '/fixtures/ai/whisper.json'),
        ]);
        $client = new stt_client(stt_client::OPENAI_ENDPOINT, 'sk-stt-key', '');

        $result = $client->transcribe(self::SAMPLE, 'video/webm');

        $this->assertSame(['text' => 'Good morning everyone, today I will talk about bees.', 'model' => 'whisper-1'], $result);
        $request = http_client::last_requests()[0];
        $this->assertSame(stt_client::OPENAI_ENDPOINT, $request['url']);
        $this->assertSame(['Authorization: Bearer sk-stt-key'], $request['headers']);
        $this->assertSame(300, $request['options']['timeout']);
        $this->assertIsArray($request['body']);
        $this->assertInstanceOf(\CURLFile::class, $request['body']['file']);
        $this->assertSame(self::SAMPLE, $request['body']['file']->getFilename());
        $this->assertSame('video/webm', $request['body']['file']->getMimeType());
        $this->assertSame('whisper-1', $request['body']['model']);
        $this->assertSame('openai', $client->route());
    }

    /**
     * A self hosted endpoint is the compatible route and may have no key.
     *
     * @return void
     */
    public function test_compatible_endpoint_without_key(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => '{"text":"hello"}']);
        $client = new stt_client('https://stt.example.com/v1/audio/transcriptions', '', 'Systran/faster-whisper');
        $this->assertSame('hello', $client->transcribe(self::SAMPLE, 'video/webm')['text']);
        $this->assertSame([], http_client::last_requests()[0]['headers']);
        $this->assertSame('compatible', $client->route());
        $this->assertSame('Systran/faster-whisper', $client->model());
    }

    /**
     * Empty text, a missing file, statuses and an unsafe endpoint.
     *
     * @return void
     */
    public function test_failures(): void {
        $this->resetAfterTest();
        $client = new stt_client(stt_client::OPENAI_ENDPOINT, 'sk-stt-key');
        $cases = [
            [200, '{"text":"   "}', ai_exception::BAD_RESPONSE, false],
            [200, 'garbage', ai_exception::BAD_RESPONSE, false],
            [429, '{"error":{"message":"slow"}}', ai_exception::HTTP_429, true],
            [502, '', ai_exception::HTTP_5XX, true],
            [413, '{"error":{"message":"too large for sk-stt-key"}}', ai_exception::TOO_LARGE, false],
            [400, '{"error":{"message":"bad"}}', ai_exception::HTTP_4XX, false],
        ];
        foreach ($cases as [$status, $body, $reason, $transient]) {
            http_client::set_test_handler(fn() => ['status' => $status, 'body' => $body]);
            try {
                $client->transcribe(self::SAMPLE, 'video/webm');
                $this->fail('Expected ' . $reason);
            } catch (ai_exception $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($transient, $e->transient);
                $this->assertStringNotContainsString('sk-stt-key', $e->debuginfo);
            }
        }

        try {
            $client->transcribe('/nonexistent/file.webm', 'video/webm');
            $this->fail('Expected bad_response');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::BAD_RESPONSE, $e->reason);
        }

        try {
            (new stt_client('https://192.168.1.10/v1/audio/transcriptions', 'k'))->transcribe(self::SAMPLE, 'video/webm');
            $this->fail('Expected unsafe_endpoint');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::UNSAFE_ENDPOINT, $e->reason);
        }
    }

    /**
     * A file over OpenAI's 25 MB limit is refused before it's uploaded; a self hosted endpoint takes it.
     *
     * @return void
     */
    public function test_openai_size_limit(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/big.webm';
        $handle = fopen($path, 'w');
        ftruncate($handle, stt_client::OPENAI_MAX_BYTES + 1);
        fclose($handle);
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => '{"text":"hello"}']);

        try {
            (new stt_client(stt_client::OPENAI_ENDPOINT, 'sk-stt-key'))->transcribe($path, 'video/webm');
            $this->fail('An oversized file was sent to OpenAI.');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::TOO_LARGE, $e->reason);
            $this->assertFalse($e->transient);
        }
        $this->assertSame([], http_client::last_requests());

        $client = new stt_client('https://stt.example.com/v1/audio/transcriptions', '');
        $this->assertSame('hello', $client->transcribe($path, 'video/webm')['text']);
    }

    /**
     * The longest recording that fits under the limit follows the quality preset.
     *
     * @return void
     */
    public function test_openai_max_seconds(): void {
        $this->resetAfterTest();
        set_config('quality', 'standard_480p', 'mod_presenterai');
        $standard = stt_client::openai_max_seconds('video');
        // 540 kbps with 15 percent headroom is about 77.6 KB a second.
        $this->assertGreaterThan(300, $standard);
        $this->assertLessThan(400, $standard);
        set_config('quality', 'high_720p', 'mod_presenterai');
        $this->assertLessThan($standard, stt_client::openai_max_seconds('video'));
        $this->assertGreaterThan(stt_client::openai_max_seconds('video'), stt_client::openai_max_seconds('audio'));
    }

    /**
     * Warming needs the setting and a custom endpoint.
     *
     * @return void
     */
    public function test_warm_enabled(): void {
        $this->resetAfterTest();
        $this->assertFalse(stt_client::warm_enabled());
        set_config('sttwarm', 1, 'mod_presenterai');
        $this->assertFalse(stt_client::warm_enabled());
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        $this->assertTrue(stt_client::warm_enabled());
        set_config('sttwarm', 0, 'mod_presenterai');
        $this->assertFalse(stt_client::warm_enabled());
    }
}
