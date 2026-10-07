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
 * A learner's attempt was finalized and now counts.
 *
 * Fired once per attempt, on the move from uploading to finalized. A retried
 * finalize that finds the attempt already done doesn't fire it again, so a
 * log reader can count submissions by counting these.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int attemptnumber: which attempt this is for the learner.
 * }
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_submitted extends \core\event\base {
    /**
     * Build the event for one recording.
     *
     * @param \stdClass $rec The presenterai_recording row, as finalized.
     * @param \context_module $ctx The activity's module context.
     * @return self
     */
    public static function create_from_recording(\stdClass $rec, \context_module $ctx): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
            'other' => ['attemptnumber' => (int) $rec->attemptnumber],
        ]);
    }

    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'presenterai_recording';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordingsubmitted', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' submitted attempt '{$this->other['attemptnumber']}', the recording "
            . "with id '{$this->objectid}', in the PresenterAI activity with course module id "
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
        if (!isset($this->other['attemptnumber'])) {
            throw new \coding_exception('The \'attemptnumber\' value must be set in other.');
        }
    }

    /**
     * Recordings are restored with learner data, so the object id maps.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'presenterai_recording', 'restore' => 'presenterai_recording'];
    }

    /**
     * The attempt number is a count, not an id, so nothing in 'other' needs mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
