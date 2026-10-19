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
use mod_presenterai\local\migration\mapper;
use mod_presenterai\local\migration\plan;
use mod_presenterai\local\migration\runner;
use mod_presenterai\tests\fixtures\fake_source;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The Soapbox migration runner: dry run, apply, resume and refusals.
 *
 * SOLA is a test double that returns arrays (fake_source), so these run on a site without SOLA's tables. The
 * PresenterAI side is real: real tables, a real course with sections, a real mod_url stand-in. Every test that
 * plans or applies also checks SOLA's own tables are byte for byte unchanged when this site has them, which catches
 * a write a source scan can't see.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\runner
 * @covers     \mod_presenterai\local\migration\plan
 */
final class migration_runner_test extends \advanced_testcase {
    /** @var string A transcript no map payload may ever contain. */
    private const SECRET_TRANSCRIPT = 'ZEBRA-TRANSCRIPT-7731 my private words';

    /** @var string The stand-in URL path. */
    private const PRESENT = '/local/ai_course_assistant/soapbox_present.php';

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
     * A course with four sections, two learners, S3 settings that match SOLA's, stealth allowed, admin as the user.
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
        $CFG->allowstealth = 1;

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'format' => 'topics']);
        $this->alice = $gen->create_and_enrol($this->course, 'student');
        $this->bob = $gen->create_and_enrol($this->course, 'student');
        $this->source = new fake_source();

        if (sola_rows::available()) {
            // Real SOLA rows the fingerprint covers, so "unchanged" means something.
            $assign = sola_rows::assign($this->course->id);
            $score = sola_rows::score($this->course->id, $this->alice->id);
            sola_rows::rec($assign, $this->alice->id, ['scoreid' => $score, 'transcript' => self::SECRET_TRANSCRIPT]);
        }
    }

    /**
     * SOLA's tables, fingerprinted, or nothing on a site without them.
     *
     * Every test that plans or applies compares this before and after. On a site without SOLA's tables (CI) both
     * sides are empty and the comparison proves nothing; on a site with them it catches any SOLA write.
     *
     * @return array
     */
    private function sola_print(): array {
        return sola_rows::available() ? sola_rows::fingerprint() : [];
    }

    /**
     * Row counts of every table a run could touch.
     *
     * @return array Table to count.
     */
    private function table_counts(): array {
        global $DB;

        $counts = [];
        foreach ([
            'presenterai', 'presenterai_topic', 'presenterai_recording', 'presenterai_score', 'presenterai_migration_map',
            'presenterai_migreport', 'course_modules', 'course_sections', 'logstore_standard_log', 'url', 'grade_items',
            'context', 'files', 'event',
        ] as $table) {
            $counts[$table] = $DB->count_records($table);
        }
        $counts['cm_fields'] = $DB->get_records_menu('course_modules', ['course' => $this->course->id], 'id',
            'id, ' . $DB->sql_concat('visible', "'/'", 'visibleoncoursepage', "'/'", 'section'));
        $counts['urls'] = $DB->get_records_menu('url', ['course' => $this->course->id], 'id', 'id, externalurl');

        return $counts;
    }

    /**
     * A mod_url stand-in in the course pointing at one Soapbox assignment, and the reader row for it.
     *
     * @param int $assignid The assignment it points at.
     * @param int $section The section it sits in.
     * @param int|null $courseid The course, the test course when null.
     * @return array The reader row.
     */
    private function standin(int $assignid, int $section = 2, ?int $courseid = null): array {
        global $CFG;

        $courseid = $courseid ?? $this->course->id;
        $url = $CFG->wwwroot . self::PRESENT . '?id=' . $assignid;
        $mod = $this->getDataGenerator()->create_module('url',
            ['course' => $courseid, 'externalurl' => $url, 'section' => $section]);
        $row = ['cmid' => (int) $mod->cmid, 'assignid' => $assignid, 'externalurl' => $url, 'visible' => 1,
            'visibleoncoursepage' => 1];
        $this->source->standins[$courseid][] = $row;

        return $row;
    }

    /**
     * One assignment with two topics and a spread of recordings: a scored one, an expired one with a score, an
     * expired one without, a failed one and a plain uploaded one.
     *
     * @return array Ids: assign, topic1, topic2, and recordings by name.
     */
    private function seed(): array {
        $src = $this->source;
        $cid = $this->course->id;
        $assign = $src->add_assign($cid);
        $topic1 = $src->add_topic($assign);
        $topic2 = $src->add_topic($assign);
        $s1 = $src->add_score($cid, $this->alice->id, ['session_meta' => json_encode([
            'tips' => ['Slow down.'], 'visual_assessed' => true, 'visual_observation' => 'Leans on the desk.',
        ])]);
        $s2 = $src->add_score($cid, $this->alice->id, ['scores' => json_encode([
            ['name' => 'Delivery & Fluency', 'score' => 3, 'feedback' => 'Okay.'],
        ]), 'overall_score' => 3]);
        $ids = ['assign' => $assign, 'topic1' => $topic1, 'topic2' => $topic2];
        $ids['scored'] = $src->add_rec($assign, $this->alice->id, ['status' => 'scored', 'scoreid' => $s1,
            'topicid' => $topic1, 'transcript' => self::SECRET_TRANSCRIPT, 'timecreated' => 1100]);
        $ids['expired'] = $src->add_rec($assign, $this->alice->id, ['status' => 'deleted', 'scoreid' => $s2,
            'storage_key' => null, 'timecreated' => 1200, 'expires_at' => 3000]);
        $ids['expirednoscore'] = $src->add_rec($assign, $this->bob->id, ['status' => 'deleted', 'storage_key' => null,
            'timecreated' => 1300, 'expires_at' => 3100]);
        $ids['failed'] = $src->add_rec($assign, $this->bob->id, ['status' => 'failed', 'timecreated' => 1400]);
        $ids['uploaded'] = $src->add_rec($assign, $this->bob->id, ['status' => 'uploaded', 'topicid' => $topic2,
            'timecreated' => 1500]);

        return $ids;
    }

    /**
     * A lock factory double that records what it hands out and gives it back.
     *
     * @param bool $available False to say the lock is held by someone else.
     * @return \core\lock\lock_factory
     */
    private function lock_double(bool $available = true): \core\lock\lock_factory {
        return new class ('mod_presenterai_migration', $available) implements \core\lock\lock_factory {
            /** @var string[] Keys handed out. */
            public array $taken = [];
            /** @var string[] Keys given back. */
            public array $released = [];
            /** @var bool Whether a lock can be had. */
            private bool $available;

            /**
             * Construct.
             *
             * @param string $type The lock type.
             * @param bool $available Whether a lock can be had.
             */
            public function __construct($type, bool $available = true) {
                $this->available = $available;
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
             * Get a lock.
             *
             * @param string $resource The key.
             * @param int $timeout Seconds.
             * @param int $maxlifetime Seconds.
             * @return \core\lock\lock|false
             */
            public function get_lock($resource, $timeout, $maxlifetime = 86400) {
                if (!$this->available) {
                    return false;
                }
                $this->taken[] = $resource;
                return new \core\lock\lock($resource, $this);
            }

            /**
             * Release a lock.
             *
             * @param \core\lock\lock $lock The lock.
             * @return bool
             */
            public function release_lock(\core\lock\lock $lock) {
                $this->released[] = $lock->get_key();
                return true;
            }
        };
    }

    /**
     * The runner under test.
     *
     * @param \core\lock\lock_factory|null $locks A lock double, a fresh one when null.
     * @return runner
     */
    private function runner(?\core\lock\lock_factory $locks = null): runner {
        return new runner($this->source, $locks ?? $this->lock_double());
    }

    /**
     * The map row of one source row.
     *
     * @param string $table An ids::SOURCE_* constant.
     * @param int $sourceid The source id.
     * @return \stdClass|false
     */
    private function map(string $table, int $sourceid) {
        global $DB;

        return $DB->get_record('presenterai_migration_map',
            ['sourcetable' => $table, 'sourceid' => $sourceid, 'sourcesiteid' => ids::site_id()]);
    }

    /**
     * The single assignment entry of a plan.
     *
     * @param plan $plan The plan.
     * @param int $assignid The source assignment.
     * @return array
     */
    private function entry(plan $plan, int $assignid): array {
        $this->assertArrayHasKey($assignid, $plan->assignments);

        return $plan->assignments[$assignid];
    }

    /**
     * Test the dry run reads everything, plans the work, and writes nothing and fires nothing.
     */
    public function test_the_dry_run_writes_nothing(): void {
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $before = $this->table_counts();
        $sola = $this->sola_print();
        $sink = $this->redirectEvents();
        $messages = $this->redirectMessages();

        $plan = $this->runner()->plan_course($this->course->id);

        $this->assertSame(0, $sink->count());
        $this->assertSame(0, $messages->count());
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
        $this->assertFalse($plan->applied);
        $this->assertTrue($plan->can_proceed());
        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::CREATE, $entry['decision']);
        $this->assertSame(plan::STANDIN_REWRITE, $entry['standin']);
        $this->assertSame(2, $entry['counts']['topics']);
        $this->assertSame(5, $entry['counts']['recordings']);
        $this->assertSame(['abandoned' => 1, 'failed' => 1, 'scored' => 2, 'uploaded' => 1], $entry['counts']['bystatus']);
        $this->assertSame(2, $entry['counts']['scores']);
        $this->assertSame(2, $entry['counts']['learners']);
        $totals = $plan->counts();
        $this->assertSame(1, $totals['assignments']);
        $this->assertSame(2, $totals['learners']);
        $this->assertSame(0, $totals['orphans']);
    }

    /**
     * Test the dry run takes no lock, which would itself be a write.
     */
    public function test_the_dry_run_takes_no_lock(): void {
        $this->seed();
        $locks = $this->lock_double();
        $sola = $this->sola_print();

        $this->runner($locks)->plan_course($this->course->id);

        $this->assertSame([], $locks->taken);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test apply creates the activity, topics, attempts, scores and map rows, with no event, message or grade.
     */
    public function test_apply_creates_everything_quietly(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $ids = $this->seed();
        // A real course already has its gradebook. On a course with none, core's create_module() makes the empty
        // course total item for any activity that supports grades, even at grade 0; that moves no learner's total.
        \grade_item::fetch_course_item($this->course->id);
        $gradeitems = $DB->count_records('grade_items', ['courseid' => $this->course->id]);
        $grades = $DB->count_records('grade_grades');
        $sola = $this->sola_print();
        $sink = $this->redirectEvents();
        $messages = $this->redirectMessages();

        $plan = $this->runner()->apply_course($this->course->id);

        $own = array_filter($sink->get_events(), static fn($e) => str_starts_with(get_class($e), 'mod_presenterai\\event\\'));
        $this->assertSame([], array_values($own));
        $this->assertSame(0, $messages->count());
        $this->assertSame($gradeitems, $DB->count_records('grade_items', ['courseid' => $this->course->id]));
        $this->assertSame($grades, $DB->count_records('grade_grades'));
        $this->assertFalse($DB->record_exists('grade_items', ['itemmodule' => 'presenterai']));
        $this->assertSame($sola, $this->sola_print());
        $this->assertTrue($plan->applied);
        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $ids['assign'])['outcome']);

        $instance = $DB->get_record('presenterai', ['legacyassignid' => $ids['assign']], '*', MUST_EXIST);
        $this->assertSame(0, (int) $instance->grade);
        $this->assertSame(-1, (int) $instance->retentiondays);
        $this->assertSame((int) $this->course->id, (int) $instance->course);
        $this->assertSame('persuasive', $instance->ptype);
        $this->assertSame(3, (int) $instance->maxattempts);

        $topics = $DB->get_records('presenterai_topic', ['presenteraiid' => $instance->id], 'sortorder');
        $this->assertSame([$ids['topic1'], $ids['topic2']], array_values(array_map(fn($t) => (int) $t->legacytopicid, $topics)));

        $recs = [];
        foreach ($DB->get_records('presenterai_recording', ['presenteraiid' => $instance->id]) as $row) {
            $recs[(int) $row->legacyrecid] = $row;
        }
        $this->assertCount(5, $recs);
        $numbers = mapper::attempt_numbers($this->source->recs[$ids['assign']]);
        foreach ($this->source->recs[$ids['assign']] as $src) {
            $rec = $recs[$src['id']];
            $this->assertSame($numbers[$src['id']], (int) $rec->attemptnumber, 'attempt of ' . $src['id']);
            $this->assertSame(mapper::recording_status($src, !empty($src['scoreid'])), $rec->status, 'status of ' . $src['id']);
            $this->assertSame((int) $src['userid'], (int) $rec->userid);
        }
        $topicids = array_flip(array_map(fn($t) => (int) $t->legacytopicid, $topics));
        $this->assertSame((int) $topicids[$ids['topic1']], (int) $recs[$ids['scored']]->topicid);
        $this->assertNull($recs[$ids['failed']]->topicid);

        $scores = $DB->get_records('presenterai_score', null, 'id');
        $this->assertCount(2, $scores);
        foreach ($scores as $score) {
            $pointer = $DB->get_field('presenterai_recording', 'scoreid', ['id' => $score->recordingid]);
            $this->assertSame((int) $score->id, (int) $pointer);
        }
        $this->assertStringNotContainsString('visual_observation', (string) $DB->get_field('presenterai_score', 'legacymeta',
            ['recordingid' => $recs[$ids['scored']]->id]));

        $maps = $DB->get_records('presenterai_migration_map');
        $this->assertCount(1 + 2 + 5, $maps);
        foreach ($maps as $map) {
            $this->assertSame(runner::STATE_VERIFIED, $map->state);
            $this->assertSame(ids::site_id(), $map->sourcesiteid);
            $this->assertSame($plan->runid, $map->runid);
        }
        $assignmap = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        $this->assertSame((int) $instance->id, (int) $assignmap->targetid);
        $cm = get_coursemodule_from_instance('presenterai', $instance->id);
        $this->assertSame((int) $cm->id, (int) $assignmap->targetcmid);
        $this->assertSame((int) $this->map(ids::SOURCE_TOPIC, $ids['topic2'])->targetid,
            (int) $DB->get_field('presenterai_topic', 'id', ['legacytopicid' => $ids['topic2']]));
        $this->assertSame((int) $recs[$ids['failed']]->id, (int) $this->map(ids::SOURCE_REC, $ids['failed'])->targetid);
    }

    /**
     * Test an attempt whose video SOLA deleted becomes a scored attempt with no keys and a deletion record.
     */
    public function test_a_deleted_source_row_becomes_a_scored_attempt_with_null_keys(): void {
        global $DB;
        $ids = $this->seed();
        $sola = $this->sola_print();

        $this->runner()->apply_course($this->course->id);

        $rec = $DB->get_record('presenterai_recording', ['legacyrecid' => $ids['expired']], '*', MUST_EXIST);
        $this->assertSame('scored', $rec->status);
        $this->assertNull($rec->storagekey);
        $this->assertNull($rec->deckkey);
        $this->assertNull($rec->frameskey);
        $this->assertSame('retention', $rec->mediagonereason);
        $this->assertSame(3000, (int) $rec->mediadeletedat);
        $this->assertSame(3000, (int) $rec->expiresat);
        $this->assertNotEmpty($rec->scoreid);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test media SOLA pruned before its expiry never reads "deleted on" a future date, while expiresat stays verbatim.
     */
    public function test_a_future_deletion_date_is_clamped_to_the_migration_time(): void {
        global $DB;
        $ids = $this->seed();
        $future = time() + 5 * DAYSECS;
        $this->source->set_rec($ids['expired'], ['expires_at' => $future]);
        $sola = $this->sola_print();

        $start = time();
        $this->runner()->apply_course($this->course->id);
        $end = time();

        $rec = $DB->get_record('presenterai_recording', ['legacyrecid' => $ids['expired']], '*', MUST_EXIST);
        $this->assertGreaterThanOrEqual($start, (int) $rec->mediadeletedat);
        $this->assertLessThanOrEqual($end, (int) $rec->mediadeletedat);
        $this->assertSame($future, (int) $rec->expiresat);
        $past = $DB->get_record('presenterai_recording', ['legacyrecid' => $ids['expirednoscore']], '*', MUST_EXIST);
        $this->assertSame(3100, (int) $past->mediadeletedat);
        $live = $DB->get_record('presenterai_recording', ['legacyrecid' => $ids['uploaded']], '*', MUST_EXIST);
        $this->assertSame(0, (int) $live->mediadeletedat);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * The speaking levels SOLA may hold and what the activity gets.
     *
     * @return array
     */
    public static function level_provider(): array {
        return [
            'absent' => [null, null, false],
            'empty' => ['', null, false],
            'general' => ['general', null, false],
            'esl beginner' => ['esl_beginner', 'esl_beginner', false],
            'esl intermediate' => ['esl_intermediate', 'esl_intermediate', false],
            'esl advanced, odd case and spaces' => [' ESL_Advanced ', 'esl_advanced', false],
            'unmappable' => ['klingon', null, true],
        ];
    }

    /**
     * Test the activity's speaking level comes from SOLA's course setting, general when absent or unmappable.
     *
     * @dataProvider level_provider
     * @param string|null $sola SOLA's value, null for none.
     * @param string|null $expected The activity's speakinglevel column.
     * @param bool $noted Whether the plan notes an unmappable value.
     */
    public function test_the_speaking_level_comes_from_the_course(?string $sola, ?string $expected, bool $noted): void {
        global $DB;
        $ids = $this->seed();
        if ($sola !== null) {
            $this->source->levels[$this->course->id] = $sola;
        }
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $level = $DB->get_field('presenterai', 'speakinglevel', ['legacyassignid' => $ids['assign']]);
        $this->assertSame($expected, $level === '' ? null : $level);
        $codes = array_column($plan->notes, 'code');
        $this->assertSame($noted, in_array('unmappablelevel', $codes, true));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test applying twice changes nothing the second time and says each assignment is already done.
     */
    public function test_apply_twice_is_a_no_op(): void {
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $this->runner()->apply_course($this->course->id);
        $before = $this->table_counts();
        $sola = $this->sola_print();
        $sink = $this->redirectEvents();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::SKIP_VERIFIED, $this->entry($plan, $ids['assign'])['decision']);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame(0, $sink->count());
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * A runner that fails at a chosen point, once.
     *
     * @param string $where 'topics' to fail after the topics commit, 'recordings' inside the recordings step,
     *        'runtime' to throw a plain RuntimeException carrying a secret-looking message after the topics.
     * @param int|null $only Fail only for this source assignment, every one when null.
     * @param \core\lock\lock_factory|null $locks A lock double, a fresh one when null.
     * @return runner
     */
    private function failing_runner(string $where, ?int $only = null, ?\core\lock\lock_factory $locks = null): runner {
        return new class ($this->source, $locks ?? $this->lock_double(), $where, $only) extends runner {
            /** @var string Where to fail. */
            private string $where;
            /** @var int|null Which assignment. */
            private ?int $only;

            /**
             * Construct.
             *
             * @param \mod_presenterai\local\migration\source_reader $reader The source.
             * @param \core\lock\lock_factory $locks Locks.
             * @param string $where Where to fail.
             * @param int|null $only Which assignment.
             */
            public function __construct($reader, $locks, string $where, ?int $only) {
                parent::__construct($reader, $locks);
                $this->where = $where;
                $this->only = $only;
            }

            /**
             * Fail after the topics.
             *
             * @param int $sourceid The source assignment.
             */
            protected function after_topics(int $sourceid): void {
                if ($this->only !== null && $this->only !== $sourceid) {
                    return;
                }
                if ($this->where === 'topics') {
                    throw new \moodle_exception('error', 'error', '', null, 'forced after topics');
                }
                if ($this->where === 'runtime') {
                    throw new \RuntimeException('token sk-SECRET-4242 leaked');
                }
            }

            /**
             * Fail inside the recordings step, after their rows and map rows are written.
             *
             * @param int $sourceid The source assignment.
             */
            protected function after_recordings(int $sourceid): void {
                if ($this->where === 'recordings' && ($this->only === null || $this->only === $sourceid)) {
                    throw new \moodle_exception('error', 'error', '', null, 'forced after recordings');
                }
            }
        };
    }

    /**
     * Test an assignment interrupted between its topics and its recordings resumes without duplicating anything.
     */
    public function test_an_interrupted_assignment_resumes(): void {
        global $DB;
        $ids = $this->seed();
        $sola = $this->sola_print();

        $plan = $this->failing_runner('topics')->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_FAILED, $this->entry($plan, $ids['assign'])['outcome']);
        $map = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        $this->assertContains($map->state, [runner::STATE_CREATED, runner::STATE_ATTACHED]);
        $this->assertSame(1, $DB->count_records('presenterai'));
        $this->assertSame(2, $DB->count_records('presenterai_topic'));
        $this->assertSame(0, $DB->count_records('presenterai_recording'));

        $replan = $this->runner()->plan_course($this->course->id);
        $this->assertSame(plan::RESUME, $this->entry($replan, $ids['assign'])['decision']);
        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $ids['assign'])['outcome']);
        $this->assertSame(1, $DB->count_records('presenterai'));
        $this->assertSame(1, $DB->count_records('course_modules', ['instance' => $map->targetid,
            'module' => $DB->get_field('modules', 'id', ['name' => 'presenterai'])]));
        $this->assertSame(2, $DB->count_records('presenterai_topic'));
        $this->assertSame(5, $DB->count_records('presenterai_recording'));
        $this->assertSame(runner::STATE_VERIFIED, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);
        $this->assertSame((int) $map->targetid, (int) $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->targetid);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a failure inside the recordings step leaves no recording, score or recording map row behind.
     */
    public function test_a_failure_mid_assignment_leaves_no_recording_map_row(): void {
        global $DB;
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $sola = $this->sola_print();
        $urls = $DB->get_records_menu('url', null, 'id', 'id, externalurl');

        $plan = $this->failing_runner('recordings')->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_FAILED, $this->entry($plan, $ids['assign'])['outcome']);
        $this->assertSame(0, $DB->count_records('presenterai_migration_map', ['sourcetable' => ids::SOURCE_REC]));
        $this->assertSame(0, $DB->count_records('presenterai_recording'));
        $this->assertSame(0, $DB->count_records('presenterai_score'));
        $this->assertNull($this->map(ids::SOURCE_ASSIGN, $ids['assign'])->payload);
        $this->assertSame($urls, $DB->get_records_menu('url', null, 'id', 'id, externalurl'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test one assignment failing doesn't roll back another: the second still completes.
     */
    public function test_a_failed_assignment_does_not_roll_back_another(): void {
        global $DB;
        $first = $this->seed()['assign'];
        $second = $this->source->add_assign($this->course->id);
        $this->source->add_topic($second);
        $this->source->add_rec($second, $this->bob->id);
        $sola = $this->sola_print();

        $plan = $this->failing_runner('recordings', $first)->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_FAILED, $this->entry($plan, $first)['outcome']);
        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $second)['outcome']);
        $this->assertSame(runner::STATE_VERIFIED, $this->map(ids::SOURCE_ASSIGN, $second)->state);
        $instance = $DB->get_field('presenterai', 'id', ['legacyassignid' => $second]);
        $this->assertSame(1, $DB->count_records('presenterai_recording', ['presenteraiid' => $instance]));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Assert an apply refused before writing anything, and return the plan.
     *
     * @return plan
     */
    private function assert_apply_writes_nothing(): plan {
        $before = $this->table_counts();
        $sola = $this->sola_print();
        $sink = $this->redirectEvents();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame($before, $this->table_counts());
        $this->assertSame(0, $sink->count());
        $this->assertSame($sola, $this->sola_print());
        $this->assertFalse($plan->can_proceed());

        return $plan;
    }

    /**
     * Test a site without SOLA's tables gets "no Soapbox tables" and the reader is asked nothing else.
     */
    public function test_no_source_tables_does_nothing(): void {
        $this->seed();
        $this->source->tables = false;
        $this->source->calls = [];

        $plan = $this->assert_apply_writes_nothing();

        $this->assertSame(['tables_present'], array_values(array_unique($this->source->calls)));
        $this->assertSame(['sourcetables'], array_column($this->runner()->preconditions(), 'code'));
        $this->assertSame([], $plan->assignments);
        $this->assertStringContainsString('No Soapbox tables', $plan->unmet()[0]['message']);
    }

    /**
     * Test S3 settings that differ refuse, naming both sides' bucket, region and prefix and no key.
     */
    public function test_different_storage_settings_refuse(): void {
        $this->seed();
        set_config('s3prefix', 'presenterai', 'mod_presenterai');
        set_config('s3key', 'AKIASECRETKEY', 'mod_presenterai');

        $plan = $this->assert_apply_writes_nothing();

        $unmet = $plan->unmet();
        $this->assertSame(['storage'], array_column($unmet, 'code'));
        $this->assertStringContainsString('soapbox/', $unmet[0]['message']);
        $this->assertStringContainsString('presenterai/', $unmet[0]['message']);
        $this->assertStringContainsString('archive-course', $unmet[0]['message']);
        $this->assertStringContainsString('us-east-1', $unmet[0]['message']);
        $this->assertStringNotContainsString('AKIASECRETKEY', $unmet[0]['message']);
    }

    /**
     * Storage settings that differ, one field at a time.
     *
     * @return array
     */
    public static function storage_provider(): array {
        return [
            'bucket' => [['s3bucket' => 'other-bucket'], false],
            'region' => [['s3region' => 'eu-west-1'], false],
            'prefix unset defaults to presenterai/' => [['s3prefix' => ''], false],
            'region unset defaults to us-east-1' => [['s3region' => ''], true],
            'prefix with slashes is the same prefix' => [['s3prefix' => '/soapbox/'], true],
        ];
    }

    /**
     * Test each storage field is compared after the same normalisation the store applies.
     *
     * @dataProvider storage_provider
     * @param array $settings PresenterAI settings to set.
     * @param bool $met Whether the storage precondition is met.
     */
    public function test_storage_fields_are_compared_normalised(array $settings, bool $met): void {
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'mod_presenterai');
        }
        $sola = $this->sola_print();

        $codes = array_column($this->runner()->preconditions(), 'code');

        $this->assertSame(!$met, in_array('storage', $codes, true));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a filesystem backend refuses (D10).
     */
    public function test_a_filesystem_backend_refuses(): void {
        $this->seed();
        set_config('backend', 'fs', 'mod_presenterai');

        $plan = $this->assert_apply_writes_nothing();

        $this->assertContains('backend', array_column($plan->unmet(), 'code'));
    }

    /**
     * Test a SOLA without the interlock refuses.
     */
    public function test_no_interlock_refuses(): void {
        $this->seed();
        $this->source->interlock = false;

        $plan = $this->assert_apply_writes_nothing();

        $this->assertSame(['interlock'], array_column($plan->unmet(), 'code'));
    }

    /**
     * Test queued scoring tasks refuse.
     */
    public function test_queued_scoring_tasks_refuse(): void {
        $this->seed();
        $this->source->queued = 2;

        $plan = $this->assert_apply_writes_nothing();

        $this->assertSame(['scoringqueued'], array_column($plan->unmet(), 'code'));
    }

    /**
     * Test two recordings sharing a score refuse the whole course.
     */
    public function test_a_shared_score_refuses_the_course(): void {
        $this->seed();
        $this->source->shared[$this->course->id] = [123];

        $plan = $this->assert_apply_writes_nothing();

        $this->assertSame(['sharedscore'], array_column($plan->refusals, 'code'));
    }

    /**
     * Test a course that no longer exists is refused for that course, not a crash.
     */
    public function test_a_course_that_is_gone_is_refused(): void {
        $this->source->add_assign(987654);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course(987654);

        $this->assertSame(['nocourse'], array_column($plan->refusals, 'code'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a user who can't add activities is refused before anything is written.
     */
    public function test_a_user_without_the_capability_is_refused(): void {
        $this->seed();
        $this->setUser($this->alice);

        $plan = $this->assert_apply_writes_nothing();

        $this->assertSame(['capability'], array_column($plan->refusals, 'code'));
    }

    /**
     * Test orphan scores are reported as unowned speech scores and stop nothing.
     */
    public function test_orphans_are_reported_and_stop_nothing(): void {
        global $DB;
        $ids = $this->seed();
        $this->source->orphans[$this->course->id] = 28;
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame($sola, $this->sola_print());
        $this->assertSame(28, $plan->counts()['orphans']);
        $notes = array_column($plan->notes, 'message', 'code');
        $this->assertStringContainsString('unowned speech scores (includes freeform practice)', $notes['orphans']);
        $this->assertTrue($DB->record_exists('presenterai', ['legacyassignid' => $ids['assign']]));
    }

    /**
     * Test a source assignment edited after it was first imported is refused, not resumed.
     */
    public function test_a_stale_source_refuses_rather_than_resumes(): void {
        global $DB;
        $ids = $this->seed();
        $this->failing_runner('topics')->apply_course($this->course->id);
        $state = $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state;
        $this->source->set_assign($ids['assign'], ['timemodified' => time() + 600]);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::REFUSE, $entry['decision']);
        $this->assertSame('stale', $entry['code']);
        $this->assertSame(0, $DB->count_records('presenterai_recording'));
        $this->assertSame($state, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a created activity a teacher deleted moves the entry to failed and isn't created again.
     */
    public function test_a_vanished_target_is_failed_not_recreated(): void {
        global $DB;
        $ids = $this->seed();
        $this->failing_runner('topics')->apply_course($this->course->id);
        $map = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        course_delete_module((int) $map->targetcmid);
        $dry = $this->runner()->plan_course($this->course->id);
        $this->assertSame('targetgone', $this->entry($dry, $ids['assign'])['code']);
        $this->assertSame($map->state, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame('targetgone', $this->entry($plan, $ids['assign'])['code']);
        $this->assertSame(runner::STATE_FAILED, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);
        $this->assertSame(0, $DB->count_records('presenterai'));
        $again = $this->runner()->apply_course($this->course->id);
        $this->assertSame(plan::REFUSE, $this->entry($again, $ids['assign'])['decision']);
        $this->assertSame(0, $DB->count_records('presenterai'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test two stand-ins for one assignment refuse that assignment.
     */
    public function test_two_standins_refuse_the_assignment(): void {
        global $DB;
        $ids = $this->seed();
        $this->standin($ids['assign'], 1);
        $this->standin($ids['assign'], 2);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::REFUSE, $entry['decision']);
        $this->assertSame(plan::STANDIN_DUPLICATE, $entry['standin']);
        $this->assertSame(0, $DB->count_records('presenterai'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a stand-in that points at another course's assignment is ignored.
     */
    public function test_a_standin_for_another_courses_assignment_is_ignored(): void {
        $ids = $this->seed();
        $this->standin(424242, 1);
        $sola = $this->sola_print();

        $plan = $this->runner()->plan_course($this->course->id);

        $this->assertSame($sola, $this->sola_print());
        $this->assertSame(plan::STANDIN_NONE, $this->entry($plan, $ids['assign'])['standin']);
    }

    /**
     * Test the stand-in's original URL and visibility are in the map payload before it's rewritten, and it then
     * points at the new activity and is hidden from the course page only.
     */
    public function test_the_standin_is_recorded_before_it_is_rewritten(): void {
        global $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign'], 2);
        $sola = $this->sola_print();
        $runner = new class ($this->source, $this->lock_double()) extends runner {
            /** @var array|null The payload the map held when the rewrite began. */
            public ?array $seen = null;

            /**
             * Look at the map before rewriting.
             *
             * @param int $mapid The map row.
             * @param int $standincmid The stand-in.
             * @param int $cmid The new activity.
             * @param bool $hide Whether to hide it from the course page.
             */
            protected function rewrite_standin(int $mapid, int $standincmid, int $cmid, bool $hide): void {
                global $DB;
                $payload = $DB->get_field('presenterai_migration_map', 'payload', ['id' => $mapid]);
                $this->seen = $payload === null ? [] : json_decode($payload, true);
                parent::rewrite_standin($mapid, $standincmid, $cmid, $hide);
            }
        };

        $plan = $runner->apply_course($this->course->id);

        $expected = [runner::PAYLOAD_STANDIN => ['cmid' => $standin['cmid'], 'externalurl' => $standin['externalurl'],
            'visible' => 1, 'visibleoncoursepage' => 1]];
        $this->assertSame($expected, $runner->seen);
        $map = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        $this->assertSame($expected, json_decode($map->payload, true));
        $cm = $DB->get_record('course_modules', ['id' => $standin['cmid']]);
        $this->assertSame(1, (int) $cm->visible);
        $this->assertSame(0, (int) $cm->visibleoncoursepage);
        $url = $DB->get_field('url', 'externalurl', ['id' => $cm->instance]);
        $this->assertSame((new \moodle_url('/mod/presenterai/view.php', ['id' => $map->targetcmid]))->out(false), $url);
        $this->assertSame(plan::STANDIN_REWRITE, $this->entry($plan, $ids['assign'])['standin']);
        $newcm = $DB->get_record('course_modules', ['id' => $map->targetcmid]);
        $this->assertSame((int) $cm->section, (int) $newcm->section);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test with stealth off the stand-in stays fully visible and the plan says so.
     */
    public function test_without_stealth_the_standin_stays_visible(): void {
        global $CFG, $DB;
        $ids = $this->seed();
        $standin = $this->standin($ids['assign'], 2);
        $CFG->allowstealth = 0;
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::STANDIN_VISIBLE, $entry['standin']);
        $this->assertStringContainsString('stays visible', $entry['standinmessage']);
        $cm = $DB->get_record('course_modules', ['id' => $standin['cmid']]);
        $this->assertSame(1, (int) $cm->visible);
        $this->assertSame(1, (int) $cm->visibleoncoursepage);
        $payload = json_decode($this->map(ids::SOURCE_ASSIGN, $ids['assign'])->payload, true);
        $this->assertSame($standin['externalurl'], $payload[runner::PAYLOAD_STANDIN]['externalurl']);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test with no stand-in the activity lands in the last section and no section is created.
     */
    public function test_with_no_standin_the_activity_lands_in_the_last_section(): void {
        global $DB;
        $ids = $this->seed();
        $sections = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $last = (int) $DB->get_field('course_sections', 'MAX(section)', ['course' => $this->course->id]);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::STANDIN_NONE, $this->entry($plan, $ids['assign'])['standin']);
        $this->assertSame($sections, $DB->count_records('course_sections', ['course' => $this->course->id]));
        $cmid = $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->targetcmid;
        $sectionid = $DB->get_field('course_modules', 'section', ['id' => $cmid]);
        $this->assertSame($last, (int) $DB->get_field('course_sections', 'section', ['id' => $sectionid]));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a per-course lock is taken and given back, and a held lock refuses the course.
     */
    public function test_a_per_course_lock_is_taken_and_released(): void {
        global $DB;
        $this->seed();
        $locks = $this->lock_double();
        $sola = $this->sola_print();

        $this->runner($locks)->apply_course($this->course->id);

        $this->assertSame($sola, $this->sola_print());
        $this->assertSame(['course_' . $this->course->id], $locks->taken);
        $this->assertSame($locks->taken, $locks->released);

        $held = $this->lock_double(false);
        $this->source->add_assign($this->course->id);
        $count = $DB->count_records('presenterai');
        $plan = $this->runner($held)->apply_course($this->course->id);
        $this->assertSame(['locked'], array_column($plan->refusals, 'code'));
        $this->assertSame($count, $DB->count_records('presenterai'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test the lock is released even when an assignment fails.
     */
    public function test_the_lock_is_released_after_a_failure(): void {
        $this->seed();
        $locks = $this->lock_double();
        $sola = $this->sola_print();

        $this->failing_runner('topics', null, $locks)->apply_course($this->course->id);

        $this->assertSame(['course_' . $this->course->id], $locks->released);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test no map payload holds learner text: no transcript, no name.
     */
    public function test_no_learner_text_in_any_payload(): void {
        global $DB;
        $ids = $this->seed();
        $this->standin($ids['assign']);
        $sola = $this->sola_print();

        $this->runner()->apply_course($this->course->id);

        $this->assertSame($sola, $this->sola_print());
        $all = implode('|', array_map(fn($m) => (string) $m->payload, $DB->get_records('presenterai_migration_map')));
        $this->assertStringContainsString(runner::PAYLOAD_STANDIN, $all);
        foreach ([self::SECRET_TRANSCRIPT, 'ZEBRA', $this->alice->firstname, $this->alice->lastname, 'Slow down',
                'A good talk', 'Leans on the desk'] as $text) {
            $this->assertStringNotContainsString($text, $all);
        }
    }

    /**
     * The visibility of the activity an assignment's map row points at.
     *
     * @param int $assignid The source assignment.
     * @return array [visible, visibleold].
     */
    private function target_visibility(int $assignid): array {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $this->map(ids::SOURCE_ASSIGN, $assignid)->targetcmid], '*', MUST_EXIST);

        return [(int) $cm->visible, (int) $cm->visibleold];
    }

    /**
     * Test the activity stays hidden while its import is unfinished and takes the source's visibility at the end.
     */
    public function test_the_activity_is_hidden_until_the_import_finishes(): void {
        $ids = $this->seed();
        $sola = $this->sola_print();

        $this->failing_runner('topics')->apply_course($this->course->id);
        $this->assertSame([0, 0], $this->target_visibility($ids['assign']));

        $this->failing_runner('recordings')->apply_course($this->course->id);
        $this->assertSame([0, 0], $this->target_visibility($ids['assign']));
        $this->assertSame(runner::STATE_ATTACHED, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $ids['assign'])['outcome']);
        $this->assertSame([1, 1], $this->target_visibility($ids['assign']));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a source assignment that was hidden stays hidden after the import finishes.
     */
    public function test_a_hidden_source_assignment_stays_hidden(): void {
        $ids = $this->seed();
        $this->source->set_assign($ids['assign'], ['visible' => 0]);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $ids['assign'])['outcome']);
        $this->assertSame([0, 0], $this->target_visibility($ids['assign']));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Ways an unfinished activity can have been used.
     *
     * @return array
     */
    public static function used_provider(): array {
        return ['a learner made an attempt' => ['attempt'], 'grading was turned on' => ['grade']];
    }

    /**
     * Test resuming into an activity that has been used since is refused and writes nothing.
     *
     * @dataProvider used_provider
     * @param string $how How it was used.
     */
    public function test_a_used_activity_is_not_resumed(string $how): void {
        global $DB;
        $ids = $this->seed();
        $this->failing_runner('topics')->apply_course($this->course->id);
        $map = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        if ($how === 'attempt') {
            $row = mapper::recording_to_row(['id' => 0, 'userid' => $this->bob->id, 'status' => 'uploaded',
                'timecreated' => time()], (int) $map->targetid, null, 1, false);
            $DB->insert_record('presenterai_recording', (object) $row);
        } else {
            $DB->set_field('presenterai', 'grade', 100, ['id' => $map->targetid]);
        }
        $before = $this->table_counts();
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::REFUSE, $entry['decision']);
        $this->assertSame('targetused', $entry['code']);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame(runner::STATE_ATTACHED, $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->state);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a verified assignment reports the recordings SOLA gained and scored since, imports neither, writes nothing.
     */
    public function test_a_verified_assignment_reports_what_sola_gained(): void {
        $ids = $this->seed();
        $this->runner()->apply_course($this->course->id);
        $this->source->add_rec($ids['assign'], $this->bob->id);
        $score = $this->source->add_score($this->course->id, $this->bob->id);
        $this->source->set_rec($ids['uploaded'], ['scoreid' => $score, 'status' => 'scored']);
        $before = $this->table_counts();
        $sola = $this->sola_print();

        $dry = $this->runner()->plan_course($this->course->id);
        $plan = $this->runner()->apply_course($this->course->id);

        foreach ([$dry, $plan] as $result) {
            $entry = $this->entry($result, $ids['assign']);
            $this->assertSame(plan::SKIP_VERIFIED, $entry['decision']);
            $this->assertSame(1, $entry['new_source_rows']);
            $this->assertSame(1, $entry['newly_scored_rows']);
            $this->assertStringContainsString('Soapbox recordings added after this assignment was imported (not imported): 1.',
                $entry['message']);
            $this->assertStringContainsString('Soapbox recordings scored after the import (scores not imported): 1.',
                $entry['message']);
            $this->assertSame(1, $result->counts()['new_source_rows']);
        }
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a verified assignment SOLA hasn't changed reports nothing gained.
     */
    public function test_an_unchanged_verified_assignment_reports_nothing(): void {
        $ids = $this->seed();
        $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($this->runner()->plan_course($this->course->id), $ids['assign']);

        $this->assertSame([0, 0, ''], [$entry['new_source_rows'], $entry['newly_scored_rows'], $entry['message']]);
    }

    /**
     * Attempt caps as the reader hands them over (already the effective cap).
     *
     * @return array
     */
    public static function cap_provider(): array {
        return ['unlimited' => [0], 'capped' => [5]];
    }

    /**
     * Test the attempt cap arrives as the effective cap and is copied as it stands.
     *
     * @dataProvider cap_provider
     * @param int $cap The cap.
     */
    public function test_the_attempt_cap_is_copied(int $cap): void {
        global $DB;
        $assign = $this->source->add_assign($this->course->id, ['max_attempts' => $cap]);
        $sola = $this->sola_print();

        $this->runner()->apply_course($this->course->id);

        $this->assertSame($cap, (int) $DB->get_field('presenterai', 'maxattempts', ['legacyassignid' => $assign]));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a recording whose score row is gone (the reader hands it over with scoreid null) is an unscored attempt.
     */
    public function test_a_recording_whose_score_row_is_gone_is_imported_unscored(): void {
        global $DB;
        $assign = $this->source->add_assign($this->course->id);
        $rec = $this->source->add_rec($assign, $this->alice->id, ['status' => 'scored', 'scoreid' => 777777]);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $assign)['outcome']);
        $row = $DB->get_record('presenterai_recording', ['legacyrecid' => $rec], '*', MUST_EXIST);
        $this->assertSame(mapper::recording_status(['status' => 'scored'], false), $row->status);
        $this->assertSame(0, (int) $row->legacyscoreid);
        $this->assertNull($row->scoreid);
        $this->assertSame(0, $DB->count_records('presenterai_score'));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Assert an apply refused one assignment with a code and wrote nothing.
     *
     * @param int $assignid The assignment.
     * @param string $code The refusal code.
     */
    private function assert_assignment_refused_without_writes(int $assignid, string $code): void {
        $before = $this->table_counts();
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $assignid);
        $this->assertSame(plan::REFUSE, $entry['decision']);
        $this->assertSame($code, $entry['code']);
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a score that vanishes between the reader's two reads refuses the assignment (the defensive check).
     */
    public function test_a_score_that_vanishes_refuses_the_assignment(): void {
        $ids = $this->seed();
        $this->source->vanishing = [(int) $this->source->recs[$ids['assign']][0]['scoreid']];

        $this->assert_assignment_refused_without_writes($ids['assign'], 'scoremissing');
    }

    /**
     * Test a recording status SOLA doesn't have refuses the assignment.
     */
    public function test_an_unknown_status_refuses_the_assignment(): void {
        $ids = $this->seed();
        $this->source->set_rec($ids['failed'], ['status' => 'bogus']);

        $this->assert_assignment_refused_without_writes($ids['assign'], 'badstatus');
    }

    /**
     * Test an apply called inside a database transaction refuses before writing anything.
     */
    public function test_apply_inside_a_transaction_refuses(): void {
        global $DB;
        $this->seed();
        $before = $this->table_counts();
        $sola = $this->sola_print();

        $transaction = $DB->start_delegated_transaction();
        $plan = $this->runner()->apply_course($this->course->id);
        $transaction->allow_commit();

        $this->assertSame(['transaction'], array_column($plan->unmet(), 'code'));
        $this->assertFalse($plan->can_proceed());
        $this->assertSame($before, $this->table_counts());
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Ways a topic can change between attach and resume.
     *
     * @return array
     */
    public static function topic_change_provider(): array {
        return ['a topic was edited' => ['edited'], 'an attached topic was removed' => ['removed']];
    }

    /**
     * Test a topic that changed after the attach makes the resume stale.
     *
     * @dataProvider topic_change_provider
     * @param string $change What happened to the topic.
     */
    public function test_a_topic_change_makes_the_resume_stale(string $change): void {
        $ids = $this->seed();
        $this->failing_runner('topics')->apply_course($this->course->id);
        if ($change === 'edited') {
            $this->source->topics[$ids['assign']][1]['timemodified'] = time() + 600;
        } else {
            array_pop($this->source->topics[$ids['assign']]);
        }

        $this->assert_assignment_refused_without_writes($ids['assign'], 'stale');
    }

    /**
     * Test a stand-in that isn't on the course page is left alone and the activity goes into the last section.
     */
    public function test_a_standin_not_on_the_course_page_is_left_alone(): void {
        global $DB;
        $ids = $this->seed();
        $this->source->standins[$this->course->id][] = ['cmid' => 987654321, 'assignid' => $ids['assign'],
            'externalurl' => 'https://example.com' . self::PRESENT . '?id=' . $ids['assign'], 'visible' => 1,
            'visibleoncoursepage' => 1];
        $last = (int) $DB->get_field('course_sections', 'MAX(section)', ['course' => $this->course->id]);
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $entry = $this->entry($plan, $ids['assign']);
        $this->assertSame(plan::STANDIN_UNAVAILABLE, $entry['standin']);
        $this->assertStringContainsString('left alone', $entry['standinmessage']);
        $this->assertSame(plan::OUTCOME_DONE, $entry['outcome']);
        $map = $this->map(ids::SOURCE_ASSIGN, $ids['assign']);
        $this->assertNull($map->payload);
        $sectionid = $DB->get_field('course_modules', 'section', ['id' => $map->targetcmid]);
        $this->assertSame($last, (int) $DB->get_field('course_sections', 'section', ['id' => $sectionid]));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test a failure is reported by its class and a Moodle error code, never by its message.
     */
    public function test_a_failure_is_reported_without_its_message(): void {
        $ids = $this->seed();
        $sola = $this->sola_print();

        $plan = $this->failing_runner('runtime')->apply_course($this->course->id);

        $error = $this->entry($plan, $ids['assign'])['error'];
        $this->assertStringContainsString('RuntimeException', $error);
        $this->assertStringNotContainsString('sk-SECRET', $error);
        $this->assertStringNotContainsString('leaked', $error);

        $plan = $this->failing_runner('topics')->apply_course($this->course->id);

        $error = $this->entry($plan, $ids['assign'])['error'];
        $this->assertStringContainsString('moodle_exception (error)', $error);
        $this->assertStringNotContainsString('forced after topics', $error);
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * A source visibility and a section visibility, and what the activity ends with.
     *
     * @return array
     */
    public static function section_visibility_provider(): array {
        return [
            'visible source, hidden section' => [1, 0, [0, 1]],
            'hidden source, visible section' => [0, 1, [0, 0]],
            'visible source, visible section' => [1, 1, [1, 1]],
        ];
    }

    /**
     * Test an activity in a hidden section is hidden with its own state kept in visibleold, as core does it.
     *
     * @dataProvider section_visibility_provider
     * @param int $source The source assignment's visible.
     * @param int $section The last section's visible.
     * @param array $expected [visible, visibleold].
     */
    public function test_a_hidden_section_keeps_the_activity_hidden(int $source, int $section, array $expected): void {
        $ids = $this->seed();
        $this->source->set_assign($ids['assign'], ['visible' => $source]);
        if (!$section) {
            global $CFG;
            require_once($CFG->dirroot . '/course/lib.php');
            set_section_visible($this->course->id, 3, 0);
        }
        $sola = $this->sola_print();

        $plan = $this->runner()->apply_course($this->course->id);

        $this->assertSame(plan::OUTCOME_DONE, $this->entry($plan, $ids['assign'])['outcome']);
        $this->assertSame($expected, $this->target_visibility($ids['assign']));
        $this->assertSame($sola, $this->sola_print());
    }

    /**
     * Test an unfinished activity a person made visible isn't resumed (the import only ever creates it hidden).
     */
    public function test_an_unfinished_activity_someone_unhid_is_not_resumed(): void {
        global $DB;
        $ids = $this->seed();
        $this->failing_runner('topics')->apply_course($this->course->id);
        $DB->set_field('course_modules', 'visible', 1, ['id' => $this->map(ids::SOURCE_ASSIGN, $ids['assign'])->targetcmid]);

        $this->assert_assignment_refused_without_writes($ids['assign'], 'targetused');
    }

    /**
     * Test a recording whose destination row was deleted after the import isn't counted as newly scored.
     */
    public function test_a_deleted_destination_row_is_not_newly_scored(): void {
        global $DB;
        $ids = $this->seed();
        $this->runner()->apply_course($this->course->id);
        $DB->delete_records('presenterai_recording', ['legacyrecid' => $ids['uploaded']]);
        $score = $this->source->add_score($this->course->id, $this->bob->id);
        $this->source->set_rec($ids['uploaded'], ['scoreid' => $score, 'status' => 'scored']);
        $sola = $this->sola_print();

        $entry = $this->entry($this->runner()->plan_course($this->course->id), $ids['assign']);

        $this->assertSame(plan::SKIP_VERIFIED, $entry['decision']);
        $this->assertSame([0, 0, ''], [$entry['new_source_rows'], $entry['newly_scored_rows'], $entry['message']]);
        $this->assertSame($sola, $this->sola_print());
    }
}
