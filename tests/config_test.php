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

use mod_presenterai\local\config;

/**
 * Site recording configuration: the preset, the length ceiling and the size estimate.
 *
 * The size estimate is what stands between a learner and a failed upload after
 * seven minutes of speaking, so its arithmetic is pinned to numbers worked out
 * by hand rather than recomputed with the same formula.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\config
 */
final class config_test extends \advanced_testcase {
    /**
     * With nothing configured the default preset is used, and it says which one it is.
     *
     * @return void
     */
    public function test_quality_default(): void {
        $this->resetAfterTest();

        $quality = config::quality();

        $this->assertSame('standard_480p', $quality['key']);
        $this->assertSame(854, $quality['width']);
        $this->assertSame(480, $quality['height']);
        $this->assertSame(500, $quality['videokbps']);
        $this->assertSame(40, $quality['audiokbps']);
    }

    /**
     * A configured preset is honoured.
     *
     * @return void
     */
    public function test_quality_configured(): void {
        $this->resetAfterTest();
        set_config('quality', 'high_720p', 'mod_presenterai');

        $quality = config::quality();

        $this->assertSame('high_720p', $quality['key']);
        $this->assertSame(1200, $quality['videokbps']);
        $this->assertSame(48, $quality['audiokbps']);
    }

    /**
     * An unknown stored key falls back to the default instead of failing.
     *
     * @return void
     */
    public function test_quality_unknown_key_falls_back(): void {
        $this->resetAfterTest();
        set_config('quality', 'ultra_8k', 'mod_presenterai');

        $this->assertSame(config::DEFAULT_QUALITY, config::quality()['key']);
    }

    /**
     * The length ceiling defaults to 720 seconds, and zero or negative means the default.
     *
     * @return void
     */
    public function test_max_recording_seconds_default(): void {
        $this->resetAfterTest();

        $this->assertSame(720, config::max_recording_seconds());

        set_config('maxrecordingseconds', 0, 'mod_presenterai');
        $this->assertSame(720, config::max_recording_seconds());

        set_config('maxrecordingseconds', -5, 'mod_presenterai');
        $this->assertSame(720, config::max_recording_seconds());
    }

    /**
     * A configured ceiling is honoured.
     *
     * @return void
     */
    public function test_max_recording_seconds_override(): void {
        $this->resetAfterTest();
        set_config('maxrecordingseconds', 1200, 'mod_presenterai');

        $this->assertSame(1200, config::max_recording_seconds());
    }

    /**
     * Seven minutes at the default preset.
     *
     * Video: (500 + 40) kbps * 125 bytes per kbit * 420 s * 1.15 = 32,602,500 bytes.
     * Audio: 40 kbps * 125 * 420 * 1.15 = 2,415,000 bytes.
     *
     * @return void
     */
    public function test_estimated_bytes_at_420_seconds(): void {
        $this->resetAfterTest();

        $this->assertSame(32602500, config::estimated_bytes('video', 420));
        $this->assertSame(2415000, config::estimated_bytes('audio', 420));
    }

    /**
     * The estimate follows the configured preset, and an unknown mode is costed as video.
     *
     * 720p: (1200 + 48) * 125 * 420 * 1.15 = 75,348,000 bytes.
     *
     * @return void
     */
    public function test_estimated_bytes_follows_preset(): void {
        $this->resetAfterTest();
        set_config('quality', 'high_720p', 'mod_presenterai');

        $this->assertSame(75348000, config::estimated_bytes('video', 420));
        $this->assertSame(75348000, config::estimated_bytes('something', 420));
        $this->assertSame(0, config::estimated_bytes('video', 0));
    }
}
