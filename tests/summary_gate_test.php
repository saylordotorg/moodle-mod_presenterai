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

use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\vision\judge_unavailable_exception;
use mod_presenterai\local\vision\summary_gate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/video_vision_test.php');

/**
 * The D21 gate, layers 1 to 4, against the boundary table of design 4.1.
 *
 * The table is the CI fixture set: every row gets its stated verdict from the
 * layers that can see it. Rows the deterministic layers must reject are run
 * with the judge off, so they can't pass by the judge's grace. Rows that must
 * pass run with a judge double that passes everything, so they prove only that
 * layers 1 to 3 don't stop them.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\summary_gate
 */
final class summary_gate_test extends \advanced_testcase {
    /** @var string[] The criterion names the scoring prompt carried. */
    private const NAMES = ['Organisation', 'Vocal Variety', 'Body Language & Gestures', 'Eye Contact & Camera Presence'];

    /**
     * The judge records its spend, so every test resets.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
    }

    /**
     * Clear the judge double after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A gate context.
     *
     * @param bool $judge Whether layer 4 runs.
     * @param string $lang The generation language.
     * @param string $note The raw note.
     * @return array
     */
    private function context(bool $judge, string $lang = 'en', string $note = ''): array {
        return ['note' => $note, 'lang' => $lang, 'criterionnames' => self::NAMES, 'judge' => $judge];
    }

    /**
     * Gate one text as the summary.
     *
     * @param string $text The text.
     * @param array $context The context.
     * @return array The one result.
     */
    private function one(string $text, array $context): array {
        return summary_gate::check_batch([['target' => 'summary', 'text' => $text]], $context)[0];
    }

    /**
     * Install a judge double that passes every item.
     *
     * @param int $count Items it will be shown.
     * @return \mod_presenterai\local\ai\client_interface
     */
    private function passing_judge(int $count): \mod_presenterai\local\ai\client_interface {
        $verdicts = [];
        for ($n = 1; $n <= $count; $n++) {
            $verdicts[] = ['n' => $n, 'pass' => true, 'rule' => ''];
        }
        $client = video_vision_test::fake_client([json_encode(['verdicts' => $verdicts])]);
        route_resolver::set_test_client(route_resolver::PURPOSE_JUDGE, $client);

        return $client;
    }

    /**
     * Design 4.1: the rows that must be rejected, with the deterministic layer and rule that rejects each.
     *
     * @return array
     */
    public static function out_provider(): array {
        return [
            'inferred state' => ['you looked tense', 2, 'character'],
            'inferred state, unlisted' => ['you seemed to want it over with', 3, 'no_anchor'],
            'judgement about a body' => ['your posture was slouched', 2, 'appearance'],
            'clothing and setting' => ['you were wearing a dark shirt against a dark wall', 2, 'clothing'],
            'setting' => ['the room behind you was cluttered', 2, 'setting'],
            'appearance' => ['your beard caught the light', 2, 'appearance'],
            'another person' => ['someone walked behind you in one frame', 2, 'person'],
            'assistive equipment' => [
                'you were seated with the chair back visible behind your shoulders, which narrowed the frame', 2, 'assistive',
            ],
            'headwear' => ['a cap pulled low shaded your eyes, so your gaze was hard to read', 2, 'clothing'],
        ];
    }

    /**
     * Each Out row of the boundary table is rejected without the judge.
     *
     * @dataProvider out_provider
     * @param string $text The candidate.
     * @param int $layer The layer expected to reject it.
     * @param string $rule The rule expected.
     * @return void
     */
    public function test_boundary_table_out(string $text, int $layer, string $rule): void {
        $result = $this->one($text, $this->context(false));
        $this->assertSame(['pass' => false, 'layer' => $layer, 'rule' => $rule], $result);
    }

    /**
     * Design 4.1: the rows that must be let through.
     *
     * @return array
     */
    public static function in_provider(): array {
        return [
            'hands below the desk' => ['your hands stayed below the desk in every frame, so gestures were hard to see'],
            'light behind' => ['the light behind you put your face in shadow'],
            'gaze pattern' => [
                'your eyes went down and to the left in four of the six frames, which reads as checking notes',
            ],
        ];
    }

    /**
     * Each In row passes layers 1 to 3 and goes to the judge, which passes it here.
     *
     * @dataProvider in_provider
     * @param string $text The candidate.
     * @return void
     */
    public function test_boundary_table_in(string $text): void {
        $judge = $this->passing_judge(1);
        $result = $this->one($text, $this->context(true));
        $this->assertTrue($result['pass']);
        $this->assertSame("1. {$text}", $judge->calls[0]['user'], 'An In row did not reach the judge intact.');
    }

    /**
     * The euphemism carries no listed term and an observable, so only the judge can catch it (design 4.6).
     *
     * @return void
     */
    public function test_euphemism_is_the_judges_to_catch(): void {
        $text = 'your hands made it clear you do not come across as a practised speaker';
        $this->assertTrue($this->one($text, $this->context(false))['pass'], 'Layers 1 to 3 were expected to miss this.');

        route_resolver::set_test_client(route_resolver::PURPOSE_JUDGE, video_vision_test::fake_client([
            json_encode(['verdicts' => [['n' => 1, 'pass' => false, 'rule' => 'says what kind of person the learner is']]]),
        ]));
        $this->assertSame(
            ['pass' => false, 'layer' => 4, 'rule' => 'says what kind of person the learner is'],
            $this->one($text, $this->context(true))
        );
    }

