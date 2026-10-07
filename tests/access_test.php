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
     * In separate groups a non-editing teacher reaches only the learners in their groups.
     *
     * @return void
     */
    public function test_separate_groups_limit_other_peoples_recordings(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $cm = get_coursemodule_from_id('presenterai', (int) $this->context->instanceid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        rebuild_course_cache((int) $this->course->id, true);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->owner->id]);

        // The teacher role holds downloadany and deleteanyrecording here only so
        // the group rule is what is being tested, not the role matrix.
        $teacherrole = $this->get_role_id('teacher');
        assign_capability('mod/presenterai:downloadany', CAP_ALLOW, $teacherrole, $this->context->id, true);
        assign_capability('mod/presenterai:deleteanyrecording', CAP_ALLOW, $teacherrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $teacherid = (int) $this->teacher->id;
        $this->assertFalse(
            access::may_view($this->rec, $this->context, $teacherid),
            'A teacher in group A watched a group B learner\'s recording in separate groups.'
        );
        $this->assertFalse(access::may_download($this->rec, $this->context, $teacherid));
        $this->assertFalse(access::may_delete($this->rec, $this->context, $teacherid));

        // Sharing a group restores access, and the owner is never affected.
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->teacher->id]);
        $this->assertTrue(access::may_view($this->rec, $this->context, $teacherid));
        $this->assertTrue(access::may_download($this->rec, $this->context, $teacherid));
        $this->assertTrue(access::may_delete($this->rec, $this->context, $teacherid));
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->owner->id));

        // A manager holds accessallgroups and is in no group at all.
        $this->assertTrue(access::may_view($this->rec, $this->context, (int) $this->manager->id));
    }

    /**
     * A teacher may grade a finished attempt; a learner may not grade anything.
     *
     * @return void
     */
    public function test_may_grade_teacher_and_not_learner(): void {
        $this->assertTrue(access::may_grade($this->rec, $this->context, (int) $this->teacher->id));
        $this->assertTrue(access::may_grade($this->rec, $this->context, (int) $this->manager->id));
        $this->assertFalse(access::may_grade($this->rec, $this->context, (int) $this->owner->id));
        $this->assertFalse(access::may_grade($this->rec, $this->context, (int) $this->peer->id));
    }

    /**
     * An attempt that never finished uploading has nothing to grade.
     *
     * @return void
     */
    public function test_may_grade_refuses_unfinished_attempts(): void {
        foreach (['uploading', 'abandoned'] as $status) {
            $rec = clone $this->rec;
            $rec->status = $status;
            $this->assertFalse(access::may_grade($rec, $this->context, (int) $this->teacher->id), $status);
        }
        foreach (['uploaded', 'scoring', 'scored', 'failed'] as $status) {
            $rec = clone $this->rec;
            $rec->status = $status;
            $this->assertTrue(access::may_grade($rec, $this->context, (int) $this->teacher->id), $status);
        }

        // Media gone is still gradable: the attempt still counts (D8).
        $gone = clone $this->rec;
        $gone->storagekey = null;
        $this->assertTrue(access::may_grade($gone, $this->context, (int) $this->teacher->id));
    }

    /**
     * In separate groups a non-editing teacher grades only learners who share a group.
     *
     * @return void
     */
    public function test_may_grade_respects_separate_groups(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $cm = get_coursemodule_from_id('presenterai', (int) $this->context->instanceid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        rebuild_course_cache((int) $this->course->id, true);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->owner->id]);

        $this->assertFalse(
            access::may_grade($this->rec, $this->context, (int) $this->teacher->id),
            'A teacher in group A could grade a group B learner in separate groups.'
        );
        $this->assertTrue(access::may_grade($this->rec, $this->context, (int) $this->manager->id));

        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->teacher->id]);
        $this->assertTrue(access::may_grade($this->rec, $this->context, (int) $this->teacher->id));
    }

    /**
     * A recording id from another activity is refused with the not found error (IDOR).
     *
     * @return void
     */
    public function test_require_gradable_recording_rejects_other_instance(): void {
        $generator = $this->getDataGenerator();
        $cm = get_coursemodule_from_id('presenterai', (int) $this->context->instanceid, 0, false, MUST_EXIST);

        $this->assertSame(
            (int) $this->rec->id,
            (int) access::require_gradable_recording($cm, $this->context, (int) $this->rec->id, (int) $this->teacher->id)->id
        );

        // The grading page passes the cm_info that get_course_and_cm_from_cmid() returns.
        [, $cminfo] = get_course_and_cm_from_cmid((int) $this->context->instanceid, 'presenterai');
        $this->assertSame(
            (int) $this->rec->id,
            (int) access::require_gradable_recording($cminfo, $this->context, (int) $this->rec->id, (int) $this->teacher->id)->id
        );

        $other = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $foreign = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $other->id,
            'userid' => $this->owner->id,
            'storagekey' => 'other.webm',
        ]);

        try {
            access::require_gradable_recording($cm, $this->context, (int) $foreign->id, (int) $this->teacher->id);
            $this->fail('A recording from another activity was accepted through this one.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:recordingnotfound', $e->errorcode);
        }
    }

    /**
     * A forbidden recording and a missing one raise the same error.
     *
     * @return void
     */
    public function test_require_gradable_recording_same_error_for_forbidden_and_missing(): void {
        $cm = get_coursemodule_from_id('presenterai', (int) $this->context->instanceid, 0, false, MUST_EXIST);

        $cases = [
            [(int) $this->rec->id, (int) $this->peer->id],
            [(int) $this->rec->id + 1000, (int) $this->teacher->id],
        ];
        foreach ($cases as $case) {
            try {
                access::require_gradable_recording($cm, $this->context, $case[0], $case[1]);
                $this->fail('Expected error:recordingnotfound');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:recordingnotfound', $e->errorcode);
            }
        }
    }

    /**
     * Visual evidence follows its own capability, which learners don't hold.
     *
     * @return void
     */
    public function test_may_view_visual_evidence(): void {
        $this->assertTrue(access::may_view_visual_evidence($this->context, (int) $this->teacher->id));
        $this->assertTrue(access::may_view_visual_evidence($this->context, (int) $this->manager->id));
        $this->assertFalse(access::may_view_visual_evidence($this->context, (int) $this->owner->id));
    }

    /**
     * The report lists learners who may submit, narrowed to a group when one is chosen.
     *
     * @return void
     */
    public function test_visible_learner_ids(): void {
        $generator = $this->getDataGenerator();
        $cm = get_coursemodule_from_id('presenterai', (int) $this->context->instanceid, 0, false, MUST_EXIST);

        $ids = access::visible_learner_ids($cm, $this->context, 0);
        sort($ids);
        $expected = [(int) $this->owner->id, (int) $this->peer->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'Only learners who may submit, and not staff.');

        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $this->peer->id]);
        $this->assertSame([(int) $this->peer->id], access::visible_learner_ids($cm, $this->context, (int) $group->id));
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
