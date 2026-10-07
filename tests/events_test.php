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

use mod_presenterai\event\recording_scored;
use mod_presenterai\event\recording_submitted;
use mod_presenterai\external\begin_attempt;
use mod_presenterai\external\finalize_recording;
use mod_presenterai\external\start_upload;
use mod_presenterai\local\storage\fs_store;

/**
 * The submitted and scored events.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\event\recording_submitted
 * @covers     \mod_presenterai\event\recording_scored
 * @covers     \mod_presenterai\local\attempt_events
 * @covers     \mod_presenterai\event\visual_summary_rejected
 * @covers     \mod_presenterai\event\visual_summary_judge_unavailable
 */
final class events_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * One course, one activity, one learner, on a site that keeps forever.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        set_config('retentiondays', 0, 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->ctx = \context_module::instance($this->instance->cmid);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * A recording row for the learner.
     *
     * @return \stdClass
     */
    private function recording(): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'attemptnumber' => 3,
        ]);
    }

    /**
     * recording_submitted carries the attempt, the learner and the right mappings.
     *
     * @return void
     */
    public function test_recording_submitted_data(): void {
        $rec = $this->recording();
        $sink = $this->redirectEvents();
        recording_submitted::create_from_recording($rec, $this->ctx)->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertInstanceOf(recording_submitted::class, $event);
        $this->assertSame('c', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertSame('presenterai_recording', $event->objecttable);
        $this->assertSame((int) $rec->id, (int) $event->objectid);
        $this->assertSame((int) $this->learner->id, (int) $event->relateduserid);
        $this->assertSame(3, $event->other['attemptnumber']);
        $this->assertSame($this->ctx->id, $event->get_context()->id);
        $this->assertEquals(new \moodle_url('/mod/presenterai/view.php', ['id' => $this->instance->cmid]), $event->get_url());
        $this->assertNotEmpty($event->get_description());
        $this->assertSame(get_string('eventrecordingsubmitted', 'mod_presenterai'), recording_submitted::get_name());
        $this->assertSame(
            ['db' => 'presenterai_recording', 'restore' => 'presenterai_recording'],
            recording_submitted::get_objectid_mapping()
        );
        $this->assertFalse(recording_submitted::get_other_mapping());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * recording_scored carries the score id and origin, and maps both ids.
     *
     * @return void
     */
    public function test_recording_scored_data(): void {
        $rec = $this->recording();
        $score = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_score([
            'recordingid' => $rec->id,
            'origin' => 'teacher',
        ]);
        $sink = $this->redirectEvents();
        recording_scored::create_from_score($rec, $score, $this->ctx)->trigger();
        $event = $sink->get_events()[0];

        $this->assertInstanceOf(recording_scored::class, $event);
        $this->assertSame('u', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertSame((int) $rec->id, (int) $event->objectid);
        $this->assertSame((int) $this->learner->id, (int) $event->relateduserid);
        $this->assertSame((int) $score->id, $event->other['scoreid']);
        $this->assertSame('teacher', $event->other['origin']);
        $this->assertEquals(
            new \moodle_url('/mod/presenterai/grade.php', ['id' => $this->instance->cmid, 'recordingid' => $rec->id]),
            $event->get_url()
        );
        $this->assertSame(get_string('eventrecordingscored', 'mod_presenterai'), recording_scored::get_name());
        $this->assertSame(
            ['db' => 'presenterai_recording', 'restore' => 'presenterai_recording'],
            recording_scored::get_objectid_mapping()
        );
        $this->assertSame(
            ['scoreid' => ['db' => 'presenterai_score', 'restore' => 'presenterai_score']],
            recording_scored::get_other_mapping()
        );
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Each event refuses to be created without what it must carry.
     *
     * @return void
     */
    public function test_validation(): void {
        $cases = [
            [recording_submitted::class, ['objectid' => 1, 'relateduserid' => 2, 'other' => []]],
            [recording_submitted::class, ['objectid' => 1, 'other' => ['attemptnumber' => 1]]],
            [recording_scored::class, ['objectid' => 1, 'relateduserid' => 2, 'other' => ['origin' => 'teacher']]],
            [recording_scored::class, ['objectid' => 1, 'relateduserid' => 2, 'other' => ['scoreid' => 5]]],
            [recording_scored::class, ['objectid' => 1, 'other' => ['scoreid' => 5, 'origin' => 'teacher']]],
        ];
        foreach ($cases as $i => [$class, $data]) {
            try {
                $class::create(['context' => $this->ctx] + $data);
                $this->fail("Case {$i} was accepted.");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must be set', $e->getMessage());
            }
        }
    }

    /**
     * Finalizing through the web service fires recording_submitted once, even when retried.
     *
     * @return void
     */
    public function test_finalize_fires_submitted_once(): void {
        $this->setUser($this->learner);
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $content = str_repeat('spoken words ', 50);
        $target = start_upload::execute($begin['recordingid'], 'recording', 'webm', strlen($content), $begin['attempttoken']);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);

        $sink = $this->redirectEvents();
        finalize_recording::execute($begin['recordingid'], $begin['attempttoken'], 0, 30, '');
        finalize_recording::execute($begin['recordingid'], $begin['attempttoken'], 0, 30, '');

        $submitted = array_values(array_filter($sink->get_events(), function ($e): bool {
            return $e instanceof recording_submitted;
        }));
        $this->assertCount(1, $submitted, 'A retried finalize fired the event again.');
        $this->assertSame((int) $begin['recordingid'], (int) $submitted[0]->objectid);
        $this->assertSame(1, $submitted[0]->other['attemptnumber']);
        $this->assertSame((int) $this->learner->id, (int) $submitted[0]->userid);
    }

    /**
     * visual_summary_rejected carries target, layer and rule, never the text, about the learner.
     *
     * @return void
     */
    public function test_visual_summary_rejected_data(): void {
        $rec = $this->recording();
        $sink = $this->redirectEvents();
        \mod_presenterai\event\visual_summary_rejected::create_from_recording($rec, $this->ctx, [
            'target' => 'summary',
            'layer' => 2,
            'rule' => 'clothing',
            'text' => 'You were wearing a dark shirt.',
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(['target' => 'summary', 'layer' => 2, 'rule' => 'clothing'], $event->other);
        $this->assertSame((int) $rec->id, (int) $event->objectid);
        $this->assertSame((int) $this->learner->id, (int) $event->relateduserid);
        $this->assertSame('u', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
        $this->assertSame($this->ctx->id, $event->get_context()->id);
        $this->assertStringNotContainsString('shirt', json_encode($event->get_data()), 'The event carries the rejected text.');
        $this->assertSame(get_string('eventvisualsummaryrejected', 'mod_presenterai'), $event->get_name());
        $this->assertStringContainsString("'clothing'", $event->get_description());
        $this->assertStringContainsString('recordingid=' . $rec->id, $event->get_url()->out(false));
    }

    /**
     * visual_summary_judge_unavailable carries the reason only.
     *
     * @return void
     */
    public function test_visual_summary_judge_unavailable_data(): void {
        $rec = $this->recording();
        $sink = $this->redirectEvents();
        \mod_presenterai\event\visual_summary_judge_unavailable::create_from_recording($rec, $this->ctx, [
            'reason' => 'timeout',
        ])->trigger();
        $event = $sink->get_events()[0];

        $this->assertSame(['reason' => 'timeout'], $event->other);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
        $this->assertSame(get_string('eventvisualsummaryjudgeunavailable', 'mod_presenterai'), $event->get_name());
        $this->assertStringContainsString("'timeout'", $event->get_description());
    }

    /**
     * Both visual events refuse to be built without their other keys.
     *
     * @return void
     */
    public function test_visual_event_validation(): void {
        $rec = $this->recording();
        try {
            \mod_presenterai\event\visual_summary_rejected::create([
                'context' => $this->ctx, 'objectid' => $rec->id, 'relateduserid' => $rec->userid, 'other' => ['target' => 'x'],
            ]);
            $this->fail('A rejection event without a layer and rule was accepted.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('layer', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        \mod_presenterai\event\visual_summary_judge_unavailable::create([
            'context' => $this->ctx, 'objectid' => $rec->id, 'relateduserid' => $rec->userid, 'other' => [],
        ]);
    }
}
