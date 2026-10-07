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
use mod_presenterai\local\ai\claude_client;
use mod_presenterai\local\ai\http_client;

/**
 * The Claude client's request shape and SOLA's rules (a) to (e).
 *
 * claude-opus-5-5 stands for the models that reject temperature and a forced
 * tool choice; claude-sonnet-4-5 for the ones that accept both.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\claude_client
 */
final class ai_claude_client_test extends \advanced_testcase {
    /** @var string A recognisable fake key. */
    private const KEY = 'sk-ant-test-SECRETKEY-123';

    /** @var array A schema in the shape the scorer sends. */
    private const SCHEMA = [
        'name' => 'score_presentation',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'overall' => ['type' => 'integer'],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['overall', 'summary'],
            'additionalProperties' => false,
        ],
    ];

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
     * A fixture's contents.
     *
     * @param string $name The file name under tests/fixtures/ai.
     * @return string
     */
    private static function fixture(string $name): string {
        return file_get_contents(__DIR__ . '/fixtures/ai/' . $name);
    }

    /**
     * Answer every request with the given bodies in turn.
     *
     * @param array $responses List of [status, body].
     * @return void
     */
    private static function respond(array $responses): void {
        http_client::set_test_handler(function () use (&$responses) {
            [$status, $body] = array_shift($responses);
            return ['status' => $status, 'body' => $body];
        });
    }

    /**
     * The decoded body of the nth request.
     *
     * @param int $n Zero based.
     * @return array
     */
    private static function sent(int $n = 0): array {
        return json_decode(http_client::last_requests()[$n]['body'], true);
    }

    /**
     * URL, headers, system blocks, image blocks and the returned tool input.
     *
     * @return void
     */
    public function test_request_shape_and_tool_input(): void {
        $this->resetAfterTest();
        self::respond([[200, self::fixture('claude_tool_use.json')]]);
        $client = new claude_client(self::KEY, 'claude-sonnet-4-5');

        $out = $client->generate_text('You are a judge.', 'Score this.', [
            'schema' => self::SCHEMA,
            'images' => [['mime' => 'image/jpeg', 'base64' => 'QUJD']],
            'max_tokens' => 900,
        ]);

        $this->assertSame(['overall' => 7, 'summary' => 'Clear and well paced.'], json_decode($out, true));
        $request = http_client::last_requests()[0];
        $this->assertSame('https://api.anthropic.com/v1/messages', $request['url']);
        $this->assertContains('x-api-key: ' . self::KEY, $request['headers']);
        $this->assertContains('anthropic-version: 2023-06-01', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);

        $body = self::sent();
        $this->assertSame('claude-sonnet-4-5', $body['model']);
        $this->assertSame(900, $body['max_tokens']);
        $this->assertSame(
            [['type' => 'text', 'text' => 'You are a judge.', 'cache_control' => ['type' => 'ephemeral']]],
            $body['system']
        );
        $content = $body['messages'][0]['content'];
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame(['type' => 'text', 'text' => 'Score this.'], $content[0]);
        $this->assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD']],
            $content[1]
        );
        $this->assertSame('score_presentation', $body['tools'][0]['name']);
        $this->assertSame(self::SCHEMA['schema'], $body['tools'][0]['input_schema']);
        $this->assertTrue($client->supports_images());
        $this->assertTrue($client->supports_json_schema());
        $this->assertSame('claude', $client->route());
    }

    /**
     * Rule (a): temperature only for allow listed models, never for Opus 5.5,
     * even with thinking on; thinking is off by default.
     *
     * @return void
     */
    public function test_temperature_rule(): void {
        $this->resetAfterTest();
        $old = new claude_client(self::KEY, 'claude-sonnet-4-5');
        $this->assertSame(0.2, $old->build_body('s', 'u', ['temperature' => 0.2])['temperature']);
        $this->assertArrayNotHasKey('thinking', $old->build_body('s', 'u', []));
        $this->assertSame(1, $old->build_body('s', 'u', ['thinking' => true])['temperature']);

        foreach (['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-mythos-5-1', 'claude-future-9'] as $model) {
            $client = new claude_client(self::KEY, $model);
            $this->assertArrayNotHasKey('temperature', $client->build_body('s', 'u', ['temperature' => 0.2]), $model);
            $thinking = $client->build_body('s', 'u', ['thinking' => true, 'temperature' => 0.2]);
            $this->assertArrayNotHasKey('temperature', $thinking, $model);
            $this->assertSame(['type' => 'adaptive'], $thinking['thinking']);
        }
    }

    /**
     * Rule (b): a forced tool for older models; auto plus a separate steering
     * block for the Opus 5.5 generation.
     *
     * @return void
     */
    public function test_tool_choice_rule(): void {
        $this->resetAfterTest();
        $old = (new claude_client(self::KEY, 'claude-sonnet-4-5'))->build_body('sys', 'u', ['schema' => self::SCHEMA]);
        $this->assertSame(['type' => 'tool', 'name' => 'score_presentation'], $old['tool_choice']);
        $this->assertCount(1, $old['system']);

        $new = (new claude_client(self::KEY, 'claude-opus-5-5'))->build_body('sys', 'u', ['schema' => self::SCHEMA]);
        $this->assertSame(['type' => 'auto'], $new['tool_choice']);
        $this->assertCount(2, $new['system']);
        $this->assertSame('sys', $new['system'][0]['text']);
        $this->assertArrayHasKey('cache_control', $new['system'][0]);
        $this->assertSame(
            ['type' => 'text', 'text' => 'Respond by calling the score_presentation tool. Do not answer in prose.'],
            $new['system'][1]
        );
    }

    /**
     * Rule (c): "strict" appears nowhere in any request body.
     *
     * @return void
     */
    public function test_strict_never_sent(): void {
        $this->resetAfterTest();
        foreach (['claude-opus-5-5', 'claude-sonnet-4-5'] as $model) {
            self::respond([[200, self::fixture('claude_tool_use.json')]]);
            (new claude_client(self::KEY, $model))->generate_text('s', 'u', ['schema' => self::SCHEMA]);
            $this->assertStringNotContainsString('"strict"', http_client::last_requests()[0]['body'], $model);
        }
    }

    /**
     * Rule (b) retry: one retry when auto returns prose, usage summed across both.
     *
     * @return void
     */
    public function test_retry_when_no_tool_use(): void {
        $this->resetAfterTest();
        self::respond([[200, self::fixture('claude_text.json')], [200, self::fixture('claude_tool_use.json')]]);
        $client = new claude_client(self::KEY, 'claude-opus-5-5');

        $out = $client->generate_text('s', 'u', ['schema' => self::SCHEMA]);

        $this->assertSame(7, json_decode($out, true)['overall']);
        $this->assertCount(2, http_client::last_requests());
        $usage = $client->last_usage();
        // 1000 from the first; 1200 + 300 + 50 cache tokens from the second.
        $this->assertSame(2550, $usage['prompttokens']);
        $this->assertSame(130, $usage['completiontokens']);
        $this->assertSame('claude', $usage['provider']);
    }

    /**
     * When the retry fails the first response stands; an older model is never retried.
     *
     * @return void
     */
    public function test_retry_failure_keeps_first_response(): void {
        $this->resetAfterTest();
        self::respond([[200, self::fixture('claude_text.json')], [503, '{"type":"error","error":{"type":"overloaded_error"}}']]);
        $client = new claude_client(self::KEY, 'claude-opus-5-5');
        $this->assertSame('I would rather explain in prose.', $client->generate_text('s', 'u', ['schema' => self::SCHEMA]));
        $this->assertCount(2, http_client::last_requests());
        $this->assertDebuggingCalled();

        self::respond([[200, self::fixture('claude_text.json')]]);
        $old = new claude_client(self::KEY, 'claude-sonnet-4-5');
        $this->assertSame('I would rather explain in prose.', $old->generate_text('s', 'u', ['schema' => self::SCHEMA]));
        $this->assertCount(1, http_client::last_requests());
    }

    /**
     * Rule (d): a refusal throws, not transient, with usage already recorded.
     *
     * @return void
     */
    public function test_refusal_records_usage(): void {
        $this->resetAfterTest();
        self::respond([[200, self::fixture('claude_refusal.json')]]);
        $client = new claude_client(self::KEY, 'claude-opus-5-5');
        try {
            $client->generate_text('s', 'u');
            $this->fail('Expected refusal');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::REFUSAL, $e->reason);
            $this->assertFalse($e->transient);
        }
        $this->assertSame(800, $client->last_usage()['prompttokens']);
        $this->assertSame(3, $client->last_usage()['completiontokens']);
    }

    /**
     * Rule (e): plain text skips thinking blocks; usage resets between calls.
     *
     * @return void
     */
    public function test_text_skips_thinking_and_usage_resets(): void {
        $this->resetAfterTest();
        self::respond([[200, self::fixture('claude_text.json')], [200, self::fixture('claude_refusal.json')]]);
        $client = new claude_client(self::KEY, 'claude-opus-5-5');
        $this->assertSame('I would rather explain in prose.', $client->generate_text('s', 'u'));
        $this->assertSame(1000, $client->last_usage()['prompttokens']);
        try {
            $client->generate_text('s', 'u');
        } catch (ai_exception $e) {
            $this->assertSame(800, $client->last_usage()['prompttokens']);
        }
    }

    /**
     * Errors: the API message lands in debuginfo, the key never does.
     *
     * @return void
     */
    public function test_errors_never_carry_the_key(): void {
        $this->resetAfterTest();
        $cases = [
            [400, self::fixture('claude_error.json'), ai_exception::HTTP_4XX, false],
            [429, '{"type":"error","error":{"type":"rate_limit_error","message":"slow down ' . self::KEY . '"}}',
                ai_exception::HTTP_429, true],
            [529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', ai_exception::HTTP_5XX, true],
            [200, 'not json at all', ai_exception::BAD_RESPONSE, false],
            [200, '{"content":[]}', ai_exception::BAD_RESPONSE, false],
        ];
        foreach ($cases as [$status, $body, $reason, $transient]) {
            self::respond([[$status, $body]]);
            try {
                (new claude_client(self::KEY, 'claude-sonnet-4-5'))->generate_text('s', 'u');
                $this->fail('Expected ' . $reason);
            } catch (ai_exception $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($transient, $e->transient);
                $this->assertStringNotContainsString(self::KEY, $e->debuginfo);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertLessThanOrEqual(420, \core_text::strlen($e->debuginfo));
            }
        }
        self::respond([[400, self::fixture('claude_error.json')]]);
        try {
            (new claude_client(self::KEY, 'claude-sonnet-4-5'))->generate_text('s', 'u');
        } catch (ai_exception $e) {
            $this->assertStringContainsString('max_tokens: must be positive', $e->debuginfo);
        }
    }

    /**
     * No key means not_configured, and nothing is sent.
     *
     * @return void
     */
    public function test_no_key(): void {
        $this->resetAfterTest();
        self::respond([]);
        $this->expectException(ai_exception::class);
        try {
            (new claude_client('', 'claude-sonnet-4-5'))->generate_text('s', 'u');
        } finally {
            $this->assertSame([], http_client::last_requests());
        }
    }
}
