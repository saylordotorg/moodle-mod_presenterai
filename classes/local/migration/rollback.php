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

use mod_presenterai\local\recording_manager;

/**
 * Undoes one course's Soapbox import (IMPLEMENTATION-PLAN 7.5 rule 5): removes the activities the import made, puts
 * each course page stand-in back as it was, and deletes every map row the import wrote. Writes nothing to SOLA (D13)
 * and deletes no media.
 *
 * What it touches. Only an assignment map row of THIS site (ids::site_id()) in state created, attached, verified or
 * failed, whose source assignment is the course's (as the reader lists it), or whose activity or stand-in is in the
 * course. Its activity is removed only while it still resolves: the course module exists, is a presenterai module
 * whose instance is the row's target, and that instance's legacyassignid is the row's source id. An activity with no
 * such row (made by hand, restored from another site, or mapped by another site) is never touched.
 *
 * What it refuses, per assignment, leaving everything as it was. An activity whose grade isn't 0 (REFUSE_GRADED: a
 * teacher turned grading on, so gradebook entries can exist), one with an attempt the import didn't make
 * (REFUSE_LEARNERATTEMPTS: legacyrecid 0, a learner used it after the import), and one with a score the import didn't
 * make (REFUSE_TEACHERSCORES: a teacher's score, a second score on an attempt, or a score on an attempt SOLA never
 * scored). A rollback would destroy that work. For
 * the whole course: another import or rollback holding the course's lock (REFUSE_LOCKED), and being called inside a
 * database transaction (REFUSE_TRANSACTION), which would hold every assignment's commit back.
 *
 * Why it deletes no media. The migrated rows' keys name objects SOLA wrote and SOLA's bucket still holds: the import
 * re-pointed keys and moved no byte, so the objects are SOLA's again the moment the map rows go. Deleting the module
 * normally runs presenterai_delete_instance(), which hands every key it finds to the store to delete. So, inside the
 * same transaction and before the module is deleted, every media key and upload id on the activity's migrated
 * recording rows (legacyrecid above 0) is set to null, and nothing else on them changes;
 * recording_manager::delete_all_media_for_instance() then finds no row with a key and builds no store at all. The
 * audio key and the upload id are only ever set by an upload to the activity, which makes a legacyrecid 0 row and so
 * a refusal; on a migrated row they're null already. The refusal checks run once before the transaction and again
 * inside it, so an attempt a learner starts in between is still refused and nothing is changed.
 *
 * The recycle bin is bypassed for this one delete, the way the recycle bin itself forces backup settings
 * ($CFG->forced_plugin_settings, read by get_config(), restored straight after; nothing is saved). A recycle bin copy
 * would keep the learners' transcripts and feedback after the rollback, would be a backup run inside the transaction,
 * and restoring it would bring back an activity no map row knows about.
 *
 * Per assignment, in one delegated transaction: the keys are nulled, the module is deleted with course_delete_module()
 * (which removes its grade items, completion, context and files and fires core's course_module_deleted, none of
 * PresenterAI's own events, and messages nobody), the stand-in is restored, and the assignment's map row and every
 * topic and recording map row carrying the same activity cmid are deleted. SOLA's cleanup interlock holds a recording
 * whose map row is in any state, so a map row left behind would keep SOLA's media forever.
 *
 * The stand-in comes back from the payload's runner::PAYLOAD_STANDIN record, read the same way whether or not the
 * payload also has runner::PAYLOAD_FAILED: its externalurl, visible and visibleoncoursepage. It's restored only while
 * it still points at the activity the import made (the learner page path and its id; host and scheme aside); one
 * someone pointed elsewhere since is left as they set it
 * (STANDIN_CHANGED), and one that's gone is reported (STANDIN_MISSING), not an error.
 *
 * An entry whose activity no longer resolves (deleted by a teacher, or failed) has nothing to remove: its stand-in is
 * restored and its map rows deleted (OUTCOME_CLEARED, code NOTE_TARGETGONE). When the module doesn't resolve but the
 * import's instance row is still there (Moodle's course page deletes asynchronously: deletioninprogress is set and an
 * adhoc task deletes the instance later), the migrated attempts' keys are released in the same transaction, so that
 * deletion finds nothing of SOLA's to purge (code NOTE_KEYSRELEASED).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rollback {
    /** @var string The activity was removed, its stand-in restored and its map rows deleted. */
    public const OUTCOME_REMOVED = 'removed';

    /** @var string There was no activity left to remove; the stand-in was restored and the map rows deleted. */
    public const OUTCOME_CLEARED = 'cleared';

    /** @var string Nothing was changed for this assignment; code says why. */
    public const OUTCOME_REFUSED = 'refused';

    /** @var string The transaction failed and was rolled back; nothing changed for this assignment. */
    public const OUTCOME_FAILED = 'failed';

    /** @var string Refused: the activity's grade isn't 0. */
    public const REFUSE_GRADED = 'graded';

    /** @var string Refused: the activity has an attempt the import didn't make. */
    public const REFUSE_LEARNERATTEMPTS = 'learnerattempts';

    /** @var string Refused: an attempt has a score the import didn't make. */
    public const REFUSE_TEACHERSCORES = 'teacherscores';

    /** @var string Course refused: another import or rollback holds the course's lock. */
    public const REFUSE_LOCKED = 'locked';

    /** @var string Course refused: called inside a database transaction. */
    public const REFUSE_TRANSACTION = 'transaction';

    /** @var string Cleared: the activity the row names no longer resolves. */
    public const NOTE_TARGETGONE = 'targetgone';

    /**
     * @var string Cleared: the module no longer resolves (its deletion is queued, say) but the import's instance is
     *      still there, so its migrated attempts' keys were released before that deletion could purge them.
     */
    public const NOTE_KEYSRELEASED = 'keys_released';

    /** @var string The stand-in was put back. */
    public const STANDIN_RESTORED = 'restored';

    /** @var string The stand-in recorded in the payload no longer exists. */
    public const STANDIN_MISSING = 'missing';

    /** @var string The stand-in points somewhere other than the activity the import made, so it's left alone. */
    public const STANDIN_CHANGED = 'changed';

    /** @var string The import recorded no stand-in for this assignment. */
    public const STANDIN_NONE = 'none';

    /** @var string[] The map states an import writes, all of which a rollback undoes. */
    private const STATES = [runner::STATE_CREATED, runner::STATE_ATTACHED, runner::STATE_VERIFIED, runner::STATE_FAILED];

    /** @var string The map table. */
    private const MAP = destination::MAP;

    /** @var source_reader Every read of SOLA. */
    private source_reader $reader;

    /** @var \core\lock\lock_factory|null The lock factory, made on first use when none was given. */
    private ?\core\lock\lock_factory $locks;

    /**
     * A rollback over one source.
     *
     * @param source_reader $reader Where SOLA's rows come from (only to list the course's source assignments).
     * @param \core\lock\lock_factory|null $locks The lock factory, the site's own (the runner's lock type) when null.
     */
    public function __construct(source_reader $reader, ?\core\lock\lock_factory $locks = null) {
        $this->reader = $reader;
        $this->locks = $locks;
    }

    /**
     * Roll back one course's import.
     *
     * @param int $courseid The course.
     * @return array [
     *     'courseid' => int,
     *     'noop' => bool, true when no map row of this site belongs to the course (a second rollback),
     *     'refusal' => string, '' or REFUSE_LOCKED or REFUSE_TRANSACTION for the whole course,
     *     'message' => string, why it was a no-op or refused ('' otherwise),
     *     'removed' => int, activities removed,
     *     'cleared' => int, entries with no activity left whose rows were cleared,
     *     'refused' => int, entries refused,
     *     'failed' => int, entries whose transaction failed,
     *     'standinsrestored' => int,
     *     'maprowsdeleted' => int,
     *     'entries' => array, source assignment id to ['sourceid' => int, 'state' => string, 'cmid' => int,
     *         'outcome' => an OUTCOME_*, 'code' => a REFUSE_* or a NOTE_* or '', 'standin' => a STANDIN_*
     *         or '' when nothing was done, 'maprows' => int deleted, 'message' => string],
     * ]. Ids, codes and counts only, never learner text.
     */
    public function rollback_course(int $courseid): array {
        global $DB;

        $result = ['courseid' => $courseid, 'noop' => false, 'refusal' => '', 'message' => '', 'removed' => 0,
            'cleared' => 0, 'refused' => 0, 'failed' => 0, 'standinsrestored' => 0, 'maprowsdeleted' => 0, 'entries' => []];
        if ($DB->is_transaction_started()) {
            return $this->refuse_course($result, self::REFUSE_TRANSACTION);
        }
        $this->locks = $this->locks ?? \core\lock\lock_config::get_lock_factory(runner::LOCK_TYPE);
        $lock = $this->locks->get_lock('course_' . $courseid, 0);
        if (!$lock) {
            return $this->refuse_course($result, self::REFUSE_LOCKED);
        }
        try {
            $maps = $this->maps_for_course($courseid);
            if ($maps === []) {
                $result['noop'] = true;
                $result['message'] = self::str('rollback_noop', $courseid);
                return $result;
            }
            foreach ($maps as $map) {
                $entry = $this->roll_back_entry($map);
                $result['entries'][(int) $map->sourceid] = $entry;
                $result[$entry['outcome']]++;
                $result['standinsrestored'] += $entry['standin'] === self::STANDIN_RESTORED ? 1 : 0;
                $result['maprowsdeleted'] += $entry['maprows'];
            }
            \course_modinfo::purge_course_cache($courseid);
            rebuild_course_cache($courseid, true);
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * The assignment map rows of this site that belong to the course, in source id order.
     *
     * A row belongs when its source assignment is one the reader lists for the course, or its activity, or the
     * stand-in its payload records, is in the course. The last two find a row whose SOLA assignment has gone.
     *
     * @param int $courseid The course.
     * @return \stdClass[]
     */
    private function maps_for_course(int $courseid): array {
        global $DB;

        $own = [];
        if ($this->reader->tables_present()) {
            $own = array_flip(array_map('intval', array_column($this->reader->assignments($courseid), 'id')));
        }
        [$insql, $params] = $DB->get_in_or_equal(self::STATES, SQL_PARAMS_NAMED, 'st');
        $rows = $DB->get_records_select(
            self::MAP,
            "sourcetable = :t AND sourcesiteid = :s AND state {$insql}",
            ['t' => ids::SOURCE_ASSIGN, 's' => ids::site_id()] + $params,
            'sourceid'
        );
        $instances = $DB->get_records_menu('presenterai', ['course' => $courseid], '', 'id, legacyassignid');
        $cms = array_flip(array_map('intval', $DB->get_fieldset_select(
            'course_modules',
            'id',
            'course = :c',
            ['c' => $courseid]
        )));

        $maps = [];
        foreach ($rows as $row) {
            $standin = self::standin_record($row);
            if (
                isset($own[(int) $row->sourceid]) || isset($cms[(int) $row->targetcmid])
                    || (int) ($instances[(int) $row->targetid] ?? 0) === (int) $row->sourceid
                    || ($standin !== null && isset($cms[(int) $standin['cmid']]))
            ) {
                $maps[] = $row;
            }
        }

        return $maps;
    }

    /**
     * Roll back one assignment: check, then (in one transaction) check again, remove, restore and delete.
     *
     * @param \stdClass $map The assignment's map row.
     * @return array The entry (see rollback_course()).
     */
    private function roll_back_entry(\stdClass $map): array {
        global $DB;

        $sourceid = (int) $map->sourceid;
        $entry = ['sourceid' => $sourceid, 'state' => (string) $map->state, 'cmid' => (int) $map->targetcmid,
            'outcome' => self::OUTCOME_REFUSED, 'code' => '', 'standin' => '', 'maprows' => 0, 'message' => ''];
        $resolves = destination::target_resolves($map, $sourceid);
        // The module is being deleted (queued, deletioninprogress) or otherwise broken, but the instance is still
        // the import's: when its deletion runs it would purge SOLA's objects, so its keys are released too.
        $pending = !$resolves && $DB->record_exists('presenterai', ['id' => (int) $map->targetid, 'legacyassignid' => $sourceid]);
        if ($resolves && ($refusal = self::refusal($map)) !== '') {
            return self::refused($entry, $refusal);
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $this->in_transaction($sourceid);
            if ($resolves) {
                // Again, just before anything changes, so an attempt started since the first check is a refusal. This
                // narrows the window without closing it: a delegated transaction doesn't stop another connection
                // inserting an attempt between this check and the delete.
                $refusal = self::refusal($map);
                if ($refusal !== '') {
                    // Nothing of the rollback's was written yet; committing keeps whatever the caller's seam did.
                    $transaction->allow_commit();
                    return self::refused($entry, $refusal);
                }
                $this->remove_activity($map);
            } else if ($pending) {
                self::release_keys((int) $map->targetid);
            }
            $entry['standin'] = self::restore_standin($map);
            $entry['maprows'] = self::delete_map_rows($map);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                // Rolling back rethrows the failure, which is reported below.
                unset($rethrown);
            }
            // Reported by class only, never by its message, which can carry SQL or values.
            $entry['outcome'] = self::OUTCOME_FAILED;
            $entry['standin'] = '';
            $entry['maprows'] = 0;
            $entry['message'] = self::str('rollback_failed', (object) ['id' => $sourceid, 'error' => get_class($e)]);
            return $entry;
        }
        $entry['outcome'] = $resolves ? self::OUTCOME_REMOVED : self::OUTCOME_CLEARED;
        if (!$resolves) {
            $entry['code'] = $pending ? self::NOTE_KEYSRELEASED : self::NOTE_TARGETGONE;
            $entry['message'] = self::str($pending ? 'rollback_keysreleased' : 'rollback_targetgone', $sourceid);
        }

        return $entry;
    }

    /**
     * A seam called first inside each assignment's transaction, before the activity is checked again.
     *
     * @param int $sourceid The source assignment.
     */
    protected function in_transaction(int $sourceid): void {
    }

    /**
     * Delete the course module. A seam, so a test can make the deletion fail inside the transaction.
     *
     * @param int $cmid The course module.
     */
    protected function delete_module(int $cmid): void {
        course_delete_module($cmid);
    }

    /**
     * An entry refused with a code.
     *
     * @param array $entry The entry.
     * @param string $code A REFUSE_* code.
     * @return array
     */
    private static function refused(array $entry, string $code): array {
        $entry['outcome'] = self::OUTCOME_REFUSED;
        $entry['code'] = $code;
        $entry['message'] = self::str('rollback_refuse_' . $code, $entry['sourceid']);

        return $entry;
    }

    /**
     * Forget the keys of the attempts the import made in an activity, so no deletion of it reaches SOLA's objects.
     *
     * Only rows with a legacy recording id: an attempt made in the activity itself holds this plugin's own media,
     * which its deletion should remove.
     *
     * @param int $instanceid The activity instance.
     */
    private static function release_keys(int $instanceid): void {
        global $DB;

        foreach (array_merge(recording_manager::MEDIA_COLUMNS, ['uploadid']) as $column) {
            $DB->set_field_select(
                'presenterai_recording',
                $column,
                null,
                'presenteraiid = :p AND legacyrecid > 0',
                ['p' => $instanceid]
            );
        }
    }

    /**
     * Why an activity that still resolves can't be rolled back, or '' when it can.
     *
     * @param \stdClass $map The assignment's map row.
     * @return string A REFUSE_* code, or ''.
     */
    private static function refusal(\stdClass $map): string {
        global $DB;

        $instanceid = (int) $map->targetid;
        if ((float) $DB->get_field('presenterai', 'grade', ['id' => $instanceid]) != 0) {
            return self::REFUSE_GRADED;
        }
        if ($DB->record_exists('presenterai_recording', ['presenteraiid' => $instanceid, 'legacyrecid' => 0])) {
            return self::REFUSE_LEARNERATTEMPTS;
        }
        // The import gives an attempt one AI score, and only when SOLA had one (the attempt's legacyscoreid). Any
        // other score row was made here afterwards: a teacher's, or a rescore.
        $scores = $DB->get_records_sql("SELECT r.id, r.legacyscoreid, COUNT(s.id) AS total,
                SUM(CASE WHEN s.origin <> :ai THEN 1 ELSE 0 END) AS other
            FROM {presenterai_recording} r
            JOIN {presenterai_score} s ON s.recordingid = r.id
            WHERE r.presenteraiid = :p
            GROUP BY r.id, r.legacyscoreid", ['ai' => \mod_presenterai\local\score_manager::ORIGIN_AI, 'p' => $instanceid]);
        foreach ($scores as $row) {
            if ((int) $row->total > 1 || (int) $row->other > 0 || (int) $row->legacyscoreid === 0) {
                return self::REFUSE_TEACHERSCORES;
            }
        }

        return '';
    }

    /**
     * Delete the activity without letting any of its keys reach a store, and without a recycle bin copy.
     *
     * @param \stdClass $map The assignment's map row.
     */
    private function remove_activity(\stdClass $map): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // The objects these keys name are SOLA's: forget them before deletion would delete them.
        self::release_keys((int) $map->targetid);

        $had = array_key_exists('tool_recyclebin', $CFG->forced_plugin_settings);
        $forced = $had ? $CFG->forced_plugin_settings['tool_recyclebin'] : null;
        $CFG->forced_plugin_settings['tool_recyclebin'] = ['coursebinenable' => 0] + (array) $forced;
        try {
            $this->delete_module((int) $map->targetcmid);
        } finally {
            if ($had) {
                $CFG->forced_plugin_settings['tool_recyclebin'] = $forced;
            } else {
                unset($CFG->forced_plugin_settings['tool_recyclebin']);
            }
        }
    }

    /**
     * Put the stand-in back as the payload recorded it, while it still points at the activity the import made.
     *
     * @param \stdClass $map The assignment's map row.
     * @return string A STANDIN_* value.
     */
    private static function restore_standin(\stdClass $map): string {
        global $DB;

        $standin = self::standin_record($map);
        if ($standin === null) {
            return self::STANDIN_NONE;
        }
        $cm = $DB->get_record('course_modules', ['id' => (int) $standin['cmid']], 'id, instance');
        $url = $cm ? $DB->get_record('url', ['id' => $cm->instance], 'id, externalurl') : false;
        if (!$url) {
            return self::STANDIN_MISSING;
        }
        if (!self::points_at((string) $url->externalurl, (int) $map->targetcmid)) {
            return self::STANDIN_CHANGED;
        }
        $DB->update_record('url', (object) ['id' => $url->id, 'externalurl' => (string) $standin['externalurl'],
            'timemodified' => time()]);
        $DB->update_record('course_modules', (object) ['id' => $cm->id, 'visible' => (int) $standin['visible'],
            'visibleoncoursepage' => (int) $standin['visibleoncoursepage']]);

        return self::STANDIN_RESTORED;
    }

    /**
     * Whether a URL is the learner page of one activity: its path ends in /mod/presenterai/view.php and its id is
     * the cmid. The host and scheme aren't compared, so a stand-in written before a host or https change still counts.
     *
     * @param string $url The URL.
     * @param int $cmid The activity's cmid.
     * @return bool
     */
    private static function points_at(string $url, int $cmid): bool {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return str_ends_with($path, '/mod/presenterai/view.php') && (string) ($query['id'] ?? '') === (string) $cmid;
    }

    /**
     * Delete the assignment's map row and every topic and recording map row of its activity.
     *
     * @param \stdClass $map The assignment's map row.
     * @return int How many rows went.
     */
    private static function delete_map_rows(\stdClass $map): int {
        global $DB;

        $select = 'sourcesiteid = :s AND targetcmid = :c AND sourcetable IN (:topic, :rec)';
        $params = ['s' => ids::site_id(), 'c' => (int) $map->targetcmid, 'topic' => ids::SOURCE_TOPIC,
            'rec' => ids::SOURCE_REC];
        $children = $DB->count_records_select(self::MAP, $select, $params);
        $DB->delete_records_select(self::MAP, $select, $params);
        $DB->delete_records(self::MAP, ['id' => (int) $map->id]);

        return $children + 1;
    }

    /**
     * The stand-in record in a map row's payload, whatever else the payload holds.
     *
     * @param \stdClass $map An assignment map row.
     * @return array|null With cmid, externalurl, visible and visibleoncoursepage; null when there's none.
     */
    private static function standin_record(\stdClass $map): ?array {
        $payload = json_decode((string) $map->payload, true);
        $standin = is_array($payload) ? ($payload[runner::PAYLOAD_STANDIN] ?? null) : null;
        if (!is_array($standin) || empty($standin['cmid']) || !array_key_exists('externalurl', $standin)) {
            return null;
        }

        return $standin + ['visible' => 1, 'visibleoncoursepage' => 1];
    }

    /**
     * A refusal of the whole course.
     *
     * @param array $result The result so far.
     * @param string $code REFUSE_LOCKED or REFUSE_TRANSACTION.
     * @return array The result.
     */
    private function refuse_course(array $result, string $code): array {
        $result['refusal'] = $code;
        $result['message'] = self::str('rollback_refuse_' . $code, $result['courseid']);

        return $result;
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
