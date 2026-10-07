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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\storage\store_factory;

/**
 * Which file a recording is transcribed from, and how it's cut to fit.
 *
 * OpenAI's transcription endpoint takes at most 25 MB, and a long video goes
 * over that. So the browser records a second, audio only track beside a video
 * (recorder.js, about 32 kbps, which fits about 100 minutes), and this class
 * picks the source in this order:
 *
 * 1. The separate audio track, when the attempt has one.
 * 2. An audio only recording, which is the audio already.
 * 3. For a video with no audio track (recorded before the track existed, or
 *    by a browser that couldn't record two at once): the audio extracted on
 *    the server with ffmpeg, when an admin has set its path.
 * 4. The video itself, when it fits under the limit.
 * 5. Otherwise the attempt fails with too_large, which is what the readiness
 *    warning on the settings page predicts.
 *
 * Whatever the source, a file still over the limit is cut into segments
 * with ffmpeg when it's available, each re-encoded at a known bitrate so it
 * fits, and transcribed in order; the scorer joins the texts. Without ffmpeg
 * a file over the limit fails with too_large rather than be sent to a 413.
 *
 * The limit applies only to OpenAI's own endpoint. A self hosted endpoint has
 * no known limit, so a file is sent to it whole.
 *
 * choose(), segment_seconds() and order_segments() are pure, so the rules can
 * be tested without ffmpeg or a store; prepare() does the fetching and the
 * cutting.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transcription_source {
    /** @var string The separate audio track (audiokey). */
    public const SOURCE_AUDIO = 'audio';

    /** @var string The recording itself (storagekey): an audio only recording, or a video under the limit. */
    public const SOURCE_RECORDING = 'recording';

    /** @var string Audio extracted from the video with ffmpeg. */
    public const SOURCE_EXTRACT = 'extract';

    /** @var int The bitrate the browser records the audio track at, and ffmpeg re-encodes segments at, in kbps. */
    public const AUDIO_TRACK_KBPS = 32;

    /** @var int Percent of the limit a segment is planned to fill, leaving room for a variable bitrate. */
    public const SEGMENT_FILL_PERCENT = 80;

    /** @var int The shortest segment worth making, in seconds. */
    public const MIN_SEGMENT_SECONDS = 60;

    /** @var int Seconds ffmpeg may take over one recording. */
    public const FFMPEG_TIMEOUT = 600;

    /** @var string[] MIME types by extension, for the transcription upload. */
    public const MIMETYPES = [
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'wav' => 'audio/wav',
    ];

    /** @var callable|null The ffmpeg runner, tests only: fn(string[] $args): int exit code. */
    private static $testrunner = null;

    /**
     * Which file to transcribe. Pure.
     *
     * @param bool $hasaudio Whether the attempt has a separate audio track.
     * @param string $mode The recording's mode, video or audio.
     * @param int $recordingbytes The recording's size in bytes.
     * @param int $limit The transcription service's upload limit in bytes, 0 for none.
     * @param bool $ffmpeg Whether ffmpeg is available.
     * @return string One of the SOURCE_* constants.
     * @throws ai_exception too_large, when nothing can fit.
     */
    public static function choose(bool $hasaudio, string $mode, int $recordingbytes, int $limit, bool $ffmpeg): string {
        if ($hasaudio) {
            return self::SOURCE_AUDIO;
        }
        if ($mode === 'audio') {
            return self::SOURCE_RECORDING;
        }
        if ($ffmpeg) {
            return self::SOURCE_EXTRACT;
        }
        if ($limit <= 0 || $recordingbytes <= $limit) {
            return self::SOURCE_RECORDING;
        }
        throw ai_exception::for_reason(
            ai_exception::TOO_LARGE,
            'The video is ' . $recordingbytes . ' bytes with no separate audio track and no ffmpeg to extract one'
        );
    }

    /**
     * How long each segment should be so it fits under the limit at AUDIO_TRACK_KBPS. Pure.
     *
     * @param int $limit The upload limit in bytes.
     * @return int Seconds, at least MIN_SEGMENT_SECONDS.
     */
    public static function segment_seconds(int $limit): int {
        $bytespersecond = (int) (self::AUDIO_TRACK_KBPS * 1000 / 8);
        $seconds = (int) floor($limit * self::SEGMENT_FILL_PERCENT / 100 / $bytespersecond);

        return max(self::MIN_SEGMENT_SECONDS, $seconds);
    }

    /**
     * Segment files in the order they were cut, whatever order the directory listed them in. Pure.
     *
     * ffmpeg numbers them from 000. Natural order keeps 1000 after 999 should
     * a recording ever be long enough to get there.
     *
     * @param string[] $paths Segment paths.
     * @return string[] The same paths, in order.
     */
    public static function order_segments(array $paths): array {
        $paths = array_values($paths);
        usort($paths, fn($a, $b) => strnatcmp(basename($a), basename($b)));

        return $paths;
    }

    /**
     * The ffmpeg binary an admin set, or '' when there's none that can run.
     *
     * @return string
     */
    public static function ffmpeg_path(): string {
        if (self::$testrunner !== null) {
            return 'ffmpeg';
        }
        $path = trim((string) get_config('mod_presenterai', 'ffmpegpath'));
        if ($path === '' || !is_file($path) || !is_executable($path)) {
            return '';
        }

        return $path;
    }

    /**
     * Fetch and, if need be, cut the files to transcribe for one attempt.
     *
     * @param \stdClass $rec The recording row.
     * @param int $limit The transcription service's upload limit in bytes, 0 for none.
     * @return array ['files' => list of ['path' => string, 'mime' => string] in order,
     *     'source' => one of SOURCE_*, 'workdir' => a directory to remove afterwards, or ''].
     * @throws ai_exception too_large when nothing fits; network when the store can't be read.
     */
    public static function prepare(\stdClass $rec, int $limit): array {
        $ffmpeg = self::ffmpeg_path();
        $hasaudio = !empty($rec->audiokey);
        $source = self::choose($hasaudio, (string) ($rec->mode ?? 'video'), (int) ($rec->sizebytes ?? 0), $limit, $ffmpeg !== '');

        $key = $source === self::SOURCE_AUDIO ? (string) $rec->audiokey : (string) $rec->storagekey;
        $ext = self::ext_for($key);
        $path = store_factory::for_recording($rec)->fetch_to_file($key, $ext);
        if ($path === null) {
            // On S3 this is a download that can fail for a while; the retry rule decides.
            throw new ai_exception('network', true, 'The recording could not be fetched from storage.');
        }

        $fits = $limit <= 0 || filesize($path) <= $limit;
        if ($source !== self::SOURCE_EXTRACT && $fits) {
            return ['files' => [['path' => $path, 'mime' => self::MIMETYPES[$ext]]], 'source' => $source, 'workdir' => ''];
        }
        if ($ffmpeg === '') {
            throw ai_exception::for_reason(
                ai_exception::TOO_LARGE,
                'The ' . $source . ' file is ' . filesize($path) . ' bytes, over the limit, and there is no ffmpeg to split it'
            );
        }

        // Extraction and splitting are one ffmpeg run: the audio is re-encoded
        // at a known bitrate and cut wherever the limit needs it. A short
        // extraction gives one segment.
        $workdir = make_request_directory();
        $segments = self::cut($path, $workdir, $limit > 0 ? self::segment_seconds($limit) : 0);
        foreach ($segments as $segment) {
            if ($limit > 0 && filesize($segment['path']) > $limit) {
                throw ai_exception::for_reason(ai_exception::TOO_LARGE, 'A segment is still over the limit');
            }
        }

        return ['files' => $segments, 'source' => $source, 'workdir' => $workdir];
    }

    /**
     * Re-encode a file's audio and cut it into segments, in order.
     *
     * Opus in Ogg first, which most ffmpeg builds can write; 16 kHz mono WAV
     * if that fails, which every build can, with segments sized for its
     * larger bitrate.
     *
     * @param string $input The source file.
     * @param string $workdir An empty directory for the output.
     * @param int $seconds Seconds per segment at AUDIO_TRACK_KBPS, or 0 for one file.
     * @return array List of ['path', 'mime'], in order.
     * @throws ai_exception bad_response when ffmpeg can't produce anything.
     */
    private static function cut(string $input, string $workdir, int $seconds): array {
        // WAV at 16 kHz mono is 256 kbps, eight times the Opus bitrate.
        $attempts = [
            // Constant bitrate, so a segment's size is its length times the
            // bitrate. Opus's default VBR overshot by half on test audio.
            ['ogg', ['-c:a', 'libopus', '-b:a', self::AUDIO_TRACK_KBPS . 'k', '-vbr', 'off'], $seconds],
            ['wav', ['-c:a', 'pcm_s16le', '-ar', '16000'], $seconds > 0 ? max(self::MIN_SEGMENT_SECONDS, intdiv($seconds, 8)) : 0],
        ];
        foreach ($attempts as [$ext, $codec, $segmentseconds]) {
            foreach (glob($workdir . '/part*') ?: [] as $stale) {
                if (is_file($stale)) {
                    unlink($stale);
                }
            }
            $args = array_merge(['-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-vn', '-ac', '1'], $codec);
            if ($segmentseconds > 0) {
                $args = array_merge($args, ['-f', 'segment', '-segment_time', (string) $segmentseconds, '-reset_timestamps', '1']);
                $args[] = $workdir . '/part%03d.' . $ext;
            } else {
                $args[] = $workdir . '/part000.' . $ext;
            }
            if (self::run_ffmpeg($args) !== 0) {
                continue;
            }
            $paths = self::order_segments(glob($workdir . '/part*.' . $ext) ?: []);
            $paths = array_values(array_filter($paths, fn($p) => filesize($p) > 0));
            if (!empty($paths)) {
                return array_map(fn($p) => ['path' => $p, 'mime' => self::MIMETYPES[$ext]], $paths);
            }
        }

        throw ai_exception::for_reason(ai_exception::BAD_RESPONSE, 'ffmpeg could not extract the audio');
    }

    /**
     * Run ffmpeg with arguments, never through a shell.
     *
     * @param string[] $args The arguments, without the binary.
     * @return int The exit code.
     */
    private static function run_ffmpeg(array $args): int {
        if (self::$testrunner !== null) {
            return (int) (self::$testrunner)($args);
        }
        $command = array_merge([self::ffmpeg_path()], $args);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return -1;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = time() + self::FFMPEG_TIMEOUT;
        while (true) {
            // Drain both pipes so a chatty ffmpeg can't block on a full buffer.
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return -1;
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return (int) $status['exitcode'];
    }

    /**
     * The extension to fetch a key as, falling back to webm.
     *
     * @param string $key The stored key.
     * @return string
     */
    private static function ext_for(string $key): string {
        $ext = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));

        return isset(self::MIMETYPES[$ext]) ? $ext : 'webm';
    }

    /**
     * Stand in for ffmpeg (tests only). The runner is given the argument list
     * and returns an exit code; it writes whatever files it means ffmpeg to
     * have written. Null removes it.
     *
     * @param callable|null $runner The runner.
     * @return void
     */
    public static function set_test_runner(?callable $runner): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('transcription_source::set_test_runner() is for unit tests only');
        }
        self::$testrunner = $runner;
    }
}
