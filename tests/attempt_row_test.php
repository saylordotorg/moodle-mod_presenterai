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

use mod_presenterai\output\attempt_row;

/**
 * The recording state cell, row by row, against the table in design section 9.5.
 *
 * state() is pure, so every case here is an in-memory row: no database, no
 * other slice, nothing that can make the test pass for a reason other than the
 * table being right. The one case that needs the real database, a row read
 * back through export(), lives in retention_message_source_test.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\attempt_row
 */
final class attempt_row_test extends \advanced_testcase {
    /** @var int A fixed now, so the boundary cases mean exactly what they say. */
    private const NOW = 1790000000;

    /**
     * A recording row with defaults that describe a finished attempt with media.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function row(array $fields = []): \stdClass {
        return (object) array_merge([
            'id' => 1,
            'status' => 'uploaded',
            'storagekey' => 'abc123.webm',
            'expiresat' => 0,
            'mediadeletedat' => 0,
            'mediagonereason' => null,
            'attemptnumber' => 1,
            'durationseconds' => 61,
            'timecreated' => self::NOW - DAYSECS,
        ], $fields);
    }

    /**
     * The date format the attempt list uses.
     *
     * @param int $timestamp Unix time.
     * @return string
     */
    private function date(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'));
    }

    /**
     * Every row state in design 9.5, and every attempt_gone_* reason.
     *
     * @return array
     */
    public static function state_provider(): array {
        $now = self::NOW;
        $future = $now + 10 * DAYSECS;
        $past = $now - 3 * DAYSECS;

        return [
            'media, future date, cleanup on' => [
                ['expiresat' => $future], true, 'attempt_deletes_on', $future,
            ],
            'media, future date, cleanup off' => [
                ['expiresat' => $future], false, 'attempt_kept', null,
            ],
            'media, date passed' => [
                ['expiresat' => $past], true, 'attempt_deletes_due', null,
            ],
            'media, no date' => [
                ['expiresat' => 0], true, 'attempt_kept', null,
            ],
            'gone, retention' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'retention'], true,
                'attempt_deleted_on', $past,
            ],
            'gone, no reason recorded' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => ''], true,
                'attempt_deleted_on', $past,
            ],
            'gone, null reason' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => null], true,
                'attempt_deleted_on', $past,
            ],
            'gone, unknown reason' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'futurereason'], true,
                'attempt_deleted_on', $past,
            ],
            'gone, pruned' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'pruned'], true,
                'attempt_gone_pruned', $past,
            ],
            'gone, manual' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'manual'], true,
                'attempt_gone_manual', $past,
            ],
            'gone, learner' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'learner'], true,
                'attempt_gone_learner', $past,
            ],
            'gone, missing' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'missing'], true,
                'attempt_gone_missing', null,
            ],
            'gone, not backed up' => [
                ['storagekey' => null, 'mediadeletedat' => $past, 'mediagonereason' => 'notbackedup'], true,
                'attempt_gone_notbackedup', null,
            ],
            'gone, no deletion time (migrated)' => [
                ['storagekey' => null, 'mediadeletedat' => 0], true, 'attempt_deleted', null,
            ],
            'gone, empty key string' => [
                ['storagekey' => '', 'mediadeletedat' => 0], true, 'attempt_deleted', null,
            ],
            'still uploading' => [
                ['status' => 'uploading', 'storagekey' => null], true, 'attempt_never_uploaded', null,
            ],
            'uploading with a minted key' => [
                ['status' => 'uploading', 'storagekey' => 'minted.webm', 'expiresat' => $future], true,
                'attempt_never_uploaded', null,
            ],
            'abandoned' => [
                ['status' => 'abandoned', 'storagekey' => null], true, 'attempt_never_uploaded', null,
            ],
            'scored with media, cleanup on' => [
                ['status' => 'scored', 'expiresat' => $future], true, 'attempt_deletes_on', $future,
            ],
        ];
    }

    /**
     * Each row state selects the key and the date the table says it should.
     *
     * @dataProvider state_provider
     * @param array $fields Row overrides.
     * @param bool $cleanupenabled Whether the cleanup task is enabled.
     * @param string $key The expected lang key.
     * @param int|null $datefrom The timestamp whose date {$a} should be, or null for none.
     * @return void
     */
    public function test_state(array $fields, bool $cleanupenabled, string $key, ?int $datefrom): void {
        $this->resetAfterTest();

        $state = attempt_row::state($this->row($fields), self::NOW, $cleanupenabled);

        $this->assertSame($key, $state['key']);
        $this->assertSame($datefrom === null ? null : $this->date($datefrom), $state['a']);

        // Design 11, rule 6: the cell is never empty, in any state.
        $text = attempt_row::text($state);
        $this->assertNotSame('', trim($text));
        $this->assertTrue(get_string_manager()->string_exists($key, 'mod_presenterai'));
        if ($datefrom !== null) {
            $this->assertStringContainsString($this->date($datefrom), $text);
        }
    }

    /**
     * expiresat exactly now is due, one second later is still a date.
     *
     * @return void
     */
    public function test_due_boundary(): void {
        $this->resetAfterTest();

        $atnow = attempt_row::state($this->row(['expiresat' => self::NOW]), self::NOW, true);
        $this->assertSame('attempt_deletes_due', $atnow['key']);

        $later = attempt_row::state($this->row(['expiresat' => self::NOW + 1]), self::NOW, true);
        $this->assertSame('attempt_deletes_on', $later['key']);
    }

    /**
     * A disabled cleanup task suppresses every date it would not honour (design 8.7f).
     *
     * A row already past its date is also shown as kept, because "due to be
     * deleted" is as much a promise as a date when nothing is going to do it.
     *
     * @return void
     */
    public function test_disabled_cleanup_never_shows_a_date(): void {
        $this->resetAfterTest();

        foreach ([self::NOW + DAYSECS, self::NOW, self::NOW - DAYSECS] as $expiresat) {
            $state = attempt_row::state($this->row(['expiresat' => $expiresat]), self::NOW, false);
            $this->assertSame('attempt_kept', $state['key']);
            $this->assertNull($state['a']);
        }
    }

    /**
     * Every status has a learner facing label, and an unknown one is shown escaped.
     *
     * @return void
     */
    public function test_status_label(): void {
        $this->resetAfterTest();

        $this->assertSame('Submitted', attempt_row::status_label($this->row(['status' => 'uploaded'])));
        foreach (['uploading', 'abandoned', 'scoring', 'scored', 'failed'] as $status) {
            $this->assertSame(
                get_string('status_' . $status, 'mod_presenterai'),
                attempt_row::status_label($this->row(['status' => $status]))
            );
        }
        $this->assertSame('&lt;b&gt;', attempt_row::status_label($this->row(['status' => '<b>'])));
    }

    /**
     * Lengths read as minutes and seconds.
     *
     * @return void
     */
    public function test_duration(): void {
        $this->assertSame('0:00', attempt_row::duration(0));
        $this->assertSame('1:01', attempt_row::duration(61));
        $this->assertSame('7:05', attempt_row::duration(425));
        $this->assertSame('0:00', attempt_row::duration(-5));
    }

    /**
     * Media exists only on a non uploading row with a key.
     *
     * @return void
     */
    public function test_has_media(): void {
        $this->assertTrue(attempt_row::has_media($this->row()));
        $this->assertFalse(attempt_row::has_media($this->row(['storagekey' => null])));
        $this->assertFalse(attempt_row::has_media($this->row(['storagekey' => ''])));
        $this->assertFalse(attempt_row::has_media($this->row(['status' => 'uploading'])));
        $this->assertFalse(attempt_row::has_media($this->row(['status' => 'abandoned'])));
    }

    /**
     * The learner's row shows the current score and its feedback, teacher row first.
     *
     * @return void
     */
    public function test_export_score_and_feedback(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $learner = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'storagekey' => 'a.webm',
        ]);
        $this->setUser($learner);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $this->assertFalse($export['hasscore']);
        $this->assertSame('', $export['score']);
        $this->assertFalse($export['hasfeedback']);

        $now = time();
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $learner->id, 'rubricid' => 0, 'origin' => 'ai',
            'scores' => json_encode([
                ['name' => 'Content', 'score' => 5, 'max_score' => 5, 'feedback' => 'AI', 'assessed' => true],
            ]),
            'rawsum' => 5, 'rawmax' => 5, 'overallpct' => 100.00, 'feedback' => 'AI overall', 'graderid' => 0,
            'timecreated' => $now,
        ]);
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $learner->id, 'rubricid' => 0, 'origin' => 'teacher',
            'scores' => json_encode([
                ['name' => 'Content', 'score' => 3, 'max_score' => 5, 'feedback' => 'Clear <thesis>.', 'assessed' => true],
                ['name' => 'Delivery', 'score' => null, 'max_score' => 5, 'feedback' => '', 'assessed' => false],
            ]),
            'rawsum' => 3, 'rawmax' => 5, 'overallpct' => 60.00, 'feedback' => "Good.\nSlow down.", 'graderid' => 2,
            'timecreated' => $now - 60,
        ]);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $this->assertTrue($export['hasscore']);
        $this->assertSame(format_float(60, 2) . '%', $export['score'], 'The teacher row is the current score.');
        $this->assertTrue($export['hasfeedback']);
        $this->assertSame([
            [
                'name' => 'Content',
                'scoretext' => get_string('feedback_criterion_score', 'mod_presenterai', (object) ['score' => 3, 'max' => 5]),
                'feedback' => 'Clear <thesis>.',
                'notcounted' => false,
            ],
            [
                'name' => 'Delivery',
                'scoretext' => get_string('feedback_notassessed', 'mod_presenterai'),
                'feedback' => '',
                'notcounted' => false,
            ],
        ], $export['feedback']['criteria']);
        $this->assertSame('Good.<br />' . "\n" . 'Slow down.', $export['feedback']['overall']);
        $this->assertStringContainsString($export['recorded'], $export['feedbackaria']);

        // The template escapes criterion feedback, which is plain text.
        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/attempts', [
            'hasattempts' => true,
            'showdownloadoffnote' => false,
            'rows' => [$export],
        ]);
        $this->assertStringContainsString('Clear &lt;thesis&gt;.', $html);
        $this->assertStringContainsString(format_float(60, 2) . '%', $html);
    }

    /**
     * A score that assessed nothing has no percentage, and the cell is empty rather than 0%.
     *
     * @return void
     */
    public function test_export_score_with_nothing_assessed(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $learner = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('presenterai', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
        ]);
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $learner->id, 'rubricid' => 0, 'origin' => 'teacher',
            'scores' => '[]', 'rawsum' => 0, 'rawmax' => 0, 'overallpct' => null, 'feedback' => null, 'graderid' => 2,
            'timecreated' => time(),
        ]);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $this->assertFalse($export['hasscore']);
        $this->assertSame('', $export['score']);
        $this->assertTrue($export['hasfeedback']);
        $this->assertSame('', $export['feedback']['overall']);
    }

    /**
     * A learner, an activity and a finished recording, for the feedback panel tests.
     *
     * @return array [\stdClass $rec, \context_module $context, \stdClass $learner]
     */
    private function panel_fixture(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $learner = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('presenterai', ['course' => $course->id, 'videovision' => 1]);
        $context = \context_module::instance($instance->cmid);
        $rec = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $instance->id,
            'userid' => $learner->id,
            'storagekey' => 'a.webm',
            'status' => 'scored',
            'visualevidence' => json_encode(['note' => 'RAW-NOTE-NEVER-SHOWN', 'confidence' => 'high', 'unusable_frames' => 0]),
        ]);
        $this->setUser($learner);

        return [$rec, $context, $learner];
    }

    /**
     * Insert an AI score row with a visual section.
     *
     * @param \stdClass $rec The recording.
     * @param string $status The visualstatus.
     * @param string|null $summary The visualsummary.
     * @param array|null $criteria The criteria, or a default pair of one speech and one visual criterion.
     * @param array|null $tips The tips list.
     * @return void
     */
    private function ai_score(
        \stdClass $rec,
        string $status,
        ?string $summary,
        ?array $criteria = null,
        ?array $tips = null
    ): void {
        global $DB;

        $criteria = $criteria ?? [
            ['name' => 'Content', 'score' => 4, 'max_score' => 5, 'feedback' => 'Clear.', 'assessed' => true],
            ['name' => 'Body Language & Gestures', 'score' => 2, 'max_score' => 5, 'feedback' => 'Hands low.',
                'assessed' => true, 'visual' => true, 'counts' => false],
        ];
        $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id, 'userid' => $rec->userid, 'rubricid' => 0, 'origin' => 'ai',
            'scores' => json_encode($criteria), 'rawsum' => 4, 'rawmax' => 5, 'overallpct' => 80.00,
            'feedback' => 'Overall.', 'tips' => $tips === null ? null : json_encode($tips),
            'visualsummary' => $summary, 'visualstatus' => $status, 'graderid' => 0, 'timecreated' => time(),
        ]);
    }

    /**
     * The attempts template rendered for one exported row.
     *
     * @param array $row An export() result.
     * @return string HTML.
     */
    private function render_rows(array $row): string {
        global $PAGE;

        return $PAGE->get_renderer('core')->render_from_template('mod_presenterai/attempts', [
            'hasattempts' => true,
            'showdownloadoffnote' => false,
            'rows' => [$row],
        ]);
    }

    /**
     * Every visual status and the sentence it shows.
     *
     * @return array
     */
    public static function visual_status_provider(): array {
        return [
            'summary' => ['summary', 'Your hands stayed below the desk, so gestures were hard to see.', null],
            'fallback' => ['fallback', 'TEMPLATE-TEXT', null],
            'not assessed' => ['notassessed', null, 'visual_not_assessed'],
            'not analysed' => ['notanalysed', null, 'visual_not_analysed'],
            'opted out' => ['optedout', null, 'visual_optedout'],
        ];
    }

    /**
     * Each visualstatus renders its own text under the heading, and never the raw note.
     *
     * @dataProvider visual_status_provider
     * @param string $status The visualstatus.
     * @param string|null $summary The stored summary.
     * @param string|null $stringkey The lang string the panel must show, or null to show the summary.
     * @return void
     */
    public function test_visual_section_for_every_status(string $status, ?string $summary, ?string $stringkey): void {
        [$rec, $context, $learner] = $this->panel_fixture();
        $this->ai_score($rec, $status, $summary);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $feedback = $export['feedback'];
        $expected = $stringkey !== null ? get_string($stringkey, 'mod_presenterai') : $summary;

        $this->assertTrue($feedback['hasvisual']);
        $this->assertSame($status, $feedback['visualstatus']);
        $this->assertSame(get_string('visual_summary_heading', 'mod_presenterai'), $feedback['visualheading']);
        $this->assertSame($expected, $feedback['visualtext']);
        // Only the model's own summary carries the note saying where it came from.
        $this->assertSame(
            $status === 'summary' ? get_string('visual_summary_note', 'mod_presenterai') : '',
            $feedback['visualnote']
        );

        $html = $this->render_rows($export);
        $this->assertStringContainsString('data-visualstatus="' . $status . '"', $html);
        $this->assertStringContainsString(s($expected), $html);
        $this->assertStringNotContainsString('RAW-NOTE-NEVER-SHOWN', $html, 'The raw note reached the learner.');
    }

    /**
     * No visual status, no visual section at all, rather than an empty one.
     *
     * @return void
     */
    public function test_no_visual_section_without_a_status(): void {
        [$rec, $context, $learner] = $this->panel_fixture();
        $this->ai_score($rec, '', null);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $this->assertFalse($export['feedback']['hasvisual']);
        $this->assertStringNotContainsString('data-region="visual-feedback"', $this->render_rows($export));
    }

    /**
     * A status that promised words but has none still shows the template, never an empty panel.
     *
     * @return void
     */
    public function test_empty_summary_falls_back_to_the_template(): void {
        [$rec, $context, $learner] = $this->panel_fixture();
        $this->ai_score($rec, 'summary', '');

        $feedback = attempt_row::export($rec, $context, (int) $learner->id, time(), true)['feedback'];
        $this->assertTrue($feedback['hasvisual']);
        $this->assertNotSame('', trim($feedback['visualtext']));
        $this->assertSame('', $feedback['visualnote'], 'The template is not the AI\'s summary and must not say it is.');
    }

    /**
     * A criterion that does not count says so in words (D23); tips render as a list.
     *
     * @return void
     */
    public function test_feedback_only_label_and_tips(): void {
        [$rec, $context, $learner] = $this->panel_fixture();
        $this->ai_score($rec, '', null, null, ['Pause after each point.', '', 'Look at the lens.']);

        $export = attempt_row::export($rec, $context, (int) $learner->id, time(), true);
        $criteria = $export['feedback']['criteria'];
        $this->assertFalse($criteria[0]['notcounted']);
        $this->assertTrue($criteria[1]['notcounted']);
        $this->assertSame(['Pause after each point.', 'Look at the lens.'], $export['feedback']['tips']);

        $html = $this->render_rows($export);
        $this->assertSame(1, substr_count($html, get_string('feedback_criterion_notcounted', 'mod_presenterai')));
        $this->assertStringContainsString(get_string('feedback_tips', 'mod_presenterai'), $html);
        $this->assertStringContainsString('Look at the lens.', $html);
    }
}
