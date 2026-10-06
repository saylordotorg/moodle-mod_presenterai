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
use mod_presenterai\local\storage\media_ref;

/**
 * An attempt's life on the File API backend, end to end, with nothing mocked.
 *
 * Bytes go through fs_store exactly as upload.php sends them, so a key that the
 * row stops naming, a deck committed into the wrong area or a staging file that
 * is never promoted fails here rather than for a learner.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\recording_manager
 */
final class recording_manager_test extends \advanced_testcase {
    /** @var \stdClass The course, as get_course() returns it. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $user;

    /** @var \stdClass The activity instance, slides enabled. */
    private \stdClass $instance;

    /** @var \stdClass Its course module row. */
    private \stdClass $cm;

    /** @var \context_module Its context. */
    private \context_module $context;

    /**
     * One course, one learner, one slides-enabled activity on a site that keeps forever.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        set_config('retentiondays', 0, 'mod_presenterai');
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = get_course($generator->create_course()->id);
        $this->user = $generator->create_and_enrol($this->course, 'student');
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        // Written straight to the row rather than through the form path, so
        // nothing that normalises form data can change what the test relies on.
        $fields = ['slidesenabled' => 1, 'maxattempts' => 0, 'storedattempts' => 0, 'retentiondays' => -1];
        $DB->update_record('presenterai', (object) (['id' => $this->instance->id] + $fields));
        foreach ($fields as $name => $value) {
            $this->instance->$name = $value;
        }
        $this->cm = get_coursemodule_from_id('presenterai', $this->instance->cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($this->instance->cmid);
    }

    /**
     * A readable stream carrying one chunk body.
     *
     * @param string $bytes The chunk content.
     * @return resource
     */
    private function stream_of(string $bytes) {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
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
     * Begin a fresh attempt for the learner.
     *
     * @return \stdClass The new uploading row.
     */
    private function begin(): \stdClass {
        return recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
    }

    /**
     * Start an upload of one kind and send it in a single chunk, as upload.php would.
     *
     * @param \stdClass $rec The uploading row.
     * @param string $kind media_ref::KIND_RECORDING or media_ref::KIND_DECK.
     * @param string $content The bytes.
     * @return array The upload target start_upload returned.
     */
    private function send(\stdClass $rec, string $kind, string $content): array {
        $ext = $kind === media_ref::KIND_DECK ? 'pdf' : 'webm';
        $target = recording_manager::start_upload(
            $rec,
            $this->instance,
            $this->course,
            $this->context,
            $kind,
            $ext,
            strlen($content)
        );
        (new fs_store())->accept_chunk($target['uploadid'], 0, $this->stream_of($content));

        return $target;
    }

    /**
     * Finalize with the given extras.
     *
     * @param \stdClass $rec The row.
     * @param int $topicid Topic id or 0.
     * @param string $timeline Timeline JSON or empty.
     * @return \stdClass The finalized row.
     */
    private function finalize(\stdClass $rec, int $topicid = 0, string $timeline = ''): \stdClass {
        return recording_manager::finalize($rec, $this->instance, $this->course, $this->context, $topicid, 30, $timeline);
    }

    /**
     * Assert that a call throws a moodle_exception with this error code.
     *
     * @param string $errorcode Expected code.
     * @param callable $call The call.
     * @param string $why What it means if it does not.
     * @return void
     */
    private function assert_refused(string $errorcode, callable $call, string $why): void {
        try {
            $call();
            $this->fail($why);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $why);
        }
    }

    /**
     * A new attempt is an uploading row on the default backend, with no keys and no number yet.
     *
     * @return void
     */
    public function test_begin_creates_an_uploading_row(): void {
        $result = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id);
        $rec = $result['recording'];

