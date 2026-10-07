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
use mod_presenterai\local\vision\judge;
use mod_presenterai\local\vision\judge_unavailable_exception;
use mod_presenterai\local\vision\visual_prompts;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/video_vision_test.php');

/**
 * Layer 4: the judge's request, its verdicts, and the difference between a verdict and an outage.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\judge
 */
final class judge_test extends \advanced_testcase {
    /**
     * The request: prompt 3 verbatim, the numbered candidates alone, a schema within the client rules.
     *
     * @return void
     */
    public function test_request_shape(): void {
        $this->resetAfterTest();
        $client = video_vision_test::fake_client([json_encode(['verdicts' => [
            ['n' => 1, 'pass' => true, 'rule' => ''],
            ['n' => 2, 'pass' => true, 'rule' => ''],
        ]])]);

        judge::verdicts(['Your hands stayed low.', "Your eyes\nwent to your notes."], $client);

        $call = $client->calls[0];
        $this->assertSame(visual_prompts::judge(), $call['system']);
        $this->assertSame("1. Your hands stayed low.\n2. Your eyes went to your notes.", $call['user']);
        $schema = $call['opts']['schema'];
        $this->assertSame('judge_verdicts', $schema['name']);
        $this->assertSame(['verdicts'], $schema['schema']['required']);
        $this->assertFalse($schema['schema']['additionalProperties']);
        $item = $schema['schema']['properties']['verdicts']['items'];
        $this->assertSame(['n', 'pass', 'rule'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $encoded = json_encode($schema);
        foreach (['enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern'] as $keyword) {
            $this->assertStringNotContainsString('"' . $keyword . '"', $encoded, "The schema uses {$keyword}.");
        }
    }

    /**
     * Verdicts map back by number; a missing one is a rejection; a rule is cut to the column.
     *
     * @return void
     */
    public function test_verdicts(): void {
        $this->resetAfterTest();
        $client = video_vision_test::fake_client([json_encode(['verdicts' => [
            ['n' => 3, 'pass' => false, 'rule' => str_repeat('describes the room ', 10)],
            ['n' => 1, 'pass' => true, 'rule' => 'ignored on a pass'],
            ['n' => 1, 'pass' => false, 'rule' => 'a second verdict for 1 is ignored'],
        ]])]);

        $out = judge::verdicts(['one', 'two', 'three'], $client);

        $this->assertSame(['pass' => true, 'rule' => ''], $out[0]);
        $this->assertSame(['pass' => false, 'rule' => 'judge_missing'], $out[1], 'A skipped item was treated as passed.');
        $this->assertFalse($out[2]['pass']);
        $this->assertSame(64, \core_text::strlen($out[2]['rule']));
    }

    /**
     * An ai_exception is an outage carrying its reason, and the spend is still recorded.
     *
     * @return void
     */
    public function test_call_failure_is_an_outage(): void {
        global $DB;

        $this->resetAfterTest();
        $client = video_vision_test::fake_client([new ai_exception('http_429', true)]);
        try {
            judge::verdicts(['one'], $client, ['presenteraiid' => 5, 'recordingid' => 6, 'userid' => 7]);
            $this->fail('A failed judge call was taken as a verdict.');
        } catch (judge_unavailable_exception $e) {
            $this->assertSame('http_429', $e->reason);
            $this->assertSame('error:judgeunavailable', $e->errorcode);
        }
        $row = $DB->get_record('presenterai_aiusage', ['recordingid' => 6], '*', MUST_EXIST);
        $this->assertSame('judge', $row->action);
        $this->assertSame(7, (int) $row->userid);
    }

    /**
     * A reply that isn't the JSON asked for is an outage, not a verdict.
     *
     * @return void
     */
    public function test_unparseable_reply_is_an_outage(): void {
        $this->resetAfterTest();
        $this->expectException(judge_unavailable_exception::class);
        judge::verdicts(['one'], video_vision_test::fake_client(['All of these look fine.']));
    }

    /**
     * Nothing to judge, no call.
     *
     * @return void
     */
    public function test_nothing_to_judge(): void {
        $client = video_vision_test::fake_client([]);
        $this->assertSame([], judge::verdicts([], $client));
        $this->assertSame([], $client->calls);
    }
}
