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
            'storedattempts' => 0,
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

    /**
     * Create a recording row directly, without going through the upload flow.
     *
     * The defaults describe a finished attempt with media on fs, which is the
     * state most tests start from. Tests that need another state pass it.
     *
     * @param array|stdClass $record Fields for the row, requiring presenteraiid and userid.
     * @return stdClass The row as read back from the database.
     */
    public function create_recording($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->presenteraiid) || empty($record->userid)) {
            throw new coding_exception('create_recording needs presenteraiid and userid');
        }
        $now = time();
        $defaults = [
            'topicid' => null,
            'attemptnumber' => 1,
            'mode' => 'video',
            'backend' => 'fs',
            'storagekey' => null,
            'deckkey' => null,
            'frameskey' => null,
            'audiokey' => null,
            'uploadid' => null,
            'slidetimeline' => null,
            'durationseconds' => 60,
            'sizebytes' => 0,
            'status' => 'uploaded',
            'expiresat' => 0,
            'mediadeletedat' => 0,
            'mediagonereason' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        foreach ($defaults as $name => $value) {
            if (!property_exists($record, $name)) {
                $record->$name = $value;
            }
        }

        $id = $DB->insert_record('presenterai_recording', $record);
        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a topic row for an instance.
     *
     * @param array|stdClass $record Fields for the row, requiring presenteraiid.
     * @return stdClass The row as read back from the database.
     */
    public function create_topic($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->presenteraiid)) {
            throw new coding_exception('create_topic needs presenteraiid');
        }
        $now = time();
        $defaults = [
            'title' => 'Topic',
            'instructions' => '',
            'instructionsformat' => FORMAT_HTML,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        foreach ($defaults as $name => $value) {
            if (!property_exists($record, $name)) {
                $record->$name = $value;
            }
        }

        $id = $DB->insert_record('presenterai_topic', $record);
        return $DB->get_record('presenterai_topic', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a score row for a recording.
     *
     * The recording's scoreid is NOT updated, so a test can set up a stale or
     * missing pointer on purpose; tests that need it set do it themselves.
     *
     * @param array|stdClass $record Fields for the row, requiring recordingid.
     * @return stdClass The row as read back from the database.
     */
    public function create_score($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->recordingid)) {
            throw new coding_exception('create_score needs recordingid');
        }
        $defaults = [
            'userid' => (int) $DB->get_field('presenterai_recording', 'userid', ['id' => $record->recordingid], MUST_EXIST),
            'rubricid' => 0,
            'origin' => 'teacher',
            'scores' => '[]',
            'rawsum' => 0,
            'rawmax' => 0,
            'overallpct' => null,
            'scoreprovenance' => 'exact',
            'feedback' => null,
            'tips' => null,
            'graderid' => 0,
            'timecreated' => time(),
        ];
        foreach ($defaults as $name => $value) {
            if (!property_exists($record, $name)) {
                $record->$name = $value;
            }
        }

        $id = $DB->insert_record('presenterai_score', $record);
        return $DB->get_record('presenterai_score', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a rubric row.
     *
     * @param array|stdClass $record Fields for the row, requiring contextid.
     * @return stdClass The row as read back from the database.
     */
    public function create_rubric($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->contextid)) {
            throw new coding_exception('create_rubric needs contextid');
        }
        $now = time();
        $defaults = [
            'type' => 'speech',
            'title' => 'Rubric',
            'criteria' => json_encode(\mod_presenterai\local\rubric_manager::DEFAULT_CRITERIA),
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        foreach ($defaults as $name => $value) {
            if (!property_exists($record, $name)) {
                $record->$name = $value;
            }
        }

        $id = $DB->insert_record('presenterai_rubric', $record);
        return $DB->get_record('presenterai_rubric', ['id' => $id], '*', MUST_EXIST);
    }
}
