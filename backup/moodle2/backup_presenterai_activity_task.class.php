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
 * Backup task for mod_presenterai.
 *
 * presenterai_supports(FEATURE_BACKUP_MOODLE2) has returned true since phase 0,
 * and until these classes existed that declaration made every course backup
 * containing the activity fail. Follows mod_folder's task (the smallest core
 * activity with a file area) in shape.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/presenterai/backup/moodle2/backup_presenterai_stepslib.php');

/**
 * Provides the steps for one complete backup of a PresenterAI instance.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_presenterai_activity_task extends backup_activity_task {
    /**
     * No settings specific to this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the one structure step that writes presenterai.xml.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_presenterai_activity_structure_step('presenterai_structure', 'presenterai.xml'));
    }

    /**
     * Encode links to this module's index.php and view.php so restore can rewrite them.
     *
     * @param string $content HTML that may contain links to the activity's scripts.
     * @return string The content with those links encoded.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = '/(' . $base . '\/mod\/presenterai\/index.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@PRESENTERAIINDEX*$2@$', $content);

        $search = '/(' . $base . '\/mod\/presenterai\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@PRESENTERAIVIEWBYID*$2@$', $content);

        return $content;
    }
}
