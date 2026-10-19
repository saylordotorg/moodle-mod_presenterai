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

use mod_presenterai\local\migration\command;
use mod_presenterai\local\migration\factory;
use mod_presenterai\local\migration\local_aica_reader;
use mod_presenterai\local\migration\object_checker;
use mod_presenterai\local\migration\rollback;
use mod_presenterai\local\migration\runner;
use mod_presenterai\local\migration\s3_object_checker;
use mod_presenterai\local\migration\source_reader;
use mod_presenterai\local\migration\verifier;
use mod_presenterai\tests\fixtures\fake_source;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The migration command: options, exit codes, what it prints, and that a dry run writes nothing.
 *
 * The command's logic is command::execute(), run here in process with the real cli_get_params() and the output
 * written to memory streams. Everything it builds comes from one factory; the test's factory hands out the SOLA
 * double (fake_source) and a storage double, and records what it was asked for, so routing is checked without
 * SOLA's tables or a bucket.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\command
 * @covers     \mod_presenterai\local\migration\factory
 */
final class migration_cli_test extends \advanced_testcase {
    /** @var string A transcript the output may never contain. */
    private const SECRET_TRANSCRIPT = 'ZEBRA-TRANSCRIPT-7731 my private words';

    /** @var string Feedback the output may never contain. */
    private const SECRET_FEEDBACK = 'OKAPI-FEEDBACK-5512 you rushed the ending';

    /** @var string The stand-in URL path. */
    private const PRESENT = '/local/ai_course_assistant/soapbox_present.php';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var fake_source The source double. */
    private fake_source $source;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /** @var \stdClass Another learner. */
    private \stdClass $bob;

    /** @var factory The factory double. */
    private factory $factory;

    /**
     * Load the fixtures.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/migration/sola_rows.php');
        require_once(__DIR__ . '/fixtures/migration/fake_source.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course with sections and two learners, S3 settings that match the double's, stealth allowed.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        \core\session\manager::set_user(get_admin());

        set_config('backend', 's3', 'mod_presenterai');
        set_config('s3bucket', 'archive-course', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3prefix', 'soapbox', 'mod_presenterai');
        $CFG->allowstealth = 1;

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'format' => 'topics']);
        $this->alice = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Wilhelmina',
            'lastname' => 'Quarternight']);
        $this->bob = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Bartholomew',
            'lastname' => 'Zephyrfield']);
        $this->source = new fake_source();
        $this->factory = $this->factory_double();

        if (sola_rows::available()) {
            $assign = sola_rows::assign($this->course->id);
            $score = sola_rows::score($this->course->id, $this->alice->id);
            sola_rows::rec($assign, $this->alice->id, ['scoreid' => $score, 'transcript' => self::SECRET_TRANSCRIPT]);
        }
    }

    /**
     * A factory that hands out the doubles and records what it was asked for.
     *
     * @return factory
     */
    private function factory_double(): factory {
        $source = $this->source;
        $checker = new class implements object_checker {
            /**
             * Every object is there.
             *
             * @param string $key The key.
             * @param int $bytes The size.
             * @return bool
             */
            public function exists_with_size(string $key, int $bytes): bool {
                return true;
            }
        };

        return new class ($source, $checker) extends factory {
            /** @var string[] What was made, in order. */
            public array $made = [];
            /** @var source_reader The source. */
            private source_reader $source;
            /** @var object_checker The storage double. */
            private object_checker $store;

            /**
             * Construct.
             *
             * @param source_reader $source The source.
             * @param object_checker $store The storage double.
             */
            public function __construct(source_reader $source, object_checker $store) {
                $this->source = $source;
                $this->store = $store;
            }

            /**
             * The source.
             *
             * @return source_reader
             */
            public function reader(): source_reader {
                $this->made[] = 'reader';
                return $this->source;
            }

            /**
             * A runner.
             *
             * @param source_reader $reader The source.
             * @return runner
             */
            public function runner(source_reader $reader): runner {
                $this->made[] = 'runner';
                return parent::runner($reader);
            }

            /**
             * A rollback.
             *
             * @param source_reader $reader The source.
             * @return rollback
             */
            public function rollback(source_reader $reader): rollback {
                $this->made[] = 'rollback';
                return parent::rollback($reader);
            }

            /**
             * A verifier.
             *
             * @param source_reader $reader The source.
             * @return verifier
             */
            public function verifier(source_reader $reader): verifier {
                $this->made[] = 'verifier';
                return parent::verifier($reader);
            }

            /**
             * The storage double.
             *
             * @return object_checker
             */
            public function checker(): object_checker {
                $this->made[] = 'checker';
                return $this->store;
            }
        };
    }

