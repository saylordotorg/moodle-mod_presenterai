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
 * A recording's media was deleted on request, by its learner or by staff.
 *
 * The media only: the attempt, its score and its feedback survive. A member of
 * staff deleting somebody else's recording must leave a trace, and the reason
 * ('learner' or 'manual', as stored in mediagonereason) says which kind of
 * delete it was. Retention and pruning do not fire this; they are scheduled,
 * announced and recorded on the row.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string reason: why the media went, one of the mediagonereason values.
 * }
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_deleted extends \core\event\base {
    /**
     * Build the event for one recording.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param string $reason The mediagonereason written to the row.
     * @return self
     */
    public static function create_from_recording(\stdClass $rec, \context_module $ctx, string $reason): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
            'other' => ['reason' => $reason],
        ]);
    }

    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'presenterai_recording';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordingdeleted', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' deleted the media of the recording with id '{$this->objectid}' "
            . "made by the user with id '{$this->relateduserid}' in the PresenterAI activity with course module id "
            . "'{$this->contextinstanceid}'. Reason: '{$this->other['reason']}'.";
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
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
        }
    }

    /**
     * The recording, which a restore with user data maps to its new id.
     *
     * Recordings travel in a backup that includes user data from phase 2 on,
     * and the restore step records each one under 'presenterai_recording'.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'presenterai_recording', 'restore' => 'presenterai_recording'];
    }

    /**
     * The reason is a word, not an id, so nothing in 'other' needs mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
