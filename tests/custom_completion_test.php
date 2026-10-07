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

use mod_presenterai\completion\custom_completion;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/presenterai/lib.php');

/**
 * The two custom completion rules.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\completion\custom_completion
 * @covers     \mod_presenterai\local\completion_rules
 * @covers     ::presenterai_update_instance
 */
final class custom_completion_test extends \advanced_testcase {
    /** @var \stdClass The course, with completion on. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * A course with completion enabled and one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * An activity with automatic completion and the given rules.
     *
     * @param array $fields Instance fields, including the rule values.
     * @return \stdClass The instance, carrying cmid.
     */
    private function activity(array $fields): \stdClass {
        return $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ] + $fields);
    }

    /**
     * The completion object for the learner on an activity, freshly read.
     *
     * @param \stdClass $instance The activity.
     * @return custom_completion
     */
    private function completion(\stdClass $instance): custom_completion {
        $cm = get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
        return new custom_completion($cm, (int) $this->learner->id);
    }

    /**
     * An attempt with a status, and optionally a teacher score out of 10.
     *
     * @param \stdClass $instance The activity.
     * @param int $attemptnumber The attempt number.
     * @param string $status The recording status.
     * @param int|null $score Points out of 10, or null for no score row.
     * @return \stdClass The recording row.
     */
    private function attempt(\stdClass $instance, int $attemptnumber, string $status, ?int $score = null): \stdClass {
        $rec = $this->gen->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $this->learner->id,
            'attemptnumber' => $attemptnumber,
            'status' => $status,
        ]);
        if ($score !== null) {
            $this->gen->create_score(['recordingid' => $rec->id, 'rawsum' => $score, 'rawmax' => 10]);
        }
        return $rec;
    }

    /**
     * Uploaded, scoring and scored count as submitted; uploading, abandoned and failed don't.
     *
     * @return void
     */
    public function test_completionsubmit(): void {
        $instance = $this->activity(['completionsubmit' => 3]);

        foreach (['uploading', 'abandoned', 'failed', 'uploaded', 'scoring'] as $i => $status) {
            $this->attempt($instance, $i + 1, $status);
        }
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion($instance)->get_state('completionsubmit'));

        $this->attempt($instance, 6, 'scored');
        $this->assertSame(COMPLETION_COMPLETE, $this->completion($instance)->get_state('completionsubmit'));
    }

    /**
     * The minimum score follows the grading method.
     *
     * @return void
     */
    public function test_completionminscore_follows_gradingmethod(): void {
        global $DB;

        $instance = $this->activity(['completionminscore' => 70, 'grade' => 100, 'gradingmethod' => 'highest']);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion($instance)->get_state('completionminscore'));

        $this->attempt($instance, 1, 'scored', 8);
        $this->attempt($instance, 2, 'scored', 5);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion($instance)->get_state('completionminscore'));

        $DB->set_field('presenterai', 'gradingmethod', 'latest', ['id' => $instance->id]);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion($instance)->get_state('completionminscore'));

        // Exactly the threshold completes.
        $this->attempt($instance, 3, 'scored', 7);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion($instance)->get_state('completionminscore'));
    }

    /**
     * The minimum score works on an activity with no grade item.
     *
     * @return void
     */
    public function test_completionminscore_with_grade_zero(): void {
        $instance = $this->activity(['completionminscore' => 50, 'grade' => 0]);
        $this->attempt($instance, 1, 'scored', 6);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion($instance)->get_state('completionminscore'));
    }

    /**
     * Saving a teacher score and finalizing both update the stored completion state.
     *
     * @return void
     */
    public function test_state_updated_by_save(): void {
        $instance = $this->activity(['completionminscore' => 50, 'grade' => 100]);
        $cm = get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
        $completion = new \completion_info($this->course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $this->learner->id)->completionstate);

        $rec = $this->attempt($instance, 1, 'uploaded');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        local\score_manager::save_teacher_score(
            $rec,
            $instance,
            \context_module::instance($instance->cmid),
            (int) $teacher->id,
            0,
            [['name' => 'Overall', 'max_score' => 10, 'score' => 6, 'assessed' => true, 'feedback' => '']],
            ''
        );
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $this->learner->id)->completionstate);
    }

    /**
     * Changing the grading method in the settings recomputes the stored minimum-score state.
     *
     * Core resets completion only when the completion settings themselves change,
     * and the grading method isn't one of them.
     *
     * @return void
     */
    public function test_gradingmethod_change_updates_stored_state(): void {
        $instance = $this->activity(['completionminscore' => 70, 'grade' => 100, 'gradingmethod' => 'highest']);
        $this->attempt($instance, 1, 'scored', 8);
        $this->attempt($instance, 2, 'scored', 5);

        $cm = get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
        $completion = new \completion_info($this->course);
        $completion->update_state($cm, COMPLETION_UNKNOWN, (int) $this->learner->id);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $this->learner->id)->completionstate);

        presenterai_update_instance((object) [
            'instance' => $instance->id,
            'coursemodule' => $instance->cmid,
            'gradingmethod' => 'latest',
        ]);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $this->learner->id)->completionstate);

        presenterai_update_instance((object) [
            'instance' => $instance->id,
            'coursemodule' => $instance->cmid,
            'gradingmethod' => 'highest',
        ]);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $this->learner->id)->completionstate);
    }

    /**
     * A custom rule alongside the grade rules renders its completion details.
     *
     * Core's cm_completion_details throws when get_sort_order() leaves out a rule
     * that applies, which would break view.php and the course page.
     *
     * @return void
     */
    public function test_details_with_grade_rules(): void {
        $instance = $this->activity([
            'grade' => 100,
            'completionsubmit' => 1,
            'completionminscore' => 50,
            'completiongradeitemnumber' => 0,
            'completionpassgrade' => 1,
        ]);
        $cm = get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
        $details = \core_completion\cm_completion_details::get_instance($cm, (int) $this->learner->id)->get_details();
        $this->assertSame(
            ['completionsubmit', 'completionminscore', 'completionusegrade', 'completionpassgrade'],
            array_keys($details)
        );
    }

    /**
     * The rules travel in customdata only when completion is automatic and the value is set.
     *
     * @return void
     */
    public function test_customdata_and_descriptions(): void {
        $instance = $this->activity(['completionsubmit' => 2, 'completionminscore' => 0]);
        $cm = get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
        $this->assertSame(['completionsubmit' => 2], $cm->customdata['customcompletionrules']);

        $completion = new custom_completion($cm, (int) $this->learner->id);
        $this->assertSame(['completionsubmit'], array_values($completion->get_available_custom_rules()));
        $descriptions = $completion->get_custom_rule_descriptions();
        $this->assertSame(get_string('completiondetail:submit', 'mod_presenterai', 2), $descriptions['completionsubmit']);
        $this->assertSame(
            ['completionview', 'completionsubmit', 'completionminscore', 'completionusegrade', 'completionpassgrade'],
            $completion->get_sort_order()
        );
        $this->assertSame(['completionsubmit', 'completionminscore'], custom_completion::get_defined_custom_rules());

        $this->assertSame(
            [get_string('completiondetail:submit', 'mod_presenterai', 2)],
            mod_presenterai_get_completion_active_rule_descriptions($cm)
        );

        $manual = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionsubmit' => 2,
        ]);
        $manualcm = get_fast_modinfo($this->course->id)->get_cm($manual->cmid);
        $this->assertArrayNotHasKey('customcompletionrules', (array) $manualcm->customdata);
        $this->assertSame([], mod_presenterai_get_completion_active_rule_descriptions($manualcm));
    }
}
