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

use mod_presenterai\local\grading_outcomes;

/**
 * Outcomes on the grading screen: listed from the gradebook, saved by hand.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\grading_outcomes
 */
final class grading_outcomes_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \stdClass A learner. */
    private \stdClass $learner;

    /**
     * A course with one activity, one learner, and outcomes switched on.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');

        $CFG->enableoutcomes = 1;
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $this->learner = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * Attach an outcome to the activity the way course/modlib.php does.
     *
     * @return int The outcome grade item's itemnumber.
     */
    private function attach_outcome(): int {
        $generator = $this->getDataGenerator();
        $scale = $generator->create_scale(['scale' => 'Not met,Partly met,Met', 'courseid' => $this->course->id]);
        $outcome = $generator->create_grade_outcome([
            'fullname' => 'Speaks clearly',
            'shortname' => 'clear',
            'scaleid' => $scale->id,
            'courseid' => $this->course->id,
        ]);

        $item = new \grade_item();
        $item->courseid = $this->course->id;
        $item->itemtype = 'mod';
        $item->itemmodule = 'presenterai';
        $item->iteminstance = $this->instance->id;
        $item->itemnumber = 1000;
        $item->itemname = $outcome->fullname;
        $item->outcomeid = $outcome->id;
        $item->gradetype = GRADE_TYPE_SCALE;
        $item->scaleid = $scale->id;
        $item->insert();

        return 1000;
    }

    /**
     * Nothing is listed while outcomes are off or none are attached.
     *
     * @return void
     */
    public function test_empty_when_off_or_none(): void {
        global $CFG;

        $this->assertSame([], grading_outcomes::for_user($this->course, $this->instance, (int) $this->learner->id));

        $this->attach_outcome();
        $CFG->enableoutcomes = 0;
        $this->assertSame([], grading_outcomes::for_user($this->course, $this->instance, (int) $this->learner->id));
    }

    /**
     * An attached outcome is listed with "No outcome" first and the scale items from 1.
     *
     * @return void
     */
    public function test_for_user_lists_attached_outcome(): void {
        $itemnumber = $this->attach_outcome();

        $outcomes = grading_outcomes::for_user($this->course, $this->instance, (int) $this->learner->id);
        $this->assertCount(1, $outcomes);
        $this->assertSame($itemnumber, $outcomes[0]['itemnumber']);
        $this->assertSame('Speaks clearly', $outcomes[0]['name']);
        $this->assertSame([
            0 => get_string('nooutcome', 'grades'),
            1 => 'Not met',
            2 => 'Partly met',
            3 => 'Met',
        ], $outcomes[0]['options']);
        $this->assertSame(0, $outcomes[0]['current']);
    }

    /**
     * Saving sets the learner's outcome grade, and 0 clears it.
     *
     * @return void
     */
    public function test_save_sets_and_clears(): void {
        $itemnumber = $this->attach_outcome();
        $userid = (int) $this->learner->id;
        $this->setAdminUser();

        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 3]);
        $this->assertSame(3, grading_outcomes::for_user($this->course, $this->instance, $userid)[0]['current']);
        $this->assertEquals(3, $this->final_grade($itemnumber, $userid));

        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 0]);
        $this->assertSame(0, grading_outcomes::for_user($this->course, $this->instance, $userid)[0]['current']);
        $this->assertNull($this->final_grade($itemnumber, $userid));
    }

    /**
     * An unchanged value isn't sent again, so the grade keeps who last set it.
     *
     * @return void
     */
    public function test_unchanged_value_is_not_resent(): void {
        $itemnumber = $this->attach_outcome();
        $userid = (int) $this->learner->id;
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        $this->setUser($first);
        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 2]);
        $this->assertSame((int) $first->id, $this->grade_modifier($itemnumber, $userid));

        $this->setUser($second);
        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 2]);
        $this->assertSame(
            (int) $first->id,
            $this->grade_modifier($itemnumber, $userid),
            'An unchanged outcome was written again (mod_assign compares before sending).'
        );

        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 1]);
        $this->assertSame((int) $second->id, $this->grade_modifier($itemnumber, $userid));
    }

    /**
     * A value outside the outcome's scale is ignored rather than stored.
     *
     * @return void
     */
    public function test_value_outside_scale_is_ignored(): void {
        $itemnumber = $this->attach_outcome();
        $userid = (int) $this->learner->id;
        $this->setAdminUser();

        grading_outcomes::save($this->course, $this->instance, $userid, [$itemnumber => 9, 1001 => 1]);
        $this->assertSame(0, grading_outcomes::for_user($this->course, $this->instance, $userid)[0]['current']);
    }

    /**
     * The stored final grade of an outcome item for a user.
     *
     * @param int $itemnumber The outcome item's number.
     * @param int $userid The learner.
     * @return float|null
     */
    private function final_grade(int $itemnumber, int $userid): ?float {
        $grade = $this->grade_row($itemnumber, $userid);
        return ($grade && $grade->finalgrade !== null) ? (float) $grade->finalgrade : null;
    }

    /**
     * Who last modified an outcome grade.
     *
     * @param int $itemnumber The outcome item's number.
     * @param int $userid The learner.
     * @return int
     */
    private function grade_modifier(int $itemnumber, int $userid): int {
        return (int) $this->grade_row($itemnumber, $userid)->usermodified;
    }

    /**
     * The grade_grades row of an outcome item for a user.
     *
     * @param int $itemnumber The outcome item's number.
     * @param int $userid The learner.
     * @return \stdClass|null
     */
    private function grade_row(int $itemnumber, int $userid): ?\stdClass {
        global $DB;

        $itemid = $DB->get_field('grade_items', 'id', [
            'itemtype' => 'mod',
            'itemmodule' => 'presenterai',
            'iteminstance' => $this->instance->id,
            'itemnumber' => $itemnumber,
        ], MUST_EXIST);

        return $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid]) ?: null;
    }
}
