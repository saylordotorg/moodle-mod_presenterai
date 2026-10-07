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

use mod_presenterai\local\ai\core_ai_client;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\fs_store;
use mod_presenterai\local\storage\media_ref;
use mod_presenterai\local\vision\visual_pipeline;
use mod_presenterai\output\policy;
use mod_presenterai\output\view_page;

/**
 * D26 on the learner's side: on the core AI route no frames are taken and nothing says they are.
 *
 * The core route is reached two ways, airoute = core and Automatic with no
 * vendor key for scoring, and both are checked. The view page, the
 * recorder's config, the privacy paragraph, start_upload and finalize all
 * have to agree, and on a keyed route everything stays as it was.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\vision\visual_pipeline
 * @covers     \mod_presenterai\local\recording_manager
 * @covers     \mod_presenterai\output\view_page
 * @covers     \mod_presenterai\output\policy
 */
final class core_route_frames_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A camera activity with body language feedback and the opt out on. */
    private \stdClass $instance;

    /** @var \stdClass Its course module row. */
    private \stdClass $cm;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /**
     * The activity, a learner, and core AI available.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
        core_ai_client::set_test_processor(fn() => ['success' => true, 'text' => '{}', 'errorcode' => 0]);
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = get_course($generator->create_course()->id);
        $this->instance = $generator->create_module('presenterai', [
            'course' => $this->course->id,
            'mode' => 'video',
            'videovision' => 1,
            'allowvisualoptout' => 1,
            'maxattempts' => 0,
        ]);
        $this->cm = get_coursemodule_from_id('presenterai', $this->instance->cmid, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->learner = $generator->create_and_enrol($this->course, 'student');
        $this->instance = $this->reload_instance();
        $this->assertSame(1, (int) $this->instance->videovision);
    }

    /**
     * Never leave a double behind.
     *
     * @return void
     */
    protected function tearDown(): void {
        core_ai_client::set_test_processor(null);
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * The instance row as stored.
     *
     * @return \stdClass
     */
    private function reload_instance(): \stdClass {
        global $DB;
        return $DB->get_record('presenterai', ['id' => $this->instance->id], '*', MUST_EXIST);
    }

    /**
     * The three ways the site can be set up, and whether frames are taken in each.
     *
     * @return array name => [airoute, set an OpenAI key, frames taken]
     */
    public static function routes(): array {
        return [
            'core chosen, with a vendor key' => ['core', true, false],
            'automatic, no vendor key so core' => ['auto', false, false],
            'automatic, with a vendor key' => ['auto', true, true],
        ];
    }

    /**
     * Set up a route.
     *
     * @param string $route The airoute setting.
     * @param bool $key Whether to set an OpenAI key.
     * @return void
     */
    private function route(string $route, bool $key): void {
        set_config('airoute', $route, 'mod_presenterai');
        // A self hosted transcription endpoint, so a site with no vendor key
        // still takes recordings.
        set_config('sttendpoint', 'https://stt.example.com/v1/audio/transcriptions', 'mod_presenterai');
        if ($key) {
            set_config('openaiapikey', 'sk-test', 'mod_presenterai');
        }
    }

    /**
     * The view page, the recorder's config and the privacy paragraph follow the route.
     *
     * @dataProvider routes
     * @param string $route The airoute setting.
     * @param bool $key Whether an OpenAI key is set.
     * @param bool $frames Whether frames are taken.
     * @return void
     */
    public function test_learner_page_follows_the_route(string $route, bool $key, bool $frames): void {
        global $PAGE;

        $this->route($route, $key);
        $this->assertSame(!$frames, route_resolver::scoring_on_core());
        $this->assertSame($frames, visual_pipeline::takes_frames($this->instance));

        $this->setUser($this->learner);
        $data = (new view_page($this->instance, $this->course, $this->context, (int) $this->learner->id))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame($frames ? 1 : 0, $data['config']['videovision'], 'The recorder would sample frames.');
        $this->assertSame($frames ? 1 : 0, $data['config']['allowvisualoptout']);
        $this->assertSame($frames, $data['recorder']['visualdisclosure']);
        $this->assertSame($frames, $data['recorder']['visualoptout']);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/view', $data);
        $disclosure = get_string('visual_disclosure', 'mod_presenterai');
        $this->assertSame($frames, str_contains($html, $disclosure) || str_contains($html, s($disclosure)));
        $this->assertSame($frames, str_contains($html, 'data-region="visualoptout"'));
        $this->assertStringContainsString('data-videovision="' . ($frames ? 1 : 0) . '"', $html);

        $paragraph = policy::privacy_paragraph($this->instance, true);
        $frameclauses = [
            get_string('privacy_frames_keep', 'mod_presenterai'),
            get_string('privacy_frames_delete', 'mod_presenterai'),
        ];
        $mentions = str_contains($paragraph, $frameclauses[0]) || str_contains($paragraph, $frameclauses[1]);
        $this->assertSame($frames, $mentions, 'The privacy paragraph is wrong about frames.');
    }

    /**
     * On core a frame sheet is refused at start_upload, before a byte is sent.
     *
     * @dataProvider routes
     * @param string $route The airoute setting.
     * @param bool $key Whether an OpenAI key is set.
     * @param bool $frames Whether frames are taken.
     * @return void
     */
    public function test_frames_upload_follows_the_route(string $route, bool $key, bool $frames): void {
        $this->route($route, $key);
        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->learner->id)['recording'];
        try {
            recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, 'frames', 'jpg', 10);
            $this->assertTrue($frames, 'A frame sheet was accepted on the core route.');
        } catch (\moodle_exception $e) {
            $this->assertFalse($frames, 'A frame sheet was refused on a keyed route.');
            $this->assertSame('error:framesdisabled', $e->errorcode);
        }
    }

    /**
     * Frames that arrived before the site moved to core are deleted at finalize, and the audio track is kept.
     *
     * @return void
     */
    public function test_finalize_discards_frames_after_a_move_to_core(): void {
        $this->route('auto', true);
        $rec = recording_manager::begin($this->instance, $this->cm, $this->context, (int) $this->learner->id)['recording'];
        foreach ([media_ref::KIND_FRAMES => ['jpg', "\xFF\xD8\xFF frames"], media_ref::KIND_RECORDING => ['webm', 'video']]
                as $kind => [$ext, $bytes]) {
            $target = recording_manager::start_upload($rec, $this->instance, $this->course, $this->context, $kind, $ext,
                strlen($bytes));
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $bytes);
            rewind($stream);
            (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);
        }

        unset_config('openaiapikey', 'mod_presenterai');
        $this->assertTrue(route_resolver::scoring_on_core());
        $done = recording_manager::finalize($rec, $this->instance, $this->course, $this->context, 0, 30, '');

        $this->assertSame('uploaded', $done->status);
        $this->assertEmpty($done->frameskey, 'Frames nothing will analyze were kept.');
        $this->assertEmpty(get_file_storage()->get_area_files($this->context->id, 'mod_presenterai', 'frames', $done->id, 'id',
            false));
    }

    /**
     * The visual pipeline says nothing at all about body language on core, rather than "couldn't be analyzed".
     *
     * @return void
     */
    public function test_pipeline_has_no_visual_section_on_core(): void {
        $this->route('core', true);
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => 'v.webm',
            'frameskey' => 'sheet.jpg',
        ]);
        $evidence = visual_pipeline::evidence($rec, $this->instance, $this->context, route_resolver::scoring_route());
        $this->assertSame(visual_pipeline::EVIDENCE_NONE, $evidence['state']);
        $this->assertSame(visual_pipeline::STATUS_NONE, visual_pipeline::status_for($evidence));
    }
}
