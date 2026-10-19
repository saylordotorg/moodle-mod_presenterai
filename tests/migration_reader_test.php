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

namespace mod_presenterai;

use mod_presenterai\local\migration\ids;
use mod_presenterai\local\migration\local_aica_reader;
use mod_presenterai\local\migration\mapper;
use mod_presenterai\local\migration\source_reader;
use mod_presenterai\tests\fixtures\interlock_stub;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The SOLA reader, against real rows in SOLA's own tables.
 *
 * The tests that need SOLA's Soapbox tables are skipped on a site that doesn't have them (CI installs only this
 * plugin), and say so. The rest need only core tables and settings, so they run everywhere. Rows are inserted
 * with plain inserts, as SOLA's own tests do: there is no SOLA generator.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\local_aica_reader
 */
final class migration_reader_test extends \advanced_testcase {
    /** @var string SOLA's component, where its settings live. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string The stand-in URL path. */
    private const PRESENT = '/local/ai_course_assistant/soapbox_present.php';

    /** @var string A class constant that exists, standing in for SOLA's interlock constant. */
    private const EXISTING_CONSTANT = '\mod_presenterai\local\migration\ids::SOURCE_REC';

    /**
     * Load the row helpers.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/migration/sola_rows.php');
        require_once(__DIR__ . '/fixtures/migration/interlock_stub.php');
        parent::setUpBeforeClass();
    }

    /**
     * Skip the test when SOLA's Soapbox tables aren't installed, and make it reset what it writes.
     */
    private function require_sola_tables(): void {
        if (!sola_rows::available()) {
            $this->markTestSkipped('SOLA\'s Soapbox tables are not installed on this site.');
        }
        $this->resetAfterTest();
    }

    /**
     * A reader whose preset lookup is a fixture rather than a call into SOLA.
     *
     * @param array|null $source What the lookup returns for every level: criteria and visual, or null.
     * @return local_aica_reader
     */
    private function reader_with_presets(?array $source): local_aica_reader {
        return new local_aica_reader(static fn(string $level): ?array => $source);
    }

    /**
     * Test the reader is a source_reader, which is all the runner may rely on.
     */
    public function test_it_is_a_source_reader(): void {
        $this->assertInstanceOf(source_reader::class, new local_aica_reader());
    }

    /**
     * Test the site id is the one the importer writes to the map.
     */
    public function test_site_id_is_the_importers_site_id(): void {
        $this->assertSame(ids::site_id(), (new local_aica_reader())->site_id());
    }

    /**
     * A reader that sees only the named tables on the site.
     *
     * @param array $names Table names, without the prefix, as the database lists them.
     * @return local_aica_reader
     */
    private function reader_with_tables(array $names): local_aica_reader {
        return new local_aica_reader(null, local_aica_reader::INTERLOCK_CONSTANT, static fn(): array => $names);
    }

    /**
     * Test the tables count as present only when all five are listed, extra tables and list shape notwithstanding.
     */
    public function test_tables_are_present_when_all_five_are_listed(): void {
        $five = [sola_rows::ASSIGN, sola_rows::TOPIC, sola_rows::REC, sola_rows::SCORES, sola_rows::RUBRICS];

        $this->assertTrue($this->reader_with_tables($five)->tables_present());
        $this->assertTrue($this->reader_with_tables(array_merge(['course', 'user', 'url'], $five))->tables_present());
        $this->assertTrue($this->reader_with_tables(array_combine($five, $five))->tables_present(), 'a name => name map');
    }

    /**
     * Test the tables count as present on a site that has SOLA's Soapbox tables.
     */
    public function test_tables_are_present_on_a_site_with_sola(): void {
        $this->require_sola_tables();

        $this->assertTrue((new local_aica_reader())->tables_present());
    }

    /**
     * Which tables are listed in each case where the tables don't all exist.
     *
     * @return array Name to [the table names listed].
     */
    public static function missing_tables_provider(): array {
        $assign = 'local_ai_course_assistant_sbx_assign';
        $topic = 'local_ai_course_assistant_sbx_topic';
        $rec = 'local_ai_course_assistant_sbx_rec';
        $scores = 'local_ai_course_assistant_practice_scores';
        $rubrics = 'local_ai_course_assistant_rubrics';

        return [
            'no tables' => [[]],
            'only core tables' => [['course', 'user', 'url']],
            'no assignment table' => [[$topic, $rec, $scores, $rubrics]],
            'no topic table' => [[$assign, $rec, $scores, $rubrics]],
            'no recording table' => [[$assign, $topic, $scores, $rubrics]],
            'no score table' => [[$assign, $topic, $rec, $rubrics]],
            'no rubric table' => [[$assign, $topic, $rec, $scores]],
            'a similar name is not the table' => [[$assign . '_old', $topic, $rec, $scores, $rubrics]],
        ];
    }

