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

namespace mod_presenterai\event;

/**
 * A PresenterAI activity was viewed.
 *
 * Exists because the core class is abstract (lib/classes/event/course_module_viewed.php:38):
 * phase 0's view.php called \core\event\course_module_viewed::create() directly,
 * which fatals on the first page view. Every activity module carries a subclass
 * like this one so the log can name the table its objectid points at.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Set the fixed properties of the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'presenterai';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * How restore maps the objectid of a logged event to the restored instance.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'presenterai', 'restore' => 'presenterai'];
    }
}
