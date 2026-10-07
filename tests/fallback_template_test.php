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

use mod_presenterai\local\vision\fallback_template;

/**
 * The deterministic summary of design 6 (b), banded on each criterion's own maximum.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\fallback_template
 */
final class fallback_template_test extends \advanced_testcase {
    /**
     * The two visual criteria plus a spoken one the template must ignore.
     *
     * @param int $body Score of the gestures criterion, out of $bodymax.
     * @param int $eye Score of the camera criterion, out of 10.
     * @param bool $bodyassessed Whether the gestures criterion was assessed.
     * @param int $bodymax The gestures criterion's maximum.
     * @return array
     */
    private function criteria(int $body, int $eye, bool $bodyassessed = true, int $bodymax = 5): array {
        return [
            ['name' => 'Content', 'score' => 0, 'max_score' => 5, 'assessed' => true, 'visual' => false],
            ['name' => 'Body Language & Gestures', 'score' => $body, 'max_score' => $bodymax, 'assessed' => $bodyassessed,
                'visual' => true],
            ['name' => 'Eye Contact & Camera Presence', 'score' => $eye, 'max_score' => 10, 'visual' => true],
        ];
    }

    /**
     * Each band, using each criterion's own maximum.
     *
     * @return void
     */
    public function test_bands(): void {
        $this->assertSame(
            get_string('visual_fallback_bothstrong', 'mod_presenterai'),
            fallback_template::build($this->criteria(4, 8))
        );
        $this->assertSame(
            get_string('visual_fallback_bothweak', 'mod_presenterai'),
            fallback_template::build($this->criteria(2, 4))
        );
        $this->assertSame(
            get_string('visual_fallback_mixed', 'mod_presenterai'),
            fallback_template::build($this->criteria(3, 7))
        );
        $this->assertSame(
            get_string('visual_fallback_onestrong', 'mod_presenterai', (object) [
                'strong' => 'eye contact & camera presence',
                'weak' => 'Body Language & Gestures',
            ]),
            fallback_template::build($this->criteria(1, 9))
        );
        // 16 of 20 is strong on its own maximum, though it would be off the scale of 5.
        $this->assertSame(
            get_string('visual_fallback_bothstrong', 'mod_presenterai'),
            fallback_template::build($this->criteria(16, 9, true, 20))
        );
    }

    /**
     * An unassessed visual criterion prepends the partial sentence; with one left, it is all there is.
     *
     * @return void
     */
    public function test_partial(): void {
        $partial = get_string('visual_fallback_partial', 'mod_presenterai');
        $this->assertSame($partial, fallback_template::build($this->criteria(4, 8, false)));

        $three = $this->criteria(4, 8);
        $three[] = ['name' => 'Posture', 'score' => 0, 'max_score' => 5, 'assessed' => false, 'visual' => true];
        $this->assertSame(
            $partial . ' ' . get_string('visual_fallback_bothstrong', 'mod_presenterai'),
            fallback_template::build($three)
        );
    }

    /**
     * Never empty, even with nothing visual to speak of.
     *
     * @return void
     */
    public function test_never_empty(): void {
        $this->assertNotSame('', fallback_template::build([]));
    }
}
