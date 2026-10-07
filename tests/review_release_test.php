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

use mod_presenterai\event\feedback_released;
use mod_presenterai\form\grade_form;
use mod_presenterai\local\score_manager;
use mod_presenterai\output\attempt_row;
use mod_presenterai\output\grade_page;
use mod_presenterai\output\report_page;

/**
 * D28: teacher review before release.
 *
 * With reviewbeforerelease on, the AI still scores, but the learner sees
 * nothing of it, the gradebook and completion leave it out and no message is
 * sent until a teacher releases the attempt. Release does all three and fires
 * feedback_released. A rescore holds the attempt again, a teacher's own grade
 * releases it, and switching the setting off releases everything held.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\score_manager
 * @covers     \mod_presenterai\event\feedback_released
 * @covers     \mod_presenterai\output\attempt_row
 * @covers     \mod_presenterai\output\report_page
 * @covers     \mod_presenterai\output\grade_page
 */
final class review_release_test extends \advanced_testcase {
    /** @var string The AI's overall comment, which must not reach a learner early. */
    private const AI_FEEDBACK = 'The AI thought your opening was strong.';

    /** @var string The AI's body language summary. */
    private const AI_SUMMARY = 'Your hands stayed in view through the talk.';

    /** @var string The AI's tip. */
    private const AI_TIP = 'Pause after each main point.';

    /** @var \stdClass The course, with completion on. */
    private \stdClass $course;

    /** @var \stdClass The presenterai row, review on. */
    private \stdClass $instance;

    /** @var \cm_info The course module. */
    private \cm_info $cm;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \stdClass A second learner. */
    private \stdClass $learner2;

    /** @var \stdClass A non-editing teacher. */
    private \stdClass $teacher;

    /**
     * A graded activity with review on and completion on a 50% minimum score.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/presenterai/lib.php');

        parent::setUp();
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);
        $this->instance = $generator->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
            'reviewbeforerelease' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionminscore' => 50,
        ]);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'presenterai');
        $this->context = \context_module::instance($this->cm->id);
        $this->learner = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->learner2 = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Alan', 'lastname' => 'Turing']);
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->instance = $this->reload_instance();
    }

    /**
     * The instance row as stored.
     *
     * @return \stdClass
     */
    private function reload_instance(): \stdClass {
        global $DB;
        return $DB->get_record('presenterai', ['id' => $this->instance->id], '*', MUST_EXIST);
    }

