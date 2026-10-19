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

use mod_presenterai\local\migration\local_aica_reader;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The importer never writes to a SOLA table (D13), pinned two ways.
 *
 * A source scan: the reader contains no write call at all, and no other migration file so much as names a SOLA
 * table, so everything else reaches SOLA through the reader. And a behavioral guard: a full pass of the reader over
 * fixture rows leaves every SOLA row it reads, and SOLA's settings, exactly as they were.
 *
 * The scan reads code, not comments, so a docblock may say what the code must never do. It can't see a table
 * name built at run time, which is why the behavioral guard exists, and why the later tasks' tests should compare
 * the same fingerprint around an apply and a rollback.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\local_aica_reader
 */
final class migration_no_source_write_test extends \advanced_testcase {
    /** @var string The substring every SOLA table name starts with. */
    private const SOLA_PREFIX = 'local_ai_course_assistant_';

    /** @var string[] The files that may name a SOLA table: the contract, and the reader. */
    private const MAY_NAME_SOLA = ['classes/local/migration/ids.php', 'classes/local/migration/local_aica_reader.php'];

    /** @var string The reader. */
    private const READER = 'classes/local/migration/local_aica_reader.php';

    /** @var string[] Database methods that write, change the schema or run raw SQL. */
    private const WRITE_METHODS = [
        'insert_record_raw', 'insert_record', 'insert_records', 'bulk_insert_records', 'import_record',
        'update_record_raw', 'update_record', 'set_field_select', 'set_field', 'delete_records_select',
        'delete_records_list', 'delete_records', 'execute_sql', 'execute', 'get_manager',
    ];

    /** @var string[] Functions that write a setting. */
    private const WRITE_FUNCTIONS = ['set_config', 'unset_config', 'add_to_config_log'];

    /**
     * Load the row helpers.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/migration/sola_rows.php');
        parent::setUpBeforeClass();
    }

    /**
     * The importer's files, by path relative to the plugin: everything under classes/local/migration, and the command.
     *
     * @return array Relative path to absolute path.
     */
    private static function migration_files(): array {
        $root = dirname(__DIR__);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/classes/local/migration',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }
        if (is_file($root . '/cli/migrate_from_local.php')) {
            $files['cli/migrate_from_local.php'] = $root . '/cli/migrate_from_local.php';
        }
        ksort($files);

        return $files;
    }

    /**
     * A file's code with every comment taken out, so prose can't trip a scan and a real call can't hide in it.
     *
     * @param string $php The source.
     * @return string
     */
    private static function code_of(string $php): string {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * Every write, schema change, raw statement and setting write in some code.
     *
     * @param string $code Code, from code_of().
     * @return string[] What was found.
     */
    private static function writes(string $code): array {
        $found = [];
        $patterns = [
            '/(?:->|::)\s*(' . implode('|', self::WRITE_METHODS) . ')\s*\(/i',
            '/(?<![\w>:$\\\\])(' . implode('|', self::WRITE_FUNCTIONS) . ')\s*\(/i',
            '/\b(INSERT\s+INTO|DELETE\s+FROM|TRUNCATE|DROP\s+TABLE|ALTER\s+TABLE|REPLACE\s+INTO|UPDATE\s+\S+\s+SET)\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $matches)) {
                $found = array_merge($found, $matches[1]);
            }
        }

        return $found;
    }

    /**
     * The write calls whose table or statement is a SOLA table, named by its string or by an ids constant.
     *
     * Only the first argument is read, which is the table for a write method and the statement for execute, so a
     * map row that merely carries ids::SOURCE_REC as a value isn't a write to that table.
     *
     * @param string $code Code, from code_of().
     * @return string[] The offending calls.
     */
    private static function sola_table_writes(string $code): array {
        $found = [];
        $pattern = '/(?:->|::)\s*(?:' . implode('|', self::WRITE_METHODS) . ')\s*\(\s*([^,;]*)/i';
        preg_match_all($pattern, $code, $calls, PREG_SET_ORDER);
        foreach ($calls as $call) {
            if (preg_match('/SOURCE_(?:REC|ASSIGN|TOPIC)\b|' . self::SOLA_PREFIX . '|practice_scores/i', $call[1])) {
                $found[] = trim($call[0]);
            }
        }

        return $found;
    }

    /**
     * Test the scan is looking at something: the reader is among the files, and so are the ones that exist already.
     */
    public function test_the_scan_covers_the_importer(): void {
        $files = self::migration_files();

        foreach (['ids', 'source_reader', 'mapper', 'local_aica_reader'] as $name) {
            $this->assertArrayHasKey('classes/local/migration/' . $name . '.php', $files);
        }
    }

    /**
     * Test the reader contains no write call, no raw statement, no schema call and no setting write.
     */
    public function test_the_reader_contains_no_write_call(): void {
        $source = (string) file_get_contents(self::migration_files()[self::READER]);

        $this->assertSame([], self::writes(self::code_of($source)));
    }

