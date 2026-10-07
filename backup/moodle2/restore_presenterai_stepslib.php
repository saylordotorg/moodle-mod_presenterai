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
 * On S3 the backup carries keys only. A restore on the same site keeps them,
 * so the restored attempt still plays from the same bucket object, and the
 * shared-key guard (recording_manager::key_in_use_elsewhere()) stops either
 * row's deletion destroying the other's media. A restore anywhere else drops
 * them: the object is in another site's bucket, behind credentials this site
 * does not have.
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
        $data->graderid = !empty($data->graderid) ? ($this->get_mappingid('user', $data->graderid) ?: 0) : 0;
        $data->rubricid = !empty($data->rubricid) ? ($this->get_mappingid('presenterai_rubric', $data->rubricid) ?: 0) : 0;

        $newid = $DB->insert_record('presenterai_score', $data);
        $this->set_mapping('presenterai_score', $oldid, $newid);
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
            $rubricid = $this->get_mappingid('presenterai_rubric', $this->oldrubricid);
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
