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
use mod_presenterai\external\start_upload;

/**
 * The web service that hands the browser an upload target.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\start_upload
 */
final class external_start_upload_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \stdClass The learner who owns the attempt. */
    private \stdClass $alice;

    /** @var \stdClass Another learner. */
    private \stdClass $bob;

    /**
     * One course, one activity, two learners.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'slidesenabled', 1, ['id' => $this->instance->id]);
        $this->alice = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->bob = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * The owner gets a chunked target and no key.
     *
     * @return void
     */
    public function test_owner_gets_a_target(): void {
        global $DB;

        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $result = external_api::clean_returnvalue(
            start_upload::execute_returns(),
            start_upload::execute($begin['recordingid'], 'recording', 'webm', 2048, $begin['attempttoken'])
        );

        $this->assertSame('POST', $result['method']);
        $this->assertStringContainsString('/mod/presenterai/upload.php', $result['url']);
        $this->assertNotSame('', $result['uploadid']);
        $this->assertGreaterThan(0, $result['chunkbytes']);
        $this->assertArrayNotHasKey('key', $result);
        $row = $DB->get_record('presenterai_recording', ['id' => $begin['recordingid']], '*', MUST_EXIST);
        $this->assertStringNotContainsString((string) $row->storagekey, $result['url'], 'The stored key leaked into the target.');
    }

    /**
     * Another learner cannot upload into somebody else's attempt.
     *
     * @return void
     */
    public function test_another_learner_is_refused(): void {
        global $DB;

        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);

        $this->setUser($this->bob);
        try {
            start_upload::execute($begin['recordingid'], 'recording', 'webm', 2048, $begin['attempttoken']);
            $this->fail('A learner was handed an upload target for another learner\'s attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:recordingnotfound', $e->errorcode);
        }
        $this->assertEmpty(
            $DB->get_field('presenterai_recording', 'storagekey', ['id' => $begin['recordingid']]),
            'The refused request still minted a key on the victim\'s row.'
        );
    }

    /**
     * A finished attempt takes no more uploads.
     *
     * @return void
     */
    public function test_finished_attempt_is_refused(): void {
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'storagekey' => 'abc.webm',
        ]);
        $this->setUser($this->alice);

        try {
            start_upload::execute((int) $rec->id, 'recording', 'webm', 10, 'anytoken');
            $this->fail('A finished attempt accepted a second recording.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:notuploading', $e->errorcode);
        }
    }

    /**
     * Without the submit capability there is no upload.
     *
     * @return void
     */
    public function test_without_submit_is_refused(): void {
        global $DB;

        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);

        $context = \context_module::instance($this->instance->cmid);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/presenterai:submit', CAP_PROHIBIT, $studentrole, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        start_upload::execute($begin['recordingid'], 'recording', 'webm', 10, $begin['attempttoken']);
    }
}
