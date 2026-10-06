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
use mod_presenterai\local\upload_handler;

/**
 * The status codes the browser's uploader acts on.
 *
 * Each answer here is a branch in the uploader: 409 resyncs, 413 halves the
 * chunk, 503 waits and retries, 404 gives up. A wrong code is an upload that
 * stalls or loops after the learner has finished speaking.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\upload_handler
 */
final class upload_handler_test extends \advanced_testcase {
    /** @var \stdClass The course module row. */
    private \stdClass $cm;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $user;

    /** @var \stdClass The uploading recording row. */
    private \stdClass $rec;

    /** @var string The upload id start_upload issued. */
    private string $uploadid;

    /**
     * One learner with one recording upload started on Moodle file storage.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $course = get_course($generator->create_course()->id);
        $this->user = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('presenterai', ['course' => $course->id]);
        $this->cm = get_coursemodule_from_id('presenterai', $instance->cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($instance->cmid);

        $this->rec = recording_manager::begin($instance, $this->cm, $this->context, (int) $this->user->id)['recording'];
        $target = recording_manager::start_upload($this->rec, $instance, $course, $this->context, 'recording', 'webm', 100);
        $this->uploadid = $target['uploadid'];
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
     * Send one chunk as the learner.
     *
     * @param int $offset Claimed offset.
     * @param string $bytes Chunk body.
     * @param int|null $userid Override the sender.
     * @param string|null $uploadid Override the upload id.
     * @return array [code, payload]
     */
    private function chunk(int $offset, string $bytes, ?int $userid = null, ?string $uploadid = null): array {
        return upload_handler::chunk(
            $this->cm,
            $this->context,
            $userid ?? (int) $this->user->id,
            (int) $this->rec->id,
            $uploadid ?? $this->uploadid,
            $offset,
            $this->stream_of($bytes)
        );
    }

    /**
     * Chunks in order succeed and report the new length; resume reports it too.
     *
     * @return void
     */
    public function test_chunks_in_order_succeed(): void {
        $this->assertSame([200, ['offset' => 5]], $this->chunk(0, 'hello'));
        $this->assertSame([200, ['offset' => 11]], $this->chunk(5, ' world'));
        $this->assertSame(
            [200, ['offset' => 11]],
            upload_handler::offset($this->cm, $this->context, (int) $this->user->id, (int) $this->rec->id, $this->uploadid)
        );
    }

    /**
     * A chunk at the wrong offset gets 409 and the true offset to resume from.
     *
     * @return void
     */
    public function test_wrong_offset_is_409_with_the_true_offset(): void {
        $this->chunk(0, 'hello');
        $this->assertSame(
            [409, ['error' => 'offset', 'offset' => 5]],
            $this->chunk(2, 'xyz'),
            'Without the true offset the uploader cannot resync and restarts the whole recording.'
        );
    }

    /**
     * Anything that is not this user's unfinished File API upload is 404.
     *
     * @return void
     */
    public function test_everything_else_is_404(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_user();
        $this->assertSame(
            [404, ['error' => 'notfound']],
            $this->chunk(0, 'x', (int) $other->id),
            'Another user wrote into the upload.'
        );
        $this->assertSame(
            [404, ['error' => 'notfound']],
            $this->chunk(0, 'x', null, str_repeat('a', 32)),
            'An upload id that is not the row\'s was accepted.'
        );

        $DB->set_field('presenterai_recording', 'backend', 's3', ['id' => $this->rec->id]);
        $this->assertSame([404, ['error' => 'notfound']], $this->chunk(0, 'x'), 'An S3 attempt was fed chunks.');

        $DB->set_field('presenterai_recording', 'backend', 'fs', ['id' => $this->rec->id]);
        $DB->set_field('presenterai_recording', 'status', 'uploaded', ['id' => $this->rec->id]);
        $this->assertSame([404, ['error' => 'notfound']], $this->chunk(0, 'x'), 'A finished attempt accepted more bytes.');
    }

    /**
     * Past the ceiling the answer is 413 with a sentence the learner can read.
     *
     * @return void
     */
    public function test_over_the_ceiling_is_413(): void {
        set_config('maxmediabytes', 10, 'mod_presenterai');
        [$code, $payload] = $this->chunk(0, str_repeat('x', 20));

        $this->assertSame(413, $code);
        $this->assertSame('toolarge', $payload['error']);
        $this->assertNotEmpty($payload['message']);
    }

    /**
     * A malformed request is 400, not a crash.
     *
     * @return void
     */
    public function test_negative_offset_is_400(): void {
        $this->assertSame([400, ['error' => 'badrequest']], $this->chunk(-1, 'x'));
    }
}
