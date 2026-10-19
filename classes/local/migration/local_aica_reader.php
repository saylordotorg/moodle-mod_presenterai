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
 * Reads SOLA's Soapbox rows, and nothing else, for the importer.
 *
 * Every query here is a SELECT. This class and ids are the only files of the importer that name a SOLA table, and
 * a test fails the build if this one holds a write call of any kind (D13), so SOLA's rows are read-only to the
 * importer however it is changed later.
 *
 * SOLA's own classes are asked only for what has no other home (the level presets and the names of the two visual
 * criteria), only behind class_exists() and defined(), and only for plain data. This plugin keeps no runtime
 * dependency on SOLA (D3): nothing here loads SOLA's code unless it is installed. But the tables are another
 * matter: tables_present() must be checked first, and every other method assumes SOLA's Soapbox tables exist,
 * so on a site without them each of those fails with a database error.
 *
 * Where SOLA computes something from its settings (the attempt cap, the storage location) the same rule is
 * restated here, so the importer reads SOLA's settings the way SOLA does without calling it, and a test pins
 * that the two agree whenever SOLA is installed.
 *
 * What is selected, and why:
 *
 * - Scores only through sbx_rec.scoreid. practice_scores also holds conversation and pronunciation practice, and
 *   freeform speech practice, so no other selector picks out Soapbox scores (IMPLEMENTATION-PLAN 7.2).
 * - A recording whose score row has gone comes back with scoreid null, so "has a score" means the same to the
 *   mapper and the runner, and so two recordings pointing at a vanished score aren't counted as sharing one.
 * - A shared score is reported to every course that points at it, not only the course holding both recordings,
 *   because migrating either course alone would copy the one source row.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class local_aica_reader implements source_reader {
    /** @var string The root-relative path of the learner page a course page stand-in points at. */
    public const STANDIN_PATH = '/local/ai_course_assistant/soapbox_present.php';

    /** @var string The class constant SOLA 7.13.0 adds when its cleanup can skip media this plugin owns. */
    public const INTERLOCK_CONSTANT = '\local_ai_course_assistant\task\soapbox_cleanup::PRESENTERAI_INTERLOCK';

    /** @var string The adhoc task SOLA queues to score one recording, as core stores a class name. */
    public const SCORE_TASK = '\local_ai_course_assistant\task\score_recording';

    /** @var string SOLA's component, where its settings live. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string SOLA's rubric manager, the home of the level presets and the visual criteria. */
    private const RUBRIC_MANAGER = '\local_ai_course_assistant\rubric_manager';

    /** @var int SOLA's soapbox_config::DEFAULT_MAX_RECORDINGS, the admin cap when none is set. */
    private const DEFAULT_MAX_RECORDINGS = 3;

    /** @var string SOLA's soapbox_storage::DEFAULT_BUCKET. */
    private const DEFAULT_BUCKET = 'archive-course';

    /** @var string SOLA's soapbox_storage::DEFAULT_REGION. */
    private const DEFAULT_REGION = 'us-east-1';

    /** @var string SOLA's soapbox_storage::DEFAULT_PREFIX. */
    private const DEFAULT_PREFIX = 'soapbox/';

    /** @var string SOLA's practice score table, which holds Soapbox scores among others. */
    private const SCORES_TABLE = 'local_ai_course_assistant_practice_scores';

    /** @var string SOLA's rubric table, which the criteria of an old score are looked up in. */
    private const RUBRICS_TABLE = 'local_ai_course_assistant_rubrics';

    /** @var string The score type SOLA's speech scoring writes. */
    private const SPEECH = 'speech';

    /** @var string[] The assignment columns the reader returns, and the type each is returned as. */
    private const ASSIGN = [
        'id' => 'int', 'courseid' => 'int', 'name' => 'string', 'intro' => '?string', 'introformat' => 'int',
        'ptype' => 'string', 'mode' => 'string', 'min_seconds' => 'int', 'max_seconds' => 'int',
        'max_attempts' => 'int', 'stored_attempts' => 'int', 'rubricid' => '?int', 'speaking_level' => '?string',
        'slides_enabled' => 'int', 'slide_vision' => 'int', 'visible' => 'int', 'timecreated' => 'int',
        'timemodified' => 'int',
    ];

    /** @var string[] The topic columns the reader returns, and the type each is returned as. */
    private const TOPIC = [
        'id' => 'int', 'assignid' => 'int', 'title' => 'string', 'instructions' => '?string',
        'instructionsformat' => 'int', 'sortorder' => 'int', 'timecreated' => 'int', 'timemodified' => 'int',
    ];

    /** @var string[] The recording columns the reader returns, and the type each is returned as. */
    private const REC = [
        'id' => 'int', 'assignid' => 'int', 'userid' => 'int', 'topicid' => '?int', 'mode' => 'string',
        'storage_key' => '?string', 'deck_key' => '?string', 'frames_key' => '?string',
        'duration_seconds' => 'int', 'size_bytes' => 'int', 'status' => 'string', 'transcript' => '?string',
        'slide_timeline' => '?string', 'scoreid' => '?int', 'expires_at' => 'int', 'timecreated' => 'int',
    ];

    /** @var string[] The score columns the reader returns, and the type each is returned as. */
    private const SCORE = [
        'id' => 'int', 'rubricid' => 'int', 'userid' => 'int', 'courseid' => 'int', 'scores' => 'string',
        'overall_score' => 'int', 'ai_feedback' => '?string', 'session_meta' => '?string', 'timecreated' => 'int',
    ];

    /** @var \Closure|null Replaces the lookup of a level preset and the visual criteria in SOLA, for tests. */
    private $presets;

    /** @var string The class constant whose existence says SOLA carries the interlock. */
    private $interlock;

    /** @var \Closure|null Replaces the list of the site's table names, for tests. */
    private $tables;

    /**
     * Create a reader.
     *
     * The arguments exist so a test can stand in for what a site may or may not have (SOLA's classes, and its
     * tables). Production code passes nothing.
     *
     * @param \Closure|null $presets A function from a level key to ['criteria' => the level preset's definitions,
     *        'visual' => the visual criteria's definitions] (each a list of arrays with a name and a max_score), or
     *        to null when it can't say, used instead of asking SOLA's rubric manager.
     * @param string $interlock The class constant, as a string, whose existence means the interlock is present.
     * @param \Closure|null $tables A function returning the names of the tables on the site, without the prefix,
     *        used instead of asking the database.
     */
    public function __construct(
        ?\Closure $presets = null,
        string $interlock = self::INTERLOCK_CONSTANT,
        ?\Closure $tables = null
    ) {
        $this->presets = $presets;
        $this->interlock = $interlock;
        $this->tables = $tables;
    }

    /**
     * The id of the site being read, as the map records it.
     *
     * @return string Forty hexadecimal characters.
     */
    public function site_id(): string {
        return ids::site_id();
    }

    /**
     * Whether SOLA's three sbx tables, its practice score table and its rubric table are all on this site.
     *
     * Reads the list of table names, which isn't a schema call, so the reader stays free of the database
     * manager. The tables are listed without the site's prefix.
     *
     * @return bool
     */
    public function tables_present(): bool {
        global $DB;

        $names = $this->tables !== null ? ($this->tables)() : $DB->get_tables(false);
        $existing = array_map('strval', array_values($names));
        $required = [ids::SOURCE_REC, ids::SOURCE_ASSIGN, ids::SOURCE_TOPIC, self::SCORES_TABLE, self::RUBRICS_TABLE];

        return array_diff($required, $existing) === [];
    }

    /**
     * The courses that hold any Soapbox assignment.
     *
     * A course that has since been deleted is still listed while its assignments remain, because this reports
     * what SOLA holds and the runner decides what can be done about it.
     *
     * @return int[] Course ids, ascending.
     */
    public function course_ids_with_data(): array {
        global $DB;

        $sql = 'SELECT DISTINCT courseid FROM {local_ai_course_assistant_sbx_assign} ORDER BY courseid';

        return array_map('intval', $DB->get_fieldset_sql($sql));
    }

    /**
     * The Soapbox assignments of one course, each with the cap SOLA would really apply.
     *
     * @param int $courseid The course.
     * @return array[] Rows keyed by SOLA's column names, in id order, max_attempts being the effective cap.
     */
    public function assignments(int $courseid): array {
        global $DB;

        $admincap = $this->admin_cap();
        $rows = [];
        foreach ($DB->get_records(ids::SOURCE_ASSIGN, ['courseid' => $courseid], 'id ASC') as $record) {
            $row = self::typed($record, self::ASSIGN);
            $row['max_attempts'] = self::effective_cap($row['max_attempts'], $admincap);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The topics of one assignment.
     *
     * @param int $assignid The source assignment id.
     * @return array[] Rows keyed by SOLA's column names, in sort order then id order.
     */
    public function topics(int $assignid): array {
        global $DB;

        $records = $DB->get_records(ids::SOURCE_TOPIC, ['assignid' => $assignid], 'sortorder ASC, id ASC');

        return array_values(array_map(static fn($record): array => self::typed($record, self::TOPIC), $records));
    }

    /**
     * Every recording of one assignment, whatever its status, with scoreid null where the score row has gone.
     *
     * @param int $assignid The source assignment id.
     * @return array[] Rows keyed by SOLA's column names, in id order.
     */
    public function recordings(int $assignid): array {
        global $DB;

        $sql = 'SELECT r.id, r.assignid, r.userid, r.topicid, r.mode, r.storage_key, r.deck_key, r.frames_key,
                       r.duration_seconds, r.size_bytes, r.status, r.transcript, r.slide_timeline,
                       CASE WHEN ps.id IS NULL THEN NULL ELSE r.scoreid END AS scoreid,
                       r.expires_at, r.timecreated
                  FROM {local_ai_course_assistant_sbx_rec} r
             LEFT JOIN {local_ai_course_assistant_practice_scores} ps ON ps.id = r.scoreid
                 WHERE r.assignid = :assignid
              ORDER BY r.id ASC';
        $records = $DB->get_records_sql($sql, ['assignid' => $assignid]);

        return array_values(array_map(static fn($record): array => self::typed($record, self::REC), $records));
    }

    /**
     * One practice score, and only one a recording points at.
     *
     * A speech score no recording owns could be the learner's freeform practice, so it is never handed over,
     * whatever id is asked for.
     *
     * @param int $scoreid The practice_scores id.
     * @return array|null The row, or null when there's none or no recording points at it.
     */
    public function score(int $scoreid): ?array {
        global $DB;

        $sql = 'SELECT ps.id, ps.rubricid, ps.userid, ps.courseid, ps.scores, ps.overall_score, ps.ai_feedback,
                       ps.session_meta, ps.timecreated
                  FROM {local_ai_course_assistant_practice_scores} ps
                 WHERE ps.id = :scoreid
                   AND EXISTS (SELECT 1 FROM {local_ai_course_assistant_sbx_rec} r WHERE r.scoreid = ps.id)';
        $record = $DB->get_record_sql($sql, ['scoreid' => $scoreid]);

        return $record ? self::typed($record, self::SCORE) : null;
    }

    /**
     * How many speech scores of the course belong to no recording.
     *
     * This counts every such row, which includes a learner's freeform Soapbox practice as well as a score a crash
     * left behind: nothing in the row tells them apart.
     *
     * @param int $courseid The course.
     * @return int
     */
    public function orphan_score_count(int $courseid): int {
        global $DB;

        $sql = 'SELECT COUNT(1)
                  FROM {local_ai_course_assistant_practice_scores} ps
                 WHERE ps.courseid = :courseid AND ps.session_type = :type
                   AND NOT EXISTS (SELECT 1 FROM {local_ai_course_assistant_sbx_rec} r WHERE r.scoreid = ps.id)';

        return (int) $DB->count_records_sql($sql, ['courseid' => $courseid, 'type' => self::SPEECH]);
    }

    /**
     * The existing scores that more than one recording points at, among the course's recordings.
     *
     * The other recording may belong to another course: either course alone would copy the one source row.
     *
     * @param int $courseid The course.
     * @return int[] Score ids, ascending.
     */
    public function shared_score_ids(int $courseid): array {
        global $DB;

        $sql = 'SELECT DISTINCT r.scoreid
                  FROM {local_ai_course_assistant_sbx_rec} r
                  JOIN {local_ai_course_assistant_sbx_assign} a ON a.id = r.assignid
                  JOIN {local_ai_course_assistant_practice_scores} ps ON ps.id = r.scoreid
                 WHERE a.courseid = :courseid
                   AND (SELECT COUNT(1) FROM {local_ai_course_assistant_sbx_rec} r2 WHERE r2.scoreid = r.scoreid) > 1
              ORDER BY r.scoreid';

        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * The speaking level SOLA scored the course's attempts with.
     *
     * SOLA reads the per course setting and falls back to general whenever it's empty, and so does this.
     *
     * @param int $courseid The course.
     * @return string A level key, 'general' when the course never set one.
     */
    public function speaking_level_for_course(int $courseid): string {
        return (string) (get_config(self::PLUGIN, 'soapbox_level_course_' . $courseid) ?: 'general');
    }

    /**
     * The criteria of a SOLA rubric, by normalised name.
     *
     * Read by id whether or not the rubric is still active, because a score points at the rubric that was in
     * force when it was given. A criterion without a maximum is read as 5, as SOLA's scoring does.
     *
     * @param int $rubricid The rubric id a score row carries.
     * @return array|null Criterion name to maximum score, null when the rubric no longer exists.
     */
    public function rubric_criteria(int $rubricid): ?array {
        global $DB;

        $record = $DB->get_record(self::RUBRICS_TABLE, ['id' => $rubricid], 'id, criteria');
        if (!$record) {
            return null;
        }
        $criteria = json_decode((string) $record->criteria, true);

        return self::by_name(is_array($criteria) ? $criteria : []);
    }

    /**
     * The criteria of one level's preset, with the two visual criteria, by normalised name.
     *
     * The visual criteria are added because an attempt recorded on video was scored against them too, and a
     * stored row may hold them. Lookup is by name, so the extra names do no harm to a row that doesn't. An empty
     * map, when SOLA's presets can't be read, makes the mapper say unreconstructed instead of guessing.
     *
     * @param string $level A level key, '' meaning the general preset.
     * @return array Criterion name to maximum score.
     */
    public function preset_criteria(string $level): array {
        $source = $this->presets !== null ? ($this->presets)($level) : $this->sola_preset($level);
        if (!is_array($source)) {
            return [];
        }

        return self::by_name(array_merge(
            is_array($source['criteria'] ?? null) ? $source['criteria'] : [],
            is_array($source['visual'] ?? null) ? $source['visual'] : []
        ));
    }

    /**
     * SOLA's media storage settings as SOLA itself reads them, defaults included.
     *
     * Where its recordings really are, which is what the destination must match, so an unset bucket reads as
     * the bucket SOLA falls back to. The prefix is in SOLA's normal form (no leading slash, one trailing slash).
     * The access key and secret are never read.
     *
     * @return array With bucket, region and prefix.
     */
    public function storage_settings(): array {
        $bucket = trim((string) get_config(self::PLUGIN, 'soapbox_storage_bucket'));
        $region = trim((string) get_config(self::PLUGIN, 'soapbox_storage_region'));
        $prefix = trim((string) get_config(self::PLUGIN, 'soapbox_storage_prefix'));

        return [
            'bucket' => $bucket !== '' ? $bucket : self::DEFAULT_BUCKET,
            'region' => $region !== '' ? $region : self::DEFAULT_REGION,
            'prefix' => rtrim(ltrim($prefix !== '' ? $prefix : self::DEFAULT_PREFIX, '/'), '/') . '/',
        ];
    }

    /**
     * Whether SOLA carries the interlock constant.
     *
     * defined() on a class constant triggers the autoloader for SOLA's class, so SOLA's class file is loaded when
     * SOLA is installed. None of its methods run, and a class that doesn't exist is a silent false.
     *
     * @return bool
     */
    public function interlock_present(): bool {
        return defined($this->interlock);
    }

    /**
     * How many score_recording adhoc tasks are queued or running.
     *
     * A task stays in the table until it finishes, running or failing, so the table's count is the answer.
     *
     * @return int
     */
    public function queued_scoring_task_count(): int {
        global $DB;

        return $DB->count_records('task_adhoc', ['classname' => self::SCORE_TASK]);
    }

    /**
     * The course page stand-ins that point at a Soapbox assignment, all of them, in course module order.
     *
     * The same match as SOLA's soapbox_course_link::assign_id_from_url(): the learner page's path followed by
     * ?id=<digits>, found anywhere in the URL, so the root-relative form and an absolute one both match. Two
     * activities for one assignment come back as two rows. An activity already being deleted isn't one.
     *
     * @param int $courseid The course.
     * @return array[] Rows of cmid, assignid, externalurl, visible and visibleoncoursepage.
     */
    public function standins(int $courseid): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, u.externalurl, cm.visible, cm.visibleoncoursepage
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'url'
                  JOIN {url} u ON u.id = cm.instance
                 WHERE cm.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY cm.id";
        $standins = [];
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid]) as $record) {
            $assignid = self::assign_id_from_url((string) $record->externalurl);
            if ($assignid !== null) {
                $standins[] = [
                    'cmid' => (int) $record->cmid,
                    'assignid' => $assignid,
                    'externalurl' => (string) $record->externalurl,
                    'visible' => (int) $record->visible,
                    'visibleoncoursepage' => (int) $record->visibleoncoursepage,
                ];
            }
        }

        return $standins;
    }

    /**
     * The assignment id in a url activity's address, null when it isn't a link to a Soapbox assignment.
     *
     * @param string $externalurl The activity's externalurl.
     * @return int|null
     */
    private static function assign_id_from_url(string $externalurl): ?int {
        $pattern = '#' . preg_quote(self::STANDIN_PATH, '#') . '\?id=(\d+)(?:&|$)#';

        return preg_match($pattern, $externalurl, $matches) ? (int) $matches[1] : null;
    }

    /**
     * The attempts a learner may make: SOLA's soapbox_config::effective_recording_cap(), restated.
     *
     * @param int $stored The assignment's stored attempts, where 0 (or less) means "up to the admin cap".
     * @param int $admincap The admin cap.
     * @return int
     */
    private static function effective_cap(int $stored, int $admincap): int {
        return $stored <= 0 ? $admincap : min($stored, $admincap);
    }

    /**
     * The admin's cap on attempts: SOLA's soapbox_config::max_recordings(), restated.
     *
     * @return int The setting when it is a positive number, else SOLA's default.
     */
    private function admin_cap(): int {
        $cap = (int) get_config(self::PLUGIN, 'soapbox_max_recordings');

        return $cap > 0 ? $cap : self::DEFAULT_MAX_RECORDINGS;
    }

    /**
     * What SOLA's rubric manager says about a level: its preset's criteria and the two visual criteria.
     *
     * @param string $level A level key.
     * @return array|null With criteria and visual, null when SOLA's rubric manager isn't installed. A SOLA that
     *         has no visual criteria (before 7.5.1) has an empty list for them.
     */
    private function sola_preset(string $level): ?array {
        $class = self::RUBRIC_MANAGER;
        if (!class_exists($class) || !method_exists($class, 'speech_preset')) {
            return null;
        }

        return [
            'criteria' => $class::speech_preset($level)['criteria'] ?? [],
            'visual' => defined($class . '::VISUAL_CRITERIA') ? constant($class . '::VISUAL_CRITERIA') : [],
        ];
    }

    /**
     * Criterion definitions as a map from normalised name to maximum, the way SOLA's scoring builds its own.
     *
     * A definition without a maximum counts as 5, and when two names normalise alike the later one wins, as
     * in score_speech. The names are normalised with the mapper, which looks them up the same way.
     *
     * @param array $definitions Arrays with a name and, usually, a max_score.
     * @return array Normalised name to maximum score.
     */
    private static function by_name(array $definitions): array {
        $map = [];
        foreach ($definitions as $definition) {
            $name = is_array($definition) ? mapper::normalise_name((string) ($definition['name'] ?? '')) : '';
            if ($name !== '') {
                $map[$name] = (int) ($definition['max_score'] ?? 5);
            }
        }

        return $map;
    }

    /**
     * A database record as a plain array of exactly the named columns, each of the type given.
     *
     * Types are 'int', 'string', and '?int' or '?string' for a column that may be null, which stays null.
     *
     * @param \stdClass $record The record.
     * @param array $columns Column name to type.
     * @return array
     */
    private static function typed(\stdClass $record, array $columns): array {
        $row = [];
        foreach ($columns as $column => $type) {
            $value = $record->{$column} ?? null;
            if ($value === null && $type[0] === '?') {
                $row[$column] = null;
            } else {
                $row[$column] = ltrim($type, '?') === 'int' ? (int) $value : (string) $value;
            }
        }

        return $row;
    }
}