    /**
     * Test one missing table is enough to say they aren't there (the false branch, tested where the tables exist).
     *
     * @dataProvider missing_tables_provider
     * @param array $names The table names listed.
     */
    public function test_tables_are_not_present_when_one_is_missing(array $names): void {
        $this->assertFalse($this->reader_with_tables($names)->tables_present());
    }

    /**
     * Test the answer from the database is the truth, on a site with SOLA's tables and on one without.
     */
    public function test_tables_present_agrees_with_the_database(): void {
        global $DB;
        $expected = true;
        foreach ([sola_rows::ASSIGN, sola_rows::TOPIC, sola_rows::REC, sola_rows::SCORES, sola_rows::RUBRICS] as $table) {
            $expected = $expected && $DB->get_manager()->table_exists($table);
        }

        $this->assertSame($expected, (new local_aica_reader())->tables_present());
    }

    /**
     * Test the courses with data come back once each, ascending.
     */
    public function test_course_ids_with_data_lists_each_course_once_ascending(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $this->assertSame([], $reader->course_ids_with_data());

        sola_rows::assign(42);
        sola_rows::assign(7);
        sola_rows::assign(42);

        $this->assertSame([7, 42], $reader->course_ids_with_data());
    }

    /**
     * Test the assignments of one course come back typed, in id order, and only that course's.
     */
    public function test_assignments_of_one_course(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $second = sola_rows::assign(5, ['name' => 'Second', 'visible' => 0, 'timemodified' => 2000, 'max_attempts' => 2,
            'slides_enabled' => 1, 'slide_vision' => 1, 'mode' => 'audio', 'ptype' => 'persuasive', 'intro' => null]);
        $first = sola_rows::assign(5, ['name' => 'First', 'speaking_level' => 'esl_beginner', 'rubricid' => 9]);
        sola_rows::assign(6, ['name' => 'Other course']);

        $rows = $reader->assignments(5);

        $this->assertSame([$second, $first], array_column($rows, 'id'));
        $this->assertSame([
            'id', 'courseid', 'name', 'intro', 'introformat', 'ptype', 'mode', 'min_seconds', 'max_seconds',
            'max_attempts', 'stored_attempts', 'rubricid', 'speaking_level', 'slides_enabled', 'slide_vision',
            'visible', 'timecreated', 'timemodified',
        ], array_keys($rows[0]));
        $this->assertSame([
            'id' => $second, 'courseid' => 5, 'name' => 'Second', 'intro' => null, 'introformat' => 1,
            'ptype' => 'persuasive', 'mode' => 'audio', 'min_seconds' => 300, 'max_seconds' => 420,
            'max_attempts' => 2, 'stored_attempts' => 2, 'rubricid' => null, 'speaking_level' => null,
            'slides_enabled' => 1, 'slide_vision' => 1, 'visible' => 0, 'timecreated' => 1000, 'timemodified' => 2000,
        ], $rows[0]);
        $this->assertSame(9, $rows[1]['rubricid']);
        $this->assertSame('esl_beginner', $rows[1]['speaking_level']);
        $this->assertSame([], $reader->assignments(99));
    }

    /**
     * The effective cap for a stored value and an admin setting.
     *
     * @return array Name to [stored max_attempts, soapbox_max_recordings (null when unset), expected cap].
     */
    public static function cap_provider(): array {
        return [
            'zero gives the admin cap' => [0, 5, 5],
            'a value above the admin cap clamps to it' => [9, 5, 5],
            'a value below the admin cap passes' => [2, 5, 2],
            'a value equal to the cap passes' => [5, 5, 5],
            'an unset admin cap uses the default, for zero' => [0, null, 3],
            'an unset admin cap uses the default, for a larger value' => [8, null, 3],
            'an unset admin cap lets a smaller value through' => [1, null, 1],
            'a zero admin cap is not positive, so the default' => [0, 0, 3],
            'a negative admin cap is not positive, so the default' => [4, -2, 3],
            'a negative stored value counts as zero' => [-1, 6, 6],
            'a text admin cap reads as zero, so the default' => [0, 'many', 3],
        ];
    }

    /**
     * Test max_attempts is the EFFECTIVE cap, never the stored column (0 means unlimited only in this plugin).
     *
     * @dataProvider cap_provider
     * @param int $stored The stored max_attempts.
     * @param int|string|null $setting The admin setting, null when unset.
     * @param int $expected The cap the reader reports.
     */
    public function test_assignments_report_the_effective_cap(int $stored, $setting, int $expected): void {
        $this->require_sola_tables();
        if ($setting !== null) {
            set_config('soapbox_max_recordings', $setting, self::PLUGIN);
        }
        sola_rows::assign(3, ['max_attempts' => $stored]);

        $rows = (new local_aica_reader())->assignments(3);

        $this->assertSame($expected, $rows[0]['max_attempts']);
    }