    /**
     * Test no migration file but the contract and the reader names a SOLA table, so the rest go through the reader.
     */
    public function test_only_the_contract_and_the_reader_name_a_sola_table(): void {
        foreach (self::migration_files() as $relative => $path) {
            if (in_array($relative, self::MAY_NAME_SOLA, true)) {
                continue;
            }
            $this->assertStringNotContainsString(self::SOLA_PREFIX, self::code_of((string) file_get_contents($path)), $relative);
        }
    }

    /**
     * Test no migration file writes to a SOLA table through an ids constant (a table name the substring check can't see).
     */
    public function test_no_migration_file_writes_through_an_ids_constant(): void {
        foreach (self::migration_files() as $relative => $path) {
            if ($relative === self::READER) {
                continue;
            }
            $this->assertSame([], self::sola_table_writes(self::code_of((string) file_get_contents($path))), $relative);
        }
    }

    /**
     * Code that writes, in each of the ways the scan knows.
     *
     * @return array Name to [code].
     */
    public static function write_provider(): array {
        return [
            'update_record' => ['$DB->update_record(\'x\', $row);'],
            'insert_record' => ['$DB->insert_record(\'x\', $row);'],
            'insert_record_raw' => ['$DB->insert_record_raw(\'x\', $row);'],
            'bulk insert' => ['$DB->bulk_insert_records(\'x\', $rows);'],
            'import_record' => ['$DB->import_record(\'x\', $row);'],
            'set_field' => ['$DB->set_field(\'x\', \'a\', 1, [\'id\' => 1]);'],
            'set_field_select' => ['$DB->set_field_select(\'x\', \'a\', 1, \'id > 1\');'],
            'delete_records' => ['$DB->delete_records(\'x\');'],
            'delete_records_select' => ['$DB->delete_records_select(\'x\', \'id > 1\');'],
            'delete_records_list' => ['$DB->delete_records_list(\'x\', \'id\', [1]);'],
            'execute' => ['$DB->execute(\'SELECT 1\');'],
            'execute_sql' => ['$DB->execute_sql(\'SELECT 1\');'],
            'a schema call' => ['$DB->get_manager()->drop_table($table);'],
            'a spaced call' => ["\$DB\n    ->update_record  (\n'x', \$row);"],
            'a static call' => ['\\moodle_database::update_record(\'x\', $row);'],
            'set_config' => ['set_config(\'a\', 1, \'local_ai_course_assistant\');'],
            'unset_config' => ['unset_config(\'a\', \'local_ai_course_assistant\');'],
            'a raw delete' => ['$DB->get_records_sql(\'DELETE FROM {x} WHERE id > 0\');'],
            'a raw insert' => ['$sql = "INSERT INTO {x} (a) VALUES (1)";'],
            'a raw update' => ['$sql = \'UPDATE {x} SET a = 1\';'],
            'a raw drop' => ['$sql = \'DROP TABLE {x}\';'],
        ];
    }

    /**
     * Test the scan fails on every kind of write, which is what makes its passing mean something.
     *
     * @dataProvider write_provider
     * @param string $snippet Code that writes.
     */
    public function test_the_scan_catches_a_planted_write(string $snippet): void {
        $this->assertNotSame([], self::writes(self::code_of('<?php ' . $snippet)));
    }

    /**
     * Test the scan lets a read through, and lets a comment say what the code must not do.
     */
    public function test_the_scan_passes_reads_and_comments(): void {
        $php = <<<'PHP'
<?php
/**
 * Never $DB->update_record() or set_config(); the SOLA table local_ai_course_assistant_sbx_rec is read only.
 */
// A line comment about $DB->execute('DELETE FROM {x}').
$rows = $DB->get_records_sql('SELECT id FROM {local_ai_course_assistant_sbx_rec} WHERE id = :id', ['id' => 1]);
$one = $DB->get_record('x', ['id' => 1]);
$sets = get_config('local_ai_course_assistant', 'a');
$count = $DB->count_records('task_adhoc', ['classname' => 'x']);
$ok = $DB->get_manager_not_a_call;
PHP;

        $this->assertSame([], self::writes(self::code_of($php)));
    }

    /**
     * Test the table check fails on a write to a SOLA table by name or by constant, and passes a map row.
     */
    public function test_the_table_check_tells_a_sola_write_from_a_map_row(): void {
        $bad = [
            '$DB->update_record(ids::SOURCE_REC, $row);',
            '$DB->delete_records( \\mod_presenterai\\local\\migration\\ids::SOURCE_ASSIGN, [\'id\' => 1]);',
            '$DB->set_field(ids::SOURCE_TOPIC, \'title\', \'x\', [\'id\' => 1]);',
            '$DB->insert_record(\'local_ai_course_assistant_practice_scores\', $row);',
            '$DB->update_record(\'local_ai_course_assistant_sbx_rec\', $row);',
            '$DB->execute("UPDATE {" . ids::SOURCE_REC . "} SET status = 1");',
        ];
        foreach ($bad as $snippet) {
            $this->assertNotSame([], self::sola_table_writes(self::code_of('<?php ' . $snippet)), $snippet);
        }

        $good = '$DB->insert_record(\'presenterai_migration_map\', [\'sourcetable\' => ids::SOURCE_REC, \'sourceid\' => 5]);';
        $this->assertSame([], self::sola_table_writes(self::code_of('<?php ' . $good)));
    }

