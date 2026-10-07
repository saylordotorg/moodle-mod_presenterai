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

use mod_presenterai\task\expire_visual_data;

/**
 * The site administrator's view of what the visual feedback gate rejected.
 *
 * Design 5.3. The rejection event carries no text, so a count of which rule
 * fired cannot tell "glass of water" tripping the glasses rule from a real
 * appearance leak. This page shows the text, for the seven days the log keeps
 * it, so the word lists can be tuned against real output. Every value is
 * escaped: the text is model output and the rule may be too.
 *
 * Learner names are not shown. Staff who need to know whose attempt it was
 * follow the activity link with their own permissions there.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class gatelog_page implements \renderable, \templatable {
    /** @var int Rows per page. */
    public const PERPAGE = 50;

    /** @var int The page being shown, from 0. */
    private int $page;

    /** @var int The time the seven days are counted back from. */
    private int $now;

    /**
     * Build the page.
     *
     * @param int $page The page being shown, from 0.
     * @param int|null $now The time to count back from; null for now.
     */
    public function __construct(int $page = 0, ?int $now = null) {
        $this->page = max(0, $page);
        $this->now = $now ?? time();
    }

    /**
     * Context for templates/gatelog.mustache.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB;

        $since = $this->now - expire_visual_data::GATELOG_DAYS * DAYSECS;
        $total = $DB->count_records_select('presenterai_gatelog', 'timecreated >= :since', ['since' => $since]);

        $sql = "SELECT g.id, g.recordingid, g.target, g.layer, g.gaterule, g.rejectedtext, g.timecreated,
                       p.id AS presenteraiid, p.name AS activityname, p.course AS courseid
                  FROM {presenterai_gatelog} g
             LEFT JOIN {presenterai_recording} r ON r.id = g.recordingid
             LEFT JOIN {presenterai} p ON p.id = r.presenteraiid
                 WHERE g.timecreated >= :since
              ORDER BY g.timecreated DESC, g.id DESC";
        $records = $DB->get_records_sql($sql, ['since' => $since], $this->page * self::PERPAGE, self::PERPAGE);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = $this->row($record);
        }

        $baseurl = new \moodle_url('/mod/presenterai/gatelog.php');

        return [
            'hasrows' => !empty($rows),
            'rows' => $rows,
            'total' => $total,
            'pagingbar' => $total > self::PERPAGE
                ? $output->paging_bar($total, $this->page, self::PERPAGE, $baseurl)
                : '',
        ];
    }

    /**
     * One row of the table.
     *
     * @param \stdClass $record The joined gatelog row.
     * @return array
     */
    private function row(\stdClass $record): array {
        global $DB;

        // A dash rather than an empty cell, which a screen reader announces as blank.
        $coursename = '-';
        $activityname = '-';
        $activityurl = '';
        $course = !empty($record->courseid) ? $DB->get_record('course', ['id' => $record->courseid]) : false;
        if (!empty($record->presenteraiid) && $course) {
            $coursename = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
            $cm = get_coursemodule_from_instance(
                'presenterai',
                (int) $record->presenteraiid,
                (int) $course->id,
                false,
                IGNORE_MISSING
            );
            if ($cm) {
                $context = \context_module::instance((int) $cm->id);
                $activityname = format_string($record->activityname, true, ['context' => $context]);
                $activityurl = (new \moodle_url('/mod/presenterai/grade.php', [
                    'id' => (int) $cm->id,
                    'recordingid' => (int) $record->recordingid,
                ]))->out(false);
            }
        }

        return [
            'time' => userdate((int) $record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'course' => $coursename,
            'activity' => $activityname,
            'activityurl' => $activityurl,
            'hasactivityurl' => $activityurl !== '',
            'target' => in_array($record->target, ['summary', 'overall', 'tip'], true)
                ? get_string('gatelog_target_' . $record->target, 'mod_presenterai')
                : (string) $record->target,
            'layer' => get_string('gatelog_layer_' . self::layer_key((int) $record->layer), 'mod_presenterai'),
            'rule' => (string) $record->gaterule,
            'text' => (string) $record->rejectedtext,
        ];
    }

    /**
     * The lang string suffix for a layer number.
     *
     * @param int $layer The stored layer.
     * @return string
     */
    private static function layer_key(int $layer): string {
        switch ($layer) {
            case 1:
                return 'form';
            case 2:
                return 'deny';
            case 3:
                return 'anchor';
            case 4:
                return 'judge';
            default:
                return 'unavailable';
        }
    }
}
