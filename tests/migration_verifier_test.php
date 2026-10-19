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
use mod_presenterai\local\migration\object_checker;
use mod_presenterai\local\migration\runner;
use mod_presenterai\local\migration\s3_object_checker;
use mod_presenterai\local\migration\verifier;
use mod_presenterai\tests\fixtures\fake_source;
use mod_presenterai\tests\fixtures\sola_rows;

/**
 * The Soapbox migration verifier: plan 7.6 tiers 1 to 3, and the presenterai_migreport row it writes.
 *
 * Each test imports through the real runner (SOLA is the fake_source double), then verifies, sometimes after
 * doctoring a row on either side. Storage is an object_checker double that knows which keys exist and how big
 * they are; the real S3 checker's mapping from a HEAD answer to a result is tested at the end without the network.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\verifier
 * @covers     \mod_presenterai\local\migration\s3_object_checker
 */
final class migration_verifier_test extends \advanced_testcase {
    /** @var string A transcript the report may never contain. */
    private const SECRET_TRANSCRIPT = 'ZEBRA-TRANSCRIPT-7731 my private words';

    /** @var string A criterion feedback string the report may never contain. */
    private const SECRET_FEEDBACK = 'OCELOT-FEEDBACK-5150 you rushed';

    /** @var string The body language observation, which never travels. */
    private const OBSERVATION = 'Leans on the desk.';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var fake_source The source double. */
    private fake_source $source;

    /** @var \stdClass A learner. */
    private \stdClass $alice;

    /** @var \stdClass Another learner. */
    private \stdClass $bob;

    /** @var object_checker The storage double: key to size, and the keys asked about. */
    private object_checker $store;

    /**
     * Load the fixtures.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/migration/sola_rows.php');
        require_once(__DIR__ . '/fixtures/migration/fake_source.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course, two learners, S3 settings that match SOLA's, admin as the user, an empty storage double.
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
        $this->alice = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Alicetta', 'lastname' => 'Zwolinski']);
        $this->bob = $gen->create_and_enrol($this->course, 'student');
        $this->source = new fake_source();
        $this->store = new class implements object_checker {
            /** @var array Key to size in bytes. */
            public array $objects = [];
            /** @var array Key to the HTTP status a HEAD fails with. */
            public array $errors = [];
            /** @var string[] Every key asked about. */
            public array $asked = [];

