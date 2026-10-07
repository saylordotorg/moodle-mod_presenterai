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
 * The judge model could not give a verdict on an attempt's visual feedback.
 *
 * An outage, not a rejection, and a separate event so that a count of
 * rejections is not polluted by a count of timeouts (design 4.7). Fired once
 * per gate run that hit the outage, whether the scoring task then retries or,
 * out of retries, fails closed.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string reason: why there was no verdict, such as not_configured, timeout or bad_response.
 * }
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visual_summary_judge_unavailable extends \core\event\base {
    /**
     * Build the event for one attempt.
     *
     * @param \stdClass $rec The presenterai_recording row being scored.
     * @param \context_module $ctx The activity's module context.
     * @param array $other ['reason' => string]. Anything else is dropped.
     * @return self
     */
    public static function create_from_recording(\stdClass $rec, \context_module $ctx, array $other): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
            'other' => [
                'reason' => (string) ($other['reason'] ?? ''),
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
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'presenterai_recording';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventvisualsummaryjudgeunavailable', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The visual feedback judge was unavailable (reason '{$this->other['reason']}') while checking the "
            . "feedback for the recording with id '{$this->objectid}' made by the user with id '{$this->relateduserid}' "
            . "in the PresenterAI activity with course module id '{$this->contextinstanceid}'.";
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
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
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
     * Nothing in other is an id.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
