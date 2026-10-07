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
 * The arithmetic that turns scores into a grade.
 *
 * Kept apart from the gradebook and the database so the rules can be tested
 * without either, and so the report, completion and the gradebook all use one
 * definition of "the learner's number" (IMPLEMENTATION-PLAN section 6):
 *
 * - One attempt's fraction is rawsum / rawmax over assessed criteria only. A
 *   criterion nobody could judge is left out of both sums, not counted as 0.
 * - An attempt with rawmax 0 has no number and doesn't count, rather than
 *   counting as zero.
 * - Across attempts, gradingmethod picks or averages, over every attempt with
 *   a current score, whether or not its media still exists (D8). Media expiry
 *   is a storage decision and must never quietly change a grade.
 *
 * Everything here is pure apart from aggregate_for_user() and
 * aggregate_for_users(), which read the rows and then call the pure part.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grader {
    /** @var string[] How attempts combine into one grade, as mod_quiz offers them. */
    public const GRADING_METHODS = ['highest', 'latest', 'average', 'first'];

    /** @var string The method used when the stored one isn't known. */
    public const DEFAULT_GRADING_METHOD = 'highest';

    /**
     * Sum the assessed criteria of one score.
     *
     * @param array $criteria A list of ['score' => int, 'max_score' => int, 'assessed' => bool].
     * @return array ['rawsum' => int, 'rawmax' => int, 'assessed' => int]
     */
    public static function sums(array $criteria): array {
        $rawsum = 0;
        $rawmax = 0;
        $assessed = 0;
        foreach ($criteria as $criterion) {
            if (!is_array($criterion) || !rubric_manager::is_assessed($criterion)) {
                continue;
            }
            $rawsum += (int) ($criterion['score'] ?? 0);
            $rawmax += (int) ($criterion['max_score'] ?? 0);
            $assessed++;
        }
        return ['rawsum' => $rawsum, 'rawmax' => $rawmax, 'assessed' => $assessed];
    }

    /**
     * One score as a fraction of its maximum.
     *
     * @param int $rawsum Points given over the assessed criteria.
     * @param int $rawmax Points available over the assessed criteria.
     * @return float|null Between 0 and 1, or null when nothing was assessed.
     */
    public static function fraction(int $rawsum, int $rawmax): ?float {
        if ($rawmax <= 0) {
            return null;
        }
        return max(0.0, min(1.0, $rawsum / $rawmax));
    }

    /**
     * One score as a percentage, to two decimal places.
     *
     * @param int $rawsum Points given over the assessed criteria.
     * @param int $rawmax Points available over the assessed criteria.
     * @return float|null The percentage, or null when nothing was assessed.
     */
    public static function percent(int $rawsum, int $rawmax): ?float {
        $fraction = self::fraction($rawsum, $rawmax);
        return $fraction === null ? null : round(100 * $fraction, 2);
    }

    /**
     * Which item of a scale a fraction lands on, counting from 1.
     *
     * The product is rounded to nine places before ceil(), so float noise such
     * as 0.1 * 3 = 0.30000000000000004 can't push a learner up an item.
     *
     * @param float $fraction Between 0 and 1.
     * @param int $count The number of items in the scale.
     * @return int Between 1 and $count.
     */
    public static function scale_index(float $fraction, int $count): int {
        if ($count < 1) {
            return 1;
        }
        $index = (int) ceil(round($fraction * $count, 9));
        return max(1, min($count, $index));
    }

    /**
     * The raw grade pushed to the gradebook for a fraction.
     *
     * @param float $fraction Between 0 and 1.
     * @param \stdClass $instance The presenterai row, whose grade is points (> 0), a scale (< 0) or none (0).
     * @return float|null The raw grade, or null when the activity has no grade.
     */
    public static function to_rawgrade(float $fraction, \stdClass $instance): ?float {
        global $DB;

        $grade = (int) ($instance->grade ?? 0);
        if ($grade > 0) {
            return round($fraction * $grade, 5);
        }
        if ($grade < 0) {
            $scale = $DB->get_field('scale', 'scale', ['id' => -$grade]);
            if ($scale === false) {
                return null;
            }
            $items = array_filter(array_map('trim', explode(',', (string) $scale)), 'strlen');
            return (float) self::scale_index($fraction, count($items));
        }
        return null;
    }

    /**
     * Combine attempts into one number by a grading method.
     *
     * Attempts whose rawmax is 0 or less are ignored. Ties under 'highest' go
     * to the lowest attempt number, so a later equal attempt doesn't move the
     * grade's date. 'latest' and 'first' order by attempt number, then time
     * created, then recording id. 'average' is the mean of fractions and names
     * the latest counted attempt. An unknown method is treated as 'highest'.
     *
     * @param array $attempts A list of ['recordingid', 'attemptnumber', 'timecreated', 'rawsum', 'rawmax'].
     * @param string $method One of GRADING_METHODS.
     * @return array|null ['fraction' => float, 'pct' => float, 'recordingid' => int, 'counted' => int], or null.
     */
    public static function aggregate(array $attempts, string $method): ?array {
        $counted = [];
        foreach ($attempts as $attempt) {
            $attempt = (array) $attempt;
            $fraction = self::fraction((int) ($attempt['rawsum'] ?? 0), (int) ($attempt['rawmax'] ?? 0));
            if ($fraction === null) {
                continue;
            }
            $counted[] = [
                'recordingid' => (int) ($attempt['recordingid'] ?? 0),
                'attemptnumber' => (int) ($attempt['attemptnumber'] ?? 0),
                'timecreated' => (int) ($attempt['timecreated'] ?? 0),
                'fraction' => $fraction,
            ];
        }
        if (empty($counted)) {
            return null;
        }

        // Oldest first: attempt number, then time created, then id.
        usort($counted, function (array $a, array $b): int {
            return [$a['attemptnumber'], $a['timecreated'], $a['recordingid']]
                <=> [$b['attemptnumber'], $b['timecreated'], $b['recordingid']];
        });

        if (!in_array($method, self::GRADING_METHODS, true)) {
            $method = self::DEFAULT_GRADING_METHOD;
        }

        switch ($method) {
            case 'first':
                $chosen = reset($counted);
                $fraction = $chosen['fraction'];
                break;
            case 'latest':
                $chosen = end($counted);
                $fraction = $chosen['fraction'];
                break;
            case 'average':
                $chosen = end($counted);
                $fraction = array_sum(array_column($counted, 'fraction')) / count($counted);
                break;
            default:
                // Highest. Walking oldest first and replacing only on a strictly
                // greater fraction keeps a tie on the earliest attempt.
                $chosen = null;
                foreach ($counted as $attempt) {
                    if ($chosen === null || $attempt['fraction'] > $chosen['fraction']) {
                        $chosen = $attempt;
                    }
                }
                $fraction = $chosen['fraction'];
                break;
        }

        return [
            'fraction' => $fraction,
            'pct' => round(100 * $fraction, 2),
            'recordingid' => $chosen['recordingid'],
            'counted' => count($counted),
        ];
    }

    /**
     * One learner's aggregate in one activity.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int $userid The learner.
     * @return array|null As aggregate(), or null when no attempt counts.
     */
    public static function aggregate_for_user(\stdClass $instance, int $userid): ?array {
        $all = self::aggregate_for_users($instance, [$userid]);
        return $all[$userid] ?? null;
    }

    /**
     * Several learners' aggregates in one activity, in one query for the rows.
     *
     * Every recording row of each learner is read, whatever its storagekey and
     * status (D8): an attempt whose media expired still has its score, and a
     * score is what counts. Each row's current score (score_manager) supplies
     * its sums.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int[] $userids The learners.
     * @return array userid => aggregate() result or null, for every id asked about.
     */
    public static function aggregate_for_users(\stdClass $instance, array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        $result = array_fill_keys($userids, null);
        if (empty($userids)) {
            return $result;
        }

        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params['presenteraiid'] = (int) $instance->id;
        $recs = $DB->get_records_select(
            'presenterai_recording',
            "presenteraiid = :presenteraiid AND userid {$usersql}",
            $params,
            'id ASC',
            'id, userid, attemptnumber, timecreated'
        );
        if (empty($recs)) {
            return $result;
        }

        $scores = score_manager::current_scores(array_keys($recs));
        $byuser = [];
        foreach ($recs as $rec) {
            $score = $scores[(int) $rec->id] ?? null;
            if (!$score) {
                continue;
            }
            $byuser[(int) $rec->userid][] = [
                'recordingid' => (int) $rec->id,
                'attemptnumber' => (int) $rec->attemptnumber,
                'timecreated' => (int) $rec->timecreated,
                'rawsum' => (int) $score->rawsum,
                'rawmax' => (int) $score->rawmax,
            ];
        }

        $method = (string) ($instance->gradingmethod ?? self::DEFAULT_GRADING_METHOD);
        foreach ($byuser as $userid => $attempts) {
            $result[$userid] = self::aggregate($attempts, $method);
        }
        return $result;
    }
}
