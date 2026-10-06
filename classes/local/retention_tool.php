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

namespace mod_presenterai\local;

/**
 * The one deliberate way to change the deletion date of recordings that already exist.
 *
 * Design section 8.3 is the specification. A settings change never rewrites
 * expiresat, so turning automatic deletion on does not put a year of
 * recordings on a clock that fires at the next cron run, and turning it off
 * does not cancel dates learners were already shown. When an administrator
 * does want existing recordings changed, this is how, and it is built so the
 * dangerous version is hard to run by accident: a basis must be named, a dry
 * run is the default, a grace floor stops a retroactive apply deleting
 * anything today, and a large batch of newly eligible recordings is refused
 * without --force.
 *
 * Split into plan(), which only reads, and apply(), which writes what a plan
 * says, so the CLI can print a plan before anything happens and the tests need
 * no CLI.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class retention_tool {
    /** @var string[] The bases --from accepts. There is deliberately no default. */
    public const FROMS = ['created', 'now', 'none'];

    /** @var int Newly eligible recordings within 24 hours above which --force is required. */
    public const FORCE_THRESHOLD = 100;

    /** @var int Smallest grace floor in days, whatever deletewarndays says. */
    public const MIN_GRACE_DAYS = 7;

    /**
     * Work out what a run would do, without writing anything.
     *
     * Options: 'from' (required, one of FROMS), 'course' and 'instance' (ids,
     * 0 for all), 'olderthan' (days; only recordings made at least this long
     * ago), 'grace' (bool, default true), 'force' (bool), 'now' (unix time, for
     * tests). Only recordings whose media still exists and that are not still
     * uploading are in scope: a row without media has nothing to delete, and
     * an uploading row has no deletion date to change yet.
     *
     * @param array $options See above.
     * @return array The plan: counts for the report and 'changes', id => new expiresat.
     */
    public static function plan(array $options): array {
        global $DB;

        $from = (string) ($options['from'] ?? '');
        if (!in_array($from, self::FROMS, true)) {
            throw new \moodle_exception('cli_badfrom', 'mod_presenterai');
        }
        $grace = !array_key_exists('grace', $options) || !empty($options['grace']);
        $force = !empty($options['force']);
        if (!$grace && !$force) {
            throw new \moodle_exception('cli_nogracewithoutforce', 'mod_presenterai');
        }
        $now = (int) ($options['now'] ?? time());

        // Only --from=created can produce a date in the past, so only it needs
        // the floor. The floor is never shorter than a week, and never shorter
        // than the advance warning a learner is owed.
        $gracedays = max((int) get_config('mod_presenterai', 'deletewarndays'), self::MIN_GRACE_DAYS);
        $gracefloor = ($from === 'created' && $grace) ? $now + $gracedays * DAYSECS : 0;

        $where = ['r.storagekey IS NOT NULL', 'r.status <> :uploading'];
        $params = ['uploading' => recording_manager::STATUS_UPLOADING];
        if (!empty($options['course'])) {
            $where[] = 'p.course = :course';
            $params['course'] = (int) $options['course'];
        }
        if (!empty($options['instance'])) {
            $where[] = 'p.id = :instance';
            $params['instance'] = (int) $options['instance'];
        }
        if (!empty($options['olderthan'])) {
            $where[] = 'r.timecreated <= :olderthan';
            $params['olderthan'] = $now - (int) $options['olderthan'] * DAYSECS;
        }

        $sql = "SELECT r.id, r.timecreated, r.expiresat, p.id AS instanceid, p.retentiondays
                  FROM {presenterai_recording} r
                  JOIN {presenterai} p ON p.id = r.presenteraiid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY r.id";

        $plan = [
            'from' => $from,
            'now' => $now,
            'gracedays' => $gracefloor > 0 ? $gracedays : 0,
            'force' => $force,
            'inscope' => 0,
            'changes' => [],
            'eligiblesoon' => 0,
            'currentlyzero' => 0,
            'skippednever' => 0,
        ];

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $plan['inscope']++;
            $current = (int) $row->expiresat;
            if ($current === 0) {
                $plan['currentlyzero']++;
            }

            if ($from === 'none') {
                $new = 0;
            } else {
                $days = retention::effective_days((object) ['retentiondays' => $row->retentiondays]);
                if ($days === 0) {
                    // The activity keeps forever, so there is no basis to compute
                    // a date from. Counted so the report says so.
                    $plan['skippednever']++;
                    continue;
                }
                $base = $from === 'created' ? (int) $row->timecreated : $now;
                $new = $base + $days * DAYSECS;
                if ($gracefloor > 0 && $new < $gracefloor) {
                    $new = $gracefloor;
                }
            }

            if ($new === $current) {
                continue;
            }
            $plan['changes'][(int) $row->id] = $new;
            $wassoon = $current > 0 && $current <= $now + DAYSECS;
            if ($new > 0 && $new <= $now + DAYSECS && !$wassoon) {
                $plan['eligiblesoon']++;
            }
        }
        $rs->close();

        $plan['refused'] = $plan['eligiblesoon'] > self::FORCE_THRESHOLD && !$force;

        return $plan;
    }

    /**
     * Write what a plan says, in one transaction.
     *
     * @param array $plan A plan from plan().
     * @return int Rows changed.
     */
    public static function apply(array $plan): int {
        global $DB;

        if (!empty($plan['refused'])) {
            throw new \moodle_exception('cli_toomanyeligible', 'mod_presenterai', '', self::FORCE_THRESHOLD);
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($plan['changes'] as $id => $expiresat) {
            $DB->update_record('presenterai_recording', (object) [
                'id' => (int) $id,
                'expiresat' => (int) $expiresat,
                'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();

        return count($plan['changes']);
    }
}
