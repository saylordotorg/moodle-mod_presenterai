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

use core_external\external_api;
use mod_presenterai\external\begin_attempt;
use mod_presenterai\external\render_deck;
use mod_presenterai\external\start_upload;
use mod_presenterai\local\deck_renderer;
use mod_presenterai\local\storage\fs_store;

/**
 * The web service that renders a learner's deck for the recorder.
 *
 * Nothing here depends on Ghostscript being installed. The one assertion that
 * needs a real render skips itself on a machine without it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\external\render_deck
 */
final class external_render_deck_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \stdClass The learner who owns the attempt. */
    private \stdClass $alice;

    /**
     * One course, one slides-enabled activity, one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'slidesenabled', 1, ['id' => $this->instance->id]);
        $this->alice = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * A one page PDF, small and valid, with a correct cross reference table.
     *
     * @return string
     */
    private function minimal_pdf(): string {
        $objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] >>",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * Begin an attempt as Alice and upload a deck into it.
     *
     * @return int The recording id.
     */
    private function attempt_with_deck(): int {
        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);
        $pdf = $this->minimal_pdf();
        $target = start_upload::execute($begin['recordingid'], 'deck', 'pdf', strlen($pdf));
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $pdf);
        rewind($stream);
        (new fs_store())->accept_chunk($target['uploadid'], 0, $stream);

        return (int) $begin['recordingid'];
    }

    /**
     * An attempt with no deck is told so.
     *
     * @return void
     */
    public function test_no_deck_is_an_error(): void {
        $this->setUser($this->alice);
        $begin = begin_attempt::execute((int) $this->instance->cmid);

        try {
            render_deck::execute($begin['recordingid']);
            $this->fail('An attempt with no deck rendered something.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:nodeck', $e->errorcode);
        }
    }

    /**
     * Another learner cannot render somebody else's deck.
     *
     * @return void
     */
    public function test_another_learner_is_refused(): void {
        $recordingid = $this->attempt_with_deck();
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        try {
            render_deck::execute($recordingid);
            $this->fail('A learner was shown another learner\'s slides.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:recordingnotfound', $e->errorcode);
        }
    }

    /**
     * Without Ghostscript the answer is "not available", not an error.
     *
     * @return void
     */
    public function test_without_ghostscript_slides_are_unavailable(): void {
        global $CFG;

        $recordingid = $this->attempt_with_deck();
        $CFG->pathtogs = '/nonexistent/presenterai/gs';
        if (deck_renderer::is_available()) {
            $this->markTestSkipped('The renderer still reports itself available with pathtogs pointing nowhere.');
        }

        $result = external_api::clean_returnvalue(render_deck::execute_returns(), render_deck::execute($recordingid));
        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['pagecount']);
        $this->assertSame([], $result['pages']);
    }

    /**
     * With Ghostscript the deck renders to page images.
     *
     * @return void
     */
    public function test_with_ghostscript_the_deck_renders(): void {
        global $CFG;

        // Look in the usual places when the Moodle setting is not filled in, so
        // the test runs on a developer machine with Ghostscript installed.
        if (!deck_renderer::is_available()) {
            foreach (['/opt/homebrew/bin/gs', '/usr/local/bin/gs', '/usr/bin/gs'] as $candidate) {
                if (file_is_executable($candidate)) {
                    $CFG->pathtogs = $candidate;
                    break;
                }
            }
        }
        if (!deck_renderer::is_available()) {
            $this->markTestSkipped('Ghostscript is not available on this machine.');
        }
        $recordingid = $this->attempt_with_deck();

        $result = external_api::clean_returnvalue(render_deck::execute_returns(), render_deck::execute($recordingid));
        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['pagecount']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['pages'][0]);
    }
}
