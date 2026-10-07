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
 * The visual feedback gate rejected a learner facing string (D21, design 4.7).
 *
 * Fired once per rejected string: the summary, or one visual criterion's
 * feedback. It carries which string, which layer and which rule, and never
 * the text. Logs are read by more people, and kept longer, than the staff
 * only presenterai_gatelog table that does hold the text for seven days.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - string target: 'summary' or the criterion name.
 *      - int layer: 1 length or copy, 2 deny list, 3 anchor, 4 judge.
 *      - string rule: the rule that fired.
 * }
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class visual_summary_rejected extends \core\event\base {
    /**
     * Build the event for one rejected string.
     *
     * @param \stdClass $rec The presenterai_recording row the feedback is about.
     * @param \context_module $ctx The activity's module context.
     * @param array $other ['target' => string, 'layer' => int, 'rule' => string]. Anything else is dropped.
     * @return self
     */
    public static function create_from_recording(\stdClass $rec, \context_module $ctx, array $other): self {
        return self::create([
            'context' => $ctx,
            'objectid' => (int) $rec->id,
            'relateduserid' => (int) $rec->userid,
            'other' => [
                'target' => (string) ($other['target'] ?? ''),
                'layer' => (int) ($other['layer'] ?? 0),
                'rule' => (string) ($other['rule'] ?? ''),
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
        return get_string('eventvisualsummaryrejected', 'mod_presenterai');
    }

    /**
     * Non-localised description, as core events give.
     *
     * @return string
     */
    public function get_description() {
        return "The visual feedback gate rejected the '{$this->other['target']}' text at layer "
            . "'{$this->other['layer']}' (rule '{$this->other['rule']}') for the recording with id '{$this->objectid}' "
            . "made by the user with id '{$this->relateduserid}' in the PresenterAI activity with course module id "
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
        foreach (['target', 'layer', 'rule'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
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
