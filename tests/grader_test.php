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

use mod_presenterai\local\grader;

/**
 * The arithmetic from scores to a grade.
 *
 * Pure: no database apart from the scale case of to_rawgrade().
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\grader
 */
final class grader_test extends \advanced_testcase {
    /**
     * Only assessed criteria count, and a missing flag means assessed.
     *
     * @return void
     */
    public function test_sums_over_assessed_only(): void {
        $sums = grader::sums([
            ['score' => 4, 'max_score' => 5, 'assessed' => true],
            ['score' => 3, 'max_score' => 5],
            ['score' => 0, 'max_score' => 5, 'assessed' => false],
            ['score' => 2, 'max_score' => 10, 'assessed' => true],
        ]);
        $this->assertSame(['rawsum' => 9, 'rawmax' => 20, 'assessed' => 3], $sums);

        $this->assertSame(['rawsum' => 0, 'rawmax' => 0, 'assessed' => 0], grader::sums([]));
        $this->assertSame(
            ['rawsum' => 0, 'rawmax' => 0, 'assessed' => 0],
            grader::sums([['score' => 5, 'max_score' => 5, 'assessed' => false]])
        );
    }

    /**
     * Nothing assessed is no number, not zero.
     *
     * @return void
     */
    public function test_fraction_and_percent(): void {
        $this->assertNull(grader::fraction(0, 0));
        $this->assertNull(grader::fraction(3, -1));
        $this->assertNull(grader::percent(0, 0));
        $this->assertSame(0.0, grader::fraction(0, 25));
        $this->assertSame(0.8, grader::fraction(20, 25));
        $this->assertSame(1.0, grader::fraction(30, 25), 'A fraction is clamped to 1.');
        $this->assertSame(0.0, grader::fraction(-3, 25), 'A fraction is clamped to 0.');
        $this->assertSame(66.67, grader::percent(2, 3));
        $this->assertSame(80.0, grader::percent(20, 25));
    }

    /**
     * The scale index, at the edges and on exact boundaries.
     *
     * @return void
     */
    public function test_scale_index(): void {
        $this->assertSame(1, grader::scale_index(0.0, 4), 'Zero still lands on the first item.');
        $this->assertSame(1, grader::scale_index(0.5, 2), 'Exactly half of two items is the first.');
        $this->assertSame(2, grader::scale_index(0.51, 2));
        $this->assertSame(4, grader::scale_index(1.0, 4));
        $this->assertSame(2, grader::scale_index(0.5, 4));
        $this->assertSame(3, grader::scale_index(0.51, 4));
        // 0.1 * 3 is 0.30000000000000004, and times 10 that is just over 3. Unguarded, ceil() gives 4.
        $this->assertSame(3, grader::scale_index(0.1 * 3, 10));
        $this->assertSame(1, grader::scale_index((0.1 + 0.2) / 0.9, 3), '(0.1+0.2)/0.9 is a third plus noise.');
        $this->assertSame(3, grader::scale_index(1.0000000000000002, 3));
        $this->assertSame(1, grader::scale_index(0.7, 0), 'A scale with no items gives the first.');
    }

    /**
     * Points multiply, no grade gives null, and a scale maps to its index.
     *
     * @return void
     */
    public function test_to_rawgrade(): void {
        $this->resetAfterTest();

        $this->assertSame(80.0, grader::to_rawgrade(0.8, (object) ['grade' => 100]));
        $this->assertSame(6.66667, grader::to_rawgrade(2 / 3, (object) ['grade' => 10]));
        $this->assertNull(grader::to_rawgrade(0.8, (object) ['grade' => 0]));

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Not yet, Nearly, Met, Exceeded']);
        $this->assertSame(3.0, grader::to_rawgrade(0.7, (object) ['grade' => -$scale->id]));
        $this->assertSame(1.0, grader::to_rawgrade(0.0, (object) ['grade' => -$scale->id]));
        $this->assertNull(grader::to_rawgrade(0.7, (object) ['grade' => -($scale->id + 1000)]), 'A missing scale.');
    }

