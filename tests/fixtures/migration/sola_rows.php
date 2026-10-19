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

namespace mod_presenterai\tests\fixtures;

/**
 * Plain inserts of SOLA-shaped rows, for the tests that read SOLA's tables.
 *
 * SOLA has no data generator, and its own tests insert rows the same way. Every
 * method takes the columns the test cares about and fills the rest with the
 * values a real row carries, so a test reads as the scenario it sets up. The
 * tests are the only code that ever writes these tables: the importer must not.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sola_rows {
    /** @var string The assignment table. */
    public const ASSIGN = 'local_ai_course_assistant_sbx_assign';

    /** @var string The topic table. */
    public const TOPIC = 'local_ai_course_assistant_sbx_topic';

    /** @var string The recording table. */
    public const REC = 'local_ai_course_assistant_sbx_rec';

    /** @var string The score table, shared with SOLA's conversation and pronunciation practice. */
    public const SCORES = 'local_ai_course_assistant_practice_scores';

    /** @var string The rubric table. */
    public const RUBRICS = 'local_ai_course_assistant_rubrics';

    /**
     * Whether every SOLA table the reader touches exists on this site.
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;

        foreach ([self::ASSIGN, self::TOPIC, self::REC, self::SCORES, self::RUBRICS] as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Insert an assignment.
     *
     * @param int $courseid The course.
     * @param array $columns Columns to set, replacing the defaults.
     * @return int The new id.
     */
    public static function assign(int $courseid, array $columns = []): int {
        global $DB;

        return $DB->insert_record(self::ASSIGN, (object) ($columns + [
            'courseid' => $courseid,
            'name' => 'Assignment',
            'intro' => '<p>Present for five minutes.</p>',
            'introformat' => 1,
            'ptype' => 'informative',
            'mode' => 'video',
            'min_seconds' => 300,
            'max_seconds' => 420,
            'max_attempts' => 0,
            'stored_attempts' => 2,
            'rubricid' => null,
            'speaking_level' => null,
            'slides_enabled' => 0,
            'slide_vision' => 0,
            'visible' => 1,
            'usermodified' => 0,
            'timecreated' => 1000,
            'timemodified' => 1000,
        ]));
    }

    /**
     * Insert a topic.
     *
     * @param int $assignid The assignment.
     * @param array $columns Columns to set, replacing the defaults.
     * @return int The new id.
     */
    public static function topic(int $assignid, array $columns = []): int {
        global $DB;

        return $DB->insert_record(self::TOPIC, (object) ($columns + [
            'assignid' => $assignid,
            'title' => 'Topic',
            'instructions' => '<p>Pick a side.</p>',
            'instructionsformat' => 1,
            'pdf_itemid' => 0,
            'sortorder' => 0,
            'timecreated' => 1000,
            'timemodified' => 1000,
        ]));
    }

    /**
     * Insert a recording.
     *
     * @param int $assignid The assignment.
     * @param int $userid The learner.
     * @param array $columns Columns to set, replacing the defaults.
     * @return int The new id.
     */
    public static function rec(int $assignid, int $userid, array $columns = []): int {
        global $DB;

        return $DB->insert_record(self::REC, (object) ($columns + [
            'assignid' => $assignid,
            'userid' => $userid,
            'topicid' => null,
            'mode' => 'video',
            'storage_key' => null,
            'duration_seconds' => 0,
            'size_bytes' => 0,
            'status' => 'uploaded',
            'transcript' => null,
            'deck_key' => null,
            'slide_timeline' => null,
            'frames_key' => null,
            'scoreid' => null,
            'expires_at' => 0,
            'timecreated' => 1000,
        ]));
    }

    /**
     * Insert a practice score, a speech one unless the columns say otherwise.
     *
     * @param int $courseid The course.
     * @param int $userid The learner.
     * @param array $columns Columns to set, replacing the defaults.
     * @return int The new id.
     */
    public static function score(int $courseid, int $userid, array $columns = []): int {
        global $DB;

        return $DB->insert_record(self::SCORES, (object) ($columns + [
            'rubricid' => 0,
            'userid' => $userid,
            'courseid' => $courseid,
            'session_type' => 'speech',
            'scores' => json_encode([['name' => 'Delivery & Fluency', 'score' => 4, 'feedback' => 'Steady.']]),
            'overall_score' => 4,
            'ai_feedback' => 'A good talk.',
            'session_duration' => 0,
            'session_meta' => null,
            'timecreated' => 1000,
        ]));
    }

    /**
     * Insert a rubric.
     *
     * @param array $criteria The criterion definitions, stored as JSON.
     * @param array $columns Columns to set, replacing the defaults.
     * @return int The new id.
     */
    public static function rubric(array $criteria, array $columns = []): int {
        global $DB;

        return $DB->insert_record(self::RUBRICS, (object) ($columns + [
            'courseid' => 0,
            'type' => 'speech',
            'title' => 'Rubric',
            'criteria' => json_encode($criteria),
            'active' => 1,
            'timecreated' => 1000,
            'timemodified' => 1000,
        ]));
    }

    /**
     * A count and a checksum of every SOLA row the importer must leave alone.
     *
     * The five tables the reader reads, and the plugin's own settings, whose changing would mean the reader
     * wrote a config value. Compared before and after a pass, any difference is a write.
     *
     * @return array Label to [row count, sha1 of every row in id order].
     */
    public static function fingerprint(): array {
        global $DB;

        $print = [];
        foreach ([self::ASSIGN, self::TOPIC, self::REC, self::SCORES, self::RUBRICS] as $table) {
            $print[$table] = self::digest($DB->get_records($table, [], 'id ASC'));
        }
        $print['config_plugins'] = self::digest(
            $DB->get_records('config_plugins', ['plugin' => 'local_ai_course_assistant'], 'id ASC')
        );

        return $print;
    }

    /**
     * One table's count and checksum.
     *
     * @param array $records The rows.
     * @return array [row count, checksum].
     */
    private static function digest(array $records): array {
        return [count($records), sha1(serialize(array_map('get_object_vars', array_values($records))))];
    }
}
