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

use mod_presenterai\local\vision\luminance;

/**
 * Layer 0's objective half: dark cells counted from the pixels (design 4.2).
 *
 * The sheets are made with GD at the size frames.js makes them, 1278 by 480,
 * as real JPEGs, so the check sees what it will see in production.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\luminance
 */
final class luminance_test extends \advanced_testcase {
    /**
     * Build a contact sheet whose listed cells are black and whose others carry a lit, textured picture.
     *
     * A lit cell needs texture: a flat colour has no detail to read and the
     * check rightly calls it dark.
     *
     * @param int[] $darkcells Cell indexes, 0 to 5, reading order.
     * @param int $width Sheet width.
     * @param int $height Sheet height.
     * @return string JPEG bytes.
     */
    public static function sheet(array $darkcells, int $width = 1278, int $height = 480): string {
        $image = imagecreatetruecolor($width, $height);
        $cellw = intdiv($width, 3);
        $cellh = intdiv($height, 2);
        mt_srand(42);
        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $cell = min(2, intdiv($x, $cellw)) + 3 * min(1, intdiv($y, $cellh));
                $grey = in_array($cell, $darkcells, true) ? 0 : mt_rand(90, 240);
                $colour = imagecolorallocate($image, $grey, $grey, $grey);
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $colour);
            }
        }
        ob_start();
        imagejpeg($image, null, 72);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Skip when GD is not available, which the check itself treats as "skip".
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!luminance::available() || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is not available.');
        }
    }

    /**
     * A bright sheet has no dark cells.
     *
     * @return void
     */
    public function test_bright_sheet(): void {
        $result = luminance::check(self::sheet([]));
        $this->assertSame(['dark' => 0, 'cells' => 6], $result);
        $this->assertFalse(luminance::too_dark($result));
    }

    /**
     * Three black cells are more than two of six, so the sheet fails layer 0.
     *
     * @return void
     */
    public function test_three_black_cells_are_too_dark(): void {
        $result = luminance::check(self::sheet([0, 2, 4]));
        $this->assertSame(3, $result['dark']);
        $this->assertTrue(luminance::too_dark($result));
    }

    /**
     * Two black cells are not more than two, so the sheet passes.
     *
     * @return void
     */
    public function test_two_black_cells_pass(): void {
        $result = luminance::check(self::sheet([1, 5]));
        $this->assertSame(2, $result['dark']);
        $this->assertFalse(luminance::too_dark($result));
    }

    /**
     * A flat, mid grey cell has nothing to read, which is what an undecoded frame looks like.
     *
     * @return void
     */
    public function test_flat_cells_are_dark(): void {
        $image = imagecreatetruecolor(1278, 480);
        imagefill($image, 0, 0, imagecolorallocate($image, 128, 128, 128));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $this->assertSame(6, luminance::check($bytes)['dark']);
    }

    /**
     * The grid scales to a sheet of another size.
     *
     * @return void
     */
    public function test_grid_scales_to_the_image(): void {
        $this->assertSame(3, luminance::check(self::sheet([3, 4, 5], 641, 301))['dark']);
    }

    /**
     * Something that is not an image is a skipped check, not a verdict.
     *
     * @return void
     */
    public function test_undecodable_bytes_skip(): void {
        $this->assertNull(luminance::check('not an image at all'));
        $this->assertNull(luminance::check(''));
        $this->assertFalse(luminance::too_dark(null));
    }
}
