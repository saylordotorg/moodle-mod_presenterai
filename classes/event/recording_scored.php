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
 * A score was recorded for a learner's attempt.
 *
 * Fired for every score row written, so a regrade leaves its own trace. The
 * origin says whether a person or the scoring task wrote it; phase 2 only has
 * people.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int scoreid: the presenterai_score row written.
 *      - string origin: 'teacher' or 'ai'.
 * }
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_scored extends \core\event\base {
    /**
     * Build the event for one score row.
     *
     * @param \stdClass $rec The presenterai_recording row that was scored.
     * @param \stdClass $score The presenterai_score row written.
     * @param \context_module $ctx The activity's module context.
     * @return self
     */
    public static function create_from_score(\stdClass $rec, \stdClass $score, \context_module $ctx): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
            'other' => [
                'scoreid' => (int) $score->id,
                'origin' => (string) $score->origin,
            ],
        ]);
    }

    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'presenterai_recording';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordingscored', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' recorded the score with id '{$this->other['scoreid']}' "
            . "(origin '{$this->other['origin']}') for the recording with id '{$this->objectid}' made by the user "
            . "with id '{$this->relateduserid}' in the PresenterAI activity with course module id "
            . "'{$this->contextinstanceid}'.";
    }

    /**
     * The grading screen for the attempt.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/presenterai/grade.php', [
            'id' => $this->contextinstanceid,
            'recordingid' => $this->objectid,
        ]);
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
        if (!isset($this->other['scoreid'])) {
            throw new \coding_exception('The \'scoreid\' value must be set in other.');
        }
        if (!isset($this->other['origin'])) {
            throw new \coding_exception('The \'origin\' value must be set in other.');
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
     * The score id maps through the restored score rows.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['scoreid' => ['db' => 'presenterai_score', 'restore' => 'presenterai_score']];
    }
}
