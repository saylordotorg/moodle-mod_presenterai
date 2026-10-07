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

namespace mod_presenterai\output;

use mod_presenterai\local\rubric_manager;

/**
 * The rubric list on rubric.php, and the access rules for editing a rubric.
 *
 * Lists every rubric the activity can see: its own, its course's and those of
 * the categories and site above, nearest first, because that is the order
 * rubric_manager::resolve() walks. A rubric can be edited or deleted here only
 * when it belongs to this activity, or to this course and the user holds
 * mod/presenterai:managerubrics in the course context. Rubrics further out
 * are shown read only, so a teacher can see what automatic resolution would
 * pick without being able to change a rubric other courses use.
 *
 * A rubric id in a request is only a claim until require_editable() has
 * checked it against those two contexts. A missing rubric and a forbidden one
 * raise the same error, so the response doesn't reveal which ids exist.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rubrics_page implements \renderable, \templatable {
    /** @var string Rubrics defined in the activity's own context. */
    public const LEVEL_MODULE = 'module';

    /** @var string Rubrics defined in the course context. */
    public const LEVEL_COURSE = 'course';

    /** @var string The capability that edits rubrics. */
    private const CAPABILITY = 'mod/presenterai:managerubrics';

    /** @var \context_module The activity's context. */
    private \context_module $context;

    /** @var int The user viewing the page. */
    private int $userid;

    /**
     * Build the page.
     *
     * @param \context_module $ctx The activity's context.
     * @param int $userid The user viewing the page.
     */
    public function __construct(\context_module $ctx, int $userid) {
        $this->context = $ctx;
        $this->userid = $userid;
    }

    /**
     * The context a new rubric at this level is created in, checking the user may create one there.
     *
     * @param \context_module $ctx The activity's context.
     * @param string $level LEVEL_MODULE or LEVEL_COURSE.
     * @param int $userid The user.
     * @return \context The module or course context.
     */
    public static function target_context(\context_module $ctx, string $level, int $userid): \context {
        $target = $level === self::LEVEL_COURSE ? $ctx->get_course_context() : $ctx;
        require_capability(self::CAPABILITY, $target, $userid);
        return $target;
    }

    /**
     * Whether the user may edit or delete this rubric from this activity's page.
     *
     * @param \stdClass $row A presenterai_rubric row.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The user.
     * @return bool
     */
    public static function may_edit(\stdClass $row, \context_module $ctx, int $userid): bool {
        $contextid = (int) $row->contextid;
        if ($contextid === (int) $ctx->id) {
            return has_capability(self::CAPABILITY, $ctx, $userid);
        }
        $course = $ctx->get_course_context();
        if ($contextid === (int) $course->id) {
            return has_capability(self::CAPABILITY, $course, $userid);
        }
        return false;
    }

    /**
     * Load a rubric named in a request and confirm the user may edit it from this activity.
     *
     * @param int $rubricid The rubric id from the request.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The user.
     * @return \stdClass The presenterai_rubric row.
     * @throws \moodle_exception rubricerror_notfound
     */
    public static function require_editable(int $rubricid, \context_module $ctx, int $userid): \stdClass {
        global $DB;

        $row = $rubricid > 0 ? $DB->get_record('presenterai_rubric', ['id' => $rubricid]) : false;
        if (!$row || !self::may_edit($row, $ctx, $userid)) {
            throw new \moodle_exception('rubricerror_notfound', 'mod_presenterai');
        }
        return $row;
    }

    /**
     * Delete a rubric the user may edit, for a confirmed POST.
     *
     * @param int $rubricid The rubric id from the request.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The user.
     * @return void
     */
    public static function delete(int $rubricid, \context_module $ctx, int $userid): void {
        require_sesskey();
        $row = self::require_editable($rubricid, $ctx, $userid);
        rubric_manager::delete((int) $row->id);
    }

    /**
     * Context for templates/rubrics.mustache.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $cmid = (int) $this->context->instanceid;
        $base = new \moodle_url('/mod/presenterai/rubric.php', ['id' => $cmid]);
        $course = $this->context->get_course_context();

        $rows = [];
        foreach (rubric_manager::list_for_context($this->context) as $row) {
            // HTML for the cell, which the template outputs raw; plain text for the
            // aria labels, which the template escapes once.
            $title = format_string((string) $row->title, true, ['context' => $this->context]);
            $plaintitle = format_string((string) $row->title, true, ['context' => $this->context, 'escape' => false]);
            $editable = self::may_edit($row, $this->context, $this->userid);
            $typekey = $row->type === rubric_manager::TYPE_VIDEO ? 'rubrictype_video' : 'rubrictype_speech';
            $rows[] = [
                'title' => $title,
                'type' => get_string($typekey, 'mod_presenterai'),
                'where' => $this->where((int) $row->contextid),
                'active' => get_string(!empty($row->active) ? 'yes' : 'no'),
                'criteriacount' => (int) $row->criteriacount,
                'editable' => $editable,
                'editurl' => $editable
                    ? (new \moodle_url($base, ['action' => 'edit', 'rubricid' => (int) $row->id]))->out(false)
                    : '',
                'editaria' => get_string('rubricedit_aria', 'mod_presenterai', $plaintitle),
                'deleteurl' => $editable
                    ? (new \moodle_url($base, ['action' => 'delete', 'rubricid' => (int) $row->id]))->out(false)
                    : '',
                'deletearia' => get_string('rubricdelete_aria', 'mod_presenterai', $plaintitle),
            ];
        }

        $canmodule = has_capability(self::CAPABILITY, $this->context, $this->userid);
        $cancourse = has_capability(self::CAPABILITY, $course, $this->userid);

        return [
            'hasrubrics' => !empty($rows),
            'rows' => $rows,
            'canaddmodule' => $canmodule,
            'addmoduleurl' => (new \moodle_url($base, ['action' => 'edit', 'level' => self::LEVEL_MODULE]))->out(false),
            'canaddcourse' => $cancourse,
            'addcourseurl' => (new \moodle_url($base, ['action' => 'edit', 'level' => self::LEVEL_COURSE]))->out(false),
        ];
    }

    /**
     * Where a rubric is defined, for the list.
     *
     * @param int $contextid The rubric's context id.
     * @return string Plain text.
     */
    private function where(int $contextid): string {
        if ($contextid === (int) $this->context->id) {
            return get_string('rubriclevel_activity', 'mod_presenterai');
        }
        if ($contextid === (int) $this->context->get_course_context()->id) {
            return get_string('rubriclevel_course', 'mod_presenterai');
        }
        $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
        return $ctx ? $ctx->get_context_name(false, true) : '';
    }
}