    /**
     * Four attempts used by the method tests.
     *
     * @return array[]
     */
    private static function attempts(): array {
        return [
            ['recordingid' => 11, 'attemptnumber' => 1, 'timecreated' => 100, 'rawsum' => 10, 'rawmax' => 20],
            ['recordingid' => 12, 'attemptnumber' => 2, 'timecreated' => 200, 'rawsum' => 18, 'rawmax' => 20],
            ['recordingid' => 13, 'attemptnumber' => 3, 'timecreated' => 300, 'rawsum' => 0, 'rawmax' => 0],
            ['recordingid' => 14, 'attemptnumber' => 4, 'timecreated' => 400, 'rawsum' => 14, 'rawmax' => 20],
        ];
    }

    /**
     * Each method picks the right attempt and number; rawmax 0 never counts.
     *
     * @return void
     */
    public function test_aggregate_methods(): void {
        $highest = grader::aggregate(self::attempts(), 'highest');
        $this->assertSame(['fraction' => 0.9, 'pct' => 90.0, 'recordingid' => 12, 'counted' => 3], $highest);

        $latest = grader::aggregate(self::attempts(), 'latest');
        $this->assertSame(14, $latest['recordingid'], 'The unscorable attempt 3 is not the latest counted one.');
        $this->assertSame(0.7, $latest['fraction']);

        $first = grader::aggregate(self::attempts(), 'first');
        $this->assertSame(11, $first['recordingid']);
        $this->assertSame(50.0, $first['pct']);

        $average = grader::aggregate(self::attempts(), 'average');
        $this->assertEqualsWithDelta(0.7, $average['fraction'], 1e-9);
        $this->assertSame(70.0, $average['pct']);
        $this->assertSame(14, $average['recordingid'], 'Average names the latest counted attempt.');
        $this->assertSame(3, $average['counted']);

        $this->assertSame($highest, grader::aggregate(self::attempts(), 'nonsense'), 'An unknown method is highest.');
    }

    /**
     * A tie under highest goes to the lowest attempt number, whatever the input order.
     *
     * @return void
     */
    public function test_highest_tie_goes_to_lowest_attempt(): void {
        $attempts = [
            ['recordingid' => 30, 'attemptnumber' => 3, 'timecreated' => 300, 'rawsum' => 8, 'rawmax' => 10],
            ['recordingid' => 20, 'attemptnumber' => 2, 'timecreated' => 200, 'rawsum' => 16, 'rawmax' => 20],
            ['recordingid' => 10, 'attemptnumber' => 1, 'timecreated' => 100, 'rawsum' => 1, 'rawmax' => 10],
        ];
        $this->assertSame(20, grader::aggregate($attempts, 'highest')['recordingid']);
    }

    /**
     * latest and first break an attempt-number tie on time, then on id.
     *
     * @return void
     */
    public function test_latest_and_first_ordering(): void {
        $attempts = [
            ['recordingid' => 7, 'attemptnumber' => 1, 'timecreated' => 100, 'rawsum' => 1, 'rawmax' => 10],
            ['recordingid' => 5, 'attemptnumber' => 1, 'timecreated' => 100, 'rawsum' => 2, 'rawmax' => 10],
            ['recordingid' => 9, 'attemptnumber' => 1, 'timecreated' => 50, 'rawsum' => 3, 'rawmax' => 10],
        ];
        $this->assertSame(7, grader::aggregate($attempts, 'latest')['recordingid']);
        $this->assertSame(9, grader::aggregate($attempts, 'first')['recordingid']);
    }

    /**
     * Nothing to count gives null for every method.
     *
     * @return void
     */
    public function test_aggregate_null_when_nothing_counts(): void {
        $none = [['recordingid' => 1, 'attemptnumber' => 1, 'timecreated' => 1, 'rawsum' => 0, 'rawmax' => 0]];
        foreach (grader::GRADING_METHODS as $method) {
            $this->assertNull(grader::aggregate([], $method));
            $this->assertNull(grader::aggregate($none, $method));
        }
    }
}