    /**
     * Test the reader and SOLA's own soapbox_config agree on the cap, when SOLA is installed.
     *
     * @dataProvider cap_provider
     * @param int $stored The stored max_attempts.
     * @param int|string|null $setting The admin setting, null when unset.
     */
    public function test_the_cap_equals_sola_soapbox_config(int $stored, $setting): void {
        $this->require_sola_tables();
        if (!class_exists('\local_ai_course_assistant\soapbox_config')) {
            $this->markTestSkipped('SOLA\'s soapbox_config class is not installed on this site.');
        }
        if ($setting !== null) {
            set_config('soapbox_max_recordings', $setting, self::PLUGIN);
        }
        sola_rows::assign(3, ['max_attempts' => $stored]);

        $rows = (new local_aica_reader())->assignments(3);

        $this->assertSame(\local_ai_course_assistant\soapbox_config::effective_recording_cap($stored), $rows[0]['max_attempts']);
    }

    /**
     * Test the topics of one assignment come back in sort order, then id order.
     */
    public function test_topics_in_sort_order_then_id_order(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        $other = sola_rows::assign(3);
        $b = sola_rows::topic($assign, ['title' => 'B', 'sortorder' => 2, 'instructions' => null]);
        $c = sola_rows::topic($assign, ['title' => 'C', 'sortorder' => 1]);
        $d = sola_rows::topic($assign, ['title' => 'D', 'sortorder' => 1]);
        sola_rows::topic($other, ['title' => 'Elsewhere']);

        $rows = (new local_aica_reader())->topics($assign);

        $this->assertSame([$c, $d, $b], array_column($rows, 'id'));
        $this->assertSame([
            'id' => $b, 'assignid' => $assign, 'title' => 'B', 'instructions' => null, 'instructionsformat' => 1,
            'sortorder' => 2, 'timecreated' => 1000, 'timemodified' => 1000,
        ], $rows[2]);
        $this->assertSame([], (new local_aica_reader())->topics(0));
    }

    /**
     * Test every status comes back, including a deleted row with no keys, and a row with a score has its scoreid.
     */
    public function test_recordings_include_deleted_rows_and_every_column(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        $score = sola_rows::score(3, 11);
        $topic = sola_rows::topic($assign);
        $alive = sola_rows::rec($assign, 11, [
            'topicid' => $topic, 'mode' => 'video', 'storage_key' => 'soapbox/3/11/a.webm', 'deck_key' => 'soapbox/3/11/a.pdf',
            'frames_key' => 'soapbox/3/11/a.jpg', 'duration_seconds' => 301, 'size_bytes' => 9000000000,
            'status' => 'scored', 'transcript' => 'Fixture transcript one.', 'slide_timeline' => '[{"t":1,"i":0}]',
            'scoreid' => $score, 'expires_at' => 5000, 'timecreated' => 1200,
        ]);
        $gone = sola_rows::rec($assign, 12, ['status' => 'deleted', 'expires_at' => 3000, 'timecreated' => 900]);
        sola_rows::rec(sola_rows::assign(3), 13, ['status' => 'failed']);

        $rows = (new local_aica_reader())->recordings($assign);

        $this->assertSame([$alive, $gone], array_column($rows, 'id'));
        $this->assertSame([
            'id', 'assignid', 'userid', 'topicid', 'mode', 'storage_key', 'deck_key', 'frames_key', 'duration_seconds',
            'size_bytes', 'status', 'transcript', 'slide_timeline', 'scoreid', 'expires_at', 'timecreated',
        ], array_keys($rows[0]));
        $this->assertSame([
            'id' => $alive, 'assignid' => $assign, 'userid' => 11, 'topicid' => $topic, 'mode' => 'video',
            'storage_key' => 'soapbox/3/11/a.webm', 'deck_key' => 'soapbox/3/11/a.pdf', 'frames_key' => 'soapbox/3/11/a.jpg',
            'duration_seconds' => 301, 'size_bytes' => 9000000000, 'status' => 'scored',
            'transcript' => 'Fixture transcript one.', 'slide_timeline' => '[{"t":1,"i":0}]', 'scoreid' => $score,
            'expires_at' => 5000, 'timecreated' => 1200,
        ], $rows[0]);
        $this->assertSame([
            'id' => $gone, 'assignid' => $assign, 'userid' => 12, 'topicid' => null, 'mode' => 'video',
            'storage_key' => null, 'deck_key' => null, 'frames_key' => null, 'duration_seconds' => 0, 'size_bytes' => 0,
            'status' => 'deleted', 'transcript' => null, 'slide_timeline' => null, 'scoreid' => null,
            'expires_at' => 3000, 'timecreated' => 900,
        ], $rows[1]);
    }