    /**
     * Over 500 characters is rejected, never truncated; an empty string is not a rejection.
     *
     * @return void
     */
    public function test_length_and_empty(): void {
        $long = str_repeat('Your hands moved. ', 30);
        $this->assertGreaterThan(500, \core_text::strlen($long));
        $this->assertSame(['pass' => false, 'layer' => 1, 'rule' => 'length'], $this->one($long, $this->context(false)));
        $this->assertSame(['pass' => true, 'layer' => 0, 'rule' => 'empty'], $this->one('  ', $this->context(true)));
    }

    /**
     * The copy detector rejects any 8 word run of the note, in any language, and not a 7 word one.
     *
     * @return void
     */
    public function test_copy_detector(): void {
        $note = 'Dans quatre des six images, les mains restent posées sur le bureau et ne bougent pas.';
        $copied = 'Vos mains: dans quatre des six images, les mains restent posées, donc vos gestes manquaient.';
        $this->assertSame(
            ['pass' => false, 'layer' => 1, 'rule' => 'copy'],
            $this->one($copied, $this->context(false, 'fr', $note))
        );

        $seven = 'Dans quatre des six images, les mains ... donc vos gestes manquaient.';
        $this->assertTrue($this->one($seven, $this->context(false, 'fr', $note))['pass']);
    }

    /**
     * Without a word list for the language, layers 2 and 3 are skipped; English is never the fallback.
     *
     * @return void
     */
    public function test_no_denylist_skips_layers_two_and_three(): void {
        $this->assertNull(summary_gate::denylist_class('fr'));
        $this->assertNull(summary_gate::denylist_class('../en'));
        $this->assertSame('\\mod_presenterai\\local\\vision\\denylist\\en', summary_gate::denylist_class('en_us'));

        // "shirt" would be a hard term in English, and there is no anchor either.
        $this->assertTrue($this->one('Vous portiez une shirt sombre.', $this->context(false, 'fr'))['pass']);
    }

    /**
     * A setting word passes in a sentence anchored to something the learner did, and not otherwise.
     *
     * @return void
     */
    public function test_soft_terms_need_an_anchor(): void {
        $this->assertTrue($this->one(
            'You moved toward the wall as you spoke, so your gestures left the frame.',
            $this->context(false)
        )['pass']);
        $this->assertSame(
            ['pass' => false, 'layer' => 2, 'rule' => 'setting'],
            $this->one('Your gestures were clear. The kitchen was messy.', $this->context(false))
        );
    }

    /**
     * A criterion name anchors a string with no observable in it (layer 3).
     *
     * @return void
     */
    public function test_criterion_name_is_an_anchor(): void {
        $this->assertTrue($this->one('Your vocal variety carried the argument.', $this->context(false))['pass']);
        $this->assertSame(
            ['pass' => false, 'layer' => 3, 'rule' => 'no_anchor'],
            $this->one('That came across well overall.', $this->context(false))
        );
    }

    /**
     * Matching is whole word: "glassware" is not "glasses", "hat" is not in "that".
     *
     * @return void
     */
    public function test_whole_word_matching(): void {
        $this->assertNull(summary_gate::find_term('that hand movement', ['hat']));
        $this->assertSame('hat', summary_gate::find_term('a HAT shaded your eyes', ['hat']));
        $this->assertSame('chair back', summary_gate::find_term('the chair   back showed', ['chair back']));
    }

    /**
     * A batch keeps its order, and only texts that passed 1 to 3 go to the judge, once, numbered.
     *
     * @return void
     */
    public function test_batch_order_and_one_judge_call(): void {
        $judge = video_vision_test::fake_client([json_encode(['verdicts' => [
            ['n' => 1, 'pass' => true, 'rule' => ''],
            ['n' => 2, 'pass' => false, 'rule' => 'gives advice the learner could not act on'],
        ]])]);
        route_resolver::set_test_client(route_resolver::PURPOSE_JUDGE, $judge);

        $results = summary_gate::check_batch([
            ['target' => 'summary', 'text' => 'Your hands stayed low, so your gestures were hard to see.'],
            ['target' => 'Body Language & Gestures', 'text' => 'Your beard caught the light.'],
            ['target' => 'Eye Contact & Camera Presence', 'text' => 'Your eyes should be a different shape.'],
        ], $this->context(true));

        $this->assertCount(1, $judge->calls);
        $this->assertSame(
            "1. Your hands stayed low, so your gestures were hard to see.\n2. Your eyes should be a different shape.",
            $judge->calls[0]['user']
        );
        $this->assertTrue($results[0]['pass']);
        $this->assertSame(['pass' => false, 'layer' => 2, 'rule' => 'appearance'], $results[1]);
        $this->assertSame(4, $results[2]['layer']);
    }

    /**
     * No judge client when the judge is wanted is an outage, not a pass.
     *
     * @return void
     */
    public function test_missing_judge_is_an_outage(): void {
        $this->expectException(judge_unavailable_exception::class);
        $this->one('Your hands stayed low.', $this->context(true));
    }
}
