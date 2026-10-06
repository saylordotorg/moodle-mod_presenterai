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
use mod_presenterai\external\get_playback;
use mod_presenterai\external\start_upload;
use mod_presenterai\local\storage\fs_store;

/**
 * The web service the player asks for media, slides and timeline.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\get_playback
 */
final class external_get_playback_test extends \advanced_testcase {
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
     * @return int The recording id.
     */
    private function finished_recording(): int {
        $this->setUser($this->alice);
        $content = 'recorded-bytes';
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $target = start_upload::execute($begin['recordingid'], 'recording', 'webm', strlen($content), $begin['attempttoken']);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        finalize_recording::execute($begin['recordingid'], $begin['attempttoken']);

        return (int) $begin['recordingid'];
    }

    /**
     * The owner and a teacher both get a playable URL.
     *
     * @return void
     */
    public function test_owner_and_teacher_can_play(): void {
        $recordingid = $this->finished_recording();

        $mine = external_api::clean_returnvalue(get_playback::execute_returns(), get_playback::execute($recordingid));
        $this->assertTrue($mine['mediaavailable']);
        $this->assertStringContainsString('pluginfile.php', $mine['mediaurl']);
        $this->assertSame(0, $mine['ttl'], 'A pluginfile URL does not expire, so the player must not refresh it on a clock.');
        $this->assertSame('[]', $mine['timeline']);
        $this->assertSame('', $mine['mediagonereason']);

        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'teacher'));
        $theirs = get_playback::execute($recordingid);
        $this->assertTrue($theirs['mediaavailable']);
    }

    /**
     * Another learner cannot play somebody else's recording.
     *
     * @return void
     */
    public function test_another_learner_is_refused(): void {
        $recordingid = $this->finished_recording();
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $this->expectException(\required_capability_exception::class);
        get_playback::execute($recordingid);
    }

    /**
     * Media that has gone is a structured answer, never an exception.
     *
     * @return void
     */
    public function test_media_gone_is_an_answer(): void {
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'storagekey' => null,
            'mediadeletedat' => 1790000000,
            'mediagonereason' => 'retention',
        ]);
        $this->setUser($this->alice);

        $result = external_api::clean_returnvalue(get_playback::execute_returns(), get_playback::execute((int) $rec->id));
        $this->assertFalse($result['mediaavailable']);
        $this->assertSame('', $result['mediaurl']);
        $this->assertSame([], $result['pages']);
        $this->assertSame('[]', $result['timeline']);
        $this->assertSame(1790000000, $result['mediadeletedat']);
        $this->assertSame(
            'retention',
            $result['mediagonereason'],
            'Without the reason the player cannot tell an announced deletion from a broken link (design 8.5).'
        );
    }

    /**
     * An unfinished attempt has nothing to play.
     *
     * @return void
     */
    public function test_unfinished_attempt_is_not_found(): void {
        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);

        try {
            get_playback::execute($begin['recordingid']);
            $this->fail('An attempt still uploading was offered for playback.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:recordingnotfound', $e->errorcode);
        }
    }
}