    /**
     * Test a recording whose score row no longer exists comes back with scoreid null (the mapper reads that as no score).
     */
    public function test_a_recording_whose_score_row_is_gone_has_scoreid_null(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        $score = sola_rows::score(3, 11);
        // No row has this id, which is what a recording is left with when its score row has been deleted.
        $vanished = $score + 500;
        $with = sola_rows::rec($assign, 11, ['status' => 'scored', 'scoreid' => $score]);
        $without = sola_rows::rec($assign, 12, ['status' => 'scored', 'scoreid' => $vanished]);

        $rows = (new local_aica_reader())->recordings($assign);

        $this->assertSame([$with, $without], array_column($rows, 'id'));
        $this->assertSame($score, $rows[0]['scoreid']);
        $this->assertNull($rows[1]['scoreid']);
        $attempts = mapper::attempt_numbers($rows);
        $this->assertSame([$with => 1, $without => 1], $attempts);
        $this->assertSame(
            'uploaded',
            mapper::recording_status($rows[1], !empty($rows[1]['scoreid']))
        );
    }

    /**
     * Test a score is returned, in full, for a row a recording points at.
     */
    public function test_score_reached_through_scoreid(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        $criteria = json_encode([['name' => 'Content & Relevance', 'score' => 3, 'feedback' => 'Fine.', 'max_score' => 5]]);
        $meta = json_encode(['tips' => ['Slow down'], 'pct' => 60]);
        $score = sola_rows::score(3, 11, [
            'rubricid' => 8, 'scores' => $criteria, 'overall_score' => 3, 'ai_feedback' => 'Good effort.',
            'session_meta' => $meta, 'timecreated' => 1500,
        ]);
        sola_rows::rec($assign, 11, ['status' => 'scored', 'scoreid' => $score]);

        $row = (new local_aica_reader())->score($score);

        $this->assertSame([
            'id' => $score, 'rubricid' => 8, 'userid' => 11, 'courseid' => 3, 'scores' => $criteria,
            'overall_score' => 3, 'ai_feedback' => 'Good effort.', 'session_meta' => $meta, 'timecreated' => 1500,
        ], $row);
    }

    /**
     * Test a row with no meta and no feedback comes back with nulls, which is what the mapper expects.
     */
    public function test_score_keeps_nulls(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        $score = sola_rows::score(3, 11, ['ai_feedback' => null, 'session_meta' => null]);
        sola_rows::rec($assign, 11, ['scoreid' => $score]);

        $row = (new local_aica_reader())->score($score);

        $this->assertNull($row['ai_feedback']);
        $this->assertNull($row['session_meta']);
    }

    /**
     * Test a speech score no recording points at isn't returned, an unknown id is null, and the orphan is counted.
     */
    public function test_an_orphan_score_is_not_returned_and_is_counted(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $assign = sola_rows::assign(3);
        $owned = sola_rows::score(3, 11);
        sola_rows::rec($assign, 11, ['scoreid' => $owned, 'status' => 'scored']);
        $orphan = sola_rows::score(3, 11);
        sola_rows::score(3, 12);
        sola_rows::score(3, 11, ['session_type' => 'conversation']);
        sola_rows::score(4, 11);

        $this->assertNotNull($reader->score($owned));
        $this->assertNull($reader->score($orphan));
        $this->assertNull($reader->score($orphan + 1000));
        $this->assertSame(2, $reader->orphan_score_count(3));
        $this->assertSame(1, $reader->orphan_score_count(4));
        $this->assertSame(0, $reader->orphan_score_count(99));
    }

    /**
     * Test two recordings that share a scoreid are reported, a score used once isn't, and only the course's own.
     */
    public function test_shared_score_ids(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $assign = sola_rows::assign(3);
        $shared = sola_rows::score(3, 11);
        $single = sola_rows::score(3, 11);
        sola_rows::rec($assign, 11, ['scoreid' => $shared, 'status' => 'scored']);
        sola_rows::rec($assign, 12, ['scoreid' => $shared, 'status' => 'scored']);
        sola_rows::rec($assign, 13, ['scoreid' => $single, 'status' => 'scored']);
        sola_rows::rec($assign, 14, ['scoreid' => null]);
        sola_rows::rec($assign, 15, ['scoreid' => null]);

        $this->assertSame([$shared], $reader->shared_score_ids(3));
        $this->assertSame([], $reader->shared_score_ids(4));
    }

    /**
     * Test a score two courses' recordings share is reported to both courses, so neither makes its own copy.
     */
    public function test_a_score_shared_across_courses_is_reported_to_each(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $score = sola_rows::score(3, 11);
        sola_rows::rec(sola_rows::assign(3), 11, ['scoreid' => $score, 'status' => 'scored']);
        sola_rows::rec(sola_rows::assign(4), 11, ['scoreid' => $score, 'status' => 'scored']);

        $this->assertSame([$score], $reader->shared_score_ids(3));
        $this->assertSame([$score], $reader->shared_score_ids(4));
    }

