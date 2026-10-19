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

namespace mod_presenterai\tests\fixtures;

use mod_presenterai\local\migration\ids;
use mod_presenterai\local\migration\source_reader;

/**
 * A source reader that returns arrays a test builds by hand, so the runner is tested without SOLA's tables.
 *
 * Every public property is the data one method returns. The add_* helpers fill them with SOLA-shaped rows whose
 * ids start high, so they never look like a real row of this site. Every call is recorded in $calls, which is how a
 * test proves a method wasn't asked.
 *
 * It keeps the real reader's contracts: recordings() returns scoreid null when the score row is gone, score()
 * returns only a score some recording points at, and assignments() hands max_attempts over as given (the real
 * reader's is already the effective cap). $vanishing simulates a score deleted between those two reads.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_source implements source_reader {
    /** @var bool What tables_present() says. */
    public bool $tables = true;

    /** @var array Course id to assignment rows. */
    public array $assigns = [];

    /** @var array Assignment id to topic rows. */
    public array $topics = [];

    /** @var array Assignment id to recording rows. */
    public array $recs = [];

    /** @var array Score id to score row. */
    public array $scores = [];

    /** @var array Course id to the orphan count. */
    public array $orphans = [];

    /** @var array Course id to shared score ids. */
    public array $shared = [];

    /** @var array Course id to SOLA's speaking level. */
    public array $levels = [];

    /** @var array Rubric id to criteria, or null for a rubric that is gone. */
    public array $rubrics = [];

    /** @var array Level to preset criteria. */
    public array $presets = [];

    /** @var array What storage_settings() returns. */
    public array $storage = ['bucket' => 'archive-course', 'region' => 'us-east-1', 'prefix' => 'soapbox/'];

    /** @var bool What interlock_present() says. */
    public bool $interlock = true;

    /** @var int What queued_scoring_task_count() says. */
    public int $queued = 0;

    /** @var array Course id to stand-in rows. */
    public array $standins = [];

    /** @var int[] Score ids score() says are gone although a recording still pointed at them a moment ago. */
    public array $vanishing = [];

    /** @var string[] The name of every method called, in order. */
    public array $calls = [];

    /** @var int The next id the add_* helpers hand out. */
    private int $nextid = 90000;

    /**
     * Add an assignment.
     *
     * @param int $courseid The course.
     * @param array $columns Columns replacing the defaults.
     * @return int The new id.
     */
    public function add_assign(int $courseid, array $columns = []): int {
        $id = $this->nextid++;
        $this->assigns[$courseid][] = $columns + [
            'id' => $id, 'courseid' => $courseid, 'name' => 'Persuasive talk ' . $id, 'intro' => '<p>Persuade us.</p>',
            'introformat' => FORMAT_HTML, 'ptype' => 'persuasive', 'mode' => 'video', 'min_seconds' => 60,
            'max_seconds' => 300, 'max_attempts' => 3, 'stored_attempts' => 2, 'rubricid' => null,
            'speaking_level' => null, 'slides_enabled' => 0, 'slide_vision' => 0, 'visible' => 1,
            'timecreated' => 1000, 'timemodified' => 1000,
        ];
        $this->topics[$id] = $this->topics[$id] ?? [];
        $this->recs[$id] = $this->recs[$id] ?? [];

        return $id;
    }

    /**
     * Add a topic.
     *
     * @param int $assignid The assignment.
     * @param array $columns Columns replacing the defaults.
     * @return int The new id.
     */
    public function add_topic(int $assignid, array $columns = []): int {
        $id = $this->nextid++;
        $this->topics[$assignid][] = $columns + [
            'id' => $id, 'assignid' => $assignid, 'title' => 'Topic ' . $id, 'instructions' => '<p>Pick a side.</p>',
            'instructionsformat' => FORMAT_HTML, 'sortorder' => count($this->topics[$assignid] ?? []),
            'timecreated' => 1000, 'timemodified' => 1000,
        ];

        return $id;
    }

    /**
     * Add a recording.
     *
     * @param int $assignid The assignment.
     * @param int $userid The learner.
     * @param array $columns Columns replacing the defaults.
     * @return int The new id.
     */
    public function add_rec(int $assignid, int $userid, array $columns = []): int {
        $id = $this->nextid++;
        $this->recs[$assignid][] = $columns + [
            'id' => $id, 'assignid' => $assignid, 'userid' => $userid, 'topicid' => null, 'mode' => 'video',
            'storage_key' => 'soapbox/' . $id . '.webm', 'deck_key' => null, 'frames_key' => null,
            'duration_seconds' => 120, 'size_bytes' => 4096, 'status' => 'uploaded', 'transcript' => 'Hello.',
            'slide_timeline' => null, 'scoreid' => null, 'expires_at' => 5000, 'timecreated' => 1000 + $id,
        ];

        return $id;
    }

    /**
     * Add a score and point a recording at it.
     *
     * @param int $courseid The course.
     * @param int $userid The learner.
     * @param array $columns Columns replacing the defaults.
     * @return int The new id.
     */
    public function add_score(int $courseid, int $userid, array $columns = []): int {
        $id = $this->nextid++;
        $this->scores[$id] = $columns + [
            'id' => $id, 'rubricid' => 0, 'userid' => $userid, 'courseid' => $courseid,
            'scores' => json_encode([['name' => 'Delivery & Fluency', 'score' => 4, 'feedback' => 'Steady.', 'max_score' => 5]]),
            'overall_score' => 4, 'ai_feedback' => 'A good talk.', 'session_meta' => null, 'timecreated' => 2000,
        ];

        return $id;
    }

    /**
     * Change one column of a recording.
     *
     * @param int $recid The recording.
     * @param array $columns Columns to set.
     */
    public function set_rec(int $recid, array $columns): void {
        foreach ($this->recs as $assignid => $rows) {
            foreach ($rows as $index => $row) {
                if ($row['id'] === $recid) {
                    $this->recs[$assignid][$index] = $columns + $row;
                }
            }
        }
    }

    /**
     * Change columns of an assignment.
     *
     * @param int $assignid The assignment.
     * @param array $columns Columns to set.
     */
    public function set_assign(int $assignid, array $columns): void {
        foreach ($this->assigns as $courseid => $rows) {
            foreach ($rows as $index => $row) {
                if ($row['id'] === $assignid) {
                    $this->assigns[$courseid][$index] = $columns + $row;
                }
            }
        }
    }

    /**
     * Record a call.
     *
     * @param string $method The method.
     */
    private function called(string $method): void {
        $this->calls[] = $method;
    }

    /**
     * The site id.
     *
     * @return string
     */
    public function site_id(): string {
        $this->called(__FUNCTION__);
        return ids::site_id();
    }

    /**
     * Whether the tables exist.
     *
     * @return bool
     */
    public function tables_present(): bool {
        $this->called(__FUNCTION__);
        return $this->tables;
    }

    /**
     * Courses with data.
     *
     * @return int[]
     */
    public function course_ids_with_data(): array {
        $this->called(__FUNCTION__);
        $ids = array_keys(array_filter($this->assigns));
        sort($ids);
        return $ids;
    }

    /**
     * Assignments of a course.
     *
     * @param int $courseid The course.
     * @return array[]
     */
    public function assignments(int $courseid): array {
        $this->called(__FUNCTION__);
        return $this->assigns[$courseid] ?? [];
    }

    /**
     * Topics of an assignment.
     *
     * @param int $assignid The assignment.
     * @return array[]
     */
    public function topics(int $assignid): array {
        $this->called(__FUNCTION__);
        return $this->topics[$assignid] ?? [];
    }

    /**
     * Recordings of an assignment.
     *
     * @param int $assignid The assignment.
     * @return array[]
     */
    public function recordings(int $assignid): array {
        $this->called(__FUNCTION__);
        $rows = [];
        foreach ($this->recs[$assignid] ?? [] as $rec) {
            if (!empty($rec['scoreid']) && !isset($this->scores[(int) $rec['scoreid']])) {
                $rec['scoreid'] = null;
            }
            $rows[] = $rec;
        }
        return $rows;
    }

    /**
     * One score.
     *
     * @param int $scoreid The score.
     * @return array|null
     */
    public function score(int $scoreid): ?array {
        $this->called(__FUNCTION__);
        if (in_array($scoreid, $this->vanishing, true)) {
            return null;
        }
        foreach ($this->recs as $rows) {
            foreach ($rows as $rec) {
                if ((int) ($rec['scoreid'] ?? 0) === $scoreid) {
                    return $this->scores[$scoreid] ?? null;
                }
            }
        }
        return null;
    }

    /**
     * Orphans.
     *
     * @param int $courseid The course.
     * @return int
     */
    public function orphan_score_count(int $courseid): int {
        $this->called(__FUNCTION__);
        return $this->orphans[$courseid] ?? 0;
    }

    /**
     * Shared scores.
     *
     * @param int $courseid The course.
     * @return int[]
     */
    public function shared_score_ids(int $courseid): array {
        $this->called(__FUNCTION__);
        return $this->shared[$courseid] ?? [];
    }

    /**
     * Speaking level.
     *
     * @param int $courseid The course.
     * @return string
     */
    public function speaking_level_for_course(int $courseid): string {
        $this->called(__FUNCTION__);
        return $this->levels[$courseid] ?? 'general';
    }

    /**
     * A rubric's criteria.
     *
     * @param int $rubricid The rubric.
     * @return array|null
     */
    public function rubric_criteria(int $rubricid): ?array {
        $this->called(__FUNCTION__);
        return array_key_exists($rubricid, $this->rubrics) ? $this->rubrics[$rubricid] : null;
    }

    /**
     * A preset's criteria.
     *
     * @param string $level The level.
     * @return array
     */
    public function preset_criteria(string $level): array {
        $this->called(__FUNCTION__);
        return $this->presets[$level] ?? ['delivery & fluency' => 5];
    }

    /**
     * Storage settings.
     *
     * @return array
     */
    public function storage_settings(): array {
        $this->called(__FUNCTION__);
        return $this->storage;
    }

    /**
     * The interlock.
     *
     * @return bool
     */
    public function interlock_present(): bool {
        $this->called(__FUNCTION__);
        return $this->interlock;
    }

    /**
     * Queued scoring tasks.
     *
     * @return int
     */
    public function queued_scoring_task_count(): int {
        $this->called(__FUNCTION__);
        return $this->queued;
    }

    /**
     * Stand-ins.
     *
     * @param int $courseid The course.
     * @return array[]
     */
    public function standins(int $courseid): array {
        $this->called(__FUNCTION__);
        return $this->standins[$courseid] ?? [];
    }
}
