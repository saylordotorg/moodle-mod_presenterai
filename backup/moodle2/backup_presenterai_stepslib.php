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
 * The structure of presenterai.xml: the instance and its topics.
 *
 * Phase 1 backs up configuration only. Recordings, scores and rubrics are not
 * included, with or without user data. A recording's backup has rules of its
 * own (plan section 4.7: media on S3 cannot ride in a .mbz, and a large fs
 * recording may need to travel as metadata only), and it lands in phase 2
 * alongside the privacy provider, which has to agree with it about what a
 * recording is. Until then a restored activity starts with no attempts, which
 * is the same thing a course copy without user data gives.
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
        // Every presenterai column except id (the element's attribute) and
        // course (the restore target supplies it).
        $presenterai = new backup_nested_element('presenterai', ['id'], [
            'name', 'intro', 'introformat', 'ptype', 'mode', 'minseconds', 'maxseconds',
            'maxattempts', 'storedattempts', 'rubricid', 'speakinglevel', 'slidesenabled',
            'slidevision', 'videovision', 'retentiondays', 'grade', 'gradingmethod',
            'completionsubmit', 'completionminscore', 'legacyassignid', 'timecreated', 'timemodified',
        ]);

        $topics = new backup_nested_element('topics');
        $topic = new backup_nested_element('topic', ['id'], [
            'title', 'instructions', 'instructionsformat', 'sortorder', 'legacytopicid',
            'timecreated', 'timemodified',
        ]);

        $presenterai->add_child($topics);
        $topics->add_child($topic);

        $presenterai->set_source_table('presenterai', ['id' => backup::VAR_ACTIVITYID]);
        // Topics are the teacher's configuration, not user data, so they are
        // backed up whatever the userinfo setting says.
        $topic->set_source_table(
            'presenterai_topic',
            ['presenteraiid' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );

        $presenterai->annotate_files('mod_presenterai', 'intro', null);
        $topic->annotate_files('mod_presenterai', 'topicfile', 'id');

        return $this->prepare_activity_structure($presenterai);
    }
}