    /**
     * Test two recordings pointing at a score that no longer exists aren't a shared score (neither has one).
     */
    public function test_a_vanished_score_is_not_shared(): void {
        $this->require_sola_tables();
        $assign = sola_rows::assign(3);
        sola_rows::rec($assign, 11, ['scoreid' => 987654, 'status' => 'scored']);
        sola_rows::rec($assign, 12, ['scoreid' => 987654, 'status' => 'scored']);

        $this->assertSame([], (new local_aica_reader())->shared_score_ids(3));
    }

    /**
     * Test the course level comes from the per-course setting, and an unset course is general.
     */
    public function test_speaking_level_for_course(): void {
        $this->resetAfterTest();
        $reader = new local_aica_reader();
        set_config('soapbox_level_course_3', 'esl_beginner', self::PLUGIN);
        set_config('soapbox_level_course_5', 'general', self::PLUGIN);

        $this->assertSame('esl_beginner', $reader->speaking_level_for_course(3));
        $this->assertSame('general', $reader->speaking_level_for_course(5));
        $this->assertSame('general', $reader->speaking_level_for_course(4));
    }

    /**
     * Test a stored rubric comes back by normalised name, a missing maximum is 5, and an inactive rubric still reads.
     */
    public function test_rubric_criteria_for_a_stored_rubric(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $rubric = sola_rows::rubric([
            ['name' => '  Delivery  &   FLUENCY ', 'description' => 'x', 'max_score' => 5],
            ['name' => 'Content', 'max_score' => 10],
            ['name' => 'Language'],
            'not a criterion',
            ['max_score' => 3],
        ], ['active' => 0]);

        $this->assertSame(
            ['delivery & fluency' => 5, 'content' => 10, 'language' => 5],
            $reader->rubric_criteria($rubric)
        );
    }

    /**
     * Test a missing rubric is null, and one with nothing readable is an empty map, not null.
     */
    public function test_rubric_criteria_for_a_missing_rubric(): void {
        global $DB;
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $empty = sola_rows::rubric([]);
        $DB->set_field(sola_rows::RUBRICS, 'criteria', 'not json', ['id' => $empty]);

        $this->assertNull($reader->rubric_criteria(987654));
        $this->assertSame([], $reader->rubric_criteria($empty));
    }

    /**
     * Test a duplicate normalised name takes the last maximum, as SOLA's own score_speech does.
     */
    public function test_rubric_criteria_last_duplicate_wins(): void {
        $this->require_sola_tables();
        $rubric = sola_rows::rubric([['name' => 'Pace', 'max_score' => 5], ['name' => ' pace', 'max_score' => 4]]);

        $this->assertSame(['pace' => 4], (new local_aica_reader())->rubric_criteria($rubric));
    }

    /**
     * Test the preset carries the two visual criteria too, every name normalised (a fixture, not a call into SOLA).
     */
    public function test_preset_criteria_adds_the_two_visual_criteria(): void {
        $reader = $this->reader_with_presets([
            'criteria' => [
                ['name' => 'Delivery & Fluency', 'max_score' => 5],
                ['name' => 'Time  Management', 'max_score' => 4],
            ],
            'visual' => [
                ['name' => 'Body Language & Gestures', 'max_score' => 5, 'visual' => true],
                ['name' => ' Eye Contact & Camera Presence', 'max_score' => 5, 'visual' => true],
            ],
        ]);

        $this->assertSame([
            'delivery & fluency' => 5,
            'time management' => 4,
            'body language & gestures' => 5,
            'eye contact & camera presence' => 5,
        ], $reader->preset_criteria('general'));
    }

    /**
     * Test a SOLA with no visual criteria (before 7.5.1) gives the preset alone, and a maximum left out is 5.
     */
    public function test_preset_criteria_without_visual_criteria(): void {
        $reader = $this->reader_with_presets(['criteria' => [['name' => 'Pace'], ['name' => 'Tone', 'max_score' => 3]]]);

        $this->assertSame(['pace' => 5, 'tone' => 3], $reader->preset_criteria('general'));
    }

    /**
     * Test a preset lookup SOLA can't answer gives an empty map, so the mapper says unreconstructed and nothing is faked.
     */
    public function test_preset_criteria_is_empty_when_sola_cannot_say(): void {
        $this->assertSame([], $this->reader_with_presets(null)->preset_criteria('general'));
    }

    /**
     * Test the level reaches the lookup unchanged, '' included (SOLA treats '' as general).
     */
    public function test_preset_criteria_passes_the_level_through(): void {
        $seen = [];
        $reader = new local_aica_reader(static function (string $level) use (&$seen): array {
            $seen[] = $level;
            return ['criteria' => [], 'visual' => []];
        });

        $reader->preset_criteria('esl_advanced');
        $reader->preset_criteria('');

        $this->assertSame(['esl_advanced', ''], $seen);
    }

