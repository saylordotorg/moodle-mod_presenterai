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

use mod_presenterai\local\rubric_manager;

/**
 * Imports one course's Soapbox assignments into PresenterAI activities (IMPLEMENTATION-PLAN 7.1, 7.3 and 7.5).
 *
 * plan_course() is the dry run: it reads SOLA through the source reader and this site's tables, and writes nothing,
 * takes no lock and fires no event. apply_course() checks the same things, takes a per-course lock, plans again
 * under it and then imports each assignment the plan says to create or resume. The site-wide checks live in
 * preconditions, the read-only questions about this site in destination.
 *
 * Each assignment is imported in two delegated transactions, never one around the whole run:
 *
 * 1. Attach: create_module() makes the activity HIDDEN (grade 0, retention following the site, legacyassignid
 *    set), the assignment's map row is written (state created), then the topics and a map row for each (state
 *    attached), and the assignment's row moves to attached.
 * 2. Finish: every recording, its score and its map row (state verified); then the stand-in's original URL and
 *    visibility go into the assignment's map payload, and only after that is the stand-in rewritten; then the
 *    activity takes the source assignment's visibility; then the topic rows and the assignment row move to
 *    verified.
 *
 * The split is what makes resume possible: an interruption between the two leaves the activity (still hidden)
 * and its topics committed and the assignment's row at attached, and the next run finishes it, unless the source
 * changed meanwhile or the activity has been used. A recording's map row is only ever written inside the finishing
 * transaction, so an assignment that fails part way leaves none; SOLA's cleanup interlock treats any map row as
 * "PresenterAI owns this media", so a stray one would keep media forever.
 *
 * Map rows (presenterai_migration_map): sourcetable is ids::SOURCE_ASSIGN, SOURCE_TOPIC or SOURCE_REC, sourceid the
 * SOLA id, sourcesiteid ids::site_id(), targetid the presenterai, presenterai_topic or presenterai_recording id,
 * targetcmid the activity's cmid on every row, runid the run that last wrote the row. timecreated is when the row
 * was first written, which for an assignment is when it was migrated: a source assignment or topic modified later
 * than that is refused rather than resumed.
 *
 * payload is JSON or null, and only an assignment row ever has one. Its keys, either or both:
 * - PAYLOAD_STANDIN ("standin"): {"cmid": int, "externalurl": string, "visible": int, "visibleoncoursepage": int},
 *   the stand-in's ORIGINAL values, written before it's rewritten, for rollback.
 * - PAYLOAD_FAILED ("failed"): the refusal code that moved the row to failed (today only "targetgone"), written
 *   beside any "standin" key already there.
 * Never learner text.
 *
 * sourcesiteid comes from the site identifier, which a database clone shares, so a clone sees the same map rows
 * (it only ever holds more than the site it was copied from).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runner {
    /** @var string The lock type, with one lock per course. */
    public const LOCK_TYPE = 'mod_presenterai_migration';

    /** @var string The payload key holding a stand-in's original values. Rollback reads the same key. */
    public const PAYLOAD_STANDIN = 'standin';

    /** @var string The payload key holding why an assignment was moved to failed. */
    public const PAYLOAD_FAILED = 'failed';

    /** @var string The activity exists; its topics may not. */
    public const STATE_CREATED = 'created';

    /** @var string The activity and its topics exist; its recordings don't yet. */
    public const STATE_ATTACHED = 'attached';

    /** @var string Everything was written. */
    public const STATE_VERIFIED = 'verified';

    /** @var string The activity this row created is gone; it's reported and never recreated. */
    public const STATE_FAILED = 'failed';

    /** @var string The map table. */
    private const MAP = destination::MAP;

    /** @var source_reader Every read of SOLA. */
    protected source_reader $reader;

    /** @var \core\lock\lock_factory|null The lock factory, made on first use when none was given. */
    private ?\core\lock\lock_factory $locks;

    /** @var array Source assignment id to the rows the last plan read, for apply. Holds learner text; never printed. */
    private array $work = [];

    /** @var array Rubric id to its criteria (or null), read once per runner. */
    private array $rubrics = [];

    /**
     * A runner over one source.
     *
     * @param source_reader $reader Where SOLA's rows come from.
     * @param \core\lock\lock_factory|null $locks The lock factory, the site's own when null.
     */
    public function __construct(source_reader $reader, ?\core\lock\lock_factory $locks = null) {
        $this->reader = $reader;
        $this->locks = $locks;
    }

    /**
     * The site-wide preconditions that don't hold.
     *
     * @return array[] ['code' => string, 'message' => string]; empty when the import may run.
     */
    public function preconditions(): array {
        $plan = new plan(0);
        preconditions::check($this->reader, $plan);

        return $plan->unmet();
    }

    /**
     * The dry run: what an apply would do for one course. Reads only.
     *
     * @param int $courseid The course.
     * @return plan
     */
    public function plan_course(int $courseid): plan {
        return $this->build($courseid);
    }

    /**
     * Import one course: plan, lock, plan again under the lock, then create or resume each assignment.
     *
     * One assignment failing doesn't stop or undo another. Nothing happens when a precondition is unmet, the course
     * is refused, or the caller is inside a database transaction (which would hold every assignment's commit back).
     *
     * @param int $courseid The course.
     * @return plan The plan, with each assignment's outcome.
     */
    public function apply_course(int $courseid): plan {
        $plan = $this->build_for_apply($courseid);
        if (!$plan->can_proceed()) {
            return $plan;
        }

        $this->locks = $this->locks ?? \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $this->locks->get_lock('course_' . $courseid, 0);
        if (!$lock) {
            $plan->refuse('locked', self::str('refuse_locked', $courseid));
            return $plan;
        }
        try {
            $plan = $this->build_for_apply($courseid);
            if (!$plan->can_proceed()) {
                return $plan;
            }
            $plan->runid = random_string(32);
            $course = get_course($courseid);
            foreach ($plan->assignments as $sourceid => $entry) {
                $plan->assignments[$sourceid] = $this->apply_assignment($course, $entry, $plan);
            }
        } finally {
            $lock->release();
        }

        return $plan;
    }

    /**
     * The speaking level an activity gets from SOLA's course setting.
     *
     * @param string $sola SOLA's value.
     * @return array [string level, one of rubric_manager::LEVELS; bool whether SOLA's value was understood].
     */
    public static function speaking_level(string $sola): array {
        $value = \core_text::strtolower(trim($sola));
        if ($value === '') {
            return [rubric_manager::LEVEL_GENERAL, true];
        }
        if (in_array($value, rubric_manager::LEVELS, true)) {
            return [$value, true];
        }

        return [rubric_manager::LEVEL_GENERAL, false];
    }

    /**
     * A seam called after an assignment's activity and topics are committed, before its recordings.
     *
     * @param int $sourceid The source assignment.
     */
    protected function after_topics(int $sourceid): void {
    }

    /**
     * A seam called inside the finishing transaction, after the recordings, scores and their map rows.
     *
     * @param int $sourceid The source assignment.
     */
    protected function after_recordings(int $sourceid): void {
    }

    /**
     * Point a stand-in at the new activity and, when asked, hide it from the course page (never visible = 0).
     *
     * Called only after record_standin() has written the original values to the map row.
     *
     * @param int $mapid The assignment's map row, which already holds the stand-in's original values.
     * @param int $standincmid The stand-in.
     * @param int $cmid The new activity.
     * @param bool $hide Whether stealth is available, so it can leave the course page and stay reachable.
     */
    protected function rewrite_standin(int $mapid, int $standincmid, int $cmid, bool $hide): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $standincmid], 'id, instance, visible', MUST_EXIST);
        $url = (new \moodle_url('/mod/presenterai/view.php', ['id' => $cmid]))->out(false);
        $DB->update_record('url', (object) ['id' => $cm->instance, 'externalurl' => $url, 'timemodified' => time()]);
        if ($hide && (int) $cm->visible === 1) {
            $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $standincmid]);
        }
    }

    /**
     * A plan built for an apply: the same reads as the dry run, plus the transaction guard.
     *
     * @param int $courseid The course.
     * @return plan
     */
    private function build_for_apply(int $courseid): plan {
        global $DB;

        $plan = $this->build($courseid);
        $plan->applied = true;
        $outside = !$DB->is_transaction_started();
        $plan->precondition('transaction', $outside, $outside ? '' : self::str('precondition_transaction'));

        return $plan;
    }

    /**
     * Read everything for one course and decide what to do with each assignment. Writes nothing.
     *
     * @param int $courseid The course.
     * @return plan
     */
    private function build(int $courseid): plan {
        global $DB;

        $this->work = [];
        $plan = new plan($courseid);
        preconditions::check($this->reader, $plan);
        if (!$plan->preconditions[0]['met']) {
            return $plan;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            $plan->refuse('nocourse', self::str('refuse_nocourse', $courseid));
            return $plan;
        }
        if (!has_capability('moodle/course:manageactivities', \context_course::instance($courseid))) {
            $plan->refuse('capability', self::str('refuse_capability', $courseid));
        }
        $shared = $this->reader->shared_score_ids($courseid);
        if ($shared !== []) {
            $plan->refuse('sharedscore', self::str('refuse_sharedscore', count($shared)));
        }
        $plan->orphans = $this->reader->orphan_score_count($courseid);
        if ($plan->orphans > 0) {
            $plan->note('orphans', self::str('note_orphans', $plan->orphans));
        }
        $sola = $this->reader->speaking_level_for_course($courseid);
        [$plan->speakinglevel, $known] = self::speaking_level($sola);
        if (!$known) {
            $plan->note('unmappablelevel', self::str('note_unmappablelevel', $sola));
        }

        $assigns = $this->reader->assignments($courseid);
        $own = array_map('intval', array_column($assigns, 'id'));
        $standins = [];
        foreach ($this->reader->standins($courseid) as $standin) {
            if (in_array((int) $standin['assignid'], $own, true)) {
                $standins[(int) $standin['assignid']][] = $standin;
            }
        }
        $last = destination::last_section($course);
        $learners = [];
        foreach ($assigns as $assign) {
            $id = (int) $assign['id'];
            $plan->assignments[$id] = $this->plan_assignment($course, $assign, $standins[$id] ?? [], $last, $learners);
        }
        $plan->learners = count($learners);

        return $plan;
    }

    /**
     * Decide what to do with one assignment and read its rows for the apply. Writes nothing.
     *
     * @param \stdClass $course The course.
     * @param array $assign The source assignment.
     * @param array[] $standins The stand-ins pointing at it.
     * @param int $last The course's last section number.
     * @param array $learners Learner ids seen so far in the course, added to.
     * @return array The plan entry.
     */
    private function plan_assignment(\stdClass $course, array $assign, array $standins, int $last, array &$learners): array {
        $id = (int) $assign['id'];
        $entry = plan::blank_entry($id);
        $map = destination::map_row(ids::SOURCE_ASSIGN, $id);
        if ($map) {
            $entry['state'] = $map->state;
            $entry['targetid'] = (int) $map->targetid;
            $entry['targetcmid'] = (int) $map->targetcmid;
            if ($map->state === self::STATE_VERIFIED) {
                return $this->plan_verified($entry, $map);
            }
        }

        $topics = $this->reader->topics($id);
        if ($map) {
            if ($map->state === self::STATE_FAILED) {
                self::refuse_entry($entry, 'failed', $id);
            } else if (destination::source_changed($map, $assign, $topics)) {
                self::refuse_entry($entry, 'stale', $id);
            } else if (!destination::target_resolves($map, $id)) {
                self::refuse_entry($entry, 'targetgone', $id);
            } else if (destination::target_used($map)) {
                self::refuse_entry($entry, 'targetused', $id);
            } else {
                $entry['decision'] = plan::RESUME;
            }
        }

        $entry = $this->plan_standin($course, $entry, $standins, $last);

        $recs = $this->reader->recordings($id);
        $scores = [];
        $entry['counts']['topics'] = count($topics);
        $entry['counts']['recordings'] = count($recs);
        $users = [];
        foreach ($recs as $rec) {
            $scoreid = (int) ($rec['scoreid'] ?? 0);
            if ($scoreid > 0) {
                $score = $this->reader->score($scoreid);
                if ($score === null) {
                    self::refuse_entry($entry, 'scoremissing', $id);
                    continue;
                }
                $scores[$scoreid] = $score;
            }
            try {
                $status = mapper::recording_status($rec, $scoreid > 0);
            } catch (\InvalidArgumentException $e) {
                self::refuse_entry($entry, 'badstatus', $id);
                continue;
            }
            $entry['counts']['bystatus'][$status] = ($entry['counts']['bystatus'][$status] ?? 0) + 1;
            $users[(int) $rec['userid']] = true;
            $learners[(int) $rec['userid']] = true;
        }
        ksort($entry['counts']['bystatus']);
        $entry['counts']['scores'] = count($scores);
        $entry['counts']['learners'] = count($users);
        $this->work[$id] = ['assign' => $assign, 'topics' => $topics, 'recs' => $recs, 'scores' => $scores,
            'standin' => in_array($entry['standin'], [plan::STANDIN_REWRITE, plan::STANDIN_VISIBLE], true) ? $standins[0] : null];

        return $entry;
    }

    /**
     * A verified assignment is skipped. One read says what SOLA gained since, which nothing imports.
     *
     * @param array $entry The plan entry.
     * @param \stdClass $map The assignment's map row.
     * @return array The entry.
     */
    private function plan_verified(array $entry, \stdClass $map): array {
        $entry['decision'] = plan::SKIP_VERIFIED;
        [$new, $scored] = destination::drift($map, $this->reader->recordings($entry['sourceid']));
        $entry['new_source_rows'] = $new;
        $entry['newly_scored_rows'] = $scored;
        if ($new > 0 || $scored > 0) {
            $entry['message'] = self::str('verified_drift', (object) ['added' => $new, 'scored' => $scored]);
        }

        return $entry;
    }

    /**
     * Decide what happens to the assignment's stand-in and which section the activity goes into.
     *
     * @param \stdClass $course The course.
     * @param array $entry The plan entry.
     * @param array[] $standins The stand-ins pointing at the assignment.
     * @param int $last The course's last section number.
     * @return array The entry.
     */
    private function plan_standin(\stdClass $course, array $entry, array $standins, int $last): array {
        $entry['section'] = $last;
        if (count($standins) > 1) {
            $entry['standin'] = plan::STANDIN_DUPLICATE;
            self::refuse_entry($entry, 'duplicatestandins', $entry['sourceid']);
        } else if (count($standins) === 1) {
            $cmid = (int) $standins[0]['cmid'];
            $entry['standincmid'] = $cmid;
            $cm = get_fast_modinfo($course)->cms[$cmid] ?? null;
            if ($cm === null) {
                // Being deleted, or otherwise not on the course page: left alone.
                $entry['standin'] = plan::STANDIN_UNAVAILABLE;
            } else {
                $entry['section'] = (int) $cm->sectionnum;
                $entry['standin'] = destination::stealth_allowed($course, $cm) ? plan::STANDIN_REWRITE : plan::STANDIN_VISIBLE;
            }
        } else {
            $entry['standin'] = plan::STANDIN_NONE;
        }
        $entry['standinmessage'] = self::str('standin_' . $entry['standin']);

        return $entry;
    }

    /**
     * Import one assignment the plan said to create or resume; move one whose activity vanished to failed.
     *
     * @param \stdClass $course The course.
     * @param array $entry The plan entry.
     * @param plan $plan The plan.
     * @return array The entry with its outcome.
     */
    private function apply_assignment(\stdClass $course, array $entry, plan $plan): array {
        $id = $entry['sourceid'];
        if ($entry['decision'] === plan::REFUSE && $entry['code'] === 'targetgone') {
            $this->mark_failed($id, $entry['code'], $plan->runid);
            $entry['state'] = self::STATE_FAILED;
        }
        if (!in_array($entry['decision'], [plan::CREATE, plan::RESUME], true)) {
            return $entry;
        }

        try {
            $map = $this->attach($entry, $plan);
            $entry['targetid'] = (int) $map->targetid;
            $entry['targetcmid'] = (int) $map->targetcmid;
            $this->after_topics($id);
            $this->finish($entry, $map, $plan);
            $entry['outcome'] = plan::OUTCOME_DONE;
        } catch (\Throwable $e) {
            $entry['outcome'] = plan::OUTCOME_FAILED;
            $entry['error'] = self::str('failed_error', (object) ['id' => $id, 'error' => self::describe($e)]);
        }
        // A rolled back transaction can leave the course cache describing modules that no longer exist.
        \course_modinfo::purge_course_cache((int) $course->id);
        rebuild_course_cache((int) $course->id, true);
        $map = destination::map_row(ids::SOURCE_ASSIGN, $id);
        $entry['state'] = $map ? $map->state : '';

        return $entry;
    }

    /**
     * The first transaction: the activity, hidden (unless resuming), its map row, the topics and theirs.
     *
     * @param array $entry The plan entry.
     * @param plan $plan The plan.
     * @return \stdClass The assignment's map row, at attached.
     */
    private function attach(array $entry, plan $plan): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $id = $entry['sourceid'];
        $work = $this->work[$id];
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $map = destination::map_row(ids::SOURCE_ASSIGN, $id);
            if (!$map) {
                $moduleinfo = (object) mapper::assignment_to_instance($work['assign']);
                // Hidden until finish() has put every attempt in, which then gives it the source's visibility.
                $moduleinfo->visible = 0;
                $moduleinfo->section = $entry['section'];
                $moduleinfo->speakinglevel = $plan->speakinglevel;
                $moduleinfo->introeditor['itemid'] = file_get_unused_draft_itemid();
                $cm = create_module($moduleinfo);
                $map = $this->new_map(ids::SOURCE_ASSIGN, $id, (int) $cm->instance, (int) $cm->coursemodule,
                    self::STATE_CREATED, $plan->runid, $now);
            }
            foreach ($work['topics'] as $topic) {
                if (!destination::map_row(ids::SOURCE_TOPIC, (int) $topic['id'])) {
                    $topicid = $DB->insert_record('presenterai_topic', (object) mapper::topic_to_row($topic, (int) $map->targetid));
                    $this->new_map(ids::SOURCE_TOPIC, (int) $topic['id'], (int) $topicid, (int) $map->targetcmid,
                        self::STATE_ATTACHED, $plan->runid, $now);
                }
            }
            $map->state = self::STATE_ATTACHED;
            $DB->update_record(self::MAP, (object) ['id' => $map->id, 'state' => $map->state, 'runid' => $plan->runid,
                'timemodified' => $now]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $map;
    }

    /**
     * The second transaction: recordings, scores and their map rows, then the stand-in, then the activity's
     * visibility, then every row verified.
     *
     * @param array $entry The plan entry.
     * @param \stdClass $map The assignment's map row.
     * @param plan $plan The plan.
     */
    private function finish(array $entry, \stdClass $map, plan $plan): void {
        global $DB;

        $id = $entry['sourceid'];
        $work = $this->work[$id];
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $topics = [];
            foreach ($work['topics'] as $topic) {
                $topics[(int) $topic['id']] = (int) destination::map_row(ids::SOURCE_TOPIC, (int) $topic['id'])->targetid;
            }
            $numbers = mapper::attempt_numbers($work['recs']);
            $preset = $this->reader->preset_criteria($plan->speakinglevel);
            foreach ($work['recs'] as $rec) {
                if (!destination::map_row(ids::SOURCE_REC, (int) $rec['id'])) {
                    $recid = $this->import_recording($rec, $map, $topics, $numbers, $preset, $plan->speakinglevel, $now);
                    $this->new_map(ids::SOURCE_REC, (int) $rec['id'], $recid, (int) $map->targetcmid,
                        self::STATE_VERIFIED, $plan->runid, $now);
                }
            }
            $this->after_recordings($id);

            if ($work['standin'] !== null) {
                $this->record_standin((int) $map->id, (int) $work['standin']['cmid']);
                $this->rewrite_standin((int) $map->id, (int) $work['standin']['cmid'], (int) $map->targetcmid,
                    $entry['standin'] === plan::STANDIN_REWRITE);
            }

            // In a hidden section a module is visible = 0 with its own state kept in visibleold, as core's
            // set_section_visible() leaves it; visible = 1 there would make it a stealth module.
            $visible = empty($work['assign']['visible']) ? 0 : 1;
            $section = $DB->get_field_sql('SELECT s.visible FROM {course_sections} s JOIN {course_modules} cm
                ON cm.section = s.id WHERE cm.id = :cmid', ['cmid' => $map->targetcmid], MUST_EXIST);
            $DB->update_record('course_modules', (object) ['id' => $map->targetcmid,
                'visible' => $visible && (int) $section === 1 ? 1 : 0, 'visibleold' => $visible]);
            foreach (array_keys($topics) as $topicid) {
                $DB->set_field(self::MAP, 'state', self::STATE_VERIFIED,
                    ['sourcetable' => ids::SOURCE_TOPIC, 'sourceid' => $topicid, 'sourcesiteid' => ids::site_id()]);
            }
            $DB->update_record(self::MAP, (object) ['id' => $map->id, 'state' => self::STATE_VERIFIED, 'runid' => $plan->runid,
                'timemodified' => $now]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Insert one attempt and its score.
     *
     * @param array $rec The source recording.
     * @param \stdClass $map The assignment's map row.
     * @param array $topics Source topic id to new topic id.
     * @param array $numbers Source recording id to attempt number.
     * @param array $preset The speaking level's preset criteria.
     * @param string $level The speaking level.
     * @param int $now The migration time.
     * @return int The new recording id.
     */
    private function import_recording(array $rec, \stdClass $map, array $topics, array $numbers, array $preset,
            string $level, int $now): int {
        global $DB;

        $scoreid = (int) ($rec['scoreid'] ?? 0);
        $topicid = $topics[(int) ($rec['topicid'] ?? 0)] ?? null;
        $row = mapper::recording_to_row($rec, (int) $map->targetid, $topicid, $numbers[(int) $rec['id']], $scoreid > 0);
        // SOLA's pruning can delete media before its expiry; a deletion is never dated in the future.
        $row['mediadeletedat'] = min((int) $row['mediadeletedat'], $now);
        $recid = (int) $DB->insert_record('presenterai_recording', (object) $row);
        if ($scoreid > 0) {
            $score = $this->work[(int) $map->sourceid]['scores'][$scoreid];
            $rubricid = (int) ($score['rubricid'] ?? 0);
            $newscore = $DB->insert_record('presenterai_score', (object) mapper::score_to_row($score, [
                'rubric' => $rubricid > 0 ? $this->rubric($rubricid) : null, 'preset' => $preset,
                'speaking_level' => $level, 'recordingid' => $recid, 'userid' => (int) $rec['userid'],
            ]));
            $DB->set_field('presenterai_recording', 'scoreid', $newscore, ['id' => $recid]);
        }

        return $recid;
    }

    /**
     * Write a stand-in's original URL and visibility to the assignment's map payload, before anything changes it.
     *
     * @param int $mapid The assignment's map row.
     * @param int $standincmid The stand-in.
     */
    private function record_standin(int $mapid, int $standincmid): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $standincmid], 'id, instance, visible, visibleoncoursepage', MUST_EXIST);
        $payload = $this->payload($mapid);
        $payload[self::PAYLOAD_STANDIN] = [
            'cmid' => (int) $cm->id,
            'externalurl' => (string) $DB->get_field('url', 'externalurl', ['id' => $cm->instance], MUST_EXIST),
            'visible' => (int) $cm->visible,
            'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
        ];
        $DB->set_field(self::MAP, 'payload', json_encode($payload, JSON_UNESCAPED_SLASHES), ['id' => $mapid]);
    }

    /**
     * Move an assignment whose activity is gone to failed, with the reason in its payload beside any stand-in record.
     *
     * @param int $sourceid The source assignment.
     * @param string $code The refusal code.
     * @param string $runid The run.
     */
    private function mark_failed(int $sourceid, string $code, string $runid): void {
        global $DB;

        $map = destination::map_row(ids::SOURCE_ASSIGN, $sourceid);
        $payload = $this->payload((int) $map->id);
        $payload[self::PAYLOAD_FAILED] = $code;
        $DB->update_record(self::MAP, (object) ['id' => $map->id, 'state' => self::STATE_FAILED, 'runid' => $runid,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), 'timemodified' => time()]);
    }

    /**
     * A map row's payload as an array, empty when there's none.
     *
     * @param int $mapid The map row.
     * @return array
     */
    private function payload(int $mapid): array {
        global $DB;

        $decoded = json_decode((string) $DB->get_field(self::MAP, 'payload', ['id' => $mapid]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write a new map row.
     *
     * @param string $table An ids::SOURCE_* value.
     * @param int $sourceid The source id.
     * @param int $targetid The new row's id.
     * @param int $cmid The activity's cmid.
     * @param string $state Its state.
     * @param string $runid The run.
     * @param int $now The time.
     * @return \stdClass The row, with its id.
     */
    private function new_map(string $table, int $sourceid, int $targetid, int $cmid, string $state, string $runid,
            int $now): \stdClass {
        global $DB;

        $row = (object) ['runid' => $runid, 'sourcetable' => $table, 'sourceid' => $sourceid,
            'sourcesiteid' => ids::site_id(), 'targetid' => $targetid, 'targetcmid' => $cmid, 'state' => $state,
            'payload' => null, 'timecreated' => $now, 'timemodified' => $now];
        $row->id = (int) $DB->insert_record(self::MAP, $row);

        return $row;
    }

    /**
     * A rubric's criteria, read once.
     *
     * @param int $rubricid The SOLA rubric.
     * @return array|null
     */
    private function rubric(int $rubricid): ?array {
        if (!array_key_exists($rubricid, $this->rubrics)) {
            $this->rubrics[$rubricid] = $this->reader->rubric_criteria($rubricid);
        }

        return $this->rubrics[$rubricid];
    }

    /**
     * What a failure is reported as: the class, and a Moodle exception's error code. Never the message, which can
     * carry SQL, values or other text that has no place in output an operator pastes into a ticket.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    private static function describe(\Throwable $e): string {
        return $e instanceof \moodle_exception ? get_class($e) . ' (' . $e->errorcode . ')' : get_class($e);
    }

    /**
     * Refuse one assignment, keeping the first reason found.
     *
     * @param array $entry The plan entry.
     * @param string $code The refusal code.
     * @param int $sourceid The source assignment, for the message.
     */
    private static function refuse_entry(array &$entry, string $code, int $sourceid): void {
        if ($entry['decision'] === plan::REFUSE) {
            return;
        }
        $entry['decision'] = plan::REFUSE;
        $entry['code'] = $code;
        $entry['message'] = self::str('refuse_' . $code, $sourceid);
    }

    /**
     * One of the migration strings.
     *
     * @param string $name The name after "migration:".
     * @param mixed $a The string's argument.
     * @return string
     */
    private static function str(string $name, $a = null): string {
        return get_string('migration:' . $name, 'mod_presenterai', $a);
    }
}
