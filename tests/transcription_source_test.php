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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\stt_interface;
use mod_presenterai\local\scorer;
use mod_presenterai\local\transcription_source;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/scorer_test.php');

/**
 * Which file a recording is transcribed from, and how it's cut to fit under 25 MB.
 *
 * ffmpeg is replaced by a runner that writes the files ffmpeg would have,
 * so the rules are tested without the binary and the order of segments is
 * tested by writing them out of order.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\transcription_source
 * @covers     \mod_presenterai\local\scorer
 */
final class transcription_source_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The camera activity. */
    private \stdClass $instance;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * One graded camera activity and a learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
        transcription_source::set_test_runner(null);
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id, 'grade' => 100]);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Never leave a double behind.
     *
     * @return void
     */
    protected function tearDown(): void {
        transcription_source::set_test_runner(null);
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A finished attempt with a recording and optionally an audio track, on the File API.
     *
     * @param string $video The recording's bytes.
     * @param string|null $audio The audio track's bytes, or null for none.
     * @param string $mode video or audio.
     * @return \stdClass
     */
    private function attempt(string $video, ?string $audio = null, string $mode = 'video'): \stdClass {
        $videokey = random_string(12) . ($mode === 'audio' ? '.m4a' : '.webm');
        $audiokey = $audio === null ? null : random_string(12) . '.ogg';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'mode' => $mode,
            'storagekey' => $videokey,
            'audiokey' => $audiokey,
            'sizebytes' => strlen($video),
            'durationseconds' => 600,
        ]);
        $files = ['recording' => [$videokey, $video]];
        if ($audio !== null) {
            $files['audio'] = [$audiokey, $audio];
        }
        foreach ($files as $area => [$key, $bytes]) {
            get_file_storage()->create_file_from_string([
                'contextid' => $this->context->id,
                'component' => 'mod_presenterai',
                'filearea' => $area,
                'itemid' => $rec->id,
                'filepath' => '/',
                'filename' => $key,
            ], $bytes);
        }
        return $rec;
    }

    /**
     * An ffmpeg stand in that writes these segments, in this order, to the output pattern's directory.
     *
     * @param array $segments filename => content, written in the order given.
     * @param array $calls Each call's arguments, collected.
     * @param int $failfirst How many calls fail before one succeeds.
     * @return callable
     */
    private static function runner(array $segments, array &$calls, int $failfirst = 0): callable {
        return function (array $args) use ($segments, &$calls, &$failfirst): int {
            $calls[] = $args;
            if ($failfirst-- > 0) {
                return 1;
            }
            $dir = dirname((string) end($args));
            $ext = pathinfo((string) end($args), PATHINFO_EXTENSION);
            foreach ($segments as $name => $content) {
                file_put_contents($dir . '/' . $name . '.' . $ext, $content);
            }
            return 0;
        };
    }

    /**
     * The order of sources: audio track, audio recording, ffmpeg extraction, the video, too large.
     *
     * @return void
     */
    public function test_choose(): void {
        $mb = 1048576;
        $limit = 25 * $mb;
        $this->assertSame('audio', transcription_source::choose(true, 'video', 300 * $mb, $limit, false));
        $this->assertSame('audio', transcription_source::choose(true, 'video', 300 * $mb, $limit, true));
        $this->assertSame('recording', transcription_source::choose(false, 'audio', 5 * $mb, $limit, false));
        $this->assertSame('extract', transcription_source::choose(false, 'video', 10 * $mb, $limit, true), 'ffmpeg is preferred.');
        $this->assertSame('recording', transcription_source::choose(false, 'video', 10 * $mb, $limit, false));
        $this->assertSame('recording', transcription_source::choose(false, 'video', 300 * $mb, 0, false), 'No limit, sent whole.');
        try {
            transcription_source::choose(false, 'video', 30 * $mb, $limit, false);
            $this->fail('A video over the limit with no track and no ffmpeg was accepted.');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::TOO_LARGE, $e->reason);
        }
    }

    /**
     * Segment length fits the limit at the track bitrate, and segments come back in cut order.
     *
     * @return void
     */
    public function test_segment_length_and_order(): void {
        $seconds = transcription_source::segment_seconds(26214400);
        $this->assertLessThanOrEqual(26214400, $seconds * transcription_source::AUDIO_TRACK_KBPS * 1000 / 8);
        $this->assertGreaterThan(60 * 60, $seconds, 'Segments are needlessly short.');
        $this->assertSame(transcription_source::MIN_SEGMENT_SECONDS, transcription_source::segment_seconds(10));

        $this->assertSame(
            ['/t/part000.ogg', '/t/part002.ogg', '/t/part010.ogg', '/t/part999.ogg', '/t/part1000.ogg'],
            transcription_source::order_segments(['/t/part010.ogg', '/t/part1000.ogg', '/t/part000.ogg', '/t/part999.ogg',
                '/t/part002.ogg'])
        );
    }

    /**
     * prepare(): the audio track is what's sent when there is one, whole when it fits.
     *
     * @return void
     */
    public function test_prepare_prefers_the_audio_track(): void {
        $rec = $this->attempt(str_repeat('v', 200), 'the audio');
        $prepared = transcription_source::prepare($rec, 100);

        $this->assertSame('audio', $prepared['source']);
        $this->assertCount(1, $prepared['files']);
        $this->assertSame('the audio', file_get_contents($prepared['files'][0]['path']));
        $this->assertSame('audio/ogg', $prepared['files'][0]['mime']);
        $this->assertSame('', $prepared['workdir']);
    }

    /**
     * A track whose file can't be read is passed over for the recording, rather than failing the attempt.
     *
     * @return void
     */
    public function test_prepare_falls_back_when_the_track_is_missing(): void {
        global $DB;

        $rec = $this->attempt('the video');
        $DB->set_field('presenterai_recording', 'audiokey', 'no-such-track.ogg', ['id' => $rec->id]);
        $rec->audiokey = 'no-such-track.ogg';
        $prepared = transcription_source::prepare($rec, 100);
        $this->assertSame('recording', $prepared['source']);
        $this->assertSame('the video', file_get_contents($prepared['files'][0]['path']));
    }

    /**
     * prepare() without ffmpeg: a video under the limit goes whole; one over it, or a track over it, is too large.
     *
     * @return void
     */
    public function test_prepare_without_ffmpeg(): void {
        $small = $this->attempt('a small video');
        $prepared = transcription_source::prepare($small, 100);
        $this->assertSame('recording', $prepared['source']);
        $this->assertSame('a small video', file_get_contents($prepared['files'][0]['path']));
        $this->assertSame('video/webm', $prepared['files'][0]['mime']);

        foreach ([$this->attempt(str_repeat('v', 200)), $this->attempt('v', str_repeat('a', 200))] as $rec) {
            try {
                transcription_source::prepare($rec, 100);
                $this->fail('A file over the limit was sent with no ffmpeg to cut it.');
            } catch (ai_exception $e) {
                $this->assertSame(ai_exception::TOO_LARGE, $e->reason);
            }
        }
    }

    /**
     * prepare() with ffmpeg: a video with no track is extracted and cut, and the segments come back in order.
     *
     * @return void
     */
    public function test_prepare_extracts_and_cuts_in_order(): void {
        $calls = [];
        // Written out of order, as a directory listing may return them.
        transcription_source::set_test_runner(self::runner(['part002' => 'three', 'part000' => 'one', 'part001' => 'two'], $calls));

        $rec = $this->attempt(str_repeat('v', 500));
        $prepared = transcription_source::prepare($rec, 100);

        $this->assertSame('extract', $prepared['source']);
        $this->assertSame(['one', 'two', 'three'], array_map(fn($f) => file_get_contents($f['path']), $prepared['files']));
        $this->assertSame('audio/ogg', $prepared['files'][0]['mime']);
        $this->assertNotSame('', $prepared['workdir']);
        $this->assertCount(1, $calls);
        $args = $calls[0];
        $this->assertContains('-vn', $args, 'The video stream was not dropped.');
        $this->assertContains('libopus', $args);
        $this->assertContains('segment', $args);
        $this->assertSame((string) transcription_source::segment_seconds(100), $args[array_search('-segment_time', $args) + 1]);

        // An audio track over the limit is cut the same way.
        $calls = [];
        transcription_source::set_test_runner(self::runner(['part000' => 'first', 'part001' => 'second'], $calls));
        $prepared = transcription_source::prepare($this->attempt('v', str_repeat('a', 300)), 100);
        $this->assertSame('audio', $prepared['source']);
        $this->assertSame(['first', 'second'], array_map(fn($f) => file_get_contents($f['path']), $prepared['files']));
    }

    /**
     * ffmpeg without Opus falls back to WAV, with shorter segments for its bigger bitrate; a segment over the limit fails.
     *
     * @return void
     */
    public function test_prepare_falls_back_to_wav_and_checks_each_segment(): void {
        $calls = [];
        transcription_source::set_test_runner(self::runner(['part000' => 'pcm'], $calls, 1));
        $prepared = transcription_source::prepare($this->attempt(str_repeat('v', 500)), 100);
        $this->assertCount(2, $calls);
        $this->assertContains('pcm_s16le', $calls[1]);
        $this->assertSame('audio/wav', $prepared['files'][0]['mime']);
        $this->assertStringEndsWith('.wav', $prepared['files'][0]['path']);

        $calls = [];
        transcription_source::set_test_runner(self::runner(['part000' => str_repeat('x', 150)], $calls));
        try {
            transcription_source::prepare($this->attempt(str_repeat('v', 500)), 100);
            $this->fail('A segment over the limit was accepted.');
        } catch (ai_exception $e) {
            $this->assertSame(ai_exception::TOO_LARGE, $e->reason);
        }
    }

    /**
     * The ffmpeg path setting: empty means none, and a path that isn't an executable file is ignored.
     *
     * @return void
     */
    public function test_ffmpeg_path(): void {
        $this->assertSame('', transcription_source::ffmpeg_path());
        set_config('ffmpegpath', '/no/such/ffmpeg', 'mod_presenterai');
        $this->assertSame('', transcription_source::ffmpeg_path());
        $file = make_request_directory() . '/ffmpeg';
        file_put_contents($file, '#!/bin/sh');
        chmod($file, 0644);
        set_config('ffmpegpath', $file, 'mod_presenterai');
        $this->assertSame('', transcription_source::ffmpeg_path(), 'A file that cannot run was used.');
        chmod($file, 0755);
        $this->assertSame($file, transcription_source::ffmpeg_path());
    }

    /**
     * The scorer transcribes the audio track, not the video, when there is one.
     *
     * @return void
     */
    public function test_scorer_transcribes_the_audio_track(): void {
        $stt = $this->recording_stt(['A transcript long enough to be scored, about renewable energy policy.']);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([$this->answer()]));

        $rec = $this->attempt('the video', 'the audio track');
        $this->score((int) $rec->id);

        $this->assertSame(['the audio track'], $stt->contents);
        $this->assertSame(['audio/ogg'], $stt->mimes);
        $this->assertSame('scored', $this->reload((int) $rec->id)->status);
    }

    /**
     * The scorer joins segment transcripts in the order the segments were cut.
     *
     * @return void
     */
    public function test_scorer_joins_segments_in_order(): void {
        $calls = [];
        transcription_source::set_test_runner(self::runner(['part001' => 'second', 'part000' => 'first'], $calls));
        $stt = $this->recording_stt([
            'The first half of a talk about renewable energy,',
            'and the second half, which concludes it well.',
        ]);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([$this->answer()]));

        $rec = $this->attempt('a video with no audio track');
        $this->score((int) $rec->id);

        $this->assertSame(['first', 'second'], $stt->contents);
        $after = $this->reload((int) $rec->id);
        $this->assertSame(
            'The first half of a talk about renewable energy, and the second half, which concludes it well.',
            $after->transcript
        );
        $this->assertSame('scored', $after->status);
    }

    /**
     * Without ffmpeg, a long video with no track fails with too_large rather than being sent.
     *
     * @return void
     */
    public function test_scorer_too_large_without_ffmpeg(): void {
        $stt = $this->recording_stt([], 'openai');
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, scorer_test::fake_client([$this->answer()]));

        $rec = $this->attempt('a video');
        global $DB;
        $DB->set_field('presenterai_recording', 'sizebytes', 30 * 1048576, ['id' => $rec->id]);
        $out = $this->score((int) $rec->id);

        $this->assertSame([], $stt->contents, 'The video was sent anyway.');
        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
        $this->assertStringContainsString('too_large', $out);
    }

    /**
     * A transcription double that records what it was given.
     *
     * @param string[] $answers Texts to return, in order.
     * @param string $route The route it reports.
     * @return stt_interface
     */
    private function recording_stt(array $answers, string $route = 'openai'): stt_interface {
        return new class ($answers, $route) implements stt_interface {
            /** @var string[] What each call was sent. */
            public array $contents = [];

            /** @var string[] Each call's MIME type. */
            public array $mimes = [];

            /**
             * Hold the answers.
             *
             * @param array $answers Texts.
             * @param string $route The route.
             */
            public function __construct(
                /** @var array Texts. */
                private array $answers,
                /** @var string The route. */
                private string $route
            ) {
            }

            /**
             * Record the file and answer.
             *
             * @param string $filepath The file.
             * @param string $mime Its type.
             * @return array
             */
            public function transcribe(string $filepath, string $mime): array {
                $this->contents[] = file_get_contents($filepath);
                $this->mimes[] = $mime;
                return ['text' => (string) array_shift($this->answers), 'model' => 'fake-whisper'];
            }

            /**
             * The model.
             *
             * @return string
             */
            public function model(): string {
                return 'fake-whisper';
            }

            /**
             * The route.
             *
             * @return string
             */
            public function route(): string {
                return $this->route;
            }
        };
    }

    /**
     * A scoring answer for every spoken criterion of the default rubric.
     *
     * @return string
     */
    private function answer(): string {
        $criteria = [];
        foreach (local\rubric_manager::resolve($this->instance, $this->context)['criteria'] as $criterion) {
            if (empty($criterion['visual'])) {
                $criteria[] = ['name' => $criterion['name'], 'score' => 4, 'assessed' => true, 'feedback' => 'Good.'];
            }
        }
        return json_encode(['criteria' => $criteria, 'overall' => 'Well done.', 'tips' => []]);
    }

    /**
     * Run the scorer, capturing its mtrace line.
     *
     * @param int $recordingid The recording.
     * @return string
     */
    private function score(int $recordingid): string {
        ob_start();
        try {
            scorer::score($recordingid, false);
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /**
     * The row as stored.
     *
     * @param int $id The recording id.
     * @return \stdClass
     */
    private function reload(int $id): \stdClass {
        global $DB;
        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }
}
