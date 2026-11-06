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
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/presenterai/mod_form.php');

/**
 * The 0.6.0 settings form: the presentation type first, Saylor's defaults, the grouped settings and the
 * read only rubric.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * The form's QuickForm object.
     *
     * @param \mod_presenterai_mod_form $form The form.
     * @return \MoodleQuickForm
     */
    private function quickform(\mod_presenterai_mod_form $form): \MoodleQuickForm {
        return (fn() => $this->_form)->call($form);
    }

    /**
     * The form for a new activity in a course, with its defaults loaded as modedit.php loads them.
     *
     * @param \stdClass $course The course.
     * @return \mod_presenterai_mod_form
     */
    private function new_form(\stdClass $course): \mod_presenterai_mod_form {
        global $PAGE;

        $PAGE->set_course($course);
        [, , , , $data] = prepare_new_moduleinfo_data($course, 'presenterai', 0);
        $form = new \mod_presenterai_mod_form($data, 0, null, $course);
        $form->set_data($data);
        return $form;
    }

    /**
     * A new activity: the type right after the name, the default type's length, slides and starter topics, no
     * grade, review off, recordings kept 30 days, and the rarely changed settings under one collapsed heading.
     *
     * @return void
     */
    public function test_new_activity_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $mform = $this->quickform($this->new_form($course));

        $index = $mform->_elementIndex;
        $this->assertSame($index['name'] + 1, $index['presentationtype'], 'The presentation type is not right after the name.');

        $defaults = $mform->_defaultValues;
        $this->assertSame('informative', $defaults['presentationtype']);
        $this->assertSame(180, (int) $defaults['minseconds']);
        $this->assertSame(300, (int) $defaults['maxseconds']);
        $this->assertSame(1, (int) $defaults['slidesenabled']);
        $this->assertSame('video', $defaults['mode']);
        $this->assertSame(0, (int) $defaults['grade'], 'A new activity is ungraded.');
        $this->assertSame(0, (int) $defaults['reviewbeforerelease']);
        $this->assertSame('days', $defaults['retentionmode']);
        $this->assertSame(30, (int) $defaults['retentiondaysvalue']);
        $starters = \mod_presenterai\local\presentation_types::defaults('informative')['topics'];
        $this->assertSame($starters, array_values($defaults['topictitle']));

        // Grouped under More settings, after its header and before the grade section.
        $more = $index['moresettings'];
        foreach (
            ['maxattempts', 'reviewbeforerelease', 'slidevision', 'videovision', 'visualscored', 'allowvisualoptout',
                'retentionmode', 'retentiondaysvalue', 'storedattempts'] as $name
        ) {
            $this->assertGreaterThan($more, $index[$name], $name);
            $this->assertLessThan($index['grade'], $index[$name], $name);
        }
        $this->assertFalse($mform->elementExists('speakinglevel'), 'The speaking level select is gone.');
        $this->assertFalse($mform->elementExists('ptype'), 'The ptype select is gone.');

        // The preset's criteria and levels, read only, and no edit link before the activity exists.
        $html = $mform->getElement('rubricpreview')->toHtml();
        $this->assertStringContainsString('data-previewfor="type:informative"', $html);
        $this->assertStringContainsString('data-previewfor="type:practice"', $html);
        $this->assertStringContainsString('Organization and transitions', $html);
        $this->assertStringContainsString('Proficient', $html);
        $this->assertStringContainsString(get_string('rubricpreview_savefirst', 'mod_presenterai'), $html);
        $this->assertStringContainsString('data-isnew="1"', $html, 'A new activity takes the type defaults.');
        $this->assertStringNotContainsString('rubric.php', $html);
        $this->assertStringNotContainsString('<input', $html, 'The preview is not an editor.');
    }

    /**
     * A teacher who may not set retention sees the site's value, which is what is stored for them.
     *
     * @return void
     */
    public function test_retention_default_without_the_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/presenterai:setretention', CAP_PROHIBIT, $role, \context_course::instance($course->id));
        $this->setUser($teacher);

        $mform = $this->quickform($this->new_form($course));
        $this->assertSame('site', $mform->_defaultValues['retentionmode']);
    }

    /**
     * An activity made before 0.6.0 shows its own general type, so saving the form keeps its rubric, and the
     * rubric links to the editor with a way back.
     *
     * @return void
     */
    public function test_existing_activity_keeps_its_rubric(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id, 'ptype' => 'persuasive']);
        $cm = get_coursemodule_from_id('presenterai', $instance->cmid, 0, false, MUST_EXIST);
        $PAGE->set_course($course);

        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new \mod_presenterai_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        $mform = $this->quickform($form);

        $this->assertSame('general_persuasive', $mform->_defaultValues['presentationtype']);
        $options = [];
        foreach ($mform->getElement('presentationtype')->_options as $option) {
            $options[] = $option['attr']['value'];
        }
        $this->assertContains('general_persuasive', $options);
        $this->assertNotContains('general_informative', $options);

        $html = $mform->getElement('rubricpreview')->toHtml();
        $this->assertMatchesRegularExpression('/data-previewfor="type:general_persuasive"(?![^>]*hidden)/', $html);
        $this->assertStringContainsString('Delivery &amp; Fluency', $html);
        $this->assertStringContainsString('/mod/presenterai/rubric.php?id=' . $cm->id, $html);
        $this->assertStringContainsString('returnurl=', $html);
        $this->assertStringContainsString(get_string('rubricpreview_edit', 'mod_presenterai'), $html);
        $this->assertStringContainsString('data-isnew="0"', $html, 'A type change would reset an existing activity.');

        // Saving it unchanged leaves the columns as they were.
        $saved = \mod_presenterai\local\instance_manager::normalise(
            (object) ['presentationtype' => 'general_persuasive'],
            (object) ['ptype' => 'persuasive', 'speakinglevel' => null, 'preset' => null, 'retentiondays' => -1]
        );
        $this->assertSame(['persuasive', null, null], [$saved->ptype, $saved->speakinglevel, $saved->preset]);
    }
}
