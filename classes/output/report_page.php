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

use mod_presenterai\local\access;
use mod_presenterai\local\grader;
use mod_presenterai\local\score_manager;

/**
 * The submissions report: one row per learner the viewer may see.
 *
 * Who appears is decided by access::visible_learner_ids() with the active
 * group, so separate groups are enforced before anything is read. Everything
 * else is fetched in bulk for those learners: one query for their recordings
 * in this activity, two for the latest score of each origin, the aggregate
 * from grader, and one grouped query for AI spend. Nothing is read per row.
 *
 * The AI % and spend columns read rows that phase 3 writes. In phase 2 they
 * are empty and $0.00, which is accurate rather than missing.
 *
 * Attempts whose AI score is held for review (D28) read "Awaiting review",
 * in the status column for the latest attempt and on each attempt's grade
 * link, and the page offers to release every held attempt the viewer may
 * grade at once. The overall column counts released scores only, as the
 * gradebook does.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_page implements \renderable, \templatable {
    /** @var string[] Statuses of an attempt that never finished arriving. */
    private const UNFINISHED = ['uploading', 'abandoned'];

    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \stdClass The course row. */
    private \stdClass $course;

    /** @var \stdClass|\cm_info The course module. */
    private $cm;

    /** @var \context_module The activity's context. */
    private \context_module $context;

    /** @var int The staff member viewing the report. */
    private int $viewerid;

    /** @var int The active group, 0 for all. */
    private int $groupid;

    /** @var string The rendered group selector, or ''. */
    private string $groupselector;

    /** @var array Recording id => true, for attempts held for review (D28). */
    private array $held = [];

    /**
     * Build the report.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \stdClass|\cm_info $cm The course module.
     * @param \context_module $ctx The activity's context.
     * @param int $viewerid The staff member viewing the report.
     * @param int $groupid The active group, 0 for all.
     * @param string $groupselector The rendered group selector, or ''.
     */
    public function __construct(
        \stdClass $instance,
        \stdClass $course,
        $cm,
        \context_module $ctx,
        int $viewerid,
        int $groupid,
        string $groupselector
    ) {
        $this->instance = $instance;
        $this->course = $course;
        $this->cm = $cm;
        $this->context = $ctx;
        $this->viewerid = $viewerid;
        $this->groupid = $groupid;
        $this->groupselector = $groupselector;
    }

    /**
     * Context for templates/report.mustache.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $rows = $this->rows();
        $held = has_capability('mod/presenterai:grade', $this->context, $this->viewerid)
            ? count($this->releasable_ids())
            : 0;

        return [
            'cmid' => (int) $this->cm->id,
            'graded' => (int) $this->instance->grade !== 0,
            'groupselector' => $this->groupselector,
            'hasrows' => !empty($rows),
            'rows' => $rows,
            'hasheld' => $held > 0,
            'heldtext' => $held > 0 ? get_string('report_heldcount', 'mod_presenterai', $held) : '',
            'releaseallurl' => (new \moodle_url('/mod/presenterai/report.php', [
                'id' => (int) $this->cm->id,
                'action' => 'releaseall',
            ]))->out(false),
        ];
    }

    /**
     * The held attempts (D28) of the learners this viewer may see, which are the ones they may release.
     *
     * Group mode is applied through access::visible_learner_ids(), with the
     * page's active group, so a teacher in separate groups can't release
     * another group's feedback.
     *
     * @return int[] Recording ids.
     */
    public function releasable_ids(): array {
        global $DB;

        $held = score_manager::held_recording_ids((int) $this->instance->id);
        if (empty($held)) {
            return [];
        }
        $visible = array_flip(array_map('intval', access::visible_learner_ids($this->cm, $this->context, $this->groupid)));
        $out = [];
        foreach (array_chunk($held, grader::IN_CHUNK) as $chunk) {
            foreach ($DB->get_records_list('presenterai_recording', 'id', $chunk, 'id ASC', 'id, userid') as $rec) {
                if (isset($visible[(int) $rec->userid])) {
                    $out[] = (int) $rec->id;
                }
            }
        }
        return $out;
    }

    /**
     * One row per visible learner, sorted by last name then first name.
     *
     * @return array
     */
    private function rows(): array {
        global $DB;

        $userids = access::visible_learner_ids($this->cm, $this->context, $this->groupid);
        if (!$userids) {
            return [];
        }

        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, ' . $fields);
        uasort($users, function (\stdClass $a, \stdClass $b): int {
            return [\core_text::strtolower((string) $a->lastname), \core_text::strtolower((string) $a->firstname), (int) $a->id]
                <=> [\core_text::strtolower((string) $b->lastname), \core_text::strtolower((string) $b->firstname), (int) $b->id];
        });

        // Every recording of these learners in this activity, oldest first, so
        // the last one seen per learner is their latest.
        [$insql, $params] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED);
        $params['p'] = (int) $this->instance->id;
        $recordings = $DB->get_records_select(
            'presenterai_recording',
            "presenteraiid = :p AND userid $insql",
            $params,
            'timecreated ASC, id ASC',
            'id, userid, attemptnumber, durationseconds, status, storagekey, timecreated'
        );

        $byuser = [];
        foreach ($recordings as $rec) {
            $byuser[(int) $rec->userid][] = $rec;
        }

        $recids = array_map('intval', array_keys($recordings));
        $ai = score_manager::latest_by_origin($recids, score_manager::ORIGIN_AI);
        $teacher = score_manager::latest_by_origin($recids, score_manager::ORIGIN_TEACHER);
        $aggregates = grader::aggregate_for_users($this->instance, array_keys($users));
        $spend = $this->spend(array_keys($users));
        $cangrade = has_capability('mod/presenterai:grade', $this->context, $this->viewerid);
        $this->held = array_flip(score_manager::held_recording_ids((int) $this->instance->id));

        $out = [];
        foreach ($users as $userid => $user) {
            $out[] = $this->row(
                $user,
                $byuser[(int) $userid] ?? [],
                $ai,
                $teacher,
                $aggregates[$userid] ?? null,
                (int) ($spend[(int) $userid] ?? 0),
                $cangrade
            );
        }

        return $out;
    }

    /**
     * The context for one learner's row.
     *
     * @param \stdClass $user The learner, with name fields.
     * @param \stdClass[] $recs Their recordings, oldest first.
     * @param array $ai recordingid => latest ai score row.
     * @param array $teacher recordingid => latest teacher score row.
     * @param array|null $aggregate grader::aggregate_for_users() result for this learner.
     * @param int $microcents Their AI spend in this activity.
     * @param bool $cangrade Whether the viewer may grade.
     * @return array
     */
    private function row(
        \stdClass $user,
        array $recs,
        array $ai,
        array $teacher,
        ?array $aggregate,
        int $microcents,
        bool $cangrade
    ): array {
        $fullname = fullname($user);

        $finished = array_values(array_filter($recs, function (\stdClass $rec): bool {
            return !in_array((string) $rec->status, self::UNFINISHED, true);
        }));
        $latestfinished = $finished ? end($finished) : null;
        // The latest attempt is the latest finished one. An abandoned or
        // half uploaded row after it would otherwise blank the columns of an
        // attempt that was scored. A learner with only unfinished rows still
        // shows the status of the newest of them.
        $latest = $latestfinished ?? ($recs ? end($recs) : null);

        $attemptlinks = [];
        if ($cangrade) {
            foreach ($finished as $rec) {
                $n = (int) $rec->attemptnumber;
                $inreview = isset($this->held[(int) $rec->id]);
                $a = (object) ['name' => $fullname, 'n' => $n];
                $attemptlinks[] = [
                    'label' => get_string($inreview ? 'report_attemptn_review' : 'report_attemptn', 'mod_presenterai', $n),
                    'url' => $this->grade_url($rec),
                    'aria' => get_string($inreview ? 'report_attempt_aria_review' : 'report_attempt_aria', 'mod_presenterai', $a),
                    'inreview' => $inreview,
                ];
            }
        }

        $latestid = $latest ? (int) $latest->id : 0;

        return [
            'userid' => (int) $user->id,
            'fullname' => $fullname,
            'hasattempt' => $latest !== null,
            'status' => $latest
                ? (isset($this->held[$latestid])
                    ? get_string('status_awaitingreview', 'mod_presenterai')
                    : attempt_row::status_label($latest))
                : get_string('report_noattempt', 'mod_presenterai'),
            'attempts' => (string) count($finished),
            'latestattempt' => $latestfinished ? (string) (int) $latestfinished->attemptnumber : '-',
            'length' => $latest ? attempt_row::duration((int) $latest->durationseconds) : '-',
            'aipct' => self::pct(isset($ai[$latestid]) ? $ai[$latestid]->overallpct : null),
            'teacherpct' => self::pct(isset($teacher[$latestid]) ? $teacher[$latestid]->overallpct : null),
            'finalpct' => self::pct($aggregate['pct'] ?? null),
            'spend' => get_string('report_spendvalue', 'mod_presenterai', format_float($microcents / 1e8, 2)),
            'gradeurl' => ($cangrade && $latestfinished) ? $this->grade_url($latestfinished) : '',
            'attemptlinks' => $attemptlinks,
        ];
    }

    /**
     * AI spend per learner in this activity, in estimated microcents.
     *
     * @param int[] $userids The learners.
     * @return array userid => microcents.
     */
    private function spend(array $userids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['p'] = (int) $this->instance->id;
        $sql = "SELECT userid, SUM(estmicrocents) AS total
                  FROM {presenterai_aiusage}
                 WHERE presenteraiid = :p AND userid $insql
              GROUP BY userid";

        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[(int) $row->userid] = (int) $row->total;
        }

        return $out;
    }

    /**
     * The grading screen URL for one attempt.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @return string
     */
    private function grade_url(\stdClass $rec): string {
        return (new \moodle_url('/mod/presenterai/grade.php', [
            'id' => (int) $this->cm->id,
            'recordingid' => (int) $rec->id,
        ]))->out(false);
    }

    /**
     * A stored percentage as the report shows it, or '' when there is none.
     *
     * @param float|string|null $pct A percentage, as stored or computed.
     * @return string
     */
    private static function pct($pct): string {
        if ($pct === null || $pct === '') {
            return '';
        }

        return format_float((float) $pct, 2) . '%';
    }
}
