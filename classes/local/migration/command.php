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

namespace mod_presenterai\local\migration;

/**
 * The logic of cli/migrate_from_local.php: options, which worker runs, what's printed, and the exit code.
 *
 * Kept out of the script so PHPUnit can run it in process: execute() returns the exit code instead of exiting, and
 * the output goes to streams the caller gives (STDOUT and STDERR from the script). Everything is printed with
 * cli_writeln(). Everything it builds comes from one factory.
 *
 * Modes: a dry run (no mode option), --apply, --rollback, --verify and --report, one at a time. --course is a course
 * id or "all" (every course the reader lists; for --report, every course with a report). --json prints the same
 * data as one JSON document and nothing else on standard output.
 *
 * Exit codes: EXIT_OK (done, or a clean dry run), EXIT_ERROR (bad usage, a refusal, a failure, a failed
 * verification) and EXIT_PRECONDITION (a site-wide precondition is unmet). A plan is judged by each assignment's
 * decision and code and each outcome, never by whether a message is empty: a verified assignment's drift note is
 * information, and exits 0. With --course=all a course that no longer exists is reported and doesn't change the exit
 * code; named on its own it's a refusal.
 *
 * Nothing printed is learner text: plans, results and reports hold ids, codes, counts and messages built from lang
 * strings with ids, and failures carry an exception's class, never its message. No option value is echoed back.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class command {
    /** @var int Done, or a clean dry run. */
    public const EXIT_OK = 0;

    /** @var int Bad usage, a refusal, a failure, or a failed verification. */
    public const EXIT_ERROR = 1;

    /** @var int A site-wide precondition is unmet. */
    public const EXIT_PRECONDITION = 2;

    /** @var array The options cli_get_params() knows, with their defaults. backend is known only to be refused. */
    private const OPTIONS = ['course' => false, 'apply' => false, 'rollback' => false, 'verify' => false,
        'report' => false, 'json' => false, 'help' => false, 'backend' => false];

    /** @var string[] The options that pick a mode; at most one is given. */
    private const MODES = ['apply', 'rollback', 'verify', 'report'];

    /** @var factory Builds the reader and the workers. */
    private factory $factory;

    /** @var resource Standard output. */
    private $stdout;

    /** @var resource Standard error. */
    private $stderr;

    /** @var string[] Lines for standard output, written at the end unless the output is JSON. */
    private array $lines = [];

    /**
     * A command.
     *
     * @param factory $factory Builds the reader and the workers.
     * @param resource|null $stdout Where output goes, STDOUT when null.
     * @param resource|null $stderr Where usage errors go, STDERR when null.
     */
    public function __construct(factory $factory, $stdout = null, $stderr = null) {
        $this->factory = $factory;
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * Run the command.
     *
     * @param array $argv The arguments, without the script's name.
     * @return int The exit code.
     */
    public function execute(array $argv): int {
        global $CFG;
        require_once($CFG->libdir . '/clilib.php');

        \core\session\manager::set_user(get_admin());
        [$options, $unknown] = self::parse($argv);
        if ($unknown) {
            return $this->usage('cli_unknownoption');
        }
        if (!empty($options['help'])) {
            cli_writeln(self::str('cli_help'), $this->stdout);
            return self::EXIT_OK;
        }
        if ($options['backend'] !== false) {
            return $this->usage('cli_backend');
        }
        $course = $options['course'];
        if (!is_string($course) || ($course !== 'all' && !preg_match('/^[1-9][0-9]*$/', $course))) {
            return $this->usage('cli_course');
        }
        $modes = array_values(array_filter(self::MODES, static fn(string $mode): bool => !empty($options[$mode])));
        if (count($modes) > 1) {
            return $this->usage('cli_modes');
        }
        $mode = $modes[0] ?? 'dryrun';

        if ($mode === 'report') {
            [$code, $data] = $this->report($course);
        } else {
            [$code, $data] = $this->work($mode, $course);
        }

        if (!empty($options['json'])) {
            $doc = ['mode' => $mode, 'exitcode' => $code] + $data;
            cli_writeln(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->stdout);
        } else {
            foreach ($this->lines as $line) {
                cli_writeln($line, $this->stdout);
            }
        }

        return $code;
    }

    /**
     * Parse the arguments with cli_get_params(), which reads $_SERVER['argv'].
     *
     * @param array $argv The arguments, without the script's name.
     * @return array [options, unrecognised].
     */
    private static function parse(array $argv): array {
        $saved = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = array_merge(['migrate_from_local.php'], array_map('strval', array_values($argv)));
        try {
            return cli_get_params(self::OPTIONS, ['h' => 'help']);
        } finally {
            $_SERVER['argv'] = $saved;
        }
    }

    /**
     * Refuse the usage with a fixed message that doesn't repeat what was typed.
     *
     * @param string $name The string's name after "migration:".
     * @return int EXIT_ERROR.
     */
    private function usage(string $name): int {
        cli_writeln(self::str($name), $this->stderr);

        return self::EXIT_ERROR;
    }

    /**
     * A dry run, an apply, a rollback or a verification of one course or of every course the reader lists.
     *
     * @param string $mode dryrun, apply, rollback or verify.
     * @param string $course A course id or "all".
     * @return array [int exit code, array data for JSON].
     */
    private function work(string $mode, string $course): array {
        global $USER;

        $reader = $this->factory->reader();
        $all = $course === 'all';
        if (in_array($mode, ['dryrun', 'apply', 'verify'], true) && !$reader->tables_present()) {
            // Nothing to import or compare; the verifier would only write a failing report.
            $plan = new plan(0);
            preconditions::check($reader, $plan);
            return $this->site_preconditions($plan->unmet());
        }
        $courses = $all ? ($reader->tables_present() ? $reader->course_ids_with_data() : []) : [(int) $course];
        if ($courses === []) {
            $this->lines[] = self::str('cli_nocourses');
            return [self::EXIT_OK, ['courses' => []]];
        }

        $worker = null;
        if ($mode === 'dryrun' || $mode === 'apply') {
            $worker = $this->factory->runner($reader);
        } else if ($mode === 'rollback') {
            $worker = $this->factory->rollback($reader);
        } else {
            $worker = $this->factory->verifier($reader);
        }

        $code = self::EXIT_OK;
        $data = [];
        foreach ($courses as $courseid) {
            if ($worker instanceof runner) {
                $plan = $mode === 'apply' ? $worker->apply_course((int) $courseid) : $worker->plan_course((int) $courseid);
                [$one, $data[]] = $this->print_plan($plan, $all);
            } else if ($worker instanceof rollback) {
                [$one, $data[]] = $this->print_rollback($worker->rollback_course((int) $courseid));
            } else {
                [$one, $data[]] = $this->print_verify($worker->verify_course((int) $courseid, (int) $USER->id));
            }
            $code = max($code, $one);
        }

        return [$code, ['courses' => $data]];
    }

    /**
     * Report site-wide preconditions that stop everything before any course is read.
     *
     * @param array $unmet ['code' => string, 'message' => string] each.
     * @return array [int exit code, array data].
     */
    private function site_preconditions(array $unmet): array {
        foreach ($unmet as $check) {
            $this->lines[] = self::str('cli_precondition', (object) $check);
        }

        return [$unmet ? self::EXIT_PRECONDITION : self::EXIT_OK, ['preconditions' => $unmet, 'courses' => []]];
    }

    /**
     * Print a plan (a dry run or an apply) and judge it.
     *
     * @param plan $plan The plan.
     * @param bool $all Whether every course is being run, so a vanished course is only reported.
     * @return array [int exit code, array data].
     */
    private function print_plan(plan $plan, bool $all): array {
        $counts = $plan->counts();
        $fallback = [];
        foreach ($plan->assignments as $entry) {
            if (in_array($entry['standin'], [plan::STANDIN_NONE, plan::STANDIN_UNAVAILABLE], true)
                    && in_array($entry['decision'], [plan::CREATE, plan::RESUME], true)) {
                $fallback[] = (int) $entry['sourceid'];
            }
        }

        $this->lines[] = $plan->applied
            ? self::str('cli_applied', (object) ['course' => $plan->courseid, 'runid' => $plan->runid])
            : self::str('cli_dryrun', $plan->courseid);
        $unmet = $plan->unmet();
        foreach ($unmet as $check) {
            $this->lines[] = self::str('cli_precondition', (object) $check);
        }
        foreach ($plan->refusals as $refusal) {
            $this->lines[] = self::str('cli_refusal', (object) $refusal);
        }
        foreach ($plan->notes as $note) {
            if ($note['code'] !== 'orphans') {
                $this->lines[] = self::str('cli_note', (object) $note);
            }
        }
        $this->lines[] = self::str('cli_counts', (object) ['assignments' => $counts['assignments'],
            'topics' => $counts['topics'], 'recordings' => $counts['recordings'],
            'bystatus' => self::by_status($counts['bystatus']), 'scores' => $counts['scores'],
            'learners' => $counts['learners']]);
        $this->lines[] = self::str('cli_orphans', $counts['orphans']);
        $this->lines[] = self::str('cli_unreconstructed', $counts['unreconstructed']);
        $this->lines[] = $fallback ? self::str('cli_fallback', implode(', ', $fallback)) : self::str('cli_fallback_none');
        foreach ($plan->assignments as $entry) {
            $this->print_entry($entry);
        }

        $code = $unmet ? self::EXIT_PRECONDITION : self::EXIT_OK;
        foreach ($plan->refusals as $refusal) {
            if (!($all && $refusal['code'] === 'nocourse')) {
                $code = max($code, self::EXIT_ERROR);
            }
        }
        foreach ($plan->assignments as $entry) {
            if ($entry['decision'] === plan::REFUSE || $entry['outcome'] === plan::OUTCOME_FAILED) {
                $code = max($code, self::EXIT_ERROR);
            }
        }

        return [$code, ['courseid' => $plan->courseid, 'applied' => $plan->applied, 'runid' => $plan->runid,
            'preconditions' => $unmet, 'refusals' => $plan->refusals, 'notes' => $plan->notes,
            'speakinglevel' => $plan->speakinglevel, 'counts' => $counts, 'fallback' => $fallback,
            'assignments' => array_values($plan->assignments)]];
    }

    /**
     * Print one assignment of a plan.
     *
     * @param array $entry The plan entry.
     */
    private function print_entry(array $entry): void {
        $this->lines[] = self::str('cli_assignment', (object) ['id' => $entry['sourceid'], 'decision' => $entry['decision'],
            'code' => $entry['code'] === '' ? '' : ' (' . $entry['code'] . ')',
            'section' => $entry['decision'] === plan::SKIP_VERIFIED ? '-' : $entry['section'],
            'topics' => $entry['counts']['topics'], 'recordings' => $entry['counts']['recordings'],
            'scores' => $entry['counts']['scores'], 'unreconstructed' => $entry['counts']['unreconstructed'],
            'learners' => $entry['counts']['learners']]);
        if ($entry['standin'] !== '') {
            $this->lines[] = self::str('cli_standin', (object) ['decision' => $entry['standin'],
                'cmid' => $entry['standincmid'], 'message' => $entry['standinmessage']]);
        }
        if ($entry['decision'] === plan::SKIP_VERIFIED) {
            $this->lines[] = self::str('cli_drift', (object) ['added' => $entry['new_source_rows'],
                'scored' => $entry['newly_scored_rows']]);
        }
        if ($entry['message'] !== '') {
            $this->lines[] = self::str('cli_message', $entry['message']);
        }
        if ($entry['outcome'] !== '') {
            $this->lines[] = self::str('cli_outcome', (object) ['outcome' => $entry['outcome'],
                'state' => $entry['state'] === '' ? '-' : $entry['state'], 'cmid' => $entry['targetcmid']]);
        }
        if ($entry['error'] !== '') {
            $this->lines[] = self::str('cli_message', $entry['error']);
        }
    }

    /**
     * Print a rollback's result and judge it.
     *
     * @param array $result What rollback::rollback_course() returned.
     * @return array [int exit code, array data].
     */
    private function print_rollback(array $result): array {
        $this->lines[] = self::str('cli_rollback', (object) ['course' => $result['courseid'], 'removed' => $result['removed'],
            'cleared' => $result['cleared'], 'refused' => $result['refused'], 'failed' => $result['failed'],
            'standinsrestored' => $result['standinsrestored'], 'maprowsdeleted' => $result['maprowsdeleted']]);
        if ($result['message'] !== '') {
            $this->lines[] = self::str('cli_message', $result['message']);
        }
        foreach ($result['entries'] as $entry) {
            $this->lines[] = self::str('cli_rollback_entry', (object) ['id' => $entry['sourceid'],
                'outcome' => $entry['outcome'], 'code' => $entry['code'] === '' ? '' : ' (' . $entry['code'] . ')',
                'standin' => $entry['standin'] === '' ? '-' : $entry['standin']]);
            if ($entry['message'] !== '') {
                $this->lines[] = self::str('cli_message', $entry['message']);
            }
        }
        $bad = $result['refusal'] !== '' || $result['refused'] > 0 || $result['failed'] > 0;
        $result['entries'] = array_values($result['entries']);

        return [$bad ? self::EXIT_ERROR : self::EXIT_OK, $result];
    }

    /**
     * Print a verification's result and judge it.
     *
     * @param array $result What verifier::verify_course() returned.
     * @return array [int exit code, array data].
     */
    private function print_verify(array $result): array {
        $args = (object) ['course' => $result['courseid'], 'report' => $result['reportid'], 'runid' => $result['runid']];
        $this->lines[] = self::str($result['passed'] ? 'cli_verify_passed' : 'cli_verify_failed', $args);
        $pairs = [];
        foreach ($result['counts'] as $check => $pair) {
            $pairs[] = $check . ' ' . (int) $pair['source'] . '/' . (int) $pair['destination'];
        }
        $this->lines[] = self::str('cli_verify_counts', $pairs ? implode(', ', $pairs) : '-');
        $this->lines[] = self::str('cli_verify_storage', (object) ['sampled' => $result['storagesampled'],
            'failures' => $result['storagefailures']]);
        $this->lines[] = self::str('cli_mismatches', count($result['mismatches']));
        foreach ($result['mismatches'] as $mismatch) {
            $this->lines[] = self::str('cli_mismatch', self::describe_mismatch($mismatch));
        }

        return [$result['passed'] ? self::EXIT_OK : self::EXIT_ERROR, $result];
    }

    /**
     * Print stored verification reports, writing nothing: every one for a course, or the newest of each course.
     *
     * @param string $course A course id or "all".
     * @return array [int exit code, array data].
     */
    private function report(string $course): array {
        global $DB;

        if ($course === 'all') {
            $rows = $DB->get_records_sql('SELECT r.* FROM {presenterai_migreport} r
                JOIN (SELECT courseid, MAX(id) AS id FROM {presenterai_migreport} GROUP BY courseid) n ON n.id = r.id
                ORDER BY r.courseid, r.id');
        } else {
            $rows = $DB->get_records('presenterai_migreport', ['courseid' => (int) $course], 'timecreated, id');
        }
        if (!$rows) {
            $this->lines[] = $course === 'all' ? self::str('cli_report_none_all') : self::str('cli_report_none', (int) $course);
            return [self::EXIT_OK, ['courses' => []]];
        }

        $data = [];
        foreach ($rows as $row) {
            $codes = [];
            foreach ((array) json_decode((string) $row->mismatches, true) as $mismatch) {
                $name = is_array($mismatch) ? (string) ($mismatch['code'] ?? '') : '';
                $codes[$name] = ($codes[$name] ?? 0) + 1;
            }
            ksort($codes);
            $this->lines[] = self::str('cli_report', (object) ['course' => (int) $row->courseid, 'id' => (int) $row->id,
                'runid' => $row->runid, 'time' => userdate((int) $row->timecreated, get_string('strftimedatetimeshort',
                    'langconfig')), 'result' => self::str(!empty($row->passed) ? 'cli_report_passed' : 'cli_report_failed'),
                'mismatchcount' => (int) $row->mismatchcount, 'sampled' => (int) $row->storagesampled,
                'failures' => (int) $row->storagefailures]);
            if ($codes) {
                $this->lines[] = self::str('cli_report_codes', self::by_status($codes));
            }
            $data[] = ['id' => (int) $row->id, 'courseid' => (int) $row->courseid, 'runid' => $row->runid,
                'passed' => !empty($row->passed), 'counts' => json_decode((string) $row->counts, true),
                'mismatchcodes' => $codes, 'mismatchcount' => (int) $row->mismatchcount,
                'storagesampled' => (int) $row->storagesampled, 'storagefailures' => (int) $row->storagefailures,
                'timecreated' => (int) $row->timecreated];
        }

        return [self::EXIT_OK, ['courses' => $data]];
    }

    /**
     * "name count, name count", or "none".
     *
     * @param array $counts Name to count.
     * @return string
     */
    private static function by_status(array $counts): string {
        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = $name . ' ' . (int) $count;
        }

        return $parts ? implode(', ', $parts) : self::str('cli_none');
    }

    /**
     * A mismatch as its code and ids: "code (assignid 1, recid 2)". The verifier puts only ids and codes in one.
     *
     * @param array $mismatch The mismatch.
     * @return string
     */
    private static function describe_mismatch(array $mismatch): string {
        $parts = [];
        foreach ($mismatch as $key => $value) {
            if ($key !== 'code' && (is_int($value) || is_string($value))) {
                $parts[] = $key . ' ' . $value;
            }
        }

        return (string) ($mismatch['code'] ?? '') . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }

    /**
     * One of the migration strings.
     *
     * @param string $name The name after "migration:".
     * @param mixed $a The string's argument.
     * @return string
     */
    private static function str(string $name, $a = null): string {
        return get_string('migration:' . $name, 'mod_presenterai', $a);
    }
}
