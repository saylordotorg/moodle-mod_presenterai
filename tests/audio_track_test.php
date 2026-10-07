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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_presenterai\local\media_purger;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\fs_store;
use mod_presenterai\local\storage\media_ref;
use mod_presenterai\privacy\provider;
use mod_presenterai\task\cleanup;

/**
 * The separate audio only track: its upload, and that it goes wherever the video goes.
 *
 * Bytes go through fs_store as upload.php sends them. Every path that deletes
 * or carries a recording's media is checked to delete or carry the track too:
 * a learner's delete, retention, pruning, an abandoned upload, a privacy
 * delete and export, and a purge. It's never served by pluginfile, so a
 * learner never gets a second copy of their recording through it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\recording_manager
 * @covers     \mod_presenterai\local\media_purger
 * @covers     \mod_presenterai\task\cleanup
 * @covers     \mod_presenterai\privacy\provider
 */
final class audio_track_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $user;

    /** @var \stdClass The camera activity, video vision and slides on. */
    private \stdClass $instance;

    /** @var \stdClass Its course module row. */
    private \stdClass $cm;

    /** @var \context_module Its context. */
    private \context_module $context;

    /**
     * One course, one learner, one camera activity that keeps forever.
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
        $fields = ['slidesenabled' => 1, 'videovision' => 1, 'maxattempts' => 0, 'storedattempts' => 0, 'retentiondays' => -1];
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
     * @param string $bytes The content.
     * @return resource
     */
    private function stream_of(string $bytes) {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);
        return $handle;
    }

    /**
     * Start an upload of one kind and send it in one chunk.
     *
     * @param \stdClass $rec The uploading row.
     * @param string $kind A media_ref kind.
     * @param string $content The bytes.
     * @return array The upload target.
     */
    private function send(\stdClass $rec, string $kind, string $content): array {
        $exts = [media_ref::KIND_DECK => 'pdf', media_ref::KIND_FRAMES => 'jpg', media_ref::KIND_AUDIO => 'ogg'];
        $target = recording_manager::start_upload(
            $rec,
            $this->instance,
            $this->course,
            $this->context,
            $kind,
            $exts[$kind] ?? 'webm',
            strlen($content)
        );
        (new fs_store())->accept_chunk($target['uploadid'], 0, $this->stream_of($content));
        return $target;
    }

    /**
     * The row as stored.
     *
     * @param int $id The recording id.
     * @return \stdClass
     */
    private function reload(int $id): \stdClass {
        global $DB;
        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Whether the audio area holds a file under this key for this attempt.
     *
     * @param int $recordingid The recording id.
     * @param string $key The key.
     * @return bool
     */
    private function audio_file_exists(int $recordingid, string $key): bool {
        return (bool) get_file_storage()->get_file($this->context->id, 'mod_presenterai', 'audio', $recordingid, '/', $key);
    }

    /**
     * A finished attempt with frames, an audio track and a recording, uploaded in the browser's order.
     *
     * @return \stdClass The finalized row.
     */
    private function finished_with_audio(): \stdClass {
        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $this->send($rec, media_ref::KIND_FRAMES, "\xFF\xD8\xFF frames");
        $this->send($rec, media_ref::KIND_AUDIO, 'OggS the audio track');
        $this->send($rec, media_ref::KIND_RECORDING, 'the video bytes');
        return recording_manager::finalize($rec, $this->instance, $this->course, $this->context, 0, 30, '');
    }

    /**
     * The track is committed when the recording starts, kept apart from it and doesn't change the attempt's size.
     *
     * @return void
     */
    public function test_track_is_committed_beside_the_recording(): void {
        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $this->send($rec, media_ref::KIND_AUDIO, 'OggS the audio track');
        $audiokey = (string) $this->reload((int) $rec->id)->audiokey;
        $this->assertNotSame('', $audiokey);
        $this->assertFalse($this->audio_file_exists((int) $rec->id, $audiokey), 'Committed before the recording started.');

        $this->send($rec, media_ref::KIND_RECORDING, 'the video bytes');
        $this->assertTrue(
            $this->audio_file_exists((int) $rec->id, $audiokey),
            'Starting the recording did not commit the track.'
        );

        $done = recording_manager::finalize($rec, $this->instance, $this->course, $this->context, 0, 30, '');
        $this->assertSame($audiokey, $done->audiokey);
        $this->assertSame(strlen('the video bytes'), (int) $done->sizebytes, 'The track was counted as the recording.');
        $this->assertSame('uploaded', $done->status);
    }

    /**
     * Order and eligibility: only camera recordings get a track, it comes before the recording,
     * and nothing earlier in the order can follow it.
     *
     * @return void
     */
    public function test_track_order_and_eligibility(): void {
        global $DB;

        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $this->send($rec, media_ref::KIND_AUDIO, 'OggS track');
        $earlier = [
            [media_ref::KIND_FRAMES, 'jpg', 'error:framesdisabled'],
            [media_ref::KIND_DECK, 'pdf', 'error:deckafterrecording'],
        ];
        foreach ($earlier as [$kind, $ext, $code]) {
            try {
                recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, $kind, $ext, 10);
                $this->fail($kind . ' was accepted after the audio track.');
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
        $this->send($rec, media_ref::KIND_RECORDING, 'video');
        try {
            recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'audio', 'ogg', 10);
            $this->fail('An audio track was accepted after the recording.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:audiotracknotwanted', $e->errorcode);
        }

        // An audio only activity isn't recorded twice.
        $DB->set_field('presenterai', 'mode', 'audio', ['id' => $this->instance->id]);
        $this->instance->mode = 'audio';
        $audio = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $DB->set_field('presenterai_recording', 'mode', 'audio', ['id' => $audio->id]);
        $audio->mode = 'audio';
        try {
            recording_manager::start_upload($audio, $this->instance, $this->course, $this->context, 'audio', 'ogg', 10);
            $this->fail('An audio only recording was given a second track.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:audiotracknotwanted', $e->errorcode);
        }
    }

    /**
     * The track is held to the recording's ceiling, declared and committed, and the wrong extension is refused.
     *
     * @return void
     */
    public function test_track_size_and_extension_checks(): void {
        global $DB;

        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        try {
            recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'audio', 'exe', 10);
            $this->fail('A track with a strange extension was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:badext', $e->errorcode);
        }

        $DB->set_field('course', 'maxbytes', 64, ['id' => $this->course->id]);
        $this->course = get_course($this->course->id);
        try {
            recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'audio', 'ogg', 65);
            $this->fail('A track over the course upload limit was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:uploadtoolarge', $e->errorcode);
        }

        // A track that arrives bigger than it said is dropped at commit, and the recording is unaffected.
        $this->send($rec, media_ref::KIND_AUDIO, 'small');
        $DB->set_field('course', 'maxbytes', 4, ['id' => $this->course->id]);
        $this->course = get_course($this->course->id);
        $this->assertNull(recording_manager::commit_pending_audio($this->reload((int) $rec->id), $this->context, $this->course));
        $this->assertEmpty($this->reload((int) $rec->id)->audiokey);
    }

    /**
     * A track whose upload stopped partway isn't committed as whole, and the recording goes on.
     *
     * @return void
     */
    public function test_truncated_track_is_dropped(): void {
        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        // Declared at 20 bytes, only 7 arrive, as when a chunk fails and the browser moves on.
        $target = recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'audio', 'ogg', 20);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $this->stream_of('OggS 12'));
        $this->assertSame(20, (int) $this->reload((int) $rec->id)->audiobytes);

        $this->send($rec, media_ref::KIND_RECORDING, 'the video bytes');
        $done = recording_manager::finalize($rec, $this->instance, $this->course, $this->context, 0, 30, '');
        $this->assertEmpty($done->audiokey, 'A truncated track was kept for transcription.');
        $this->assertSame('uploaded', $done->status);
        $this->assertEmpty(
            get_file_storage()->get_area_files($this->context->id, 'mod_presenterai', 'audio', $done->id, 'id', false)
        );
    }

    /**
     * A learner's delete, retention and pruning take the track with the video.
     *
     * @return void
     */
    public function test_drop_media_paths_delete_the_track(): void {
        global $DB;

        $learner = $this->finished_with_audio();
        $this->assertTrue(recording_manager::drop_media($learner, 'learner'));
        $this->assertNull($this->reload((int) $learner->id)->audiokey);
        $this->assertFalse($this->audio_file_exists((int) $learner->id, (string) $learner->audiokey));

        $expired = $this->finished_with_audio();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $expired->id,
            'timecreated' => time() - 10 * DAYSECS,
            'expiresat' => time() - 60,
        ]);
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertSame('retention', $this->reload((int) $expired->id)->mediagonereason);
        $this->assertNull($this->reload((int) $expired->id)->audiokey);
        $this->assertFalse($this->audio_file_exists((int) $expired->id, (string) $expired->audiokey));

        $older = $this->finished_with_audio();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $older->id,
            'timecreated' => time() - 3 * DAYSECS,
            'status' => 'scored',
        ]);
        $newer = $this->finished_with_audio();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $newer->id,
            'timecreated' => time() - 2 * DAYSECS,
            'status' => 'scored',
        ]);
        $DB->set_field('presenterai', 'storedattempts', 1, ['id' => $this->instance->id]);
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertSame('pruned', $this->reload((int) $older->id)->mediagonereason);
        $this->assertFalse($this->audio_file_exists((int) $older->id, (string) $older->audiokey));
        $this->assertTrue(
            $this->audio_file_exists((int) $newer->id, (string) $newer->audiokey),
            'The kept attempt lost its track.'
        );
    }

    /**
     * An upload abandoned with only its track sent loses the track.
     *
     * @return void
     */
    public function test_abandoned_upload_deletes_the_track(): void {
        global $DB;

        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $this->send($rec, media_ref::KIND_AUDIO, 'OggS track');
        $this->send($rec, media_ref::KIND_RECORDING, 'video');
        $key = (string) $this->reload((int) $rec->id)->audiokey;
        $this->assertTrue($this->audio_file_exists((int) $rec->id, $key));
        $DB->set_field('presenterai_recording', 'timecreated', time() - 2 * DAYSECS, ['id' => $rec->id]);

        ob_start();
        (new cleanup())->execute();
        ob_end_clean();

        $row = $this->reload((int) $rec->id);
        $this->assertSame('abandoned', $row->status);
        $this->assertNull($row->audiokey);
        $this->assertFalse($this->audio_file_exists((int) $rec->id, $key));
    }

    /**
     * A purge, a privacy delete and deleting the activity take the track; the export carries it.
     *
     * @return void
     */
    public function test_purge_privacy_and_instance_delete(): void {
        $exported = $this->finished_with_audio();
        $this->export_context_data_for_user((int) $this->user->id, $this->context, 'mod_presenterai');
        $path = [
            get_string('privacy:path:attempts', 'mod_presenterai'),
            'attempt-' . $exported->attemptnumber . '-' . $exported->id,
        ];
        $files = writer::with_context($this->context)->get_files($path);
        $this->assertArrayHasKey((string) $exported->audiokey, $files, 'A subject access request left the track out.');

        provider::delete_data_for_user(new approved_contextlist($this->user, 'mod_presenterai', [$this->context->id]));
        $this->assertFalse($this->audio_file_exists((int) $exported->id, (string) $exported->audiokey));

        $purged = $this->finished_with_audio();
        media_purger::purge_user((int) $this->instance->id, (int) $this->user->id, media_purger::AIUSAGE_DELETE);
        $this->assertFalse($this->audio_file_exists((int) $purged->id, (string) $purged->audiokey));

        $last = $this->finished_with_audio();
        recording_manager::delete_all_media_for_instance((int) $this->instance->id);
        $this->assertFalse($this->audio_file_exists((int) $last->id, (string) $last->audiokey));
    }

    /**
     * pluginfile never serves the track, not even to its owner, so no second download exists.
     *
     * @return void
     */
    public function test_pluginfile_never_serves_the_track(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/presenterai/lib.php');

        $done = $this->finished_with_audio();
        $this->setUser($this->user);
        foreach ([false, true] as $forcedownload) {
            $this->assertFalse(mod_presenterai_pluginfile(
                $this->course,
                $this->cm,
                $this->context,
                'audio',
                [(int) $done->id, (string) $done->audiokey],
                $forcedownload
            ));
        }
        // The download a learner is offered is the recording's.
        $this->assertStringEndsWith('.webm', recording_manager::download_name($done));
        $this->assertSame('fs', recording_manager::download_target($done)['kind']);
    }

    /**
     * The shared S3 key guard knows about the track, so a restored copy's track survives the original's deletion.
     *
     * @return void
     */
    public function test_shared_key_guard_covers_the_track(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $key = 'presenterai/' . $this->course->id . '/' . $this->user->id . '/audio/track.ogg';
        $one = $generator->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->user->id,
            'backend' => 's3',
            'storagekey' => 'a.webm',
            'audiokey' => $key,
        ]);
        $this->assertFalse(recording_manager::key_in_use_elsewhere('s3', $key, (int) $one->id));
        $two = $generator->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->user->id,
            'backend' => 's3',
            'storagekey' => 'b.webm',
            'audiokey' => $key,
        ]);
        $this->assertTrue(recording_manager::key_in_use_elsewhere('s3', $key, (int) $one->id));
        $this->assertTrue(recording_manager::key_in_use_outside('s3', $key, [(int) $one->id]));
        $this->assertFalse(recording_manager::key_in_use_outside('s3', $key, [(int) $one->id, (int) $two->id]));
    }

    /**
     * The storage abstraction knows the kind: its own area on the File API and its own folder on S3.
     *
     * @return void
     */
    public function test_storage_knows_the_kind(): void {
        $this->assertContains(media_ref::KIND_AUDIO, media_ref::KINDS);
        $this->assertContains('audio', media_purger::AREAS);
        $this->assertContains('audiokey', recording_manager::MEDIA_COLUMNS);
        $ref = new media_ref(1, (int) $this->context->id, (int) $this->course->id, (int) $this->user->id, 'audio', 'ogg');
        $this->assertTrue($ref->has_valid_kind());

        $done = $this->finished_with_audio();
        $store = new fs_store();
        $this->assertSame(strlen('OggS the audio track'), $store->size((string) $done->audiokey));
        $this->assertTrue(
            $store->owns((string) $done->audiokey, (int) $this->course->id, (int) $this->user->id, (int) $this->context->id)
        );
    }
}
