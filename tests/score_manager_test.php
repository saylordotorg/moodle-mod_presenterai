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

use mod_presenterai\local\score_manager;

/**
 * Score rows: writing a teacher's, and which row is current.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\score_manager
 * @covers     \mod_presenterai\local\notifier
 */
final class score_manager_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity row, carrying cmid. */
    private \stdClass $instance;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \stdClass The teacher. */
    private \stdClass $teacher;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * One course, one graded activity, one learner and one teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
        ]);
        $this->ctx = \context_module::instance($this->instance->cmid);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * A finished attempt by the learner.
     *
     * @param int $attemptnumber The attempt number.
     * @return \stdClass The recording row.
     */
    private function recording(int $attemptnumber = 1): \stdClass {
        return $this->gen->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'attemptnumber' => $attemptnumber,
        ]);
    }

    /**
     * Three criteria, the middle one not assessed.
     *
     * @return array[]
     */
    private static function criteria(): array {
        return [
            ['name' => 'Pace', 'max_score' => 5, 'score' => 4, 'assessed' => true, 'feedback' => 'Good pace.'],
            ['name' => 'Eye contact', 'max_score' => 5, 'score' => null, 'assessed' => false, 'feedback' => ''],
            ['name' => 'Structure', 'max_score' => 10, 'score' => 6, 'assessed' => true, 'feedback' => 'Clear.'],
        ];
    }

    /**
     * Saving writes a teacher row with the right JSON and sums, and marks the recording scored.
     *
     * @return void
     */
    public function test_save_writes_teacher_row(): void {
        global $DB;

        $rec = $this->recording();
        $score = score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->ctx,
            (int) $this->teacher->id,
            0,
            self::criteria(),
            'Well done overall.'
        );

        $this->assertSame('teacher', $score->origin);
        $this->assertSame((int) $this->learner->id, (int) $score->userid);
        $this->assertSame((int) $rec->id, (int) $score->recordingid);
        $this->assertSame((int) $this->teacher->id, (int) $score->graderid);
        $this->assertSame(10, (int) $score->rawsum, 'The unassessed criterion adds nothing to the sum.');
        $this->assertSame(15, (int) $score->rawmax, 'The unassessed criterion adds nothing to the maximum.');
        $this->assertEqualsWithDelta(66.67, (float) $score->overallpct, 0.001);
        $this->assertSame('exact', $score->scoreprovenance);
        $this->assertSame('Well done overall.', $score->feedback);
        $this->assertNull($score->tips);
        $this->assertSame([
            ['name' => 'Pace', 'score' => 4, 'max_score' => 5, 'feedback' => 'Good pace.', 'assessed' => true,
                'visual' => false, 'counts' => true],
            ['name' => 'Eye contact', 'score' => 0, 'max_score' => 5, 'feedback' => '', 'assessed' => false,
                'visual' => false, 'counts' => true],
            ['name' => 'Structure', 'score' => 6, 'max_score' => 10, 'feedback' => 'Clear.', 'assessed' => true,
                'visual' => false, 'counts' => true],
        ], json_decode($score->scores, true));
        $this->assertSame(json_decode($score->scores, true), score_manager::decode_criteria($score));

        $after = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $this->assertSame((int) $score->id, (int) $after->scoreid);
        $this->assertSame('scored', $after->status);
    }

    /**
     * With nothing assessed the percentage is null, not zero.
     *
     * @return void
     */
    public function test_nothing_assessed_gives_null_pct(): void {
        $rec = $this->recording();
        $score = score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $this->teacher->id, 0, [
            ['name' => 'Pace', 'max_score' => 5, 'score' => null, 'assessed' => false, 'feedback' => ''],
        ], '');

        $this->assertSame(0, (int) $score->rawmax);
        $this->assertNull($score->overallpct);
    }

    /**
     * The event carries the score id and origin; the learner gets one message.
     *
     * @return void
     */
    public function test_event_and_message(): void {
        $this->preventResetByRollback();
        $rec = $this->recording();
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();

        $score = score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->ctx,
            (int) $this->teacher->id,
            0,
            self::criteria(),
            ''
        );

        $scored = array_values(array_filter($events->get_events(), function ($e): bool {
            return $e instanceof \mod_presenterai\event\recording_scored;
        }));
        $this->assertCount(1, $scored);
        $this->assertSame((int) $rec->id, (int) $scored[0]->objectid);
        $this->assertSame((int) $this->learner->id, (int) $scored[0]->relateduserid);
        $this->assertSame((int) $score->id, $scored[0]->other['scoreid']);
        $this->assertSame('teacher', $scored[0]->other['origin']);

        $sent = $messages->get_messages();
        $this->assertCount(1, $sent);
        $this->assertSame((int) $this->learner->id, (int) $sent[0]->useridto);
        $this->assertSame('mod_presenterai', $sent[0]->component);
        $this->assertSame('recordingscored', $sent[0]->eventtype);
        $this->assertStringNotContainsString(fullname($this->teacher), $sent[0]->fullmessage, 'The grader is not named.');
    }

    /**
     * No score-ready message while the grade item is hidden from the learner.
     *
     * @return void
     */
    public function test_no_message_while_grade_hidden(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->preventResetByRollback();
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'presenterai',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]);
        $item->set_hidden(1);
        $rec = $this->recording();
        $messages = $this->redirectMessages();

        score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->ctx,
            (int) $this->teacher->id,
            0,
            self::criteria(),
            ''
        );

        $this->assertCount(0, $messages->get_messages());
    }

    /**
     * The score-ready message is in the learner's language, not the grader's.
     *
     * @return void
     */
    public function test_message_in_the_learner_language(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->install_language('xx', ['message_recordingscored_subject' => 'XX {$a->activity}']);
        try {
            $DB->set_field('user', 'lang', 'xx', ['id' => $this->learner->id]);
            $rec = $this->recording();
            $messages = $this->redirectMessages();

            score_manager::save_teacher_score(
                $rec,
                $this->instance,
                $this->ctx,
                (int) $this->teacher->id,
                0,
                self::criteria(),
                ''
            );

            $sent = $messages->get_messages();
            $this->assertCount(1, $sent);
            $this->assertSame('XX ' . $this->instance->name, $sent[0]->subject);
            $this->assertSame('en', current_language(), 'The language is put back afterwards.');
        } finally {
            $this->remove_language('xx');
        }
    }

    /**
     * An activity name with '&' reads as '&' in the plain-text parts and stays escaped in the HTML body.
     *
     * @return void
     */
    public function test_message_escapes_name_only_in_html(): void {
        global $DB;

        $this->preventResetByRollback();
        $DB->set_field('presenterai', 'name', 'Q&A pitch', ['id' => $this->instance->id]);
        $this->instance->name = 'Q&A pitch';
        $rec = $this->recording();
        $messages = $this->redirectMessages();

        score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->ctx,
            (int) $this->teacher->id,
            0,
            self::criteria(),
            ''
        );

        $sent = $messages->get_messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Q&A pitch', $sent[0]->subject);
        $this->assertStringContainsString('Q&A pitch', $sent[0]->fullmessage);
        $this->assertStringNotContainsString('&amp;', $sent[0]->fullmessage);
        $this->assertStringContainsString('Q&amp;A pitch', $sent[0]->fullmessagehtml);
    }

    /**
     * Nobody is told about a score they entered on their own attempt, and a suspended learner isn't messaged.
     *
     * @return void
     */
    public function test_no_message_to_self_or_suspended(): void {
        global $DB;

        $this->preventResetByRollback();
        $rec = $this->recording();
        $messages = $this->redirectMessages();

        score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $this->learner->id, 0, self::criteria(), '');
        $this->assertCount(0, $messages->get_messages());

        $DB->set_field('user', 'suspended', 1, ['id' => $this->learner->id]);
        score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $this->teacher->id, 0, self::criteria(), '');
        $this->assertCount(0, $messages->get_messages());
    }

    /**
     * Bad numbers and a recording from another activity are refused, and nothing is written.
     *
     * @return void
     */
    public function test_validation(): void {
        global $DB;

        $rec = $this->recording();
        $bad = [
            'score above max' => [['name' => 'Pace', 'max_score' => 5, 'score' => 6, 'assessed' => true, 'feedback' => '']],
            'negative' => [['name' => 'Pace', 'max_score' => 5, 'score' => -1, 'assessed' => true, 'feedback' => '']],
            'null while assessed' => [['name' => 'Pace', 'max_score' => 5, 'score' => null, 'assessed' => true, 'feedback' => '']],
            'not whole' => [['name' => 'Pace', 'max_score' => 5, 'score' => 2.5, 'assessed' => true, 'feedback' => '']],
            'zero max' => [['name' => 'Pace', 'max_score' => 0, 'score' => 0, 'assessed' => true, 'feedback' => '']],
            'empty name' => [['name' => ' ', 'max_score' => 5, 'score' => 1, 'assessed' => true, 'feedback' => '']],
            'empty list' => [],
        ];
        foreach ($bad as $label => $criteria) {
            try {
                score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $this->teacher->id, 0, $criteria, '');
                $this->fail("Accepted: {$label}");
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['error:invalidscore', 'invalidparameter'], $label);
            }
        }

        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        try {
            score_manager::save_teacher_score($rec, $other, $this->ctx, (int) $this->teacher->id, 0, self::criteria(), '');
            $this->fail('A recording from another activity was scored.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame('invalidparameter', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('presenterai_score'));
        $this->assertSame('uploaded', $DB->get_field('presenterai_recording', 'status', ['id' => $rec->id]));
    }

    /**
     * A teacher row beats a newer AI row, the latest teacher row wins, and AI is used without one.
     *
     * @return void
     */
    public function test_current_score_precedence(): void {
        $rec = $this->recording();
        $other = $this->recording(2);
        $now = time();

        $this->assertNull(score_manager::current_score((int) $rec->id));

        $ai1 = $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'timecreated' => $now - 50]);
        $this->assertSame((int) $ai1->id, (int) score_manager::current_score((int) $rec->id)->id);

        $teacher1 = $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher', 'timecreated' => $now - 40]);
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'timecreated' => $now]);
        $this->assertSame((int) $teacher1->id, (int) score_manager::current_score((int) $rec->id)->id, 'Teacher beats newer AI.');

        // Same second: the higher id is the later row.
        $teacher2 = $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher', 'timecreated' => $now - 40]);
        $teacher3 = $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher', 'timecreated' => $now - 10]);
        $this->assertSame((int) $teacher3->id, (int) score_manager::current_score((int) $rec->id)->id);
        $this->assertNotSame((int) $teacher2->id, (int) $teacher3->id);

        $ai2 = $this->gen->create_score(['recordingid' => $other->id, 'origin' => 'ai', 'timecreated' => $now]);
        $current = score_manager::current_scores([$rec->id, $other->id, 0, -5]);
        $this->assertSame([(int) $rec->id, (int) $other->id], array_keys($current));
        $this->assertSame((int) $ai2->id, (int) $current[$other->id]->id);

        $latestai = score_manager::latest_by_origin([$rec->id, $other->id], score_manager::ORIGIN_AI);
        $this->assertCount(2, $latestai);
        $this->assertSame([], score_manager::latest_by_origin([], score_manager::ORIGIN_AI));
    }

    /**
     * A teacher row with nothing assessed is still current.
     *
     * @return void
     */
    public function test_teacher_row_with_zero_max_still_wins(): void {
        $rec = $this->recording();
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'rawsum' => 8, 'rawmax' => 10]);
        $teacher = $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher', 'rawmax' => 0]);
        $this->assertSame((int) $teacher->id, (int) score_manager::current_score((int) $rec->id)->id);
    }

    /**
     * decode_criteria copes with junk and treats a missing flag as assessed.
     *
     * @return void
     */
    public function test_decode_criteria(): void {
        $this->assertSame([], score_manager::decode_criteria((object) ['scores' => 'nope']));
        $this->assertSame([
            ['name' => 'Pace', 'score' => 3, 'max_score' => 5, 'feedback' => '', 'assessed' => true,
                'visual' => false, 'counts' => true],
            ['name' => '', 'score' => 0, 'max_score' => 5, 'feedback' => 'x', 'assessed' => false,
                'visual' => false, 'counts' => true],
            ['name' => 'Gestures', 'score' => 4, 'max_score' => 5, 'feedback' => '', 'assessed' => true,
                'visual' => true, 'counts' => false],
        ], score_manager::decode_criteria((object) ['scores' => json_encode([
            ['name' => 'Pace', 'score' => 3, 'max_score' => 5],
            'junk',
            ['feedback' => 'x', 'assessed' => false],
            ['name' => 'Gestures', 'score' => 4, 'max_score' => 5, 'visual' => true, 'counts' => false],
        ])]));
    }

    /**
     * Seven criteria as the scorer hands them over: five spoken, two visual and feedback only.
     *
     * @return array[]
     */
    private static function ai_criteria(): array {
        $out = [];
        foreach (\mod_presenterai\local\rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $out[] = ['name' => $criterion['name'], 'score' => 4, 'max_score' => 5, 'feedback' => 'Good.',
                'assessed' => true, 'visual' => false, 'counts' => true];
        }
        $out[] = ['name' => 'Body Language & Gestures', 'score' => 5, 'max_score' => 5, 'feedback' => 'Open hands.',
            'assessed' => true, 'visual' => true, 'counts' => false];
        $out[] = ['name' => 'Eye Contact & Camera Presence', 'score' => 1, 'max_score' => 5, 'feedback' => 'Look up.',
            'assessed' => true, 'visual' => true, 'counts' => false];
        return $out;
    }

    /**
     * An AI score on an attempt with no teacher row becomes current and is passed on like a teacher's.
     *
     * @return void
     */
    public function test_save_ai_score(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->preventResetByRollback();
        $rec = $this->recording();
        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();

        $score = score_manager::save_ai_score(
            $rec,
            $this->instance,
            $this->ctx,
            0,
            self::ai_criteria(),
            'Overall.',
            ['Tip one.', '', '  Tip two. '],
            'You kept your hands in view.',
            'summary'
        );

        $this->assertSame('ai', $score->origin);
        $this->assertSame(0, (int) $score->graderid);
        $this->assertSame(20, (int) $score->rawsum, 'Feedback only visual criteria reached the sum (D23).');
        $this->assertSame(25, (int) $score->rawmax);
        $this->assertEqualsWithDelta(80.0, (float) $score->overallpct, 0.001);
        $this->assertSame(['Tip one.', 'Tip two.'], json_decode($score->tips, true));
        $this->assertSame('You kept your hands in view.', $score->visualsummary);
        $this->assertSame('summary', $score->visualstatus);
        $stored = score_manager::decode_criteria($score);
        $this->assertSame([false, false], array_column(array_slice($stored, 5), 'counts'));

        $after = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $this->assertSame('scored', $after->status);
        $this->assertSame((int) $score->id, (int) $after->scoreid);

        $scored = array_values(array_filter($events->get_events(), function ($e): bool {
            return $e instanceof \mod_presenterai\event\recording_scored;
        }));
        $this->assertCount(1, $scored);
        $this->assertSame('ai', $scored[0]->other['origin']);
        $this->assertCount(1, $messages->get_messages());

        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id);
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$this->learner->id]->grade, 0.001);
    }

    /**
     * With visualscored on, the same visual criteria count.
     *
     * @return void
     */
    public function test_save_ai_score_with_visual_scored(): void {
        $criteria = array_map(function (array $c): array {
            $c['counts'] = true;
            return $c;
        }, self::ai_criteria());
        $score = score_manager::save_ai_score($this->recording(), $this->instance, $this->ctx, 0, $criteria, '', [], null, '');
        $this->assertSame(26, (int) $score->rawsum);
        $this->assertSame(35, (int) $score->rawmax);
    }

    /**
     * A summary is kept only for the summary and fallback statuses.
     *
     * @return void
     */
    public function test_save_ai_score_summary_only_with_its_status(): void {
        $score = score_manager::save_ai_score(
            $this->recording(),
            $this->instance,
            $this->ctx,
            0,
            self::ai_criteria(),
            '',
            [],
            'Stray text.',
            'notassessed'
        );
        $this->assertNull($score->visualsummary);
        $this->assertSame('notassessed', $score->visualstatus);

        $score = score_manager::save_ai_score(
            $this->recording(2),
            $this->instance,
            $this->ctx,
            0,
            self::ai_criteria(),
            '',
            [],
            'Template text.',
            'fallback'
        );
        $this->assertSame('Template text.', $score->visualsummary);
    }

    /**
     * An AI score never changes the current score of an attempt with a teacher row.
     *
     * The AI row is still written for the audit trail; the grade, scoreid,
     * sums and the learner's inbox are untouched.
     *
     * @return void
     */
    public function test_ai_rescore_never_overrides_a_teacher(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->preventResetByRollback();
        $rec = $this->recording();
        $teacher = score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->ctx,
            (int) $this->teacher->id,
            0,
            self::criteria(),
            'Teacher says.'
        );
        $before = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $gradebefore = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id)
            ->items[0]->grades[$this->learner->id]->grade;

        $events = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $ai = score_manager::save_ai_score(
            $before,
            $this->instance,
            $this->ctx,
            0,
            self::ai_criteria(),
            'AI says.',
            [],
            null,
            ''
        );

        $this->assertSame('ai', $ai->origin, 'The AI row is kept for the audit trail.');
        $after = $DB->get_record('presenterai_recording', ['id' => $rec->id]);
        $this->assertSame((int) $teacher->id, (int) $after->scoreid);
        $this->assertSame('scored', $after->status);
        $current = score_manager::current_score((int) $rec->id);
        $this->assertSame((int) $teacher->id, (int) $current->id);
        $this->assertSame((int) $teacher->rawsum, (int) $current->rawsum);
        $this->assertSame((int) $teacher->rawmax, (int) $current->rawmax);
        $this->assertSame((float) $teacher->overallpct, (float) $current->overallpct);
        $gradeafter = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id)
            ->items[0]->grades[$this->learner->id]->grade;
        $this->assertEquals($gradebefore, $gradeafter);
        $this->assertCount(0, $messages->get_messages(), 'The learner was told about a score that is not theirs.');
        $this->assertCount(0, array_filter($events->get_events(), function ($e): bool {
            return $e instanceof \mod_presenterai\event\recording_scored;
        }));
    }

    /**
     * A teacher's row takes visual and counts from the rubric, never from the request.
     *
     * @return void
     */
    public function test_teacher_flags_come_from_the_rubric(): void {
        global $DB;

        $DB->update_record('presenterai', (object) ['id' => $this->instance->id, 'videovision' => 1, 'visualscored' => 0]);
        $instance = $DB->get_record('presenterai', ['id' => $this->instance->id]);

        $score = score_manager::save_teacher_score($this->recording(), $instance, $this->ctx, (int) $this->teacher->id, 0, [
            ['name' => 'Delivery & Fluency', 'max_score' => 5, 'score' => 3, 'assessed' => true, 'visual' => true,
                'counts' => false],
            ['name' => 'body language &  GESTURES', 'max_score' => 5, 'score' => 5, 'assessed' => true, 'counts' => true],
        ], '');

        $stored = score_manager::decode_criteria($score);
        $this->assertFalse($stored[0]['visual'], 'The request made a spoken criterion visual.');
        $this->assertTrue($stored[0]['counts']);
        $this->assertTrue($stored[1]['visual']);
        $this->assertFalse($stored[1]['counts'], 'The request moved a feedback only criterion into the total.');
        $this->assertSame(3, (int) $score->rawsum);
        $this->assertSame(5, (int) $score->rawmax);

        $DB->set_field('presenterai', 'visualscored', 1, ['id' => $this->instance->id]);
        $instance = $DB->get_record('presenterai', ['id' => $this->instance->id]);
        $score = score_manager::save_teacher_score($this->recording(2), $instance, $this->ctx, (int) $this->teacher->id, 0, [
            ['name' => 'Body Language & Gestures', 'max_score' => 5, 'score' => 5, 'assessed' => true],
        ], '');
        $this->assertSame(5, (int) $score->rawmax);
    }

    /**
     * has_teacher_score sees only teacher rows.
     *
     * @return void
     */
    public function test_has_teacher_score(): void {
        $rec = $this->recording();
        $this->assertFalse(score_manager::has_teacher_score((int) $rec->id));
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai']);
        $this->assertFalse(score_manager::has_teacher_score((int) $rec->id));
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher']);
        $this->assertTrue(score_manager::has_teacher_score((int) $rec->id));
    }

    /**
     * Install a one-string language pack, so a message in it can be told from English.
     *
     * @param string $lang The language code.
     * @param array $strings mod_presenterai string id => text.
     * @return void
     */
    private function install_language(string $lang, array $strings): void {
        global $CFG;

        $dir = $CFG->langotherroot . '/' . $lang;
        make_writable_directory($dir);
        file_put_contents($dir . '/langconfig.php', "<?php\n\$string['thislanguage'] = 'Test';\n"
            . "\$string['parentlanguage'] = '';\n");
        $php = "<?php\n";
        foreach ($strings as $id => $text) {
            $php .= '$string[' . var_export($id, true) . '] = ' . var_export($text, true) . ";\n";
        }
        file_put_contents($dir . '/presenterai.php', $php);
        get_string_manager()->reset_caches();
    }

    /**
     * Remove a language pack install_language() added.
     *
     * @param string $lang The language code.
     * @return void
     */
    private function remove_language(string $lang): void {
        global $CFG;

        remove_dir($CFG->langotherroot . '/' . $lang);
        get_string_manager()->reset_caches();
    }
}
