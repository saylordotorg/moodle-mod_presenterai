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
     * A grade item hidden in the gradebook hides the score and feedback in the attempt list too.
     *
     * @return void
     */
    public function test_hidden_grade_item_hides_score(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student, $teacher] = $this->setup_activity(['grade' => 100]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $rec = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => 'a.webm', 'status' => 'scored']);
        $generator->create_score(['recordingid' => $rec->id, 'rawsum' => 7, 'rawmax' => 10, 'overallpct' => 70.0,
            'feedback' => 'Well paced.']);

        $row = $this->export($instance, $course, $context, $student)['attempts']['rows'][0];
        $this->assertTrue($row['hasscore']);
        $this->assertTrue($row['hasfeedback']);

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'presenterai', 'iteminstance' => $instance->id,
            'courseid' => $course->id]);
        $item->set_hidden(1);
        $row = $this->export($instance, $course, $context, $student)['attempts']['rows'][0];
        $this->assertFalse($row['hasscore']);
        $this->assertSame('', $row['score']);
        $this->assertFalse($row['hasfeedback']);
        $this->assertSame([], $row['feedback']['criteria']);

        // Hidden until a date that has passed is no longer hidden.
        $item->set_hidden(time() - 10);
        $row = $this->export($instance, $course, $context, $student)['attempts']['rows'][0];
        $this->assertTrue($row['hasscore']);
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

    /**
     * The disclosure, the opt out and their config keys render only where frames are taken (D17, D24).
     *
     * @return array
     */
    public static function visual_combinations(): array {
        return [
            'vision off' => ['video', 0, 0, false, false],
            'vision off, opt out set but meaningless' => ['video', 0, 1, false, false],
            'vision on, no opt out' => ['video', 1, 0, true, false],
            'vision on, opt out' => ['video', 1, 1, true, true],
            'audio, everything set' => ['audio', 1, 1, false, false],
        ];
    }

    /**
     * Each combination renders the disclosure and the opt out, or doesn't.
     *
     * @dataProvider visual_combinations
     * @param string $mode The activity mode.
     * @param int $videovision The videovision field.
     * @param int $allowoptout The allowvisualoptout field.
     * @param bool $disclosure Whether the disclosure must render.
     * @param bool $optout Whether the opt out must render.
     * @return void
     */
    public function test_visual_disclosure_and_optout(
        string $mode,
        int $videovision,
        int $allowoptout,
        bool $disclosure,
        bool $optout
    ): void {
        global $DB, $OUTPUT;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['mode' => $mode]);
        // Straight to the row, so normalise() can't tidy the combination away.
        $DB->update_record('presenterai', (object) [
            'id' => $instance->id, 'mode' => $mode, 'videovision' => $videovision, 'allowvisualoptout' => $allowoptout,
        ]);

        $data = $this->export($instance, $course, $context, $student);

        $this->assertSame($disclosure, $data['recorder']['visualdisclosure']);
        $this->assertSame($optout, $data['recorder']['visualoptout']);
        $this->assertSame($disclosure ? 1 : 0, $data['config']['videovision']);
        $this->assertSame($optout ? 1 : 0, $data['config']['allowvisualoptout']);

        $html = $OUTPUT->render_from_template('mod_presenterai/view', $data);
        $note = get_string('visualoptout_note', 'mod_presenterai');
        $hasnote = str_contains($html, $note) || str_contains($html, s($note));
        $this->assertSame($optout, $hasnote, 'The opt out note rendered in the wrong case.');
        $this->assertSame($optout, str_contains($html, 'data-region="visualoptout"'));
        $disclosuretext = get_string('visual_disclosure', 'mod_presenterai');
        $this->assertSame(
            $disclosure,
            str_contains($html, $disclosuretext) || str_contains($html, s($disclosuretext)),
            'The still frames disclosure rendered in the wrong case.'
        );
        $this->assertStringContainsString('data-videovision="' . ($disclosure ? 1 : 0) . '"', $html);
    }

    /**
     * The speech to text warm up flag comes from the site setting.
     *
     * @return void
     */
    public function test_warmstt_config(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();

        $this->assertSame(0, $this->export($instance, $course, $context, $student)['config']['warmstt']);
        set_config('sttwarm', 1, 'mod_presenterai');
        $this->assertSame(1, $this->export($instance, $course, $context, $student)['config']['warmstt']);
    }

    /**
     * A camera activity asks the browser for a separate audio track; an audio only one doesn't record twice.
     *
     * @return void
     */
    public function test_audiotrack_config(): void {
        global $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['mode' => 'video']);
        $config = $this->export($instance, $course, $context, $student)['config'];
        $this->assertSame(1, $config['audiotrack']);
        $this->assertSame(\mod_presenterai\local\transcription_source::AUDIO_TRACK_KBPS, $config['audiotrackkbps']);
        $html = $PAGE->get_renderer('core')->render_from_template(
            'mod_presenterai/view',
            $this->export($instance, $course, $context, $student)
        );
        $this->assertStringContainsString('data-audiotrack="1"', $html);

        [$course, $instance, $context, $student] = $this->setup_activity(['mode' => 'audio']);
        $this->assertSame(0, $this->export($instance, $course, $context, $student)['config']['audiotrack']);
    }
}