            /**
             * Whether the object is there at that size.
             *
             * @param string $key The key.
             * @param int $bytes The size expected, 0 for existence only.
             * @return bool
             */
            public function exists_with_size(string $key, int $bytes): bool {
                $this->asked[] = $key;
                if (isset($this->errors[$key])) {
                    throw new \RuntimeException('HTTP ' . $this->errors[$key], $this->errors[$key]);
                }
                if (!array_key_exists($key, $this->objects)) {
                    return false;
                }
                return $bytes <= 0 || $this->objects[$key] === $bytes;
            }
        };

        if (sola_rows::available()) {
            $assign = sola_rows::assign($this->course->id);
            $score = sola_rows::score($this->course->id, $this->alice->id);
            sola_rows::rec($assign, $this->alice->id, ['scoreid' => $score, 'transcript' => self::SECRET_TRANSCRIPT]);
        }
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
     * One assignment with two topics and every kind of attempt: scored with media and a deck (its meta carries the
     * body language observation), expired with a score, expired without, failed, uploaded, and one whose maximum
     * can't be rebuilt.
     *
     * @return array Ids: assign, topics, and recordings by name.
     */
    private function seed(): array {
        $src = $this->source;
        $cid = $this->course->id;
        $assign = $src->add_assign($cid);
        $ids = ['assign' => $assign, 'topic1' => $src->add_topic($assign), 'topic2' => $src->add_topic($assign)];
        $s1 = $src->add_score($cid, $this->alice->id, [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 4, 'feedback' => self::SECRET_FEEDBACK, 'max_score' => 5],
                ['name' => 'Organization', 'score' => 3, 'feedback' => 'Clear.', 'max_score' => 5],
            ]),
            'overall_score' => 4,
            'session_meta' => json_encode(['tips' => ['Slow down.'], 'visual_assessed' => true, 'pct' => 70,
                'visual_observation' => self::OBSERVATION]),
        ]);
        $s2 = $src->add_score($cid, $this->alice->id, ['overall_score' => 3, 'scores' => json_encode([
            ['name' => 'Delivery & Fluency', 'score' => 3, 'feedback' => 'Okay.'],
        ])]);
        $s3 = $src->add_score($cid, $this->bob->id, ['rubricid' => 77, 'overall_score' => 2, 'scores' => json_encode([
            ['name' => 'A criterion of a rubric since deleted', 'score' => 2, 'feedback' => 'Hm.'],
        ])]);
        $ids['scored'] = $src->add_rec($assign, $this->alice->id, ['status' => 'scored', 'scoreid' => $s1,
            'topicid' => $ids['topic1'], 'transcript' => self::SECRET_TRANSCRIPT, 'deck_key' => 'soapbox/deck.pdf',
            'size_bytes' => 5000, 'timecreated' => 1100, 'expires_at' => 9000]);
        $ids['expired'] = $src->add_rec($assign, $this->alice->id, ['status' => 'deleted', 'scoreid' => $s2,
            'storage_key' => null, 'timecreated' => 1200, 'expires_at' => 3000]);
        $ids['expirednoscore'] = $src->add_rec($assign, $this->bob->id, ['status' => 'deleted', 'storage_key' => null,
            'timecreated' => 1300, 'expires_at' => 3100]);
        $ids['failed'] = $src->add_rec($assign, $this->bob->id, ['status' => 'failed', 'timecreated' => 1400]);
        $ids['uploaded'] = $src->add_rec($assign, $this->bob->id, ['status' => 'uploaded', 'topicid' => $ids['topic2'],
            'timecreated' => 1500]);
        $ids['unreconstructed'] = $src->add_rec($assign, $this->bob->id, ['status' => 'scored', 'scoreid' => $s3,
            'timecreated' => 1600]);
        $this->source->rubrics[77] = null;
        $this->stock_bucket();

        return $ids;
    }

    /**
     * Put every key the source names into the storage double, at the size the source gives the video.
     */
    private function stock_bucket(): void {
        foreach ($this->source->recs as $rows) {
            foreach ($rows as $rec) {
                if (!empty($rec['storage_key'])) {
                    $this->store->objects[$rec['storage_key']] = (int) $rec['size_bytes'];
                }
                foreach (['deck_key', 'frames_key'] as $column) {
                    if (!empty($rec[$column])) {
                        $this->store->objects[$rec[$column]] = 123;
                    }
                }
            }
        }
    }

    /**
     * Import the course through the real runner.
     */
    private function apply(): void {
        (new runner($this->source))->apply_course($this->course->id);
    }

    /**
     * Verify the course.
     *
     * @param int $threshold Above how many recordings with media the storage check samples.
     * @param string $runid The run id, a new one when empty.
     * @return array The result.
     */
    private function verify(int $threshold = verifier::DEFAULT_THRESHOLD, string $runid = ''): array {
        return (new verifier($this->source, $this->store, $threshold))->verify_course($this->course->id, 7, $runid);
    }

    /**
     * The mismatch codes of a result, sorted.
     *
     * @param array $result A verify result.
     * @return string[]
     */
    private static function codes(array $result): array {
        $codes = array_column($result['mismatches'], 'code');
        sort($codes);

        return $codes;
    }

    /**
     * The destination row of one source recording.
     *
     * @param int $recid The source recording.
     * @return \stdClass
     */
    private function dest(int $recid): \stdClass {
        global $DB;

        return $DB->get_record('presenterai_recording', ['legacyrecid' => $recid], '*', MUST_EXIST);
    }

    /**
     * Test a faithful import passes every tier, counts every source row exactly (deleted ones included), checks every
     * stored object, and writes one report row.
     */
    public function test_a_faithful_import_passes(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $sola = $this->sola_print();

        $result = $this->verify();

        $this->assertSame([], $result['mismatches']);
        $this->assertTrue($result['passed']);
        $this->assertSame([
            'assignments' => ['source' => 1, 'destination' => 1],
            'topics' => ['source' => 2, 'destination' => 2],
            'recordings' => ['source' => 6, 'destination' => 6],
            'withmedia' => ['source' => 4, 'destination' => 4],
            'scores' => ['source' => 3, 'destination' => 3],
            'learners' => ['source' => 2, 'destination' => 2],
        ], $result['counts']);
        // Four videos and one deck.
        $this->assertSame(5, $result['storagesampled']);
        $this->assertSame(0, $result['storagefailures']);
        $this->assertSame($sola, $this->sola_print());

        $report = $DB->get_record('presenterai_migreport', ['id' => $result['reportid']], '*', MUST_EXIST);
        $this->assertSame($result['runid'], $report->runid);
        $this->assertSame((int) $this->course->id, (int) $report->courseid);
        $this->assertSame($result['counts'], json_decode($report->counts, true));
        $this->assertSame([], json_decode($report->mismatches, true));
        $this->assertSame(0, (int) $report->mismatchcount);
        $this->assertSame(5, (int) $report->storagesampled);
        $this->assertSame(0, (int) $report->storagefailures);
        $this->assertSame(1, (int) $report->passed);
        $this->assertSame(7, (int) $report->operatorid);
        $this->assertGreaterThan(0, (int) $report->timecreated);
        $this->assertSame(32, strlen($report->runid));
        $this->assertNotEmpty($ids);
    }

    /**
     * Test each call writes its own report row.
     */
    public function test_each_call_writes_a_report_row(): void {
        global $DB;
        $this->seed();
        $this->apply();

        $first = $this->verify();
        $second = $this->verify();

        $this->assertSame(2, $DB->count_records('presenterai_migreport', ['courseid' => $this->course->id]));
        $this->assertNotSame($first['runid'], $second['runid']);
        $this->assertNotSame($first['reportid'], $second['reportid']);
    }

    /**
     * Test the source's body language observation never has to be in the destination, whether SOLA still holds it
     * or its cleanup has stripped it since.
     */
    public function test_the_observation_is_left_out_of_the_comparison(): void {
        $ids = $this->seed();
        $this->apply();
        $this->assertStringNotContainsString('visual_observation', (string) $this->score_row($ids['scored'])->legacymeta);

        $this->assertTrue($this->verify()['passed'], 'SOLA still holds the observation');

        $scoreid = $this->source_rec($ids['scored'])['scoreid'];
        $meta = json_decode($this->source->scores[$scoreid]['session_meta'], true);
        unset($meta['visual_observation']);
        $this->source->scores[$scoreid]['session_meta'] = json_encode($meta);
        $this->assertTrue($this->verify()['passed'], 'SOLA stripped the observation');
    }

    /**
     * The source recording row of one id.
     *
     * @param int $recid The source recording.
     * @return array
     */
    private function source_rec(int $recid): array {
        foreach ($this->source->recs as $rows) {
            foreach ($rows as $rec) {
                if ($rec['id'] === $recid) {
                    return $rec;
                }
            }
        }
        $this->fail('No source recording ' . $recid);
    }

    /**
     * The destination score row of one source recording.
     *
     * @param int $recid The source recording.
     * @return \stdClass
     */
    private function score_row(int $recid): \stdClass {
        global $DB;

        return $DB->get_record('presenterai_score', ['recordingid' => $this->dest($recid)->id], '*', MUST_EXIST);
    }

    /**
     * Ways a destination row can be doctored, and the code each must produce.
     *
     * @return array Name to [what to do, code].
     */
    public static function doctored_provider(): array {
        return [
            'one criterion feedback changed' => ['feedback', 'criteria_feedback'],
            'a criterion dropped' => ['drop', 'criteria_count'],
            'a criterion renamed' => ['rename', 'criteria_names'],
            'criteria reordered' => ['reorder', 'criteria_names'],
            'a criterion score changed' => ['score', 'criteria_scores'],
            'the overall feedback changed' => ['overall', 'feedback_mismatch'],
            'the transcript changed' => ['transcript', 'transcript_mismatch'],
            'the expiry moved' => ['expiry', 'expiry_mismatch'],
            'the legacy mean changed' => ['mean', 'legacymeanscore'],
            'the legacy meta changed' => ['meta', 'legacymeta'],
            'the observation imported' => ['observation', 'legacymeta'],
            'the score row deleted' => ['noscore', 'score_missing'],
        ];
    }

    /**
     * Test a doctored destination row fails tier 2, names the recording by id, and carries no text.
     *
     * @dataProvider doctored_provider
     * @param string $doctor What to change.
     * @param string $code The code expected.
     */
    public function test_a_doctored_row_fails_tier_two(string $doctor, string $code): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $rec = $this->dest($ids['scored']);
        $score = $this->score_row($ids['scored']);
        $criteria = json_decode($score->scores, true);
        switch ($doctor) {
            case 'feedback':
                $criteria[0]['feedback'] = 'Something else.';
                break;
            case 'drop':
                array_pop($criteria);
                break;
            case 'rename':
                $criteria[1]['name'] = 'Structure';
                break;
            case 'reorder':
                $criteria = array_reverse($criteria);
                break;
            case 'score':
                $criteria[1]['score'] = 4;
                break;
        }
        $DB->set_field('presenterai_score', 'scores', json_encode($criteria), ['id' => $score->id]);
        $changes = [
            'overall' => ['presenterai_score', 'feedback', 'A great talk.'],
            'transcript' => ['presenterai_recording', 'transcript', 'Hello there.'],
            'expiry' => ['presenterai_recording', 'expiresat', 9001],
            'mean' => ['presenterai_score', 'legacymeanscore', 3],
            'meta' => ['presenterai_score', 'legacymeta', json_encode(['tips' => ['Speed up.'], 'visual_assessed' => true,
                'pct' => 70])],
            'observation' => ['presenterai_score', 'legacymeta', json_encode(['tips' => ['Slow down.'], 'visual_assessed' => true,
                'pct' => 70, 'visual_observation' => self::OBSERVATION])],
        ];
        if (isset($changes[$doctor])) {
            [$table, $field, $value] = $changes[$doctor];
            $DB->set_field($table, $field, $value, ['id' => $table === 'presenterai_score' ? $score->id : $rec->id]);
        }
        if ($doctor === 'noscore') {
            $DB->delete_records('presenterai_score', ['id' => $score->id]);
        }

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertContains($code, self::codes($result));
        $found = array_values(array_filter($result['mismatches'], static fn($m) => $m['code'] === $code));
        $this->assertSame($ids['scored'], $found[0]['recid']);
        $this->assertSame((int) $rec->id, $found[0]['recordingid']);
        $this->assert_no_text($result);
    }

    /**
     * Test a score whose criteria mean is more than one away from SOLA's own mean is a mismatch even when every
     * column was copied faithfully: the maxima were rebuilt from the wrong place.
     */
    public function test_a_mean_out_of_step_with_the_source_fails(): void {
        $ids = $this->seed();
        $scoreid = $this->source_rec($ids['scored'])['scoreid'];
        $this->source->scores[$scoreid]['overall_score'] = 1;
        $this->apply();
        $this->assertGreaterThan(0, (int) $this->score_row($ids['scored'])->rawmax);

        $result = $this->verify();

        $this->assertSame(['mean_drift'], self::codes($result));
        $this->assertFalse($result['passed']);
    }

    /**
     * Test a mean within one of the source's passes (SOLA rounds its mean).
     */
    public function test_a_mean_within_one_passes(): void {
        $ids = $this->seed();
        $scoreid = $this->source_rec($ids['scored'])['scoreid'];
        // The criteria are 4 and 3, a mean of 3.5; SOLA stored 4 (rounded up), and 3 would be as close.
        $this->source->scores[$scoreid]['overall_score'] = 3;
        $this->apply();

        $this->assertTrue($this->verify()['passed']);
    }

    /**
     * Ways an unreconstructed row can claim more than it knows.
     *
     * @return array Name to [field, value].
     */
    public static function unreconstructed_provider(): array {
        return [
            'a maximum' => ['rawmax', 5],
            'a percentage' => ['overallpct', 40],
        ];
    }

    /**
     * Test an unreconstructed row must have no maximum and no percentage.
     *
     * @dataProvider unreconstructed_provider
     * @param string $field The field doctored.
     * @param int $value Its new value.
     */
    public function test_an_unreconstructed_row_claims_nothing(string $field, int $value): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $score = $this->score_row($ids['unreconstructed']);
        $this->assertSame('unreconstructed', $score->scoreprovenance);
        $this->assertSame(2, (int) $score->legacymeanscore);
        $this->assertTrue($this->verify()['passed']);

        $DB->set_field('presenterai_score', $field, $value, ['id' => $score->id]);
        $result = $this->verify();

        $this->assertContains('unreconstructed_inconsistent', self::codes($result));
        $this->assertFalse($result['passed']);
    }

    /**
     * Test a reconstructed row with no maximum is a mismatch: a row that can't show a percentage has to say it's
     * unreconstructed.
     */
    public function test_a_reconstructed_row_without_a_maximum_fails(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $score = $this->score_row($ids['expired']);
        $this->assertSame('preset_backfill', $score->scoreprovenance);
        $this->assertSame(5, (int) $score->rawmax);
        $DB->update_record('presenterai_score', (object) ['id' => $score->id, 'rawmax' => 0, 'overallpct' => null]);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame(['rawmax_zero_not_unreconstructed'], self::codes($result));
        $this->assertSame(['code' => 'rawmax_zero_not_unreconstructed', 'assignid' => $ids['assign'],
            'recid' => $ids['expired'], 'recordingid' => (int) $this->dest($ids['expired'])->id], $result['mismatches'][0]);
    }

    /**
     * Test a recording SOLA gained after the assignment was verified is a mismatch, named by ids only.
     */
    public function test_a_recording_added_after_the_import_is_reported(): void {
        $ids = $this->seed();
        $this->apply();
        $late = $this->source->add_rec($ids['assign'], $this->alice->id, ['status' => 'uploaded', 'storage_key' => null,
            'transcript' => self::SECRET_TRANSCRIPT]);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame(['count_mismatch', 'source_recording_not_imported'], self::codes($result));
        $missing = array_values(array_filter($result['mismatches'],
            static fn($m) => $m['code'] === 'source_recording_not_imported'))[0];
        $this->assertSame(['code' => 'source_recording_not_imported', 'assignid' => $ids['assign'], 'recid' => $late], $missing);
        $this->assertSame(['source' => 7, 'destination' => 6], $result['counts']['recordings']);
        $this->assert_no_text($result);
    }

    /**
     * Test a deleted source recording is counted like any other: a destination missing it fails.
     */
    public function test_a_deleted_source_recording_counts(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $DB->delete_records('presenterai_recording', ['id' => $this->dest($ids['expirednoscore'])->id]);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame(['source' => 6, 'destination' => 5], $result['counts']['recordings']);
        $this->assertContains('source_recording_not_imported', self::codes($result));
    }

    /**
     * Test a verified activity a teacher deleted since is a mismatch, not a pass.
     */
    public function test_a_verified_activity_that_is_gone_is_target_missing(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $map = $DB->get_record('presenterai_migration_map',
            ['sourcetable' => ids::SOURCE_ASSIGN, 'sourceid' => $ids['assign'], 'sourcesiteid' => ids::site_id()]);
        $DB->delete_records('course_modules', ['id' => $map->targetcmid]);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertContains('target_missing', self::codes($result));
    }

    /**
     * Test an assignment that wasn't imported, and one that's only part done, both fail.
     */
    public function test_an_unfinished_import_fails(): void {
        $ids = $this->seed();
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
        $runner->apply_course($this->course->id);
        $other = $this->source->add_assign($this->course->id);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $codes = self::codes($result);
        $this->assertContains('assignment_not_verified', $codes);
        $this->assertContains('assignment_not_imported', $codes);
        $this->assertContains('count_mismatch', $codes);
        $byassign = array_column($result['mismatches'], 'code', 'assignid');
        $this->assertSame('assignment_not_imported', $byassign[$other]);
        $this->assertNotEmpty($ids);
    }

    /**
     * What can go wrong with a stored object, and the code each gives.
     *
     * @return array Name to [what to do, code].
     */
    public static function storage_provider(): array {
        return [
            'a missing video' => ['missing', 'storage_mismatch'],
            'a video of the wrong size' => ['size', 'storage_mismatch'],
            'a missing deck' => ['deck', 'storage_mismatch'],
            'a HEAD that fails' => ['error', 'storage_error'],
        ];
    }

    /**
     * Test a storage failure fails the report and counts, with the recording id and nothing that names the object.
     *
     * @dataProvider storage_provider
     * @param string $what What to break.
     * @param string $code The code expected.
     */
    public function test_a_storage_failure_fails(string $what, string $code): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $rec = $this->dest($ids['scored']);
        if ($what === 'missing') {
            unset($this->store->objects[$rec->storagekey]);
        } else if ($what === 'size') {
            $this->store->objects[$rec->storagekey] = 4999;
        } else if ($what === 'deck') {
            unset($this->store->objects[$rec->deckkey]);
        } else {
            $this->store->errors[$rec->storagekey] = 503;
        }

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['storagefailures']);
        $this->assertSame([$code], self::codes($result));
        $mismatch = $result['mismatches'][0];
        $this->assertSame($ids['scored'], $mismatch['recid']);
        $this->assertSame((int) $rec->id, $mismatch['recordingid']);
        $this->assertSame($what === 'deck' ? 'deckkey' : 'storagekey', $mismatch['column']);
        if ($what === 'error') {
            $this->assertSame(503, $mismatch['status']);
        }
        $report = $DB->get_record('presenterai_migreport', ['id' => $result['reportid']]);
        $this->assertSame(0, (int) $report->passed);
        $this->assertSame(1, (int) $report->storagefailures);
        $this->assertStringNotContainsString('soapbox/', $report->mismatches);
        $this->assertStringNotContainsString('HTTP', $report->mismatches);
    }

    /**
     * Test above the threshold the storage check samples deterministically: the newest tenth, every attempt SOLA
     * marked failed, and a run-seeded pick of the rest; under it, everything.
     */
    public function test_storage_is_sampled_above_the_threshold(): void {
        $src = $this->source;
        $assign = $src->add_assign($this->course->id);
        $recs = [];
        for ($i = 0; $i < 30; $i++) {
            $status = $i === 0 ? 'failed' : 'uploaded';
            $recs[$i] = $src->add_rec($assign, $i % 2 ? $this->alice->id : $this->bob->id, ['status' => $status,
                'timecreated' => 2000 + $i]);
        }
        $this->stock_bucket();
        $this->apply();

        $all = $this->verify(30, str_repeat('a', 32));
        $this->assertSame(30, $all['storagesampled']);

        $first = $this->verify(5, str_repeat('b', 32));
        $again = $this->verify(5, str_repeat('b', 32));
        $other = $this->verify(5, str_repeat('c', 32));

        $this->assertTrue($first['passed']);
        $this->assertSame($first['sampledrecordings'], $again['sampledrecordings']);
        $sampled = $first['sampledrecordings'];
        // The newest tenth (3 of 30), the failed one (the oldest), and 5 more chosen by the run id.
        $this->assertCount(3 + 1 + 5, $sampled);
        $this->assertSame(9, $first['storagesampled']);
        foreach ([$recs[29], $recs[28], $recs[27], $recs[0]] as $recid) {
            $this->assertContains((int) $this->dest($recid)->id, $sampled);
        }
        $this->assertNotSame($sampled, $other['sampledrecordings']);
    }

    /**
     * Test without SOLA's tables nothing can be compared, so the report fails.
     */
    public function test_no_source_tables_fails(): void {
        $this->source->tables = false;

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame(['sourcetables'], self::codes($result));
    }

    /**
     * Test a course with no Soapbox data at all passes with every count zero.
     */
    public function test_an_empty_course_passes(): void {
        $result = $this->verify();

        $this->assertTrue($result['passed']);
        $this->assertSame(['source' => 0, 'destination' => 0], $result['counts']['recordings']);
    }

    /**
     * Test the report, and every value it was built from, holds no learner text and no name.
     */
    public function test_the_report_holds_no_learner_text(): void {
        global $DB;
        $ids = $this->seed();
        $this->apply();
        $score = $this->score_row($ids['scored']);
        $DB->set_field('presenterai_score', 'scores', '[]', ['id' => $score->id]);
        $DB->set_field('presenterai_recording', 'transcript', 'x', ['id' => $this->dest($ids['scored'])->id]);

        $result = $this->verify();

        $this->assertFalse($result['passed']);
        $this->assert_no_text($result);
        foreach ($DB->get_records('presenterai_migreport') as $report) {
            $this->assert_no_text((array) $report);
        }
    }

    /**
     * Assert none of the seeded learner text or names appears anywhere in a value.
     *
     * @param array $value A result or a report row.
     */
    private function assert_no_text(array $value): void {
        $json = json_encode($value);
        foreach ([self::SECRET_TRANSCRIPT, self::SECRET_FEEDBACK, self::OBSERVATION, 'Slow down', 'A good talk',
                'Alicetta', 'Zwolinski', 'Steady', 'Delivery'] as $text) {
            $this->assertStringNotContainsString($text, $json);
        }
    }

    /**
     * HEAD answers and what the S3 checker makes of them.
     *
     * @return array Name to [status, content length, bytes expected, result].
     */
    public static function head_provider(): array {
        return [
            'there, right size' => [200, 4096, 4096, true],
            'there, wrong size' => [200, 4000, 4096, false],
            'there, size not expected' => [200, 4000, 0, true],
            'there, size not reported' => [200, null, 4096, true],
            'not there' => [404, null, 4096, false],
        ];
    }

    /**
     * Test the S3 checker turns a HEAD answer into a result without the network.
     *
     * @dataProvider head_provider
     * @param int $status The HTTP status.
     * @param int|null $length The content length reported.
     * @param int $bytes The size expected.
     * @param bool $expected The result.
     */
    public function test_the_s3_checker_reads_a_head(int $status, ?int $length, int $bytes, bool $expected): void {
        $asked = [];
        $checker = new s3_object_checker(static function (string $key) use (&$asked, $status, $length): array {
            $asked[] = $key;
            return ['status' => $status, 'bytes' => $length];
        });

        $this->assertSame($expected, $checker->exists_with_size('soapbox/a.webm', $bytes));
        $this->assertSame(['soapbox/a.webm'], $asked);
    }

    /**
     * Statuses that are neither "there" nor "not there".
     *
     * @return array Name to [status].
     */
    public static function head_error_provider(): array {
        return ['forbidden' => [403], 'unavailable' => [503], 'no answer' => [0]];
    }

    /**
     * Test any other answer is an error carrying the status alone, never the key.
     *
     * @dataProvider head_error_provider
     * @param int $status The HTTP status.
     */
    public function test_the_s3_checker_reports_other_answers_by_status(int $status): void {
        $checker = new s3_object_checker(static fn(string $key): array => ['status' => $status, 'bytes' => null]);

        try {
            $checker->exists_with_size('soapbox/secret-key.webm', 10);
            $this->fail('No error');
        } catch (\RuntimeException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertStringNotContainsString('secret-key', $e->getMessage());
        }
    }

    /**
     * Test the real store's HEAD on an unconfigured site answers status 0 without a request.
     */
    public function test_an_unconfigured_store_head_is_status_zero(): void {
        $store = new \mod_presenterai\local\storage\s3_store(['key' => '', 'secret' => '']);

        $this->assertSame(['status' => 0, 'bytes' => null], $store->head('soapbox/a.webm'));
    }
}