    /**
     * Fixture rows for a course, with every kind of row the reader reads.
     *
     * @param int $courseid The course.
     * @return array [assign id, score id, rubric id].
     */
    private function fixture_rows(int $courseid): array {
        $rubric = sola_rows::rubric([['name' => 'Delivery & Fluency', 'max_score' => 5]]);
        $assign = sola_rows::assign($courseid, ['rubricid' => $rubric, 'max_attempts' => 0]);
        sola_rows::assign($courseid, ['max_attempts' => 9, 'visible' => 0]);
        $topic = sola_rows::topic($assign);
        $shared = sola_rows::score($courseid, 11, ['rubricid' => $rubric, 'session_meta' => '{"tips":["Slow down"]}']);
        sola_rows::score($courseid, 11);
        sola_rows::rec($assign, 11, ['status' => 'scored', 'scoreid' => $shared, 'topicid' => $topic, 'storage_key' => 'k/1']);
        sola_rows::rec($assign, 12, ['status' => 'scored', 'scoreid' => $shared]);
        sola_rows::rec($assign, 13, ['status' => 'deleted', 'scoreid' => 424242]);
        set_config('soapbox_level_course_' . $courseid, 'esl_beginner', 'local_ai_course_assistant');

        return [$assign, $shared, $rubric];
    }

    /**
     * Call every method of the reader, for the course, its assignments and everything they point at.
     *
     * @param local_aica_reader $reader The reader.
     * @param int $courseid The course.
     * @return array Each method's result, so a pass that returned nothing can be told from one that read the rows.
     */
    private function full_pass(local_aica_reader $reader, int $courseid): array {
        $results = ['courses' => $reader->course_ids_with_data(), 'assignments' => [], 'topics' => [], 'recordings' => [],
            'scores' => [], 'rubrics' => []];
        foreach ($reader->assignments($courseid) as $assign) {
            $results['assignments'][] = $assign;
            $results['topics'][] = $reader->topics($assign['id']);
            $recordings = $reader->recordings($assign['id']);
            $results['recordings'][] = $recordings;
            foreach ($recordings as $recording) {
                $results['scores'][] = $reader->score((int) $recording['scoreid']);
            }
            $results['rubrics'][] = $reader->rubric_criteria((int) $assign['rubricid']);
        }
        $results['other'] = [
            $reader->orphan_score_count($courseid), $reader->shared_score_ids($courseid),
            $reader->speaking_level_for_course($courseid), $reader->preset_criteria('esl_beginner'),
            $reader->storage_settings(), $reader->interlock_present(), $reader->queued_scoring_task_count(),
            $reader->standins($courseid), $reader->site_id(),
        ];

        return $results;
    }

    /**
     * Test a full pass of the reader over fixture rows leaves the SOLA rows and settings exactly as they were.
     */
    public function test_a_full_reader_pass_leaves_the_sola_tables_unchanged(): void {
        $this->require_sola_tables();
        $course = $this->getDataGenerator()->create_course();
        $this->fixture_rows((int) $course->id);
        $before = sola_rows::fingerprint();

        $results = $this->full_pass(new local_aica_reader(), (int) $course->id);

        $this->assertSame($before, sola_rows::fingerprint());
        // The pass really read the rows: an empty one would pass the comparison above for the wrong reason.
        $this->assertCount(2, $results['assignments']);
        $this->assertCount(3, $results['recordings'][0]);
        $this->assertSame(1, $results['other'][0]);
        $this->assertSame([$results['scores'][0]['id']], $results['other'][1]);
        foreach ($before as $label => [$count]) {
            $this->assertGreaterThan(0, $count, $label);
        }
    }

    /**
     * The ways a SOLA row or setting can change.
     *
     * @return array Name to [what changes].
     */
    public static function change_provider(): array {
        return [
            'a changed recording' => ['recording'],
            'a changed score' => ['score'],
            'a new topic' => ['topic'],
            'a removed rubric' => ['rubric'],
            'a changed setting' => ['setting'],
        ];
    }

    /**
     * Test the fingerprint notices a changed row, a new row, a removed row and a changed setting.
     *
     * @dataProvider change_provider
     * @param string $kind What to change.
     */
    public function test_the_fingerprint_notices_a_write(string $kind): void {
        global $DB;
        $this->require_sola_tables();
        $course = $this->getDataGenerator()->create_course();
        [$assign, $score, $rubric] = $this->fixture_rows((int) $course->id);
        $clean = sola_rows::fingerprint();

        match ($kind) {
            'recording' => $DB->set_field(sola_rows::REC, 'status', 'failed', ['assignid' => $assign]),
            'score' => $DB->set_field(sola_rows::SCORES, 'overall_score', 1, ['id' => $score]),
            'topic' => sola_rows::topic($assign),
            'rubric' => $DB->delete_records(sola_rows::RUBRICS, ['id' => $rubric]),
            'setting' => set_config('soapbox_max_recordings', 9, 'local_ai_course_assistant'),
        };

        $this->assertNotSame($clean, sola_rows::fingerprint());
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
}