    /**
     * Test the real SOLA presets are all in the map, the two visual criteria with the maximum 5, when SOLA is installed.
     */
    public function test_preset_criteria_from_sola_include_the_visual_criteria(): void {
        $class = '\local_ai_course_assistant\rubric_manager';
        if (!class_exists($class) || !defined($class . '::VISUAL_CRITERIA')) {
            $this->markTestSkipped('SOLA\'s rubric_manager is not installed on this site.');
        }
        $reader = new local_aica_reader();

        foreach (array_keys($class::speech_presets()) as $level) {
            $map = $reader->preset_criteria($level);
            foreach ($class::speech_preset($level)['criteria'] as $criterion) {
                $this->assertSame((int) $criterion['max_score'], $map[mapper::normalise_name($criterion['name'])]);
            }
            foreach (['body language & gestures', 'eye contact & camera presence'] as $visual) {
                $this->assertArrayHasKey($visual, $map, $level);
                $this->assertSame(5, $map[$visual], $level . ': ' . $visual);
            }
        }
    }

    /**
     * Test the stored settings come back as they are, the prefix in SOLA's normal form, and no key anywhere.
     */
    public function test_storage_settings_return_bucket_region_prefix_and_never_a_key(): void {
        $this->resetAfterTest();
        set_config('soapbox_storage_bucket', ' my-bucket ', self::PLUGIN);
        set_config('soapbox_storage_region', 'eu-west-2', self::PLUGIN);
        set_config('soapbox_storage_prefix', '/media/soapbox', self::PLUGIN);
        set_config('soapbox_storage_key', 'AKIA-SENTINEL-KEY', self::PLUGIN);
        set_config('soapbox_storage_secret', 'SENTINEL-SECRET-VALUE', self::PLUGIN);

        $settings = (new local_aica_reader())->storage_settings();

        $this->assertSame(['bucket' => 'my-bucket', 'region' => 'eu-west-2', 'prefix' => 'media/soapbox/'], $settings);
        $this->assertStringNotContainsString('SENTINEL', json_encode($settings));
    }

    /**
     * Test unset settings read as SOLA's defaults, which is where its recordings really are.
     */
    public function test_storage_settings_default_to_solas_defaults(): void {
        $this->resetAfterTest();

        $this->assertSame(
            ['bucket' => 'archive-course', 'region' => 'us-east-1', 'prefix' => 'soapbox/'],
            (new local_aica_reader())->storage_settings()
        );
    }

    /**
     * Combinations of the three storage settings; null leaves a setting unset.
     *
     * @return array Name to [bucket, region, prefix].
     */
    public static function storage_provider(): array {
        return [
            'all unset' => [null, null, null],
            'all set' => ['b1', 'eu-west-1', 'p/q'],
            'blank values fall back' => ['  ', '', ' / '],
            'slashes are normalised' => ['b2', 'ap-south-1', '///deep/path///'],
        ];
    }

    /**
     * Test the reader and SOLA's own soapbox_storage agree on all three values, when SOLA is installed.
     *
     * @dataProvider storage_provider
     * @param string|null $bucket The bucket setting, null when unset.
     * @param string|null $region The region setting, null when unset.
     * @param string|null $prefix The prefix setting, null when unset.
     */
    public function test_storage_settings_equal_sola_soapbox_storage(?string $bucket, ?string $region, ?string $prefix): void {
        $this->resetAfterTest();
        if (!class_exists('\local_ai_course_assistant\soapbox_storage')) {
            $this->markTestSkipped('SOLA\'s soapbox_storage class is not installed on this site.');
        }
        foreach (['bucket' => $bucket, 'region' => $region, 'prefix' => $prefix] as $name => $value) {
            if ($value !== null) {
                set_config('soapbox_storage_' . $name, $value, self::PLUGIN);
            }
        }

        $this->assertSame([
            'bucket' => \local_ai_course_assistant\soapbox_storage::bucket(),
            'region' => \local_ai_course_assistant\soapbox_storage::region(),
            'prefix' => \local_ai_course_assistant\soapbox_storage::prefix(),
        ], (new local_aica_reader())->storage_settings());
    }

    /**
     * A reader whose interlock checks name a constant and a helper class of the test's choosing.
     *
     * @param string $constant The class constant, as a string, whose existence means the interlock is present.
     * @param string $helper The class whose site_id() is compared with the importer's.
     * @return local_aica_reader
     */
    private function reader_with_interlock(string $constant, string $helper): local_aica_reader {
        return new local_aica_reader(null, $constant, null, $helper);
    }