    /**
     * Run the command in process.
     *
     * @param array $argv The arguments, without the script name.
     * @return array [int exit code, string stdout, string stderr].
     */
    private function run_command(array $argv): array {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new command($this->factory, $out, $err))->execute($argv);
        rewind($out);
        rewind($err);
        $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }

    /**
     * SOLA's tables, fingerprinted, or nothing on a site without them.
     *
     * @return array
     */
    private function sola_print(): array {
        return sola_rows::available() ? sola_rows::fingerprint() : [];
    }

    /**
     * Row counts of every table a run could touch, and the course's module and URL states.
     *
     * @return array
     */
    private function table_counts(): array {
        global $DB;

        $counts = [];
        foreach ([
            'presenterai', 'presenterai_topic', 'presenterai_recording', 'presenterai_score', 'presenterai_migration_map',
            'presenterai_migreport', 'course_modules', 'course_sections', 'logstore_standard_log', 'url', 'grade_items',
            'context', 'files', 'event', 'task_adhoc', 'lock_db',
        ] as $table) {
            $counts[$table] = $DB->get_manager()->table_exists($table) ? $DB->count_records($table) : -1;
        }
        $counts['cm_fields'] = $DB->get_records_menu('course_modules', ['course' => $this->course->id], 'id',
            'id, ' . $DB->sql_concat('visible', "'/'", 'visibleoncoursepage', "'/'", 'section'));
        $counts['urls'] = $DB->get_records_menu('url', ['course' => $this->course->id], 'id', 'id, externalurl');

        return $counts;
    }

    /**
     * A mod_url stand-in pointing at one Soapbox assignment.
     *
     * @param int $assignid The assignment.
     * @param int $section Its section.
     */
    private function standin(int $assignid, int $section = 2): void {
        global $CFG;

        $url = $CFG->wwwroot . self::PRESENT . '?id=' . $assignid;
        $mod = $this->getDataGenerator()->create_module('url',
            ['course' => $this->course->id, 'externalurl' => $url, 'section' => $section]);
        $this->source->standins[$this->course->id][] = ['cmid' => (int) $mod->cmid, 'assignid' => $assignid,
            'externalurl' => $url, 'visible' => 1, 'visibleoncoursepage' => 1];
    }

    /**
     * Two assignments: one with a stand-in, a spread of recordings and an unreconstructable score, and one with
     * no stand-in (the last-section fallback).
     *
     * @return array Ids: linked, fallback, and the learners' recordings.
     */
    private function seed(): array {
        $src = $this->source;
        $cid = $this->course->id;
        $linked = $src->add_assign($cid);
        $topic = $src->add_topic($linked);
        $s1 = $src->add_score($cid, $this->alice->id, ['ai_feedback' => self::SECRET_FEEDBACK]);
        // No max_score, no rubric, and a name no preset knows: unreconstructed.
        $s2 = $src->add_score($cid, $this->bob->id, ['scores' => json_encode([
            ['name' => 'Rhetorical Flourish', 'score' => 3, 'feedback' => self::SECRET_FEEDBACK],
        ]), 'overall_score' => 3]);
        $ids = ['linked' => $linked];
        $ids['scored'] = $src->add_rec($linked, $this->alice->id, ['status' => 'scored', 'scoreid' => $s1,
            'topicid' => $topic, 'transcript' => self::SECRET_TRANSCRIPT, 'timecreated' => 1100]);
        $ids['expired'] = $src->add_rec($linked, $this->bob->id, ['status' => 'deleted', 'scoreid' => $s2,
            'storage_key' => null, 'timecreated' => 1200, 'expires_at' => 3000]);
        $ids['failed'] = $src->add_rec($linked, $this->bob->id, ['status' => 'failed', 'timecreated' => 1300]);
        $this->standin($linked);
        $ids['fallback'] = $src->add_assign($cid);
        $src->add_rec($ids['fallback'], $this->alice->id, ['status' => 'uploaded', 'transcript' => self::SECRET_TRANSCRIPT]);
        $src->orphans[$cid] = 4;

        return $ids;
    }

    /**
     * Assert output carries no learner text, name or storage key.
     *
     * @param string $output The output.
     */
    private function assert_no_learner_text(string $output): void {
        foreach ([self::SECRET_TRANSCRIPT, 'ZEBRA', self::SECRET_FEEDBACK, 'OKAPI', 'Wilhelmina', 'Quarternight',
                'Bartholomew', 'Zephyrfield', 'soapbox/', '.webm', 'Persuasive talk', 'Rhetorical'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    /**
     * Test the script is a thin CLI script that hands its arguments to the command through the real factory.
     */
    public function test_the_script_is_a_thin_cli_script(): void {
        $script = (string) file_get_contents(dirname(__DIR__) . '/cli/migrate_from_local.php');
        $command = (string) file_get_contents(dirname(__DIR__) . '/classes/local/migration/command.php');

        $this->assertMatchesRegularExpression("/define\\('CLI_SCRIPT', true\\);/", $script);
        $this->assertStringContainsString("require(__DIR__ . '/../../../config.php');", $script);
        $this->assertStringContainsString('new command(new factory())', $script);
        $this->assertStringContainsString('exit(', $script);
        $this->assertLessThan(80, substr_count($script, "\n"));
        $this->assertStringContainsString('cli_get_params(', $command);
        $this->assertStringContainsString('\core\session\manager::set_user(get_admin())', $command);
    }

    /**
     * Test the output goes through cli_writeln and nothing else: no echo, print, var_dump, print_r or the like.
     */
    public function test_output_goes_through_cli_writeln_only(): void {
        foreach (['cli/migrate_from_local.php', 'classes/local/migration/command.php',
                'classes/local/migration/factory.php'] as $relative) {
            $code = '';
            foreach (token_get_all((string) file_get_contents(dirname(__DIR__) . '/' . $relative)) as $token) {
                $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b(echo|print|var_dump|print_r|var_export|printf|vprintf|mtrace|debug_zval_dump|fwrite|fputs|' .
                'file_put_contents|cli_write|readfile|passthru)\b\s*[\(\'"$]/i', $code, $relative);
            $this->assertDoesNotMatchRegularExpression('/\?>|<\?=/', preg_replace('/^<\?php/', '', $code), $relative);
        }
    }

    /**
     * Test the real factory builds the real reader, the S3 checker, and the three workers.
     */
    public function test_the_real_factory_builds_the_real_classes(): void {
        $factory = new factory();
        $reader = $factory->reader();

        $this->assertInstanceOf(local_aica_reader::class, $reader);
        $this->assertInstanceOf(runner::class, $factory->runner($reader));
        $this->assertInstanceOf(rollback::class, $factory->rollback($reader));
        $this->assertInstanceOf(verifier::class, $factory->verifier($reader));
        $this->assertInstanceOf(s3_object_checker::class, $factory->checker());
    }

    /**
     * Test the command runs as the site admin whoever started it.
     */
    public function test_it_runs_as_the_admin(): void {
        global $USER;
        $this->seed();
        \core\session\manager::set_user($this->alice);

        [$code] = $this->run_command(['--course=' . $this->course->id]);

        $this->assertSame(0, $code);
        $this->assertSame((int) get_admin()->id, (int) $USER->id);
    }

    /**
     * Test a dry run prints the counts, every fallback and every decision, and writes nothing.
     */
    public function test_a_dry_run_prints_the_plan_and_writes_nothing(): void {
        $ids = $this->seed();
        $before = $this->table_counts();
        $sola = $this->sola_print();
        $sink = $this->redirectEvents();

        [$code, $out, $err] = $this->run_command(['--course=' . $this->course->id]);

        $this->assertSame(0, $code, $out . $err);
        $this->assertSame('', $err);
        $this->assertSame(0, $sink->count());
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
        $this->assertSame(['reader', 'runner'], $this->factory->made);
        $this->assertStringContainsString(get_string('migration:cli_dryrun', 'mod_presenterai', $this->course->id), $out);
        $this->assertStringContainsString(get_string('migration:cli_orphans', 'mod_presenterai', 4), $out);
        $this->assertStringContainsString(get_string('migration:cli_unreconstructed', 'mod_presenterai', 1), $out);
        $this->assertStringContainsString(get_string('migration:cli_fallback', 'mod_presenterai', $ids['fallback']), $out);
        $this->assertStringContainsString(get_string('migration:standin_rewrite', 'mod_presenterai'), $out);
        $this->assertStringContainsString(get_string('migration:standin_none_fallback_last_section', 'mod_presenterai'), $out);
        $this->assertMatchesRegularExpression('/\b' . $ids['linked'] . '\b.*\bcreate\b/', $out);
        $this->assertMatchesRegularExpression('/\b' . $ids['fallback'] . '\b.*\bcreate\b/', $out);
        $this->assert_no_learner_text($out);
    }

    /**
     * Test the counts line names every status the seed produces.
     */
    public function test_the_counts_line_names_each_status(): void {
        $this->seed();

        [, $out] = $this->run_command(['--course=' . $this->course->id]);

        $this->assertStringContainsString(get_string('migration:cli_counts', 'mod_presenterai', (object) [
            'assignments' => 2, 'topics' => 1, 'recordings' => 4, 'bystatus' => 'failed 1, scored 2, uploaded 1',
            'scores' => 2, 'learners' => 2,
        ]), $out);
    }

    /**
     * Test nothing is written without --apply, and --apply imports.
     */
    public function test_apply_is_required_to_write(): void {
        global $DB;
        $ids = $this->seed();
        $before = $this->table_counts();

        $this->run_command(['--course=' . $this->course->id]);
        $this->assertSame($before, $this->table_counts());
        $this->assertFalse($DB->record_exists('presenterai', ['legacyassignid' => $ids['linked']]));

        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--apply']);

        $this->assertSame(0, $code, $out);
        $this->assertTrue($DB->record_exists('presenterai', ['legacyassignid' => $ids['linked']]));
        $this->assertTrue($DB->record_exists('presenterai', ['legacyassignid' => $ids['fallback']]));
        $this->assertStringContainsString(get_string('migration:cli_applied', 'mod_presenterai', (object) [
            'course' => $this->course->id, 'runid' => $DB->get_field('presenterai_migration_map', 'runid',
                ['sourceid' => $ids['linked'], 'sourcetable' => 'local_ai_course_assistant_sbx_assign']),
        ]), $out);
        $this->assert_no_learner_text($out);
    }

    /**
     * Test --verify goes to the verifier and --rollback to the rollback, each through the factory.
     */
    public function test_verify_and_rollback_route_to_their_classes(): void {
        global $DB;
        $ids = $this->seed();
        $this->run_command(['--course=' . $this->course->id, '--apply']);
        $this->factory->made = [];

        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--verify']);

        $this->assertSame(['reader', 'verifier', 'checker'], $this->factory->made);
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, $DB->count_records('presenterai_migreport', ['courseid' => $this->course->id, 'passed' => 1]));
        $report = $DB->get_record('presenterai_migreport', ['courseid' => $this->course->id]);
        $this->assertSame((int) get_admin()->id, (int) $report->operatorid);
        $this->assertStringContainsString(get_string('migration:cli_verify_passed', 'mod_presenterai', (object) [
            'course' => $this->course->id, 'report' => $report->id, 'runid' => $report->runid]), $out);
        $this->assert_no_learner_text($out);

        $this->factory->made = [];
        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--rollback']);

        $this->assertSame(['reader', 'rollback'], $this->factory->made);
        $this->assertSame(0, $code, $out);
        $this->assertFalse($DB->record_exists('presenterai', ['legacyassignid' => $ids['linked']]));
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
        $this->assert_no_learner_text($out);
    }

    /**
     * Test a failing verification exits 1 and names the mismatch codes with ids only.
     */
    public function test_a_failing_verification_exits_1(): void {
        global $DB;
        $ids = $this->seed();
        $this->run_command(['--course=' . $this->course->id, '--apply']);
        $DB->set_field('presenterai_recording', 'transcript', 'doctored', ['legacyrecid' => $ids['scored']]);

        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--verify']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('transcript_mismatch', $out);
        $this->assertStringContainsString((string) $ids['scored'], $out);
        $this->assertStringNotContainsString('doctored', $out);
    }

    /**
     * Unknown options and the storage backend option, each with a value that must not be echoed.
     *
     * @return array Name to [argument, the fixed message's string id].
     */
    public static function refused_provider(): array {
        return [
            'unknown with a value' => ['--frobnicate=SECRET-VALUE-99', 'migration:cli_unknownoption'],
            'unknown flag' => ['--SECRET-VALUE-99', 'migration:cli_unknownoption'],
            'positional' => ['SECRET-VALUE-99', 'migration:cli_unknownoption'],
            'a reader hook' => ['--reader=SECRET-VALUE-99', 'migration:cli_unknownoption'],
            'backend fs' => ['--backend=fs', 'migration:cli_backend'],
            'backend anything' => ['--backend=SECRET-VALUE-99', 'migration:cli_backend'],
            'backend bare' => ['--backend', 'migration:cli_backend'],
        ];
    }

    /**
     * Test an unknown option or --backend is refused with exit 1 and a fixed message that doesn't echo it.
     *
     * @dataProvider refused_provider
     * @param string $argument The argument.
     * @param string $stringid The message expected.
     */
    public function test_a_refused_option_exits_1_without_echoing_it(string $argument, string $stringid): void {
        $this->seed();
        $before = $this->table_counts();

        [$code, $out, $err] = $this->run_command(['--course=' . $this->course->id, '--apply', $argument]);

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame(get_string($stringid, 'mod_presenterai') . PHP_EOL, $err);
        $this->assertStringNotContainsString('SECRET-VALUE-99', $err);
        $this->assertStringNotContainsString('fs', str_replace(get_string($stringid, 'mod_presenterai'), '', $err));
        $this->assertSame($before, $this->table_counts());
        $this->assertSame([], $this->factory->made);
    }

    /**
     * Option combinations and course values that are refused.
     *
     * @return array Name to [arguments, the fixed message's string id].
     */
    public static function bad_usage_provider(): array {
        return [
            'no course' => [['--apply'], 'migration:cli_course'],
            'course not a number' => [['--course=SECRET-VALUE-99'], 'migration:cli_course'],
            'course zero' => [['--course=0'], 'migration:cli_course'],
            'course bare' => [['--course'], 'migration:cli_course'],
            'apply and rollback' => [['--course=5', '--apply', '--rollback'], 'migration:cli_modes'],
            'apply and verify' => [['--course=5', '--apply', '--verify'], 'migration:cli_modes'],
            'rollback and verify' => [['--course=5', '--rollback', '--verify'], 'migration:cli_modes'],
            'report and apply' => [['--course=5', '--report', '--apply'], 'migration:cli_modes'],
        ];
    }

    /**
     * Test bad usage exits 1 with a fixed message and builds nothing.
     *
     * @dataProvider bad_usage_provider
     * @param array $argv The arguments.
     * @param string $stringid The message expected.
     */
    public function test_bad_usage_exits_1(array $argv, string $stringid): void {
        $before = $this->table_counts();

        [$code, $out, $err] = $this->run_command($argv);

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame(get_string($stringid, 'mod_presenterai') . PHP_EOL, $err);
        $this->assertStringNotContainsString('SECRET-VALUE-99', $err);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame([], $this->factory->made);
    }

    /**
     * Test --help prints the help and exits 0.
     */
    public function test_help(): void {
        [$code, $out] = $this->run_command(['--help']);

        $this->assertSame(0, $code);
        $this->assertSame(get_string('migration:cli_help', 'mod_presenterai') . PHP_EOL, $out);
        foreach (['--course', '--apply', '--rollback', '--verify', '--report', '--json'] as $option) {
            $this->assertStringContainsString($option, $out);
        }
    }

    /**
     * Unmet preconditions, one at a time.
     *
     * @return array Name to [the change to the double, the precondition code].
     */
    public static function precondition_provider(): array {
        return [
            'interlock absent' => ['interlock', 'interlock'],
            'storage differs' => ['storage', 'storage'],
            'scoring queued' => ['queued', 'scoringqueued'],
            'no SOLA tables' => ['tables', 'sourcetables'],
            'backend not S3' => ['backend', 'backend'],
        ];
    }

    /**
     * Test an unmet precondition exits 2, for a dry run, an apply and a course list, and writes nothing.
     *
     * @dataProvider precondition_provider
     * @param string $change What to break.
     * @param string $precondition The code expected.
     */
    public function test_an_unmet_precondition_exits_2(string $change, string $precondition): void {
        $this->seed();
        if ($change === 'interlock') {
            $this->source->interlock = false;
        } else if ($change === 'storage') {
            $this->source->storage['prefix'] = 'elsewhere/';
        } else if ($change === 'queued') {
            $this->source->queued = 2;
        } else if ($change === 'tables') {
            $this->source->tables = false;
        } else {
            set_config('backend', 'fs', 'mod_presenterai');
        }
        $before = $this->table_counts();

        foreach ([['--course=' . $this->course->id], ['--course=' . $this->course->id, '--apply'], ['--course=all'],
                ['--course=all', '--apply']] as $argv) {
            [$code, $out] = $this->run_command($argv);
            $this->assertSame(2, $code, implode(' ', $argv) . "\n" . $out);
            $this->assertStringContainsString('(' . $precondition . ')', $out);
        }
        $this->assertSame($before, $this->table_counts());
    }

    /**
     * Test --verify on a site without SOLA's tables exits 2 and writes no report.
     */
    public function test_verify_without_sola_tables_exits_2(): void {
        global $DB;
        $this->source->tables = false;

        [$code] = $this->run_command(['--course=' . $this->course->id, '--verify']);

        $this->assertSame(2, $code);
        $this->assertSame(0, $DB->count_records('presenterai_migreport'));
    }

    /**
     * Test an assignment refusal exits 1.
     */
    public function test_a_refused_assignment_exits_1(): void {
        $ids = $this->seed();
        $this->standin($ids['linked'], 3);

        [$code, $out] = $this->run_command(['--course=' . $this->course->id]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString(get_string('migration:refuse_duplicatestandins', 'mod_presenterai', $ids['linked']),
            $out);
    }

    /**
     * Test a verified assignment with drift exits 0 and prints the numbers.
     */
    public function test_a_verified_assignment_with_drift_exits_0(): void {
        $ids = $this->seed();
        $this->run_command(['--course=' . $this->course->id, '--apply']);
        $this->source->add_rec($ids['linked'], $this->alice->id, ['status' => 'uploaded']);
        $before = $this->table_counts();

        foreach ([[], ['--apply']] as $extra) {
            [$code, $out] = $this->run_command(array_merge(['--course=' . $this->course->id], $extra));
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString(get_string('migration:cli_drift', 'mod_presenterai',
                (object) ['added' => 1, 'scored' => 0]), $out);
            $this->assertStringContainsString(get_string('migration:verified_drift', 'mod_presenterai',
                (object) ['added' => 1, 'scored' => 0]), $out);
        }
        $this->assertSame($before, $this->table_counts());
    }

    /**
     * Test --json prints one JSON document and nothing else, with the counts and no learner text.
     */
    public function test_json_is_one_document_without_learner_text(): void {
        $ids = $this->seed();

        [$code, $out, $err] = $this->run_command(['--course=' . $this->course->id, '--json']);

        $this->assertSame(0, $code);
        $this->assertSame('', $err);
        $doc = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('dryrun', $doc['mode']);
        $this->assertSame(0, $doc['exitcode']);
        $this->assertCount(1, $doc['courses']);
        $course = $doc['courses'][0];
        $this->assertSame((int) $this->course->id, $course['courseid']);
        $this->assertSame(4, $course['counts']['recordings']);
        $this->assertSame(1, $course['counts']['unreconstructed']);
        $this->assertSame(4, $course['counts']['orphans']);
        $this->assertSame([$ids['fallback']], $course['fallback']);
        $this->assertCount(2, $course['assignments']);
        $this->assert_no_learner_text($out);

        $this->run_command(['--course=' . $this->course->id, '--apply']);
        foreach ([['--verify'], ['--report'], ['--rollback']] as $mode) {
            [$code, $out, $err] = $this->run_command(array_merge(['--course=' . $this->course->id, '--json'], $mode));
            $this->assertSame(0, $code, $out);
            $this->assertSame('', $err);
            $doc = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(ltrim($mode[0], '-'), $doc['mode']);
            $this->assertNotEmpty($doc['courses']);
            $this->assert_no_learner_text($out);
        }
    }

    /**
     * Test --course=all covers every course the reader lists, and a course that's gone is reported, not fatal.
     */
    public function test_all_covers_every_course_and_reports_a_missing_one(): void {
        global $DB;
        $this->seed();
        $other = $this->getDataGenerator()->create_course();
        $this->source->add_assign((int) $other->id);
        $gone = (int) $DB->get_field_sql('SELECT MAX(id) FROM {course}') + 100;
        $this->source->add_assign($gone);

        [$code, $out] = $this->run_command(['--course=all']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString(get_string('migration:cli_dryrun', 'mod_presenterai', $this->course->id), $out);
        $this->assertStringContainsString(get_string('migration:cli_dryrun', 'mod_presenterai', $other->id), $out);
        $this->assertStringContainsString(get_string('migration:refuse_nocourse', 'mod_presenterai', $gone), $out);

        [$code, $out] = $this->run_command(['--course=' . $gone]);
        $this->assertSame(1, $code, $out);
    }

    /**
     * Test --report prints the stored rows, latest per course or all for one, and writes nothing.
     */
    public function test_report_prints_stored_rows_without_writing(): void {
        global $DB;
        $ids = $this->seed();
        $this->run_command(['--course=' . $this->course->id, '--apply']);
        $this->run_command(['--course=' . $this->course->id, '--verify']);
        $DB->set_field('presenterai_recording', 'transcript', 'doctored', ['legacyrecid' => $ids['scored']]);
        $this->run_command(['--course=' . $this->course->id, '--verify']);
        $reports = array_values($DB->get_records('presenterai_migreport', ['courseid' => $this->course->id], 'id'));
        $this->assertCount(2, $reports);
        $before = $this->table_counts();
        $this->factory->made = [];

        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--report']);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->factory->made);
        $this->assertSame($before, $this->table_counts());
        $this->assertStringContainsString('run ' . $reports[0]->runid, $out);
        $this->assertStringContainsString('run ' . $reports[1]->runid, $out);
        $this->assertStringContainsString('transcript_mismatch', $out);

        [$code, $out] = $this->run_command(['--course=all', '--report']);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('run ' . $reports[0]->runid, $out);
        $this->assertStringContainsString('run ' . $reports[1]->runid, $out);
        $this->assertSame($before, $this->table_counts());
        $this->assertStringNotContainsString('doctored', $out);
    }

    /**
     * Test a rollback that refuses an activity exits 1.
     */
    public function test_a_refused_rollback_exits_1(): void {
        global $DB;
        $ids = $this->seed();
        $this->run_command(['--course=' . $this->course->id, '--apply']);
        $DB->set_field('presenterai', 'grade', 100, ['legacyassignid' => $ids['linked']]);

        [$code, $out] = $this->run_command(['--course=' . $this->course->id, '--rollback']);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString(get_string('migration:rollback_refuse_graded', 'mod_presenterai', $ids['linked']),
            $out);
        $this->assertTrue($DB->record_exists('presenterai', ['legacyassignid' => $ids['linked']]));
    }
}
