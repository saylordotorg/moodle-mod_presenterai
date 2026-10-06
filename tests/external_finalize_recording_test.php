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
}
