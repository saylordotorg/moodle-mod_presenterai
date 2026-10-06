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

use mod_presenterai\output\attempt_row;

/**
 * The recording state cell, row by row, against the table in design section 9.5.
 *
 * state() is pure, so every case here is an in-memory row: no database, no
 * other slice, nothing that can make the test pass for a reason other than the
 * table being right. The one case that needs the real database, a row read
 * back through export(), lives in retention_message_source_test.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\attempt_row
 */
final class attempt_row_test extends \advanced_testcase {
    /** @var int A fixed now, so the boundary cases mean exactly what they say. */
    private const NOW = 1790000000;

    /**
     * A recording row with defaults that describe a finished attempt with media.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function row(array $fields = []): \stdClass {
        return (object) array_merge([
            'id' => 1,
            'status' => 'uploaded',
            'storagekey' => 'abc123.webm',
            'expiresat' => 0,
            'mediadeletedat' => 0,
            'mediagonereason' => null,
            'attemptnumber' => 1,
            'durationseconds' => 61,
            'timecreated' => self::NOW - DAYSECS,
        ], $fields);
    }

    /**
     * The date format the attempt list uses.
     *
     * @param int $timestamp Unix time.
     * @return string
     */
    private function date(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'));
    }

    /**
     * Every row state in design 9.5, and every attempt_gone_* reason.
     *
     * @return array
     */
    public static function state_provider(): array {
        $now = self::NOW;
        $future = $now + 10 * DAYSECS;
        $past = $now - 3 * DAYSECS;

        return [
            'media, future date, cleanup on' => [
                ['expiresat' => $future], true, 'attempt_deletes_on', $future,
            ],
            'media, future date, cleanup off' => [
                ['expiresat' => $future], false, 'attempt_kept', null,
            ],
            'media, date passed' => [
                ['expiresat' => $past], true, 'attempt_deletes_due', null,
            ],
            'media, no date' => [
                ['expiresat' => 0], true, 'attempt_kept', null,
            ],
            'gone, retention' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'retention'], true,
                'attempt_deleted_on', $past,
            ],
            'gone, no reason recorded' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => ''], true,
                'attempt_deleted_on', $past,
            ],
            'gone, null reason' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => null], true,
                'attempt_deleted_on', $past,
            ],
            'gone, unknown reason' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'futurereason'], true,
                'attempt_deleted_on', $past,
            ],
            'gone, pruned' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'pruned'], true,
                'attempt_gone_pruned', $past,
            ],
            'gone, manual' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'manual'], true,
                'attempt_gone_manual', $past,
            ],
            'gone, learner' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'learner'], true,
                'attempt_gone_learner', $past,
            ],
            'gone, missing' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'missing'], true,
                'attempt_gone_missing', null,
            ],
            'gone, not backed up' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'notbackedup'], true,
                'attempt_gone_notbackedup', null,
            ],
            'gone, no deletion time (migrated)' => [
                ['storagekey' => null, 'mediadeletedat' => 0], true, 'attempt_deleted', null,
            ],
            'gone, empty key string' => [
                ['storagekey' => '', 'mediadeletedat' => 0], true, 'attempt_deleted', null,
            ],
            'still uploading' => [
                ['status' => 'uploading', 'storagekey' => null], true, 'attempt_never_uploaded', null,
            ],
            'uploading with a minted key' => [
                ['status' => 'uploading', 'storagekey' => 'minted.webm', 'expiresat' => $future], true,
                'attempt_never_uploaded', null,
            ],
            'abandoned' => [
                ['status' => 'abandoned', 'storagekey' => null], true, 'attempt_never_uploaded', null,
            ],
            'scored with media, cleanup on' => [
                ['status' => 'scored', 'expiresat' => $future], true, 'attempt_deletes_on', $future,
            ],
        ];
    }

    /**
     * Each row state selects the key and the date the table says it should.
     *
     * @dataProvider state_provider
     * @param array $fields Row overrides.
     * @param bool $cleanupenabled Whether the cleanup task is enabled.
     * @param string $key The expected lang key.
     * @param int|null $datefrom The timestamp whose date {$a} should be, or null for none.
     * @return void
     */
    public function test_state(array $fields, bool $cleanupenabled, string $key, ?int $datefrom): void {
        $this->resetAfterTest();

        $state = attempt_row::state($this->row($fields), self::NOW, $cleanupenabled);

        $this->assertSame($key, $state['key']);
        $this->assertSame($datefrom === null ? null : $this->date($datefrom), $state['a']);

        // Design 11, rule 6: the cell is never empty, in any state.
        $text = attempt_row::text($state);
        $this->assertNotSame('', trim($text));
        $this->assertTrue(get_string_manager()->string_exists($key, 'mod_presenterai'));
        if ($datefrom !== null) {
            $this->assertStringContainsString($this->date($datefrom), $text);
        }
    }

    /**
     * expiresat exactly now is due, one second later is still a date.
     *
     * @return void
     */
    public function test_due_boundary(): void {
        $this->resetAfterTest();

        $atnow = attempt_row::state($this->row(['expiresat' => self::NOW]), self::NOW, true);
        $this->assertSame('attempt_deletes_due', $atnow['key']);

        $later = attempt_row::state($this->row(['expiresat' => self::NOW + 1]), self::NOW, true);
        $this->assertSame('attempt_deletes_on', $later['key']);
    }

    /**
     * A disabled cleanup task suppresses every date it would not honour (design 8.7f).
     *
     * A row already past its date is also shown as kept, because "due to be
     * deleted" is as much a promise as a date when nothing is going to do it.
     *
     * @return void
     */
    public function test_disabled_cleanup_never_shows_a_date(): void {
        $this->resetAfterTest();

        foreach ([self::NOW + DAYSECS, self::NOW, self::NOW - DAYSECS] as $expiresat) {
            $state = attempt_row::state($this->row(['expiresat' => $expiresat]), self::NOW, false);
            $this->assertSame('attempt_kept', $state['key']);
            $this->assertNull($state['a']);
        }
    }

    /**
     * Every status has a learner facing label, and an unknown one is shown escaped.
     *
     * @return void
     */
    public function test_status_label(): void {
        $this->resetAfterTest();

        $this->assertSame('Submitted', attempt_row::status_label($this->row(['status' => 'uploaded'])));
        foreach (['uploading', 'abandoned', 'scoring', 'scored', 'failed'] as $status) {
            $this->assertSame(
                get_string('status_' . $status, 'mod_presenterai'),
                attempt_row::status_label($this->row(['status' => $status]))
            );
        }
        $this->assertSame('&lt;b&gt;', attempt_row::status_label($this->row(['status' => '<b>'])));
    }

    /**
     * Lengths read as minutes and seconds.
     *
     * @return void
     */
    public function test_duration(): void {
        $this->assertSame('0:00', attempt_row::duration(0));
        $this->assertSame('1:01', attempt_row::duration(61));
        $this->assertSame('7:05', attempt_row::duration(425));
        $this->assertSame('0:00', attempt_row::duration(-5));
    }

    /**
     * Media exists only on a non uploading row with a key.
     *
     * @return void
     */
    public function test_has_media(): void {
        $this->assertTrue(attempt_row::has_media($this->row()));
        $this->assertFalse(attempt_row::has_media($this->row(['storagekey' => null])));
        $this->assertFalse(attempt_row::has_media($this->row(['storagekey' => ''])));
        $this->assertFalse(attempt_row::has_media($this->row(['status' => 'uploading'])));
        $this->assertFalse(attempt_row::has_media($this->row(['status' => 'abandoned'])));
    }
}
