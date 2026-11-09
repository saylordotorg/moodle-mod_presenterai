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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/presenterai/lib.php');

/**
 * The learner's page: what renders for whom, and what survives a blocked recorder.
 *
 * The property most worth holding is the one SOLA's old speech tool lost: the retention line
 * and the attempt list render whether or not the recorder does. A learner at
 * the attempt cap, or on a site whose storage is unconfigured, is still told
 * the policy and still sees their attempts.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\view_page
 * @covers     \mod_presenterai\output\policy
 * @covers     ::mod_presenterai_output_fragment_latest
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
     * The sources step is there only when the activity gives sources.
     *
     * @return void
     */
    public function test_sources_step(): void {
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['sources' => "A (2020).\nB (2021).", 'minsources' => 2]);
        $data = $this->export($instance, $course, $context, $student);
        $this->assertTrue($data['sources']['hassources']);
        $this->assertCount(2, $data['sources']['items']);

        [$course, $instance, $context, $student] = $this->setup_activity();
        $this->assertFalse($this->export($instance, $course, $context, $student)['sources']['hassources']);
    }

    /**
     * A learner gets the retention line and the recorder.
     *
     * @return void
     */
    public function test_learner_sees_recorder_and_callout(): void {
        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['slidesenabled' => 1]);

        $data = $this->export($instance, $course, $context, $student);

        $this->assertNotSame('', $data['retentionline']);
        $this->assertArrayNotHasKey('callout', $data, 'The long callout is replaced by one line.');
        $this->assertArrayNotHasKey('privacy', $data);
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
        $this->assertNotSame('', $data['retentionline']);
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
        $this->assertNotSame('', $data['retentionline']);
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
     * The page says once, in one line, how long the recording is kept, using the activity's real retention, and
     * none of the long paragraphs it replaced.
     *
     * @return void
     */
    public function test_one_retention_line(): void {
        global $DB, $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['retentiondays' => 30]);
        set_config('allowlearnerdownload', 1, 'mod_presenterai');

        $data = $this->export($instance, $course, $context, $student);
        $expected = get_string('retentionline_delete', 'mod_presenterai', get_string('numdays', 'moodle', 30));
        $this->assertSame(s($expected), $data['retentionline']);
        $this->assertSame(
            'Your recording is private to you and your instructors and is deleted after 30 days '
                . '(your transcript and feedback are kept).',
            $expected
        );

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $this->assertSame(1, substr_count($html, 'data-region="retention"'));
        $this->assertSame(1, substr_count($html, 'is deleted after 30 days'), 'The retention period is said more than once.');
        foreach (['record_delete_dl_body', 'privacy_kept_after', 'privacy_delete'] as $key) {
            $this->assertStringNotContainsString(
                get_string($key, 'mod_presenterai', 30),
                $html,
                "The {$key} paragraph is still on the page."
            );
        }

        // The real value, not a fixed 30, and the kept wording with deletion off.
        $DB->set_field('presenterai', 'retentiondays', 7, ['id' => $instance->id]);
        $data = $this->export($instance, $course, $context, $student);
        $this->assertStringContainsString('deleted after 7 days', $data['retentionline']);
        $DB->set_field('presenterai', 'retentiondays', 0, ['id' => $instance->id]);
        $data = $this->export($instance, $course, $context, $student);
        $this->assertSame(s(get_string('retentionline_keep', 'mod_presenterai')), $data['retentionline']);

        // With downloading off the line says so, and the site's support contact follows.
        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        set_config('supportemail', 'help@example.com');
        $data = $this->export($instance, $course, $context, $student);
        $this->assertStringContainsString(s("can't be downloaded"), $data['retentionline']);
        $this->assertStringContainsString('mailto:help@example.com', $data['retentionline']);
    }

    /**
     * The steps: choose a topic only when there are topics, then record, then read your feedback; and every
     * attempt below, collapsed, with the count.
     *
     * @return void
     */
    public function test_steps_and_collapsed_attempts(): void {
        global $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();
        $renderer = $PAGE->get_renderer('core');

        $html = $renderer->render_from_template('mod_presenterai/view', $this->export($instance, $course, $context, $student));
        $this->assertStringNotContainsString('data-step="topic"', $html);
        $this->assertStringContainsString('data-step="record"', $html);
        $this->assertStringContainsString('data-step="feedback"', $html);
        $this->assertStringContainsString(get_string('card_none', 'mod_presenterai'), $html);
        $this->assertStringNotContainsString('data-region="allattempts"', $html, 'No attempts, nothing to expand.');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $generator->create_topic(['presenteraiid' => $instance->id, 'title' => 'Rivers']);
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id, 'storagekey' => 'a.webm']);
        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id, 'storagekey' => 'b.webm',
            'attemptnumber' => 2]);
        $data = $this->export($instance, $course, $context, $student);
        $html = $renderer->render_from_template('mod_presenterai/view', $data);

        $this->assertSame(2, $data['attempts']['count']);
        $this->assertLessThan(strpos($html, 'data-step="record"'), strpos($html, 'data-step="topic"'));
        $this->assertLessThan(strpos($html, 'data-step="feedback"'), strpos($html, 'data-step="record"'));
        // Collapsed: a details element without the open attribute.
        $this->assertMatchesRegularExpression(
            '/<details class="mod-presenterai-allattempts[^"]*" data-region="allattempts">/',
            $html
        );
        $this->assertStringContainsString(get_string('allattempts', 'mod_presenterai', 2), $html);
        $this->assertGreaterThan(strpos($html, 'data-step="feedback"'), strpos($html, 'data-region="attempts"'));
    }

    /**
     * The latest attempt's card: scoring while it's scored (which the page polls on), then ready with each
     * criterion's level and the tips as "Try next time".
     *
     * @return void
     */
    public function test_latest_card(): void {
        global $DB, $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $rec = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => 'a.webm', 'status' => 'scoring']);

        $data = $this->export($instance, $course, $context, $student);
        $this->assertTrue($data['latest']['hasattempt']);
        $this->assertTrue($data['latest']['scoring']);
        $this->assertSame(get_string('card_status_scoring', 'mod_presenterai'), $data['latest']['status']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $this->assertStringContainsString('data-scoring="1"', $html);
        $this->assertStringContainsString('Scoring, usually about 2 minutes', $html);

        $DB->set_field('presenterai_recording', 'status', 'scored', ['id' => $rec->id]);
        $generator->create_score([
            'recordingid' => $rec->id,
            'scores' => json_encode([
                ['name' => 'Introduction', 'score' => 4, 'max_score' => 5, 'feedback' => 'Clear opener.', 'assessed' => true,
                    'level' => 'Proficient'],
                ['name' => 'Delivery', 'score' => 3, 'max_score' => 5, 'feedback' => '', 'assessed' => true],
            ]),
            'rawsum' => 7,
            'rawmax' => 10,
            'overallpct' => 70,
            'tips' => json_encode(['Pause after each point.']),
        ]);
        $data = $this->export($instance, $course, $context, $student);
        $card = $data['latest'];
        $this->assertFalse($card['scoring']);
        $this->assertSame(get_string('card_status_ready', 'mod_presenterai'), $card['status']);
        $this->assertSame('4 of 5, Proficient', $card['feedback']['criteria'][0]['scoretext']);
        $this->assertSame(
            get_string('feedback_criterion_score', 'mod_presenterai', (object) ['score' => 3, 'max' => 5]),
            $card['feedback']['criteria'][1]['scoretext'],
            'A criterion scored without levels keeps "3 out of 5".'
        );

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/latest', $card);
        $this->assertStringContainsString('data-scoring="0"', $html);
        $this->assertStringContainsString('4 of 5, Proficient', $html);
        $this->assertStringContainsString(get_string('card_tips', 'mod_presenterai'), $html);
        $this->assertStringContainsString('Pause after each point.', $html);
        $this->assertStringContainsString(format_float(70, 2) . '%', $html);

        // The fragment the page polls renders the same card, for the learner only.
        $this->setUser($student);
        $fragment = mod_presenterai_output_fragment_latest(['context' => $context]);
        $this->assertStringContainsString('4 of 5, Proficient', $fragment);
        $this->assertStringContainsString('data-region="latest-card"', $fragment);
    }

    /**
     * Which pending attempts the card promises a score for: being scored, or freshly uploaded on a site that can
     * score. An uploaded row that has waited 15 minutes, or sits on a site with AI off, promises nothing.
     *
     * @return void
     */
    public function test_pending_state_mapping(): void {
        $now = 1000000;
        $limit = view_page::SCORING_PROMISE_SECONDS;
        $this->assertSame('scoring', view_page::pending_state('scoring', $now - 10 * $limit, $now, false));
        $this->assertSame('scoring', view_page::pending_state('uploaded', $now - 60, $now, true));
        $this->assertSame('waiting', view_page::pending_state('uploaded', $now - $limit, $now, true), 'Too old.');
        $this->assertSame('waiting', view_page::pending_state('uploaded', $now - 60, $now, false), 'AI off.');
        foreach (['scored', 'failed', 'abandoned', 'uploading'] as $status) {
            $this->assertSame('', view_page::pending_state($status, $now, $now, true), $status);
        }
    }

    /**
     * An attempt left uploaded (AI off here) reads "Waiting to be scored" and isn't polled; an abandoned newest
     * attempt doesn't hide the feedback of the one before it, and a failed one is still shown.
     *
     * @return void
     */
    public function test_card_waiting_and_latest_row(): void {
        global $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        $now = time();
        $uploaded = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => 'a.webm', 'status' => 'uploaded', 'timecreated' => $now - 100, 'timemodified' => $now - 100]);

        $card = $this->export($instance, $course, $context, $student)['latest'];
        $this->assertSame((int) $uploaded->id, $card['recid']);
        $this->assertFalse($card['scoring']);
        $this->assertSame(get_string('card_status_waiting', 'mod_presenterai'), $card['status']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/latest', $card);
        $this->assertStringContainsString('data-scoring="0"', $html);

        $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'status' => 'abandoned', 'timecreated' => $now, 'timemodified' => $now]);
        $card = $this->export($instance, $course, $context, $student)['latest'];
        $this->assertSame((int) $uploaded->id, $card['recid'], 'An abandoned attempt hid the one before it.');

        $failed = $generator->create_recording(['presenteraiid' => $instance->id, 'userid' => $student->id,
            'storagekey' => 'b.webm', 'status' => 'failed', 'timecreated' => $now + 1, 'timemodified' => $now + 1]);
        $card = $this->export($instance, $course, $context, $student)['latest'];
        $this->assertSame((int) $failed->id, $card['recid']);
        $this->assertTrue($card['failed']);

        $this->assertNull(view_page::latest_row([['statuscode' => 'abandoned']]));
    }

    /**
     * The recorder is one Start button that becomes Finish, with the limits beside the timer.
     *
     * @return void
     */
    public function test_start_finish_and_timer_limits(): void {
        global $PAGE;

        $this->require_other_slices();
        $this->resetAfterTest();
        [$course, $instance, $context, $student] = $this->setup_activity(['minseconds' => 20, 'maxseconds' => 90]);

        $data = $this->export($instance, $course, $context, $student);
        $this->assertSame('minimum 0:20, maximum 1:30', $data['recorder']['timerlimits']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $start = get_string('rec_start', 'mod_presenterai');
        $finish = get_string('rec_finish', 'mod_presenterai');
        $this->assertStringContainsString('data-action="record">' . $start . '</button>', $html);
        $this->assertStringContainsString('data-action="stop" hidden>' . $finish . '</button>', $html);
        $this->assertStringContainsString('minimum 0:20, maximum 1:30', $html);
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
