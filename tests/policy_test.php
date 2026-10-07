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

use mod_presenterai\output\policy;

/**
 * What a learner is told before recording: the callout and the privacy paragraph.
 *
 * The callout's four variants, its two conditional additions, and the clause
 * order and gates of the privacy paragraph, each against the exact strings of
 * design sections 9.3 and 9.4.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\policy
 */
final class policy_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A student enrolled in the course. */
    private \stdClass $student;

    /**
     * Skip until the server slice's retention and access classes are present.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\\mod_presenterai\\local\\retention') || !class_exists('\\mod_presenterai\\local\\access')) {
            $this->markTestSkipped('needs the server slice');
        }
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * An instance and its context.
     *
     * @param array $fields Instance overrides.
     * @return array [instance, context]
     */
    private function instance(array $fields = []): array {
        $instance = $this->getDataGenerator()->create_module(
            'presenterai',
            array_merge(['course' => $this->course->id], $fields)
        );

        return [$instance, \context_module::instance($instance->cmid)];
    }

    /**
     * The site name as {$a} carries it.
     *
     * @return string
     */
    private function sitename(): string {
        global $SITE;

        return format_string($SITE->fullname, true, ['context' => \context_system::instance()]);
    }

    /**
     * The four variants, each with its exact heading and body.
     *
     * @return array
     */
    public static function variant_provider(): array {
        return [
            'keep, download on' => [0, 1, 'keep_dl'],
            'keep, download off' => [0, 0, 'keep_nodl'],
            'delete, download on' => [14, 1, 'delete_dl'],
            'delete, download off' => [14, 0, 'delete_nodl'],
        ];
    }

    /**
     * Each combination of retention and download picks its own pair of strings.
     *
     * @dataProvider variant_provider
     * @param int $days Site retention days.
     * @param int $download Site allowlearnerdownload.
     * @param string $variant The expected variant.
     * @return void
     */
    public function test_callout_variants(int $days, int $download, string $variant): void {
        set_config('retentiondays', $days, 'mod_presenterai');
        set_config('allowlearnerdownload', $download, 'mod_presenterai');
        set_config('supportemail', '');
        set_config('supportpage', '');
        [$instance, $context] = $this->instance();

        $callout = policy::callout($instance, $context, (int) $this->student->id);

        $a = $days > 0 ? $days : $this->sitename();
        $this->assertSame($variant, $callout['variant']);
        $this->assertSame(get_string('record_' . $variant . '_heading', 'mod_presenterai', $days), $callout['heading']);
        $this->assertSame(get_string('record_' . $variant . '_body', 'mod_presenterai', $a), $callout['body']);
        $this->assertSame('', $callout['prunewarning']);
    }

    /**
     * The support contact is appended to a no-download body only when the site has one.
     *
     * @return void
     */
    public function test_nodl_contact_only_with_a_support_contact(): void {
        set_config('retentiondays', 14, 'mod_presenterai');
        set_config('allowlearnerdownload', 0, 'mod_presenterai');
        [$instance, $context] = $this->instance();

        set_config('supportemail', '');
        set_config('supportpage', '');
        $without = policy::callout($instance, $context, (int) $this->student->id);
        $this->assertStringNotContainsString('mailto:', $without['body']);
        $this->assertSame(get_string('record_delete_nodl_body', 'mod_presenterai', 14), $without['body']);

        set_config('supportemail', 'help@example.com');
        $with = policy::callout($instance, $context, (int) $this->student->id);
        $this->assertStringContainsString('mailto:help@example.com', $with['body']);
        $this->assertStringStartsWith(get_string('record_delete_nodl_body', 'mod_presenterai', 14) . ' ', $with['body']);

        $contact = policy::support_link();
        $this->assertStringEndsWith(
            ' ' . get_string('record_nodl_contact_delete', 'mod_presenterai', $contact),
            $with['body']
        );

        // On a keep site there is no deletion date, so the sentence must not
        // mention one: it is the keep sentence.
        set_config('retentiondays', 0, 'mod_presenterai');
        $keep = policy::callout($instance, $context, (int) $this->student->id);
        $this->assertSame('keep_nodl', $keep['variant']);
        $this->assertStringEndsWith(
            ' ' . get_string('record_nodl_contact_keep', 'mod_presenterai', $contact),
            $keep['body']
        );
        set_config('retentiondays', 14, 'mod_presenterai');

        // Never on a download-on variant, where there is nothing to ask for.
        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        $dl = policy::callout($instance, $context, (int) $this->student->id);
        $this->assertStringNotContainsString('mailto:', $dl['body']);
    }

    /**
     * The pruning warning appears only at the cap, and only when pruning is on.
     *
     * @return void
     */
    public function test_prune_warning_only_at_the_cap(): void {
        global $DB;

        set_config('retentiondays', 0, 'mod_presenterai');
        [$instance, $context] = $this->instance(['storedattempts' => 2]);
        $userid = (int) $this->student->id;
        $base = time() - 10 * DAYSECS;

        $insert = function (int $timecreated, ?string $key, string $status = 'uploaded') use ($DB, $instance, $userid): void {
            $DB->insert_record('presenterai_recording', (object) [
                'presenteraiid' => $instance->id,
                'userid' => $userid,
                'attemptnumber' => 1,
                'mode' => 'video',
                'backend' => 'fs',
                'storagekey' => $key,
                'status' => $status,
                'timecreated' => $timecreated,
                'timemodified' => $timecreated,
            ]);
        };

        $insert($base, 'first.webm');
        // Rows without media, and rows still uploading, do not count.
        $insert($base - DAYSECS, null);
        $insert($base + DAYSECS, 'minted.webm', 'uploading');
        $this->assertSame('', policy::callout($instance, $context, $userid)['prunewarning']);

        $insert($base + 2 * DAYSECS, 'second.webm');
        $expected = get_string(
            'record_prune_warning',
            'mod_presenterai',
            userdate($base, get_string('strftimedate', 'langconfig'))
        );
        $this->assertSame($expected, policy::callout($instance, $context, $userid)['prunewarning']);

        // The same learner, the same rows, pruning off: no warning.
        $DB->set_field('presenterai', 'storedattempts', 0, ['id' => $instance->id]);
        $instance->storedattempts = 0;
        $this->assertSame('', policy::callout($instance, $context, $userid)['prunewarning']);
    }

    /**
     * Clauses in the stated order, joined by single spaces.
     *
     * @return void
     */
    public function test_privacy_clause_order_with_frames(): void {
        set_config('retentiondays', 14, 'mod_presenterai');
        set_config('allowlearnerdownload', 1, 'mod_presenterai');
        set_config('visualdatadays', 21, 'mod_presenterai');
        [$instance] = $this->instance(['videovision' => 1, 'mode' => 'video']);

        $expected = implode(' ', [
            get_string('privacy_stem', 'mod_presenterai', $this->sitename()),
            get_string('privacy_delete', 'mod_presenterai', 14),
            get_string('privacy_frames_delete', 'mod_presenterai'),
            get_string('privacy_visualnote', 'mod_presenterai', 21),
            get_string('privacy_kept_after', 'mod_presenterai'),
            get_string('privacy_download_on', 'mod_presenterai'),
        ]);
        $this->assertSame($expected, policy::privacy_paragraph($instance, true));
    }

    /**
     * Keep variant, download off, frames kept, and the visual note default of 30.
     *
     * @return void
     */
    public function test_privacy_keep_and_download_off(): void {
        set_config('retentiondays', 0, 'mod_presenterai');
        unset_config('visualdatadays', 'mod_presenterai');
        [$instance] = $this->instance(['videovision' => 1, 'mode' => 'video']);

        $expected = implode(' ', [
            get_string('privacy_stem', 'mod_presenterai', $this->sitename()),
            get_string('privacy_keep', 'mod_presenterai'),
            get_string('privacy_frames_keep', 'mod_presenterai'),
            get_string('privacy_visualnote', 'mod_presenterai', 30),
            get_string('privacy_kept_after', 'mod_presenterai'),
            get_string('privacy_download_off', 'mod_presenterai'),
        ]);
        $this->assertSame($expected, policy::privacy_paragraph($instance, false));
    }

    /**
     * No frames clause and no visual note unless frames are really taken.
     *
     * @return void
     */
    public function test_privacy_frames_gate(): void {
        set_config('retentiondays', 0, 'mod_presenterai');
        $frames = [
            get_string('privacy_frames_keep', 'mod_presenterai'),
            get_string('privacy_frames_delete', 'mod_presenterai'),
        ];

        foreach ([['videovision' => 0, 'mode' => 'video'], ['videovision' => 1, 'mode' => 'audio']] as $fields) {
            [$instance] = $this->instance($fields);
            $paragraph = policy::privacy_paragraph($instance, true);
            foreach ($frames as $clause) {
                $this->assertStringNotContainsString($clause, $paragraph);
            }
            $this->assertStringNotContainsString('frames is deleted after', $paragraph);
            $this->assertFalse(policy::frames_taken($instance));
        }
    }

    /**
     * The visual note clause promises what the expiry task does: zero or less is one day, never the default or forever.
     *
     * @return void
     */
    public function test_privacy_visualnote_minimum_is_one_day(): void {
        set_config('retentiondays', 0, 'mod_presenterai');
        set_config('visualdatadays', 0, 'mod_presenterai');
        [$instance] = $this->instance(['videovision' => 1, 'mode' => 'video']);

        $paragraph = policy::privacy_paragraph($instance, true);

        $this->assertStringContainsString(get_string('privacy_visualnote', 'mod_presenterai', 1), $paragraph);
    }
}
