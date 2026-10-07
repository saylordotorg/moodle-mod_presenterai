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

use mod_presenterai\output\gatelog_page;

/**
 * The site administrator's view of withheld body language feedback (design 5.3).
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\gatelog_page
 */
final class gatelog_page_test extends \advanced_testcase {
    /**
     * One recording with log rows of the given ages and texts.
     *
     * @param array $rows List of [days old, text].
     * @return \stdClass The recording.
     */
    private function logged(array $rows): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Speaking <b>101</b>']);
        $learner = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('presenterai', ['course' => $course->id, 'name' => 'Persuasive talk']);
        $rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id, 'userid' => $learner->id, 'storagekey' => 'a.webm',
        ]);
        foreach ($rows as [$days, $text]) {
            $DB->insert_record('presenterai_gatelog', (object) [
                'recordingid' => $rec->id, 'target' => 'summary', 'layer' => 2, 'rule' => 'clothing',
                'rejectedtext' => $text, 'timecreated' => time() - (int) round($days * DAYSECS),
            ]);
        }

        return $rec;
    }

    /**
     * The last seven days only, newest first, with the activity linked and the text escaped.
     *
     * @return void
     */
    public function test_rows_and_escaping(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $rec = $this->logged([[8, 'too old'], [2, 'older <script>alert(1)</script>'], [1, 'newest']]);

        $context = (new gatelog_page())->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame(2, $context['total']);
        $this->assertSame(['newest', 'older <script>alert(1)</script>'], array_column($context['rows'], 'text'));
        $row = $context['rows'][0];
        $this->assertSame(get_string('gatelog_target_summary', 'mod_presenterai'), $row['target']);
        $this->assertSame(get_string('gatelog_layer_deny', 'mod_presenterai'), $row['layer']);
        $this->assertSame('clothing', $row['rule']);
        $this->assertStringContainsString('recordingid=' . $rec->id, $row['activityurl']);
        $this->assertSame('Persuasive talk', $row['activity']);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/gatelog', $context);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('too old', $html);
    }

    /**
     * Fifty rows a page, with a paging bar past that.
     *
     * @return void
     */
    public function test_paging(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $rows = [];
        for ($i = 0; $i < gatelog_page::PERPAGE + 3; $i++) {
            $rows[] = [1, "text {$i}"];
        }
        $this->logged($rows);

        $first = (new gatelog_page(0))->export_for_template($PAGE->get_renderer('core'));
        $second = (new gatelog_page(1))->export_for_template($PAGE->get_renderer('core'));

        $this->assertCount(gatelog_page::PERPAGE, $first['rows']);
        $this->assertCount(3, $second['rows']);
        $this->assertNotSame('', $first['pagingbar']);
    }

    /**
     * Nothing logged says so.
     *
     * @return void
     */
    public function test_empty(): void {
        global $PAGE;

        $this->resetAfterTest();
        $context = (new gatelog_page())->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($context['hasrows']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/gatelog', $context);
        $this->assertStringContainsString(get_string('gatelog_empty', 'mod_presenterai'), $html);
    }
}
