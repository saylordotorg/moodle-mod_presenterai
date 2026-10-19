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

use mod_presenterai\local\migration\ids;
use mod_presenterai\local\migration\rollback;
use mod_presenterai\local\migration\runner;
use mod_presenterai\tests\fixtures\fake_source;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The Soapbox migration rollback: it removes only what the import made, restores each stand-in, keeps SOLA's media.
 *
 * Each test imports through the real runner (SOLA is the fake_source double) and then rolls back. S3 is configured
 * with credentials and an endpoint nothing listens on, and a sentinel curl response is queued: a rollback that sends
 * any request to the bucket consumes it, and one that tries to delete and fails queues delete_orphaned_media. Both
 * are checked, so a delete of a SOLA object can't get through either way.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\rollback
 */
final class migration_rollback_test extends \advanced_testcase {
    /** @var string A transcript no output may contain. */
    private const SECRET_TRANSCRIPT = 'ZEBRA-TRANSCRIPT-7731 my private words';

    /** @var string The stand-in URL path. */
    private const PRESENT = '/local/ai_course_assistant/soapbox_present.php';

    /** @var string What the queued curl response says, so it's recognisable if it's consumed. */
    private const SENTINEL = 'ROLLBACK-SENTINEL-NO-REQUEST-EXPECTED';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var fake_source The source double. */
    private fake_source $source;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /** @var \stdClass Another learner. */
    private \stdClass $bob;

    /**
     * Load the fixtures.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/migration/sola_rows.php');
        require_once(__DIR__ . '/fixtures/migration/fake_source.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course, two learners, S3 settings matching SOLA's (with credentials, unreachable), stealth and the recycle
     * bin on, admin as the user.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        \core\session\manager::set_user(get_admin());

        set_config('backend', 's3', 'mod_presenterai');
        set_config('s3bucket', 'archive-course', 'mod_presenterai');
        set_config('s3region', 'us-east-1', 'mod_presenterai');
        set_config('s3prefix', 'soapbox', 'mod_presenterai');
        set_config('s3key', 'AKIAIOSFODNN7EXAMPLE', 'mod_presenterai');
        set_config('s3secret', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'mod_presenterai');
        set_config('s3endpoint', 'http://127.0.0.1:1', 'mod_presenterai');
        set_config('s3pathstyle', 1, 'mod_presenterai');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $CFG->allowstealth = 1;

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'format' => 'topics']);
        $this->alice = $gen->create_and_enrol($this->course, 'student');
        $this->bob = $gen->create_and_enrol($this->course, 'student');
        $this->source = new fake_source();
        self::set_mocks([]);

        if (sola_rows::available()) {
            $assign = sola_rows::assign($this->course->id);
            $score = sola_rows::score($this->course->id, $this->alice->id);
            sola_rows::rec($assign, $this->alice->id, ['scoreid' => $score, 'transcript' => self::SECRET_TRANSCRIPT,
                'storage_key' => 'soapbox/sola-own.webm']);
        }
    }

    /**
     * Leave no queued curl response behind for another test.
     */
    protected function tearDown(): void {
        self::set_mocks([]);
        parent::tearDown();
    }

    /**
     * Replace curl's queued test responses.
     *
     * @param array $mocks The responses.
     */
    private static function set_mocks(array $mocks): void {
        (new \ReflectionProperty(\curl::class, 'mockresponses'))->setValue(null, $mocks);
    }

    /**
     * Curl's queued test responses: a request consumes one.
     *
     * @return array
     */
    private static function mocks(): array {
        return (new \ReflectionProperty(\curl::class, 'mockresponses'))->getValue();
    }

    /**
     * SOLA's tables, fingerprinted, or nothing on a site without them.
     *
     * @return array
     */
    private function sola_print(): array {
        return sola_rows::available() ? sola_rows::fingerprint() : [];
    }

    /**
     * Row counts of the tables an import and a rollback write.
     *
     * @return array Table to count.
     */
    private function table_counts(): array {
        global $DB;

        $counts = [];
        foreach (['presenterai', 'presenterai_topic', 'presenterai_recording', 'presenterai_score', 'presenterai_migration_map',
                'course_modules', 'url', 'context'] as $table) {
            $counts[$table] = $DB->count_records($table);
        }

        return $counts;
    }