        $this->assertFalse($result['resumed']);
        $this->assertSame('uploading', $rec->status);
        $this->assertSame('fs', $rec->backend);
        $this->assertSame(0, (int) $rec->attemptnumber, 'The number is assigned at finalize, so an abandoned try never uses one.');
        $this->assertEmpty($rec->storagekey, 'No key is minted until the learner has something to upload.');
        $this->assertSame(0, (int) $rec->expiresat);
    }

    /**
     * Reloading the page resumes the unfinished attempt rather than making another row.
     *
     * @return void
     */
    public function test_begin_resumes_within_the_window(): void {
        global $DB;

        $first = $this->begin();
        $again = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id);
        $this->assertTrue($again['resumed']);
        $this->assertSame((int) $first->id, (int) $again['recording']->id, 'A reload made a second row for the same attempt.');

        $old = time() - recording_manager::RESUME_WINDOW - 60;
        $DB->set_field('presenterai_recording', 'timecreated', $old, ['id' => $first->id]);
        $later = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id);
        $this->assertFalse($later['resumed']);
        $this->assertNotSame((int) $first->id, (int) $later['recording']->id);
    }

    /**
     * The cap counts finished attempts only.
     *
     * @return void
     */
    public function test_cap_ignores_unfinished_abandoned_and_failed_attempts(): void {
        global $DB;

        $DB->set_field('presenterai', 'maxattempts', 2, ['id' => $this->instance->id]);
        $this->instance->maxattempts = 2;
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $base = ['presenteraiid' => $this->instance->id, 'userid' => $this->user->id];
        $gen->create_recording($base + ['status' => 'uploading', 'timecreated' => time() - 2 * DAYSECS]);
        $gen->create_recording($base + ['status' => 'abandoned']);
        $gen->create_recording($base + ['status' => 'failed']);
        $gen->create_recording($base + ['status' => 'uploaded']);

        $this->assertSame(1, recording_manager::counted_attempts((int) $this->instance->id, (int) $this->user->id));
        $this->assertFalse(
            recording_manager::cap_reached($this->instance, (int) $this->user->id),
            'An upload that never finished used up an attempt, so a dropped connection cost the learner a try.'
        );

        $gen->create_recording($base + ['status' => 'uploaded', 'storagekey' => null, 'mediagonereason' => 'learner']);
        $this->assertTrue(
            recording_manager::cap_reached($this->instance, (int) $this->user->id),
            'An attempt whose media was deleted must still count, or deleting media resets the cap.'
        );
        $this->assert_refused('error:capreached', fn() => $this->begin(), 'A learner with no attempts left was allowed to start.');

        $this->instance->maxattempts = 0;
        $this->assertFalse(recording_manager::cap_reached($this->instance, (int) $this->user->id), '0 means unlimited.');
    }

    /**
     * The key is minted and stored server side and never handed to the browser.
     *
     * @return void
     */
    public function test_start_upload_writes_the_key_to_the_row(): void {
        $rec = $this->begin();
        $target = recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'recording', 'webm', 1000);
        $row = $this->reload((int) $rec->id);

        $this->assertArrayNotHasKey(
            'key',
            $target,
            'The key reached the browser, which is what let Soapbox finalize foreign keys.'
        );
        $this->assertStringEndsWith('.webm', (string) $row->storagekey);
        $this->assertSame($target['uploadid'], $row->uploadid);
        $this->assertSame('POST', $target['method']);
        $this->assertGreaterThan(0, $target['chunkbytes']);
        $this->assertSame(0, $target['expires']);
        $this->assertSame(recording_manager::max_media_bytes($this->course), $target['maxbytes']);
    }

    /**
     * Bad extensions, a deck where slides are off, and an oversized declaration are refused.
     *
     * @return void
     */
    public function test_start_upload_refuses_what_it_should(): void {
        global $DB;

        $rec = $this->begin();
        $start = fn(string $kind, string $ext, int $size) => recording_manager::start_upload(
            $rec,
            $this->instance,
            $this->course,
            $this->context,
            $kind,
            $ext,
            $size
        );

        $this->assert_refused('error:badext', fn() => $start('recording', 'exe', 10), 'An executable was accepted as a recording.');
        $this->assert_refused('error:badext', fn() => $start('deck', 'webm', 10), 'A video was accepted as a slide deck.');
        $this->assert_refused(
            'error:uploadtoolarge',
            fn() => $start('recording', 'webm', recording_manager::max_media_bytes($this->course) + 1),
            'A recording larger than the ceiling was offered an upload target.'
        );
        $this->assert_refused(
            'error:uploadtoolarge',
            fn() => $start('deck', 'pdf', recording_manager::MAX_DECK_BYTES + 1),
            'A deck larger than the deck ceiling was offered an upload target.'
        );

        $this->instance->slidesenabled = 0;
        $DB->set_field('presenterai', 'slidesenabled', 0, ['id' => $this->instance->id]);
        $this->assert_refused(
            'error:slidesdisabled',
            fn() => $start('deck', 'pdf', 10),
            'A deck was accepted where slides are off.'
        );

        $this->expectException(\invalid_parameter_exception::class);
        $start('frames', 'jpg', 10);
    }

    /**
     * Replacing a deck deletes the one it replaces.
     *
     * @return void
     */
    public function test_replacing_a_deck_deletes_the_old_file(): void {
        $rec = $this->begin();
        $this->send($rec, 'deck', '%PDF-1.4 first deck');
        $committed = recording_manager::commit_pending_deck($rec, $this->context, $this->course);
        $this->assertSame(strlen('%PDF-1.4 first deck'), $committed);
        $oldkey = (string) $this->reload((int) $rec->id)->deckkey;
        $fs = get_file_storage();
        $this->assertNotFalse($fs->get_file($this->context->id, 'mod_presenterai', 'deck', $rec->id, '/', $oldkey));

        $this->send($rec, 'deck', '%PDF-1.4 second deck');
        $this->assertFalse(
            $fs->get_file($this->context->id, 'mod_presenterai', 'deck', $rec->id, '/', $oldkey),
            'The replaced deck is still stored, and with no row naming it nothing can ever delete it.'
        );
        $this->assertNotSame($oldkey, (string) $this->reload((int) $rec->id)->deckkey);
    }

    /**
     * A deck cannot be started once the recording has a key.
     *
     * @return void
     */
    public function test_deck_after_recording_is_refused(): void {
        $rec = $this->begin();
        $this->send($rec, 'recording', 'video-bytes');
        $this->assert_refused(
            'error:deckafterrecording',
            fn() => recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'deck', 'pdf', 10),
            'A deck started after the recording would take over the recording\'s upload id.'
        );
    }

    /**
     * The full round trip: chunks in, finalize, a stored file and a counted attempt.
     *
     * @return void
     */
    public function test_finalize_commits_the_recording(): void {
        $content = str_repeat('presentation-', 400);
        $rec = $this->begin();
        $this->send($rec, 'recording', $content);

        $done = $this->finalize($rec);

        $this->assertSame('uploaded', $done->status);
        $this->assertSame(strlen($content), (int) $done->sizebytes);
        $this->assertSame(1, (int) $done->attemptnumber);
        $this->assertSame(30, (int) $done->durationseconds);
        $this->assertEmpty($done->uploadid, 'A finished upload must not keep an upload id the sweep would act on.');
        $this->assertSame(0, (int) $done->expiresat, 'A site that keeps forever wrote a deletion date.');

        $file = get_file_storage()->get_file($this->context->id, 'mod_presenterai', 'recording', $done->id, '/', $done->storagekey);
        $this->assertNotFalse($file);
        $this->assertSame($content, $file->get_content());
    }

    /**
     * A second finalize returns the same answer and changes nothing.
     *
     * @return void
     */
    public function test_finalize_is_idempotent(): void {
        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc123');
        $first = $this->finalize($rec);
        $second = $this->finalize($this->reload((int) $rec->id));

        $this->assertSame((int) $first->attemptnumber, (int) $second->attemptnumber);
        $this->assertSame($first->status, $second->status);
        $this->assertSame((int) $first->timemodified, (int) $second->timemodified, 'A retried finalize rewrote the row.');
    }

    /**
     * expiresat is written once from the activity's window and a later settings change leaves it alone.
     *
     * @return void
     */
    public function test_expiry_is_written_once(): void {
        global $DB;

        $DB->set_field('presenterai', 'retentiondays', 7, ['id' => $this->instance->id]);
        $this->instance->retentiondays = 7;
        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');
        $before = time();
        $done = $this->finalize($rec);
        $after = time();

        $this->assertGreaterThanOrEqual($before + 7 * DAYSECS, (int) $done->expiresat);
        $this->assertLessThanOrEqual($after + 7 * DAYSECS, (int) $done->expiresat);

        set_config('retentiondays', 1, 'mod_presenterai');
        $DB->set_field('presenterai', 'retentiondays', 1, ['id' => $this->instance->id]);
        $this->instance->retentiondays = 1;
        $this->finalize($this->reload((int) $rec->id));

        $this->assertSame(
            (int) $done->expiresat,
            (int) $this->reload((int) $rec->id)->expiresat,
            'A settings change moved a deletion date the learner had already been shown (design 8.2).'
        );
    }

    /**
     * Attempt numbers count finished attempts, so an abandoned one leaves no gap.
     *
     * @return void
     */
    public function test_attempt_numbers_skip_abandoned_rows(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $base = ['presenteraiid' => $this->instance->id, 'userid' => $this->user->id];
        $gen->create_recording($base + ['status' => 'uploaded', 'attemptnumber' => 1, 'timecreated' => time() - 3 * DAYSECS]);
        $gen->create_recording($base + ['status' => 'abandoned', 'attemptnumber' => 0, 'timecreated' => time() - 2 * DAYSECS]);

        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');

        $this->assertSame(2, (int) $this->finalize($rec)->attemptnumber);
    }

    /**
     * A topic from another activity is dropped; one from this activity is kept.
     *
     * @return void
     */
    public function test_finalize_keeps_only_this_activitys_topic(): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $foreign = $gen->create_topic(['presenteraiid' => $other->id]);
        $own = $gen->create_topic(['presenteraiid' => $this->instance->id]);

        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');
        $this->assertNull($this->finalize($rec, (int) $foreign->id)->topicid, 'A topic from another activity was attached.');

        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');
        $this->assertSame((int) $own->id, (int) $this->finalize($rec, (int) $own->id)->topicid);
    }

    /**
     * The timeline is cleaned when there is a deck and discarded when there is not.
     *
     * @return void
     */
    public function test_timeline_is_normalised_and_needs_a_deck(): void {
        $raw = json_encode([['t' => 5.4, 'i' => 1], ['t' => -3, 'i' => 0], ['bad' => 1], ['t' => 2, 'i' => -4]]);

        $rec = $this->begin();
        $this->send($rec, 'deck', '%PDF-1.4 deck');
        $this->send($rec, 'recording', 'video');
        $done = $this->finalize($rec, 0, $raw);
        $this->assertNotEmpty($done->deckkey, 'The deck did not survive the recording upload.');
        $this->assertSame(
            [['t' => 0, 'i' => 0], ['t' => 2, 'i' => 0], ['t' => 5, 'i' => 1]],
            json_decode($done->slidetimeline, true)
        );

        $rec = $this->begin();
        $this->send($rec, 'recording', 'video');
        $this->assertNull(
            $this->finalize($rec, 0, $raw)->slidetimeline,
            'A timeline with no deck points at slides that do not exist.'
        );
    }

    /**
     * Finalize before any bytes arrived is a retryable failure, and the row stays uploading.
     *
     * @return void
     */
    public function test_finalize_without_bytes_is_uploadmissing(): void {
        $rec = $this->begin();
        $this->assert_refused(
            'error:uploadmissing',
            fn() => $this->finalize($rec),
            'An attempt with no key was finalized.'
        );

        recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'recording', 'webm', 10);
        $this->assert_refused(
            'error:uploadmissing',
            fn() => $this->finalize($this->reload((int) $rec->id)),
            'An attempt whose bytes never arrived was finalized, so the learner is graded on nothing.'
        );
        $this->assertSame('uploading', $this->reload((int) $rec->id)->status, 'The learner can no longer retry the upload.');
    }

    /**
     * A recording larger than the course allows is refused at finalize and the attempt abandoned.
     *
     * @return void
     */
    public function test_finalize_refuses_an_oversized_recording(): void {
        global $DB;

        $rec = $this->begin();
        $this->send($rec, 'recording', str_repeat('x', 500));
        $DB->set_field('course', 'maxbytes', 100, ['id' => $this->course->id]);
        $this->course->maxbytes = 100;

        $this->assert_refused(
            'error:uploadtoolarge',
            fn() => $this->finalize($this->reload((int) $rec->id)),
            'A recording over the course limit was accepted.'
        );
        $row = $this->reload((int) $rec->id);
        $this->assertSame('abandoned', $row->status);
        $this->assertEmpty($row->storagekey);
        $this->assertSame(0, (int) $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'mod_presenterai' AND filearea = 'recording' AND filename <> '.'",
            ['ctx' => $this->context->id]
        ), 'A refused recording was left in the file area.');
    }

    /**
     * drop_media deletes the bytes, records why, and leaves the attempt alone.
     *
     * @return void
     */
    public function test_drop_media_deletes_and_records_why(): void {
        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');
        $done = $this->finalize($rec);
        $key = (string) $done->storagekey;

        $this->assertTrue(recording_manager::drop_media($done, 'retention'));

        $row = $this->reload((int) $rec->id);
        $this->assertNull($row->storagekey);
        $this->assertSame('retention', $row->mediagonereason);
        $this->assertGreaterThan(0, (int) $row->mediadeletedat);
        $this->assertSame('uploaded', $row->status, 'Status changed on a media delete, which is how Soapbox lost grades (D8).');
        $this->assertFalse(
            get_file_storage()->get_file($this->context->id, 'mod_presenterai', 'recording', $rec->id, '/', $key),
            'The row says the media is gone and the file is still stored.'
        );
    }

    /**
     * When the main delete fails, nothing is written, so the next run retries.
     *
     * @return void
     */
    public function test_drop_media_changes_nothing_when_the_delete_fails(): void {
        // A configured S3 backend whose endpoint answers nothing: every delete fails.
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3endpoint', 'http://127.0.0.1:1', 'mod_presenterai');
        set_config('s3pathstyle', 1, 'mod_presenterai');

        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->user->id,
            'backend' => 's3',
            'storagekey' => 'presenterai/' . $this->course->id . '/' . $this->user->id . '/abc.webm',
        ]);

        $this->assertFalse(recording_manager::drop_media($rec, 'retention'));
        $row = $this->reload((int) $rec->id);
        $this->assertSame($rec->storagekey, $row->storagekey, 'The row forgot media the bucket still holds.');
        $this->assertSame(0, (int) $row->mediadeletedat);

        $this->expectException(\coding_exception::class);
        recording_manager::drop_media($rec, 'because');
    }

    /**
     * Deleting an activity deletes every stored object first.
     *
     * @return void
     */
    public function test_delete_all_media_for_instance(): void {
        global $DB;

        $rec = $this->begin();
        $this->send($rec, 'deck', '%PDF-1.4 deck');
        $this->send($rec, 'recording', 'abc');
        $this->finalize($rec);
        $other = $this->begin();
        $this->send($other, 'recording', 'def');
        $this->finalize($other);

        $this->assertSame(3, (int) $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'mod_presenterai' AND filename <> '.'",
            ['ctx' => $this->context->id]
        ));

        recording_manager::delete_all_media_for_instance((int) $this->instance->id);

        $this->assertSame(0, (int) $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'mod_presenterai' AND filename <> '.'",
            ['ctx' => $this->context->id]
        ), 'Media survived the deletion of its activity.');
    }

    /**
     * The timeline cleaner, case by case.
     *
     * @return void
     */
    public function test_normalise_timeline(): void {
        $this->assertSame([], recording_manager::normalise_timeline('not json'));
        $this->assertSame([], recording_manager::normalise_timeline(''));
        $this->assertSame(
            [['t' => 1, 'i' => 2]],
            recording_manager::normalise_timeline([['t' => 1, 'i' => 9]], 3),
            'An index past the last slide must clamp to the last slide when the count is known.'
        );
        $many = [];
        for ($i = 0; $i < 600; $i++) {
            $many[] = ['t' => 600 - $i, 'i' => 0];
        }
        $out = recording_manager::normalise_timeline($many);
        $this->assertCount(recording_manager::MAX_TIMELINE_EVENTS, $out);
        $this->assertSame(1, $out[0]['t'], 'The timeline must be sorted by time before it is cut.');
    }

    /**
     * The download goes to the URL the store signed, byte for byte, under a readable name.
     *
     * download.php sends this URL as a raw Location header. Anything that
     * re-encodes the query on the way breaks the SigV4 signature, so the test
     * pins that the value is exactly what s3_store::read_url() produced.
     *
     * @return void
     */
    public function test_download_target(): void {
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3bucket', 'presenterai-test', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $s3rec = $gen->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->user->id,
            'backend' => 's3',
            'attemptnumber' => 3,
            'storagekey' => 'presenterai/' . $this->course->id . '/' . $this->user->id . '/abc.webm',
        ]);

        $name = recording_manager::download_name($s3rec);
        $this->assertSame(
            'presentation-' . userdate((int) $s3rec->timecreated, '%Y-%m-%d', 99, false) . '-attempt3.webm',
            $name
        );

        // Signing reads the clock, so retry until both URLs fall in the same second.
        for ($try = 0; $try < 3; $try++) {
            $target = recording_manager::download_target($s3rec);
            $expected = (new \mod_presenterai\local\storage\s3_store())->read_url(
                (string) $s3rec->storagekey,
                recording_manager::DOWNLOAD_TTL,
                $name
            );
            if ($target['url'] === $expected) {
                break;
            }
        }
        $this->assertSame('s3', $target['kind']);
        $this->assertSame($expected, $target['url'], 'The download URL is not the one the store signed.');
        $this->assertStringContainsString('response-content-disposition', $target['url']);

        $rec = $this->begin();
        $this->send($rec, 'recording', 'abc');
        $done = $this->finalize($rec);
        $fstarget = recording_manager::download_target($done);
        $this->assertSame('fs', $fstarget['kind']);
        $this->assertStringContainsString('forcedownload=1', $fstarget['url']);
        $this->assertStringContainsString('dl=presentation-', $fstarget['url']);
    }

    /**
     * The media ceiling honours the site and the course, and not the per-request PHP limits.
     *
     * @return void
     */
    public function test_max_media_bytes(): void {
        global $CFG;

        $CFG->maxbytes = 0;
        unset_config('maxmediabytes', 'mod_presenterai');
        $course = (object) ['maxbytes' => 0];
        $this->assertSame(157286400, recording_manager::max_media_bytes($course));

        set_config('maxmediabytes', 52428800, 'mod_presenterai');
        $this->assertSame(52428800, recording_manager::max_media_bytes($course));

        $course->maxbytes = 10485760;
        $this->assertSame(10485760, recording_manager::max_media_bytes($course));

        $CFG->maxbytes = 1048576;
        $this->assertSame(1048576, recording_manager::max_media_bytes($course));
    }
}
