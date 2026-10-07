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

use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\deck_renderer;
use mod_presenterai\local\slide_context;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/scorer_test.php');

/**
 * Slide text, pacing and the slide design pass, for the scoring prompt.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\slide_context
 */
final class slide_context_test extends \advanced_testcase {
    /**
     * Never leave a double behind for the next test class.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * Time on each slide is the gap to the next advance, and the last runs to the end.
     *
     * @return void
     */
    public function test_context_text_pacing(): void {
        $text = slide_context::context_text(
            ['Intro', '', 'Close'],
            [['t' => 0, 'i' => 0], ['t' => 10, 'i' => 1], ['t' => 25, 'i' => 2], ['t' => 30, 'i' => 0]],
            40
        );

        $this->assertStringStartsWith('The presentation used 3 slide(s).', $text);
        $this->assertStringContainsString('Slide 1 (~20s): Intro', $text, 'A slide shown twice adds up.');
        $this->assertStringContainsString('Slide 2 (~15s): (no text on this slide)', $text);
        $this->assertStringContainsString('Slide 3 (~5s): Close', $text);
        $this->assertStringContainsString('pacing', $text);
        $this->assertSame('', slide_context::context_text([], [], 40));
    }

    /**
     * Long slides and long decks are clamped.
     *
     * @return void
     */
    public function test_context_text_clamps(): void {
        $text = slide_context::context_text([str_repeat('x', 1000)], [], 10);
        $this->assertStringContainsString(str_repeat('x', 600), $text);
        $this->assertStringNotContainsString(str_repeat('x', 601), $text);

        $big = slide_context::context_text(array_fill(0, 60, str_repeat('y', 600)), [], 600);
        $this->assertSame(8000, \core_text::strlen($big));
    }

    /**
     * Sampling keeps at most twelve slides, spread evenly, first and last included.
     *
     * @return void
     */
    public function test_sample(): void {
        $this->assertSame([1, 2, 3], slide_context::sample([1, 2, 3]));
        $picked = slide_context::sample(range(0, 29));
        $this->assertCount(12, $picked);
        $this->assertSame(0, $picked[0]);
        $this->assertSame(29, $picked[11]);
        $this->assertSame($picked, array_values(array_unique($picked)));
    }

    /**
     * The design pass prompt names the type and stays off the delivery.
     *
     * @return void
     */
    public function test_vision_prompt(): void {
        $this->assertStringContainsString("learner's persuasive presentation", slide_context::vision_prompt('persuasive'));
        $this->assertStringContainsString("learner's informative presentation", slide_context::vision_prompt(''));
        $this->assertStringContainsString('Do not judge the spoken delivery', slide_context::vision_prompt('x'));
    }

