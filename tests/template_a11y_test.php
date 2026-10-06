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

/**
 * The accessibility rules of design section 11 that a scan can hold.
 *
 * The defect behind these is SOLA commit 2f0b1c48: an explanation put in a
 * title attribute that most screen readers never announced. Rule 10 asks for a
 * guard test, and its own history names the false positive to avoid: the
 * first version matched the bare words and failed on a comment three lines
 * above the markup, so the class checks here match a class attribute, not a
 * word.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class template_a11y_test extends \advanced_testcase {
    /**
     * Every template this plugin ships, with its documentation comments stripped.
     *
     * @return array filename => markup
     */
    private function templates(): array {
        global $CFG;

        $out = [];
        foreach (glob($CFG->dirroot . '/mod/presenterai/templates/*.mustache') as $path) {
            $out[basename($path)] = $this->strip_comments(file_get_contents($path));
        }
        $this->assertNotEmpty($out);

        return $out;
    }

    /**
     * Remove mustache comments, which may legitimately name what they forbid.
     *
     * @param string $source A template.
     * @return string
     */
    private function strip_comments(string $source): string {
        // One line comments first, then blocks, which close on a line of their own.
        $source = preg_replace('/\{\{![^\n]*?\}\}/', '', $source);

        return preg_replace('/\{\{!.*?\n\}\}/s', '', $source);
    }

    /**
     * No title attribute carries text from the context or a string (rule 1).
     *
     * @return void
     */
    public function test_no_strings_in_title_attributes(): void {
        foreach ($this->templates() as $name => $markup) {
            $this->assertDoesNotMatchRegularExpression(
                '/\btitle\s*=\s*"[^"]*\{\{/i',
                $markup,
                "{$name} puts a placeholder or string in a title attribute"
            );
            $this->assertDoesNotMatchRegularExpression(
                "/\\btitle\\s*=\\s*'[^']*\\{\\{/i",
                $markup,
                "{$name} puts a placeholder or string in a title attribute"
            );
        }
    }

    /**
     * Hidden text uses accesshide, never either Bootstrap name (rule 2).
     *
     * @return void
     */
    public function test_accesshide_not_bootstrap_names(): void {
        foreach ($this->templates() as $name => $markup) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bclass\s*=\s*"[^"]*\b(sr-only|visually-hidden)\b[^"]*"/i',
                $markup,
                "{$name} uses a Bootstrap screen reader class instead of accesshide"
            );
        }
    }

    /**
     * The scan itself matches a class attribute and not a bare word.
     *
     * @return void
     */
    public function test_scan_ignores_comments_and_bare_words(): void {
        $comment = "{{! Use accesshide, never sr-only or visually-hidden. }}\n<p>sr-only is a word here</p>";
        $this->assertDoesNotMatchRegularExpression(
            '/\bclass\s*=\s*"[^"]*\b(sr-only|visually-hidden)\b[^"]*"/i',
            $this->strip_comments($comment)
        );
        $this->assertMatchesRegularExpression(
            '/\bclass\s*=\s*"[^"]*\b(sr-only|visually-hidden)\b[^"]*"/i',
            '<span class="small sr-only">x</span>'
        );
    }

    /**
     * Every template has an example context that is valid JSON.
     *
     * Mustache lint renders each template with it, so an invalid one hides
     * the template from the only check that renders it.
     *
     * @return void
     */
    public function test_example_contexts_are_json(): void {
        global $CFG;

        foreach (glob($CFG->dirroot . '/mod/presenterai/templates/*.mustache') as $path) {
            $source = file_get_contents($path);
            $name = basename($path, '.mustache');
            $this->assertStringContainsString('@template mod_presenterai/' . $name, $source, $name);
            $this->assertSame(1, preg_match('/Example context \(json\):\s*(\{.*?)\n\}\}/s', $source, $m), $name);
            $this->assertIsArray(json_decode($m[1], true), "{$name} example context is not valid JSON");
        }
    }

    /**
     * The table points at the download-off note when it renders (rule 7), and every state cell has text (rule 6).
     *
     * @return void
     */
    public function test_attempts_table_describedby_and_state_cells(): void {
        global $PAGE;

        $this->resetAfterTest();
        $output = $PAGE->get_renderer('core');

        $row = [
            'recid' => 7, 'attempt' => '1', 'recorded' => '6/10/26, 14:00', 'length' => '6:12',
            'status' => 'Submitted', 'statekey' => 'attempt_kept', 'state' => 'Kept until deleted',
            'mediaavailable' => true, 'canwatch' => true, 'watcharia' => 'Watch the recording you made on 6/10/26, 14:00',
            'candownload' => false, 'downloadurl' => '', 'downloadaria' => 'Download the recording you made on 6/10/26, 14:00',
            'candelete' => false, 'deletearia' => 'Delete the recording you made on 6/10/26, 14:00', 'gonenote' => '',
        ];

        $html = $output->render_from_template('mod_presenterai/attempts', [
            'hasattempts' => true,
            'showdownloadoffnote' => true,
            'rows' => [$row],
        ]);
        $this->assertSame(1, preg_match('/<table[^>]*aria-describedby="([^"]+)"/', $html, $m));
        $this->assertMatchesRegularExpression('/<p[^>]*id="' . preg_quote($m[1], '/') . '"/', $html);
        $this->assertStringContainsString(get_string('download_off_note', 'mod_presenterai'), $html);
        $this->assertSame(1, preg_match('/<td data-region="state"[^>]*>([^<]*)<\/td>/', $html, $cell));
        $this->assertNotSame('', trim($cell[1]));
        // The Watch control names its row.
        $this->assertStringContainsString('aria-label="Watch the recording you made on 6/10/26, 14:00"', $html);

        $html = $output->render_from_template('mod_presenterai/attempts', [
            'hasattempts' => true,
            'showdownloadoffnote' => false,
            'rows' => [$row],
        ]);
        $this->assertStringNotContainsString('aria-describedby', $html);
        $this->assertStringNotContainsString(get_string('download_off_note', 'mod_presenterai'), $html);
    }

    /**
     * The callout is a heading and a paragraph, with no note or alert role (rules 4 and 5).
     *
     * @return void
     */
    public function test_callout_markup(): void {
        global $PAGE;

        $this->resetAfterTest();
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/policy_callout', [
            'variant' => 'keep_dl',
            'heading' => 'Your recording is kept until it is deleted.',
            'body' => 'Nothing deletes this recording automatically.',
            'prunewarning' => '',
        ]);

        $this->assertMatchesRegularExpression('/<h4[^>]*>Your recording is kept until it is deleted\.<\/h4>/', $html);
        $this->assertStringNotContainsString('role=', $html);
        $this->assertStringNotContainsString('title=', $html);
    }

    /**
     * The live region is in the page at load, so later announcements are spoken (rule 8).
     *
     * @return void
     */
    public function test_live_region_present_at_load(): void {
        $markup = $this->templates()['view.mustache'];

        $this->assertMatchesRegularExpression('/<div[^>]*aria-live="polite"[^>]*data-region="live"/', $markup);
        // Not inside a section that might not render.
        $before = substr($markup, 0, strpos($markup, 'aria-live="polite"'));
        $this->assertSame(
            substr_count($before, '{{#') + substr_count($before, '{{^'),
            substr_count($before, '{{/'),
            'the live region must not sit inside a conditional section'
        );
    }
}
