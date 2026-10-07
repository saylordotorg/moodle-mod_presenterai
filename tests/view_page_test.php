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

use mod_presenterai\output\view_page;

/**
 * The learner's page: what renders for whom, and what survives a blocked recorder.
 *
 * The property most worth holding is the one Soapbox lost: the policy callout
 * and the attempt list render whether or not the recorder does. A learner at
 * the attempt cap, or on a site whose storage is unconfigured, is still told
 * the policy and still sees their attempts.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\view_page
 * @covers     \mod_presenterai\event\course_module_viewed
 */
final class view_page_test extends \advanced_testcase {
    /**
     * Skip a test that needs the server and config slices until they are merged.
     *
     * @return void
     */
    private function require_other_slices(): void {
        if (!class_exists('\\mod_presenterai\\local\\retention') || !class_exists('\\mod_presenterai\\local\\topic_manager')) {
            $this->markTestSkipped('needs the server and config slices');
        }
    }

    /**
     * A course, an instance, a student and a non-editing teacher.
     *
     * @param array $fields Instance overrides.
     * @return array [course, instance, context, student, teacher]
     */
    private function setup_activity(array $fields = []): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $instance = $this->getDataGenerator()->create_module('presenterai', array_merge(['course' => $course->id], $fields));
        $context = \context_module::instance($instance->cmid);

        return [$course, $instance, $context, $student, $teacher];
    }

    /**
     * Export the page for a user.
     *
     * @param \stdClass $instance The instance.
     * @param \stdClass $course The course.
     * @param \context_module $context The context.
     * @param \stdClass $user The user.
     * @return array
     */
    private function export(\stdClass $instance, \stdClass $course, \context_module $context, \stdClass $user): array {
        global $DB, $PAGE;

        $this->setUser($user);
        $instance = $DB->get_record('presenterai', ['id' => $instance->id], '*', MUST_EXIST);
        $page = new view_page($instance, $course, $context, (int) $user->id);

        return $page->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A learner gets the callout, the privacy paragraph and the recorder.
     *
     * @return void
     */
    public function test_learner_sees_recorder_and_callout(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['slidesenabled' => 1]);

        $data = $this->export($instance, $course, $context, $student);

        $this->assertNotEmpty($data['callout']['heading']);
        $this->assertNotEmpty($data['callout']['body']);
        $this->assertNotSame('', $data['privacy']);
        $this->assertTrue($data['canrecord']);
        $this->assertSame('', $data['blocked']);
        $this->assertNotNull($data['recorder']);
        $this->assertTrue($data['recorder']['slides']);
        $this->assertSame(1, $data['config']['slides']);
        $this->assertSame((int) $instance->cmid, $data['cmid']);
        foreach (['mode', 'minseconds', 'maxseconds', 'width', 'height', 'videokbps', 'audiokbps', 'maxbytes'] as $key) {
            $this->assertArrayHasKey($key, $data['config']);
        }
        $this->assertFalse($data['attempts']['hasattempts']);
    }

    /**
     * At the cap the recorder is replaced by a reason, and the callout and attempts stay.
     *
     * @return void
     */
    public function test_learner_at_cap(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['maxattempts' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id, 'storagekey' => 'a.webm']);

        $data = $this->export($instance, $course, $context, $student);

        $this->assertSame(get_string('recording_blocked_cap', 'mod_presenterai', 1), $data['blocked']);
        $this->assertNull($data['recorder']);
        $this->assertNull($data['config']);
        $this->assertNotEmpty($data['callout']['heading']);
        $this->assertTrue($data['attempts']['hasattempts']);
        $this->assertCount(1, $data['attempts']['rows']);
    }

    /**
     * S3 selected and unconfigured: no recorder, but the callout and attempts stay.
     *
     * @return void
     */
    public function test_storage_unconfigured(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id, 'storagekey' => 'a.webm']);
        set_config('backend', 's3', 'mod_presenterai');

        $data = $this->export($instance, $course, $context, $student);

        $this->assertSame(get_string('nostorage', 'mod_presenterai'), $data['blocked']);
        $this->assertNull($data['recorder']);
        $this->assertNotEmpty($data['callout']['heading']);
        $this->assertTrue($data['attempts']['hasattempts']);
    }

    /**
     * A teacher without submit gets no recorder and no reason for its absence.
     *
     * @return void
     */
    public function test_teacher_without_submit(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, , $teacher] = $this->setup_activity();

        $data = $this->export($instance, $course, $context, $teacher);

        $this->assertFalse($data['canrecord']);
        $this->assertSame('', $data['blocked']);
        $this->assertNull($data['recorder']);
    }

    /**
     * Attempts are the user's own, newest first, without rows still uploading.
     *
     * Rows whose media is gone are kept (plan 7.4), and the download-off note
     * shows only while one of them still has media.
     *
     * @return void
     */
    public function test_attempt_list(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $now = time();

        $old = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => null, 'mediadeletedat' => $now - 10, 'mediagonereason' => 'retention',
            'timecreated' => $now - 3 * DAYSECS]);
        $new = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => 'b.webm', 'attemptnumber' => 2, 'timecreated' => $now - DAYSECS]);
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'status' => 'uploading', 'timecreated' => $now]);
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $other->id,
            'storagekey' => 'c.webm']);

        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        $data = $this->export($instance, $course, $context, $student);

        $rows = $data['attempts']['rows'];
        $this->assertSame([(int) $new->id, (int) $old->id], array_column($rows, 'recid'));
        $this->assertTrue($rows[0]['mediaavailable']);
        $this->assertFalse($rows[1]['mediaavailable']);
        $this->assertSame('attempt_deleted_on', $rows[1]['statekey']);
        $this->assertNotSame('', $rows[1]['gonenote']);
        $this->assertTrue($data['attempts']['showdownloadoffnote']);

        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        $data = $this->export($instance, $course, $context, $student);
        $this->assertFalse($data['attempts']['showdownloadoffnote']);
    }

    /**
     * The view event is the module's own subclass and can be created and triggered.
     *
     * Phase 0 created the abstract core class directly, which fatals on the
     * first page view. Needs no other slice.
     *
     * @return void
     */
    public function test_course_module_viewed_event(): void {
        $this->resetAfterTest();
        [$course, $instance, $context] = $this->setup_activity();

        $event = event\course_module_viewed::create([
            'objectid' => $instance->id,
            'context' => $context,
        ]);
        $event->add_record_snapshot('course', $course);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\course_module_viewed::class, $events[0]);
        $this->assertSame('presenterai', $events[0]->objecttable);
        $this->assertSame('r', $events[0]->crud);
        $this->assertEquals($context, $events[0]->get_context());
        $this->assertSame(
            ['db' => 'presenterai', 'restore' => 'presenterai'],
            event\course_module_viewed::get_objectid_mapping()
        );
    }

    /**
     * Staff get the link to the submissions report; learners don't.
     *
     * @return void
     */
    public function test_submissions_link_for_staff_only(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student, $teacher] = $this->setup_activity();

        $data = $this->export($instance, $course, $context, $teacher);
        $this->assertTrue($data['showsubmissions']);
        $this->assertStringContainsString('/mod/presenterai/report.php?id=' . $context->instanceid, $data['submissionsurl']);

        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $this->assertStringContainsString(get_string('viewsubmissions', 'mod_presenterai'), $html);

        $data = $this->export($instance, $course, $context, $student);
        $this->assertFalse($data['showsubmissions']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $this->assertStringNotContainsString(get_string('viewsubmissions', 'mod_presenterai'), $html);
    }
}
