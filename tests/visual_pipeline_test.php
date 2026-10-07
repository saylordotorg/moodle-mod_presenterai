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
use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\vision\judge_unavailable_exception;
use mod_presenterai\local\vision\visual_pipeline;
use mod_presenterai\local\vision\visual_prompts;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/luminance_test.php');
require_once(__DIR__ . '/video_vision_test.php');

/**
 * Body language feedback from frames to the learner's words: D18, D21, D23, D24 and D5.
 *
 * Every AI client is a double installed through route_resolver::set_test_client,
 * so nothing here reaches a network.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\visual_pipeline
 */
final class visual_pipeline_test extends \advanced_testcase {
    /** @var \stdClass The activity, video vision on. */
    private \stdClass $instance;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var string A note the vision double returns, with a long distinctive run for the copy detector. */
    private const NOTE = 'NOTE-SENTINEL. In four of the six frames the hands rest flat on the desk below the shoulders '
        . 'and do not move between frames. The eyes are directed toward the lens in five frames.';

    /**
     * One video activity with video vision on, one learner with a recognisable name.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->learner = $generator->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Zebediah', 'lastname' => 'Quillfeather']
        );
        $this->instance = $generator->create_module('presenterai', ['course' => $course->id, 'videovision' => 1]);
        $this->context = \context_module::instance($this->instance->cmid);
    }

    /**
     * Clear the doubles so no other test inherits them.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A finished recording, with a frame sheet stored behind it unless told otherwise.
     *
     * @param array $fields Row overrides.
     * @param string|null|false $sheet The sheet bytes, null for none, or false for a bright sheet.
     * @return \stdClass
     */
    private function recording(array $fields = [], $sheet = false): \stdClass {
        if ($sheet === false) {
            $sheet = video_vision_test::bright_sheet();
        }
        $key = random_string(16) . '.jpg';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => random_string(16) . '.webm',
            'frameskey' => $sheet === null ? null : $key,
        ]);
        if ($sheet !== null) {
            get_file_storage()->create_file_from_string([
                'contextid' => $this->context->id, 'component' => 'mod_presenterai', 'filearea' => 'frames',
                'itemid' => $rec->id, 'filepath' => '/', 'filename' => $key,
            ], $sheet);
        }

        return $rec;
    }

    /**
     * Install a vision double replying with this evidence.
     *
     * @param array|null $evidence The JSON object to reply with, or null for a sensible ok reply.
     * @return \mod_presenterai\local\ai\client_interface
     */
    private function vision(?array $evidence = null): \mod_presenterai\local\ai\client_interface {
        $client = video_vision_test::fake_client([json_encode($evidence ?? [
            'note' => self::NOTE, 'unusable_frames' => 0, 'confidence' => 'high',
        ])]);
        route_resolver::set_test_client(route_resolver::PURPOSE_VISION, $client);

        return $client;
    }

    /**
     * The usage rows this attempt has, by action.
     *
     * @param int $recordingid The recording.
     * @return array action => count
     */
    private function usage_actions(int $recordingid): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records('presenterai_aiusage', ['recordingid' => $recordingid]) as $row) {
            $out[$row->action] = ($out[$row->action] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * No frames for this attempt: video vision off, an audio activity, or an audio attempt.
     *
     * @return void
     */
    public function test_evidence_none(): void {
        $client = $this->vision();
        $rec = $this->recording();

        $off = clone $this->instance;
        $off->videovision = 0;
        $this->assertSame('none', visual_pipeline::evidence($rec, $off, $this->context, 'claude')['state']);

        $audio = clone $this->instance;
        $audio->mode = 'audio';
        $this->assertSame('none', visual_pipeline::evidence($rec, $audio, $this->context, 'claude')['state']);

        $rec->mode = 'audio';
        $this->assertSame('none', visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude')['state']);
        $this->assertSame([], $client->calls);
    }

    /**
     * D24: an opted out attempt calls no client at all.
     *
     * @return void
     */
    public function test_evidence_optedout_calls_nothing(): void {
        $client = $this->vision();
        $rec = $this->recording(['visualoptout' => 1]);

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(visual_pipeline::EVIDENCE_OPTEDOUT, $evidence['state']);
        $this->assertSame([], $client->calls);
        $this->assertSame(visual_pipeline::STATUS_OPTEDOUT, visual_pipeline::status_for($evidence));
        $this->assertSame([], $this->usage_actions((int) $rec->id));
    }

    /**
     * Design 3.6: on the core scoring route the vision client is never called.
     *
     * @return void
     */
    public function test_core_route_is_unavailable_without_a_call(): void {
        $client = $this->vision();
        $rec = $this->recording();

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'core');

        $this->assertSame(visual_pipeline::EVIDENCE_UNAVAILABLE, $evidence['state']);
        $this->assertSame([], $client->calls);
        $this->assertSame(visual_pipeline::STATUS_NOTANALYSED, visual_pipeline::status_for($evidence));
    }

    /**
     * No frame sheet is the learner's setup (a1); no client is the site's (a2).
     *
     * @return void
     */
    public function test_no_sheet_and_no_client(): void {
        $this->vision();
        $nosheet = $this->recording([], null);
        $evidence = visual_pipeline::evidence($nosheet, $this->instance, $this->context, 'claude');
        $this->assertSame(visual_pipeline::EVIDENCE_UNUSABLE, $evidence['state']);
        $this->assertSame(visual_pipeline::STATUS_NOTASSESSED, visual_pipeline::status_for($evidence));

        route_resolver::set_test_client(route_resolver::PURPOSE_VISION, null);
        $rec = $this->recording();
        $this->assertSame(
            visual_pipeline::EVIDENCE_UNAVAILABLE,
            visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude')['state']
        );
    }

    /**
     * Rate limited is the site's reason, so unavailable, and the client is not called.
     *
     * @return void
     */
    public function test_rate_limited_is_unavailable(): void {
        $client = $this->vision();
        $rec = $this->recording();
        for ($i = 0; $i < rate_limiter::VISION_MAX; $i++) {
            rate_limiter::hit('video_vision', (int) $this->learner->id, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW);
        }

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(visual_pipeline::EVIDENCE_UNAVAILABLE, $evidence['state']);
        $this->assertSame([], $client->calls);
    }

    /**
     * A failing call is unavailable, never a throw, and its spend is still recorded.
     *
     * @return void
     */
    public function test_failed_call_is_unavailable_and_recorded(): void {
        $client = video_vision_test::fake_client([new ai_exception('http_5xx', true)]);
        route_resolver::set_test_client(route_resolver::PURPOSE_VISION, $client);
        $rec = $this->recording();

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(visual_pipeline::EVIDENCE_UNAVAILABLE, $evidence['state']);
        $this->assertSame(['video_vision' => 1], $this->usage_actions((int) $rec->id));
        $this->assertDebuggingCalled();
    }

    /**
     * Layer 0: low confidence, three unusable frames or an empty note are unusable.
     *
     * @return array
     */
    public static function weak_evidence_provider(): array {
        return [
            'low confidence' => [['note' => 'Hands visible.', 'unusable_frames' => 0, 'confidence' => 'low']],
            'three unusable frames' => [['note' => 'Hands visible.', 'unusable_frames' => 3, 'confidence' => 'high']],
            'empty note' => [['note' => '  ', 'unusable_frames' => 0, 'confidence' => 'high']],
        ];
    }

    /**
     * Weak evidence is unusable, and nothing is stored.
     *
     * @dataProvider weak_evidence_provider
     * @param array $reply The vision reply.
     * @return void
     */
    public function test_weak_evidence_is_unusable(array $reply): void {
        global $DB;

        $this->vision($reply);
        $rec = $this->recording();

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(visual_pipeline::EVIDENCE_UNUSABLE, $evidence['state']);
        $this->assertNull($DB->get_field('presenterai_recording', 'visualevidence', ['id' => $rec->id]));
        $this->assertSame(['video_vision' => 1], $this->usage_actions((int) $rec->id));
    }

    /**
     * A dark sheet is unusable before any model sees it.
     *
     * @return void
     */
    public function test_dark_sheet_is_unusable_without_a_call(): void {
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is not available.');
        }
        $client = $this->vision();
        $rec = $this->recording([], luminance_test::sheet([0, 1, 2]));

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(visual_pipeline::EVIDENCE_UNUSABLE, $evidence['state']);
        $this->assertSame([], $client->calls);
    }

    /**
     * Usable evidence is returned, stored as D18 JSON on the D5 clock, and its spend recorded.
     *
     * @return void
     */
    public function test_ok_evidence_is_stored(): void {
        global $DB;

        $this->vision(['note' => self::NOTE, 'unusable_frames' => 1, 'confidence' => 'medium']);
        $rec = $this->recording();

        $before = time();
        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame(['state' => 'ok', 'note' => self::NOTE, 'confidence' => 'medium', 'unusable_frames' => 1], $evidence);
        $row = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $this->assertSame(
            ['note' => self::NOTE, 'confidence' => 'medium', 'unusable_frames' => 1],
            json_decode($row->visualevidence, true)
        );
        $this->assertGreaterThanOrEqual($before, (int) $row->visualevidenceat);
        $usage = $DB->get_record('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'video_vision'], '*', MUST_EXIST);
        $this->assertSame(1, (int) $usage->imagecount);
        $this->assertSame((int) $this->learner->id, (int) $usage->userid);
    }

    /**
     * D5: with storevisualevidence off the note is used and never written.
     *
     * @return void
     */
    public function test_storevisualevidence_off_writes_nothing(): void {
        global $DB;

        set_config('storevisualevidence', 0, 'mod_presenterai');
        $this->vision();
        $rec = $this->recording();

        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, 'claude');

        $this->assertSame('ok', $evidence['state']);
        $row = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $this->assertNull($row->visualevidence);
        $this->assertSame(0, (int) $row->visualevidenceat);
    }

    /**
     * The scoring block: nothing without evidence; the D23 rule either way; prompt 2 in the learner's language.
     *
     * @return void
     */
    public function test_prompt_block(): void {
        $ok = ['state' => 'ok', 'note' => self::NOTE, 'confidence' => 'high', 'unusable_frames' => 0];
        $this->assertSame('', visual_pipeline::prompt_block(['state' => 'unusable', 'note' => ''], false, 'English'));
        $this->assertSame('', visual_pipeline::prompt_block(['state' => 'optedout', 'note' => 'x'], true, 'English'));

        $feedbackonly = visual_pipeline::prompt_block($ok, false, 'Spanish');
        $this->assertStringContainsString("VISUAL EVIDENCE (a description of still frames sampled from the recording, "
            . "not continuous video):\n" . self::NOTE, $feedbackonly);
        $this->assertStringContainsString('These two criteria are feedback only for this activity', $feedbackonly);
        $this->assertStringNotContainsString('clearly possible for this speaker', $feedbackonly);
        $this->assertStringContainsString('Write it in Spanish.', $feedbackonly);
        $this->assertStringContainsString('VISUAL SUMMARY.', $feedbackonly);

        $scored = visual_pipeline::prompt_block($ok, true, 'English');
        $this->assertStringContainsString('Score 0 with assessed true only when the behaviour was clearly possible', $scored);
        $this->assertStringContainsString('anything the speaker cannot change about themselves, set assessed to false', $scored);
        // SOLA's load bearing sentence pointed the wrong way (DECISIONS.md 9.17).
        $this->assertStringNotContainsString('means you could see the behaviour and it was absent', $scored);
        $this->assertStringNotContainsString('feedback only for this activity', $scored);
    }

    /**
     * The language comes from the user record, not the session.
     *
     * @return void
     */
    public function test_language_from_the_user_record(): void {
        global $CFG, $DB;

        $DB->set_field('user', 'lang', 'es', ['id' => $this->learner->id]);
        $this->assertSame('es', visual_pipeline::language_code((int) $this->learner->id));
        $this->assertSame('Spanish', visual_pipeline::language_name((int) $this->learner->id));

        $DB->set_field('user', 'lang', '', ['id' => $this->learner->id]);
        $this->assertSame($CFG->lang, visual_pipeline::language_code((int) $this->learner->id));
    }

    /**
     * Scored criteria in the B2 shape: one speech, two visual.
     *
     * @param string $bodyfeedback Feedback on the gestures criterion.
     * @param string $eyefeedback Feedback on the camera criterion.
     * @return array
     */
    private function criteria(string $bodyfeedback, string $eyefeedback): array {
        return [
            ['name' => 'Vocal Delivery', 'score' => 4, 'max_score' => 5, 'feedback' => 'Clear voice.', 'assessed' => true,
                'visual' => false, 'counts' => true],
            ['name' => 'Body Language & Gestures', 'score' => 2, 'max_score' => 5, 'feedback' => $bodyfeedback,
                'assessed' => true, 'visual' => true, 'counts' => false],
            ['name' => 'Eye Contact & Camera Presence', 'score' => 5, 'max_score' => 5, 'feedback' => $eyefeedback,
                'assessed' => true, 'visual' => true, 'counts' => false],
        ];
    }

    /**
     * An ok evidence result.
     *
     * @return array
     */
    private function ok(): array {
        return ['state' => 'ok', 'note' => self::NOTE, 'confidence' => 'high', 'unusable_frames' => 0];
    }

    /**
     * Install a judge double that passes everything it is shown.
     *
     * @param int $count How many items it will be shown.
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
     * Evidence that never came to anything leaves the criteria alone and names the section.
     *
     * @return void
     */
    public function test_finalise_without_evidence(): void {
        $rec = $this->recording();
        $criteria = $this->criteria('a', 'b');
        $states = ['optedout' => 'optedout', 'unusable' => 'notassessed', 'unavailable' => 'notanalysed', 'none' => ''];
        foreach ($states as $state => $status) {
            $result = visual_pipeline::finalise($criteria, 'x', ['state' => $state], $rec, $this->instance, $this->context, true);
            $this->assertSame($criteria, $result['criteria']);
            $this->assertNull($result['visualsummary']);
            $this->assertSame($status, $result['visualstatus']);
        }
    }

    /**
     * A clean summary and clean criterion feedback pass all four layers and are shown.
     *
     * @return void
     */
    public function test_finalise_passes_clean_feedback(): void {
        $judge = $this->passing_judge(3);
        $rec = $this->recording();
        $summary = 'Your hands stayed below the desk in most frames, so your gestures were hard to see. '
            . 'Your eyes were on the lens, which made the talk feel direct.';
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        $result = visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);

        $this->assertSame(visual_pipeline::STATUS_SUMMARY, $result['visualstatus']);
        $this->assertSame($summary, $result['visualsummary']);
        $this->assertSame($criteria, $result['criteria']);
        $this->assertCount(0, $sink->get_events());
        $this->assertCount(1, $judge->calls);
    }

    /**
     * D21: a deny list hit in a visual criterion's feedback withholds it, unassesses it, fires the event and logs the text.
     *
     * @return void
     */
    public function test_deny_hit_in_criterion_feedback(): void {
        global $DB;

        $this->passing_judge(2);
        $rec = $this->recording();
        $leak = 'You were wearing a dark shirt against a dark wall, so your hands were hard to see.';
        $criteria = $this->criteria($leak, 'You looked at the camera lens.');
        $summary = 'Your eyes were on the lens in most frames, so the talk felt direct.';

        $sink = $this->redirectEvents();
        $result = visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);
        $events = $sink->get_events();

        $body = $result['criteria'][1];
        $this->assertSame(get_string('visual_criterion_withheld', 'mod_presenterai'), $body['feedback']);
        $this->assertFalse($body['assessed'], 'A withheld criterion kept its place in the sums.');
        $this->assertSame($criteria[2], $result['criteria'][2], 'A clean criterion was touched.');
        $this->assertSame(visual_pipeline::STATUS_SUMMARY, $result['visualstatus']);

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\mod_presenterai\event\visual_summary_rejected::class, $event);
        $this->assertSame(['target' => 'Body Language & Gestures', 'layer' => 2, 'rule' => 'clothing'], $event->other);
        $this->assertSame((int) $this->learner->id, (int) $event->relateduserid);
        $this->assertStringNotContainsString('shirt', json_encode($event->get_data()), 'The event carries the text.');

        $log = $DB->get_records('presenterai_gatelog', ['recordingid' => $rec->id]);
        $this->assertCount(1, $log);
        $row = reset($log);
        $this->assertSame($leak, $row->rejectedtext);
        $this->assertSame('Body Language & Gestures', $row->target);
        $this->assertSame(2, (int) $row->layer);
        $this->assertSame('clothing', $row->rule);
    }

    /**
     * A summary that pastes an 8 word run of the note is rejected and replaced by the template.
     *
     * @return void
     */
    public function test_copied_summary_is_replaced_by_the_template(): void {
        $this->passing_judge(2);
        $rec = $this->recording();
        $summary = 'In four of the six frames the hands rest flat on the desk, so your gestures were hidden.';
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        $result = visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);

        $this->assertSame(visual_pipeline::STATUS_FALLBACK, $result['visualstatus']);
        $this->assertSame(\mod_presenterai\local\vision\fallback_template::build($result['criteria']), $result['visualsummary']);
        $this->assertStringNotContainsString('rest flat', $result['visualsummary']);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertSame(['target' => 'summary', 'layer' => 1, 'rule' => 'copy'], reset($events)->other);
    }

    /**
     * An empty summary is the model declining: the template, with no rejection.
     *
     * @return void
     */
    public function test_empty_summary_is_fallback_without_an_event(): void {
        global $DB;

        $this->passing_judge(2);
        $rec = $this->recording();
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        $result = visual_pipeline::finalise($criteria, '  ', $this->ok(), $rec, $this->instance, $this->context, true);

        $this->assertSame(visual_pipeline::STATUS_FALLBACK, $result['visualstatus']);
        $this->assertNotSame('', $result['visualsummary']);
        $this->assertCount(0, $sink->get_events());
        $this->assertSame(0, $DB->count_records('presenterai_gatelog'));
    }

    /**
     * A judge rejection is layer 4, with the judge's rule.
     *
     * @return void
     */
    public function test_judge_rejection(): void {
        $client = video_vision_test::fake_client([json_encode(['verdicts' => [
            ['n' => 1, 'pass' => false, 'rule' => 'states or guesses the learner\'s feelings'],
            ['n' => 2, 'pass' => true, 'rule' => ''],
            ['n' => 3, 'pass' => true, 'rule' => ''],
        ]])]);
        route_resolver::set_test_client(route_resolver::PURPOSE_JUDGE, $client);
        $rec = $this->recording();
        $summary = 'Your hands moved as if you were not a practised speaker yet.';
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        $result = visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);

        $this->assertSame(visual_pipeline::STATUS_FALLBACK, $result['visualstatus']);
        $event = $sink->get_events()[0];
        $this->assertSame(4, $event->other['layer']);
        $this->assertSame('states or guesses the learner\'s feelings', $event->other['rule']);
    }

    /**
     * The judge is given the candidates and nothing else: no name, transcript, raw note or scores.
     *
     * @return void
     */
    public function test_judge_never_sees_name_note_or_scores(): void {
        $judge = $this->passing_judge(3);
        $rec = $this->recording();
        $summary = 'Your hands stayed low in most frames, so your gestures were hard to see.';
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);

        $call = $judge->calls[0];
        $this->assertSame(visual_prompts::judge(), $call['system']);
        $this->assertSame(
            "1. {$summary}\n2. Your hands stayed low, so gestures did not register.\n3. You looked at the camera lens.",
            $call['user']
        );
        $sent = $call['system'] . $call['user'] . json_encode($call['opts']);
        foreach (['Zebediah', 'Quillfeather', 'NOTE-SENTINEL', 'Vocal Delivery', 'Clear voice'] as $secret) {
            $this->assertStringNotContainsString($secret, $sent, "The judge was sent {$secret}.");
        }
        $this->assertDoesNotMatchRegularExpression('/\b[0-9]+ out of [0-9]+\b|"score"/', $sent, 'The judge was sent scores.');
    }

    /**
     * A judge outage while the task can retry: the event, then a throw, and nothing logged as a rejection.
     *
     * @return void
     */
    public function test_judge_outage_throws_while_retries_remain(): void {
        global $DB;

        route_resolver::set_test_client(
            route_resolver::PURPOSE_JUDGE,
            video_vision_test::fake_client([new ai_exception('timeout', true)])
        );
        $rec = $this->recording();
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        try {
            $summary = 'Your hands stayed low.';
            visual_pipeline::finalise($criteria, $summary, $this->ok(), $rec, $this->instance, $this->context, true);
            $this->fail('A judge outage was treated as a verdict.');
        } catch (judge_unavailable_exception $e) {
            $this->assertSame('timeout', $e->reason);
        }
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_presenterai\event\visual_summary_judge_unavailable::class, $events[0]);
        $this->assertSame(['reason' => 'timeout'], $events[0]->other);
        $this->assertSame(0, $DB->count_records('presenterai_gatelog'));
        $this->assertSame(['judge' => 1], $this->usage_actions((int) $rec->id), 'A failed judge call still has a spend row.');
    }

    /**
     * Out of retries the gate fails closed: the template, every visual criterion withheld, layer 0 log rows.
     *
     * @return void
     */
    public function test_judge_outage_fails_closed_without_retries(): void {
        global $DB;

        $rec = $this->recording();
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $sink = $this->redirectEvents();
        // No judge client at all is an outage too.
        $result = visual_pipeline::finalise(
            $criteria,
            'Your hands stayed low.',
            $this->ok(),
            $rec,
            $this->instance,
            $this->context,
            false
        );

        $this->assertSame(visual_pipeline::STATUS_FALLBACK, $result['visualstatus']);
        $this->assertSame(get_string('visual_fallback_partial', 'mod_presenterai'), $result['visualsummary']);
        foreach ([1, 2] as $i) {
            $this->assertFalse($result['criteria'][$i]['assessed']);
            $this->assertSame(get_string('visual_criterion_withheld', 'mod_presenterai'), $result['criteria'][$i]['feedback']);
        }
        $this->assertTrue($result['criteria'][0]['assessed'], 'A spoken criterion was withheld by a visual gate.');

        $events = $sink->get_events();
        $this->assertCount(1, $events, 'Fail closed must not also count as rejections.');
        $this->assertSame(['reason' => 'not_configured'], $events[0]->other);
        $rows = $DB->get_records('presenterai_gatelog', ['recordingid' => $rec->id], 'id');
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row->layer);
            $this->assertSame('judge_unavailable', $row->rule);
        }
    }

    /**
     * With the judge setting off, layers 1 to 3 decide alone and no judge is asked.
     *
     * @return void
     */
    public function test_judge_off(): void {
        set_config('visualsummaryjudge', 0, 'mod_presenterai');
        $rec = $this->recording();
        $criteria = $this->criteria('Your hands stayed low, so gestures did not register.', 'You looked at the camera lens.');

        $result = visual_pipeline::finalise(
            $criteria,
            'Your hands stayed low in most frames.',
            $this->ok(),
            $rec,
            $this->instance,
            $this->context,
            true
        );

        $this->assertSame(visual_pipeline::STATUS_SUMMARY, $result['visualstatus']);
    }

    /**
     * The settings readers and their defaults.
     *
     * @return void
     */
    public function test_setting_defaults(): void {
        unset_config('visualdatadays', 'mod_presenterai');
        unset_config('storevisualevidence', 'mod_presenterai');
        unset_config('visualsummaryjudge', 'mod_presenterai');
        $this->assertSame(30, visual_pipeline::visual_data_days());
        $this->assertTrue(visual_pipeline::store_evidence_enabled());
        $this->assertTrue(visual_pipeline::judge_enabled());

        set_config('visualdatadays', 0, 'mod_presenterai');
        $this->assertSame(1, visual_pipeline::visual_data_days(), 'Zero must not mean forever here (design 7.4).');
        set_config('visualdatadays', -5, 'mod_presenterai');
        $this->assertSame(1, visual_pipeline::visual_data_days());
        set_config('visualdatadays', 12, 'mod_presenterai');
        $this->assertSame(12, visual_pipeline::visual_data_days());
    }

    /**
     * A criterion without the visual flag is recognised by the seed names.
     *
     * @return void
     */
    public function test_is_visual_criterion(): void {
        $this->assertTrue(visual_pipeline::is_visual_criterion(['name' => 'Body Language & Gestures']));
        $this->assertTrue(visual_pipeline::is_visual_criterion(['name' => 'eye contact & camera presence']));
        $this->assertFalse(visual_pipeline::is_visual_criterion(['name' => 'Body Language & Gestures', 'visual' => false]));
        $this->assertTrue(visual_pipeline::is_visual_criterion(['name' => 'Anything', 'visual' => true]));
        $this->assertFalse(visual_pipeline::is_visual_criterion(['name' => 'Content']));
    }
}
