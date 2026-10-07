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

/**
 * The scoring form: validation, prefill and the criteria it hands the saver.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\form\grade_form
 */
final class grade_form_test extends \advanced_testcase {
    /**
     * A two criterion rubric, as rubric_manager::resolve() returns it.
     *
     * @return array
     */
    private function criteria(): array {
        return [
            ['name' => 'Content', 'description' => 'What you said.', 'max_score' => 5, 'visual' => false],
            ['name' => 'Delivery', 'description' => 'How you said it.', 'max_score' => 4, 'visual' => false],
        ];
    }

    /**
     * Build the form with the test rubric.
     *
     * @param array $outcomes A grading_outcomes::for_user() result.
     * @return grade_form
     */
    private function form(array $outcomes = []): grade_form {
        return new grade_form(new \moodle_url('/mod/presenterai/grade.php'), [
            'cmid' => 3,
            'recordingid' => 9,
            'criteria' => $this->criteria(),
            'outcomes' => $outcomes,
        ]);
    }

    /**
     * An assessed criterion needs a score in range; an unassessed one needs none.
     *
     * @return void
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form();

        $errors = $form->validation(['assessed' => [1, 1], 'score' => ['', '3']], []);
        $this->assertArrayHasKey('score[0]', $errors);
        $this->assertArrayNotHasKey('score[1]', $errors);
        $this->assertSame(get_string('grade_scorerequired', 'mod_presenterai'), $errors['score[0]']);

        $errors = $form->validation(['assessed' => [0, 1], 'score' => ['', '4']], []);
        $this->assertSame([], $errors, 'An unassessed criterion was asked for a score.');

        $errors = $form->validation(['assessed' => [1, 1], 'score' => ['6', '-1']], []);
        $this->assertArrayHasKey('score[0]', $errors, 'A score above the maximum passed.');
        $this->assertArrayHasKey('score[1]', $errors, 'A negative score passed.');

        $errors = $form->validation(['assessed' => [1, 1], 'score' => ['0', '4']], []);
        $this->assertSame([], $errors, 'Zero and the maximum are both valid scores.');
    }

    /**
     * Names and maxima come from the rubric, never from the POST.
     *
     * @return void
     */
    public function test_to_criteria_uses_the_rubric(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form();

        $data = (object) [
            'assessed' => [1, 0],
            'score' => ['4', '3'],
            'criterionfeedback' => [' Clear thesis. ', 'ignored'],
            // A tampered request trying to change the rubric.
            'name' => ['Hacked', 'Hacked'],
            'max_score' => [100, 100],
        ];
        $criteria = $form->to_criteria($data);

        $this->assertSame([
            ['name' => 'Content', 'max_score' => 5, 'score' => 4, 'assessed' => true, 'feedback' => 'Clear thesis.'],
            ['name' => 'Delivery', 'max_score' => 4, 'score' => null, 'assessed' => false, 'feedback' => 'ignored'],
        ], $criteria);
    }

    /**
     * A real submission round trips through get_data(), and an invalid one is refused.
     *
     * @return void
     */
    public function test_submission_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        grade_form::mock_submit([
            'id' => 3,
            'recordingid' => 9,
            'assessed' => [1, 0],
            // A disabled select isn't submitted by the browser.
            'score' => [0 => '5'],
            'criterionfeedback' => ['Strong opening.', ''],
            'feedback' => 'Good work.',
        ]);
        $form = $this->form();
        $data = $form->get_data();
        $this->assertNotNull($data, 'A valid submission was refused.');
        $this->assertSame('Good work.', $data->feedback);
        $criteria = $form->to_criteria($data);
        $this->assertSame(5, $criteria[0]['score']);
        $this->assertTrue($criteria[0]['assessed']);
        $this->assertNull($criteria[1]['score']);
        $this->assertFalse($criteria[1]['assessed']);

