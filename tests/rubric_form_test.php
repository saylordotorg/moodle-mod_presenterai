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

use mod_presenterai\form\rubric_form;
use mod_presenterai\local\rubric_manager;

/**
 * The rubric editor form: validation, the visual flag's round trip, and presets.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\form\rubric_form
 */
final class rubric_form_test extends \advanced_testcase {
    /**
     * Build the form.
     *
     * @param int $rows Rows to show.
     * @param string $fixedtype The type of an existing rubric, or ''.
     * @return rubric_form
     */
    private function form(int $rows = 3, string $fixedtype = ''): rubric_form {
        return new rubric_form(new \moodle_url('/mod/presenterai/rubric.php'), [
            'rows' => $rows,
            'fixedtype' => $fixedtype,
            'hidden' => ['id' => 3, 'action' => 'edit', 'rubricid' => 0, 'level' => 'module'],
        ]);
    }

    /**
     * A valid submission, as the browser sends it.
     *
     * @param array $overrides Changes.
     * @return array
     */
    private function submission(array $overrides = []): array {
        return $overrides + [
            'id' => 3,
            'action' => 'edit',
            'rubricid' => 0,
            'level' => 'module',
            'title' => 'Capstone',
            'type' => 'video',
            'active' => 1,
            'criteria_repeats' => 3,
            'criterionname' => ['Content', 'Gestures', ''],
            'criteriondesc' => ['What you said.', 'Hands and posture.', ''],
            'criterionmax' => [6, 4, 5],
            'criterionvisual' => [0, 1, 0],
        ];
    }

    /**
     * The visual flag survives a submission, and a stored rubric fills the form back the same way.
     *
     * @return void
     */
    public function test_visual_flag_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        rubric_form::mock_submit($this->submission());
        $form = $this->form();
        $data = $form->get_data();
        $this->assertNotNull($data, 'A valid rubric was refused.');
        $criteria = $form->to_criteria($data);
        $this->assertSame([
            ['name' => 'Content', 'description' => 'What you said.', 'max_score' => 6, 'visual' => false],
            ['name' => 'Gestures', 'description' => 'Hands and posture.', 'max_score' => 4, 'visual' => true],
        ], $criteria);
        $this->assertSame('video', $form->to_type($data));

        // Stored and read back through the manager, then into a fresh form.
        $course = $this->getDataGenerator()->create_course();
        $ctx = \context_course::instance($course->id);
        $id = rubric_manager::create((int) $ctx->id, 'video', $data->title, $criteria);
        $row = $this->get_rubric($id);
        $this->assertSame($criteria, rubric_manager::normalise_criteria((string) $row->criteria));

        $_POST = [];
        $edit = $this->form(rubric_form::rows_for($criteria), 'video');
        $edit->set_rubric($row->title, $row->type, true, rubric_manager::normalise_criteria((string) $row->criteria));
        $html = $edit->render();
        $this->assertMatchesRegularExpression('/name="criterionvisual\[1\]"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="criterionvisual\[0\]"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="criterionname\[1\]"[^>]*value="Gestures"/', $html);
    }

    /**
     * A speech rubric refuses a visual criterion.
     *
     * @return void
     */
    public function test_speech_refuses_visual(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form();

        $errors = $form->validation($this->submission(['type' => 'speech']), []);
        $this->assertSame(get_string('rubricerror_visualspeech', 'mod_presenterai'), $errors['criterionvisual[1]']);

        $errors = $form->validation($this->submission(['type' => 'speech', 'criterionvisual' => [0, 0, 0]]), []);
        $this->assertSame([], $errors);

        // An existing speech rubric's fixed type wins over a tampered field.
        $errors = $this->form(3, 'speech')->validation($this->submission(['type' => 'video']), []);
        $this->assertArrayHasKey('criterionvisual[1]', $errors);
    }

    /**
     * At least one criterion, unique names, a name for every described row, a known type.
     *
     * @return void
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form();

        $errors = $form->validation($this->submission(['criterionname' => ['', '', ''], 'criteriondesc' => ['', '', '']]), []);
        $this->assertSame(get_string('rubricerror_nocriteria', 'mod_presenterai'), $errors['criterionname[0]']);

        $errors = $form->validation($this->submission(['criterionname' => ['Content', ' content ', '']]), []);
        $this->assertSame(get_string('rubricerror_duplicate', 'mod_presenterai'), $errors['criterionname[1]']);

        $errors = $form->validation($this->submission(['criteriondesc' => ['a', 'b', 'Orphan description.']]), []);
        $this->assertSame(get_string('rubricerror_noname', 'mod_presenterai'), $errors['criterionname[2]']);

        $errors = $form->validation($this->submission(['type' => '']), []);
        $this->assertSame(get_string('rubricerror_type', 'mod_presenterai'), $errors['type']);

        $errors = $form->validation($this->submission(['criterionmax' => [11, 0, 5]]), []);
        $this->assertArrayHasKey('criterionmax[0]', $errors);
        $this->assertArrayHasKey('criterionmax[1]', $errors);
    }

    /**
     * The type select is required and starts on an empty option; the preset button doesn't submit.
     *
     * @return void
     */
    public function test_type_select_and_preset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $this->form()->render();

        $this->assertSame(1, preg_match('/<select[^>]*name="type"[^>]*>(.*?)<\/select>/s', $html, $m));
        preg_match_all('/<option value="([^"]*)"/', $m[1], $values);
        $this->assertSame(['', 'video', 'speech'], $values[1]);
        $this->assertStringContainsString('name="applypreset"', $html);
        $this->assertSame(1, preg_match('/<select[^>]*name="preset"[^>]*>(.*?)<\/select>/s', $html, $m));
        preg_match_all('/<option value="([^"]*)"/', $m[1], $values);
        $this->assertSame(['', 'general', 'esl_beginner', 'esl_intermediate', 'esl_advanced'], $values[1]);

        // A preset's rows fill the form; a video rubric gets the two visual criteria.
        $criteria = rubric_manager::preset_criteria('esl_beginner', true);
        $form = $this->form(rubric_form::rows_for($criteria));
        $form->set_rubric('', 'video', true, $criteria);
        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="criterionname\[0\]"[^>]*value="Pronunciation &amp; Intelligibility"/', $html);
        $this->assertMatchesRegularExpression('/name="criterionname\[6\]"[^>]*value="Eye Contact &amp; Camera Presence"/', $html);
        $this->assertMatchesRegularExpression('/name="criterionvisual\[6\]"[^>]*checked/', $html);
        $this->assertSame(8, rubric_form::rows_for($criteria));
        $this->assertSame(rubric_form::NEW_ROWS, rubric_form::rows_for([]));
    }

    /**
     * A visual tick on a speech submission never reaches the stored criteria.
     *
     * @return void
     */
    public function test_to_criteria_drops_visual_on_speech(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form(3, 'speech');
        $criteria = $form->to_criteria((object) $this->submission(['type' => 'speech']));
        $this->assertSame([false, false], array_column($criteria, 'visual'));
        $this->assertSame(['Content', 'Gestures'], array_column($criteria, 'name'));
    }

    /**
     * One rubric row.
     *
     * @param int $id The rubric id.
     * @return \stdClass
     */
    private function get_rubric(int $id): \stdClass {
        global $DB;
        return $DB->get_record('presenterai_rubric', ['id' => $id], '*', MUST_EXIST);
    }
}
