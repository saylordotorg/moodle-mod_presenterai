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

namespace mod_presenterai\local\vision;

/**
 * The one part of layer 0 that is evidence rather than the model's opinion of itself.
 *
 * Design section 4.2. The vision model's own confidence and unusable_frames
 * come from the same model that wrote the note, so a model confident enough to
 * describe posture from six dark thumbnails reports "high" and 0 and the gate
 * never fires. This check is computed from the pixels before any model sees
 * them: the contact sheet's geometry is fixed (3 columns by 2 rows, built by
 * amd/src/frames.js), so the cell boundaries are arithmetic, not detection.
 *
 * A cell is dark when its mean luma is under 40 or its standard deviation is
 * under 8, on a 0 to 255 scale, sampling every 4th pixel in each direction. A
 * black cell is dark on both counts; a cell of flat grey, which is what a
 * frame that never decoded looks like after the sampler's black fill and JPEG,
 * is dark on the second.
 *
 * It catches darkness only. Blur, distance and crop have no cheap objective
 * signal and none is claimed (design 12.3).
 *
 * GD is not guaranteed on every site (design 14). Without it, or for an image
 * GD will not decode, check() returns null and the caller skips the check
 * with a debugging note rather than failing the attempt.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class luminance {
    /** @var int Columns in the contact sheet, as frames.js builds it. */
    public const COLS = 3;

    /** @var int Rows in the contact sheet. */
    public const ROWS = 2;

    /** @var float A cell whose mean luma is below this is dark. */
    public const DARK_MEAN = 40.0;

    /** @var float A cell whose luma standard deviation is below this is dark (no detail to read). */
    public const DARK_STDDEV = 8.0;

    /** @var int Sample every Nth pixel in each direction. */
    public const STEP = 4;

    /** @var int More dark cells than this and the sheet is unusable (design 4.2: "more than two of the six"). */
    public const MAX_DARK_CELLS = 2;

    /**
     * Whether GD can decode an image here at all.
     *
     * @return bool
     */
    public static function available(): bool {
        return function_exists('imagecreatefromstring') && function_exists('imagecolorat');
    }

    /**
     * Count the dark cells of a contact sheet.
     *
     * @param string $jpegbytes The sheet as stored, JPEG or PNG.
     * @return array|null ['dark' => int, 'cells' => int], or null when GD is missing or the image won't decode.
     */
    public static function check(string $jpegbytes): ?array {
        if ($jpegbytes === '' || !self::available()) {
            return null;
        }
        // GD warns on a truncated or foreign image rather than throwing, and
        // a warning must not become the attempt's failure.
        $image = @imagecreatefromstring($jpegbytes);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < self::COLS || $height < self::ROWS) {
            imagedestroy($image);
            return null;
        }

        $truecolor = imageistruecolor($image);
        $cellw = intdiv($width, self::COLS);
        $cellh = intdiv($height, self::ROWS);
        $dark = 0;
        for ($row = 0; $row < self::ROWS; $row++) {
            for ($col = 0; $col < self::COLS; $col++) {
                $x0 = $col * $cellw;
                $y0 = $row * $cellh;
                // The last column and row run to the edge, so a sheet whose
                // size is not a multiple of the grid loses no pixels.
                $x1 = ($col === self::COLS - 1) ? $width : $x0 + $cellw;
                $y1 = ($row === self::ROWS - 1) ? $height : $y0 + $cellh;
                if (self::cell_is_dark($image, $truecolor, $x0, $y0, $x1, $y1)) {
                    $dark++;
                }
            }
        }
        imagedestroy($image);

        return ['dark' => $dark, 'cells' => self::COLS * self::ROWS];
    }

    /**
     * Whether a sheet's result fails layer 0.
     *
     * @param array|null $result A check() result, or null when the check was skipped.
     * @return bool True only when the check ran and more than MAX_DARK_CELLS cells are dark.
     */
    public static function too_dark(?array $result): bool {
        return $result !== null && (int) $result['dark'] > self::MAX_DARK_CELLS;
    }

    /**
     * Mean and spread of luma over one cell, sampled on a grid.
     *
     * @param \GdImage $image The decoded sheet.
     * @param bool $truecolor Whether the image is truecolor, which decides how a pixel is read.
     * @param int $x0 Left edge, inclusive.
     * @param int $y0 Top edge, inclusive.
     * @param int $x1 Right edge, exclusive.
     * @param int $y1 Bottom edge, exclusive.
     * @return bool
     */
    private static function cell_is_dark(\GdImage $image, bool $truecolor, int $x0, int $y0, int $x1, int $y1): bool {
        $n = 0;
        $sum = 0.0;
        $sumsq = 0.0;
        for ($y = $y0; $y < $y1; $y += self::STEP) {
            for ($x = $x0; $x < $x1; $x += self::STEP) {
                $rgb = imagecolorat($image, $x, $y);
                if ($truecolor) {
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                } else {
                    $c = imagecolorsforindex($image, $rgb);
                    $r = $c['red'];
                    $g = $c['green'];
                    $b = $c['blue'];
                }
                // ITU-R BT.601 luma, the weighting JPEG itself uses.
                $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $sum += $luma;
                $sumsq += $luma * $luma;
                $n++;
            }
        }
        if ($n === 0) {
            return true;
        }
        $mean = $sum / $n;
        $variance = max(0.0, $sumsq / $n - $mean * $mean);

        return $mean < self::DARK_MEAN || sqrt($variance) < self::DARK_STDDEV;
    }
}
