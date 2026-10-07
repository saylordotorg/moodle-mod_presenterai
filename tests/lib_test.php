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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/presenterai/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/presenterai/mod_form.php');

/**
 * The lib.php callbacks phase 2 adds, and the settings form they rely on.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::presenterai_get_coursemodule_info
 * @covers     ::presenterai_scale_used
 * @covers     ::presenterai_scale_used_anywhere
 * @covers     \mod_presenterai_mod_form
 */
final class lib_test extends \advanced_testcase {
    /**
     * Custom rules reach customdata only with automatic completion, and the name and intro are cached.
     *
     * @return void
     */
    public function test_get_coursemodule_info(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $course->id,
            'name' => 'Pitch practice',
            'intro' => '<p>Say it well.</p>',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionminscore' => 65,
            'showdescription' => 1,
        ]);
        $cm = $DB->get_record('course_modules', ['id' => $instance->cmid]);

        $info = presenterai_get_coursemodule_info($cm);
        $this->assertInstanceOf(\cached_cm_info::class, $info);
        $this->assertSame('Pitch practice', $info->name);
        $this->assertStringContainsString('Say it well.', $info->content);
        $this->assertSame(
            ['completionsubmit' => 1, 'completionminscore' => 65],
            $info->customdata['customcompletionrules']
        );

        $cm->completion = COMPLETION_TRACKING_MANUAL;
        $cm->showdescription = 0;
        $info = presenterai_get_coursemodule_info($cm);
        $this->assertTrue(empty($info->customdata['customcompletionrules']));
        $this->assertTrue(empty($info->content));

        $cm->instance = $instance->id + 1000;
        $this->assertNull(presenterai_get_coursemodule_info($cm));
    }

    /**
     * A scale is in use by the activity that grades with it, and only that one.
     *
     * @return void
     */
    public function test_scale_used(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $scale = $this->getDataGenerator()->create_scale();
        $unused = $this->getDataGenerator()->create_scale();
        $graded = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id, 'grade' => -$scale->id]);
        $points = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id, 'grade' => 10]);

        $this->assertTrue(presenterai_scale_used($graded->id, $scale->id));
        $this->assertFalse(presenterai_scale_used($points->id, $scale->id));
        $this->assertFalse(presenterai_scale_used($graded->id, $unused->id));
        $this->assertTrue(presenterai_scale_used_anywhere($scale->id));
        $this->assertFalse(presenterai_scale_used_anywhere($unused->id));
        $this->assertFalse(presenterai_scale_used_anywhere(0));
    }

    /**
     * The settings form offers the grade, the grading method and both completion rules.
     *
     * @return void
     */
    public function test_form_has_grade_and_completion_elements(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $PAGE->set_course($course);
        [, , , , $data] = $this->prepare_new($course);
        $form = new \mod_presenterai_mod_form($data, 0, null, $course);
        $mform = (fn() => $this->_form)->call($form);

        foreach (['grade', 'gradingmethod', 'completionsubmitgroup', 'completionminscoregroup'] as $name) {
            $this->assertTrue($mform->elementExists($name), "Missing element {$name}.");
        }
        $this->assertSame('highest', $mform->getElement('gradingmethod')->getValue()[0]);

        $this->assertFalse($form->completion_rule_enabled(['completionsubmitenabled' => 1, 'completionsubmit' => 0]));
        $this->assertFalse($form->completion_rule_enabled(['completionsubmitenabled' => 0, 'completionsubmit' => 2]));
        $this->assertTrue($form->completion_rule_enabled(['completionminscoreenabled' => 1, 'completionminscore' => 40]));

        $submitted = (object) [
            'completionunlocked' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmitenabled' => 0,
            'completionsubmit' => 3,
            'completionminscoreenabled' => 1,
            'completionminscore' => 40,
        ];
        $form->data_postprocessing($submitted);
        $this->assertSame(0, $submitted->completionsubmit, 'An unticked rule is zeroed.');
        $this->assertSame(40, $submitted->completionminscore);

        $submitted->completion = COMPLETION_TRACKING_MANUAL;
        $form->data_postprocessing($submitted);
        $this->assertSame(0, $submitted->completionminscore, 'Manual completion zeroes every rule.');
    }

    /**
     * prepare_new_moduleinfo_data() for a PresenterAI in section 0.
     *
     * @param \stdClass $course The course.
     * @return array As prepare_new_moduleinfo_data() returns.
     */
    private function prepare_new(\stdClass $course): array {
        return prepare_new_moduleinfo_data($course, 'presenterai', 0);
    }
}
