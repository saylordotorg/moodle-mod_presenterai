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

use mod_presenterai\local\sources;

/**
 * The sources a teacher gives learners to cite.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\sources
 */
final class sources_test extends \advanced_testcase {
    /**
     * Blank lines go, white space collapses, and the count and length are capped.
     *
     * @return void
     */
    public function test_parse(): void {
        $this->assertSame([], sources::parse(null));
        $this->assertSame([], sources::parse("  \n\r\n\t"));
        $this->assertSame(
            ['CDC. (2024). About sleep.', 'NIH. (2022). Sleep deficiency.'],
            sources::parse("  CDC.   (2024).\tAbout sleep. \r\n\r\nNIH. (2022). Sleep deficiency.\n")
        );

        $many = implode("\n", array_map(fn($i) => "Source {$i}", range(1, 15)));
        $this->assertCount(sources::MAX_SOURCES, sources::parse($many));

        $long = sources::parse(str_repeat('a', sources::MAX_CHARS + 50))[0];
        $this->assertSame(sources::MAX_CHARS, \core_text::strlen($long));
    }

    /**
     * Stored text is the parsed list, or null for none.
     *
     * @return void
     */
    public function test_clean(): void {
        $this->assertNull(sources::clean(''));
        $this->assertNull(sources::clean(null));
        $this->assertSame("A (2020).\nB (2021).", sources::clean("\nA (2020).\n\n B (2021). \n"));
    }

    /**
     * The minimum never exceeds the sources given.
     *
     * @return void
     */
    public function test_required(): void {
        $this->assertSame(3, sources::required((object) ['sources' => "A\nB\nC\nD", 'minsources' => 3]));
        $this->assertSame(2, sources::required((object) ['sources' => "A\nB", 'minsources' => 3]));
        $this->assertSame(0, sources::required((object) ['sources' => null, 'minsources' => 3]));
        $this->assertSame(0, sources::required((object) ['sources' => "A\nB", 'minsources' => -1]));
        $this->assertSame(0, sources::required((object) []));
    }

    /**
     * Learners see each source escaped, with its web address as a link, and the minimum.
     *
     * @return void
     */
    public function test_export_for_view(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);

        $none = sources::export_for_view((object) ['sources' => null, 'minsources' => 0], $context);
        $this->assertFalse($none['hassources']);

        $view = sources::export_for_view((object) [
            'sources' => "CDC. (2024). About sleep. https://www.cdc.gov/sleep/about/\n<script>x</script> (2020).",
            'minsources' => 1,
        ], $context);
        $this->assertTrue($view['hassources']);
        $this->assertCount(2, $view['items']);
        $this->assertStringContainsString('<a href="https://www.cdc.gov/sleep/about/"', $view['items'][0]['html']);
        $this->assertStringNotContainsString('<script>', $view['items'][1]['html']);
        $this->assertStringContainsString('at least 1 of these 2', $view['intro']);
    }
}