    /**
     * Test the interlock is read from the class-constant string and the helper SOLA 7.12.1 adds, and nothing else.
     */
    public function test_the_default_interlock_names_solas_constant_and_helper(): void {
        $this->assertSame(
            '\local_ai_course_assistant\task\soapbox_cleanup::PRESENTERAI_INTERLOCK',
            local_aica_reader::INTERLOCK_CONSTANT
        );
        $this->assertSame('\local_ai_course_assistant\presenterai_interlock', local_aica_reader::INTERLOCK_HELPER);
    }

    /**
     * Test a SOLA that carries both halves is seen as having the interlock, when this site's SOLA does.
     */
    public function test_a_sola_that_carries_the_interlock_is_seen(): void {
        if (!defined(local_aica_reader::INTERLOCK_CONSTANT) || !class_exists(local_aica_reader::INTERLOCK_HELPER)) {
            $this->markTestSkipped('This site\'s SOLA doesn\'t carry the interlock yet.');
        }

        $this->assertTrue((new local_aica_reader())->interlock_present());
    }

    /**
     * Test SOLA's own site id is exactly the importer's, when SOLA has the helper (the two halves of the contract).
     */
    public function test_solas_site_id_equals_the_importers(): void {
        $helper = local_aica_reader::INTERLOCK_HELPER;
        if (!class_exists($helper) || !is_callable([$helper, 'site_id'])) {
            $this->markTestSkipped('This site\'s SOLA has no presenterai_interlock helper.');
        }

        $this->assertSame(ids::site_id(), $helper::site_id());
    }

    /**
     * Test a constant that doesn't exist is no interlock, whatever class it names, even with a helper that agrees.
     */
    public function test_a_missing_constant_means_no_interlock(): void {
        interlock_stub::answer(ids::site_id());

        foreach (['\no_such\class::PRESENTERAI_INTERLOCK', '\mod_presenterai\local\migration\ids::NO_SUCH_CONSTANT'] as $name) {
            $this->assertFalse($this->reader_with_interlock($name, interlock_stub::class)->interlock_present(), $name);
        }
    }

    /**
     * Test a SOLA without the constant has no interlock (the case before SOLA 7.12.1), when this site has one.
     */
    public function test_a_sola_without_the_constant_has_no_interlock(): void {
        if (defined(local_aica_reader::INTERLOCK_CONSTANT)) {
            $this->markTestSkipped('This site\'s SOLA already carries the interlock.');
        }

        $this->assertFalse((new local_aica_reader())->interlock_present());
    }

    /**
     * Test the true branch: the constant is defined and SOLA's site id is exactly the importer's.
     */
    public function test_a_defined_constant_and_an_agreeing_site_id_is_the_interlock(): void {
        interlock_stub::answer(ids::site_id());

        $this->assertTrue($this->reader_with_interlock(self::EXISTING_CONSTANT, interlock_stub::class)->interlock_present());
    }

    /**
     * Test a helper that holds recordings under another site id is no interlock: it would never hold a migrated one.
     */
    public function test_a_different_site_id_is_no_interlock(): void {
        global $CFG;
        $reader = $this->reader_with_interlock(self::EXISTING_CONSTANT, interlock_stub::class);

        $others = [
            'another install' => sha1('another-install'),
            'the old formula with the address' => sha1($CFG->wwwroot . '|' . get_site_identifier()),
            'the same digits in capitals' => strtoupper(ids::site_id()),
            'the id with a space' => ids::site_id() . ' ',
            'nothing' => '',
        ];
        foreach ($others as $label => $siteid) {
            interlock_stub::answer($siteid);
            $this->assertFalse($reader->interlock_present(), $label);
        }
    }

    /**
     * Test a constant with no helper class at all is no interlock: the helper ships in the same release.
     */
    public function test_a_missing_helper_is_no_interlock(): void {
        $reader = $this->reader_with_interlock(self::EXISTING_CONSTANT, '\no_such\helper');

        $this->assertFalse($reader->interlock_present());
    }

    /**
     * Test a class that has no site_id() is no interlock.
     */
    public function test_a_class_without_site_id_is_no_interlock(): void {
        $reader = $this->reader_with_interlock(self::EXISTING_CONSTANT, mapper::class);

        $this->assertFalse($reader->interlock_present());
    }

    /**
     * Test a helper that throws is no interlock, and the exception doesn't escape.
     */
    public function test_a_helper_that_throws_is_no_interlock(): void {
        interlock_stub::answer(ids::site_id(), true);

        $this->assertFalse($this->reader_with_interlock(self::EXISTING_CONSTANT, interlock_stub::class)->interlock_present());
    }

