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

use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\fs_store;
use mod_presenterai\task\cleanup;

/**
 * The hourly task that abandons, expires and prunes.
 *
 * Every case is a recording that must or must not be deleted, because this is
 * the one place in the plugin that destroys a learner's work without anyone
 * pressing a button.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\task\cleanup
 */
final class cleanup_task_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /** @var \stdClass Another learner. */
    private \stdClass $bob;

    /**
     * One course, one activity, two learners, on Moodle file storage.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        set_config('retentiondays', 0, 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = get_course($generator->create_course()->id);
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'storedattempts', 0, ['id' => $this->instance->id]);
        $this->instance->storedattempts = 0;
        $this->context = \context_module::instance($this->instance->cmid);
        $this->alice = $generator->create_and_enrol($this->course, 'student');
        $this->bob = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * Run the task, swallowing its mtrace output.
     *
     * @return string What it printed.
     */
    private function run_task(): string {
        ob_start();
        (new cleanup())->execute();

        return (string) ob_get_clean();
    }

    /**
     * The row as the database now holds it.
     *
     * @param int $id Recording id.
     * @return \stdClass
     */
    private function reload(int $id): \stdClass {
        global $DB;

        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * A finished recording row with a fake key, for the steps that only need the row.
     *
     * @param \stdClass $user Owner.
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(\stdClass $user, array $fields = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $user->id,
            'storagekey' => random_string(32) . '.webm',
        ]);
    }

    /**
     * An upload left for a day is abandoned and its staging removed; one left for 23 hours is not.
     *
     * @return void
     */
    public function test_unfinished_uploads_are_abandoned_after_a_day(): void {
        global $CFG, $DB;

        $cm = get_coursemodule_from_id('presenterai', $this->instance->cmid, 0, false, MUST_EXIST);
        $stale = recording_manager::begin($this->instance, $cm, $this->context, (int) $this->alice->id)['recording'];
        $target = recording_manager::start_upload($stale, $this->instance, $this->course, $this->context, 'recording', 'webm', 10);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'partial');
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        $DB->set_field('presenterai_recording', 'timecreated', time() - DAYSECS - 60, ['id' => $stale->id]);

        $recent = recording_manager::begin($this->instance, $cm, $this->context, (int) $this->bob->id)['recording'];
        $DB->set_field('presenterai_recording', 'timecreated', time() - 23 * HOURSECS, ['id' => $recent->id]);

        $this->run_task();

        $row = $this->reload((int) $stale->id);
        $this->assertSame('abandoned', $row->status);
        $this->assertNull($row->storagekey);
        $this->assertNull($row->uploadid);
        $this->assertSame(
            0,
            (int) $row->mediadeletedat,
            'An upload that never finished must not be announced as a deleted recording.'
        );
        $this->assertEmpty($row->mediagonereason);
        $this->assertFileDoesNotExist($CFG->tempdir . '/presenterai/' . $target['uploadid'] . '.part');

        $this->assertSame('uploading', $this->reload((int) $recent->id)->status, 'An upload still within its day was abandoned.');
    }

    /**
     * Expired media is deleted with its reason, and a real file really goes.
     *
     * @return void
     */
    public function test_retention_deletes_expired_media(): void {
        global $DB;

        $cm = get_coursemodule_from_id('presenterai', $this->instance->cmid, 0, false, MUST_EXIST);
        $rec = recording_manager::begin($this->instance, $cm, $this->context, (int) $this->alice->id)['recording'];
        $target = recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'recording', 'webm', 3);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'abc');
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        $done = recording_manager::finalize($rec, $this->instance, $this->course, $this->context, 0, 10, '');
        $DB->update_record('presenterai_recording', (object) [
            'id' => $done->id,
            'timecreated' => time() - 10 * DAYSECS,
            'expiresat' => time() - 60,
        ]);

        $this->run_task();

        $row = $this->reload((int) $done->id);
        $this->assertNull($row->storagekey);
        $this->assertSame('retention', $row->mediagonereason);
        $this->assertSame('uploaded', $row->status, 'Retention changed the attempt\'s status, which is how grades were lost (D8).');
        $this->assertFalse(
            get_file_storage()->get_file($this->context->id, 'mod_presenterai', 'recording', $done->id, '/', $done->storagekey)
        );
    }

    /**
     * A recording finished within the last day is held, whatever its date says; a 0 date is never.
     *
     * @return void
     */
    public function test_the_floor_and_the_zero_date_hold(): void {
        $fresh = $this->recording($this->alice, ['expiresat' => time() - 60, 'timecreated' => time() - HOURSECS]);
        $never = $this->recording($this->bob, ['expiresat' => 0, 'timecreated' => time() - 400 * DAYSECS]);

        $this->run_task();

        $this->assertNotNull(
            $this->reload((int) $fresh->id)->storagekey,
            'Media an hour old was deleted before scoring could read it (design 8.7a).'
        );
        $this->assertNotNull($this->reload((int) $never->id)->storagekey, 'A recording promised to be kept was deleted.');
    }

    /**
     * storedattempts 0 keeps everything; 1 keeps each learner's newest and nobody else's is touched.
     *
     * @return void
     */
    public function test_pruning(): void {
        global $DB;

        $older = $this->recording($this->alice, ['timecreated' => time() - 3 * DAYSECS, 'status' => 'scored']);
        $newer = $this->recording($this->alice, ['timecreated' => time() - 2 * DAYSECS, 'status' => 'scored']);
        $bobs = $this->recording($this->bob, ['timecreated' => time() - 5 * DAYSECS, 'status' => 'scored']);

        $this->run_task();
        foreach ([$older, $newer, $bobs] as $rec) {
            $this->assertNotNull($this->reload((int) $rec->id)->storagekey, 'storedattempts 0 pruned a recording (D22).');
        }

        $DB->set_field('presenterai', 'storedattempts', 1, ['id' => $this->instance->id]);
        $this->run_task();

        $this->assertNull($this->reload((int) $older->id)->storagekey);
        $this->assertSame('pruned', $this->reload((int) $older->id)->mediagonereason);
        $this->assertNotNull($this->reload((int) $newer->id)->storagekey, 'The newest recording was pruned.');
        $this->assertNotNull(
            $this->reload((int) $bobs->id)->storagekey,
            'Another learner\'s only recording was pruned, the shape of Soapbox\'s keyed-by-assignment bug.'
        );
    }

    /**
     * Pruning never deletes media that scoring hasn't read yet, but still counts it as the newest.
     *
     * @return void
     */
    public function test_pruning_never_touches_an_attempt_waiting_to_be_scored(): void {
        global $DB;

        $uploaded = $this->recording($this->alice, ['timecreated' => time() - 5 * DAYSECS, 'status' => 'uploaded']);
        $scoring = $this->recording($this->alice, ['timecreated' => time() - 4 * DAYSECS, 'status' => 'scoring']);
        $scored = $this->recording($this->alice, ['timecreated' => time() - 3 * DAYSECS, 'status' => 'scored']);
        $failed = $this->recording($this->alice, ['timecreated' => time() - 2 * DAYSECS, 'status' => 'failed']);
        $newest = $this->recording($this->alice, ['timecreated' => time() - DAYSECS, 'status' => 'uploaded']);

        $DB->set_field('presenterai', 'storedattempts', 1, ['id' => $this->instance->id]);
        $this->run_task();

        $this->assertNotNull($this->reload((int) $newest->id)->storagekey, 'The newest attempt was pruned.');
        $this->assertNotNull($this->reload((int) $uploaded->id)->storagekey, 'An attempt waiting to be scored was pruned.');
        $this->assertNotNull($this->reload((int) $scoring->id)->storagekey, 'An attempt being scored was pruned.');
        $this->assertNull($this->reload((int) $scored->id)->storagekey, 'A scored older attempt was not pruned.');
        $this->assertSame('pruned', $this->reload((int) $scored->id)->mediagonereason);
        $this->assertNull($this->reload((int) $failed->id)->storagekey, 'A failed older attempt was not pruned.');
        $this->assertSame(['uploaded', 'scoring'], cleanup::PRUNE_PROTECTED);
    }

    /**
     * A row on an unconfigured backend is skipped without stopping the rest.
     *
     * @return void
     */
    public function test_an_unconfigured_backend_does_not_stop_the_run(): void {
        $s3row = $this->recording($this->alice, [
            'backend' => 's3',
            'storagekey' => 'presenterai/1/2/abc.webm',
            'expiresat' => time() - 60,
            'timecreated' => time() - 10 * DAYSECS,
        ]);
        $fsrow = $this->recording($this->bob, ['expiresat' => time() - 60, 'timecreated' => time() - 10 * DAYSECS]);

        $output = $this->run_task();

        $this->assertNotNull($this->reload((int) $s3row->id)->storagekey, 'A row whose store cannot be reached was cleared.');
        $this->assertNull($this->reload((int) $fsrow->id)->storagekey, 'One unreachable backend stopped retention for everyone.');
        $this->assertStringNotContainsString('presenterai/1/2/abc.webm', $output, 'A key reached the task log.');
    }
}
