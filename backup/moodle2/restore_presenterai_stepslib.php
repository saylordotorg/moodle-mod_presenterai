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
 * Restores the instance and its topics from presenterai.xml.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_presenterai_activity_structure_step extends restore_activity_structure_step {
    /**
     * The paths this step processes.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [
            new restore_path_element('presenterai', '/activity/presenterai'),
            new restore_path_element('presenterai_topic', '/activity/presenterai/topics/topic'),
        ];
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

        // Rubrics are not in the backup (phase 1 has none, and the rubric table
        // is scoped by context), so a carried rubricid would name a rubric in
        // the source context, or on another site an unrelated one. Null means
        // the default rubric, which is what a restored activity should start
        // from until rubric backup exists.
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
     * Restore the files, once every topic has its new id.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_presenterai', 'intro', null);
        $this->add_related_files('mod_presenterai', 'topicfile', 'presenterai_topic');
    }
}
