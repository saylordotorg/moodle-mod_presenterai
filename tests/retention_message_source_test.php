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
 * The guard test of design section 9.1: an attempt's retention text comes from its row.
 *
 * The failure this exists to catch is quiet and plausible. Somebody "simplifies"
 * the attempt list to read the site setting, and from then on an admin who
 * turns retention off makes every existing learner read "kept until deleted"
 * while cron deletes their recording on the date it was given (design 8.2).
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\attempt_row
 */
final class retention_message_source_test extends \advanced_testcase {
    /**
     * A future date on the row survives the site setting being 0.
     *
     * @return void
     */
    public function test_row_date_beats_site_setting_off(): void {
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'mod_presenterai');

        $now = time();
        $expiresat = $now + 5 * DAYSECS;
        $row = (object) ['status' => 'uploaded', 'storagekey' => 'k.webm', 'expiresat' => $expiresat,
            'mediadeletedat' => 0, 'mediagonereason' => null];

        $state = attempt_row::state($row, $now, true);

        $this->assertSame('attempt_deletes_on', $state['key']);
        $this->assertStringContainsString(
            userdate($expiresat, get_string('strftimedatefullshort', 'langconfig')),
            attempt_row::text($state)
        );
    }

    /**
     * A row with no date stays "kept" when the site setting is 7.
     *
     * @return void
     */
    public function test_no_row_date_beats_site_setting_on(): void {
        $this->resetAfterTest();
        set_config('retentiondays', 7, 'mod_presenterai');

        $now = time();
        $row = (object) ['status' => 'uploaded', 'storagekey' => 'k.webm', 'expiresat' => 0,
            'mediadeletedat' => 0, 'mediagonereason' => null];

        $state = attempt_row::state($row, $now, true);

        $this->assertSame('attempt_kept', $state['key']);
        $this->assertNull($state['a']);
        $this->assertSame(get_string('attempt_kept', 'mod_presenterai'), attempt_row::text($state));
    }

    /**
     * The same two facts through export(), which is what the page renders.
     *
     * Needs the server slice's access class, because export() asks it which
     * actions to offer.
     *
     * @return void
     */
    public function test_export_reads_the_row(): void {
        global $DB;

        if (!class_exists('\\mod_presenterai\\local\\access')) {
            $this->markTestSkipped('needs the server slice');
        }
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $now = time();

        $id = $DB->insert_record('presenterai_recording', (object) [
            'presenteraiid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'mode' => 'video',
            'backend' => 'fs',
            'storagekey' => 'k.webm',
            'status' => 'uploaded',
            'expiresat' => $now + 5 * DAYSECS,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $row = $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);

        set_config('retentiondays', 0, 'mod_presenterai');
        $this->assertSame('attempt_deletes_on', attempt_row::export($row, $context, (int) $user->id, $now, true)['statekey']);

        $row->expiresat = 0;
        set_config('retentiondays', 7, 'mod_presenterai');
        $export = attempt_row::export($row, $context, (int) $user->id, $now, true);
        $this->assertSame('attempt_kept', $export['statekey']);
        $this->assertSame(get_string('attempt_kept', 'mod_presenterai'), $export['state']);
    }

    /**
     * No get_config() is reachable from the per attempt renderer or its template.
     *
     * Comments are stripped before the scan, so the class may explain why it
     * does not call get_config() without failing this test.
     *
     * @return void
     */
    public function test_no_get_config_in_per_attempt_renderer(): void {
        global $CFG;

        $php = file_get_contents($CFG->dirroot . '/mod/presenterai/classes/output/attempt_row.php');
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->assertStringNotContainsString('get_config', $code);
        // Nor anything that reads the site settings by another name.
        $this->assertDoesNotMatchRegularExpression('/\$CFG\b/', $code);
        $this->assertStringNotContainsString('retention::', $code);

        $template = file_get_contents($CFG->dirroot . '/mod/presenterai/templates/attempts.mustache');
        // Strip the documentation block; a template can only read its context,
        // so what is left must not name a config helper either.
        $template = preg_replace('/\{\{![^\n]*?\}\}/', '', $template);
        $template = preg_replace('/\{\{!.*?\n\}\}/s', '', $template);
        $this->assertStringNotContainsString('get_config', $template);
        $this->assertStringNotContainsString('{{#config}}', $template);
    }
}
