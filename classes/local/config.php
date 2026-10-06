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

namespace mod_presenterai\local;

/**
 * Site level recording configuration: the quality preset and the length ceiling.
 *
 * Ported from SOLA's soapbox_config (classes/soapbox_config.php:31-69), keeping
 * the numbers and dropping everything that was a Soapbox assignment concern.
 * The per activity clamp that lived beside these in SOLA (clamp_assignment) is
 * instance_manager::normalise() here, because an activity has a form and a row
 * where a Soapbox assignment had neither.
 *
 * The preset bitrates are what the recorder hands to MediaRecorder, and they are
 * also the only honest input to "how big will this recording be", which is the
 * question the front end has to answer before a learner starts speaking (plan
 * section 4.4).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /**
     * Video quality presets, bitrates in kbps.
     *
     * The numbers are SOLA's (classes/soapbox_config.php:56-69) so that a site
     * moving from Soapbox records at the size it already budgeted for. The
     * labels are not carried here: they are language strings named
     * quality_<key>, so the settings page can translate them.
     *
     * @var array<string, array{width: int, height: int, videokbps: int, audiokbps: int}>
     */
    public const QUALITY_PRESETS = [
        'low_360p' => ['width' => 640, 'height' => 360, 'videokbps' => 350, 'audiokbps' => 32],
        'standard_480p' => ['width' => 854, 'height' => 480, 'videokbps' => 500, 'audiokbps' => 40],
        'high_720p' => ['width' => 1280, 'height' => 720, 'videokbps' => 1200, 'audiokbps' => 48],
    ];

    /** @var string The preset used when none is configured or the stored key is unknown. */
    public const DEFAULT_QUALITY = 'standard_480p';

    /** @var int Site ceiling on any activity's recording length, in seconds (12 minutes, as SOLA). */
    public const DEFAULT_MAX_RECORDING_SECONDS = 720;

    /**
     * @var int The shortest minimum or maximum length an activity may set, in seconds.
     *
     * SOLA's floor was 1 second, which lets a teacher configure an activity in
     * which a cough is a complete presentation. Ten seconds is short enough to
     * never get in the way of a real activity and long enough that the recorder
     * has something to upload.
     */
    public const MIN_SECONDS_FLOOR = 10;

    /**
     * Headroom on the target bitrate, in percent of it.
     *
     * VP8 and VP9 overshoot their target bitrate on high motion, and
     * MediaRecorder treats the bitrate as a target, not a limit (plan section
     * 4.4). Fifteen percent over is the headroom the plan budgets for. Held as
     * an integer percentage so the estimate is integer arithmetic and cannot
     * round up by a byte on a float that came out a hair high.
     *
     * @var int
     */
    private const BITRATE_HEADROOM_PERCENT = 115;

    /**
     * The configured quality preset, falling back to the default.
     *
     * An unknown key falls back rather than throwing because the setting is
     * read on every recorder page load, and a preset removed in a later
     * version must not take every activity on the site down with it.
     *
     * @return array{key: string, width: int, height: int, videokbps: int, audiokbps: int}
     */
    public static function quality(): array {
        $key = (string) get_config('mod_presenterai', 'quality');
        if (!isset(self::QUALITY_PRESETS[$key])) {
            $key = self::DEFAULT_QUALITY;
        }
        return ['key' => $key] + self::QUALITY_PRESETS[$key];
    }

    /**
     * The site ceiling on any activity's recording length, in seconds.
     *
     * Zero, negative and unset all mean the default, because a ceiling of
     * zero would make every activity unrecordable rather than unlimited.
     *
     * @return int
     */
    public static function max_recording_seconds(): int {
        $value = (int) get_config('mod_presenterai', 'maxrecordingseconds');
        return $value > 0 ? $value : self::DEFAULT_MAX_RECORDING_SECONDS;
    }

    /**
     * The largest a recording of this mode and length is expected to be, in bytes.
     *
     * Uses the configured preset. The front end compares this with the upload
     * ceiling before recording starts, so that a learner is told up front
     * rather than after speaking for seven minutes. kbps times 125 is bytes per
     * second (1000 bits, 8 bits to the byte).
     *
     * @param string $mode 'video' or 'audio'. Anything other than 'audio' is costed as video.
     * @param int $seconds Length of the recording in seconds.
     * @return int Estimated size in bytes, rounded up.
     */
    public static function estimated_bytes(string $mode, int $seconds): int {
        $preset = self::quality();
        $kbps = ($mode === 'audio') ? $preset['audiokbps'] : $preset['videokbps'] + $preset['audiokbps'];
        $scaled = $kbps * 125 * max(0, $seconds) * self::BITRATE_HEADROOM_PERCENT;
        // Rounded up: an estimate that is a byte short is the wrong side to err on.
        return intdiv($scaled + 99, 100);
    }
}
