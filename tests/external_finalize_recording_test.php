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

use core_external\external_api;
use mod_presenterai\external\begin_attempt;
use mod_presenterai\external\finalize_recording;
use mod_presenterai\external\start_upload;
use mod_presenterai\local\storage\fs_store;

/**
 * The web service that makes an attempt count.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\finalize_recording
 */
final class external_finalize_recording_test extends \advanced_testcase {
    /** @var string The token begin_attempt returned for the attempt under test. */
    private string $token = '';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \stdClass The learner who owns the attempt. */
    private \stdClass $alice;

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
        $this->alice = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Alice begins an attempt and uploads her recording, without finalizing it.
     *
     * @param string $content The recording bytes.
     * @return int The recording id.
     */
    private function uploaded_attempt(string $content): int {
        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $target = start_upload::execute($begin['recordingid'], 'recording', 'webm', strlen($content), $begin['attempttoken']);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        $this->token = (string) $begin['attempttoken'];

        return (int) $begin['recordingid'];
    }

    /**
     * The owner finalizes and gets the confirmed size and the first attempt number.
     *
     * @return void
     */
    public function test_owner_finalizes(): void {
        $content = str_repeat('spoken words ', 100);
        $recordingid = $this->uploaded_attempt($content);

        $result = external_api::clean_returnvalue(
            finalize_recording::execute_returns(),
            finalize_recording::execute($recordingid, $this->token, 0, 42, '')
        );

        $this->assertSame($recordingid, $result['recordingid']);
        $this->assertSame('uploaded', $result['status']);
        $this->assertSame(1, $result['attemptnumber']);
        $this->assertSame(0, $result['expiresat']);
        $this->assertSame(strlen($content), $result['sizebytes'], 'The size must be the store\'s answer, not the browser\'s.');

        $again = finalize_recording::execute($recordingid, $this->token, 0, 42, '');
        $this->assertSame($result['attemptnumber'], $again['attemptnumber'], 'A retried finalize changed the answer.');
    }

