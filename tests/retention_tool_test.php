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

use mod_presenterai\local\retention_tool;

/**
 * The tool that changes deletion dates on recordings that already exist.
 *
 * The cases are design 8.3's guards. Each one stands between an administrator
 * typing one command and a year of recordings being deleted at the next cron
 * run.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\retention_tool
 */
final class retention_tool_test extends \advanced_testcase {
    /** @var int A fixed now, so dates are exact. */
    private const NOW = 1790000000;

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass An activity that deletes after 30 days. */
    private \stdClass $instance;

    /** @var \stdClass A learner. */
    private \stdClass $user;

    /**
     * One course and one 30 day activity; the site warns 3 days ahead.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('deletewarndays', 3, 'mod_presenterai');
        set_config('retentiondays', 0, 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->activity(30);
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * An activity with its own retention, written straight to the row.
     *
     * Straight to the row so that nothing normalising form data, such as the
     * setretention capability check, can change the value the test relies on.
     *
     * @param int $days The activity's retentiondays.
     * @return \stdClass The instance.
     */
    private function activity(int $days): \stdClass {
        global $DB;

        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'retentiondays', $days, ['id' => $instance->id]);
        $instance->retentiondays = $days;

        return $instance;
    }

    /**
     * A recording row on an activity.
     *
     * @param int $presenteraiid Activity id.
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(int $presenteraiid, array $fields = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $presenteraiid,
            'userid' => $this->user->id,
            'storagekey' => random_string(32) . '.webm',
        ]);
    }

    /**
     * --from is mandatory and must be one of the three bases.
     *
     * @return void
     */
    public function test_from_is_required(): void {
        $this->expectException(\moodle_exception::class);
        retention_tool::plan(['now' => self::NOW]);
    }

    /**
     * --from=created dates from creation, with the grace floor lifting anything due before it.
     *
     * @return void
     */
    public function test_created_with_grace(): void {
        $old = $this->recording($this->instance->id, ['timecreated' => self::NOW - 60 * DAYSECS]);
        $recent = $this->recording($this->instance->id, ['timecreated' => self::NOW - DAYSECS]);
        $this->recording($this->instance->id, ['status' => 'uploading', 'timecreated' => self::NOW - 90 * DAYSECS]);
        $this->recording($this->instance->id, ['storagekey' => null, 'timecreated' => self::NOW - 90 * DAYSECS]);

        $plan = retention_tool::plan(['from' => 'created', 'now' => self::NOW]);

        $this->assertSame(2, $plan['inscope'], 'Rows with no media, or still uploading, have no date to change.');
        $this->assertSame(2, $plan['currentlyzero']);
        $this->assertSame(7, $plan['gracedays'], 'The floor is the larger of the warning period and a week.');
        $this->assertSame(
            self::NOW + 7 * DAYSECS,
            $plan['changes'][(int) $old->id],
            'A retroactive apply scheduled a deletion inside the grace floor, so a learner could lose a recording today.'
        );
        $this->assertSame(self::NOW - DAYSECS + 30 * DAYSECS, $plan['changes'][(int) $recent->id]);
        $this->assertSame(0, $plan['eligiblesoon']);
        $this->assertFalse($plan['refused']);
    }

    /**
     * --no-grace needs --force, and then lets a past date through.
     *
     * @return void
     */
    public function test_no_grace_needs_force(): void {
        $old = $this->recording($this->instance->id, ['timecreated' => self::NOW - 60 * DAYSECS]);

        try {
            retention_tool::plan(['from' => 'created', 'grace' => false, 'now' => self::NOW]);
            $this->fail('--no-grace ran without --force.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cli_nogracewithoutforce', $e->errorcode);
        }

        $plan = retention_tool::plan(['from' => 'created', 'grace' => false, 'force' => true, 'now' => self::NOW]);
        $this->assertSame(self::NOW - 30 * DAYSECS, $plan['changes'][(int) $old->id]);
        $this->assertSame(1, $plan['eligiblesoon']);
    }

    /**
     * --from=now dates from now, and an activity that keeps forever is skipped and counted.
     *
     * @return void
     */
    public function test_now_and_the_never_activity(): void {
        $forever = $this->activity(0);
        $rec = $this->recording($this->instance->id, ['timecreated' => self::NOW - 60 * DAYSECS]);
        $kept = $this->recording($forever->id, ['timecreated' => self::NOW - 60 * DAYSECS]);

        $plan = retention_tool::plan(['from' => 'now', 'now' => self::NOW]);

        $this->assertSame(self::NOW + 30 * DAYSECS, $plan['changes'][(int) $rec->id]);
        $this->assertArrayNotHasKey((int) $kept->id, $plan['changes'], 'An activity set to keep forever was given a date.');
        $this->assertSame(1, $plan['skippednever']);

        $scoped = retention_tool::plan(['from' => 'now', 'instance' => $forever->id, 'now' => self::NOW]);
        $this->assertSame(1, $scoped['inscope'], '--instance did not narrow the run.');
    }

    /**
     * --from=none cancels dates, and apply() writes exactly what the plan says.
     *
     * @return void
     */
    public function test_none_cancels_and_apply_writes(): void {
        global $DB;

        $dated = $this->recording($this->instance->id, ['expiresat' => self::NOW + 5 * DAYSECS]);
        $this->recording($this->instance->id, ['expiresat' => 0]);

        $plan = retention_tool::plan(['from' => 'none', 'now' => self::NOW]);
        $this->assertSame([(int) $dated->id => 0], $plan['changes']);

        $this->assertSame(1, retention_tool::apply($plan));
        $this->assertSame(0, (int) $DB->get_field('presenterai_recording', 'expiresat', ['id' => $dated->id]));
    }

    /**
     * More than the threshold newly eligible within a day is refused without --force.
     *
     * @return void
     */
    public function test_large_batches_need_force(): void {
        $daily = $this->activity(1);
        for ($i = 0; $i <= retention_tool::FORCE_THRESHOLD; $i++) {
            $this->recording($daily->id, ['timecreated' => self::NOW - 10 * DAYSECS]);
        }

        $plan = retention_tool::plan(['from' => 'now', 'instance' => $daily->id, 'now' => self::NOW]);
        $this->assertSame(retention_tool::FORCE_THRESHOLD + 1, $plan['eligiblesoon']);
        $this->assertTrue($plan['refused']);
        try {
            retention_tool::apply($plan);
            $this->fail('A refused plan was applied.');
        } catch (\moodle_exception $e) {
            $this->assertSame('cli_toomanyeligible', $e->errorcode);
        }

        $forced = retention_tool::plan(['from' => 'now', 'instance' => $daily->id, 'force' => true, 'now' => self::NOW]);
        $this->assertFalse($forced['refused']);
    }
}
