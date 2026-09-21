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

use mod_presenterai\local\storage\fs_store;
use mod_presenterai\local\storage\media_ref;

/**
 * fs_store against a real course, a real module context and a real file area.
 *
 * Nothing here is mocked, because every defect this class can have lives in the
 * seam between the plugin and the File API: a file written to the wrong area, a
 * key that no row points at, a staging file that is never promoted. A test that
 * substituted the File API would pass through all of them.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\storage\fs_store
 */
final class fs_store_test extends \advanced_testcase {
    /** @var \stdClass The course holding the activity under test. */
    private \stdClass $course;

    /** @var \stdClass The learner who owns the media under test. */
    private \stdClass $user;

    /** @var \stdClass The PresenterAI activity instance, carrying its cmid. */
    private \stdClass $instance;

    /** @var \context_module The module context the media is filed under. */
    private \context_module $context;

    /** @var fs_store The store under test. */
    private fs_store $store;

    /**
     * Build one course, one learner and one activity for each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->store = new fs_store();
    }

    /**
     * A recording row for the learner, with no media attached to it yet.
     *
     * @return int The new recording id, which is also the File API itemid.
     */
    private function make_recording_row(): int {
        global $DB;

        return (int) $DB->insert_record('presenterai_recording', (object) [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->user->id,
            'attemptnumber' => 1,
            'mode' => 'video',
            'backend' => fs_store::NAME,
            'status' => 'uploading',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A readable stream carrying one chunk body, as accept_chunk() expects.
     *
     * @param string $bytes The chunk content.
     * @return resource An open stream positioned at the start.
     */
    private function stream_of(string $bytes) {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);

        return $handle;
    }

    /**
     * Put some media in the store the way an upload does, and point a row at it.
     *
     * The row update is not decoration. A key is a bare filename on this
     * backend, so fs_store finds a file only through the recording row that
     * names the key; media whose row has been cleared is unreachable by design.
     *
     * @param string $content The bytes to store.
     * @param string $kind One of the media_ref KIND_* constants.
     * @return array The key, the recording id and the media_ref, in that order.
     */
    private function store_media(string $content, string $kind = media_ref::KIND_RECORDING): array {
        global $DB;

        $ref = new media_ref(0, $this->context->id, $this->course->id, $this->user->id, $kind, 'webm');
        $begin = $this->store->begin_upload($ref);
        $this->store->accept_chunk($begin['uploadid'], 0, $this->stream_of($content));

        $recordingid = $this->make_recording_row();
        $stored = $ref->with_key($begin['key'])->with_recording($recordingid);
        $this->store->commit_upload($stored, $begin['uploadid']);

        $column = $kind === media_ref::KIND_DECK ? 'deckkey' : ($kind === media_ref::KIND_FRAMES ? 'frameskey' : 'storagekey');
        $DB->set_field('presenterai_recording', $column, $begin['key'], ['id' => $recordingid]);

        return [$begin['key'], $recordingid, $stored];
    }

    /**
     * A chunked upload commits exactly the chunks it was given, in order.
     *
     * The failure this guards against is not a missing file. It is a file of
     * the right length whose second and third megabytes are the wrong way
     * round, which plays as a recording that stops mid-sentence and restarts,
     * and which nothing downstream can detect.
     *
     * @return void
     */
    public function test_a_chunked_upload_commits_the_concatenated_chunks(): void {
        $first = str_repeat('first-chunk-', 300);
        $second = str_repeat('second-chunk-', 200);

        $ref = new media_ref(0, $this->context->id, $this->course->id, $this->user->id, media_ref::KIND_RECORDING, 'webm');
        $begin = $this->store->begin_upload($ref);

        $this->assertNotEmpty(
            $begin['key'],
            'Without a key there is nothing to write on the recording row, so the upload cannot be found again.',
        );
        $this->assertNotEmpty($begin['uploadid'], 'Without an upload id the browser has nothing to send its chunks against.');
        $this->assertGreaterThanOrEqual(
            65536,
            $begin['chunkbytes'],
            'A chunk size below 64 KiB turns a seven minute video into thousands of requests, which is slower than '
                . 'the upload the chunking exists to rescue.'
        );

        $this->assertSame(
            0,
            $this->store->resume_offset($begin['uploadid']),
            'A fresh upload that reports a non-zero offset makes the browser skip the start of the recording, so the '
                . 'learner first words are missing from the file and from the transcript.'
        );

        $afterfirst = $this->store->accept_chunk($begin['uploadid'], 0, $this->stream_of($first));
        $this->assertSame(
            strlen($first),
            $afterfirst,
            'accept_chunk() must report the true length of the staging file. A wrong number is what the browser '
                . 'resumes from, so the next chunk lands at the wrong offset and corrupts the recording.'
        );
        $this->assertSame(
            strlen($first),
            $this->store->resume_offset($begin['uploadid']),
            'An upload that resumes from the wrong offset either duplicates or skips bytes, and the learner finds out '
                . 'only when playback breaks.'
        );

        $aftersecond = $this->store->accept_chunk($begin['uploadid'], strlen($first), $this->stream_of($second));
        $this->assertSame(strlen($first . $second), $aftersecond, 'The staging file must grow by exactly the chunk it was handed.');

        $recordingid = $this->make_recording_row();
        $committed = $this->store->commit_upload($ref->with_key($begin['key'])->with_recording($recordingid), $begin['uploadid']);

        $this->assertSame(
            strlen($first . $second),
            $committed,
            'commit_upload() is the only proof the browser sent what it said it sent. A size that does not match the '
                . 'chunks means a truncated recording was accepted as complete and will be transcribed and graded.'
        );

        global $DB;
        $DB->set_field('presenterai_recording', 'storagekey', $begin['key'], ['id' => $recordingid]);

        $this->assertSame(
            $first . $second,
            $this->store->read_bytes($begin['key'], 10 * 1024 * 1024),
            'The stored bytes are not the chunks in the order they were sent. The recording plays back scrambled and '
                . 'the transcript is built from audio nobody spoke.'
        );
    }

    /**
     * A chunk that claims the wrong offset is refused and tells the client where to resume.
     *
     * @return void
     */
    public function test_a_chunk_at_the_wrong_offset_is_refused(): void {
        $ref = new media_ref(0, $this->context->id, $this->course->id, $this->user->id, media_ref::KIND_RECORDING, 'webm');
        $begin = $this->store->begin_upload($ref);
        $this->store->accept_chunk($begin['uploadid'], 0, $this->stream_of('0123456789'));

        try {
            $this->store->accept_chunk($begin['uploadid'], 4, $this->stream_of('abcdefg'));
            $this->fail(
                'A chunk claiming an offset the staging file is not at was accepted. Appending it regardless produces '
                    . 'a file of plausible length and wrong content, which no later check can catch.'
            );
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'error:chunkoffset',
                $e->errorcode,
                'The client needs the true offset back so it can resume rather than restart.',
            );
        }
    }

    /**
     * An upload that never received a byte commits as nothing, not as an empty recording.
     *
     * @return void
     */
    public function test_an_upload_with_no_bytes_commits_as_null(): void {
        $ref = new media_ref(0, $this->context->id, $this->course->id, $this->user->id, media_ref::KIND_RECORDING, 'webm');
        $begin = $this->store->begin_upload($ref);
        $recordingid = $this->make_recording_row();

        $this->assertNull(
            $this->store->commit_upload($ref->with_key($begin['key'])->with_recording($recordingid), $begin['uploadid']),
            'A failed upload that commits as a zero byte recording is indistinguishable from a silent one, so the '
                . 'learner is told their attempt succeeded and is then graded on nothing.'
        );
    }

    /**
     * Stored media reports its size, reads back byte for byte, and lands on disk.
     *
     * @return void
     */
    public function test_stored_media_is_readable_through_every_route(): void {
        $content = str_repeat('presenterai-media-', 500);
        [$key] = $this->store_media($content);

        $this->assertSame(
            strlen($content),
            $this->store->size($key),
            'size() is what the retention report and the upload confirmation quote to the learner, so a wrong number '
                . 'is a wrong claim about whether their work arrived.'
        );

        $this->assertSame(
            $content,
            $this->store->read_bytes($key, strlen($content)),
            'A ceiling equal to the object size must admit the object. Refusing it would make the largest allowed '
                . 'recording the one that cannot be scored.'
        );

        $path = $this->store->fetch_to_file($key, 'webm');
        $this->assertNotNull(
            $path,
            'Transcription and the deck renderer need a real file. A null here means an attempt can never be scored.',
        );
        $this->assertFileExists(
            $path,
            'fetch_to_file() returned a path that is not there, so the transcriber reads nothing '
                . 'and reports a silent learner.'
        );
        $this->assertSame(
            $content,
            file_get_contents($path),
            'The file on disk is not the media that was stored, so the transcript belongs to some '
                . 'other recording.'
        );
    }

    /**
     * read_bytes() refuses an object larger than the ceiling it was given.
     *
     * The ceiling is checked before the read, not after. Reading first and
     * measuring afterwards is how learner supplied media exhausts the memory
     * limit in cron, and a memory_limit breach is a fatal that no catch block
     * intercepts, so the whole cron run dies rather than that one attempt.
     *
     * @return void
     */
    public function test_read_bytes_refuses_an_object_over_the_ceiling(): void {
        $content = str_repeat('x', 4096);
        [$key] = $this->store_media($content);

        $this->assertNull(
            $this->store->read_bytes($key, strlen($content) - 1),
            'An object larger than the ceiling was read into memory anyway. A learner who uploads a very large file '
                . 'can then kill the cron run that scores everybody else attempts.'
        );
        $this->assertNull(
            $this->store->read_bytes($key, 0),
            'A ceiling of zero must refuse everything. Treating it as unlimited turns a caller that forgot to set a '
                . 'limit into one with no limit at all.'
        );
    }

    /**
     * A playback URL points at pluginfile, and a download URL asks for a download.
     *
     * @return void
     */
    public function test_read_url_serves_playback_and_download_differently(): void {
        $content = str_repeat('y', 1024);
        [$key, $recordingid] = $this->store_media($content);

        $playback = $this->store->read_url($key, 300);
        $this->assertStringContainsString(
            'pluginfile.php',
            $playback,
            'Media on this backend is only reachable through pluginfile, which is where the access check runs.',
        );
        $this->assertStringContainsString(
            $key,
            $playback,
            'The URL must name the file it serves, or pluginfile has nothing to look up.',
        );
        $this->assertStringContainsString(
            '/' . $recordingid . '/',
            $playback,
            'The itemid in the URL is the recording id, and the pluginfile handler checks the file belongs to that '
                . 'row. A wrong itemid makes every playback a 404.'
        );

        $download = $this->store->read_url($key, 300, 'my presentation.webm');
        $this->assertStringContainsString(
            'forcedownload=1',
            $download,
            'Without forcedownload the browser plays the recording inline and the Download button appears to do nothing.',
        );
        $this->assertStringContainsString(
            'dl=',
            $download,
            'The saved filename travels as a parameter. Without it the learner saves a file named after a random 32 character key.',
        );
    }

    /**
     * Deleting media makes it unreachable through every route at once.
     *
     * @return void
     */
    public function test_delete_removes_the_media_and_is_safe_to_repeat(): void {
        $content = str_repeat('z', 2048);
        [$key] = $this->store_media($content);

        $this->assertTrue(
            $this->store->delete($key),
            'A delete an administrator asked for that reports failure stops the row being removed as well.'
        );
        $this->assertNull(
            $this->store->size($key),
            'The media survived a delete. Retention promises the learner their recording is gone on a date, and a '
                . 'file still readable after that date is the promise broken.'
        );
        $this->assertNull(
            $this->store->read_bytes($key, 1024 * 1024),
            'Deleted media that still reads back means the bytes were never removed.'
        );
        $this->assertSame(
            '',
            $this->store->read_url($key, 300),
            'A URL for media that is gone sends the learner to an error page rather than to the '
                . 'deletion notice.'
        );
        $this->assertTrue(
            $this->store->delete($key),
            'Deleting something already gone must succeed. A retention run that removed the bytes and then failed to '
                . 'clear the row would otherwise never get past its second pass.'
        );
    }

    /**
     * Ownership is decided by the row, so one learner cannot name another learner media.
     *
     * @return void
     */
    public function test_owns_accepts_the_owner_and_refuses_everybody_else(): void {
        $other = $this->getDataGenerator()->create_user();
        [$key] = $this->store_media(str_repeat('w', 512));

        $this->assertTrue(
            $this->store->owns($key, $this->course->id, $this->user->id, $this->context->id),
            'The owner was refused their own recording, which blocks playback and download for every learner.'
        );
        $this->assertFalse(
            $this->store->owns($key, $this->course->id, $other->id, $this->context->id),
            'A key was accepted for a learner who does not own it. Any learner could then read, download or delete '
                . 'another learner recording by naming its key.'
        );
        $this->assertFalse(
            $this->store->owns($key, $this->course->id, $this->user->id, \context_system::instance()->id),
            'A context other than the module the media lives in was accepted, so the capability check that guards '
                . 'this activity could be satisfied somewhere it does not apply.'
        );
    }

    /**
     * Committing twice returns the same size rather than failing the second time.
     *
     * @return void
     */
    public function test_commit_is_idempotent_for_a_retrying_client(): void {
        $content = str_repeat('r', 777);
        $ref = new media_ref(0, $this->context->id, $this->course->id, $this->user->id, media_ref::KIND_RECORDING, 'webm');
        $begin = $this->store->begin_upload($ref);
        $this->store->accept_chunk($begin['uploadid'], 0, $this->stream_of($content));

        $recordingid = $this->make_recording_row();
        $stored = $ref->with_key($begin['key'])->with_recording($recordingid);

        $first = $this->store->commit_upload($stored, $begin['uploadid']);
        $second = $this->store->commit_upload($stored, $begin['uploadid']);

        $this->assertSame(strlen($content), $first, 'The first commit must report the size that was uploaded.');
        $this->assertSame(
            $first,
            $second,
            'A client whose commit response timed out retries it. If the retry throws, the learner is told their '
                . 'upload failed for work that actually succeeded, and they record the whole presentation again.'
        );
    }
}
