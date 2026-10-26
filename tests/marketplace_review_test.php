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

use mod_presenterai\external\get_playback;
use mod_presenterai\local\media_purger;

/**
 * What the plugin directory review (Volodymyr's issues 12 to 17 and 20) found, pinned so it stays fixed.
 *
 * The static checks read the plugin's own files, so a new offender fails here before a reviewer finds it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\media_purger
 * @covers     \mod_presenterai\external\get_playback
 */
final class marketplace_review_test extends \advanced_testcase {
    /**
     * Every PHP file of the plugin outside tests/, as path => contents.
     *
     * @return string[]
     */
    private function plugin_sources(): array {
        global $CFG;
        $root = $CFG->dirroot . '/mod/presenterai';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (
                $file->getExtension() !== 'php'
                || str_starts_with($relative, 'tests/')
                || str_starts_with($relative, 'node_modules/')
                || str_starts_with($relative, '.')
            ) {
                continue;
            }
            $files[$relative] = (string) file_get_contents($path);
        }
        $this->assertNotEmpty($files);
        return $files;
    }

    /**
     * Issue 12: the plugin root carries the licence text.
     *
     * @return void
     */
    public function test_the_plugin_root_has_a_gpl_licence_file(): void {
        global $CFG;
        $path = $CFG->dirroot . '/mod/presenterai/LICENSE';
        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);
        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $text);
        $this->assertStringContainsString('Version 3, 29 June 2007', $text);
        $this->assertGreaterThan(30000, strlen($text), 'the full licence, not a stub');
    }

    /**
     * Issue 20: the release ZIP leaves out the assistant planning files and the Claude workflows.
     *
     * A release ZIP is built with git archive, which skips whatever .gitattributes marks export-ignore. The file
     * is not in the ZIP itself, so a site installed from one has nothing to check and the test skips.
     *
     * @return void
     */
    public function test_the_release_zip_leaves_out_the_assistant_files(): void {
        global $CFG;
        $path = $CFG->dirroot . '/mod/presenterai/.gitattributes';
        if (!file_exists($path)) {
            $this->markTestSkipped('Installed without .gitattributes, as from a release ZIP.');
        }
        $rules = (string) file_get_contents($path);
        foreach (['/.github', '/docs/superpowers'] as $excluded) {
            $this->assertMatchesRegularExpression('~^' . preg_quote($excluded, '~') . '\s+export-ignore\s*$~m', $rules, $excluded);
        }
    }

    /**
     * Issue 17: nothing in the plugin names PHP's own curl functions, comments included.
     *
     * Moodle's \curl class is what applies the site's proxy and SSRF rules, and a reviewer's scan matches the
     * function names wherever they appear.
     *
     * @return void
     */
    public function test_no_file_mentions_php_curl_functions(): void {
        foreach ($this->plugin_sources() as $relative => $source) {
            $this->assertDoesNotMatchRegularExpression('~\bcurl_(init|exec|setopt|multi_init)\b~', $source, $relative);
        }
    }

    /**
     * Issue 15: specific DML calls only, never $DB->execute().
     *
     * @return void
     */
    public function test_no_file_calls_db_execute(): void {
        foreach ($this->plugin_sources() as $relative => $source) {
            $this->assertStringNotContainsString('$DB->execute(', $source, $relative);
        }
    }

    /**
     * Issue 14: a request parameter or return value is never typed with the unchecked PARAM type.
     *
     * The trimmed variant for admin settings (keys, model ids) is a different constant and stays.
     *
     * @return void
     */
    public function test_no_file_uses_the_unchecked_param_type(): void {
        foreach ($this->plugin_sources() as $relative => $source) {
            $this->assertDoesNotMatchRegularExpression('~\bPARAM_RAW\b(?!_)~', $source, $relative);
        }
    }

    /**
     * Issue 13: every page script that logs a user in then asks for a capability, and so does pluginfile.
     *
     * @return void
     */
    public function test_page_scripts_check_a_capability_after_login(): void {
        $sources = $this->plugin_sources();
        foreach (['download.php', 'view.php', 'report.php', 'grade.php', 'rubric.php', 'upload.php'] as $script) {
            $this->assertArrayHasKey($script, $sources);
            $login = strpos($sources[$script], 'require_login(');
            $this->assertNotFalse($login, $script . ' logs the user in');
            $this->assertNotFalse(
                strpos($sources[$script], 'require_capability(', $login),
                $script . ' checks a capability after logging in'
            );
        }
        $this->assertStringContainsString(
            "require_login(\$course, false, \$cm);\n    require_capability('mod/presenterai:view', \$context);",
            $sources['lib.php'],
            'pluginfile checks the view capability before it looks at a recording'
        );
    }

    /**
     * Issue 13: a user who cannot view the activity cannot fetch a recording's media, whatever the row says.
     *
     * @return void
     */
    public function test_pluginfile_refuses_a_user_without_the_view_capability(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/presenterai/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $alice->id,
            'storagekey' => 'recording.webm',
        ]);
        $context = \context_module::instance($instance->cmid);
        $cm = get_coursemodule_from_id('presenterai', $instance->cmid, 0, false, MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability('mod/presenterai:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        $this->setUser($alice);

        // Moodle's own login check already turns the user away once the capability is withdrawn (the module hides
        // the activity from them); the explicit check after it is the backstop. Either refusal is a moodle_exception.
        $this->expectException(\moodle_exception::class);
        mod_presenterai_pluginfile($course, $cm, $context, 'recording', [(int) $rec->id, 'recording.webm'], false);
    }

    /**
     * Issue 13: the playback web service asks for the view capability before it decides anything about the row.
     *
     * @return void
     */
    public function test_get_playback_refuses_a_user_without_the_view_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $alice->id,
            'storagekey' => 'recording.webm',
        ]);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability(
            'mod/presenterai:view',
            CAP_PROHIBIT,
            $studentrole->id,
            \context_module::instance($instance->cmid)->id,
            true
        );
        $this->setUser($alice);

        // See the pluginfile test: the login check refuses first, and the explicit check is the backstop.
        $this->expectException(\moodle_exception::class);
        $this->assertSame([], get_playback::execute((int) $rec->id));
    }

    /**
     * Issue 15: anonymising spend rows detaches every one of them, in more than one batch, and deletes none.
     *
     * @return void
     */
    public function test_anonymising_spend_rows_covers_every_row_across_batches(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rows = [];
        for ($i = 0; $i < 1201; $i++) {
            $rows[] = (object) [
                'presenteraiid' => $instance->id,
                'recordingid' => 0,
                'userid' => $alice->id,
                'action' => 'deck',
                'estmicrocents' => 7,
                'timecreated' => time(),
            ];
        }
        $rows[] = (object) [
            'presenteraiid' => $other->id,
            'recordingid' => 0,
            'userid' => $alice->id,
            'action' => 'deck',
            'estmicrocents' => 7,
            'timecreated' => time(),
        ];
        $DB->insert_records('presenterai_aiusage', $rows);

        media_purger::purge_instance((int) $instance->id, media_purger::AIUSAGE_ANONYMISE);

        $this->assertSame(1201, $DB->count_records('presenterai_aiusage', ['presenteraiid' => $instance->id, 'userid' => 0]));
        $this->assertSame(0, $DB->count_records('presenterai_aiusage', ['presenteraiid' => $instance->id, 'userid' => $alice->id]));
        $this->assertSame(1201 * 7, (int) $DB->get_field_sql(
            'SELECT SUM(estmicrocents) FROM {presenterai_aiusage} WHERE presenteraiid = ?',
            [$instance->id]
        ), 'the spend still adds up');
        $this->assertSame(
            1,
            $DB->count_records('presenterai_aiusage', ['presenteraiid' => $other->id, 'userid' => $alice->id]),
            'another activity is untouched'
        );
    }
}
