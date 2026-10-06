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
 * Restore task for mod_presenterai.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/presenterai/backup/moodle2/restore_presenterai_stepslib.php');

/**
 * Provides the steps and rules for one complete restore of a PresenterAI instance.
 *
 * @package    mod_presenterai
 * @category   backup
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_presenterai_activity_task extends restore_activity_task {
    /**
     * No settings specific to this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the one structure step that reads presenterai.xml.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_presenterai_activity_structure_step('presenterai_structure', 'presenterai.xml'));
    }

    /**
     * The content the link decoder rewrites: the intro and each topic's instructions.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('presenterai', ['intro'], 'presenterai'),
            new restore_decode_content('presenterai_topic', ['instructions'], 'presenterai_topic'),
        ];
    }

    /**
     * The rules that turn encoded links back into links to the restored activity.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('PRESENTERAIVIEWBYID', '/mod/presenterai/view.php?id=$1', 'course_module'),
            new restore_decode_rule('PRESENTERAIINDEX', '/mod/presenterai/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Log rules for activity level logs.
     *
     * Only the legacy log store uses these, and this module has never written
     * to it, so the list is empty. Overridden because the parent throws.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Log rules for course level logs, empty for the same reason.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