    /**
     * A mod_url stand-in pointing at one Soapbox assignment, and the reader row for it.
     *
     * @param int $assignid The assignment.
     * @param int|null $courseid The course, the test course when null.
     * @return array The reader row.
     */
    private function standin(int $assignid, ?int $courseid = null): array {
        global $CFG;

        $courseid = $courseid ?? $this->course->id;
        $url = $CFG->wwwroot . self::PRESENT . '?id=' . $assignid;
        $mod = $this->getDataGenerator()->create_module('url', ['course' => $courseid, 'externalurl' => $url, 'section' => 2]);
        $row = ['cmid' => (int) $mod->cmid, 'assignid' => $assignid, 'externalurl' => $url, 'visible' => 1,
            'visibleoncoursepage' => 1];
        $this->source->standins[$courseid][] = $row;

        return $row;
    }

    /**
     * One assignment with a topic and recordings carrying every kind of key, scored and not, one expired.
     *
     * @param int|null $courseid The course, the test course when null.
     * @return array Ids: assign, topic, and recordings by name.
     */
    private function seed(?int $courseid = null): array {
        $src = $this->source;
        $cid = $courseid ?? $this->course->id;
        $assign = $src->add_assign($cid);
        $topic = $src->add_topic($assign);
        $score = $src->add_score($cid, $this->alice->id);
        $ids = ['assign' => $assign, 'topic' => $topic];
        $ids['scored'] = $src->add_rec($assign, $this->alice->id, ['status' => 'scored', 'scoreid' => $score,
            'topicid' => $topic, 'transcript' => self::SECRET_TRANSCRIPT, 'deck_key' => 'soapbox/deck-' . $assign . '.pdf',
            'frames_key' => 'soapbox/frames-' . $assign . '.json']);
        $ids['expired'] = $src->add_rec($assign, $this->bob->id, ['status' => 'deleted', 'storage_key' => null]);
        $ids['uploaded'] = $src->add_rec($assign, $this->bob->id, ['status' => 'uploaded']);

        return $ids;
    }

    /**
     * Import the course through the real runner.
     *
     * @param int|null $courseid The course, the test course when null.
     * @param runner|null $runner A runner, a plain one when null.
     */
    private function apply(?int $courseid = null, ?runner $runner = null): void {
        $runner = $runner ?? new runner($this->source);
        $runner->apply_course($courseid ?? $this->course->id);
    }

    /**
     * The rollback under test.
     *
     * @return rollback
     */
    private function rollback(): rollback {
        return new rollback($this->source);
    }

    /**
     * The map row of one source assignment.
     *
     * @param int $assignid The source assignment.
     * @return \stdClass|false
     */
    private function assign_map(int $assignid) {
        global $DB;

        return $DB->get_record('presenterai_migration_map',
            ['sourcetable' => ids::SOURCE_ASSIGN, 'sourceid' => $assignid, 'sourcesiteid' => ids::site_id()]);
    }

    /**
     * A stand-in's URL and visibility as they are now.
     *
     * @param int $cmid The stand-in.
     * @return array [externalurl, visible, visibleoncoursepage].
     */
    private function standin_now(int $cmid): array {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);

