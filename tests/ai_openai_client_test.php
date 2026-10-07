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
use mod_presenterai\local\ai\openai_client;

/**
 * The OpenAI client and the compatible route that shares it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\openai_client
 */
final class ai_openai_client_test extends \advanced_testcase {
    /** @var string A recognisable fake key. */
    private const KEY = 'sk-openai-test-SECRETKEY-456';

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
     * Answer every request with one status and body.
     *
     * @param int $status The status.
     * @param string $body The body.
     * @return void
     */
    private static function respond(int $status, string $body): void {
        http_client::set_test_handler(fn() => ['status' => $status, 'body' => $body]);
    }

    /**
     * The chat fixture.
     *
     * @return string
     */
    private static function chat(): string {
        return file_get_contents(__DIR__ . '/fixtures/ai/openai_chat.json');
    }

    /**
     * URL, bearer header, messages, image parts, strict json_schema, usage.
     *
     * @return void
     */
    public function test_request_shape(): void {
        $this->resetAfterTest();
        self::respond(200, self::chat());
        $client = new openai_client(self::KEY, 'gpt-4o-mini');
        $schema = ['name' => 'score', 'schema' => [
            'type' => 'object',
            'properties' => ['overall' => ['type' => 'integer']],
            'required' => ['overall'],
            'additionalProperties' => false,
        ]];

        $out = $client->generate_text('sys', 'user text', [
            'schema' => $schema,
            'images' => [['mime' => 'image/png', 'base64' => 'UE5H']],
            'temperature' => 0.3,
        ]);

        $this->assertSame('{"overall":8}', $out);
        $request = http_client::last_requests()[0];
        $this->assertSame('https://api.openai.com/v1/chat/completions', $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::KEY, $request['headers']);
        $body = json_decode($request['body'], true);
        $this->assertSame('gpt-4o-mini', $body['model']);
        $this->assertSame(['role' => 'system', 'content' => 'sys'], $body['messages'][0]);
        $this->assertSame([
            ['type' => 'text', 'text' => 'user text'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,UE5H']],
        ], $body['messages'][1]['content']);
        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'score', 'schema' => $schema['schema'], 'strict' => true],
        ], $body['response_format']);
        $this->assertSame(4096, $body['max_tokens']);
        $this->assertSame(0.3, $body['temperature']);

        $usage = $client->last_usage();
        $this->assertSame(500, $usage['prompttokens']);
        $this->assertSame(20, $usage['completiontokens']);
        $this->assertSame('openai', $usage['provider']);
        $this->assertSame('openai', $client->route());
    }

    /**
     * GPT-5 and the o series get max_completion_tokens and no temperature.
     *
     * @return void
     */
    public function test_reasoning_models(): void {
        foreach (['gpt-5', 'gpt-5-mini', 'o1', 'o3-mini', 'o4-mini'] as $model) {
            $body = (new openai_client(self::KEY, $model))->build_body('s', 'u', ['max_tokens' => 700, 'temperature' => 0.4]);
            // The ceiling is raised so thinking can't use up the whole budget.
            $this->assertSame(700 + openai_client::REASONING_HEADROOM, $body['max_completion_tokens'], $model);
            $this->assertArrayNotHasKey('max_tokens', $body, $model);
            $this->assertArrayNotHasKey('temperature', $body, $model);
            if ($model === 'o1') {
                $this->assertArrayNotHasKey('reasoning_effort', $body, $model);
            } else {
                $this->assertSame('low', $body['reasoning_effort'], $model);
            }
        }
        $plain = (new openai_client(self::KEY, 'gpt-4o-mini'))->build_body('s', 'u', ['max_tokens' => 700]);
        $this->assertSame(700, $plain['max_tokens']);
        $this->assertArrayNotHasKey('reasoning_effort', $plain);
        $this->assertFalse(openai_client::uses_max_completion_tokens('gpt-4o-mini'));
        $this->assertFalse(openai_client::uses_max_completion_tokens('omni-model'));
        $body = (new openai_client(self::KEY, 'gpt-4o-mini'))->build_body('s', 'u', []);
        $this->assertSame('u', $body['messages'][1]['content']);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    /**
     * A refusal field is a refusal; usage is still recorded.
     *
     * @return void
     */
    public function test_refusal(): void {
        $this->resetAfterTest();
        self::respond(200, file_get_contents(__DIR__ . '/fixtures/ai/openai_refusal.json'));
        $client = new openai_client(self::KEY, 'gpt-4o-mini');
        try {
            $client->generate_text('s', 'u');
            $this->fail('Expected refusal');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::REFUSAL, $e->reason);
            $this->assertFalse($e->transient);
        }
        $this->assertSame(400, $client->last_usage()['prompttokens']);
    }

    /**
     * Status and parse failures map to reasons, and never carry the key.
     *
     * @return void
     */
    public function test_errors(): void {
        $this->resetAfterTest();
        $cases = [
            [429, '{"error":{"type":"rate_limit","message":"Rate limit for ' . self::KEY . '"}}', ai_exception::HTTP_429, true],
            [503, '{"error":{"message":"down"}}', ai_exception::HTTP_5XX, true],
            [400, '{"error":{"type":"invalid_request_error","message":"bad schema"}}', ai_exception::HTTP_4XX, false],
            [200, '<html>proxy</html>', ai_exception::BAD_RESPONSE, false],
            [200, '{"choices":[{"message":{"content":null}}]}', ai_exception::BAD_RESPONSE, false],
        ];
        foreach ($cases as [$status, $body, $reason, $transient]) {
            self::respond($status, $body);
            try {
                (new openai_client(self::KEY, 'gpt-4o-mini'))->generate_text('s', 'u');
                $this->fail('Expected ' . $reason);
            } catch (ai_exception $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($transient, $e->transient);
                $this->assertStringNotContainsString(self::KEY, $e->debuginfo);
            }
        }
    }

    /**
     * The compatible route: base or full URL, optional key, its own route name.
     *
     * @return void
     */
    public function test_compatible_route(): void {
        $this->resetAfterTest();
        self::respond(200, self::chat());
        $client = new openai_client('', 'llama-3', 'https://llm.example.com/v1/', 'compatible');
        $client->generate_text('s', 'u');
        $request = http_client::last_requests()[0];
        $this->assertSame('https://llm.example.com/v1/chat/completions', $request['url']);
        $this->assertSame(['Content-Type: application/json'], $request['headers']);
        $this->assertSame('compatible', $client->route());
        $this->assertSame('compatible', $client->last_usage()['provider']);

        $full = new openai_client('k', 'llama-3', 'https://llm.example.com/v1/chat/completions', 'compatible');
        $this->assertSame('https://llm.example.com/v1/chat/completions', $full->endpoint());

        // The openai route without a key is not configured.
        $this->expectException(ai_exception::class);
        (new openai_client('', 'gpt-4o-mini'))->generate_text('s', 'u');
    }

    /**
     * A reply stopped by the token limit is truncated, not a bad reply, and its spend is kept.
     *
     * @return void
     */
    public function test_length_finish_is_truncated(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => json_encode([
            'model' => 'gpt-5-mini',
            'choices' => [['index' => 0, 'finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => '']]],
            'usage' => [
                'prompt_tokens' => 900, 'completion_tokens' => 8800, 'total_tokens' => 9700,
                'completion_tokens_details' => ['reasoning_tokens' => 8800],
            ],
        ])]);
        $client = new openai_client(self::KEY, 'gpt-5-mini');
        try {
            $client->generate_text('s', 'u', ['max_tokens' => 600]);
            $this->fail('A cut off reply was returned.');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::TRUNCATED, $e->reason);
            $this->assertFalse($e->transient);
        }
        // OpenAI counts thinking inside completion_tokens: no double count.
        $this->assertSame(8800, $client->last_usage()['completiontokens']);
    }

    /**
     * Thinking reported outside completion_tokens, as Google's endpoint does, is added to it.
     *
     * @return void
     */
    public function test_thinking_outside_completion_tokens_is_counted(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => ['status' => 200, 'body' => json_encode([
            'model' => 'gemini-2.5-flash',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{}']]],
            'usage' => [
                'prompt_tokens' => 1000, 'completion_tokens' => 200, 'total_tokens' => 1700,
                'completion_tokens_details' => ['reasoning_tokens' => 500],
            ],
        ])]);
        $client = new \mod_presenterai\local\ai\gemini_client(self::KEY, 'gemini-2.5-flash');
        $client->generate_text('s', 'u');
        $this->assertSame(1000, $client->last_usage()['prompttokens']);
        $this->assertSame(700, $client->last_usage()['completiontokens']);
    }
}
