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
 * Rasterises a learner's PDF slide deck to one PNG per page with Ghostscript.
 *
 * A port of SOLA's soapbox_deck_renderer (classes/soapbox_deck_renderer.php),
 * keeping its command line (110 DPI, 60 page cap, -dSAFER) and using Moodle's
 * own $CFG->pathtogs, the binary assignfeedback_editpdf already relies on, so a
 * site needs nothing new installed.
 *
 * Three things are added, because the input is a file a learner chose:
 *
 * - The first five bytes must be "%PDF-". The extension and the MIME type the
 *   browser sent are claims; the magic bytes are what Ghostscript will act on.
 * - Ghostscript runs under a timeout and is killed when it expires. A hostile
 *   or merely broken PDF can keep it busy indefinitely, and SOLA's exec() would
 *   hold the web request, and a PHP worker, for as long as it liked.
 * - The process is started with an argument vector rather than a shell string,
 *   so there is no shell to interpret anything and the process the timeout
 *   kills is Ghostscript itself, not a /bin/sh wrapped around it.
 *
 * Text extraction (SOLA's extract_text) is not ported here: it feeds slide
 * aware scoring, which is phase 3.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deck_renderer {
    /** @var int Hard cap on rendered pages, which bounds both the work and the response size. */
    public const MAX_PAGES = 60;

    /** @var int Render resolution in DPI. */
    public const DPI = 110;

    /** @var int Seconds Ghostscript may run before it is killed. */
    public const TIMEOUT = 120;

    /** @var string The bytes every PDF starts with. */
    private const PDF_MAGIC = '%PDF-';

    /**
     * Whether Ghostscript is configured and executable.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        $gs = (string) ($CFG->pathtogs ?? '');
        return $gs !== '' && file_is_executable($gs);
    }

    /**
     * The Ghostscript command line, as a shell would see it.
     *
     * render() does not run this string; it runs the same arguments as a
     * vector (see argv()). This exists so a test can pin the flags, -dSAFER
     * above all, and so a log or an admin can see exactly what is run.
     *
     * @param string $gs Path to the Ghostscript binary.
     * @param string $pdfpath Path to the PDF.
     * @param string $pattern Output pattern containing %d for the page number.
     * @param int $maxpages The last page to render, clamped to [1, MAX_PAGES].
     * @return string
     */
    public static function build_command(string $gs, string $pdfpath, string $pattern, int $maxpages): string {
        return escapeshellarg($gs)
            . ' ' . implode(' ', self::flags($maxpages))
            . ' -sOutputFile=' . escapeshellarg($pattern)
            . ' ' . escapeshellarg($pdfpath);
    }

    /**
     * Render a PDF to page images.
     *
     * Reads $pdfpath and never writes to it: fs_store may hand over the path of
     * Moodle's own copy in the file directory, which is shared by every file
     * with the same content hash.
     *
     * @param string $pdfpath Local path to the PDF.
     * @param int $maxpages Pages to render, clamped to [1, MAX_PAGES].
     * @return string[] Ordered PNG paths in a per request directory that Moodle removes at the end of
     *                  the request. Empty on any failure, including when Ghostscript is unavailable.
     */
    public static function render(string $pdfpath, int $maxpages = self::MAX_PAGES): array {
        global $CFG;

        if (!self::is_available() || !self::looks_like_pdf($pdfpath)) {
            return [];
        }
        $maxpages = self::clamp_pages($maxpages);

        $outdir = make_request_directory();
        $pattern = $outdir . '/page-%d.png';

        $argv = array_merge(
            [(string) $CFG->pathtogs],
            self::flags($maxpages),
            ['-sOutputFile=' . $pattern, $pdfpath]
        );
        if (!self::run($argv, self::TIMEOUT)) {
            return [];
        }

        // Pages in numeric order. The first missing page ends the deck, so a
        // render that stopped part way returns the pages before the gap
        // rather than a list with a hole in it.
        $pages = [];
        for ($i = 1; $i <= $maxpages; $i++) {
            $file = $outdir . '/page-' . $i . '.png';
            if (!is_file($file) || filesize($file) === 0) {
                break;
            }
            $pages[] = $file;
        }
        return $pages;
    }

    /**
     * Render a PDF to page images encoded as data URIs.
     *
     * The phase 1 transport for the slide viewer, and a costly one: every call
     * re-runs Ghostscript and returns megabytes of base64 in a JSON response.
     * Phase 5 replaces it with a cached deckpage file area served as
     * pluginfile URLs (plan section 8).
     *
     * @param string $pdfpath Local path to the PDF.
     * @param int $maxpages Pages to render, clamped to [1, MAX_PAGES].
     * @return string[] One "data:image/png;base64,..." string per page, in order.
     */
    public static function render_to_datauris(string $pdfpath, int $maxpages = self::MAX_PAGES): array {
        $uris = [];
        foreach (self::render($pdfpath, $maxpages) as $file) {
            $data = file_get_contents($file);
            if ($data === false) {
                break;
            }
            $uris[] = 'data:image/png;base64,' . base64_encode($data);
        }
        return $uris;
    }

    /**
     * The Ghostscript flags, in order, between the binary and the output file.
     *
     * @param int $maxpages The last page to render.
     * @return string[]
     */
    private static function flags(int $maxpages): array {
        return [
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            // Restricts file access from inside the PDF to the input and output.
            // The default since Ghostscript 9.50, stated anyway because a site may
            // run something older.
            '-dSAFER',
            '-sDEVICE=png16m',
            '-r' . self::DPI,
            '-dTextAlphaBits=4',
            '-dGraphicsAlphaBits=4',
            '-dFirstPage=1',
            '-dLastPage=' . self::clamp_pages($maxpages),
        ];
    }

    /**
     * Clamp a page count to [1, MAX_PAGES].
     *
     * @param int $maxpages The requested count.
     * @return int
     */
    private static function clamp_pages(int $maxpages): int {
        return max(1, min($maxpages, self::MAX_PAGES));
    }

    /**
     * Whether a file is readable and starts with the PDF magic bytes.
     *
     * A path starting with "-" is refused too, since Ghostscript would read it
     * as an option. Moodle never produces one; this is belt and braces.
     *
     * @param string $path The file to check.
     * @return bool
     */
    private static function looks_like_pdf(string $path): bool {
        if ($path === '' || $path[0] === '-' || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, strlen(self::PDF_MAGIC));
        fclose($handle);
        return $head === self::PDF_MAGIC;
    }

    /**
     * Run a command with a timeout, discarding its output.
     *
     * The pipes are drained while the process runs, because a process that
     * fills a pipe nobody reads blocks forever, and the timeout would then
     * report a hang that was ours. Failures are logged without the file path,
     * which can name a learner's upload.
     *
     * @param string[] $argv The binary and its arguments. Run without a shell.
     * @param int $timeout Seconds before the process is killed.
     * @return bool True when the process exited with status 0 in time.
     */
    private static function run(array $argv, int $timeout): bool {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            debugging('mod_presenterai deck_renderer: Ghostscript could not be started.', DEBUG_DEVELOPER);
            return false;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeout;
        $exitcode = -1;
        $timedout = false;
        while (true) {
            // Read before checking the status, so output written just before the
            // process exited is not left in a pipe that is about to be closed.
            fread($pipes[1], 65536);
            fread($pipes[2], 65536);

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Only the first call after exit reports the real code; proc_close()
                // would return -1 afterwards.
                $exitcode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedout = true;
                // SIGKILL rather than SIGTERM: a Ghostscript stuck in a loop is
                // not owed a chance to tidy up. 9 is SIGKILL on every POSIX
                // system, and Windows ignores the signal and terminates anyway.
                proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedout) {
            debugging('mod_presenterai deck_renderer: Ghostscript was stopped after ' . $timeout .
                ' seconds rendering a slide deck.', DEBUG_DEVELOPER);
            return false;
        }
        if ($exitcode !== 0) {
            debugging('mod_presenterai deck_renderer: Ghostscript exited with status ' . $exitcode .
                ' rendering a slide deck.', DEBUG_DEVELOPER);
            return false;
        }
        return true;
    }
}
