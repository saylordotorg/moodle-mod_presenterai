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

use mod_presenterai\local\retention;

/**
 * What a recording is promised about its deletion, worked out from settings.
 *
 * Every number here becomes a sentence a learner reads before they speak, so a
 * wrong one is a false statement to a learner about their own face.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\retention
 */
final class retention_test extends \advanced_testcase {
    /**
     * Cases for effective_days(): site value, activity value, expected days.
     *
     * @return array
     */
    public static function effective_days_provider(): array {
        return [
            'activity defers, site keeps forever' => [0, -1, 0],
            'activity defers, site deletes after 7 days' => [7, -1, 7],
            'activity keeps forever on a site that deletes' => [7, 0, 0],
            'activity deletes after 30 days on a site that keeps' => [0, 30, 30],
            'activity defers, site value negative' => [-5, -1, 0],
            'no upper clamp' => [0, 3650, 3650],
            'an activity value below -1 means never' => [7, -3, 0],
        ];
    }

    /**
     * effective_days() resolves -1 to the site value and anything non-positive to never.
     *
     * @dataProvider effective_days_provider
     * @param int $site Site retentiondays.
     * @param int $activity Activity retentiondays.
     * @param int $expected Expected days.
     * @return void
     */
    public function test_effective_days(int $site, int $activity, int $expected): void {
        $this->resetAfterTest();
        set_config('retentiondays', $site, 'mod_presenterai');

        $this->assertSame(
            $expected,
            retention::effective_days((object) ['retentiondays' => $activity]),
            'A wrong answer here becomes a deletion date, or the absence of one, on every recording made from now on.'
        );
    }

    /**
     * expiry_for() is now plus the window, or 0 for never.
     *
     * @return void
     */
    public function test_expiry_for(): void {
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'mod_presenterai');
        $now = 1790000000;

        $this->assertSame(
            0,
            retention::expiry_for((object) ['retentiondays' => -1], $now),
            'A site that keeps forever must write 0, which the cleanup query excludes. Any other value is a deletion.'
        );
        $this->assertSame($now + 7 * DAYSECS, retention::expiry_for((object) ['retentiondays' => 7], $now));
    }

    /**
     * On S3 with a declared lifecycle, the row says what the callout promised.
     *
     * The callout folded the lifecycle in and finalize did not, so a learner
     * told "deleted after 7 days" then read "kept until removed", or a date
     * after the bucket had already deleted the bytes (design 8.6, point 1).
     *
     * @return void
     */
    public function test_callout_and_row_agree_on_an_s3_lifecycle(): void {
        $this->resetAfterTest();
        set_config('backend', 's3', 'mod_presenterai');
        set_config('s3lifecycledays', 7, 'mod_presenterai');
        $now = 1790000000;
        $instance = (object) ['retentiondays' => -1];

        foreach ([0 => 7, 30 => 7, 3 => 3] as $site => $expected) {
            set_config('retentiondays', $site, 'mod_presenterai');
            $promised = retention::prospective_days($instance);
            $expiresat = retention::expiry_for($instance, $now, 's3');
            $this->assertSame($expected, $promised);
            $this->assertSame($now + $promised * DAYSECS, $expiresat, "Retention {$site}: the row and the callout disagree.");

            $row = (object) ['status' => 'uploaded', 'backend' => 's3', 'storagekey' => 'k.webm', 'expiresat' => $expiresat];
            $state = \mod_presenterai\output\attempt_row::state($row, $now, true, true);
            $this->assertSame('attempt_deletes_on', $state['key'], "Retention {$site}: the row did not give the date.");
        }

        // The bucket deletes whether or not the task runs, so the row still gives the date.
        $row = (object) ['status' => 'uploaded', 'backend' => 's3', 'storagekey' => 'k.webm', 'expiresat' => $now + DAYSECS];
        $this->assertSame('attempt_deletes_on', \mod_presenterai\output\attempt_row::state($row, $now, false, true)['key']);
        $row->backend = 'fs';
        $this->assertSame('attempt_kept', \mod_presenterai\output\attempt_row::state($row, $now, false, true)['key']);

        // A rule declared for S3 says nothing about a recording on Moodle file storage.
        set_config('retentiondays', 0, 'mod_presenterai');
        $this->assertSame(0, retention::expiry_for($instance, $now, 'fs'));
    }

    /**
     * The callout promises nothing when the cleanup task is off, and the bucket's rule when one is declared.
     *
     * @return void
     */
    public function test_prospective_days_follows_the_task_and_the_bucket(): void {
        $this->resetAfterTest();
        set_config('retentiondays', 30, 'mod_presenterai');
        $instance = (object) ['retentiondays' => -1];

        $this->assertTrue(retention::cleanup_task_enabled(), 'The cleanup task ships enabled.');
        $this->assertSame(30, retention::prospective_days($instance));

        // An S3 lifecycle rule shorter than the plugin's window deletes first.
        set_config('backend', 's3', 'mod_presenterai');
        set_config('s3lifecycledays', 5, 'mod_presenterai');
        $this->assertSame(
            5,
            retention::prospective_days($instance),
            'The bucket deletes at 5 days whatever the database believes, so promising 30 is a false statement.'
        );

        // A lifecycle rule on a site that otherwise keeps forever is still a deletion date.
        set_config('retentiondays', 0, 'mod_presenterai');
        $this->assertSame(5, retention::prospective_days($instance));

        // A rule declared for S3 says nothing about Moodle file storage.
        set_config('backend', 'fs', 'mod_presenterai');
        $this->assertSame(0, retention::prospective_days($instance));

        // With the task disabled nothing deletes on a clock.
        set_config('retentiondays', 30, 'mod_presenterai');
        $task = \core\task\manager::get_scheduled_task(\mod_presenterai\task\cleanup::class);
        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);

        $this->assertFalse(retention::cleanup_task_enabled());
        $this->assertSame(
            0,
            retention::prospective_days($instance),
            'A disabled cleanup task never honours the date, so quoting one tells the learner something untrue (design 8.7f).'
        );
    }
}
