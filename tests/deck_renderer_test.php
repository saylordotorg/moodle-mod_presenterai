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

use mod_presenterai\local\deck_renderer;

/**
 * The Ghostscript deck renderer.
 *
 * The command line tests run everywhere. The render tests need Ghostscript and
 * skip, saying so, on a machine without it; CI's runners and a stock macOS
 * Homebrew install both have it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\deck_renderer
 */
final class deck_renderer_test extends \advanced_testcase {
    /**
     * Path to a fixture file.
     *
     * @param string $name The fixture's file name.
     * @return string
     */
    private function fixture(string $name): string {
        return __DIR__ . '/fixtures/' . $name;
    }

    /**
     * Point $CFG->pathtogs at a Ghostscript binary, or skip the test.
     *
     * Uses the configured path when it works, otherwise looks in the usual
     * places, so the test runs on a developer machine that never set the
     * Moodle setting.
     *
     * @return void
     */
    private function require_ghostscript(): void {
        global $CFG;
        if (deck_renderer::is_available()) {
            return;
        }
        foreach (['/opt/homebrew/bin/gs', '/usr/local/bin/gs', '/usr/bin/gs'] as $candidate) {
            if (file_is_executable($candidate)) {
                $CFG->pathtogs = $candidate;
                return;
            }
        }
        $this->markTestSkipped('Ghostscript is not installed, so the deck cannot be rendered.');
    }

    /**
     * The command carries -dSAFER, 110 DPI and the page range, with every path escaped.
     *
     * @return void
     */
    public function test_build_command_flags(): void {
        $command = deck_renderer::build_command('/usr/bin/gs', '/tmp/deck.pdf', '/tmp/out/page-%d.png', 5);

        $this->assertStringStartsWith(escapeshellarg('/usr/bin/gs') . ' ', $command);
        $this->assertStringContainsString(' -dSAFER ', $command);
        $this->assertStringContainsString(' -dBATCH ', $command);
        $this->assertStringContainsString(' -dNOPAUSE ', $command);
        $this->assertStringContainsString(' -sDEVICE=png16m ', $command);
        $this->assertStringContainsString(' -r110 ', $command);
        $this->assertStringContainsString(' -dFirstPage=1 ', $command);
        $this->assertStringContainsString(' -dLastPage=5 ', $command);
        $this->assertStringContainsString(' -dFIXEDMEDIA ', $command);
        $this->assertStringContainsString(' -dPDFFitPage ', $command);
        $this->assertStringContainsString(' -sOutputFile=' . escapeshellarg('/tmp/out/page-%d.png') . ' ', $command);
        $this->assertStringEndsWith(' ' . escapeshellarg('/tmp/deck.pdf'), $command);
    }

    /**
     * The page count is capped at 60 however many are asked for, and is never below 1.
     *
     * @return void
     */
    public function test_build_command_caps_pages(): void {
        $this->assertStringContainsString(' -dLastPage=60 ', deck_renderer::build_command('gs', 'a.pdf', 'p-%d.png', 200));
        $this->assertStringContainsString(' -dLastPage=1 ', deck_renderer::build_command('gs', 'a.pdf', 'p-%d.png', 0));
    }

    /**
     * A path holding shell syntax arrives as one inert argument.
     *
     * @return void
     */
    public function test_build_command_escapes_paths(): void {
        $hostile = "/tmp/x'; rm -rf / #.pdf";
        $command = deck_renderer::build_command('/usr/bin/gs', $hostile, '/tmp/p-%d.png', 3);

        $this->assertStringEndsWith(' ' . escapeshellarg($hostile), $command);
        $this->assertStringNotContainsString(" /tmp/x'; rm", $command);
    }

    /**
     * A file that is not a PDF is refused before Ghostscript sees it, whatever its extension.
     *
     * @return void
     */
    public function test_render_refuses_non_pdf(): void {
        $this->resetAfterTest();
        $this->require_ghostscript();

        $this->assertSame([], deck_renderer::render($this->fixture('not-a-pdf.pdf')));
        $this->assertSame([], deck_renderer::render_to_datauris($this->fixture('not-a-pdf.pdf')));
    }