        return [(string) $DB->get_field('url', 'externalurl', ['id' => $cm->instance]), (int) $cm->visible,
            (int) $cm->visibleoncoursepage];
    }

    /**
     * Test a rollback removes the activity, its topics, attempts, scores and every map row, and restores the stand-in.
     */
    public function test_a_rollback_removes_what_the_import_made_and_restores_the_standin(): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $before = $this->table_counts();
        $original = $this->standin_now($standin['cmid']);
        $sola = $this->sola_print();
        $this->apply();
        $map = $this->assign_map($ids['assign']);
        $this->assertSame(runner::STATE_VERIFIED, $map->state);
        $this->assertNotSame($original, $this->standin_now($standin['cmid']));

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertFalse($result['noop']);
        $this->assertSame('', $result['refusal']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['refused']);
        $this->assertSame(1, $result['standinsrestored']);
        $this->assertSame(1 + 1 + 3, $result['maprowsdeleted']);
        $entry = $result['entries'][$ids['assign']];
        $this->assertSame(rollback::OUTCOME_REMOVED, $entry['outcome']);
        $this->assertSame(rollback::STANDIN_RESTORED, $entry['standin']);
        $this->assertSame((int) $map->targetcmid, $entry['cmid']);

        $this->assertSame($before, $this->table_counts());
        $this->assertSame($original, $this->standin_now($standin['cmid']));
        $this->assertFalse($DB->record_exists('presenterai', ['id' => $map->targetid]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $map->targetcmid]));
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a rollback sends nothing to the bucket: no delete, no request at all, nothing queued for later, no
     * recycle bin copy. The objects the migrated rows named are SOLA's.
     */
    public function test_a_rollback_deletes_no_media(): void {
        global $DB;
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $this->apply();
        $map = $this->assign_map($ids['assign']);
        $keys = [];
        foreach ($DB->get_records('presenterai_recording', ['presenteraiid' => $map->targetid]) as $rec) {
            $keys = array_merge($keys, array_filter([$rec->storagekey, $rec->deckkey, $rec->frameskey]));
        }
        $this->assertCount(4, $keys, 'the scored attempt has three keys, the uploaded one a video');
        self::set_mocks([self::SENTINEL]);
        $sola = $this->sola_print();

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $this->assertSame([self::SENTINEL], self::mocks(), 'a request was sent to the bucket');
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(\mod_presenterai\task\delete_orphaned_media::class));
        $this->assertSame(0, $DB->count_records('tool_recyclebin_course'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a rollback fires none of PresenterAI's events and sends no learner a message.
     */
    public function test_a_rollback_is_quiet(): void {
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $this->apply();
        $sink = $this->redirectEvents();
        $messages = $this->redirectMessages();

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $own = array_filter($sink->get_events(), static fn($e) => str_starts_with(get_class($e), 'mod_presenterai\\event\\'));
        $this->assertSame([], array_values($own));
        $this->assertSame(0, $messages->count());
    }

    /**
     * Test a grade item the activity has (grade 0 normally gives none) goes with it.
     */
    public function test_a_grade_item_goes_with_the_activity(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $ids = $this->seed();
        $this->apply();
        $map = $this->assign_map($ids['assign']);
        grade_update('mod/presenterai', $this->course->id, 'mod', 'presenterai', (int) $map->targetid, 0, null,
            ['itemname' => 'Migrated', 'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0]);
        $this->assertTrue($DB->record_exists('grade_items', ['itemmodule' => 'presenterai', 'iteminstance' => $map->targetid]));

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $this->assertFalse($DB->record_exists('grade_items', ['itemmodule' => 'presenterai', 'iteminstance' => $map->targetid]));
    }

    /**
     * Ways a migrated activity becomes someone's work after the import.
     *
     * @return array Name to [code].
     */
    public static function used_provider(): array {
        return [
            'grading turned on' => [rollback::REFUSE_GRADED],
            'a learner made an attempt' => [rollback::REFUSE_LEARNERATTEMPTS],
            'a teacher scored an attempt' => [rollback::REFUSE_TEACHERSCORES],
        ];
    }

    /**
     * Test an activity that became someone's work is refused, reported by code, and left exactly as it was.
     *
     * @dataProvider used_provider
     * @param string $code The refusal expected.
     */
    public function test_an_activity_in_use_is_refused(string $code): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $this->apply();
        $map = $this->assign_map($ids['assign']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
        if ($code === rollback::REFUSE_GRADED) {
            $DB->set_field('presenterai', 'grade', 100, ['id' => $map->targetid]);
        } else if ($code === rollback::REFUSE_LEARNERATTEMPTS) {
            $gen->create_recording(['presenteraiid' => $map->targetid, 'userid' => $this->alice->id]);
        } else {
            $rec = $DB->get_record('presenterai_recording', ['legacyrecid' => $ids['uploaded']], '*', MUST_EXIST);
            $gen->create_score(['recordingid' => $rec->id, 'userid' => $rec->userid, 'origin' => 'teacher']);
        }
        $before = $this->table_counts();
        $standinbefore = $this->standin_now($standin['cmid']);
        $sola = $this->sola_print();

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['refused']);
        $entry = $result['entries'][$ids['assign']];
        $this->assertSame(rollback::OUTCOME_REFUSED, $entry['outcome']);
        $this->assertSame($code, $entry['code']);
        $this->assertStringNotContainsString(self::SECRET_TRANSCRIPT, json_encode($result));
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($standinbefore, $this->standin_now($standin['cmid']));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $map->targetcmid]));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test an activity with no map entry of this site is never touched: one a teacher made, one that carries a
     * legacy id with no map row, and one whose map row belongs to another site.
     */
    public function test_activities_the_import_did_not_make_are_left_alone(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $byhand = $gen->create_module('presenterai', ['course' => $this->course->id]);
        $restored = $gen->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'legacyassignid', 4242, ['id' => $restored->id]);
        $foreign = $gen->create_module('presenterai', ['course' => $this->course->id]);
        $DB->set_field('presenterai', 'legacyassignid', 4343, ['id' => $foreign->id]);
        $DB->insert_record('presenterai_migration_map', (object) ['runid' => 'x', 'sourcetable' => ids::SOURCE_ASSIGN,
            'sourceid' => 4343, 'sourcesiteid' => sha1('another site'), 'targetid' => $foreign->id,
            'targetcmid' => $foreign->cmid, 'state' => runner::STATE_VERIFIED, 'payload' => null, 'timecreated' => 1,
            'timemodified' => 1]);
        $before = $this->table_counts();

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertTrue($result['noop']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $result['entries']);
        $this->assertSame($before, $this->table_counts());
        foreach ([$byhand, $restored, $foreign] as $mod) {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $mod->cmid]));
        }
    }

    /**
     * Test a second rollback finds nothing to do and says so.
     */
    public function test_a_second_rollback_is_a_noop(): void {
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $this->apply();
        $this->rollback()->rollback_course($this->course->id);
        $before = $this->table_counts();
        $sola = $this->sola_print();

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertTrue($result['noop']);
        $this->assertSame(get_string('migration:rollback_noop', 'mod_presenterai', $this->course->id), $result['message']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['maprowsdeleted']);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test an import can be applied again after a rollback, and rolled back again.
     */
    public function test_apply_rollback_apply_rollback(): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $original = $this->standin_now($standin['cmid']);
        $sola = $this->sola_print();

        $this->apply();
        $this->rollback()->rollback_course($this->course->id);
        $this->apply();
        $this->assertSame(runner::STATE_VERIFIED, $this->assign_map($ids['assign'])->state);
        $this->assertSame(3, $DB->count_records('presenterai_recording'));
        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $this->assertSame($original, $this->standin_now($standin['cmid']));
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a failed entry (its activity was deleted) still has its stand-in restored, read from a payload that also
     * carries the failure, and its map rows deleted. There's no activity to remove.
     */
    public function test_a_failed_entry_has_its_standin_restored_and_its_rows_deleted(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $original = $this->standin_now($standin['cmid']);
        $this->apply();
        $map = $this->assign_map($ids['assign']);
        // A teacher deletes the activity. Its rows are nulled first only so this setup sends nothing to the bucket.
        $DB->set_field('presenterai_recording', 'storagekey', null, ['presenteraiid' => $map->targetid]);
        $DB->set_field('presenterai_recording', 'deckkey', null, ['presenteraiid' => $map->targetid]);
        $DB->set_field('presenterai_recording', 'frameskey', null, ['presenteraiid' => $map->targetid]);
        set_config('coursebinenable', 0, 'tool_recyclebin');
        course_delete_module((int) $map->targetcmid);
        // The row as the runner's mark_failed() leaves it: the failure beside the stand-in record.
        $payload = json_decode($map->payload, true);
        $payload[runner::PAYLOAD_FAILED] = 'targetgone';
        $DB->update_record('presenterai_migration_map', (object) ['id' => $map->id, 'state' => runner::STATE_FAILED,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
        $map = $this->assign_map($ids['assign']);
        $payload = json_decode($map->payload, true);
        $this->assertArrayHasKey(runner::PAYLOAD_FAILED, $payload);
        $this->assertArrayHasKey(runner::PAYLOAD_STANDIN, $payload);

        $result = $this->rollback()->rollback_course($this->course->id);

        $entry = $result['entries'][$ids['assign']];
        $this->assertSame(rollback::OUTCOME_CLEARED, $entry['outcome']);
        $this->assertSame(rollback::NOTE_TARGETGONE, $entry['code']);
        $this->assertSame(rollback::STANDIN_RESTORED, $entry['standin']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['cleared']);
        $this->assertSame(1 + 1 + 3, $result['maprowsdeleted']);
        $this->assertSame($original, $this->standin_now($standin['cmid']));
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
    }

    /**
     * Test a stand-in that's gone is reported, and the rest of the rollback happens.
     */
    public function test_a_missing_standin_is_reported_not_an_error(): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $this->apply();
        $DB->delete_records('course_modules', ['id' => $standin['cmid']]);

        $result = $this->rollback()->rollback_course($this->course->id);

        $entry = $result['entries'][$ids['assign']];
        $this->assertSame(rollback::OUTCOME_REMOVED, $entry['outcome']);
        $this->assertSame(rollback::STANDIN_MISSING, $entry['standin']);
        $this->assertSame(0, $result['standinsrestored']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
    }

    /**
     * Test a stand-in someone pointed somewhere else after the import is left as they set it, and reported.
     */
    public function test_a_standin_changed_since_is_left_alone(): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign']);
        $this->apply();
        $instance = $DB->get_field('course_modules', 'instance', ['id' => $standin['cmid']]);
        $DB->set_field('url', 'externalurl', 'https://example.com/elsewhere', ['id' => $instance]);

        $result = $this->rollback()->rollback_course($this->course->id);

        $entry = $result['entries'][$ids['assign']];
        $this->assertSame(rollback::OUTCOME_REMOVED, $entry['outcome']);
        $this->assertSame(rollback::STANDIN_CHANGED, $entry['standin']);
        $this->assertSame('https://example.com/elsewhere', $this->standin_now($standin['cmid'])[0]);
    }

    /**
     * Test an import interrupted after its topics (map rows at attached, no recording rows) rolls back cleanly.
     */
    public function test_an_interrupted_import_rolls_back(): void {
        global $DB;
        $ids = $this->seed();
        $before = $this->table_counts();
        $runner = new class ($this->source) extends runner {
            /**
             * Fail between the two transactions.
             *
             * @param int $sourceid The source assignment.
             */
            protected function after_topics(int $sourceid): void {
                throw new \RuntimeException('interrupted');
            }
        };
        $this->apply(null, $runner);
        $this->assertSame(runner::STATE_ATTACHED, $this->assign_map($ids['assign'])->state);

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $this->assertSame(rollback::STANDIN_NONE, $result['entries'][$ids['assign']]['standin']);
        $this->assertSame(2, $result['maprowsdeleted']);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame(0, $DB->count_records('presenterai_migration_map'));
    }

    /**
     * Test a rollback of one course leaves another course's import alone.
     */
    public function test_only_the_named_course_is_rolled_back(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->getDataGenerator()->enrol_user($this->alice->id, $other->id, 'student');
        $this->getDataGenerator()->enrol_user($this->bob->id, $other->id, 'student');
        $ids = $this->seed();
        $otherids = $this->seed((int) $other->id);
        $this->apply();
        $this->apply((int) $other->id);
        $othermap = $this->assign_map($otherids['assign']);

        $result = $this->rollback()->rollback_course($this->course->id);

        $this->assertSame(1, $result['removed']);
        $this->assertArrayHasKey($ids['assign'], $result['entries']);
        $this->assertArrayNotHasKey($otherids['assign'], $result['entries']);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $othermap->targetcmid]));
        $this->assertSame(5, $DB->count_records('presenterai_migration_map'));
        $this->assertSame(3, $DB->count_records('presenterai_recording'));
    }

    /**
     * Test a rollback started inside a transaction refuses and writes nothing.
     */
    public function test_a_rollback_inside_a_transaction_refuses(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $before = $this->table_counts();
        $transaction = $DB->start_delegated_transaction();

        $result = $this->rollback()->rollback_course($this->course->id);

        $transaction->allow_commit();
        $this->assertSame(rollback::REFUSE_TRANSACTION, $result['refusal']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame($before, $this->table_counts());
        $this->assertTrue((bool) $this->assign_map($ids['assign']));
    }

    /**
     * Test a rollback refuses while another import or rollback holds the course's lock.
     */
    public function test_a_held_lock_refuses(): void {
        $this->seed();
        $this->apply();
        $before = $this->table_counts();
        // A database lock is re-entrant within one connection, so "held elsewhere" needs a factory that says so.
        $held = new class ('mod_presenterai_migration') implements \core\lock\lock_factory {
            /** @var string[] Keys asked for. */
            public array $asked = [];

            /**
             * Construct.
             *
             * @param string $type The lock type.
             */
            public function __construct($type) {
            }

            /**
             * Timeout support.
             *
             * @return bool
             */
            public function supports_timeout() {
                return true;
            }

            /**
             * Auto release.
             *
             * @return bool
             */
            public function supports_auto_release() {
                return true;
            }

            /**
             * Availability.
             *
             * @return bool
             */
            public function is_available() {
                return true;
            }

            /**
             * Someone else holds every lock.
             *
             * @param string $resource The key.
             * @param int $timeout Seconds.
             * @param int $maxlifetime Seconds.
             * @return bool
             */
            public function get_lock($resource, $timeout, $maxlifetime = 86400) {
                $this->asked[] = $resource;
                return false;
            }

            /**
             * Release a lock.
             *
             * @param \core\lock\lock $lock The lock.
             * @return bool
             */
            public function release_lock(\core\lock\lock $lock) {
                return true;
            }
        };

        $result = (new rollback($this->source, $held))->rollback_course($this->course->id);

        $this->assertSame(['course_' . $this->course->id], $held->asked);
        $this->assertSame(rollback::REFUSE_LOCKED, $result['refusal']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame($before, $this->table_counts());
    }
}
