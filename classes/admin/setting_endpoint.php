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

namespace mod_presenterai\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

use mod_presenterai\local\ai\security;

/**
 * An admin entered AI endpoint, refused on save unless it passes the SSRF
 * check (D20, plan 5.7).
 *
 * Checking on save tells the admin at the moment they can fix it. It is not
 * the only check: http_client validates every request again, because DNS can
 * change after the form was saved.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_endpoint extends \admin_setting_configtext {
    /**
     * Build the setting.
     *
     * @param string $name The setting name, plugin/name.
     * @param string $visiblename The label.
     * @param string $description The description.
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_URL, 60);
    }

    /**
     * Refuse anything but an empty value or a safe URL.
     *
     * @param string $data The submitted value.
     * @return true|string True when valid, else an error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }
        $data = trim((string) $data);
        if ($data === '' || security::is_safe_url($data)) {
            return true;
        }
        return get_string('error:unsafeendpoint', 'mod_presenterai');
    }
}
