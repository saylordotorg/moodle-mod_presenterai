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

use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\score_manager;
use mod_presenterai\output\grade_page;

/**
 * The grading screen's context: what it shows, and to whom.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\output\grade_page
 */
final class grade_page_test extends \advanced_testcase {
    /**
     * Never leave a double behind for the next test class.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \cm_info The course module. */
    private \cm_info $cm;

    /** @var \context_module The activity context. */
    private \context_module $context;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \stdClass A non-editing teacher. */
    private \stdClass $teacher;

    /**
     * One activity, a learner and a teacher.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        [, $this->cm] = get_course_and_cm_from_instance($this->instance->id, 'presenterai');
        $this->context = \context_module::instance($this->cm->id);
        $this->learner = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
    }

    /**
     * Export the page for a recording and a viewer.
     *
     * @param \stdClass $rec The recording.
     * @param int $viewerid The viewer.
     * @return array
     */
    private function export(\stdClass $rec, int $viewerid): array {
        global $PAGE;

        $page = new grade_page($this->instance, $rec, $this->course, $this->cm, $this->context, $viewerid, '<form></form>');
        return $page->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Create a recording for the learner.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function recording(array $fields = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => 'a.webm',
            'attemptnumber' => 2,
            'durationseconds' => 372,
        ]);
    }

    /**
     * The exported keys are exactly the template's contract.
     *
     * @return void
     */
    public function test_exported_keys(): void {
        $rec = $this->recording(['transcript' => "Good afternoon.\nToday <b>wind</b>."]);
        $data = $this->export($rec, (int) $this->teacher->id);

        $this->assertSame([
            'rootid', 'cmid', 'recordingid', 'learnername', 'attemptlabel', 'recorded', 'length', 'status',
            'inreview', 'canrelease', 'mediaavailable', 'watcharia', 'hastranscript', 'transcript', 'transcriptempty', 'showvisual',
            'hasvisual', 'visualevidence', 'visualempty', 'canrescore', 'rescorenomedia', 'rescoreurl', 'sesskey',
            'form', 'reporturl', 'total',
        ], array_keys($data));

        $this->assertSame('mod-presenterai-grade', $data['rootid']);
        $this->assertSame((int) $this->cm->id, $data['cmid']);
        $this->assertSame((int) $rec->id, $data['recordingid']);
        $this->assertSame('Ada Lovelace', $data['learnername']);
        $this->assertSame(get_string('grade_attemptlabel', 'mod_presenterai', 2), $data['attemptlabel']);
        $this->assertSame('6:12', $data['length']);
        $this->assertSame(get_string('status_uploaded', 'mod_presenterai'), $data['status']);
        $this->assertFalse($data['inreview']);
        $this->assertFalse($data['canrelease']);
        $this->assertTrue($data['mediaavailable']);
        $this->assertStringContainsString('Ada Lovelace', $data['watcharia']);
        $this->assertTrue($data['hastranscript']);
        // Escaped, with line breaks kept.
        $this->assertStringContainsString('&lt;b&gt;wind&lt;/b&gt;', $data['transcript']);
        $this->assertStringContainsString('<br', $data['transcript']);
        $this->assertSame('<form></form>', $data['form']);
        $this->assertStringContainsString('/mod/presenterai/report.php?id=' . $this->cm->id, $data['reporturl']);
        $this->assertSame(['label' => get_string('grade_total_none', 'mod_presenterai')], $data['total']);
    }

    /**
     * An empty transcript and an empty note show their placeholders.
     *
     * @return void
     */
    public function test_placeholders_when_empty(): void {
        $rec = $this->recording(['transcript' => null, 'visualevidence' => null, 'storagekey' => null]);
        $data = $this->export($rec, (int) $this->teacher->id);

        $this->assertFalse($data['mediaavailable']);
        $this->assertFalse($data['hastranscript']);
        $this->assertSame('', $data['transcript']);
        $this->assertSame(get_string('grade_notranscript', 'mod_presenterai'), $data['transcriptempty']);
        $this->assertTrue($data['showvisual']);
        $this->assertFalse($data['hasvisual']);
        $this->assertSame('', $data['visualevidence']);
        $this->assertSame(get_string('grade_novisual', 'mod_presenterai'), $data['visualempty']);

        global $PAGE;
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringContainsString(get_string('grade_notranscript', 'mod_presenterai'), $html);
        $this->assertStringContainsString(get_string('grade_novisual', 'mod_presenterai'), $html);
    }

    /**
     * The note is hidden without viewvisualevidence and shown with it.
     *
     * @return void
     */
    public function test_visual_evidence_follows_the_capability(): void {
        global $DB, $PAGE;

        $rec = $this->recording(['visualevidence' => json_encode(['note' => 'Steady eye contact <throughout>.'])]);

        // A student role granted grade but not viewvisualevidence.
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $grader = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        assign_capability('mod/presenterai:grade', CAP_ALLOW, $studentrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $data = $this->export($rec, (int) $grader->id);
        $this->assertFalse($data['showvisual']);
        $this->assertSame('', $data['visualevidence'], 'The note travelled to a viewer who may not read it.');
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringNotContainsString('eye contact', $html);
        $this->assertStringNotContainsString(get_string('grade_visualevidence', 'mod_presenterai'), $html);

        assign_capability('mod/presenterai:viewvisualevidence', CAP_ALLOW, $studentrole, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $data = $this->export($rec, (int) $grader->id);
        $this->assertTrue($data['showvisual']);
        $this->assertTrue($data['hasvisual']);
        $this->assertSame('Steady eye contact &lt;throughout&gt;.', $data['visualevidence']);

        $data = $this->export($rec, (int) $this->teacher->id);
        $this->assertTrue($data['showvisual'], 'A teacher holds viewvisualevidence by default.');
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringContainsString(get_string('grade_staffonly', 'mod_presenterai'), $html);
    }

    /**
     * A note stored as plain text is shown as it stands, escaped.
     *
     * @return void
     */
    public function test_visual_note_shapes(): void {
        $this->assertSame('', grade_page::visual_note(null));
        $this->assertSame('', grade_page::visual_note('  '));
        $this->assertSame('', grade_page::visual_note('{"note": ""}'));
        $this->assertSame('Plain &amp; simple', grade_page::visual_note('Plain & simple'));
        $this->assertSame('{&quot;other&quot;:1}', grade_page::visual_note('{"other":1}'));
    }

    /**
     * The D18 JSON is decoded: the note, then the confidence and unusable frames on their own line.
     *
     * @return void
     */
    public function test_visual_note_json(): void {
        $note = grade_page::visual_note(json_encode([
            'note' => "Hands <low> in four frames.\nLooks down in two.",
            'confidence' => 'medium',
            'unusable_frames' => 2,
        ]));
        $detail = get_string('scoring_evidencedetail', 'mod_presenterai', (object) [
            'confidence' => get_string('scoring_confidence_medium', 'mod_presenterai'),
            'unusable' => 2,
        ]);
        $this->assertSame(nl2br(s("Hands <low> in four frames.\nLooks down in two.\n\n" . $detail)), $note);
        $this->assertStringContainsString('&lt;low&gt;', $note);

        // An unknown confidence shows the note alone rather than a made up label.
        $this->assertSame('Note.', grade_page::visual_note(json_encode(['note' => 'Note.', 'confidence' => 'certain'])));
    }

    /**
     * Rescore is offered only with AI ready, a grader, no teacher row and something to score from.
     *
     * @return void
     */
    public function test_rescore_state(): void {
        global $PAGE;

        $rec = $this->recording(['status' => 'scored', 'transcript' => 'A transcript.']);
        $teacherid = (int) $this->teacher->id;

        $state = grade_page::rescore_state($rec, $this->context, $teacherid);
        $this->assertSame(grade_page::RESCORE_HIDDEN, $state, 'AI is not ready.');

        $client = $this->createMock(client_interface::class);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        route_resolver::set_test_stt($this->createMock(\mod_presenterai\local\ai\stt_interface::class));
        $this->assertTrue(route_resolver::ai_ready());

        $this->assertSame(grade_page::RESCORE_AVAILABLE, grade_page::rescore_state($rec, $this->context, $teacherid));
        $this->assertSame(
            grade_page::RESCORE_HIDDEN,
            grade_page::rescore_state($rec, $this->context, (int) $this->learner->id),
            'A learner was offered a rescore.'
        );

        // Media gone but a transcript kept: still possible.
        $kept = $this->recording(['storagekey' => null, 'transcript' => 'Kept.']);
        $this->assertSame(grade_page::RESCORE_AVAILABLE, grade_page::rescore_state($kept, $this->context, $teacherid));

        // Media gone and no transcript: a sentence instead of a button.
        $gone = $this->recording(['storagekey' => null, 'transcript' => null, 'mediagonereason' => 'retention']);
        $this->assertSame(grade_page::RESCORE_NOMEDIA, grade_page::rescore_state($gone, $this->context, $teacherid));
        $data = $this->export($gone, $teacherid);
        $this->assertFalse($data['canrescore']);
        $this->assertTrue($data['rescorenomedia']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringContainsString(get_string('rescore_unavailable_nomedia', 'mod_presenterai'), $html);

        // An unfinished upload isn't gradable at all.
        $uploading = $this->recording(['status' => 'uploading']);
        $this->assertSame(grade_page::RESCORE_HIDDEN, grade_page::rescore_state($uploading, $this->context, $teacherid));

        // A teacher row removes the button for good.
        $data = $this->export($rec, $teacherid);
        $this->assertTrue($data['canrescore']);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $data);
        $this->assertStringContainsString('name="action" value="rescore"', $html);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('method="post"', $html);

        score_manager::save_teacher_score($rec, $this->instance, $this->context, $teacherid, 0, [
            ['name' => 'Delivery & Fluency', 'max_score' => 5, 'score' => 3, 'assessed' => true, 'feedback' => ''],
        ], '');
        $this->assertSame(grade_page::RESCORE_HIDDEN, grade_page::rescore_state($rec, $this->context, $teacherid));
        $html = $PAGE->get_renderer('core')->render_from_template('mod_presenterai/grade', $this->export($rec, $teacherid));
        $this->assertStringNotContainsString('value="rescore"', $html);
    }
}
