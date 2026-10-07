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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_presenterai\privacy\provider;

/**
 * The privacy provider: what is declared, found, exported and deleted.
 *
 * Three kinds of person are in play: a learner who owns recordings, a grader
 * named on scores, and a user who owns only AI spend rows. Each has to be
 * found, and a deletion has to remove the learner's work (media first) while
 * leaving another learner's alone and keeping the scores a grader wrote.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity. */
    private \stdClass $instance;

    /** @var \context_module Its context. */
    private \context_module $context;

    /** @var \stdClass A learner with an fs and an S3 attempt. */
    private \stdClass $alice;

    /** @var \stdClass Another learner, whose attempt the teacher scored. */
    private \stdClass $bob;

    /** @var \stdClass A teacher who only appears as a grader. */
    private \stdClass $teacher;

    /** @var \stdClass A user who only has AI spend rows. */
    private \stdClass $spender;

    /** @var \stdClass Alice's fs attempt. */
    private \stdClass $alicefs;

    /** @var \stdClass Alice's S3 attempt. */
    private \stdClass $alices3;

    /** @var \stdClass Bob's fs attempt. */
    private \stdClass $bobfs;

    /**
     * One activity with work from every kind of person.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        unset_config('backend', 'mod_presenterai');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->instance = $generator->create_module('presenterai', ['course' => $this->course->id]);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->alice = $generator->create_and_enrol($this->course, 'student');
        $this->bob = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->spender = $generator->create_user();

        $this->alicefs = $this->fs_recording($this->alice, [
            'attemptnumber' => 1,
            'transcript' => 'Good morning, everyone.',
            'visualevidence' => '{"note":"Hands mostly still, eyes on the camera."}',
            'visualevidenceat' => time(),
        ]);
        $this->score($this->alicefs, 'ai', 0, 70.0, 'AI says fine.');
        $this->score($this->alicefs, 'teacher', (int) $this->teacher->id, 80.0, 'Teacher says better.');

        $this->alices3 = $generator->get_plugin_generator('mod_presenterai')->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->alice->id,
            'attemptnumber' => 2,
            'backend' => 's3',
            'storagekey' => 'presenterai/1/2/alice-second.webm',
            'sizebytes' => 2097152,
        ]);

        $this->bobfs = $this->fs_recording($this->bob, ['attemptnumber' => 1]);
        $this->score($this->bobfs, 'teacher', (int) $this->teacher->id, 60.0, 'Slow down.');

        $DB->insert_record('presenterai_aiusage', (object) [
            'presenteraiid' => $this->instance->id,
            'recordingid' => 0,
            'userid' => $this->spender->id,
            'action' => 'deck',
            'provider' => 'openai',
            'model' => 'a-model',
            'estmicrocents' => 99,
            'timecreated' => time(),
        ]);
    }

    /**
     * A finished fs recording whose recording file really exists.
     *
     * @param \stdClass $user Owner.
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function fs_recording(\stdClass $user, array $fields = []): \stdClass {
        $rec = $this->getDataGenerator()->get_plugin_generator('mod_presenterai')->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $user->id,
            'storagekey' => random_string(32) . '.webm',
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_presenterai',
            'filearea' => 'recording',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $rec->storagekey,
            'userid' => $user->id,
        ], 'video of ' . $user->id);

        return $rec;
    }

    /**
     * A score row, straight to the table.
     *
     * @param \stdClass $rec The recording.
     * @param string $origin 'ai' or 'teacher'.
     * @param int $graderid The grader, or 0.
     * @param float $pct The overall percentage.
     * @param string $feedback The overall feedback.
     * @return int The score id.
     */
    private function score(\stdClass $rec, string $origin, int $graderid, float $pct, string $feedback): int {
        global $DB;

        return (int) $DB->insert_record('presenterai_score', (object) [
            'recordingid' => $rec->id,
            'userid' => $rec->userid,
            'rubricid' => 0,
            'origin' => $origin,
            'scores' => json_encode([
                ['name' => 'Clarity', 'score' => 4, 'max_score' => 5, 'feedback' => 'Clear.', 'assessed' => true],
                ['name' => 'Gestures', 'score' => 0, 'max_score' => 5, 'feedback' => '', 'assessed' => false],
            ]),
            'rawsum' => 4,
            'rawmax' => 5,
            'overallpct' => $pct,
            'feedback' => $feedback,
            'tips' => 'Breathe.',
            'graderid' => $graderid,
            'timecreated' => time(),
        ]);
    }

    /**
     * The subcontext one attempt is exported under.
     *
     * @param \stdClass $rec The recording.
     * @return string[]
     */
    private function attempt_path(\stdClass $rec): array {
        return [get_string('privacy:path:attempts', 'mod_presenterai'), 'attempt-' . $rec->attemptnumber . '-' . $rec->id];
    }

    /**
     * Every table, the File API, the gradebook, messaging and the bucket are declared.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_presenterai'));
        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $declared = [
            'presenterai_recording', 'presenterai_score', 'presenterai_aiusage', 'core_files', 'core_message', 'core_grades', 's3',
        ];
        foreach ($declared as $expected) {
            $this->assertContains($expected, $names);
        }
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'presenterai_recording') {
                $this->assertArrayHasKey('visualevidence', $item->get_privacy_fields());
                $this->assertArrayHasKey('deletewarnedat', $item->get_privacy_fields());
                $this->assertArrayHasKey('visualevidenceat', $item->get_privacy_fields());
            }
            if ($item->get_name() === 'presenterai_score') {
                $this->assertArrayHasKey('legacymeanscore', $item->get_privacy_fields());
                $this->assertArrayHasKey('legacymeta', $item->get_privacy_fields());
            }
        }
    }

    /**
     * A learner, a grader and a spender each find the activity; a stranger finds nothing.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        foreach ([$this->alice, $this->teacher, $this->spender] as $user) {
            $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
            $this->assertEquals([$this->context->id], array_values($contexts), 'Missed user ' . $user->username);
        }

        $stranger = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int) $stranger->id)->get_contextids());
    }

    /**
     * Everyone with data in the context is listed.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'mod_presenterai');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $this->alice->id, (int) $this->bob->id, (int) $this->teacher->id, (int) $this->spender->id],
            array_map('intval', $userlist->get_userids())
        );
    }

    /**
     * The export carries the transcript, the raw visual note with its declaration, every score and the file.
     *
     * @return void
     */
    public function test_export_for_a_learner(): void {
        global $DB;

        // A score migrated from Soapbox carries the old session's data and mean.
        $DB->set_field('presenterai_score', 'legacymeanscore', 72, ['recordingid' => $this->alicefs->id, 'origin' => 'ai']);
        $DB->set_field(
            'presenterai_score',
            'legacymeta',
            '{"wpm":131,"fillers":4}',
            ['recordingid' => $this->alicefs->id, 'origin' => 'ai']
        );

        $this->export_context_data_for_user((int) $this->alice->id, $this->context, 'mod_presenterai');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data($this->attempt_path($this->alicefs));
        $this->assertSame('Good morning, everyone.', $data->transcript);
        $this->assertSame('{"note":"Hands mostly still, eyes on the camera."}', $data->visualevidence);
        $this->assertSame(get_string('privacy:export:visualevidence_note', 'mod_presenterai'), $data->visualevidence_note);
        $this->assertCount(2, $data->scores);
        $origins = array_map(fn($s) => $s->origin, $data->scores);
        $this->assertEqualsCanonicalizing(['ai', 'teacher'], $origins);
        foreach ($data->scores as $score) {
            $this->assertObjectNotHasProperty('graderid', $score);
            $this->assertCount(2, $score->criteria);
            if ($score->origin === 'ai') {
                $this->assertSame(72, $score->legacymeanscore);
                $this->assertEquals((object) ['wpm' => 131, 'fillers' => 4], $score->legacymeta);
            } else {
                $this->assertNull($score->legacymeanscore);
                $this->assertNull($score->legacymeta);
            }
        }
        $this->assertNotNull($data->visualevidenceat);
        $this->assertObjectNotHasProperty('media_note', $data);

        $files = $writer->get_files($this->attempt_path($this->alicefs));
        $names = array_map(fn($f) => $f->get_filename(), array_values($files));
        $this->assertContains($this->alicefs->storagekey, $names);

        // The S3 attempt says where its media is instead of carrying the bytes.
        $s3data = $writer->get_data($this->attempt_path($this->alices3));
        $this->assertSame(
            get_string('privacy:export:s3media', 'mod_presenterai', display_size(2097152)),
            $s3data->media_note
        );
        $this->assertEmpty($writer->get_files($this->attempt_path($this->alices3)));

        // Nothing of Bob's.
        $this->assertEmpty($writer->get_data($this->attempt_path($this->bobfs)));
    }

    /**
     * A grader gets the scores they wrote, without the learners' identities; a spender gets their usage.
     *
     * @return void
     */
    public function test_export_for_a_grader_and_a_spender(): void {
        $this->export_context_data_for_user((int) $this->teacher->id, $this->context, 'mod_presenterai');
        $writer = writer::with_context($this->context);
        $given = $writer->get_data([get_string('privacy:path:scoresgiven', 'mod_presenterai')]);
        $this->assertCount(2, $given->scores);
        foreach ($given->scores as $score) {
            $this->assertObjectNotHasProperty('userid', $score);
            $this->assertObjectNotHasProperty('recordingid', $score);
        }
        $this->assertEmpty($writer->get_data($this->attempt_path($this->bobfs)));

        writer::reset();
        $this->export_context_data_for_user((int) $this->spender->id, $this->context, 'mod_presenterai');
        $usage = writer::with_context($this->context)->get_data([get_string('privacy:path:aiusage', 'mod_presenterai')]);
        $this->assertCount(1, $usage->usage);
        $this->assertSame('deck', $usage->usage[0]->action);
    }

    /**
     * Deleting a learner takes their rows, files and scores and nobody else's; a grader is anonymised.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        // The S3 bucket is not configured here, so the S3 object is queued.
        provider::delete_data_for_user(
            new approved_contextlist($this->alice, 'mod_presenterai', [$this->context->id])
        );
        $this->resetDebugging();

        $this->assertSame(0, $DB->count_records('presenterai_recording', ['userid' => $this->alice->id]));
        $this->assertFalse($DB->record_exists('presenterai_score', ['recordingid' => $this->alicefs->id]));
        $fs = get_file_storage();
        $this->assertTrue($fs->is_area_empty($this->context->id, 'mod_presenterai', 'recording', $this->alicefs->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\mod_presenterai\task\delete_orphaned_media::class));

        $this->assertTrue($DB->record_exists('presenterai_recording', ['id' => $this->bobfs->id]));
        $this->assertFalse($fs->is_area_empty($this->context->id, 'mod_presenterai', 'recording', $this->bobfs->id));

        // The grader asks: Bob's score stays, without the grader's name.
        provider::delete_data_for_user(
            new approved_contextlist($this->teacher, 'mod_presenterai', [$this->context->id])
        );
        $score = $DB->get_record('presenterai_score', ['recordingid' => $this->bobfs->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $score->graderid);
        $this->assertSame('Slow down.', $score->feedback);
        $this->assertEmpty(provider::get_contexts_for_userid((int) $this->teacher->id)->get_contextids());
    }

    /**
     * Deleting everyone in the context empties the activity of learner work and spend rows.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);
        $this->resetDebugging();

        $this->assertSame(0, $DB->count_records('presenterai_recording', ['presenteraiid' => $this->instance->id]));
        $this->assertSame(0, $DB->count_records('presenterai_score'));
        $this->assertSame(0, $DB->count_records('presenterai_aiusage', ['presenteraiid' => $this->instance->id]));
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'mod_presenterai' AND filearea = 'recording' AND filename <> '.'",
            ['ctx' => $this->context->id]
        ));
    }

    /**
     * Deleting a list of users handles each kind of person.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $userlist = new approved_userlist(
            $this->context,
            'mod_presenterai',
            [(int) $this->bob->id, (int) $this->teacher->id, (int) $this->spender->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('presenterai_recording', ['id' => $this->bobfs->id]));
        $this->assertSame(0, $DB->count_records('presenterai_aiusage', ['userid' => $this->spender->id]));
        // Alice's attempt keeps its teacher score, now with no grader named.
        $teacherscore = $DB->get_record('presenterai_score', ['recordingid' => $this->alicefs->id, 'origin' => 'teacher']);
        $this->assertSame(0, (int) $teacherscore->graderid);
        $this->assertSame(2, $DB->count_records('presenterai_recording', ['userid' => $this->alice->id]));

        $userlist = new userlist($this->context, 'mod_presenterai');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $this->alice->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * The phase 3 data is declared: the opt out, the summary and its status, the gate log and the AI service.
     *
     * @return void
     */
    public function test_get_metadata_visual(): void {
        $collection = provider::get_metadata(new collection('mod_presenterai'));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }

        $this->assertArrayHasKey('visualoptout', $items['presenterai_recording']->get_privacy_fields());
        $this->assertArrayHasKey('visualsummary', $items['presenterai_score']->get_privacy_fields());
        $this->assertArrayHasKey('visualstatus', $items['presenterai_score']->get_privacy_fields());
        $this->assertArrayHasKey('presenterai_gatelog', $items);
        $this->assertEqualsCanonicalizing(
            ['recordingid', 'target', 'layer', 'gaterule', 'rejectedtext', 'timecreated'],
            array_keys($items['presenterai_gatelog']->get_privacy_fields())
        );
        $this->assertArrayHasKey('aiservice', $items);
        $this->assertEqualsCanonicalizing(
            ['audio', 'transcript', 'frames', 'slides', 'feedback'],
            array_keys($items['aiservice']->get_privacy_fields())
        );
        $this->assertArrayHasKey('core_ai', $items);
    }

    /**
     * The export carries the summary, its status, the opt out and the withheld strings.
     *
     * @return void
     */
    public function test_export_visual_data(): void {
        global $DB;

        $DB->set_field('presenterai_recording', 'visualoptout', 1, ['id' => $this->alicefs->id]);
        $DB->set_field('presenterai_score', 'visualsummary', 'Your hands stayed low.', ['recordingid' => $this->alicefs->id,
            'origin' => 'ai']);
        $DB->set_field('presenterai_score', 'visualstatus', 'summary', ['recordingid' => $this->alicefs->id, 'origin' => 'ai']);
        $DB->insert_record('presenterai_gatelog', (object) [
            'recordingid' => $this->alicefs->id, 'target' => 'summary', 'layer' => 2, 'gaterule' => 'clothing',
            'rejectedtext' => 'You wore a dark shirt.', 'timecreated' => time(),
        ]);

        $this->export_context_data_for_user((int) $this->alice->id, $this->context, 'mod_presenterai');
        $data = writer::with_context($this->context)->get_data($this->attempt_path($this->alicefs));

        $this->assertSame(get_string('yes'), $data->visualoptout);
        $ai = array_values(array_filter($data->scores, fn($score) => $score->origin === 'ai'))[0];
        $this->assertSame('Your hands stayed low.', $ai->visualsummary);
        $this->assertSame('summary', $ai->visualstatus);
        $this->assertCount(1, $data->rejectedfeedback);
        $this->assertSame('You wore a dark shirt.', $data->rejectedfeedback[0]->rejectedtext);
        $this->assertSame('clothing', $data->rejectedfeedback[0]->rule);
    }

    /**
     * A learner's deletion takes their gate log rows and nobody else's.
     *
     * @return void
     */
    public function test_delete_removes_gatelog_rows(): void {
        global $DB;

        foreach ([$this->alicefs, $this->bobfs] as $rec) {
            $DB->insert_record('presenterai_gatelog', (object) [
                'recordingid' => $rec->id, 'target' => 'summary', 'layer' => 4, 'gaterule' => 'judge',
                'rejectedtext' => 'withheld for ' . $rec->userid, 'timecreated' => time(),
            ]);
        }

        provider::delete_data_for_user(new approved_contextlist($this->alice, 'mod_presenterai', [$this->context->id]));
        $this->resetDebugging();

        $this->assertFalse($DB->record_exists('presenterai_gatelog', ['recordingid' => $this->alicefs->id]));
        $this->assertTrue($DB->record_exists('presenterai_gatelog', ['recordingid' => $this->bobfs->id]));

        provider::delete_data_for_all_users_in_context($this->context);
        $this->resetDebugging();
        $this->assertSame(0, $DB->count_records('presenterai_gatelog'));
    }
}
