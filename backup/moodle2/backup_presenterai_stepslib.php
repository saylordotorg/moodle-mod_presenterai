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
 * Backup structure step for mod_presenterai.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The structure of presenterai.xml: the instance, its topics and rubrics, and with user data its attempts.
 *
 * Configuration (the instance, its topics, the rubrics defined in its own
 * context) is always backed up. Learner work (recordings and the scores on
 * them) is backed up only when the backup includes user data, and never an
 * attempt still uploading, which has no finished media and whose page cannot
 * come back to it on another course.
 *
 * Media follows plan section 4.7. On the File API the recording, deck and
 * frame sheet are annotated, so the bytes travel inside the .mbz. On S3 only
 * the rows travel, carrying their keys, because the bytes are in a bucket the
 * backup cannot reach; the restore keeps the keys on the same site and drops
 * them elsewhere. No S3 setting, credential or other secret is ever written
 * here, and neither are clienttoken and uploadid, which belong to a page that
 * no longer exists.
 *
 * presenterai_gatelog is never backed up. It holds text the visual feedback
 * gate refused to show a learner, kept seven days for staff to tune the gate,
 * and a copy in a backup file would outlive that clock indefinitely.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_presenterai_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the tree, its sources and its file annotations.
     *
     * @return backup_nested_element The root element wrapped in the standard activity structure.
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Every presenterai column except id (the element's attribute) and
        // course (the restore target supplies it).
        $presenterai = new backup_nested_element('presenterai', ['id'], [
            'name', 'intro', 'introformat', 'ptype', 'mode', 'minseconds', 'maxseconds',
            'maxattempts', 'storedattempts', 'rubricid', 'speakinglevel', 'slidesenabled',
            'slidevision', 'videovision', 'visualscored', 'allowvisualoptout', 'retentiondays', 'grade', 'gradingmethod',
            'completionsubmit', 'completionminscore', 'legacyassignid', 'timecreated', 'timemodified',
        ]);

        $topics = new backup_nested_element('topics');
        $topic = new backup_nested_element('topic', ['id'], [
            'title', 'instructions', 'instructionsformat', 'sortorder', 'legacytopicid',
            'timecreated', 'timemodified',
        ]);

        $rubrics = new backup_nested_element('rubrics');
        $rubric = new backup_nested_element('rubric', ['id'], [
            'type', 'title', 'criteria', 'active', 'legacyrubricid', 'timecreated', 'timemodified',
        ]);

        // Every recording column except id, presenteraiid (the parent), and the
        // two that belong to an upload page: clienttoken and uploadid.
        $recordings = new backup_nested_element('recordings');
        $recording = new backup_nested_element('recording', ['id'], [
            'userid', 'topicid', 'attemptnumber', 'mode', 'backend', 'storagekey', 'deckkey', 'frameskey',
            'slidetimeline', 'visualevidence', 'visualevidenceat', 'visualoptout', 'durationseconds', 'sizebytes', 'status',
            'transcript', 'scoreid', 'expiresat', 'mediadeletedat', 'mediagonereason', 'deletewarnedat',
            'legacyrecid', 'legacyscoreid', 'timecreated', 'timemodified',
        ]);

        // Every score column except id and recordingid (the parent).
        $scores = new backup_nested_element('scores');
        $score = new backup_nested_element('score', ['id'], [
            'userid', 'rubricid', 'origin', 'scores', 'rawsum', 'rawmax', 'overallpct', 'scoreprovenance',
            'feedback', 'tips', 'visualsummary', 'visualstatus', 'legacymeanscore', 'legacymeta', 'graderid', 'timecreated',
        ]);

        $presenterai->add_child($topics);
        $topics->add_child($topic);
        $presenterai->add_child($rubrics);
        $rubrics->add_child($rubric);
        $presenterai->add_child($recordings);
        $recordings->add_child($recording);
        $recording->add_child($scores);
        $scores->add_child($score);

        $presenterai->set_source_table('presenterai', ['id' => backup::VAR_ACTIVITYID]);
        // Topics are the teacher's configuration, not user data, so they are
        // backed up whatever the userinfo setting says.
        $topic->set_source_table(
            'presenterai_topic',
            ['presenteraiid' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );
        // So are the rubrics defined in this activity's own context. A rubric
        // in a course or category context belongs to that context's backup.
        $rubric->set_source_sql(
            'SELECT * FROM {presenterai_rubric} WHERE contextid = ? ORDER BY id',
            [backup::VAR_CONTEXTID]
        );

        if ($userinfo) {
            $recording->set_source_sql(
                "SELECT * FROM {presenterai_recording} WHERE presenteraiid = ? AND status <> 'uploading' ORDER BY id",
                [backup::VAR_PARENTID]
            );
            $score->set_source_table('presenterai_score', ['recordingid' => backup::VAR_PARENTID], 'id ASC');
        }

        $recording->annotate_ids('user', 'userid');
        $score->annotate_ids('user', 'userid');
        $score->annotate_ids('user', 'graderid');

        $presenterai->annotate_files('mod_presenterai', 'intro', null);
        $topic->annotate_files('mod_presenterai', 'topicfile', 'id');
        // On the File API these carry the bytes; on S3 the areas are empty and
        // the keys in the rows are all there is.
        $recording->annotate_files('mod_presenterai', 'recording', 'id');
        $recording->annotate_files('mod_presenterai', 'deck', 'id');
        $recording->annotate_files('mod_presenterai', 'frames', 'id');

        return $this->prepare_activity_structure($presenterai);
    }
}