    /**
     * Test the scoring tasks queued or running are counted by their class name, leading backslash and all.
     */
    public function test_queued_scoring_task_count(): void {
        global $DB;
        $this->resetAfterTest();
        $reader = new local_aica_reader();
        $this->assertSame('\local_ai_course_assistant\task\score_recording', local_aica_reader::SCORE_TASK);
        $this->assertSame(0, $reader->queued_scoring_task_count());

        $task = [
            'component' => self::PLUGIN, 'classname' => local_aica_reader::SCORE_TASK, 'nextruntime' => time(),
            'customdata' => '{"recid":1}', 'timecreated' => time(),
        ];
        $DB->insert_record('task_adhoc', (object) $task);
        $DB->insert_record('task_adhoc', (object) ($task + ['timestarted' => time(), 'hostname' => 'h', 'pid' => 1]));
        $DB->insert_record('task_adhoc', (object) (['classname' => '\local_ai_course_assistant\task\other'] + $task));
        $DB->insert_record('task_adhoc', (object) (['classname' => 'local_ai_course_assistant\task\score_recording'] + $task));

        $this->assertSame(2, $reader->queued_scoring_task_count());
    }

    /**
     * Create a url activity and return its course module id.
     *
     * @param \stdClass $course The course.
     * @param string $externalurl The URL.
     * @param array $record More fields for the generator.
     * @return int
     */
    private function url(\stdClass $course, string $externalurl, array $record = []): int {
        $record = ['course' => $course->id, 'externalurl' => $externalurl] + $record;
        $module = $this->getDataGenerator()->create_module('url', $record);

        return (int) $module->cmid;
    }

    /**
     * Test both URL forms are found, two for one assignment are both returned, and other URLs are ignored.
     */
    public function test_standins(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $relative = $this->url($course, self::PRESENT . '?id=7');
        $absolute = $this->url($course, $CFG->wwwroot . self::PRESENT . '?id=8', ['visible' => 0]);
        $twin = $this->url($course, self::PRESENT . '?id=7');
        $extra = $this->url($course, self::PRESENT . '?id=9&lang=en');
        $this->url($course, 'https://example.com/');
        $this->url($course, self::PRESENT . '?id=10x');
        $this->url($course, '/local/ai_course_assistant/soapbox.php?id=11');
        $this->url($other, self::PRESENT . '?id=7');
        $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $relative]);

        $rows = (new local_aica_reader())->standins((int) $course->id);

        $this->assertSame([$relative, $absolute, $twin, $extra], array_column($rows, 'cmid'));
        $this->assertSame([7, 8, 7, 9], array_column($rows, 'assignid'));
        $this->assertSame([
            'cmid' => $relative, 'assignid' => 7, 'externalurl' => self::PRESENT . '?id=7', 'visible' => 1,
            'visibleoncoursepage' => 0,
        ], $rows[0]);
        $this->assertSame([
            'cmid' => $absolute, 'assignid' => 8, 'externalurl' => $CFG->wwwroot . self::PRESENT . '?id=8', 'visible' => 0,
            'visibleoncoursepage' => 1,
        ], $rows[1]);
        $this->assertSame([], (new local_aica_reader())->standins(0));
    }

    /**
     * Test a url activity that's being deleted isn't a stand-in to rewrite.
     */
    public function test_a_standin_being_deleted_is_not_returned(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $live = $this->url($course, self::PRESENT . '?id=7');
        $dying = $this->url($course, self::PRESENT . '?id=8');
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $dying]);

        $this->assertSame([$live], array_column((new local_aica_reader())->standins((int) $course->id), 'cmid'));
    }

    /**
     * Test nothing the reader returns is an object, so a test double built from arrays is a faithful stand-in.
     */
    public function test_every_method_returns_plain_arrays_and_scalars(): void {
        $this->require_sola_tables();
        $reader = new local_aica_reader();
        $course = $this->getDataGenerator()->create_course();
        $assign = sola_rows::assign((int) $course->id);
        $score = sola_rows::score((int) $course->id, 11, ['session_meta' => '{"tips":["a"]}']);
        sola_rows::topic($assign);
        sola_rows::rec($assign, 11, ['scoreid' => $score, 'status' => 'scored']);
        $this->url($course, self::PRESENT . '?id=' . $assign);

        $results = [
            $reader->course_ids_with_data(), $reader->assignments((int) $course->id), $reader->topics($assign),
            $reader->recordings($assign), $reader->score($score), $reader->orphan_score_count((int) $course->id),
            $reader->shared_score_ids((int) $course->id), $reader->speaking_level_for_course((int) $course->id),
            $reader->rubric_criteria(1), $reader->preset_criteria('general'), $reader->storage_settings(),
            $reader->interlock_present(), $reader->queued_scoring_task_count(), $reader->standins((int) $course->id),
        ];

        $this->assertSame([], $this->objects_in($results));
    }

    /**
     * Find every object inside a value.
     *
     * @param mixed $value The value.
     * @return array The class names found.
     */
    private function objects_in($value): array {
        if (is_object($value)) {
            return [get_class($value)];
        }

        return is_array($value) ? array_merge([], ...array_values(array_map([$this, 'objects_in'], $value))) : [];
    }
}
