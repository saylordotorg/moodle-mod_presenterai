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

defined('MOODLE_INTERNAL') || die();

/**
 * Test data generator for mod_presenterai.
 *
 * Exists so tests can reach a real module context. Every storage test needs one:
 * fs_store addresses a file by context, area, itemid and name, so a test that
 * faked the context would be testing a different code path from the one a
 * learner uses.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_presenterai_generator extends testing_module_generator {

    /**
     * Create a PresenterAI activity instance.
     *
     * The defaults filled in here are the columns mod_form will always send.
     * They are supplied rather than left to the database defaults so that a
     * later change to install.xml shows up as a failing test rather than as
     * instances that differ depending on which route created them.
     *
     * @param array|stdClass|null $record Fields for the instance, requiring at least course.
     * @param array|null $options Course module options such as section or visible.
     * @return stdClass The instance row, with cmid attached.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'ptype' => 'informative',
            'mode' => 'video',
            'minseconds' => 300,
            'maxseconds' => 420,
            'maxattempts' => 0,
            'storedattempts' => 2,
            'slidesenabled' => 0,
            'slidevision' => 0,
            'videovision' => 0,
            'retentiondays' => -1,
            'gradingmethod' => 'highest',
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }

        return parent::create_instance($record, $options);
    }
}
