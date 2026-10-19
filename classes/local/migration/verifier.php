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

namespace mod_presenterai\local\migration;

/**
 * Checks one course's import against SOLA and records the result (IMPLEMENTATION-PLAN 7.6, tiers 1 to 3).
 *
 * Every call writes one presenterai_migreport row, passing or not; that row is the evidence SOLA 8.x reads before it
 * may drop a course's Soapbox tables. passed is true only when there's no mismatch and no storage failure. SOLA is
 * read only through the source reader; the destination is this site's own tables. Tier 4 (a person opens three
 * learners' views) is a runbook step.
 *
 * Tier 1, counts, each exact, source against destination: assignments; topics; recordings of every status, deleted
 * ones included; recordings with surviving media (a storage key); scores (recordings with a score); distinct
 * learners. The destination side counts this course's activities that carry a legacy assignment id, their topics
 * and attempts that carry a legacy id, and the scores those attempts point at.
 *
 * Per assignment: its map row of this site must exist (else assignment_not_imported), be verified (else
 * assignment_not_verified) and its activity must still resolve (else target_missing: a verified activity a teacher
 * deleted since is caught here and nowhere else). Then every source recording must have a destination row
 * (else source_recording_not_imported: the backstop for recordings SOLA gained after the assignment was verified),
 * and every destination row a source one (else recording_not_in_source).
 *
 * Tier 2, content, per imported attempt: the transcript by sha1, expiresat equal to expires_at, and the score
 * present on both sides or neither (score_missing, score_unexpected). Per score: the same number of criteria, the
 * same names in the same order, the same integer scores and feedback strings, the same overall feedback, and
 * legacymeanscore equal to SOLA's overall_score. Where rawmax is above 0, the mean of the assessed criteria's raw
 * scores must be within one of SOLA's overall_score (a 0 to 5 mean, rounded), else mean_drift: the maxima were
 * rebuilt from the wrong place. An unreconstructed row must have rawmax 0 and no percentage. legacymeta must equal
 * SOLA's session_meta minus visual_observation, never the raw meta: SOLA's cleanup now strips the observation from
 * held rows, so the source may or may not still carry it, and the destination must never (D11).
 *
 * Tier 3, storage: every key a migrated attempt still holds (storagekey, deckkey, frameskey) is checked through the
 * object_checker, the video against sizebytes and the others for existence. Up to the threshold (default 2000
 * attempts with media) every attempt is checked. Above it, a sample that's the same for the same run id: the newest
 * tenth, every attempt SOLA had marked failed, and threshold more picked by sha1(run id, attempt id). A missing
 * object or a size mismatch is storage_mismatch, a check that couldn't be made is storage_error with the HTTP status;
 * each counts as a storage failure.
 *
 * Mismatches carry codes and ids only: assignid and recid are SOLA ids, recordingid a presenterai_recording id, plus
 * check (a tier 1 count), state, column (a key column's name) or status (an HTTP status) where they apply. Never a
 * key, a name, a transcript or any other learner text.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verifier {
    /** @var int Up to this many attempts with media, the storage check covers all of them. */
    public const DEFAULT_THRESHOLD = 2000;

    /** @var int Above the threshold, the newest this percent of attempts with media is always checked. */
    public const NEWEST_PERCENT = 10;

    /** @var string[] The key columns tier 3 checks, in order. The video's size is known; the others' isn't. */
    public const KEY_COLUMNS = ['storagekey', 'deckkey', 'frameskey'];

    /** @var string The report table. */
    private const REPORT = 'presenterai_migreport';

    /** @var source_reader Every read of SOLA. */
    private source_reader $reader;

    /** @var object_checker The storage check. */
    private object_checker $checker;

    /** @var int The sampling threshold. */
    private int $threshold;

    /** @var array The mismatches found by the current call. */
    private array $mismatches = [];

    /**
     * A verifier over one source and one store.
     *
     * @param source_reader $reader Where SOLA's rows come from.
     * @param object_checker $checker The storage check.
     * @param int $threshold Above how many attempts with media tier 3 samples instead of checking all.
     */
    public function __construct(source_reader $reader, object_checker $checker, int $threshold = self::DEFAULT_THRESHOLD) {
        $this->reader = $reader;
        $this->checker = $checker;
        $this->threshold = max(1, $threshold);
    }

    /**
     * Verify one course and write its report row.
     *
     * @param int $courseid The course.
     * @param int $operatorid Who ran it, stored on the report.
     * @param string $runid The run, which seeds the storage sample; a new one when empty. At most 32 characters.
     * @return array [
     *     'passed' => bool,
     *     'counts' => array, check name (assignments, topics, recordings, withmedia, scores, learners) to
     *         ['source' => int, 'destination' => int]; empty when SOLA's tables are missing,
     *     'mismatches' => array[], each ['code' => string] plus the ids that apply (see the class docblock),
     *     'storagesampled' => int, objects checked,
     *     'storagefailures' => int, objects missing, the wrong size or not checkable,
     *     'sampledrecordings' => int[], the presenterai_recording ids tier 3 looked at, ascending,
     *     'runid' => string,
     *     'reportid' => int, the presenterai_migreport row,
     *     'courseid' => int,
     * ].
     */
    public function verify_course(int $courseid, int $operatorid, string $runid = ''): array {
        global $DB;

        $runid = $runid !== '' ? substr($runid, 0, 32) : random_string(32);
        $this->mismatches = [];
        $counts = [];
        $storage = ['sampled' => 0, 'failures' => 0, 'recordings' => []];
        if (!$this->reader->tables_present()) {
            $this->mismatch('sourcetables');
        } else {
            $source = $this->read_source($courseid);
            $counts = $this->tier_one($courseid, $source);
            foreach ($source['assigns'] as $assign) {
                $this->check_assignment($assign, $source);
            }
            $storage = $this->tier_three($courseid, $source, $runid);
        }

        $passed = $this->mismatches === [] && $storage['failures'] === 0;
        $reportid = (int) $DB->insert_record(self::REPORT, (object) [
            'runid' => $runid,
            'courseid' => $courseid,
            'counts' => json_encode((object) $counts),
            'mismatches' => json_encode($this->mismatches),
            'mismatchcount' => count($this->mismatches),
            'storagesampled' => $storage['sampled'],
            'storagefailures' => $storage['failures'],
            'passed' => $passed ? 1 : 0,
            'operatorid' => $operatorid,
            'timecreated' => time(),
        ]);

        return ['passed' => $passed, 'counts' => $counts, 'mismatches' => $this->mismatches,
            'storagesampled' => $storage['sampled'], 'storagefailures' => $storage['failures'],
            'sampledrecordings' => $storage['recordings'], 'runid' => $runid, 'reportid' => $reportid,
            'courseid' => $courseid];
    }

    /**
     * Everything SOLA holds for the course: assignments, topic counts, recordings and their scores.
     *
     * @param int $courseid The course.
     * @return array With assigns (rows), topics (assignment id to count), recs (assignment id to rows) and scores
     *               (score id to row, or null when the reader can't read it).
     */
    private function read_source(int $courseid): array {
        $source = ['assigns' => $this->reader->assignments($courseid), 'topics' => [], 'recs' => [], 'scores' => []];
        foreach ($source['assigns'] as $assign) {
            $id = (int) $assign['id'];
            $source['topics'][$id] = count($this->reader->topics($id));
            $source['recs'][$id] = $this->reader->recordings($id);
            foreach ($source['recs'][$id] as $rec) {
                $scoreid = (int) ($rec['scoreid'] ?? 0);
                if ($scoreid > 0 && !array_key_exists($scoreid, $source['scores'])) {
                    $source['scores'][$scoreid] = $this->reader->score($scoreid);
                }
            }
        }

        return $source;
    }

    /**
     * Tier 1: the six counts, each a mismatch unless exact.
     *
     * @param int $courseid The course.
     * @param array $source From read_source().
     * @return array Check name to ['source' => int, 'destination' => int].
     */
    private function tier_one(int $courseid, array $source): array {
        global $DB;

        $recs = array_merge([], ...array_values($source['recs']));
        $from = 'FROM {presenterai_recording} r JOIN {presenterai} p ON p.id = r.presenteraiid
            WHERE p.course = :c AND p.legacyassignid > 0 AND r.legacyrecid > 0';
        $params = ['c' => $courseid];
        $counts = [
            'assignments' => [count($source['assigns']),
                $DB->count_records_select('presenterai', 'course = :c AND legacyassignid > 0', $params)],
            'topics' => [array_sum($source['topics']), $DB->count_records_sql('SELECT COUNT(t.id)
                FROM {presenterai_topic} t JOIN {presenterai} p ON p.id = t.presenteraiid
                WHERE p.course = :c AND p.legacyassignid > 0 AND t.legacytopicid > 0', $params)],
            'recordings' => [count($recs), $DB->count_records_sql("SELECT COUNT(r.id) {$from}", $params)],
            'withmedia' => [count(array_filter($recs, static fn(array $r): bool => (string) ($r['storage_key'] ?? '') !== '')),
                $DB->count_records_sql("SELECT COUNT(r.id) {$from} AND r.storagekey IS NOT NULL", $params)],
            'scores' => [count(array_filter($recs, static fn(array $r): bool => !empty($r['scoreid']))),
                $this->destination_scores($courseid)],
            'learners' => [count(array_unique(array_map(static fn(array $r): int => (int) $r['userid'], $recs))),
                $DB->count_records_sql("SELECT COUNT(DISTINCT r.userid) {$from}", $params)],
        ];

        $named = [];
        foreach ($counts as $check => [$src, $dest]) {
            $named[$check] = ['source' => (int) $src, 'destination' => (int) $dest];
            if ((int) $src !== (int) $dest) {
                $this->mismatch('count_mismatch', ['check' => $check]);
            }
        }

        return $named;
    }

    /**
     * How many scores the course's imported attempts point at.
     *
     * @param int $courseid The course.
     * @return int
     */
    private function destination_scores(int $courseid): int {
        global $DB;

        return $DB->count_records_sql('SELECT COUNT(s.id)
            FROM {presenterai_score} s
            JOIN {presenterai_recording} r ON r.scoreid = s.id
            JOIN {presenterai} p ON p.id = r.presenteraiid
            WHERE p.course = :c AND p.legacyassignid > 0 AND r.legacyrecid > 0', ['c' => $courseid]);
    }

    /**
     * One assignment: its map row and activity, then every recording on both sides.
     *
     * @param array $assign The source assignment.
     * @param array $source From read_source().
     */
    private function check_assignment(array $assign, array $source): void {
        global $DB;

        $id = (int) $assign['id'];
        $map = destination::map_row(ids::SOURCE_ASSIGN, $id);
        if (!$map) {
            $this->mismatch('assignment_not_imported', ['assignid' => $id]);
            return;
        }
        if ($map->state !== runner::STATE_VERIFIED) {
            $this->mismatch('assignment_not_verified', ['assignid' => $id, 'state' => (string) $map->state]);
            return;
        }
        if (!destination::target_resolves($map, $id)) {
            $this->mismatch('target_missing', ['assignid' => $id]);
            return;
        }

        $dest = [];
        foreach ($DB->get_records_select('presenterai_recording', 'presenteraiid = :p AND legacyrecid > 0',
                ['p' => $map->targetid], 'id') as $row) {
            $dest[(int) $row->legacyrecid] = $row;
        }
        foreach ($source['recs'][$id] as $rec) {
            $recid = (int) $rec['id'];
            if (!isset($dest[$recid])) {
                $this->mismatch('source_recording_not_imported', ['assignid' => $id, 'recid' => $recid]);
                continue;
            }
            $scoreid = (int) ($rec['scoreid'] ?? 0);
            $this->check_recording($id, $rec, $dest[$recid], $scoreid > 0 ? ['row' => $source['scores'][$scoreid]] : null);
            unset($dest[$recid]);
        }
        foreach ($dest as $recid => $row) {
            $this->mismatch('recording_not_in_source', ['assignid' => $id, 'recid' => $recid, 'recordingid' => (int) $row->id]);
        }
    }

    /**
     * Tier 2 for one attempt, and its score when SOLA has one.
     *
     * @param int $assignid The source assignment.
     * @param array $rec The source recording.
     * @param \stdClass $dest Its destination row.
     * @param array|null $score ['row' => the source score, or null when unreadable], or null when SOLA has no score.
     */
    private function check_recording(int $assignid, array $rec, \stdClass $dest, ?array $score): void {
        global $DB;

        $ids = ['assignid' => $assignid, 'recid' => (int) $rec['id'], 'recordingid' => (int) $dest->id];
        if (self::hash($rec['transcript'] ?? null) !== self::hash($dest->transcript)) {
            $this->mismatch('transcript_mismatch', $ids);
        }
        if ((int) ($rec['expires_at'] ?? 0) !== (int) $dest->expiresat) {
            $this->mismatch('expiry_mismatch', $ids);
        }
        $row = empty($dest->scoreid) ? false : $DB->get_record('presenterai_score', ['id' => $dest->scoreid]);
        if ($score === null) {
            if ($row) {
                $this->mismatch('score_unexpected', $ids);
            }
            return;
        }
        if (!$row || $score['row'] === null || (int) $dest->legacyscoreid !== (int) $rec['scoreid']) {
            $this->mismatch('score_missing', $ids);
            return;
        }
        $this->check_score($score['row'], $row, $ids);
    }

    /**
     * Tier 2 for one score row.
     *
     * @param array $source The practice_scores row.
     * @param \stdClass $dest The presenterai_score row.
     * @param array $ids The ids a mismatch carries.
     */
    private function check_score(array $source, \stdClass $dest, array $ids): void {
        $want = self::criteria($source['scores'] ?? '');
        $have = self::criteria($dest->scores);
        $mean = (int) ($source['overall_score'] ?? 0);
        if (count($want) !== count($have)) {
            $this->mismatch('criteria_count', $ids);
        } else {
            $fields = ['name' => 'criteria_names', 'score' => 'criteria_scores', 'feedback' => 'criteria_feedback'];
            foreach ($fields as $field => $code) {
                if (array_column($want, $field) !== array_column($have, $field)) {
                    $this->mismatch($code, $ids);
                }
            }
        }
        if (self::hash($source['ai_feedback'] ?? null) !== self::hash($dest->feedback)) {
            $this->mismatch('feedback_mismatch', $ids);
        }
        if ($mean !== (int) $dest->legacymeanscore) {
            $this->mismatch('legacymeanscore', $ids);
        }
        if ((int) $dest->rawmax > 0) {
            $assessed = array_values(array_filter(self::criteria($dest->scores, true),
                static fn(array $c): bool => $c['assessed']));
            $sum = array_sum(array_column($assessed, 'score'));
            if ($assessed === [] || abs($sum / count($assessed) - $mean) > 1) {
                $this->mismatch('mean_drift', $ids);
            }
        }
        if ($dest->scoreprovenance === mapper::PROVENANCE_NONE && ((int) $dest->rawmax !== 0 || $dest->overallpct !== null)) {
            $this->mismatch('unreconstructed_inconsistent', $ids);
        }
        if (self::meta_without_observation($source['session_meta'] ?? null) !== self::decoded_meta($dest->legacymeta)) {
            $this->mismatch('legacymeta', $ids);
        }
    }

    /**
     * Tier 3: HEAD every key of every attempt with media, or of a deterministic sample above the threshold.
     *
     * @param int $courseid The course.
     * @param array $source From read_source().
     * @param string $runid The run, which seeds the sample.
     * @return array ['sampled' => int objects, 'failures' => int objects, 'recordings' => int[] ids looked at].
     */
    private function tier_three(int $courseid, array $source, string $runid): array {
        global $DB;

        $rows = $DB->get_records_sql('SELECT r.id, r.legacyrecid, r.storagekey, r.deckkey, r.frameskey, r.sizebytes,
                r.timecreated
            FROM {presenterai_recording} r JOIN {presenterai} p ON p.id = r.presenteraiid
            WHERE p.course = :c AND p.legacyassignid > 0 AND r.legacyrecid > 0
              AND (r.storagekey IS NOT NULL OR r.deckkey IS NOT NULL OR r.frameskey IS NOT NULL)
            ORDER BY r.timecreated DESC, r.id DESC', ['c' => $courseid]);
        $statuses = [];
        foreach ($source['recs'] as $recs) {
            foreach ($recs as $rec) {
                $statuses[(int) $rec['id']] = (string) ($rec['status'] ?? '');
            }
        }

        $sample = $this->sample(array_values($rows), $statuses, $runid);
        $result = ['sampled' => 0, 'failures' => 0, 'recordings' => array_map(static fn($r): int => (int) $r->id, $sample)];
        sort($result['recordings']);
        foreach ($sample as $row) {
            foreach (self::KEY_COLUMNS as $column) {
                if ($row->$column === null || (string) $row->$column === '') {
                    continue;
                }
                $result['sampled']++;
                $ids = ['recid' => (int) $row->legacyrecid, 'recordingid' => (int) $row->id, 'column' => $column];
                try {
                    $found = $this->checker->exists_with_size((string) $row->$column,
                        $column === 'storagekey' ? (int) $row->sizebytes : 0);
                } catch (\Throwable $e) {
                    $result['failures']++;
                    $status = $e instanceof \RuntimeException ? (int) $e->getCode() : 0;
                    $this->mismatch('storage_error', $ids + ['status' => $status]);
                    continue;
                }
                if (!$found) {
                    $result['failures']++;
                    $this->mismatch('storage_mismatch', $ids);
                }
            }
        }

        return $result;
    }

    /**
     * The attempts tier 3 checks: all of them up to the threshold, else the newest tenth, every one SOLA marked
     * failed, and threshold more chosen by a hash of the run id and the attempt id.
     *
     * @param \stdClass[] $rows Attempts with media, newest first.
     * @param array $statuses SOLA recording id to its status.
     * @param string $runid The run.
     * @return \stdClass[]
     */
    private function sample(array $rows, array $statuses, string $runid): array {
        if (count($rows) <= $this->threshold) {
            return $rows;
        }
        $newest = (int) ceil(count($rows) * self::NEWEST_PERCENT / 100);
        $chosen = [];
        $rest = [];
        foreach ($rows as $index => $row) {
            if ($index < $newest || ($statuses[(int) $row->legacyrecid] ?? '') === 'failed') {
                $chosen[(int) $row->id] = $row;
            } else {
                $rest[sha1($runid . ':' . $row->id)] = $row;
            }
        }
        ksort($rest);
        foreach (array_slice($rest, 0, $this->threshold) as $row) {
            $chosen[(int) $row->id] = $row;
        }

        return array_values($chosen);
    }

    /**
     * Record one mismatch.
     *
     * @param string $code The code.
     * @param array $ids The ids and other non-text values that go with it.
     */
    private function mismatch(string $code, array $ids = []): void {
        $this->mismatches[] = ['code' => $code] + $ids;
    }

    /**
     * Criteria as the comparison sees them: name, integer score, feedback (and, when asked, whether assessed).
     *
     * Read the way the mapper reads SOLA's: a JSON list whose non-array entries are skipped.
     *
     * @param string|null $json A scores column.
     * @param bool $assessed Whether to include the assessed flag, absent meaning assessed.
     * @return array[]
     */
    private static function criteria(?string $json, bool $assessed = false): array {
        $decoded = json_decode((string) $json, true);
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $entry = [
                'name' => isset($criterion['name']) && is_scalar($criterion['name']) ? (string) $criterion['name'] : '',
                'score' => isset($criterion['score']) && is_numeric($criterion['score']) ? (int) $criterion['score'] : 0,
                'feedback' => isset($criterion['feedback']) && is_scalar($criterion['feedback'])
                    ? (string) $criterion['feedback'] : '',
            ];
            if ($assessed) {
                $entry['assessed'] = \mod_presenterai\local\rubric_manager::is_assessed($criterion);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * What the destination's legacymeta must be: SOLA's session_meta without visual_observation.
     *
     * @param string|null $json SOLA's session_meta.
     * @return array|null Null when there's none or it isn't a JSON object.
     */
    private static function meta_without_observation(?string $json): ?array {
        $meta = ($json === null || $json === '') ? null : json_decode($json, true);
        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            return null;
        }
        unset($meta['visual_observation']);

        return $meta;
    }

    /**
     * The destination's legacymeta decoded, as it is: an observation in it is a mismatch.
     *
     * @param string|null $json The legacymeta column.
     * @return array|null
     */
    private static function decoded_meta(?string $json): ?array {
        $meta = ($json === null || $json === '') ? null : json_decode($json, true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * A text's hash for comparison, null for no text, so the text itself is never kept or compared in the clear.
     *
     * @param string|null $text The text.
     * @return string|null
     */
    private static function hash(?string $text): ?string {
        return $text === null ? null : sha1($text);
    }
}
