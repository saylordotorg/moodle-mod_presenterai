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

use mod_presenterai\local\media_purger;
use mod_presenterai\task\delete_orphaned_media;

/**
 * Removing attempts completely, storage before rows, on both backends.
 *
 * The privacy provider and course reset both stand on this class, so the
 * cases here are the ones that would leave a learner's media behind: a row
 * deleted before its file, an S3 object nobody remembers, a key printed into a
 * log, and an object another restored row still needs.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\media_purger
 */
final class media_purger_test extends \advanced_testcase {
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
        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->alice = $generator->create_and_enrol($this->course, 'student');
        $this->bob = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * A finished fs recording whose recording and deck files really exist.
     *
     * @param \stdClass $user Owner.
     * @return \stdClass The row.
     */
    private function fs_recording(\stdClass $user): \stdClass {
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $user->id,
            'storagekey' => random_string(32) . '.webm',
            'deckkey' => random_string(32) . '.pdf',
        ]);
        $fs = get_file_storage();
        foreach (['recording' => $rec->storagekey, 'deck' => $rec->deckkey] as $area => $key) {
            $fs->create_file_from_string([
                'contextid' => $this->context->id,
                'component' => 'mod_presenterai',
                'filearea' => $area,
                'itemid' => $rec->id,
                'filepath' => '/',
                'filename' => $key,
                'userid' => $user->id,
            ], 'bytes of ' . $area . ' for ' . $user->id);
        }

        return $rec;
    }

    /**
     * A score row and an AI usage row for one recording, written straight to the tables.
     *
     * @param \stdClass $rec The recording.
     * @return void
     */
    private function score_and_usage(\stdClass $rec): void {
        global $DB;

        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id,
            'userid' => $rec->userid,
            'rubricid' => 0,
            'origin' => 'teacher',
            'scores' => json_encode([['name' => 'Clarity', 'score' => 4, 'max_score' => 5]]),
            'rawsum' => 4,
            'rawmax' => 5,
            'overallpct' => 80,
            'timecreated' => time(),
        ]);
        $DB->insert_record('presenterai_aiusage', (object) [
            'presenteraiid' => $rec->presenteraiid,
            'recordingid' => $rec->id,
            'userid' => $rec->userid,
            'action' => 'transcribe',
            'estmicrocents' => 1234,
            'timecreated' => time(),
        ]);
    }

    /**
     * A configured S3 backend whose endpoint answers nothing, so every delete fails.
     *
     * @return void
     */
    private function unreachable_s3(): void {
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3endpoint', 'http://127.0.0.1:1', 'mod_presenterai');
        set_config('s3pathstyle', 1, 'mod_presenterai');
    }

    /**
     * How many real files this activity holds in the attempt media areas.
     *
     * @return int
     */
    private function media_file_count(): int {
        global $DB;

        return $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'mod_presenterai' AND filearea IN ('recording', 'deck', 'frames')
                AND filename <> '.'",
            ['ctx' => $this->context->id]
        );
    }

    /**
     * The keys queued for the orphan task, flattened.
     *
     * @return string[]
     */
    private function queued_keys(): array {
        $keys = [];
        foreach (\core\task\manager::get_adhoc_tasks(delete_orphaned_media::class) as $task) {
            $data = $task->get_custom_data();
            $this->assertSame('s3', $data->backend);
            $keys = array_merge($keys, (array) $data->keys);
        }

        return $keys;
    }

    /**
     * A privacy purge of one learner takes their files, scores, spend rows and rows, and nobody else's.
     *
     * fs_store finds a file only through the row that names it and reports a
     * debugging message when no row does, so a purge that deleted the rows
     * first would both leave the file and print that message. Neither happens.
     *
     * @return void
     */
    public function test_purge_user_on_fs_deletes_storage_before_rows(): void {
        global $DB;

        $mine = $this->fs_recording($this->alice);
        $this->score_and_usage($mine);
        // A spend row with no recording, such as a deck render, goes too.
        $DB->insert_record('presenterai_aiusage', (object) [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'action' => 'deck',
            'timecreated' => time(),
        ]);
        $theirs = $this->fs_recording($this->bob);
        $this->score_and_usage($theirs);
        $this->assertSame(4, $this->media_file_count());

        media_purger::purge_user((int) $this->instance->id, (int) $this->alice->id, media_purger::AIUSAGE_DELETE);

        $this->assertDebuggingNotCalled();
        $fs = get_file_storage();
        $this->assertFalse($fs->get_file($this->context->id, 'mod_presenterai', 'recording', $mine->id, '/', $mine->storagekey));
        $this->assertFalse($fs->get_file($this->context->id, 'mod_presenterai', 'deck', $mine->id, '/', $mine->deckkey));
        $this->assertFalse($DB->record_exists('presenterai_recording', ['id' => $mine->id]));
        $this->assertFalse($DB->record_exists('presenterai_score', ['recordingid' => $mine->id]));
        $this->assertSame(0, $DB->count_records('presenterai_aiusage', ['userid' => $this->alice->id]));

        // Bob is untouched.
        $this->assertSame(2, $this->media_file_count());
        $this->assertTrue($DB->record_exists('presenterai_recording', ['id' => $theirs->id]));
        $this->assertTrue($DB->record_exists('presenterai_score', ['recordingid' => $theirs->id]));
        $this->assertSame(1, $DB->count_records('presenterai_aiusage', ['userid' => $this->bob->id]));
    }

    /**
     * A reset purge keeps the spend rows, with nobody attached, so totals still add up.
     *
     * @return void
     */
    public function test_purge_instance_anonymises_spend_rows(): void {
        global $DB;

        foreach ([$this->alice, $this->bob] as $user) {
            $this->score_and_usage($this->fs_recording($user));
        }

        media_purger::purge_instance((int) $this->instance->id, media_purger::AIUSAGE_ANONYMISE);

        $this->assertSame(0, $this->media_file_count());
        $this->assertSame(0, $DB->count_records('presenterai_recording', ['presenteraiid' => $this->instance->id]));
        $this->assertSame(0, $DB->count_records('presenterai_score'));
        $usage = $DB->get_records('presenterai_aiusage', ['presenteraiid' => $this->instance->id]);
        $this->assertCount(2, $usage);
        foreach ($usage as $row) {
            $this->assertSame(0, (int) $row->userid);
            $this->assertSame(0, (int) $row->recordingid);
            $this->assertSame(1234, (int) $row->estmicrocents);
        }
    }

    /**
     * Unreachable S3: the rows still go, the keys go to the orphan task, and no key is printed.
     *
     * @return void
     */
    public function test_unreachable_s3_still_deletes_rows_and_queues_the_keys(): void {
        global $DB;

        $this->unreachable_s3();
        $prefix = 'presenterai/' . $this->course->id . '/' . $this->alice->id . '/';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'backend' => 's3',
            'storagekey' => $prefix . 'secretish-rec.webm',
            'deckkey' => $prefix . 'secretish-deck.pdf',
        ]);
        $this->score_and_usage($rec);

        ob_start();
        media_purger::purge_user((int) $this->instance->id, (int) $this->alice->id, media_purger::AIUSAGE_DELETE);
        $output = (string) ob_get_clean();

        foreach ($this->getDebuggingMessages() as $debug) {
            $this->assertStringNotContainsString('secretish', $debug->message, 'A key reached a debugging message.');
        }
        $this->resetDebugging();
        $this->assertStringNotContainsString('secretish', $output, 'A key reached the output.');

        $this->assertFalse($DB->record_exists('presenterai_recording', ['id' => $rec->id]));
        $this->assertFalse($DB->record_exists('presenterai_score', ['recordingid' => $rec->id]));
        $this->assertEqualsCanonicalizing([$rec->storagekey, $rec->deckkey], $this->queued_keys());
    }

    /**
     * S3 not configured at all: the store throws, which is reported without keys and queued.
     *
     * @return void
     */
    public function test_unconfigured_s3_is_queued(): void {
        global $DB;

        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'backend' => 's3',
            'storagekey' => 'presenterai/1/2/secretish.webm',
        ]);

        media_purger::purge_instance((int) $this->instance->id, media_purger::AIUSAGE_DELETE);

        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('secretish', $messages[0]->message);
        $this->resetDebugging();
        $this->assertFalse($DB->record_exists('presenterai_recording', ['id' => $rec->id]));
        $this->assertSame(['presenterai/1/2/secretish.webm'], $this->queued_keys());
    }

    /**
     * An S3 object a restored copy in another activity still names is neither deleted nor queued.
     *
     * The bucket is unreachable, so an attempted delete would fail and be
     * queued. An empty queue therefore proves the delete was never attempted.
     *
     * @return void
     */
    public function test_a_shared_s3_key_is_left_for_the_other_row(): void {
        global $DB;

        $this->unreachable_s3();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $copy = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $shared = 'presenterai/1/2/shared.webm';
        $generator->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'backend' => 's3',
            'storagekey' => $shared,
        ]);
        $kept = $generator->create_recording([
            'presenteraiid' => $copy->id,
            'userid' => $this->alice->id,
            'backend' => 's3',
            'storagekey' => $shared,
        ]);

        media_purger::purge_instance((int) $this->instance->id, media_purger::AIUSAGE_DELETE);

        $this->assertSame([], $this->queued_keys(), 'An object another recording still uses was handed to the deleter.');
        $this->assertSame($shared, $DB->get_field('presenterai_recording', 'storagekey', ['id' => $kept->id]));
        $this->assertSame(0, $DB->count_records('presenterai_recording', ['presenteraiid' => $this->instance->id]));
    }

    /**
     * Rows that share a key inside one batch do not protect each other.
     *
     * @return void
     */
    public function test_a_key_shared_only_inside_the_batch_is_deleted(): void {
        $this->unreachable_s3();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $shared = 'presenterai/1/2/both.webm';
        foreach ([$this->alice, $this->bob] as $user) {
            $generator->create_recording([
                'presenteraiid' => $this->instance->id,
                'userid' => $user->id,
                'backend' => 's3',
                'storagekey' => $shared,
            ]);
        }

        media_purger::purge_instance((int) $this->instance->id, media_purger::AIUSAGE_DELETE);

        // Attempted (and, the bucket being down, queued) rather than skipped.
        $this->assertSame([$shared], $this->queued_keys());
    }

    /**
     * A mode this class does not know is a coding error, not a silent default.
     *
     * @return void
     */
    public function test_unknown_mode_is_refused(): void {
        $this->expectException(\coding_exception::class);
        media_purger::purge_instance((int) $this->instance->id, 'keep');
    }

    /**
     * The gate log rows of purged recordings go with them; another learner's stay.
     *
     * @return void
     */
    public function test_purge_deletes_gatelog_rows(): void {
        global $DB;

        $alicerec = $this->fs_recording($this->alice);
        $bobrec = $this->fs_recording($this->bob);
        foreach ([$alicerec, $bobrec] as $rec) {
            $DB->insert_record('presenterai_gatelog', (object) [
                'recordingid' => $rec->id, 'target' => 'summary', 'layer' => 2, 'rule' => 'appearance',
                'rejectedtext' => 'about ' . $rec->userid, 'timecreated' => time(),
            ]);
        }

        media_purger::purge_user((int) $this->instance->id, (int) $this->alice->id, media_purger::AIUSAGE_DELETE);

        $this->assertFalse($DB->record_exists('presenterai_gatelog', ['recordingid' => $alicerec->id]));
        $this->assertTrue($DB->record_exists('presenterai_gatelog', ['recordingid' => $bobrec->id]));
    }
}
