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
use mod_presenterai\external\delete_recording;
use mod_presenterai\external\finalize_recording;
use mod_presenterai\external\start_upload;
use mod_presenterai\local\storage\fs_store;

/**
 * The web service that deletes a recording's media early.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\delete_recording
 */
final class external_delete_recording_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \stdClass The learner who owns the recording. */
    private \stdClass $alice;

    /**
     * One course, one activity, one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->alice = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * A finished recording of Alice's, uploaded through the real path.
     *
     * @return \stdClass The recording row.
     */
    private function finished_recording(): \stdClass {
        global $DB;

        $this->setUser($this->alice);
        $content = 'recorded-bytes';
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $target = start_upload::execute($begin['recordingid'], 'recording', 'webm', strlen($content), $begin['attempttoken']);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        finalize_recording::execute($begin['recordingid'], $begin['attempttoken']);

        return $DB->get_record('presenterai_recording', ['id' => $begin['recordingid']], '*', MUST_EXIST);
    }

    /**
     * The owner deletes their own media; the attempt survives and the event says why.
     *
     * @return void
     */
    public function test_owner_deletes_own_media(): void {
        global $DB;

        $rec = $this->finished_recording();
        $sink = $this->redirectEvents();
        $result = external_api::clean_returnvalue(delete_recording::execute_returns(), delete_recording::execute((int) $rec->id));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame('learner', $result['mediagonereason']);
        $this->assertGreaterThan(0, $result['mediadeletedat']);
        $row = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
        $this->assertNull($row->storagekey);
        $this->assertSame('uploaded', $row->status, 'Deleting media must not change the attempt.');

        $deleted = array_values(array_filter($events, fn($e) => $e instanceof \mod_presenterai\event\recording_deleted));
        $this->assertCount(1, $deleted);
        $this->assertSame((int) $this->alice->id, (int) $deleted[0]->relateduserid);
        $this->assertSame('learner', $deleted[0]->other['reason']);
    }

    /**
     * A manager deletes somebody else's media and it is recorded as manual.
     *
     * @return void
     */
    public function test_manager_deletes_as_manual(): void {
        $rec = $this->finished_recording();
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'manager'));

        $result = delete_recording::execute((int) $rec->id);
        $this->assertSame('manual', $result['mediagonereason']);
    }

    /**
     * Another learner and a teacher cannot delete Alice's media.
     *
     * @return void
     */
    public function test_others_are_refused(): void {
        global $DB;

        $rec = $this->finished_recording();
        foreach (['student', 'teacher'] as $role) {
            $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, $role));
            try {
                delete_recording::execute((int) $rec->id);
                $this->fail("A {$role} deleted another learner's recording.");
            } catch (\moodle_exception $e) {
                $this->assertSame('error:cannotdelete', $e->errorcode);
            }
        }
        $this->assertNotEmpty($DB->get_field('presenterai_recording', 'storagekey', ['id' => $rec->id]));
    }

    /**
     * Media that is already gone cannot be deleted again.
     *
     * @return void
     */
    public function test_media_already_gone_is_refused(): void {
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'storagekey' => null,
            'mediagonereason' => 'retention',
        ]);
        $this->setUser($this->alice);

        $this->expectException(\moodle_exception::class);
        delete_recording::execute((int) $rec->id);
    }
}
