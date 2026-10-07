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

use mod_presenterai\local\rubric_manager;
use mod_presenterai\output\rubrics_page;

/**
 * The rubric list and who may change which rubric.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\rubrics_page
 */
final class rubrics_page_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \stdClass An editing teacher. */
    private \stdClass $editor;

    /** @var \stdClass A non-editing teacher. */
    private \stdClass $teacher;

    /** @var \stdClass A learner. */
    private \stdClass $learner;

    /**
     * One activity, with an editing teacher, a non-editing teacher and a learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $instance = $gen->create_module('presenterai', ['course' => $this->course->id]);
        $this->ctx = \context_module::instance($instance->cmid);
        $this->editor = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->teacher = $gen->create_and_enrol($this->course, 'teacher');
        $this->learner = $gen->create_and_enrol($this->course, 'student');
    }

    /**
     * A one criterion rubric in a context.
     *
     * @param \context $ctx The context.
     * @param string $title Its title.
     * @return int The rubric id.
     */
    private function rubric(\context $ctx, string $title): int {
        return rubric_manager::create((int) $ctx->id, 'speech', $title, [['name' => 'Pace', 'max_score' => 5]]);
    }

    /**
     * Creating a rubric needs managerubrics in the context it will belong to.
     *
     * @return void
     */
    public function test_target_context_capability(): void {
        global $DB;

        $this->assertSame(
            (int) $this->ctx->id,
            (int) rubrics_page::target_context($this->ctx, rubrics_page::LEVEL_MODULE, (int) $this->editor->id)->id
        );
        $this->assertSame(
            (int) $this->ctx->get_course_context()->id,
            (int) rubrics_page::target_context($this->ctx, rubrics_page::LEVEL_COURSE, (int) $this->editor->id)->id
        );

        foreach ([$this->teacher, $this->learner] as $user) {
            try {
                rubrics_page::target_context($this->ctx, rubrics_page::LEVEL_MODULE, (int) $user->id);
                $this->fail('A user without managerubrics could create a rubric.');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }

        // Allowed on the activity only: the course level stays closed.
        $role = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/presenterai:managerubrics', CAP_ALLOW, $role, $this->ctx->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        rubrics_page::target_context($this->ctx, rubrics_page::LEVEL_MODULE, (int) $this->teacher->id);
        $this->expectException(\required_capability_exception::class);
        rubrics_page::target_context($this->ctx, rubrics_page::LEVEL_COURSE, (int) $this->teacher->id);
    }

    /**
     * Only this activity's and this course's rubrics are editable here, and an id from elsewhere is refused.
     *
     * @return void
     */
    public function test_require_editable_refuses_other_contexts(): void {
        $mine = $this->rubric($this->ctx, 'Mine');
        $course = $this->rubric($this->ctx->get_course_context(), 'Course');
        $category = $this->rubric(\context_coursecat::instance($this->course->category), 'Category');
        $other = $this->getDataGenerator()->create_course();
        $othermodule = $this->getDataGenerator()->create_module('presenterai', ['course' => $other->id]);
        $elsewhere = $this->rubric(\context_module::instance($othermodule->cmid), 'Elsewhere');

        $editor = (int) $this->editor->id;
        $this->assertSame($mine, (int) rubrics_page::require_editable($mine, $this->ctx, $editor)->id);
        $this->assertSame($course, (int) rubrics_page::require_editable($course, $this->ctx, $editor)->id);

        foreach ([$category, $elsewhere, 999999] as $id) {
            try {
                rubrics_page::require_editable($id, $this->ctx, $editor);
                $this->fail("Rubric {$id} was editable from an activity it doesn't belong to.");
            } catch (\moodle_exception $e) {
                $this->assertSame('rubricerror_notfound', $e->errorcode);
            }
        }

        try {
            rubrics_page::require_editable($mine, $this->ctx, (int) $this->teacher->id);
            $this->fail('A non-editing teacher could edit a rubric.');
        } catch (\moodle_exception $e) {
            $this->assertSame('rubricerror_notfound', $e->errorcode);
        }
    }

    /**
     * Deleting needs the sesskey as well as the capability.
     *
     * @return void
     */
    public function test_delete_needs_sesskey(): void {
        global $DB;

        $this->setUser($this->editor);
        $id = $this->rubric($this->ctx, 'Mine');

        try {
            rubrics_page::delete($id, $this->ctx, (int) $this->editor->id);
            $this->fail('A rubric was deleted without the sesskey.');
        } catch (\moodle_exception $e) {
            $this->assertSame('missingparam', $e->errorcode);
        }
        try {
            $_POST['sesskey'] = 'wrong';
            rubrics_page::delete($id, $this->ctx, (int) $this->editor->id);
            $this->fail('A rubric was deleted with a wrong sesskey.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('presenterai_rubric', ['id' => $id]));

        try {
            $this->setUser($this->teacher);
            $_POST['sesskey'] = sesskey();
            try {
                rubrics_page::delete($id, $this->ctx, (int) $this->teacher->id);
                $this->fail('A non-editing teacher deleted a rubric.');
            } catch (\moodle_exception $e) {
                $this->assertSame('rubricerror_notfound', $e->errorcode);
            }

            $this->setUser($this->editor);
            $_POST['sesskey'] = sesskey();
            rubrics_page::delete($id, $this->ctx, (int) $this->editor->id);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertFalse($DB->record_exists('presenterai_rubric', ['id' => $id]));
    }

    /**
     * The list shows every visible rubric nearest first, with edit links only where allowed.
     *
     * @return void
     */
    public function test_export(): void {
        global $PAGE;

        $this->rubric(\context_coursecat::instance($this->course->category), 'Category & co');
        $this->rubric($this->ctx->get_course_context(), 'Course');
        $this->rubric($this->ctx, 'Mine');

        $data = (new rubrics_page($this->ctx, (int) $this->editor->id))->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($data['hasrubrics']);
        $this->assertSame(['Mine', 'Course', 'Category &amp; co'], array_column($data['rows'], 'title'));
        $this->assertSame(
            get_string('rubricedit_aria', 'mod_presenterai', 'Category & co'),
            $data['rows'][2]['editaria'],
            'The aria label is plain text; the template escapes it once.'
        );
        $this->assertSame([true, true, false], array_column($data['rows'], 'editable'));
        $this->assertSame(get_string('rubriclevel_activity', 'mod_presenterai'), $data['rows'][0]['where']);
        $this->assertSame(get_string('rubriclevel_course', 'mod_presenterai'), $data['rows'][1]['where']);
        $this->assertSame('', $data['rows'][2]['editurl']);
        $this->assertTrue($data['canaddmodule']);
        $this->assertTrue($data['canaddcourse']);
        $this->assertStringContainsString('action=delete', $data['rows'][0]['deleteurl']);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/rubrics', $data);
        $this->assertStringContainsString('aria-label="' . get_string('rubricedit_aria', 'mod_presenterai', 'Mine') . '"', $html);
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('<th scope="row">Category &amp; co</th>', $html);

        $data = (new rubrics_page($this->ctx, (int) $this->teacher->id))->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame([false, false, false], array_column($data['rows'], 'editable'));
        $this->assertFalse($data['canaddmodule']);
        $this->assertFalse($data['canaddcourse']);
    }

    /**
     * With nothing defined the page says what the activity uses instead.
     *
     * @return void
     */
    public function test_empty_list(): void {
        global $PAGE;

        $data = (new rubrics_page($this->ctx, (int) $this->editor->id))->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data['hasrubrics']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/rubrics', $data);
        $this->assertStringContainsString(get_string('rubricnone', 'mod_presenterai'), $html);
    }
}
