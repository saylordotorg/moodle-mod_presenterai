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

use mod_presenterai\form\grade_form;
use mod_presenterai\local\access;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\score_manager;
use mod_presenterai\output\grade_page;
use mod_presenterai\output\report_page;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/scorer_test.php');

/**
 * D27: an attempt whose scoring failed for good, end to end.
 *
 * A failed attempt doesn't use up an attempt, so the learner gets it back. A
 * teacher can still find it on the grading page and grade it by hand, which
 * moves it to scored, where it counts; and Rescore puts it back in the queue.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\scorer
 * @covers     \mod_presenterai\output\report_page
 * @covers     \mod_presenterai\output\grade_page
 * @covers     \mod_presenterai\local\score_manager
 */
final class failed_attempt_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The presenterai row, one attempt allowed. */
    private \stdClass $instance;

    /** @var \cm_info The course module. */
    private \cm_info $cm;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \stdClass A non-editing teacher. */
    private \stdClass $teacher;

    /**
     * A graded activity allowing one attempt, a learner and a teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
            'maxattempts' => 1,
        ]);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'presenterai');
        $this->context = \context_module::instance($this->cm->id);
        $this->learner = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
    }

    /**
     * Never leave a double behind.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A failed attempt with a transcript and its media.
     *
     * @return \stdClass
     */
    private function failed_attempt(): \stdClass {
        $key = random_string(12) . '.webm';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'status' => recording_manager::STATUS_FAILED,
            'storagekey' => $key,
            'transcript' => str_repeat('A clear talk about renewable energy. ', 4),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_presenterai',
            'filearea' => 'recording',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $key,
        ], 'not really webm');
        return $rec;
    }

    /**
     * A failed attempt gives the learner the attempt back, and the grading
     * page still lists it with a grade action.
     *
     * @return void
     */
    public function test_failed_attempt_is_listed_with_a_grade_action(): void {
        global $PAGE;

        $rec = $this->failed_attempt();
        $this->assertSame(0, recording_manager::counted_attempts((int) $this->instance->id, (int) $this->learner->id));
        $this->assertFalse(recording_manager::cap_reached($this->instance, (int) $this->learner->id));

        $page = new report_page($this->instance, $this->course, $this->cm, $this->context, (int) $this->teacher->id, 0, '');
        $data = $page->export_for_template($PAGE->get_renderer('core'));
        $row = null;
        foreach ($data['rows'] as $candidate) {
            if ((int) $candidate['userid'] === (int) $this->learner->id) {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(get_string('status_failed', 'mod_presenterai'), $row['status']);
        $this->assertStringContainsString('recordingid=' . $rec->id, $row['gradeurl']);
        $this->assertCount(1, $row['attemptlinks']);
        $this->assertStringContainsString('recordingid=' . $rec->id, $row['attemptlinks'][0]['url']);

        // The check grade.php makes lets the teacher open it.
        $this->assertSame((int) $rec->id, (int) access::require_gradable_recording(
            $this->cm,
            $this->context,
            (int) $rec->id,
            (int) $this->teacher->id
        )->id);
    }

    /**
     * Saving a hand grade on a failed attempt, through the grade form, moves
     * it to scored, where it counts toward the attempts and the gradebook.
     *
     * @return void
     */
    public function test_hand_grade_on_failed_attempt_scores_it(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $rec = $this->failed_attempt();
        $this->setUser($this->teacher);
        $rubric = rubric_manager::resolve($this->instance, $this->context);
        $spoken = array_values(array_filter($rubric['criteria'], fn($c) => empty($c['visual'])));

        $assessed = [];
        $scores = [];
        $feedback = [];
        foreach ($rubric['criteria'] as $i => $criterion) {
            $isspoken = empty($criterion['visual']);
            $assessed[$i] = $isspoken ? 1 : 0;
            if ($isspoken) {
                $scores[$i] = (string) (int) $criterion['max_score'];
            }
            $feedback[$i] = '';
        }
        grade_form::mock_submit([
            'id' => (int) $this->cm->id,
            'recordingid' => (int) $rec->id,
            'assessed' => $assessed,
            'score' => $scores,
            'criterionfeedback' => $feedback,
            'feedback' => 'Graded by hand after the AI failed.',
        ]);
        $form = new grade_form(new \moodle_url('/mod/presenterai/grade.php'), [
            'cmid' => (int) $this->cm->id,
            'recordingid' => (int) $rec->id,
            'criteria' => $rubric['criteria'],
            'outcomes' => [],
        ]);
        $data = $form->get_data();
        $this->assertNotNull($data, 'The grade form refused a valid hand grade.');

        score_manager::save_teacher_score(
            $rec,
            $this->instance,
            $this->context,
            (int) $this->teacher->id,
            (int) $rubric['rubricid'],
            $form->to_criteria($data),
            (string) $data->feedback
        );

        $after = recording_manager::load((int) $rec->id)[0];
        $this->assertSame('scored', $after->status);
        $this->assertSame(1, recording_manager::counted_attempts((int) $this->instance->id, (int) $this->learner->id));
        $this->assertTrue(recording_manager::cap_reached($this->instance, (int) $this->learner->id));
        $this->assertNotEmpty($spoken);

        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id);
        $this->assertEqualsWithDelta(100.0, (float) $grades->items[0]->grades[$this->learner->id]->grade, 0.001);
    }

    /**
     * Rescore on a failed attempt queues it, and the queued task scores it.
     *
     * @return void
     */
    public function test_rescore_requeues_a_failed_attempt(): void {
        global $DB;

        $rec = $this->failed_attempt();
        $this->assertSame(
            grade_page::RESCORE_HIDDEN,
            grade_page::rescore_state($rec, $this->context, (int) $this->teacher->id),
            'Rescore was offered with no AI set up.'
        );

        $answer = [];
        foreach (rubric_manager::resolve($this->instance, $this->context)['criteria'] as $criterion) {
            if (empty($criterion['visual'])) {
                $answer[] = ['name' => $criterion['name'], 'score' => 3, 'assessed' => true, 'feedback' => 'Fine.'];
            }
        }
        route_resolver::set_test_stt(scorer_test::fake_stt([]));
        route_resolver::set_test_client(
            route_resolver::PURPOSE_SCORE,
            scorer_test::fake_client([json_encode(['criteria' => $answer, 'overall' => 'Better.', 'tips' => []])])
        );
        $this->assertSame(
            grade_page::RESCORE_AVAILABLE,
            grade_page::rescore_state($rec, $this->context, (int) $this->teacher->id)
        );

        // What grade.php does for action=rescore.
        \mod_presenterai\task\score_recording::queue((int) $rec->id, true);
        $tasks = \core\task\manager::get_adhoc_tasks(\mod_presenterai\task\score_recording::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $rec->id, (int) reset($tasks)->get_custom_data()->recordingid);

        ob_start();
        try {
            $this->runAdhocTasks(\mod_presenterai\task\score_recording::class);
        } finally {
            ob_end_clean();
        }

        $after = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
        $this->assertSame('scored', $after->status);
        $this->assertSame(1, recording_manager::counted_attempts((int) $this->instance->id, (int) $this->learner->id));
        $this->assertNotNull(score_manager::current_score((int) $rec->id));
    }
}
