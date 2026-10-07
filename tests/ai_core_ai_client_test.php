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
use mod_presenterai\local\ai\core_ai_client;

/**
 * The core AI adapter: flattening, error mapping, the response key fallback
 * and the real 4.5 branch with no provider configured.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\core_ai_client
 */
final class ai_core_ai_client_test extends \advanced_testcase {
    /**
     * Remove the processor seam.
     *
     * @return void
     */
    protected function tearDown(): void {
        core_ai_client::set_test_processor(null);
        parent::tearDown();
    }

    /**
     * The processor gets the context, the user and one flattened prompt.
     *
     * @return void
     */
    public function test_flattened_prompt_through_processor(): void {
        $seen = [];
        core_ai_client::set_test_processor(function (int $contextid, int $userid, string $prompt) use (&$seen) {
            $seen = [$contextid, $userid, $prompt];
            return ['success' => true, 'text' => '{"overall":5}', 'errorcode' => 0];
        });
        $client = new core_ai_client();

        $out = $client->generate_text('Be fair.', 'Transcript here.', [
            'contextid' => 42, 'userid' => 7, 'schema' => ['name' => 'x', 'schema' => []],
        ]);

        $this->assertSame('{"overall":5}', $out);
        $this->assertSame(42, $seen[0]);
        $this->assertSame(7, $seen[1]);
        $this->assertSame("Instructions:\nBe fair.\n\nRequest:\nTranscript here.\n\nRespond with JSON only.", $seen[2]);
        $this->assertSame(
            ['prompttokens' => 0, 'completiontokens' => 0, 'model' => 'core_ai', 'provider' => 'core'],
            $client->last_usage()
        );
        $this->assertFalse($client->supports_images());
        $this->assertFalse($client->supports_json_schema());
        $this->assertSame('core', $client->route());
        $this->assertTrue(core_ai_client::is_available());
    }

    /**
     * Without a schema there is no JSON instruction.
     *
     * @return void
     */
    public function test_no_json_instruction_without_schema(): void {
        $this->assertSame("Instructions:\nA\n\nRequest:\nB", core_ai_client::flatten('A', 'B', false));
    }

    /**
     * Images are refused before core is asked.
     *
     * @return void
     */
    public function test_images_refused(): void {
        $called = false;
        core_ai_client::set_test_processor(function () use (&$called) {
            $called = true;
            return ['success' => true, 'text' => 'x', 'errorcode' => 0];
        });
        try {
            (new core_ai_client())->generate_text('s', 'u', [
                'contextid' => 1, 'userid' => 2, 'images' => [['mime' => 'image/jpeg', 'base64' => 'x']],
            ]);
            $this->fail('Expected bad_response');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::BAD_RESPONSE, $e->reason);
        }
        $this->assertFalse($called);
    }

    /**
     * contextid and userid are required.
     *
     * @return void
     */
    public function test_context_and_user_required(): void {
        core_ai_client::set_test_processor(fn() => ['success' => true, 'text' => 'x', 'errorcode' => 0]);
        $this->expectException(\coding_exception::class);
        (new core_ai_client())->generate_text('s', 'u', ['contextid' => 1]);
    }

    /**
     * Core error codes map to reasons: 429 transient, 5xx transient,
     * 4xx not, no provider is core_disabled, empty text is bad_response.
     *
     * @return void
     */
    public function test_error_mapping(): void {
        $cases = [
            [['success' => false, 'errorcode' => 429], ai_exception::HTTP_429, true],
            [['success' => false, 'errorcode' => 503], ai_exception::HTTP_5XX, true],
            [['success' => false, 'errorcode' => 401], ai_exception::HTTP_4XX, false],
            [['success' => false, 'errorcode' => -1], ai_exception::CORE_DISABLED, false],
            [['success' => true, 'text' => '', 'errorcode' => 0], ai_exception::BAD_RESPONSE, false],
        ];
        foreach ($cases as [$result, $reason, $transient]) {
            core_ai_client::set_test_processor(fn() => $result);
            try {
                (new core_ai_client())->generate_text('s', 'u', ['contextid' => 1, 'userid' => 2]);
                $this->fail('Expected ' . $reason);
            } catch (ai_exception $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($transient, $e->transient);
            }
        }
    }

    /**
     * The text is read from generatedcontent, then content, then response.
     *
     * @return void
     */
    public function test_response_key_fallback(): void {
        $this->assertSame('a', core_ai_client::extract_text(['generatedcontent' => 'a', 'content' => 'b', 'response' => 'c']));
        $this->assertSame('b', core_ai_client::extract_text(['generatedcontent' => null, 'content' => 'b', 'response' => 'c']));
        $this->assertSame('c', core_ai_client::extract_text(['generatedcontent' => '', 'response' => 'c']));
        $this->assertSame('', core_ai_client::extract_text(['generatedcontent' => ['x'], 'id' => 'y']));
    }

    /**
     * On this site's real manager, with no AI provider configured, the call
     * is core_disabled and nothing leaves the server. On 4.5 the manager
     * takes no constructor argument; on 5.x it takes the database.
     *
     * @return void
     */
    public function test_real_manager_without_provider(): void {
        global $CFG;
        $this->resetAfterTest();
        if (!class_exists('\\core_ai\\manager')) {
            $this->markTestSkipped('No core AI subsystem here');
        }
        $expected = ((int) $CFG->branch) < 500 ? '4.5' : '5.x';
        $this->assertSame($expected, core_ai_client::api_generation());

        // Turn off every AI provider so nothing can answer.
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('aiprovider') as $plugin) {
            \core\plugininfo\aiprovider::enable_plugin($plugin->name, 0);
        }
        $this->assertFalse(core_ai_client::is_available());

        $user = $this->getDataGenerator()->create_user();
        try {
            (new core_ai_client())->generate_text('s', 'u', [
                'contextid' => \context_system::instance()->id, 'userid' => (int) $user->id,
            ]);
            $this->fail('Expected core_disabled');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::CORE_DISABLED, $e->reason);
            $this->assertFalse($e->transient);
        }
    }

    /**
     * With a configured, enabled core provider, the real manager says generate_text is available.
     *
     * The action name core is asked about must match generate_text::class
     * exactly, with no leading backslash, or in_array() in core's manager
     * finds no provider and the core route never works.
     *
     * @return void
     */
    public function test_real_manager_with_configured_provider(): void {
        $this->resetAfterTest();
        $this->assertSame(\core_ai\aiactions\generate_text::class, core_ai_client::ACTION);
        if (core_ai_client::api_generation() !== '4.5') {
            $this->markTestSkipped('Provider set up here is the 4.5 one.');
        }
        set_config('apikey', 'test-key', 'aiprovider_openai');
        \core\plugininfo\aiprovider::enable_plugin('openai', 1);

        $this->assertTrue(core_ai_client::is_available());
    }
}