    /**
     * A missing file, or no Ghostscript, gives an empty list rather than an error.
     *
     * @return void
     */
    public function test_render_fails_quietly(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->assertSame([], deck_renderer::render($this->fixture('does-not-exist.pdf')));

        $CFG->pathtogs = '';
        $this->assertFalse(deck_renderer::is_available());
        $this->assertSame([], deck_renderer::render($this->fixture('deck-3pages.pdf')));
    }

    /**
     * A three page deck renders to three PNGs, in order, and the cap is honoured.
     *
     * @return void
     */
    public function test_render_three_pages(): void {
        $this->resetAfterTest();
        $this->require_ghostscript();

        $pages = deck_renderer::render($this->fixture('deck-3pages.pdf'));
        $this->assertCount(3, $pages);
        foreach ($pages as $index => $path) {
            $this->assertStringEndsWith('/page-' . ($index + 1) . '.png', $path);
            $this->assertSame("\x89PNG", substr((string) file_get_contents($path), 0, 4));
        }

        $this->assertCount(2, deck_renderer::render($this->fixture('deck-3pages.pdf'), 2));
    }

    /**
     * Every page is fitted into a fixed slide size, whatever MediaBox the PDF declares.
     *
     * A page declared about 70 inches square rendered at 110 DPI used to come
     * out over 7600 pixels a side, and a deck of such pages, base64 encoded
     * into one response, exhausts memory_limit.
     *
     * @return void
     */
    public function test_an_oversized_page_is_fitted_to_a_slide(): void {
        $this->resetAfterTest();
        $this->require_ghostscript();

        $path = make_request_directory() . '/huge.pdf';
        file_put_contents($path, $this->pdf_with_mediabox(5000, 5000));

        $pages = deck_renderer::render($path);
        $this->assertCount(1, $pages);
        [$width, $height] = getimagesize($pages[0]);
        $maxwidth = (int) ceil(deck_renderer::PAGE_WIDTH_PT / 72 * deck_renderer::DPI);
        $maxheight = (int) ceil(deck_renderer::PAGE_HEIGHT_PT / 72 * deck_renderer::DPI);
        $this->assertLessThanOrEqual($maxwidth, $width, 'The page was rendered at the size the PDF claimed.');
        $this->assertLessThanOrEqual($maxheight, $height, 'The page was rendered at the size the PDF claimed.');
    }

    /**
     * A one page PDF with the given MediaBox and a filled rectangle, xref and all.
     *
     * @param int $width Points.
     * @param int $height Points.
     * @return string
     */
    private function pdf_with_mediabox(int $width, int $height): string {
        $content = "0.2 0.4 0.8 rg 0 0 {$width} {$height} re f";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Contents 4 0 R >>",
            '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /**
     * Data URIs are PNG data URIs, one per page.
     *
     * @return void
     */
    public function test_render_to_datauris(): void {
        $this->resetAfterTest();
        $this->require_ghostscript();

        $uris = deck_renderer::render_to_datauris($this->fixture('deck-3pages.pdf'));
        $this->assertCount(3, $uris);
        foreach ($uris as $uri) {
            $this->assertStringStartsWith('data:image/png;base64,', $uri);
            $decoded = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
            $this->assertNotFalse($decoded);
            $this->assertSame("\x89PNG", substr($decoded, 0, 4));
        }
    }

    /**
     * Rendering reads the PDF and never writes to it.
     *
     * fs_store can hand over the path of Moodle's own copy in the file
     * directory, which every file with the same content shares.
     *
     * @return void
     */
    public function test_render_leaves_input_unchanged(): void {
        $this->resetAfterTest();
        $this->require_ghostscript();

        $path = $this->fixture('deck-3pages.pdf');
        $before = sha1_file($path);
        $mtime = filemtime($path);

        deck_renderer::render($path);

        clearstatcache();
        $this->assertSame($before, sha1_file($path));
        $this->assertSame($mtime, filemtime($path));
    }
}
