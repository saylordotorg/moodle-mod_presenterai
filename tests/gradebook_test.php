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

use mod_presenterai\local\gradebook;
use mod_presenterai\local\score_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/presenterai/lib.php');

/**
 * What reaches the gradebook, and what never changes it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\gradebook
 * @covers     \mod_presenterai\local\grader
 */
final class gradebook_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \stdClass The teacher. */
    private \stdClass $teacher;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * One course, one learner, one teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * An activity, read back as its row.
     *
     * @param array $fields Instance fields.
     * @return \stdClass The presenterai row, with cmid and cmidnumber.
     */
    private function activity(array $fields = []): \stdClass {
        global $DB;
        $created = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id] + $fields);
        $instance = $DB->get_record('presenterai', ['id' => $created->id], '*', MUST_EXIST);
        $instance->cmid = $created->cmid;
        $instance->cmidnumber = '';
        return $instance;
    }

    /**
     * A finished attempt with a teacher score of rawsum out of rawmax, pushed through save_teacher_score().
     *
     * @param \stdClass $instance The activity.
     * @param int $attemptnumber The attempt number.
     * @param int $score Points given out of 10.
     * @param array $fields Extra recording fields.
     * @return \stdClass The recording row.
     */
    private function scored_attempt(\stdClass $instance, int $attemptnumber, int $score, array $fields = []): \stdClass {
        $rec = $this->gen->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $this->learner->id,
            'attemptnumber' => $attemptnumber,
            'timecreated' => 1000 + $attemptnumber,
        ] + $fields);
        score_manager::save_teacher_score(
            $rec,
            $instance,
            \context_module::instance($instance->cmid),
            (int) $this->teacher->id,
            0,
            [['name' => 'Overall', 'max_score' => 10, 'score' => $score, 'assessed' => true, 'feedback' => '']],
            ''
        );
        return $rec;
    }

    /**
     * The learner's grade in the gradebook, or null.
     *
     * @param \stdClass $instance The activity.
     * @return float|null
     */
    private function gradebook_grade(\stdClass $instance): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $instance->id, $this->learner->id);
        if (empty($grades->items[0]->grades[$this->learner->id])) {
            return null;
        }
        $grade = $grades->items[0]->grades[$this->learner->id]->grade;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * The activity's main grade item.
     *
     * @param \stdClass $instance The activity.
     * @return \grade_item|false
     */
    private function item(\stdClass $instance) {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'presenterai',
            'iteminstance' => $instance->id,
            'itemnumber' => 0,
            'courseid' => $this->course->id,
        ]);
    }

    /**
     * A grade of 0 never creates a grade item, even when grades are pushed.
     *
     * @return void
     */
    public function test_grade_zero_creates_no_item(): void {
        $instance = $this->activity(['grade' => 0]);
        $this->assertFalse($this->item($instance));

        $this->scored_attempt($instance, 1, 8);
        presenterai_update_grades($instance);
        presenterai_grade_item_update($instance);
        $this->assertFalse($this->item($instance));
        $this->assertSame([], presenterai_get_user_grades($instance, (int) $this->learner->id));
    }

    /**
     * A point grade is the fraction times the maximum, with the dates and grader set.
     *
     * @return void
     */
    public function test_point_grade(): void {
        $instance = $this->activity(['grade' => 50]);
        $item = $this->item($instance);
        $this->assertEquals(GRADE_TYPE_VALUE, $item->gradetype);
        $this->assertEquals(50, $item->grademax);

        $rec = $this->scored_attempt($instance, 1, 7);
        $this->assertSame(35.0, $this->gradebook_grade($instance));

        $grades = gradebook::get_user_grades($instance, (int) $this->learner->id);
        $grade = $grades[$this->learner->id];
        $this->assertSame(35.0, $grade->rawgrade);
        $this->assertSame((int) $this->teacher->id, $grade->usermodified);
        $this->assertSame((int) $rec->timecreated, $grade->datesubmitted);
        $this->assertGreaterThan(0, $grade->dategraded);
    }

    /**
     * A scale grade is the index the fraction lands on.
     *
     * @return void
     */
    public function test_scale_grade(): void {
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Not yet, Nearly, Met, Exceeded']);
        $instance = $this->activity(['grade' => -$scale->id]);
        $this->assertEquals(GRADE_TYPE_SCALE, $this->item($instance)->gradetype);
        $this->assertEquals($scale->id, $this->item($instance)->scaleid);

        $this->scored_attempt($instance, 1, 6);
        $this->assertSame(3.0, $this->gradebook_grade($instance), '0.6 of 4 items lands on the third.');
        $this->assertTrue(presenterai_scale_used($instance->id, $scale->id));
        $this->assertTrue(presenterai_scale_used_anywhere($scale->id));
        $this->assertFalse(presenterai_scale_used($instance->id, $scale->id + 1));
    }

    /**
     * Each grading method, end to end through the gradebook.
     *
     * @return void
     */
    public function test_grading_methods(): void {
        global $DB;

        $instance = $this->activity(['grade' => 100, 'gradingmethod' => 'highest']);
        $this->scored_attempt($instance, 1, 4);
        $this->scored_attempt($instance, 2, 9);
        $this->scored_attempt($instance, 3, 5);

        $expected = ['highest' => 90.0, 'latest' => 50.0, 'first' => 40.0, 'average' => 60.0];
        foreach ($expected as $method => $grade) {
            $DB->set_field('presenterai', 'gradingmethod', $method, ['id' => $instance->id]);
            $instance->gradingmethod = $method;
            presenterai_update_grades($instance);
            $this->assertSame($grade, $this->gradebook_grade($instance), $method);
        }
    }

    /**
     * An attempt whose media is gone still counts (D8); one with no score doesn't.
     *
     * @return void
     */
    public function test_media_gone_still_counts_and_unscored_does_not(): void {
        $instance = $this->activity(['grade' => 100, 'gradingmethod' => 'highest']);
        $this->scored_attempt($instance, 1, 9, [
            'storagekey' => null,
            'mediagonereason' => 'retention',
            'mediadeletedat' => time(),
        ]);
        $this->scored_attempt($instance, 2, 3);
        // A later attempt with no score row at all.
        $this->gen->create_recording(['presenteraiid' => $instance->id, 'userid' => $this->learner->id, 'attemptnumber' => 3]);

        $this->assertSame(90.0, $this->gradebook_grade($instance), 'The expired attempt is still the highest.');

        $instance->gradingmethod = 'latest';
        presenterai_update_grades($instance);
        $this->assertSame(30.0, $this->gradebook_grade($instance), 'The unscored attempt is not the latest counted.');
    }

    /**
     * A teacher row beats an AI row for the same attempt.
     *
     * @return void
     */
    public function test_teacher_beats_ai(): void {
        $instance = $this->activity(['grade' => 100]);
        $rec = $this->gen->create_recording(['presenteraiid' => $instance->id, 'userid' => $this->learner->id]);
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'rawsum' => 9, 'rawmax' => 10]);
        presenterai_update_grades($instance, $this->learner->id);
        $this->assertSame(90.0, $this->gradebook_grade($instance), 'With only an AI row, it counts.');

        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'teacher', 'rawsum' => 2, 'rawmax' => 10,
            'timecreated' => time() - 100]);
        $this->gen->create_score(['recordingid' => $rec->id, 'origin' => 'ai', 'rawsum' => 10, 'rawmax' => 10,
            'timecreated' => time() + 100]);
        presenterai_update_grades($instance, $this->learner->id);
        $this->assertSame(20.0, $this->gradebook_grade($instance));
    }

    /**
     * A gradebook override survives a new score being pushed.
     *
     * @return void
     */
    public function test_override_is_not_clobbered(): void {
        $instance = $this->activity(['grade' => 100]);
        $this->scored_attempt($instance, 1, 5);
        $this->assertSame(50.0, $this->gradebook_grade($instance));

        $item = $this->item($instance);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->learner->id]);
        $grade->set_overridden(true);
        $grade->finalgrade = 77;
        $grade->update();

        $this->scored_attempt($instance, 2, 10);
        $this->assertSame(77.0, $this->gradebook_grade($instance));
        $after = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->learner->id]);
        $this->assertEquals(100, $after->rawgrade, 'The raw grade still moves underneath.');
        $this->assertEquals(77, $after->finalgrade);
    }

    /**
     * Changing the maximum and regrading changes the pushed grade; switching to none keeps the item as none.
     *
     * @return void
     */
    public function test_regrade_after_grade_change(): void {
        global $DB;

        $instance = $this->activity(['grade' => 100]);
        $this->scored_attempt($instance, 1, 6);
        $this->assertSame(60.0, $this->gradebook_grade($instance));

        $DB->set_field('presenterai', 'grade', 20, ['id' => $instance->id]);
        $instance->grade = 20;
        presenterai_grade_item_update($instance);
        presenterai_update_grades($instance, 0, false);
        $this->assertSame(12.0, $this->gradebook_grade($instance));

        $instance->grade = 0;
        presenterai_update_grades($instance);
        $this->assertEquals(GRADE_TYPE_NONE, $this->item($instance)->gradetype, 'An existing item is switched to none.');
    }

    /**
     * One learner with nothing to count gets a null grade pushed.
     *
     * @return void
     */
    public function test_nullifnone(): void {
        global $DB;

        $instance = $this->activity(['grade' => 100]);
        $rec = $this->scored_attempt($instance, 1, 6);
        $this->assertSame(60.0, $this->gradebook_grade($instance));

        $DB->delete_records('presenterai_score', ['recordingid' => $rec->id]);
        presenterai_update_grades($instance, $this->learner->id);
        $this->assertNull($this->gradebook_grade($instance));
    }

    /**
     * Deleting the item removes it; 'reset' clears the grades.
     *
     * @return void
     */
    public function test_delete_and_reset(): void {
        $instance = $this->activity(['grade' => 100]);
        $this->scored_attempt($instance, 1, 6);
        $this->assertSame(60.0, $this->gradebook_grade($instance));

        presenterai_grade_item_update($instance, 'reset');
        $this->assertNull($this->gradebook_grade($instance));
        $this->assertNotFalse($this->item($instance));

        presenterai_grade_item_delete($instance);
        $this->assertFalse($this->item($instance));
    }

    /**
     * Deleting the activity deletes its grade item.
     *
     * @return void
     */
    public function test_delete_instance_removes_item(): void {
        $instance = $this->activity(['grade' => 100]);
        $this->assertNotFalse($this->item($instance));
        course_delete_module($instance->cmid);
        $this->assertFalse($this->item($instance));
    }

    /**
     * The three phase 2 features are declared.
     *
     * @return void
     */
    public function test_supports(): void {
        $this->assertTrue(presenterai_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(presenterai_supports(FEATURE_GRADE_OUTCOMES));
        $this->assertTrue(presenterai_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertFalse(presenterai_supports(FEATURE_ADVANCED_GRADING));
    }
}