    /**
     * A finished attempt.
     *
     * @param \stdClass|null $user The learner, the first one by default.
     * @return \stdClass
     */
    private function attempt(?\stdClass $user = null): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => ($user ?? $this->learner)->id,
            'status' => 'scoring',
            'storagekey' => random_string(10) . '.webm',
            'transcript' => 'A transcript.',
        ]);
    }

    /**
     * Save an AI score of $points out of 10 the way the scorer does.
     *
     * @param \stdClass $rec The attempt.
     * @param int $points Points out of 10.
     * @return \stdClass The score row.
     */
    private function ai_score(\stdClass $rec, int $points): \stdClass {
        return score_manager::save_ai_score(
            $rec,
            $this->instance,
            $this->context,
            0,
            [
                ['name' => 'Content', 'score' => $points, 'max_score' => 10, 'feedback' => 'Content feedback.',
                    'assessed' => true, 'visual' => false, 'counts' => true],
            ],
            self::AI_FEEDBACK,
            [self::AI_TIP],
            self::AI_SUMMARY,
            'summary'
        );
    }

    /**
     * The learner's grade in the gradebook, or null.
     *
     * @param \stdClass|null $user The learner.
     * @return float|null
     */
    private function gradebook_grade(?\stdClass $user = null): ?float {
        $user = $user ?? $this->learner;
        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $user->id);
        $grade = $grades->items[0]->grades[$user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * The learner's completion state.
     *
     * @return int
     */
    private function completion_state(): int {
        $completion = new \completion_info($this->course);
        return (int) $completion->get_data($this->cm, false, (int) $this->learner->id)->completionstate;
    }

    /**
     * The learner's attempt row as their page shows it.
     *
     * @param \stdClass $rec The attempt.
     * @return array
     */
    private function learner_row(\stdClass $rec): array {
        return attempt_row::export($rec, $this->context, (int) $this->learner->id, time(), true);
    }

    /**
     * Off by default: a new activity doesn't hold anything, and an AI score is released at once.
     *
     * @return void
     */
    public function test_off_by_default(): void {
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id, 'grade' => 100]);
        $this->assertSame(0, (int) $this->reload_record($instance->id)->reviewbeforerelease);

        $this->instance = $this->reload_record($instance->id);
        $this->context = \context_module::instance($instance->cmid);
        $sink = $this->redirectMessages();
        $score = $this->ai_score($this->attempt(), 8);
        $this->assertSame(1, (int) $score->released);
        $this->assertTrue(score_manager::is_released($score));
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * A presenterai row.
     *
     * @param int $id The instance id.
     * @return \stdClass
     */
    private function reload_record(int $id): \stdClass {
        global $DB;
        return $DB->get_record('presenterai', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Held: the learner sees no grade and no AI feedback of any kind, only the review sentence.
     *
     * @return void
     */
    public function test_held_score_is_hidden_from_the_learner(): void {
        global $PAGE;

        $rec = $this->attempt();
        $score = $this->ai_score($rec, 8);
        $this->assertSame(0, (int) $score->released);
        $this->assertSame('scored', $this->reload_rec($rec)->status, 'Review is a release state, not a scoring status (D8).');

        $row = $this->learner_row($this->reload_rec($rec));
        $this->assertTrue($row['inreview']);
        $this->assertFalse($row['hasscore']);
        $this->assertSame('', $row['score']);
        $this->assertFalse($row['hasfeedback']);
        $this->assertSame(get_string('status_awaitingreview', 'mod_presenterai'), $row['status']);
        $this->assertSame(get_string('feedback_inreview', 'mod_presenterai'), $row['reviewnote']);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/attempts', [
            'hasattempts' => true,
            'showdownloadoffnote' => false,
            'rows' => [$row],
        ]);
        $this->assertStringContainsString(get_string('feedback_inreview', 'mod_presenterai'), $html);
        foreach ([self::AI_FEEDBACK, self::AI_SUMMARY, self::AI_TIP, '80.00%', 'Content feedback.'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden . ' reached the learner before release.');
        }
    }

    /**
     * Held: nothing goes to the gradebook, no message is sent, and completion on a score waits.
     *
     * @return void
     */
    public function test_held_score_skips_gradebook_message_and_completion(): void {
        $sink = $this->redirectMessages();
        $this->ai_score($this->attempt(), 8);

        $this->assertCount(0, $sink->get_messages(), 'The learner was told about a held score.');
        $this->assertNull($this->gradebook_grade());
        $this->assertNull(local\grader::aggregate_for_user($this->instance, (int) $this->learner->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
    }

    /**
     * Release pushes the grade, updates completion, sends the message and logs feedback_released.
     *
     * @return void
     */
    public function test_release_does_everything_the_hold_skipped(): void {
        global $DB;

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $rec = $this->attempt();
        $held = $this->ai_score($rec, 8);
        $this->setUser($this->teacher);

        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $released = score_manager::release($this->reload_rec($rec), $this->instance, $this->context);
        $fired = $events->get_events();
        $events->close();

        $this->assertNotNull($released);
        $this->assertSame((int) $held->id, (int) $released->id);
        $this->assertSame(1, (int) $DB->get_field('presenterai_score', 'released', ['id' => $held->id]));
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade(), 0.001);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state());
        $this->assertCount(1, $messages->get_messages());
        $this->assertSame((int) $this->learner->id, (int) $messages->get_messages()[0]->useridto);

        $releasedevents = array_values(array_filter($fired, fn($e) => $e instanceof feedback_released));
        $this->assertCount(1, $releasedevents);
        $this->assertSame((int) $rec->id, (int) $releasedevents[0]->objectid);
        $this->assertSame((int) $this->learner->id, (int) $releasedevents[0]->relateduserid);
        $this->assertSame('ai', $releasedevents[0]->other['origin']);

        // Logged, not only fired: a release with no event sink lands in the standard log.
        $logged = $this->attempt($this->learner2);
        $this->ai_score($logged, 5);
        score_manager::release($this->reload_rec($logged), $this->instance, $this->context);
        $this->assertTrue($DB->record_exists('logstore_standard_log', [
            'eventname' => '\\' . feedback_released::class,
            'objectid' => $logged->id,
            'relateduserid' => $this->learner2->id,
        ]));

        // The learner now sees it all.
        $row = $this->learner_row($this->reload_rec($rec));
        $this->assertFalse($row['inreview']);
        $this->assertTrue($row['hasscore']);
        $this->assertSame(self::AI_SUMMARY, $row['feedback']['visualtext']);

        // Releasing again does nothing and says so.
        $this->assertNull(score_manager::release($this->reload_rec($rec), $this->instance, $this->context));
    }

    /**
     * The grading page: Awaiting review on the report, a Release button and "Save and release" on the attempt.
     *
     * @return void
     */
    public function test_grading_page_shows_awaiting_review_and_release(): void {
        global $PAGE;

        $rec = $this->attempt();
        $this->ai_score($rec, 8);
        $this->setUser($this->teacher);
        $renderer = $PAGE->get_renderer('core');

        $report = new report_page($this->instance, $this->course, $this->cm, $this->context, (int) $this->teacher->id, 0, '');
        $data = $report->export_for_template($renderer);
        $this->assertTrue($data['hasheld']);
        $this->assertSame(get_string('report_heldcount', 'mod_presenterai', 1), $data['heldtext']);
        $row = array_values(array_filter($data['rows'], fn($r) => (int) $r['userid'] === (int) $this->learner->id))[0];
        $this->assertSame(get_string('status_awaitingreview', 'mod_presenterai'), $row['status']);
        $this->assertSame(get_string('report_attemptn_review', 'mod_presenterai', 1), $row['attemptlinks'][0]['label']);
        $this->assertTrue($row['attemptlinks'][0]['inreview']);
        $this->assertSame('', $row['finalpct'], 'The overall column counted a held score.');
        $html = $renderer->render_from_template('mod_presenterai/report', $data);
        $this->assertStringContainsString('action=releaseall', $html);
        $this->assertSame([(int) $rec->id], $report->releasable_ids());

        $page = new grade_page(
            $this->instance,
            $this->reload_rec($rec),
            $this->course,
            $this->cm,
            $this->context,
            (int) $this->teacher->id,
            ''
        );
        $data = $page->export_for_template($renderer);
        $this->assertTrue($data['inreview']);
        $this->assertTrue($data['canrelease']);
        $this->assertSame(get_string('status_awaitingreview', 'mod_presenterai'), $data['status']);
        $html = $renderer->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringContainsString('name="action" value="release"', $html);
        $this->assertStringContainsString(get_string('grade_inreview_note', 'mod_presenterai'), $html);

        // The existing grade form is the edit; its button says it releases.
        $form = new grade_form(new \moodle_url('/mod/presenterai/grade.php'), [
            'cmid' => (int) $this->cm->id,
            'recordingid' => (int) $rec->id,
            'criteria' => [['name' => 'Content', 'max_score' => 10, 'visual' => false]],
            'outcomes' => [],
            'saveandrelease' => grade_page::in_review($rec),
        ]);
        $this->assertStringContainsString(get_string('grade_saveandrelease', 'mod_presenterai'), $form->render());

        // A learner on the same page gets neither the button nor the count.
        $data = (new report_page($this->instance, $this->course, $this->cm, $this->context, (int) $this->learner->id, 0, ''))
            ->export_for_template($renderer);
        $this->assertFalse($data['hasheld']);
    }

    /**
     * Release all releases every held attempt the teacher may grade, with a message and an event each.
     *
     * @return void
     */
    public function test_release_all(): void {
        $first = $this->attempt();
        $second = $this->attempt($this->learner2);
        $this->ai_score($first, 8);
        $this->ai_score($second, 4);
        $this->assertSame([(int) $first->id, (int) $second->id], score_manager::held_recording_ids((int) $this->instance->id));

        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $count = score_manager::release_all($this->instance, $this->context);
        $released = array_filter($events->get_events(), fn($e) => $e instanceof feedback_released);
        $events->close();

        $this->assertSame(2, $count);
        $this->assertCount(2, $released);
        $this->assertCount(2, $messages->get_messages());
        $this->assertSame([], score_manager::held_recording_ids((int) $this->instance->id));
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade(), 0.001);
        $this->assertEqualsWithDelta(40.0, $this->gradebook_grade($this->learner2), 0.001);

        // Restricting to a set releases only those.
        $third = $this->attempt();
        $fourth = $this->attempt($this->learner2);
        $this->ai_score($third, 6);
        $this->ai_score($fourth, 6);
        $this->assertSame(1, score_manager::release_all($this->instance, $this->context, [(int) $fourth->id]));
        $this->assertSame([(int) $third->id], score_manager::held_recording_ids((int) $this->instance->id));
    }

    /**
     * A rescore of a released attempt goes back to awaiting review, out of the gradebook, with no message.
     *
     * @return void
     */
    public function test_rescore_of_released_attempt_is_held_again(): void {
        $rec = $this->attempt();
        $this->ai_score($rec, 8);
        score_manager::release($this->reload_rec($rec), $this->instance, $this->context);
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade(), 0.001);

        $messages = $this->redirectMessages();
        $rescored = $this->ai_score($this->reload_rec($rec), 6);
        $this->assertSame(0, (int) $rescored->released);
        $this->assertSame([(int) $rec->id], score_manager::held_recording_ids((int) $this->instance->id));
        $this->assertTrue($this->learner_row($this->reload_rec($rec))['inreview']);
        $this->assertCount(0, $messages->get_messages());
        // As mod_assign does for a grade moved back out of released.
        $this->assertNull($this->gradebook_grade());
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());

        score_manager::release($this->reload_rec($rec), $this->instance, $this->context);
        $this->assertEqualsWithDelta(60.0, $this->gradebook_grade(), 0.001);
    }

    /**
     * A teacher's hand grade on a held attempt releases it, as the teacher's version.
     *
     * @return void
     */
    public function test_teacher_grade_releases(): void {
        $rec = $this->attempt();
        $this->ai_score($rec, 2);
        $this->setUser($this->teacher);

        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $score = score_manager::save_teacher_score(
            $this->reload_rec($rec),
            $this->instance,
            $this->context,
            (int) $this->teacher->id,
            0,
            [
                ['name' => 'Content', 'max_score' => 10, 'score' => 9, 'assessed' => true, 'feedback' => 'Strong.'],
            ],
            'Teacher feedback.'
        );
        $released = array_values(array_filter($events->get_events(), fn($e) => $e instanceof feedback_released));
        $events->close();

        $this->assertSame(1, (int) $score->released);
        $this->assertCount(1, $released);
        $this->assertSame('teacher', $released[0]->other['origin']);
        $this->assertCount(1, $messages->get_messages());
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.001);
        $this->assertSame([], score_manager::held_recording_ids((int) $this->instance->id));
        $row = $this->learner_row($this->reload_rec($rec));
        $this->assertFalse($row['inreview']);
        $this->assertStringContainsString('Teacher feedback.', $row['feedback']['overall']);

        // Grading an attempt that wasn't held fires no release event.
        $other = $this->attempt();
        $events = $this->redirectEvents();
        score_manager::save_teacher_score($other, $this->instance, $this->context, (int) $this->teacher->id, 0, [
            ['name' => 'Content', 'max_score' => 10, 'score' => 5, 'assessed' => true, 'feedback' => ''],
        ], '');
        $this->assertCount(0, array_filter($events->get_events(), fn($e) => $e instanceof feedback_released));
        $events->close();
    }

    /**
     * Switching the setting off releases everything held: grade, completion, message.
     *
     * @return void
     */
    public function test_turning_review_off_releases_held_attempts(): void {
        $first = $this->attempt();
        $second = $this->attempt($this->learner2);
        $this->ai_score($first, 8);
        $this->ai_score($second, 4);

        $data = $this->reload_instance();
        $data->instance = $data->id;
        $data->coursemodule = $this->cm->id;
        $data->reviewbeforerelease = 0;
        $messages = $this->redirectMessages();
        presenterai_update_instance($data);

        $this->assertSame(0, (int) $this->reload_instance()->reviewbeforerelease);
        $this->assertSame([], score_manager::held_recording_ids((int) $this->instance->id));
        $this->assertCount(2, $messages->get_messages());
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade(), 0.001);
        $this->assertEqualsWithDelta(40.0, $this->gradebook_grade($this->learner2), 0.001);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state());

        // From now on scores are released as they're written.
        $this->instance = $this->reload_instance();
        $this->assertSame(1, (int) $this->ai_score($this->attempt(), 5)->released);
    }

    /**
     * The recording row as stored.
     *
     * @param \stdClass $rec The attempt.
     * @return \stdClass
     */
    private function reload_rec(\stdClass $rec): \stdClass {
        global $DB;
        return $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
    }
}
