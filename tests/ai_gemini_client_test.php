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

use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\gemini_client;

/**
 * The Gemini client: Google's OpenAI compatibility base, its own route name.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\gemini_client
 */
final class ai_gemini_client_test extends \advanced_testcase {
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
     * The request goes to the compatibility base with the Gemini key.
     *
     * @return void
     */
    public function test_base_url_and_route(): void {
        $this->resetAfterTest();
        http_client::set_test_handler(fn() => [
            'status' => 200,
            'body' => file_get_contents(__DIR__ . '/fixtures/ai/openai_chat.json'),
        ]);
        $client = new gemini_client('gemini-key-789', 'gemini-2.5-flash');

        $this->assertSame('{"overall":8}', $client->generate_text('s', 'u'));

        $request = http_client::last_requests()[0];
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            $request['url']
        );
        $this->assertContains('Authorization: Bearer gemini-key-789', $request['headers']);
        $this->assertSame('gemini-2.5-flash', json_decode($request['body'], true)['model']);
        $this->assertSame('gemini', $client->route());
        $this->assertSame('gemini', $client->last_usage()['provider']);
        $this->assertTrue($client->supports_images());
    }

    /**
     * Thinking Gemini models are asked to think little and given room for it; older ones aren't.
     *
     * @return void
     */
    public function test_thinking_control(): void {
        $body = (new gemini_client('k', 'gemini-2.5-flash'))->build_body('s', 'u', ['max_tokens' => 400]);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertSame(400 + gemini_client::REASONING_HEADROOM, $body['max_tokens']);

        $body = (new gemini_client('k', 'gemini-2.0-flash'))->build_body('s', 'u', ['max_tokens' => 400]);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertSame(400, $body['max_tokens']);
    }
}
