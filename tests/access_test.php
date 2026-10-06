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

use mod_presenterai\local\access;

/**
 * Who may watch, download and delete, against real roles in a real course.
 *
 * The matrix is design 7.3 and 7.5. The cases that matter most are the ones
 * where two things that sound alike are not: a teacher who may watch every
 * recording may not take one away, and the learner download switch does not
 * bind a manager.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\access
 */
final class access_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass The learner who made the recording. */
    private \stdClass $owner;

    /** @var \stdClass Another learner. */
    private \stdClass $peer;

    /** @var \stdClass A non-editing teacher, who holds viewallattempts. */
    private \stdClass $teacher;

    /** @var \stdClass A manager, who holds downloadany and deleteanyrecording. */
    private \stdClass $manager;

    /** @var \stdClass The recording under test, with media. */
    private \stdClass $rec;

    /**
     * One course, one activity, four people and one finished recording.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $this->context = \context_module::instance($instance->cmid);
        $this->owner = $generator->create_and_enrol($this->course, 'student');
        $this->peer = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->manager = $generator->create_and_enrol($this->course, 'manager');

        $this->rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $this->owner->id,
            'storagekey' => 'abc.webm',
        ]);
    }

    /**
     * The site switch reads a never saved setting as the shipped default, on.
     *
     * @return void
     */
    public function test_site_switch_defaults_on(): void {
        unset_config('allowlearnerdownload', 'mod_presenterai');
        $this->assertTrue(access::site_allows_learner_download(), 'Never saved means the shipped default, which is on.');

        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        $this->assertFalse(access::site_allows_learner_download());

        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        $this->assertTrue(access::site_allows_learner_download());
    }

    /**
     * The owner downloads when the site allows it and they hold downloadown.
     *
     * @return void
     */
    public function test_owner_download_follows_the_switch_and_the_capability(): void {
        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        $this->assertTrue(access::may_download($this->rec, $this->context, (int) $this->owner->id));
        $this->assertTrue(access::may_download_own_prospectively($this->context, (int) $this->owner->id));

        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        $this->assertFalse(
            access::may_download($this->rec, $this->context, (int) $this->owner->id),
            'The site switched learner download off and the owner can still take a copy.'
        );
        $this->assertFalse(access::may_download_own_prospectively($this->context, (int) $this->owner->id));

        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        $studentrole = $this->get_role_id('student');
        assign_capability('mod/presenterai:downloadown', CAP_PROHIBIT, $studentrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(
            access::may_download($this->rec, $this->context, (int) $this->owner->id),
            'A role override removing downloadown is how a course says no; it must be honoured.'
        );
    }

    /**
     * Viewing every recording is not the right to take one away.
     *
     * @return void
     */
    public function test_viewallattempts_does_not_imply_download(): void {
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->teacher->id));
        $this->assertFalse(
            access::may_download($this->rec, $this->context, (int) $this->teacher->id),
            'A teacher who may watch a recording was allowed to download it. D22: downloadany is not implied.'
        );
        $this->assertFalse(access::may_download($this->rec, $this->context, (int) $this->peer->id));
    }

    /**
     * downloadany is not bound by the learner switch.
     *
     * @return void
     */
    public function test_manager_downloads_even_with_the_learner_switch_off(): void {
        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        $this->assertTrue(
            access::may_download($this->rec, $this->context, (int) $this->manager->id),
            'The learner switch blocked a grader. One setting cannot mean both things (design 7.3).'
        );
    }

    /**
     * Nobody downloads or deletes media that has gone, or that has not finished arriving.
     *
     * @return void
     */
    public function test_no_media_means_no_download_and_no_delete(): void {
        $gone = clone $this->rec;
        $gone->storagekey = null;
        $uploading = clone $this->rec;
        $uploading->status = 'uploading';

        foreach ([$gone, $uploading] as $rec) {
            $this->assertFalse(access::may_download($rec, $this->context, (int) $this->owner->id));
            $this->assertFalse(access::may_download($rec, $this->context, (int) $this->manager->id));
            $this->assertFalse(access::may_delete($rec, $this->context, (int) $this->owner->id));
            $this->assertFalse(access::may_delete($rec, $this->context, (int) $this->manager->id));
        }
        $this->assertTrue(
            access::may_view($gone, $this->context, (int) $this->owner->id),
            'A learner whose media was deleted must still open the attempt, to read why and to read the feedback.'
        );
    }

    /**
     * Owners view their own, staff view everyone's, peers view nothing.
     *
     * @return void
     */
    public function test_may_view(): void {
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->owner->id));
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->teacher->id));
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->manager->id));
        $this->assertFalse(
            access::may_view($this->rec, $this->context, (int) $this->peer->id),
            'One learner can watch another learner\'s recording.'
        );
    }

    /**
     * Owners delete their own media, managers anyone's, teachers and peers nobody's.
     *
     * @return void
     */
    public function test_may_delete(): void {
        $this->assertTrue(access::may_delete($this->rec, $this->context, (int) $this->owner->id));
        $this->assertTrue(access::may_delete($this->rec, $this->context, (int) $this->manager->id));
        $this->assertFalse(access::may_delete($this->rec, $this->context, (int) $this->teacher->id));
        $this->assertFalse(
            access::may_delete($this->rec, $this->context, (int) $this->peer->id),
            'One learner can delete another learner\'s recording.'
        );

        $studentrole = $this->get_role_id('student');
        assign_capability('mod/presenterai:deleteownmedia', CAP_PROHIBIT, $studentrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(
            access::may_delete($this->rec, $this->context, (int) $this->owner->id),
            'Withdrawing deleteownmedia is how a site answers open question 9.21 the other way; it must hold.'
        );
    }

    /**
     * The id of an archetype role.
     *
     * @param string $shortname Role shortname.
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
