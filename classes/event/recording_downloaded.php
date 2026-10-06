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
 * A copy of a recording left the platform.
 *
 * Fired on every download, a learner's own included (design 7.5). Logging only
 * staff downloads makes the log read as surveillance; logging every one makes
 * it an access record, and a copy of a learner's face leaving the site is the
 * single most likely thing an institution is asked about later.
 *
 * Nothing that could fetch the media goes in the event: no key and no URL. A
 * presigned S3 URL is a bearer token, and an event payload is stored, exported
 * and shown in reports (plan section 4.6, point 3).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_downloaded extends \core\event\base {
    /**
     * Build the event for one recording.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @return self
     */
    public static function create_from_recording(\stdClass $rec, \context_module $ctx): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
        ]);
    }

    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'presenterai_recording';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordingdownloaded', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' downloaded the recording with id '{$this->objectid}' "
            . "made by the user with id '{$this->relateduserid}' in the PresenterAI activity with course module id "
            . "'{$this->contextinstanceid}'.";
    }

    /**
     * The activity the recording belongs to.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/presenterai/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Check the event carries what it must.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    /**
     * Recordings are not restored in phase 1, so the object id cannot be mapped.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'presenterai_recording', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Nothing in 'other' needs mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
