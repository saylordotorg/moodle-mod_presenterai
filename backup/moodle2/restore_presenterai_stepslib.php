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

/**
 * Restore structure step for mod_presenterai.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the instance, its topics and rubrics, and with user data its attempts.
 *
 * Every restored attempt ends in one of two honest states: it has media this
 * site can reach, or it is media-less with mediagonereason 'notbackedup' and
 * the attempt list says so (design 8.4, plan 4.7). A row that names bytes
 * which are not there is the state this step exists to rule out.
 *
 * On the File API the bytes travel in the backup. Each file that arrives is
 * given a fresh key, because fs_store resolves a key through whichever row
 * names it, and two rows naming one filename (the original and its restored
 * copy on the same site) would let a delete for one of them find the other's
 * file. A row whose recording did not arrive goes media-less.
 *
 * On S3 the backup carries keys only. A restore on the same site keeps the
 * keys whose objects are still in the bucket, so the restored attempt still
 * plays from the same bucket object, and the shared-key guard
 * (recording_manager::key_in_use_elsewhere()) stops either row's deletion
 * destroying the other's media. A key whose object is gone (a recycle bin
 * restore, after deletion removed the media, is the common case) is dropped,
 * and a missing recording leaves the attempt 'notbackedup'. When the bucket
 * can't be asked, the keys are kept and the restore log says so, because
 * dropping media that may well be there is worse than a key that may not be.
 * A restore anywhere else drops them: the object is in another site's bucket,
 * behind credentials this site does not have.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_presenterai_activity_structure_step extends restore_activity_structure_step {
    /** @var int The rubric the backed up activity pointed at, mapped once the rubrics are in. */
    private $oldrubricid = 0;

    /** @var array New recording id => the scoreid it carried in the backup. */
    private $oldscoreids = [];

    /**
     * The paths this step processes.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [
            new restore_path_element('presenterai', '/activity/presenterai'),
            new restore_path_element('presenterai_topic', '/activity/presenterai/topics/topic'),
            new restore_path_element('presenterai_rubric', '/activity/presenterai/rubrics/rubric'),
        ];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('presenterai_recording', '/activity/presenterai/recordings/recording');
            $paths[] = new restore_path_element(
                'presenterai_score',
                '/activity/presenterai/recordings/recording/scores/score'
            );
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Insert the instance.
     *
     * @param array $data The backed up presenterai element.
     * @return void
     */
    protected function process_presenterai($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        // A backup from before phase 3 has neither; the database defaults would
        // apply anyway, and saying so here keeps a later NOT NULL change honest.
        $data->visualscored = (int) ($data->visualscored ?? 0);
        $data->allowvisualoptout = (int) ($data->allowvisualoptout ?? 0);
        $data->reviewbeforerelease = (int) ($data->reviewbeforerelease ?? 0);

        // The rubric rows are restored after this one, so the reference is
        // remembered and set in after_execute(), once the mapping exists. A
        // carried id would name a rubric in the source context, or on another
        // site an unrelated one.
        $this->oldrubricid = (int) ($data->rubricid ?? 0);
        $data->rubricid = null;

        // A negative grade is a scale id, which the course restore maps.
        if (isset($data->grade) && (int) $data->grade < 0) {
            $data->grade = -((int) $this->get_mappingid('scale', abs((int) $data->grade)));
        }

        $newid = $DB->insert_record('presenterai', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Insert one topic and record its new id, so its file follows it.
     *
     * @param array $data The backed up topic element.
     * @return void
     */
    protected function process_presenterai_topic($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->presenteraiid = $this->get_new_parentid('presenterai');

        $newid = $DB->insert_record('presenterai_topic', $data);
        // Mapped with restorefiles true: the topicfile area is keyed by topic id,
        // and the mapping is how add_related_files() moves each file to its new id.
        $this->set_mapping('presenterai_topic', $oldid, $newid, true);
    }

    /**
     * Insert one rubric into the new module context.
     *
     * @param array $data The backed up rubric element.
     * @return void
     */
    protected function process_presenterai_rubric($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->contextid = $this->task->get_contextid();

        $newid = $DB->insert_record('presenterai_rubric', $data);
        $this->set_mapping('presenterai_rubric', $oldid, $newid);
    }

    /**
     * Insert one attempt, as it was, for its mapped learner.
     *
     * The keys are restored as they were and reconciled in after_execute(),
     * once the files have arrived and it is known which of them did.
     *
     * @param array $data The backed up recording element.
     * @return void
     */
    protected function process_presenterai_recording($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $userid = $this->get_mappingid('user', $data->userid);
        if (!$userid) {
            // A learner who is not in this restore has nobody to own the attempt.
            return;
        }
        $data->userid = $userid;
        $data->presenteraiid = $this->get_new_parentid('presenterai');
        $data->topicid = !empty($data->topicid) ? ($this->get_mappingid('presenterai_topic', $data->topicid) ?: null) : null;
        // Both belong to a browser page on the source course, not to this one.
        $data->clienttoken = null;
        $data->uploadid = null;
        $data->visualoptout = (int) ($data->visualoptout ?? 0);
        // Backups made before the raw note was left out still carry it. It
        // never comes back: its visualdatadays clock ran on the source site.
        $data->visualevidence = null;
        $data->visualevidenceat = 0;
        $oldscoreid = (int) ($data->scoreid ?? 0);
        $data->scoreid = null;

        $newid = $DB->insert_record('presenterai_recording', $data);
        $this->oldscoreids[$newid] = $oldscoreid;
        // Mapped with restorefiles true: the media areas are keyed by recording id.
        $this->set_mapping('presenterai_recording', $oldid, $newid, true);
    }

    /**
     * Insert one score on its restored attempt.
     *
     * @param array $data The backed up score element.
     * @return void
     */
    protected function process_presenterai_score($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $recordingid = $this->get_new_parentid('presenterai_recording');
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$recordingid || !$userid) {
            return;
        }
        $data->recordingid = $recordingid;
        $data->userid = $userid;
        $data->visualstatus = (string) ($data->visualstatus ?? '');
        // A backup from before D28 has no flag, and every score in it had been
        // shown. A held score stays held: restoring doesn't review it.
        $data->released = (int) ($data->released ?? 1) === 0 ? 0 : 1;
        $data->graderid = !empty($data->graderid) ? ($this->get_mappingid('user', $data->graderid) ?: 0) : 0;
        $data->rubricid = $this->restored_rubricid((int) ($data->rubricid ?? 0)) ?? 0;

        $newid = $DB->insert_record('presenterai_score', $data);
        $this->set_mapping('presenterai_score', $oldid, $newid);
    }

    /**
     * The id a backed up rubric reference should have in the restored activity, or null.
     *
     * A rubric backed up with the activity has a mapping. A course level rubric
     * isn't in the activity's backup, but on the same site, when the restored
     * activity can still reach it (a duplicate, or a restore into the same
     * course), the reference is kept: without it the activity would silently
     * fall back to whichever rubric resolve() finds and score against a rubric
     * the teacher didn't choose.
     *
     * @param int $oldid The rubric id in the backup.
     * @return int|null
     */
    private function restored_rubricid(int $oldid): ?int {
        if ($oldid <= 0) {
            return null;
        }
        $mapped = $this->get_mappingid('presenterai_rubric', $oldid);
        if ($mapped) {
            return (int) $mapped;
        }
        if ($this->task->is_samesite()) {
            $context = \context::instance_by_id($this->task->get_contextid(), IGNORE_MISSING);
            if ($context && \mod_presenterai\local\rubric_manager::is_selectable($oldid, $context)) {
                return $oldid;
            }
        }
        return null;
    }

    /**
     * Restore the files, then fix every reference that needed the whole activity in place.
     *
     * @return void
     */
    protected function after_execute() {
        global $DB;

        $this->add_related_files('mod_presenterai', 'intro', null);
        $this->add_related_files('mod_presenterai', 'topicfile', 'presenterai_topic');
        foreach (['recording', 'deck', 'frames'] as $area) {
            $this->add_related_files('mod_presenterai', $area, 'presenterai_recording');
        }

        if ($this->oldrubricid > 0) {
            $rubricid = $this->restored_rubricid($this->oldrubricid);
            if ($rubricid) {
                $DB->set_field('presenterai', 'rubricid', $rubricid, ['id' => $this->task->get_activityid()]);
            }
        }

        foreach ($this->oldscoreids as $recordingid => $oldscoreid) {
            if ($oldscoreid > 0) {
                $scoreid = $this->get_mappingid('presenterai_score', $oldscoreid);
                if ($scoreid) {
                    $DB->set_field('presenterai_recording', 'scoreid', $scoreid, ['id' => $recordingid]);
                }
            }
        }

        $this->reconcile_media();
    }

    /**
     * Leave every restored attempt either with media this site can reach or honestly without it.
     *
     * @return void
     */
    private function reconcile_media(): void {
        global $DB;

        if (empty($this->oldscoreids)) {
            return;
        }

        $fs = get_file_storage();
        $contextid = (int) $this->task->get_contextid();
        $samesite = $this->task->is_samesite();
        $s3kept = false;
        $s3dropped = false;
        $s3missing = false;
        $s3unverified = false;
        $s3exists = self::s3_probe();

        [$insql, $params] = $DB->get_in_or_equal(array_keys($this->oldscoreids), SQL_PARAMS_NAMED, 'rid');
        $rows = $DB->get_records_select(
            'presenterai_recording',
            "id {$insql} AND (storagekey IS NOT NULL OR deckkey IS NOT NULL OR frameskey IS NOT NULL)",
            $params,
            'id',
            'id, backend, storagekey, deckkey, frameskey'
        );
        foreach ($rows as $row) {
            if ((string) $row->backend === 's3') {
                if ($samesite) {
                    $present = self::s3_media_present($row, $s3exists);
                    if (in_array(null, $present, true)) {
                        $s3unverified = true;
                    }
                    if (($present['storagekey'] ?? null) === false) {
                        $this->mark_not_backed_up((int) $row->id);
                        $s3missing = true;
                        continue;
                    }
                    $update = ['id' => (int) $row->id];
                    foreach ($present as $column => $there) {
                        if ($there === false) {
                            $update[$column] = null;
                        }
                    }
                    if (count($update) > 1) {
                        $DB->update_record('presenterai_recording', (object) $update);
                    }
                    $s3kept = true;
                    continue;
                }
                $this->mark_not_backed_up((int) $row->id);
                $s3dropped = true;
                continue;
            }

            // The File API: whatever arrived gets a key no other row has.
            $update = ['id' => (int) $row->id];
            $areas = ['storagekey' => 'recording', 'deckkey' => 'deck', 'frameskey' => 'frames'];
            $recordingarrived = false;
            foreach ($areas as $column => $area) {
                $key = (string) ($row->$column ?? '');
                if ($key === '') {
                    continue;
                }
                $file = $fs->get_file($contextid, 'mod_presenterai', $area, (int) $row->id, '/', $key);
                if (!$file || $file->is_directory()) {
                    $update[$column] = null;
                    continue;
                }
                $newkey = self::fresh_key($key);
                $file->rename('/', $newkey);
                $update[$column] = $newkey;
                if ($column === 'storagekey') {
                    $recordingarrived = true;
                }
            }

            if (!$recordingarrived) {
                // Without the recording the deck and frames are not an attempt's
                // media, so they go too, rather than sit in an area nothing reads.
                foreach ($areas as $area) {
                    $fs->delete_area_files($contextid, 'mod_presenterai', $area, (int) $row->id);
                }
                $this->mark_not_backed_up((int) $row->id);
                continue;
            }
            $DB->update_record('presenterai_recording', (object) $update);
        }

        if ($s3kept) {
            $this->log(get_string('restore_s3_shared', 'mod_presenterai'), backup::LOG_WARNING);
        }
        if ($s3dropped) {
            $this->log(get_string('restore_s3_unreachable', 'mod_presenterai'), backup::LOG_WARNING);
        }
        if ($s3missing) {
            $this->log(get_string('restore_s3_missing', 'mod_presenterai'), backup::LOG_WARNING);
        }
        if ($s3unverified) {
            $this->log(get_string('restore_s3_unverified', 'mod_presenterai'), backup::LOG_WARNING);
        }
    }

    /**
     * Whether each S3 object a restored row names is still in the bucket.
     *
     * @param \stdClass $row The recording row, with storagekey, deckkey and frameskey.
     * @param callable $exists Given a key, returns true, false when the object is gone, or null when unknown.
     * @return array Column => true, false or null, for each column that names a key.
     */
    private static function s3_media_present(\stdClass $row, callable $exists): array {
        $present = [];
        foreach (['storagekey', 'deckkey', 'frameskey'] as $column) {
            $key = (string) ($row->$column ?? '');
            if ($key !== '') {
                $present[$column] = $exists($key);
            }
        }
        return $present;
    }

    /**
     * A probe that asks this site's bucket whether a key's object is there.
     *
     * With S3 not configured the answer is always unknown, so nothing is dropped.
     *
     * @return callable Key => true, false or null.
     */
    private static function s3_probe(): callable {
        $store = null;
        try {
            $store = \mod_presenterai\local\storage\store_factory::for_backend(
                \mod_presenterai\local\storage\store_factory::BACKEND_S3
            );
        } catch (\moodle_exception $e) {
            $store = null;
        }
        return function (string $key) use ($store): ?bool {
            if (!$store instanceof \mod_presenterai\local\storage\s3_store) {
                return null;
            }
            return $store->exists($key);
        };
    }

    /**
     * Mark a restored attempt media-less because its media did not come with it.
     *
     * Status is left as it was (D8): the attempt, its score and its transcript
     * are restored, only the media is not. The visual note goes with the media,
     * as it does in recording_manager::drop_media().
     *
     * @param int $recordingid The restored recording id.
     * @return void
     */
    private function mark_not_backed_up(int $recordingid): void {
        global $DB;

        $DB->update_record('presenterai_recording', (object) [
            'id' => $recordingid,
            'storagekey' => null,
            'deckkey' => null,
            'frameskey' => null,
            'mediadeletedat' => time(),
            'mediagonereason' => 'notbackedup',
            'visualevidence' => null,
            'visualevidenceat' => 0,
            'deletewarnedat' => 0,
        ]);
    }

    /**
     * A new random filename with the old one's extension, as fs_store mints them.
     *
     * @param string $oldkey The key the file arrived under.
     * @return string
     */
    private static function fresh_key(string $oldkey): string {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string) pathinfo($oldkey, PATHINFO_EXTENSION)));

        return random_string(32) . '.' . ($ext !== '' && $ext !== null ? $ext : 'bin');
    }
}