    /**
     * With several topics, a recording without a valid choice is refused and
     * kept, and goes through once a topic is chosen.
     *
     * @return void
     */
    public function test_several_topics_require_a_choice(): void {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $first = $gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'First']);
        $gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'Second']);
        $recordingid = $this->uploaded_attempt(str_repeat('spoken words ', 50));

        try {
            finalize_recording::execute($recordingid, $this->token, 0, 30, '');
            $this->fail('A recording with no topic was accepted on an activity with several.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:topicrequired', $e->errorcode);
        }
        $row = $DB->get_record('presenterai_recording', ['id' => $recordingid]);
        $this->assertSame('uploading', $row->status, 'The refused recording must be kept for Retry.');

        // Another activity's topic is not a valid choice either.
        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $foreign = $gen->create_topic(['presenteraiid' => $other->id, 'title' => 'Elsewhere']);
        try {
            finalize_recording::execute($recordingid, $this->token, (int) $foreign->id, 30, '');
            $this->fail('A topic from another activity was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:topicrequired', $e->errorcode);
        }

        $result = finalize_recording::execute($recordingid, $this->token, (int) $first->id, 30, '');
        $this->assertSame('uploaded', $result['status']);
        $this->assertSame((int) $first->id, (int) $DB->get_field('presenterai_recording', 'topicid', ['id' => $recordingid]));
    }

    /**
     * With exactly one topic there is nothing to choose, so it is used.
     *
     * @return void
     */
    public function test_a_single_topic_is_used_without_a_choice(): void {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $only = $gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'Only']);
        $recordingid = $this->uploaded_attempt(str_repeat('spoken words ', 50));

        $result = finalize_recording::execute($recordingid, $this->token, 0, 30, '');
        $this->assertSame('uploaded', $result['status']);
        $this->assertSame((int) $only->id, (int) $DB->get_field('presenterai_recording', 'topicid', ['id' => $recordingid]));
    }

    /**
     * Another learner cannot finalize somebody else's attempt.
     *
     * @return void
     */
    public function test_another_learner_is_refused(): void {
        global $DB;

        $recordingid = $this->uploaded_attempt('abc');
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        try {
            finalize_recording::execute($recordingid, $this->token);
            $this->fail('A learner finalized another learner\'s attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:recordingnotfound', $e->errorcode);
        }
        $this->assertSame('uploading', $DB->get_field('presenterai_recording', 'status', ['id' => $recordingid]));
    }

    /**
     * A teacher, who cannot submit, cannot finalize.
     *
     * @return void
     */
    public function test_teacher_is_refused(): void {
        $recordingid = $this->uploaded_attempt('abc');
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'teacher'));

        $this->expectException(\required_capability_exception::class);
        finalize_recording::execute($recordingid, $this->token);
    }

    /**
     * A refused finalize fires no submitted event; the one that succeeds fires exactly one.
     *
     * @return void
     */
    public function test_submitted_event_only_on_success(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $first = $gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'First']);
        $gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'Second']);
        $recordingid = $this->uploaded_attempt(str_repeat('spoken words ', 50));
        $sink = $this->redirectEvents();

        try {
            finalize_recording::execute($recordingid, $this->token, 0, 30, '');
            $this->fail('A recording with no topic was accepted on an activity with several.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:topicrequired', $e->errorcode);
        }
        finalize_recording::execute($recordingid, $this->token, (int) $first->id, 30, '');
        finalize_recording::execute($recordingid, $this->token, (int) $first->id, 30, '');

        $submitted = array_filter($sink->get_events(), function ($event): bool {
            return $event instanceof \mod_presenterai\event\recording_submitted;
        });
        $this->assertCount(1, $submitted);
    }

    /**
     * Finalizing updates the learner's stored completion for completionsubmit.
     *
     * @return void
     */
    public function test_finalize_updates_completion(): void {
        global $DB;

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        $this->instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
        ]);
        $course = get_course($this->course->id);
        $cm = get_fast_modinfo($course)->get_cm($this->instance->cmid);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $this->alice->id)->completionstate);

        $recordingid = $this->uploaded_attempt(str_repeat('spoken words ', 50));
        finalize_recording::execute($recordingid, $this->token, 0, 30, '');

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $this->alice->id)->completionstate);
    }

    /**
     * D24 through the web service: the opt out is stored, frames that arrived anyway are deleted.
     *
     * @return void
     */
    public function test_visualoptout_is_stored_and_frames_deleted(): void {
        global $DB;

        $DB->update_record('presenterai', (object) [
            'id' => $this->instance->id, 'videovision' => 1, 'mode' => 'video', 'allowvisualoptout' => 1,
        ]);
        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $this->token = (string) $begin['attempttoken'];
        $recordingid = (int) $begin['recordingid'];
        foreach (['frames' => ['jpg', "\xFF\xD8\xFF sheet"], 'recording' => ['webm', 'video bytes']] as $kind => [$ext, $bytes]) {
            $target = start_upload::execute($recordingid, $kind, $ext, strlen($bytes), $this->token);
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $bytes);
            rewind($stream);
            (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        }
        $this->assertNotEmpty($DB->get_field('presenterai_recording', 'frameskey', ['id' => $recordingid]));

        finalize_recording::execute($recordingid, $this->token, 0, 42, '', 1);

        $row = $DB->get_record('presenterai_recording', ['id' => $recordingid], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->visualoptout);
        $this->assertEmpty($row->frameskey, 'Frames from an opted out attempt were kept.');
        $context = \context_module::instance($this->instance->cmid);
        $this->assertEmpty(
            get_file_storage()->get_area_files($context->id, 'mod_presenterai', 'frames', $recordingid, 'id', false),
            'The frame sheet is still in the file area.'
        );
    }

    /**
     * Without the parameter nothing is opted out.
     *
     * @return void
     */
    public function test_visualoptout_defaults_to_off(): void {
        global $DB;

        $recordingid = $this->uploaded_attempt('some bytes');
        finalize_recording::execute($recordingid, $this->token, 0, 42, '');

        $this->assertSame(0, (int) $DB->get_field('presenterai_recording', 'visualoptout', ['id' => $recordingid]));
    }
}

