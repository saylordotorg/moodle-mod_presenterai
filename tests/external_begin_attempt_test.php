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

/**
 * The web service that starts an attempt.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\begin_attempt
 */
final class external_begin_attempt_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /**
     * One course and one activity on Moodle file storage.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
    }

    /**
     * A learner gets an uploading row, and the same one back on a second call.
     *
     * @return void
     */
    public function test_learner_begins_and_resumes(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($learner);

        $first = external_api::clean_returnvalue(
            begin_attempt::execute_returns(),
            begin_attempt::execute((int) $this->instance->cmid)
        );
        $this->assertGreaterThan(0, $first['recordingid']);
        $this->assertFalse($first['resumed']);
        $this->assertFalse($first['hasdeck']);
        $this->assertSame('fs', $first['backend']);
        $row = $DB->get_record('presenterai_recording', ['id' => $first['recordingid']], '*', MUST_EXIST);
        $this->assertSame((int) $learner->id, (int) $row->userid);
        $this->assertSame('uploading', $row->status);

        $second = external_api::clean_returnvalue(
            begin_attempt::execute_returns(),
            begin_attempt::execute((int) $this->instance->cmid)
        );
        $this->assertTrue($second['resumed']);
        $this->assertSame($first['recordingid'], $second['recordingid']);
    }

    /**
     * Another learner never resumes somebody else's unfinished attempt.
     *
     * @return void
     */
    public function test_a_second_learner_gets_their_own_row(): void {
        global $DB;

        $alice = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $bob = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->setUser($alice);
        $mine = begin_attempt::execute((int) $this->instance->cmid);
        $this->setUser($bob);
        $theirs = begin_attempt::execute((int) $this->instance->cmid);

        $this->assertFalse($theirs['resumed'], 'One learner resumed into another learner\'s attempt.');
        $this->assertNotSame($mine['recordingid'], $theirs['recordingid']);
        $owner = (int) $DB->get_field('presenterai_recording', 'userid', ['id' => $theirs['recordingid']]);
        $this->assertSame((int) $bob->id, $owner);
    }

    /**
     * Without the submit capability there is no attempt.
     *
     * @return void
     */
    public function test_teacher_without_submit_is_refused(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        begin_attempt::execute((int) $this->instance->cmid);
    }

    /**
     * Somebody not in the course cannot begin an attempt.
     *
     * @return void
     */
    public function test_user_outside_the_course_is_refused(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        begin_attempt::execute((int) $this->instance->cmid);
    }
}
