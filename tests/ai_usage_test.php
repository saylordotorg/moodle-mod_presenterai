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

use mod_presenterai\local\ai\claude_client;
use mod_presenterai\local\ai\http_client;
use mod_presenterai\local\ai\stt_client;
use mod_presenterai\local\ai\usage;

/**
 * Spend rows: what is recorded, from which client, for which user.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\ai\usage
 */
final class ai_usage_test extends \advanced_testcase {
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
     * A Claude call that retried is billed for both attempts, to the user
     * named, not the user running the code.
     *
     * @return void
     */
    public function test_claude_retry_summed_for_explicit_user(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $this->setUser($admin);
        $learner = $this->getDataGenerator()->create_user();

        $bodies = [
            file_get_contents(__DIR__ . '/fixtures/ai/claude_text.json'),
            file_get_contents(__DIR__ . '/fixtures/ai/claude_tool_use.json'),
        ];
        http_client::set_test_handler(function () use (&$bodies) {
            return ['status' => 200, 'body' => array_shift($bodies)];
        });
        $client = new claude_client('k', 'claude-sonnet-5-5');
        $client->generate_text('s', 'u', ['schema' => ['name' => 'x', 'schema' => [
            'type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false,
        ]]]);

        $id = usage::record($client, usage::ACTION_SCORE, 11, 22, (int) $learner->id, ['imagecount' => 2]);
        $row = $DB->get_record('presenterai_aiusage', ['id' => $id]);

        $this->assertSame((int) $learner->id, (int) $row->userid);
        $this->assertNotSame((int) $admin->id, (int) $row->userid);
        $this->assertSame('score', $row->action);
        $this->assertSame('claude', $row->route);
        $this->assertSame('claude', $row->provider);
        $this->assertSame(11, (int) $row->presenteraiid);
        $this->assertSame(22, (int) $row->recordingid);
        $this->assertSame(2550, (int) $row->prompttokens);
        $this->assertSame(130, (int) $row->completiontokens);
        $this->assertSame(2, (int) $row->imagecount);
        // The model the API reported for the last response.
        $this->assertSame('claude-sonnet-4-5', $row->model);
        $this->assertGreaterThan(0, (int) $row->timecreated);
    }

    /**
     * A transcription is recorded with its audio seconds and priced per second.
     *
     * @return void
     */
    public function test_transcribe_with_audio_seconds(): void {
        global $DB;
        $this->resetAfterTest();
        $stt = new stt_client(stt_client::OPENAI_ENDPOINT, 'k');
        $id = usage::record($stt, usage::ACTION_TRANSCRIBE, 3, 4, 5, ['audioseconds' => 90]);
        $row = $DB->get_record('presenterai_aiusage', ['id' => $id]);
        $this->assertSame('transcribe', $row->action);
        $this->assertSame('openai', $row->route);
        $this->assertSame('whisper-1', $row->model);
        $this->assertSame(90, (int) $row->audioseconds);
        $this->assertSame(900000, (int) $row->estmicrocents);
    }

    /**
     * Prices: per token for known models, zero for unknown and core.
     *
     * @return void
     */
    public function test_estimates(): void {
        $this->assertSame(1000 * 300 + 100 * 1500, usage::estimate('claude-sonnet-5-5', 1000, 100));
        $this->assertSame(1000 * 100 + 100 * 500, usage::estimate('claude-haiku-4-5-20251001', 1000, 100));
        $this->assertSame(1000 * 15 + 100 * 60, usage::estimate('gpt-4o-mini-2024-07-18', 1000, 100));
        $this->assertSame(1000 * 30 + 100 * 250, usage::estimate('gemini-2.5-flash', 1000, 100));
        $this->assertSame(0, usage::estimate('core_ai', 1000, 100));
        $this->assertSame(0, usage::estimate('some-new-model', 1000, 100));
    }

    /**
     * Extra overrides win, and a null client is allowed.
     *
     * @return void
     */
    public function test_overrides_and_null_client(): void {
        global $DB;
        $this->resetAfterTest();
        $id = usage::record(null, usage::ACTION_JUDGE, 1, 2, 3, [
            'route' => 'gemini', 'model' => 'gemini-2.5-flash', 'provider' => 'gemini',
            'prompttokens' => 10, 'completiontokens' => 4,
        ]);
        $row = $DB->get_record('presenterai_aiusage', ['id' => $id]);
        $this->assertSame('judge', $row->action);
        $this->assertSame('gemini', $row->route);
        $this->assertSame(10 * 30 + 4 * 250, (int) $row->estmicrocents);
    }
}
