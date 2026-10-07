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

namespace mod_presenterai\local;

/**
 * Score rows: which one is current, and writing a teacher's or the AI's.
 *
 * A score is its own table rather than columns on the recording because an AI
 * judgement and a teacher's are two assertions about the same attempt and both
 * are kept (IMPLEMENTATION-PLAN 3.5). Rows are only ever added, never edited,
 * so a rescore leaves the earlier row as history.
 *
 * The current score of an attempt is the latest teacher row, else the latest
 * AI row. presenterai_recording.scoreid is kept pointing at it by the writer,
 * but readers that decide a grade compute precedence here instead of trusting
 * that column, so a stale pointer can never change anyone's grade.
 *
 * An AI row never displaces a teacher's. save_ai_score() still writes one for
 * an attempt a teacher has scored, so the audit trail shows the rescore, but
 * it leaves scoreid, the grade, completion and the learner's inbox alone
 * (IMPLEMENTATION-PLAN section 6: a rescore only rewrites attempts with no
 * teacher row).
 *
 * Each stored criterion carries 'visual' and 'counts' (D23). Both are derived
 * here from the resolved rubric, never from a request or a model's output.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class score_manager {
    /** @var string A score entered by a person on the grading screen. */
    public const ORIGIN_TEACHER = 'teacher';

    /** @var string A score written by the scoring task (phase 3) or the migration. */
    public const ORIGIN_AI = 'ai';

    /** @var string The recording status once it has a score. */
    public const RECORDING_STATUS_SCORED = 'scored';

    /** @var string[] visualstatus values that carry a visualsummary (visual_pipeline STATUS_SUMMARY, STATUS_FALLBACK). */
    private const SUMMARY_STATUSES = ['summary', 'fallback'];

    /**
     * Whether a teacher has scored this attempt.
     *
     * @param int $recordingid The recording id.
     * @return bool
     */
    public static function has_teacher_score(int $recordingid): bool {
        global $DB;
        return $DB->record_exists('presenterai_score', ['recordingid' => $recordingid, 'origin' => self::ORIGIN_TEACHER]);
    }

    /**
     * The current score of one attempt.
     *
     * @param int $recordingid The recording id.
     * @return \stdClass|null The score row, or null when the attempt has none.
     */
    public static function current_score(int $recordingid): ?\stdClass {
        $scores = self::current_scores([$recordingid]);
        return $scores[$recordingid] ?? null;
    }

    /**
     * The current score of several attempts, in one query.
     *
     * The latest teacher row wins, even one whose rawmax is 0, because a
     * person deciding nothing could be judged is still a decision. Without a
     * teacher row the latest AI row is current. Latest means timecreated,
     * then id, so two rows written in the same second still have an order.
     *
     * @param int[] $recordingids Recording ids.
     * @return \stdClass[] recordingid => score row, for attempts that have one.
     */
    public static function current_scores(array $recordingids): array {
        $teacher = self::latest_by_origin($recordingids, self::ORIGIN_TEACHER);
        $ai = self::latest_by_origin($recordingids, self::ORIGIN_AI);
        $current = [];
        foreach (self::clean_ids($recordingids) as $id) {
            if (isset($teacher[$id])) {
                $current[$id] = $teacher[$id];
            } else if (isset($ai[$id])) {
                $current[$id] = $ai[$id];
            }
        }
        return $current;
    }

    /**
     * The latest score row of one origin for each of several attempts.
     *
     * @param int[] $recordingids Recording ids.
     * @param string $origin ORIGIN_TEACHER or ORIGIN_AI.
     * @return \stdClass[] recordingid => score row, for attempts that have one.
     */
    public static function latest_by_origin(array $recordingids, string $origin): array {
        global $DB;

        $ids = self::clean_ids($recordingids);
        if (empty($ids)) {
            return [];
        }

        $latest = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rid');
            $params['origin'] = $origin;
            $rows = $DB->get_recordset_select(
                'presenterai_score',
                "recordingid {$insql} AND origin = :origin",
                $params,
                'recordingid ASC, timecreated DESC, id DESC'
            );
            foreach ($rows as $row) {
                $rid = (int) $row->recordingid;
                if (!isset($latest[$rid])) {
                    $latest[$rid] = $row;
                }
            }
            $rows->close();
        }
        return $latest;
    }

    /**
     * The criteria of a score row, in a shape the screens can rely on.
     *
     * @param \stdClass $score A presenterai_score row.
     * @return array[] A list of ['name' => string, 'score' => int, 'max_score' => int,
     *                 'feedback' => string, 'assessed' => bool, 'visual' => bool, 'counts' => bool].
     *                 An absent visual flag reads false and an absent counts flag reads true.
     */
    public static function decode_criteria(\stdClass $score): array {
        $raw = json_decode((string) ($score->scores ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }

        $criteria = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $criteria[] = [
                'name' => isset($entry['name']) && is_scalar($entry['name']) ? (string) $entry['name'] : '',
                'score' => isset($entry['score']) && is_numeric($entry['score']) ? (int) $entry['score'] : 0,
                'max_score' => isset($entry['max_score']) && is_numeric($entry['max_score'])
                    ? (int) $entry['max_score']
                    : rubric_manager::DEFAULT_MAX_SCORE,
                'feedback' => isset($entry['feedback']) && is_scalar($entry['feedback']) ? (string) $entry['feedback'] : '',
                'assessed' => rubric_manager::is_assessed($entry),
                'visual' => !empty($entry['visual']),
                'counts' => ($entry['counts'] ?? null) !== false,
            ];
        }
        return $criteria;
    }

    /**
     * Record a teacher's score for one attempt and pass it on.
     *
     * The caller checks capabilities and that the attempt may be graded; this
     * checks the numbers and that the attempt belongs to the instance, and
     * trusts nothing else it's given. Each criterion's visual and counts flags
     * come from the activity's resolved rubric, matched by normalized name, so a
     * request can't move a feedback only criterion into the total. After the
     * row and the recording are written together, the event fires, the grade
     * is pushed, completion is updated and then the learner is told.
     *
     * @param \stdClass $rec The presenterai_recording row being scored.
     * @param \stdClass $instance The presenterai row it belongs to.
     * @param \context_module $ctx The activity's module context.
     * @param int $graderid The user entering the score.
     * @param int $rubricid The rubric the criteria came from, 0 for the in-code default.
     * @param array $criteria A list of ['name' => string, 'max_score' => int, 'score' => ?int,
     *                        'assessed' => bool, 'feedback' => string].
     * @param string $feedback Overall feedback, plain text.
     * @return \stdClass The inserted presenterai_score row, as read back.
     */
    public static function save_teacher_score(
        \stdClass $rec,
        \stdClass $instance,
        \context_module $ctx,
        int $graderid,
        int $rubricid,
        array $criteria,
        string $feedback
    ): \stdClass {
        global $DB;

        if ((int) $rec->presenteraiid !== (int) $instance->id) {
            throw new \invalid_parameter_exception('The recording does not belong to this activity.');
        }
        $entries = self::apply_rubric_flags(self::validate_criteria($criteria), $instance, $ctx);
        $sums = grader::sums($entries);

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $scoreid = (int) $DB->insert_record('presenterai_score', (object) [
            'recordingid' => (int) $rec->id,
            'userid' => (int) $rec->userid,
            'rubricid' => max(0, $rubricid),
            'origin' => self::ORIGIN_TEACHER,
            'scores' => json_encode($entries),
            'rawsum' => $sums['rawsum'],
            'rawmax' => $sums['rawmax'],
            'overallpct' => grader::percent($sums['rawsum'], $sums['rawmax']),
            'scoreprovenance' => 'exact',
            'feedback' => $feedback,
            'tips' => null,
            'graderid' => $graderid,
            'timecreated' => $now,
        ]);
        $DB->update_record('presenterai_recording', (object) [
            'id' => (int) $rec->id,
            'scoreid' => $scoreid,
            'status' => self::RECORDING_STATUS_SCORED,
            'timemodified' => $now,
        ]);
        $transaction->allow_commit();

        $score = $DB->get_record('presenterai_score', ['id' => $scoreid], '*', MUST_EXIST);
        $rec = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);

        \mod_presenterai\event\recording_scored::create_from_score($rec, $score, $ctx)->trigger();

        // The grade and completion go first, so a message that fails to send
        // can never leave the gradebook behind the score.
        gradebook::update_grades($instance, (int) $rec->userid);
        self::update_completion($instance, $ctx, (int) $rec->userid);

        if ($graderid !== (int) $rec->userid) {
            notifier::recording_scored($rec, $instance, $ctx, $score);
        }

        return $score;
    }

    /**
     * Record the scoring task's result for one attempt.
     *
     * The row is always inserted, origin 'ai' and graderid 0. When the attempt
     * already has a teacher row, that's all: scoreid stays on the teacher's
     * row, the recording is left scored, and the grade, completion and the
     * learner's inbox are untouched, because a teacher's judgement always wins
     * (plan section 6). Otherwise the recording points at the new row and is
     * marked scored, the event fires, the grade is pushed, completion is
     * updated and the learner is told, in that order, as for a teacher.
     *
     * The caller (scorer) has already applied the allowlist and the rubric's
     * maxima and flags; this cleans the shape again and trusts nothing else.
     *
     * @param \stdClass $rec The presenterai_recording row being scored.
     * @param \stdClass $instance The presenterai row it belongs to.
     * @param \context_module $ctx The activity's module context.
     * @param int $rubricid The rubric the criteria came from, 0 for the in-code preset.
     * @param array $criteria A list of ['name', 'score', 'max_score', 'feedback', 'assessed', 'visual', 'counts'].
     * @param string $feedback Overall feedback, plain text.
     * @param string[] $tips Next-time tips, plain text.
     * @param string|null $visualsummary The gated learner summary, or null.
     * @param string $visualstatus One of visual_pipeline's STATUS_* values.
     * @return \stdClass The inserted presenterai_score row, as read back.
     */
    public static function save_ai_score(
        \stdClass $rec,
        \stdClass $instance,
        \context_module $ctx,
        int $rubricid,
        array $criteria,
        string $feedback,
        array $tips,
        ?string $visualsummary,
        string $visualstatus
    ): \stdClass {
        global $DB;

        if ((int) $rec->presenteraiid !== (int) $instance->id) {
            throw new \invalid_parameter_exception('The recording does not belong to this activity.');
        }
        $entries = self::clean_ai_criteria($criteria);
        $sums = grader::sums($entries);

        $tips = array_values(array_filter(array_map(function ($tip): string {
            return is_scalar($tip) ? trim((string) $tip) : '';
        }, $tips), 'strlen'));
        $visualstatus = \core_text::substr(clean_param($visualstatus, PARAM_ALPHA), 0, 16);
        if (!in_array($visualstatus, self::SUMMARY_STATUSES, true) || trim((string) $visualsummary) === '') {
            $visualsummary = null;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $scoreid = (int) $DB->insert_record('presenterai_score', (object) [
            'recordingid' => (int) $rec->id,
            'userid' => (int) $rec->userid,
            'rubricid' => max(0, $rubricid),
            'origin' => self::ORIGIN_AI,
            'scores' => json_encode($entries),
            'rawsum' => $sums['rawsum'],
            'rawmax' => $sums['rawmax'],
            'overallpct' => grader::percent($sums['rawsum'], $sums['rawmax']),
            'scoreprovenance' => 'exact',
            'feedback' => $feedback,
            'tips' => json_encode($tips),
            'visualsummary' => $visualsummary,
            'visualstatus' => $visualstatus,
            'graderid' => 0,
            'timecreated' => $now,
        ]);
        // Read inside the transaction, so a teacher's save that landed while
        // the model was thinking still wins.
        $teacher = self::has_teacher_score((int) $rec->id);
        $update = [
            'id' => (int) $rec->id,
            'status' => self::RECORDING_STATUS_SCORED,
            'timemodified' => $now,
        ];
        if (!$teacher) {
            $update['scoreid'] = $scoreid;
        }
        $DB->update_record('presenterai_recording', (object) $update);
        $transaction->allow_commit();

        $score = $DB->get_record('presenterai_score', ['id' => $scoreid], '*', MUST_EXIST);
        if ($teacher) {
            return $score;
        }
        $rec = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);

        \mod_presenterai\event\recording_scored::create_from_score($rec, $score, $ctx)->trigger();

        gradebook::update_grades($instance, (int) $rec->userid);
        self::update_completion($instance, $ctx, (int) $rec->userid);
        notifier::recording_scored($rec, $instance, $ctx, $score);

        return $score;
    }

    /**
     * Set each entry's visual and counts flags from the activity's resolved rubric.
     *
     * Matched by normalized name. An entry the rubric doesn't name (a score
     * entered against an older rubric) is not visual and counts.
     *
     * @param array[] $entries From validate_criteria().
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @return array[] The entries with 'visual' and 'counts'.
     */
    private static function apply_rubric_flags(array $entries, \stdClass $instance, \context_module $ctx): array {
        $byname = [];
        foreach (rubric_manager::resolve($instance, $ctx)['criteria'] as $criterion) {
            $byname[rubric_manager::normalise_name((string) $criterion['name'])] = $criterion;
        }
        foreach ($entries as $i => $entry) {
            $known = $byname[rubric_manager::normalise_name($entry['name'])] ?? null;
            $visual = $known !== null && !empty($known['visual']);
            $entries[$i]['visual'] = $visual;
            $entries[$i]['counts'] = rubric_manager::counts($visual, $instance);
        }
        return $entries;
    }

    /**
     * Clean a criteria list from the scorer into the stored shape.
     *
     * @param array $criteria The list given to save_ai_score().
     * @return array[] A list of ['name', 'score', 'max_score', 'feedback', 'assessed', 'visual', 'counts'].
     */
    private static function clean_ai_criteria(array $criteria): array {
        $entries = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $name = trim((string) ($criterion['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $max = max(1, (int) ($criterion['max_score'] ?? rubric_manager::DEFAULT_MAX_SCORE));
            $entries[] = [
                'name' => $name,
                'score' => max(0, min($max, (int) ($criterion['score'] ?? 0))),
                'max_score' => $max,
                'feedback' => (string) ($criterion['feedback'] ?? ''),
                'assessed' => ($criterion['assessed'] ?? true) !== false,
                'visual' => !empty($criterion['visual']),
                'counts' => ($criterion['counts'] ?? true) !== false,
            ];
        }
        if (empty($entries)) {
            throw new \invalid_parameter_exception('A score needs at least one criterion.');
        }
        return $entries;
    }

    /**
     * Check a submitted criteria list and turn it into the stored shape.
     *
     * @param array $criteria The list given to save_teacher_score().
     * @return array[] A list of ['name', 'score', 'max_score', 'feedback', 'assessed'].
     */
    private static function validate_criteria(array $criteria): array {
        if (empty($criteria)) {
            throw new \invalid_parameter_exception('A score needs at least one criterion.');
        }

        $entries = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                throw new \invalid_parameter_exception('Each criterion must be an array.');
            }
            $name = trim((string) ($criterion['name'] ?? ''));
            if ($name === '') {
                throw new \invalid_parameter_exception('Each criterion needs a name.');
            }
            $max = (int) ($criterion['max_score'] ?? 0);
            if ($max < 1) {
                throw new \moodle_exception('error:invalidscore', 'mod_presenterai');
            }
            $assessed = !array_key_exists('assessed', $criterion) || !empty($criterion['assessed']);
            $score = 0;
            if ($assessed) {
                $raw = $criterion['score'] ?? null;
                if ($raw === null || $raw === '' || !is_numeric($raw) || (int) $raw != $raw) {
                    throw new \moodle_exception('error:invalidscore', 'mod_presenterai');
                }
                $score = (int) $raw;
                if ($score < 0 || $score > $max) {
                    throw new \moodle_exception('error:invalidscore', 'mod_presenterai');
                }
            }
            $entries[] = [
                'name' => $name,
                'score' => $score,
                'max_score' => $max,
                'feedback' => (string) ($criterion['feedback'] ?? ''),
                'assessed' => $assessed,
            ];
        }
        return $entries;
    }

    /**
     * Ask completion to look again at one learner, when the activity tracks it.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The learner.
     * @return void
     */
    private static function update_completion(\stdClass $instance, \context_module $ctx, int $userid): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $course = get_course((int) $instance->course);
        $cm = get_coursemodule_from_id('presenterai', $ctx->instanceid, $course->id, false, MUST_EXIST);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Positive integer ids, without duplicates.
     *
     * @param array $ids Ids of any type.
     * @return int[]
     */
    private static function clean_ids(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), function (int $id): bool {
            return $id > 0;
        })));
    }
}