    /**
     * Set up an activity with slides and an attempt carrying the three page fixture deck.
     *
     * @param int $slidevision Whether slide vision is on.
     * @return array [\stdClass $rec, \stdClass $instance, \context_module $ctx, \stdClass $course]
     */
    private function deck_attempt(int $slidevision): array {
        global $CFG;

        $this->resetAfterTest();
        if (!deck_renderer::is_available()) {
            foreach (['/opt/homebrew/bin/gs', '/usr/local/bin/gs', '/usr/bin/gs'] as $candidate) {
                if (is_executable($candidate)) {
                    $CFG->pathtogs = $candidate;
                    break;
                }
            }
        }
        if (!deck_renderer::is_available()) {
            $this->markTestSkipped('Ghostscript is not installed, so the deck cannot be read.');
        }

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $course->id,
            'slidesenabled' => 1,
            'slidevision' => $slidevision,
        ]);
        $ctx = \context_module::instance($instance->cmid);
        $deckkey = random_string(16) . '.pdf';
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'deckkey' => $deckkey,
            'slidetimeline' => json_encode([['t' => 0, 'i' => 0], ['t' => 20, 'i' => 1], ['t' => 50, 'i' => 2]]),
            'durationseconds' => 60,
        ]);
        get_file_storage()->create_file_from_pathname([
            'contextid' => $ctx->id,
            'component' => 'mod_presenterai',
            'filearea' => 'deck',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $deckkey,
        ], __DIR__ . '/fixtures/deck-3pages.pdf');

        return [$rec, $instance, $ctx, $course];
    }

    /**
     * Without slides, or without a deck, there's no context.
     *
     * @return void
     */
    public function test_build_needs_slides_and_a_deck(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id, 'slidesenabled' => 0]);
        $ctx = \context_module::instance($instance->cmid);
        $empty = ['text' => '', 'count' => 0, 'visionnote' => ''];

        $rec = (object) ['id' => 1, 'userid' => 2, 'backend' => 'fs', 'deckkey' => 'a.pdf', 'durationseconds' => 10];
        $this->assertSame($empty, slide_context::build($rec, $instance, $ctx, $course));

        $instance->slidesenabled = 1;
        $rec->deckkey = null;
        $this->assertSame($empty, slide_context::build($rec, $instance, $ctx, $course));

        // A deck key whose file is gone is best effort, not an error.
        $rec->deckkey = 'gone.pdf';
        $this->assertSame($empty, slide_context::build($rec, $instance, $ctx, $course));
    }

    /**
     * A real deck gives each slide's text and pacing, and no design pass when it's off.
     *
     * @return void
     */
    public function test_build_reads_the_deck(): void {
        [$rec, $instance, $ctx, $course] = $this->deck_attempt(0);
        $client = scorer_test::fake_client(['Should not be asked.']);
        route_resolver::set_test_client(route_resolver::PURPOSE_SLIDE_VISION, $client);

        $out = slide_context::build($rec, $instance, $ctx, $course);

        $this->assertSame(3, $out['count']);
        $this->assertStringContainsString('Slide 1 (~20s): Slide 1', $out['text']);
        $this->assertStringContainsString('Slide 2 (~30s): Slide 2', $out['text']);
        $this->assertStringContainsString('Slide 3 (~10s): Slide 3', $out['text']);
        $this->assertSame('', $out['visionnote']);
        $this->assertCount(0, $client->calls);
    }

    /**
     * With slide vision on, one call sends the pages as images, and the note and spend are recorded.
     *
     * @return void
     */
    public function test_build_runs_the_design_pass(): void {
        global $DB;

        [$rec, $instance, $ctx, $course] = $this->deck_attempt(1);
        $client = scorer_test::fake_client([str_repeat('Clean layout. ', 100)]);
        route_resolver::set_test_client(route_resolver::PURPOSE_SLIDE_VISION, $client);

        $out = slide_context::build($rec, $instance, $ctx, $course);

        $this->assertSame(800, \core_text::strlen($out['visionnote']));
        $this->assertCount(1, $client->calls);
        [$system, $user, $opts] = $client->calls[0];
        $this->assertStringContainsString('presentation-design coach', $system);
        $this->assertSame('Give the slide visual-design note now.', $user);
        $this->assertCount(3, $opts['images']);
        $this->assertSame('image/png', $opts['images'][0]['mime']);
        $this->assertNotFalse(base64_decode($opts['images'][0]['base64'], true));
        $this->assertSame((int) $ctx->id, $opts['contextid']);
        $this->assertSame((int) $rec->userid, $opts['userid']);

        $usage = $DB->get_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'slide_vision']);
        $this->assertCount(1, $usage);
        $this->assertSame(3, (int) reset($usage)->imagecount);
        $this->assertSame((int) $rec->userid, (int) reset($usage)->userid);
    }

    /**
     * No vision route, a failing call, or the rate limit each leave the note empty and the text intact.
     *
     * @return void
     */
    public function test_design_pass_is_best_effort(): void {
        [$rec, $instance, $ctx, $course] = $this->deck_attempt(1);

        $out = slide_context::build($rec, $instance, $ctx, $course);
        $this->assertSame('', $out['visionnote']);
        $this->assertSame(3, $out['count']);

        route_resolver::set_test_client(
            route_resolver::PURPOSE_SLIDE_VISION,
            scorer_test::fake_client([new \moodle_exception('error')])
        );
        $this->assertSame('', slide_context::build($rec, $instance, $ctx, $course)['visionnote']);

        $client = scorer_test::fake_client(['A note.']);
        route_resolver::set_test_client(route_resolver::PURPOSE_SLIDE_VISION, $client);
        for ($i = 0; $i < rate_limiter::VISION_MAX + 1; $i++) {
            rate_limiter::hit('slide_vision', (int) $rec->userid, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW);
        }
        $this->assertSame('', slide_context::build($rec, $instance, $ctx, $course)['visionnote']);
        $this->assertCount(0, $client->calls, 'A rate limited learner was sent to the model.');
    }
}
