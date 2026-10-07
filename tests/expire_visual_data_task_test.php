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

use mod_presenterai\task\expire_visual_data;

/**
 * D5: the raw note's own clock, and the gate log's seven days.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\task\expire_visual_data
 */
final class expire_visual_data_task_test extends \advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * One activity and learner on a site that keeps media forever.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'mod_presenterai');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->learner = $generator->create_and_enrol($course, 'student');
        $this->instance = $generator->create_module('presenterai', ['course' => $course->id, 'videovision' => 1]);
    }

    /**
     * A recording whose raw note was written $daysago days ago.
     *
     * @param float $daysago Age of the note in days.
     * @return \stdClass
     */
    private function noted(float $daysago): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => 'kept-forever.webm',
            'visualevidence' => '{"note":"Hands low.","confidence":"high","unusable_frames":0}',
            'visualevidenceat' => time() - (int) round($daysago * DAYSECS),
        ]);
    }

    /**
     * Run the task, swallowing its output.
     *
     * @return string What it printed.
     */
    private function run_task(): string {
        ob_start();
        (new expire_visual_data())->execute();

        return (string) ob_get_clean();
    }

    /**
     * The row as stored now.
     *
     * @param \stdClass $rec A recording.
     * @return \stdClass
     */
    private function reload(\stdClass $rec): \stdClass {
        global $DB;

        return $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
    }

    /**
     * Unset means 30 days; an older note goes, a younger one stays, and the media is untouched.
     *
     * @return void
     */
    public function test_default_clock_with_media_kept(): void {
        unset_config('visualdatadays', 'mod_presenterai');
        $old = $this->noted(31);
        $young = $this->noted(29);
        $never = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id, 'userid' => $this->learner->id, 'storagekey' => 'x.webm',
        ]);

        $output = $this->run_task();

        $gone = $this->reload($old);
        $this->assertNull($gone->visualevidence);
        $this->assertSame(0, (int) $gone->visualevidenceat);
        $this->assertSame('kept-forever.webm', $gone->storagekey, 'The note\'s clock deleted the media.');
        $this->assertNotNull($this->reload($young)->visualevidence);
        $this->assertNull($this->reload($never)->visualevidence);
        $this->assertStringContainsString('expired 1 ', $output);
        $this->assertStringNotContainsString('Hands low', $output, 'The task printed the note.');
    }

    /**
     * The configured number of days is honoured.
     *
     * @return void
     */
    public function test_configured_clock(): void {
        set_config('visualdatadays', 5, 'mod_presenterai');
        $old = $this->noted(6);
        $young = $this->noted(4);

        $this->run_task();

        $this->assertNull($this->reload($old)->visualevidence);
        $this->assertNotNull($this->reload($young)->visualevidence);
    }

    /**
     * Zero or less is the minimum of 1 day, never forever.
     *
     * @return void
     */
    public function test_zero_is_one_day(): void {
        set_config('visualdatadays', 0, 'mod_presenterai');
        $old = $this->noted(1.5);
        $young = $this->noted(0.5);

        $this->run_task();

        $this->assertNull($this->reload($old)->visualevidence, 'Zero kept the note forever.');
        $this->assertNotNull($this->reload($young)->visualevidence);
    }

    /**
     * Gate log rows older than seven days are deleted, younger ones kept.
     *
     * @return void
     */
    public function test_gatelog_is_kept_seven_days(): void {
        global $DB;

        $rec = $this->noted(1);
        foreach ([8, 6] as $days) {
            $DB->insert_record('presenterai_gatelog', (object) [
                'recordingid' => $rec->id, 'target' => 'summary', 'layer' => 2, 'rule' => 'appearance',
                'rejectedtext' => "{$days} days old", 'timecreated' => time() - $days * DAYSECS,
            ]);
        }

        $output = $this->run_task();

        $this->assertSame(['6 days old'], array_values($DB->get_fieldset_select('presenterai_gatelog', 'rejectedtext', '1 = 1')));
        $this->assertStringContainsString('deleted 1 ', $output);
    }
}
