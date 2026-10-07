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

use mod_presenterai\local\course_reset;

/**
 * Course reset: learner work goes, the activity's configuration stays.
 *
 * Runs through core's reset_course_userdata(), which is how a teacher's reset
 * form reaches this module, so the lib.php wrappers are exercised too.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\course_reset
 */
final class course_reset_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A graded activity. */
    private \stdClass $graded;

    /** @var \stdClass An activity with grade 0, so no grade item. */
    private \stdClass $ungraded;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /**
     * Load the libraries the reset and the gradebook need.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/presenterai/lib.php');
    }

    /**
     * One course, two activities, one learner with work in both.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->graded = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'grade', 100, ['id' => $this->graded->id]);
        $this->ungraded = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $this->alice = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * A recording with a real file, a score and a spend row.
     *
     * @param \stdClass $instance The activity.
     * @return \stdClass The recording.
     */
    private function work(\stdClass $instance): \stdClass {
        global $DB;

        $context = \context_module::instance($instance->cmid);
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $this->alice->id,
            'storagekey' => random_string(32) . '.webm',
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_presenterai',
            'filearea' => 'recording',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $rec->storagekey,
        ], 'video');
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id,
            'userid' => $this->alice->id,
            'origin' => 'teacher',
            'scores' => '[]',
            'rawsum' => 4,
            'rawmax' => 5,
            'overallpct' => 80,
            'timecreated' => time(),
        ]);
        $DB->insert_record('presenterai_aiusage', (object) [
            'presenteraiid' => $instance->id,
            'recordingid' => $rec->id,
            'userid' => $this->alice->id,
            'action' => 'score',
            'estmicrocents' => 500,
            'timecreated' => time(),
        ]);

        return $rec;
    }

    /**
     * The grade item for an activity, or false.
     *
     * @param \stdClass $instance The activity.
     * @return \grade_item|false
     */
    private function grade_item(\stdClass $instance) {
        return \grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'presenterai',
            'iteminstance' => $instance->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * Reset with the PresenterAI box ticked removes all learner work and its grades, and keeps the rest.
     *
     * @return void
     */
    public function test_reset_removes_learner_work_and_keeps_configuration(): void {
        global $DB;

        $this->setAdminUser();

        // A grade item and a grade, written through core directly.
        grade_update('mod/presenterai', $this->course->id, 'mod', 'presenterai', $this->graded->id, 0, null, [
            'itemname' => 'Graded', 'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0,
        ]);
        $grade = ['userid' => $this->alice->id, 'rawgrade' => 80];
        grade_update('mod/presenterai', $this->course->id, 'mod', 'presenterai', $this->graded->id, 0, $grade);
        $item = $this->grade_item($this->graded);
        $this->assertNotFalse($item);
        $this->assertNotFalse(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->alice->id]));

        $recs = [$this->work($this->graded), $this->work($this->ungraded)];
        $topic = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_topic([
            'presenteraiid' => $this->graded->id,
        ]);
        $rubricid = $DB->insert_record('presenterai_rubric', (object) [
            'contextid' => \context_module::instance($this->graded->cmid)->id,
            'type' => 'speech',
            'title' => 'Kept',
            'criteria' => '[]',
        ]);

        $status = reset_course_userdata((object) [
            'id' => $this->course->id,
            'reset_presenterai_recordings' => 1,
        ]);
        $this->resetDebugging();

        $this->assertSame(0, $DB->count_records('presenterai_recording'));
        $this->assertSame(0, $DB->count_records('presenterai_score'));
        foreach ($recs as $rec) {
            $this->assertSame(0, $DB->count_records_select(
                'files',
                "component = 'mod_presenterai' AND filearea = 'recording' AND itemid = :id AND filename <> '.'",
                ['id' => $rec->id]
            ));
        }
        $usage = $DB->get_records('presenterai_aiusage');
        $this->assertCount(2, $usage);
        foreach ($usage as $row) {
            $this->assertSame(0, (int) $row->userid);
            $this->assertSame(0, (int) $row->recordingid);
        }
        $this->assertTrue($DB->record_exists('presenterai_topic', ['id' => $topic->id]));
        $this->assertTrue($DB->record_exists('presenterai_rubric', ['id' => $rubricid]));

        // The grade is gone, the item is kept, and grade 0 still has no item.
        $this->assertNotFalse($this->grade_item($this->graded));
        $this->assertFalse(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->alice->id]));
        $this->assertFalse($this->grade_item($this->ungraded));

        $components = array_column($status, 'component');
        $this->assertContains(get_string('modulenameplural', 'mod_presenterai'), $components);
    }

    /**
     * With the box unticked nothing of PresenterAI's is touched.
     *
     * @return void
     */
    public function test_reset_without_the_box_keeps_everything(): void {
        global $DB;

        $this->setAdminUser();
        $this->work($this->graded);

        reset_course_userdata((object) ['id' => $this->course->id, 'reset_presenterai_recordings' => 0]);

        $this->assertSame(1, $DB->count_records('presenterai_recording'));
        $this->assertSame(1, $DB->count_records('presenterai_score'));
    }

    /**
     * reset_gradebook never creates an item, even for a graded activity that has none yet.
     *
     * @return void
     */
    public function test_reset_gradebook_creates_no_item(): void {
        course_reset::reset_gradebook((int) $this->course->id);

        $this->assertFalse($this->grade_item($this->graded));
        $this->assertFalse($this->grade_item($this->ungraded));
    }

    /**
     * The form defaults to removing learner work, as core activities do.
     *
     * @return void
     */
    public function test_form_defaults(): void {
        $this->assertSame(['reset_presenterai_recordings' => 1], presenterai_reset_course_form_defaults($this->course));
    }
}
