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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\score_manager;
use mod_presenterai\task\score_recording;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/scorer_test.php');

/**
 * The score_recording adhoc task and its transient retry rule.
 *
 * The rule, kept from SOLA: while the adhoc fail delay is under 960 seconds
 * (about the fifth run, thirty minutes in) a transient failure is thrown so
 * the task manager retries; from then on the row fails and the task ends.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\task\score_recording
 */
final class score_recording_task_test extends \advanced_testcase {
    /** @var string A transcript long enough to score. */
    private const TRANSCRIPT = 'Good afternoon. Today I will explain how wind turbines turn moving air into electricity.';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * One graded activity and one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 10,
        ]);
        $this->ctx = \context_module::instance($this->instance->cmid);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Never leave a double behind for the next test class.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A finished attempt with media on the File API.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(array $fields = []): \stdClass {
        $key = random_string(16) . '.webm';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => $key,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->ctx->id,
            'component' => 'mod_presenterai',
            'filearea' => 'recording',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $key,
        ], 'bytes');
        return $rec;
    }

    /**
     * A task for a recording, at a given fail delay.
     *
     * @param int $recordingid The recording.
     * @param int $faildelay The task's fail delay.
     * @param bool $rescore Whether it's a rescore.
     * @return score_recording
     */
    private function task(int $recordingid, int $faildelay, bool $rescore = false): score_recording {
        $task = new score_recording();
        $task->set_custom_data(['recordingid' => $recordingid, 'rescore' => $rescore]);
        $task->set_fail_delay($faildelay);
        return $task;
    }

    /**
     * Run a task, swallowing its mtrace output.
     *
     * @param score_recording $task The task.
     * @return void
     */
    private function run_task(score_recording $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The status as stored.
     *
     * @param int $id The recording id.
     * @return string
     */
    private function row_status(int $id): string {
        global $DB;
        return (string) $DB->get_field('presenterai_recording', 'status', ['id' => $id], MUST_EXIST);
    }

    /**
     * A transcriber outage on an early run throws, leaving the row scoring and no score.
     *
     * @return void
     */
    public function test_transient_failure_retries_while_young(): void {
        global $DB;

        route_resolver::set_test_stt(scorer_test::fake_stt([new ai_exception('http_5xx', true)]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([]));
        $rec = $this->recording();

        try {
            $this->run_task($this->task((int) $rec->id, 0));
            $this->fail('The task did not throw, so the task manager will not retry it.');
        } catch (ai_exception $e) {
            $this->assertTrue($e->transient);
        }
        $this->assertSame('scoring', $this->row_status((int) $rec->id));
        $this->assertSame(0, $DB->count_records('presenterai_score', ['recordingid' => $rec->id]));
    }

    /**
     * The same outage at a fail delay of 960 fails the row, and the task ends normally.
     *
     * @return void
     */
    public function test_transient_failure_fails_the_row_once_retries_are_spent(): void {
        global $DB;

        route_resolver::set_test_stt(scorer_test::fake_stt([new ai_exception('http_5xx', true)]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([]));
        $rec = $this->recording();

        $this->run_task($this->task((int) $rec->id, score_recording::RETRY_UNTIL_DELAY));

        $this->assertSame('failed', $this->row_status((int) $rec->id));
        $this->assertSame(0, $DB->count_records('presenterai_score', ['recordingid' => $rec->id]));
        $this->assertSame(960, score_recording::RETRY_UNTIL_DELAY);
    }

    /**
     * A delay just under 960 still retries.
     *
     * @return void
     */
    public function test_boundary(): void {
        route_resolver::set_test_stt(scorer_test::fake_stt([new ai_exception('timeout', true)]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([]));
        $rec = $this->recording();

        $this->expectException(ai_exception::class);
        $this->run_task($this->task((int) $rec->id, 959));
    }

    /**
     * A parse failure is final on the first run.
     *
     * @return void
     */
    public function test_parse_failure_is_final_at_once(): void {
        route_resolver::set_test_stt(scorer_test::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client(['{"criteria": "nope"']));
        $rec = $this->recording(['transcript' => self::TRANSCRIPT]);

        $this->run_task($this->task((int) $rec->id, 0));

        $this->assertSame('failed', $this->row_status((int) $rec->id));
    }

    /**
     * Success writes one AI row and pushes the grade.
     *
     * @return void
     */
    public function test_success_writes_one_row_and_the_grade(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $scores = [];
        foreach (rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $scores[$criterion['name']] = [3, true];
        }
        route_resolver::set_test_stt(scorer_test::fake_stt([self::TRANSCRIPT]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([scorer_test::answer($scores)]));
        $rec = $this->recording();

        $this->run_task($this->task((int) $rec->id, 0));

        $this->assertSame('scored', $this->row_status((int) $rec->id));
        $this->assertSame(1, $DB->count_records('presenterai_score', ['recordingid' => $rec->id, 'origin' => 'ai']));
        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id);
        $this->assertEqualsWithDelta(6.0, (float) $grades->items[0]->grades[$this->learner->id]->grade, 0.001);
    }

    /**
     * A rescore task refuses an attempt a teacher has scored.
     *
     * @return void
     */
    public function test_rescore_task_refuses_a_teacher_scored_attempt(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $rec = $this->recording(['transcript' => self::TRANSCRIPT]);
        $row = score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $teacher->id, 0, [
            ['name' => 'Delivery & Fluency', 'max_score' => 5, 'score' => 5, 'assessed' => true, 'feedback' => ''],
        ], '');
        $client = scorer_test::fake_client([scorer_test::answer(['Delivery & Fluency' => [1, true]])]);
        route_resolver::set_test_stt(scorer_test::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);

        $this->run_task($this->task((int) $rec->id, 0, true));

        $this->assertCount(0, $client->calls);
        $this->assertSame(0, $DB->count_records('presenterai_score', ['recordingid' => $rec->id, 'origin' => 'ai']));
        $this->assertSame((int) $row->id, (int) score_manager::current_score((int) $rec->id)->id);
    }

    /**
     * queue() queues one task with the recording and the rescore flag, and not twice.
     *
     * @return void
     */
    public function test_queue(): void {
        score_recording::queue(42, true);
        score_recording::queue(42, true);
        score_recording::queue(43);

        $tasks = \core\task\manager::get_adhoc_tasks(score_recording::class);
        $this->assertCount(2, $tasks);
        $data = [];
        foreach ($tasks as $task) {
            $custom = $task->get_custom_data();
            $data[(int) $custom->recordingid] = (bool) $custom->rescore;
        }
        ksort($data);
        $this->assertSame([42 => true, 43 => false], $data);
        $this->assertSame(get_string('scoring_task', 'mod_presenterai'), (new score_recording())->get_name());
    }

    /**
     * A task for a recording that no longer exists ends quietly.
     *
     * @return void
     */
    public function test_missing_recording(): void {
        $this->run_task($this->task(999999, 0));
        $this->run_task($this->task(0, 0));
        $this->assertTrue(true);
    }
}