        grade_form::mock_submit([
            'id' => 3,
            'recordingid' => 9,
            'assessed' => [1, 1],
            'score' => [0 => '5', 1 => ''],
            'criterionfeedback' => ['', ''],
            'feedback' => '',
        ]);
        $this->assertNull($this->form()->get_data(), 'An assessed criterion without a score was accepted.');
    }

    /**
     * The outcome values are read from the outcome_<itemnumber> fields.
     *
     * @return void
     */
    public function test_to_outcomes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form([
            ['itemnumber' => 1000, 'name' => 'Clear', 'options' => [0 => 'No outcome', 1 => 'Met'], 'current' => 0],
        ]);

        $this->assertSame([1000 => 1], $form->to_outcomes((object) ['outcome_1000' => '1', 'outcome_1001' => 1]));
    }

    /**
     * The prefill reads the current score, and a teacher row beats a newer AI row.
     *
     * @return void
     */
    public function test_prefill_from_current_teacher_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('presenterai', ['course' => $course->id]);
        $learner = $generator->create_and_enrol($course, 'student');
        $rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'storagekey' => 'a.webm',
        ]);

        $now = time();
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $learner->id, 'rubricid' => 0, 'origin' => 'teacher',
            'scores' => json_encode([
                ['name' => 'Delivery', 'score' => null, 'max_score' => 4, 'feedback' => '', 'assessed' => false],
                ['name' => 'Content', 'score' => 2, 'max_score' => 5, 'feedback' => 'Teacher note.', 'assessed' => true],
            ]),
            'rawsum' => 2, 'rawmax' => 5, 'overallpct' => 40.00, 'feedback' => 'Teacher overall.',
            'graderid' => 2, 'timecreated' => $now - 100,
        ]);
        // A newer AI row must not win over the teacher row.
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $learner->id, 'rubricid' => 0, 'origin' => 'ai',
            'scores' => json_encode([
                ['name' => 'Content', 'score' => 5, 'max_score' => 5, 'feedback' => 'AI note.', 'assessed' => true],
                ['name' => 'Delivery', 'score' => 4, 'max_score' => 4, 'feedback' => 'AI note.', 'assessed' => true],
            ]),
            'rawsum' => 9, 'rawmax' => 9, 'overallpct' => 100.00, 'feedback' => 'AI overall.',
            'graderid' => 0, 'timecreated' => $now,
        ]);

        $form = $this->form();
        $form->set_prefill(\mod_presenterai\local\score_manager::current_score((int) $rec->id));
        $html = $form->render();

        // Content (index 0) is matched by name although it is second in the JSON.
        $this->assertMatchesRegularExpression('/<select[^>]*name="score\[0\]"[^>]*>.*?<option value="2" selected/s', $html);
        $this->assertStringContainsString('Teacher note.', $html);
        $this->assertStringContainsString('Teacher overall.', $html);
        $this->assertStringNotContainsString('AI overall.', $html);
        $this->assertStringNotContainsString('AI note.', $html);
        // Delivery was not assessed, so its box is unticked.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="assessed\[1\]"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="assessed\[0\]"[^>]*checked/', $html);
    }

    /**
     * The score select starts on an empty choice and offers 0 to the maximum.
     *
     * @return void
     */
    public function test_score_select_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $this->form()->render();

        $this->assertSame(1, preg_match('/<select[^>]*name="score\[1\]"[^>]*>(.*?)<\/select>/s', $html, $m));
        preg_match_all('/<option value="([^"]*)"/', $m[1], $values);
        $this->assertSame(['', '0', '1', '2', '3', '4'], $values[1]);
        $this->assertMatchesRegularExpression('/<select[^>]*name="score\[1\]"[^>]*data-criterion="1"/', $html);
    }

    /**
     * A feedback only criterion is labeled and left out of the live total's inputs (D23).
     *
     * @return void
     */
    public function test_feedback_only_criterion(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $criteria = $this->criteria();
        $criteria[0]['counts'] = true;
        $criteria[] = ['name' => 'Body Language & Gestures', 'description' => 'Hands.', 'max_score' => 5, 'visual' => true,
            'counts' => false];
        $form = new grade_form(new \moodle_url('/mod/presenterai/grade.php'), [
            'cmid' => 3,
            'recordingid' => 9,
            'criteria' => $criteria,
            'outcomes' => [],
        ]);
        $html = $form->render();

        $this->assertMatchesRegularExpression('/<select[^>]*name="score\[0\]"[^>]*data-counts="1"/', $html);
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="score\[1\]"[^>]*data-counts="1"/',
            $html,
            'An absent flag counts.'
        );
        $this->assertMatchesRegularExpression('/<select[^>]*name="score\[2\]"[^>]*data-counts="0"/', $html);
        $this->assertSame(1, substr_count($html, get_string('grade_feedbackonly', 'mod_presenterai')));
    }
}
