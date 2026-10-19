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
use mod_presenterai\local\migration\source_reader;
use mod_presenterai\local\recording_manager;

/**
 * The Soapbox migration contract, the reader interface and the pure mapper.
 *
 * Every test here runs over arrays shaped like SOLA's rows (tests/fixtures/migration),
 * so it needs neither SOLA's tables nor a database row of either plugin.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\migration\ids
 * @covers     \mod_presenterai\local\migration\mapper
 * @covers     \mod_presenterai\local\migration\source_reader
 */
final class migration_mapper_test extends \advanced_testcase {
    /**
     * Read one JSON fixture.
     *
     * @param string $name The file name without its extension.
     * @return array The decoded content.
     */
    private function fixture(string $name): array {
        $path = __DIR__ . '/fixtures/migration/' . $name . '.json';
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * One practice_scores row as the database holds it: scores and session_meta are JSON text.
     *
     * @param string $name A key of scores.json.
     * @param array $override Fields to replace after the fixture is loaded, raw (a JSON string stays a string).
     * @return array
     */
    private function sola_score(string $name, array $override = []): array {
        $row = $this->fixture('scores')[$name];
        $row['scores'] = json_encode($row['scores']);
        $row['session_meta'] = $row['session_meta'] === null ? null : json_encode($row['session_meta']);
        return $override + $row;
    }

    /**
     * A score_to_row context, with the ids the runner would pass.
     *
     * @param array $override Keys to replace.
     * @return array
     */
    private function context(array $override = []): array {
        $criteria = $this->fixture('criteria');
        return $override + [
            'speaking_level' => 'general',
            'rubric' => null,
            'preset' => $criteria['general_preset'],
            'recordingid' => 9001,
            'userid' => 101,
        ];
    }

    /**
     * A minimal Soapbox recording row, for numbering and status tests.
     *
     * @param int $id The row id.
     * @param int $userid The learner.
     * @param string $status The SOLA status.
     * @param int $created The creation time.
     * @param int|null $scoreid The score the row points at, if any.
     * @param int $assignid The assignment.
     * @return array
     */
    private function rec(int $id, int $userid, string $status, int $created, ?int $scoreid = null, int $assignid = 7): array {
        return [
            'id' => $id,
            'assignid' => $assignid,
            'userid' => $userid,
            'status' => $status,
            'timecreated' => $created,
            'scoreid' => $scoreid,
        ];
    }

    /**
     * Assert two rows hold exactly the same keys and values, whatever order the keys came in.
     *
     * @param array $expected The row the mapper should build.
     * @param array $actual The row it built.
     * @return void
     */
    private function assert_row(array $expected, array $actual): void {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * The code of a class file with every comment taken out.
     *
     * @param string $file Path to a PHP file.
     * @return string
     */
    private function code_only(string $file): string {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    /**
     * The three source table names are the contract SOLA's interlock reads, so they're pinned as literals.
     *
     * @return void
     */
    public function test_the_map_contract_literals_are_pinned(): void {
        $this->assertSame('local_ai_course_assistant_sbx_rec', ids::SOURCE_REC);
        $this->assertSame('local_ai_course_assistant_sbx_assign', ids::SOURCE_ASSIGN);
        $this->assertSame('local_ai_course_assistant_sbx_topic', ids::SOURCE_TOPIC);
    }

    /**
     * The site id is sha1 of the wwwroot, a bar and the site identifier, and it moves with either.
     *
     * @return void
     */
    public function test_site_id_is_the_documented_hash(): void {
        global $CFG;
        $this->resetAfterTest();

        $expected = sha1($CFG->wwwroot . '|' . get_site_identifier());
        $this->assertSame($expected, ids::site_id());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', ids::site_id());
        $this->assertSame(ids::site_id(), ids::site_id());

        $CFG->wwwroot = 'https://elsewhere.example.org';
        $this->assertSame(sha1('https://elsewhere.example.org|' . get_site_identifier()), ids::site_id());
        $this->assertNotSame($expected, ids::site_id());
    }

    /**
     * The reader interface has exactly the methods, parameters and return types the later tasks are written against.
     *
     * @return void
     */
    public function test_the_source_reader_interface_is_pinned(): void {
        $this->assertTrue(interface_exists(source_reader::class));
        $signatures = [];
        foreach ((new \ReflectionClass(source_reader::class))->getMethods() as $method) {
            $params = array_map(
                static fn(\ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(),
                $method->getParameters()
            );
            $signatures[$method->getName()] = '(' . implode(', ', $params) . '): ' . $method->getReturnType();
        }
        ksort($signatures);

        $this->assertSame([
            'assignments' => '(int $courseid): array',
            'course_ids_with_data' => '(): array',
            'interlock_present' => '(): bool',
            'orphan_score_count' => '(int $courseid): int',
            'preset_criteria' => '(string $level): array',
            'queued_scoring_task_count' => '(): int',
            'recordings' => '(int $assignid): array',
            'rubric_criteria' => '(int $rubricid): ?array',
            'score' => '(int $scoreid): ?array',
            'shared_score_ids' => '(int $courseid): array',
            'site_id' => '(): string',
            'speaking_level_for_course' => '(int $courseid): string',
            'standins' => '(int $courseid): array',
            'storage_settings' => '(): array',
            'tables_present' => '(): bool',
            'topics' => '(int $assignid): array',
        ], $signatures);
    }

    /**
     * The mapper reads no database and no global configuration, so CI can run it without SOLA.
     *
     * @return void
     */
    public function test_the_mapper_is_pure(): void {
        $code = $this->code_only(__DIR__ . '/../classes/local/migration/mapper.php');
        $this->assertDoesNotMatchRegularExpression('/\bglobal\b|\$DB\b|\$CFG\b|\btime\(/', $code);
        $this->assertTrue((new \ReflectionClass(mapper::class))->isFinal());
        foreach ((new \ReflectionClass(mapper::class))->getMethods() as $method) {
            $this->assertTrue($method->isStatic(), $method->getName() . ' must be static');
        }
    }

    /**
     * Every assignment field lands on the column or form field the activity uses.
     *
     * @return void
     */
    public function test_assignment_maps_every_field(): void {
        $info = mapper::assignment_to_instance($this->fixture('assignment'));

        $this->assertSame('presenterai', $info['modulename']);
        $this->assertSame(12, $info['course']);
        $this->assertSame('Pitch day', $info['name']);
        $this->assertSame('<p>Give a five minute pitch.</p>', $info['intro']);
        $this->assertSame(1, $info['introformat']);
        $this->assertSame(
            ['text' => '<p>Give a five minute pitch.</p>', 'format' => 1, 'itemid' => 0],
            $info['introeditor']
        );
        $this->assertSame('persuasive', $info['ptype']);
        $this->assertSame('video', $info['mode']);
        $this->assertSame(240, $info['minseconds']);
        $this->assertSame(360, $info['maxseconds']);
        $this->assertSame(3, $info['maxattempts']);
        $this->assertSame(2, $info['storedattempts']);
        $this->assertSame(1, $info['slidesenabled']);
        $this->assertSame(1, $info['slidevision']);
        $this->assertSame(1, $info['visible']);
        $this->assertSame(7, $info['legacyassignid']);
    }

    /**
     * A migrated activity is ungraded and follows the site's retention, whatever the source said (D12, ruling 4).
     *
     * @return void
     */
    public function test_assignment_is_ungraded_and_follows_the_site_retention(): void {
        $info = mapper::assignment_to_instance($this->fixture('assignment'));

        $this->assertSame(0, $info['grade']);
        $this->assertSame(-1, $info['retentiondays']);
    }

    /**
     * SOLA's rubricid and speaking_level columns are dead, and a SOLA rubric id means nothing in this plugin.
     *
     * @return void
     */
    public function test_assignment_never_carries_the_dead_columns(): void {
        $assign = $this->fixture('assignment');
        $this->assertSame(9, $assign['rubricid']);
        $this->assertSame('esl_beginner', $assign['speaking_level']);

        $info = mapper::assignment_to_instance($assign);

        $this->assertArrayNotHasKey('rubricid', $info);
        $this->assertArrayNotHasKey('speakinglevel', $info);
        $this->assertArrayNotHasKey('speaking_level', $info);
    }

    /**
     * The attempt cap is copied exactly as given: a real cap passes through, and 0 isn't reinterpreted here.
     *
     * The source reader hands over the effective cap (SOLA's 0 means the admin's cap, this plugin's 0 means
     * unlimited), so by the time a row reaches the mapper the number is already the one to store.
     *
     * @return void
     */
    public function test_assignment_cap_is_copied_exactly(): void {
        $assign = $this->fixture('assignment');

        foreach ([1, 3, 5, 12] as $cap) {
            $this->assertSame($cap, mapper::assignment_to_instance(['max_attempts' => $cap] + $assign)['maxattempts']);
        }
        $this->assertSame(0, mapper::assignment_to_instance(['max_attempts' => 0] + $assign)['maxattempts']);
    }

    /**
     * Fields a visible-only or default-only source leaves out still come out typed.
     *
     * @return void
     */
    public function test_assignment_casts_database_strings_to_integers(): void {
        $assign = array_map('strval', $this->fixture('assignment'));
        $info = mapper::assignment_to_instance($assign);

        $this->assertSame(12, $info['course']);
        $this->assertSame(240, $info['minseconds']);
        $this->assertSame(7, $info['legacyassignid']);
        $this->assertSame(1, $info['introformat']);
    }

    /**
     * A topic keeps its text and order and points at the new activity.
     *
     * @return void
     */
    public function test_topic_maps_to_a_row_for_the_new_instance(): void {
        $row = mapper::topic_to_row($this->fixture('topic'), 55);

        $this->assert_row([
            'presenteraiid' => 55,
            'title' => 'Why we should walk more',
            'instructions' => '<p>Argue for it.</p>',
            'instructionsformat' => 1,
            'sortorder' => 2,
            'legacytopicid' => 31,
            'timecreated' => 1790000100,
            'timemodified' => 1790000200,
        ], $row);
    }

    /**
     * Every Soapbox status crossed with whether a score row exists.
     *
     * @return array [sola status, has score, expected status]
     */
    public static function status_provider(): array {
        return [
            'uploading, no score' => ['uploading', false, 'abandoned'],
            'uploading, score' => ['uploading', true, 'scored'],
            'uploaded, no score' => ['uploaded', false, 'uploaded'],
            'uploaded, score' => ['uploaded', true, 'scored'],
            'scored, no score row' => ['scored', false, 'uploaded'],
            'scored, score' => ['scored', true, 'scored'],
            'deleted, no score' => ['deleted', false, 'abandoned'],
            'deleted, score' => ['deleted', true, 'scored'],
            'failed, no score' => ['failed', false, 'failed'],
            'failed, score' => ['failed', true, 'scored'],
        ];
    }

    /**
     * A score row makes an attempt scored, and without one the status says what really happened.
     *
     * @dataProvider status_provider
     * @param string $solastatus The SOLA status.
     * @param bool $hasscore Whether a score row exists.
     * @param string $expected The status this plugin stores.
     * @return void
     */
    public function test_recording_status(string $solastatus, bool $hasscore, string $expected): void {
        $this->assertSame($expected, mapper::recording_status(['status' => $solastatus], $hasscore));
    }

    /**
     * A status this mapper doesn't know is refused, never guessed.
     *
     * @return void
     */
    public function test_an_unknown_status_is_refused(): void {
        $this->expectException(\InvalidArgumentException::class);
        mapper::recording_status(['status' => 'archived'], false);
    }

    /**
     * Attempts are numbered per learner by time among the attempts that count.
     *
     * An abandoned or never finished attempt gets 0 and uses no number; a failed one is numbered as the next
     * counted attempt would be, which is what finalize gave it in the activity.
     *
     * @return void
     */
    public function test_attempt_numbers_across_users_and_statuses(): void {
        $recs = [
            $this->rec(1, 1, 'scored', 100, 11),
            $this->rec(2, 1, 'failed', 200),
            $this->rec(3, 1, 'uploaded', 300),
            $this->rec(4, 1, 'deleted', 400),
            $this->rec(5, 1, 'deleted', 500, 12),
            $this->rec(6, 1, 'uploading', 600),
            $this->rec(7, 2, 'uploaded', 50),
            $this->rec(8, 2, 'scored', 60, 13),
        ];

        $this->assertSame([1 => 1, 2 => 2, 3 => 2, 4 => 0, 5 => 3, 6 => 0, 7 => 1, 8 => 2], mapper::attempt_numbers($recs));
    }

    /**
     * Equal times are ordered by id, whatever order the rows arrive in.
     *
     * @return void
     */
    public function test_attempt_numbers_break_ties_by_id(): void {
        $recs = [
            $this->rec(31, 1, 'scored', 100, 1),
            $this->rec(30, 1, 'scored', 100, 2),
            $this->rec(32, 1, 'scored', 99, 3),
        ];

        $numbers = mapper::attempt_numbers($recs);

        $this->assertSame(1, $numbers[32]);
        $this->assertSame(2, $numbers[30]);
        $this->assertSame(3, $numbers[31]);
        $reversed = mapper::attempt_numbers(array_reverse($recs));
        ksort($numbers);
        ksort($reversed);
        $this->assertSame($numbers, $reversed);
    }

    /**
     * Two assignments number the same learner separately, as two activities will.
     *
     * @return void
     */
    public function test_attempt_numbers_restart_for_each_assignment(): void {
        $recs = [
            $this->rec(1, 1, 'scored', 100, 1, 7),
            $this->rec(2, 1, 'scored', 200, 2, 8),
            $this->rec(3, 1, 'scored', 300, 3, 7),
        ];

        $this->assertSame([1 => 1, 2 => 1, 3 => 2], mapper::attempt_numbers($recs));
    }

    /**
     * No rows, no numbers.
     *
     * @return void
     */
    public function test_attempt_numbers_of_nothing(): void {
        $this->assertSame([], mapper::attempt_numbers([]));
    }

    /**
     * A recording whose media survives keeps its keys exactly and its deletion date untouched.
     *
     * @return void
     */
    public function test_recording_with_surviving_media(): void {
        $rec = $this->fixture('recordings')['surviving'];

        $row = mapper::recording_to_row($rec, 55, 66, 1, true);

        $this->assert_row([
            'presenteraiid' => 55,
            'userid' => 101,
            'topicid' => 66,
            'attemptnumber' => 1,
            'mode' => 'video',
            'backend' => 's3',
            'storagekey' => 'soapbox/12/7/101/21-talk.webm',
            'deckkey' => 'soapbox/12/7/101/21-deck.pdf',
            'frameskey' => 'soapbox/12/7/101/21-frames.jpg',
            'audiokey' => null,
            'audiobytes' => 0,
            'uploadid' => null,
            'clienttoken' => null,
            'slidetimeline' => '[{"t":0,"i":0},{"t":95,"i":1}]',
            'visualevidence' => null,
            'visualevidenceat' => 0,
            'visualoptout' => 0,
            'durationseconds' => 312,
            'sizebytes' => 48211904,
            'status' => 'scored',
            'transcript' => 'Good morning everyone, today I want to talk about walking.',
            'scoreid' => null,
            'expiresat' => 1795000000,
            'mediadeletedat' => 0,
            'mediagonereason' => null,
            'deletewarnedat' => 0,
            'legacyrecid' => 21,
            'legacyscoreid' => 501,
            'timecreated' => 1790500000,
            'timemodified' => 1790500000,
        ], $row);
    }

    /**
     * A recording SOLA already deleted stays deleted: null keys, the date from expires_at, expiresat verbatim.
     *
     * @return void
     */
    public function test_recording_whose_media_is_already_gone(): void {
        $rec = $this->fixture('recordings')['deleted_scored'];

        $row = mapper::recording_to_row($rec, 55, null, 2, true);

        $this->assertNull($row['storagekey']);
        $this->assertNull($row['deckkey']);
        $this->assertNull($row['frameskey']);
        $this->assertSame(1791100000, $row['mediadeletedat']);
        $this->assertContains($row['mediagonereason'], recording_manager::GONE_REASONS);
        $this->assertSame(1791100000, $row['expiresat']);
        $this->assertSame('scored', $row['status']);
        $this->assertNull($row['topicid']);
        $this->assertSame('audio', $row['mode']);
        $this->assertSame('Second attempt, same topic, fewer notes.', $row['transcript']);
        $this->assertSame(502, $row['legacyscoreid']);
        $this->assertSame(22, $row['legacyrecid']);
    }

    /**
     * A deleted row with no score is abandoned, and still says when its media went.
     *
     * @return void
     */
    public function test_deleted_recording_without_a_score(): void {
        $rec = $this->fixture('recordings')['deleted_unscored'];

        $row = mapper::recording_to_row($rec, 55, 66, 0, false);

        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(1791200000, $row['mediadeletedat']);
        $this->assertSame(1791200000, $row['expiresat']);
        $this->assertNull($row['transcript']);
        $this->assertSame(0, $row['legacyscoreid']);
        $this->assertSame(0, $row['attemptnumber']);
    }

    /**
     * An attempt that never had media (failed before the upload) didn't lose any, so no deletion is recorded.
     *
     * @return void
     */
    public function test_recording_that_never_had_media_records_no_deletion(): void {
        $rec = $this->fixture('recordings')['failed_before_upload'];

        $row = mapper::recording_to_row($rec, 55, null, 1, false);

        $this->assertSame('failed', $row['status']);
        $this->assertNull($row['storagekey']);
        $this->assertSame(0, $row['mediadeletedat']);
        $this->assertNull($row['mediagonereason']);
        $this->assertSame(0, $row['expiresat']);
    }

    /**
     * An empty string is no key, so it's stored as null rather than as a key that names nothing.
     *
     * @return void
     */
    public function test_empty_keys_become_null(): void {
        $rec = $this->fixture('recordings')['surviving'];
        $rec['deck_key'] = '';
        $rec['frames_key'] = '';

        $row = mapper::recording_to_row($rec, 55, 66, 1, true);

        $this->assertNull($row['deckkey']);
        $this->assertNull($row['frameskey']);
        $this->assertSame('soapbox/12/7/101/21-talk.webm', $row['storagekey']);
    }

    /**
     * The expiry copied is the source's, not one worked out from the creation time or the retention setting.
     *
     * @return void
     */
    public function test_expiresat_is_copied_not_recomputed(): void {
        $rec = $this->fixture('recordings')['surviving'];
        $rec['expires_at'] = 1234567;

        $this->assertSame(1234567, mapper::recording_to_row($rec, 55, 66, 1, true)['expiresat']);
        $rec['expires_at'] = 0;
        $this->assertSame(0, mapper::recording_to_row($rec, 55, 66, 1, true)['expiresat']);
    }

    /**
     * New shape: every criterion states its maximum, so the percentage comes from the raw sums.
     *
     * Criteria the model couldn't judge are left out of both sums, and overall_score (a 0 to 5 mean) is
     * never read as a percentage.
     *
     * @return void
     */
    public function test_new_shape_score_is_exact_and_the_percentage_is_computed(): void {
        $row = mapper::score_to_row($this->sola_score('new_shape'), $this->context());

        $this->assertSame('exact', $row['scoreprovenance']);
        $this->assertSame(25, $row['rawsum']);
        $this->assertSame(30, $row['rawmax']);
        $this->assertIsFloat($row['overallpct']);
        $this->assertEqualsWithDelta(83.33, $row['overallpct'], 0.001);
        $this->assertNotEquals(4, $row['overallpct']);
        $this->assertSame(4, $row['legacymeanscore']);

        $criteria = json_decode($row['scores'], true);
        $this->assertCount(7, $criteria);
        $this->assertSame([
            'name' => 'Eye Contact & Camera Presence',
            'score' => 0,
            'max_score' => 5,
            'feedback' => 'Could not judge.',
            'assessed' => false,
            'visual' => false,
            'counts' => true,
        ], $criteria[6]);
        $this->assertSame(
            ['Delivery & Fluency', 'Structure & Organization', 'Content & Relevance', 'Language & Vocabulary',
                'Time Management', 'Body Language & Gestures', 'Eye Contact & Camera Presence'],
            array_column($criteria, 'name')
        );
    }

    /**
     * Four out of five is 80.00 percent, never 4.
     *
     * @return void
     */
    public function test_four_of_five_is_eighty_percent_never_four(): void {
        $score = $this->sola_score('new_shape', [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 4, 'feedback' => 'x', 'max_score' => 5, 'assessed' => true],
            ]),
            'overall_score' => 4,
        ]);

        $row = mapper::score_to_row($score, $this->context());

        $this->assertSame(80.0, $row['overallpct']);
        $this->assertSame(4, $row['rawsum']);
        $this->assertSame(5, $row['rawmax']);
        $this->assertSame(4, $row['legacymeanscore']);
    }

    /**
     * Old shape, rubric still there: the maxima come from the rubric by normalised name; absent assessed counts.
     *
     * @return void
     */
    public function test_old_shape_with_a_surviving_rubric_is_a_rubric_backfill(): void {
        $criteria = $this->fixture('criteria');
        $context = $this->context(['rubric' => $criteria['custom_rubric']]);

        $row = mapper::score_to_row($this->sola_score('old_shape_rubric'), $context);

        $this->assertSame('rubric_backfill', $row['scoreprovenance']);
        $this->assertSame(17, $row['rawsum']);
        $this->assertSame(20, $row['rawmax']);
        $this->assertSame(85.0, $row['overallpct']);
        $this->assertSame(6, $row['legacymeanscore']);

        $stored = json_decode($row['scores'], true);
        $this->assertSame([10, 5, 5], array_column($stored, 'max_score'));
        $this->assertSame([true, true, true], array_column($stored, 'assessed'));
        $this->assertSame([true, true, true], array_column($stored, 'counts'));
        // The name is stored as SOLA stored it; only the lookup is normalised.
        $this->assertSame('Delivery &  Fluency ', $stored[0]['name']);
    }

    /**
     * Old shape, scored with a level preset (rubricid 0): the maxima come from the preset.
     *
     * @return void
     */
    public function test_old_shape_scored_with_a_preset_is_a_preset_backfill(): void {
        $context = $this->context(['speaking_level' => 'esl_beginner', 'rubric' => null]);

        $row = mapper::score_to_row($this->sola_score('old_shape_preset'), $context);

        $this->assertSame('preset_backfill', $row['scoreprovenance']);
        $this->assertSame(18, $row['rawsum']);
        $this->assertSame(25, $row['rawmax']);
        $this->assertSame(72.0, $row['overallpct']);
        $this->assertSame(4, $row['legacymeanscore']);
    }

    /**
     * A rubric that has since been deleted isn't a licence to guess from the preset.
     *
     * The row was scored against a custom rubric whose maxima are gone, so it says so instead of faking a
     * percentage, and SOLA's own mean is kept.
     *
     * @return void
     */
    public function test_deleted_rubric_is_unreconstructed_and_keeps_the_legacy_mean(): void {
        $row = mapper::score_to_row($this->sola_score('old_shape_deleted_rubric'), $this->context(['rubric' => null]));

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(4, $row['legacymeanscore']);
        $this->assertSame(12, $row['rawsum']);

        $stored = json_decode($row['scores'], true);
        $this->assertCount(3, $stored);
        $this->assertSame([null, null, null], array_column($stored, 'max_score'));
        $this->assertSame([4, 3, 5], array_column($stored, 'score'));
        $this->assertSame('Fine.', $stored[0]['feedback']);
    }

    /**
     * Scored with a preset, but no preset is known for the level: unreconstructed, not a percentage out of 5.
     *
     * @return void
     */
    public function test_preset_row_with_no_matching_preset_is_unreconstructed(): void {
        $row = mapper::score_to_row($this->sola_score('old_shape_preset'), $this->context(['preset' => []]));

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(4, $row['legacymeanscore']);
    }

    /**
     * A criterion that's in no rubric stops the whole row from claiming a percentage.
     *
     * Leaving it out of the denominator but not the numerator would invent one; the others keep their maxima.
     *
     * @return void
     */
    public function test_a_criterion_in_no_rubric_makes_the_row_unreconstructed(): void {
        $criteria = $this->fixture('criteria');
        $context = $this->context(['rubric' => $criteria['custom_rubric']]);

        $row = mapper::score_to_row($this->sola_score('unknown_criterion'), $context);

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(17, $row['rawsum']);
        $stored = json_decode($row['scores'], true);
        $this->assertSame([10, 5, null], array_column($stored, 'max_score'));
        $this->assertSame('Awarded from the transcript alone.', $stored[2]['feedback']);
    }

    /**
     * A row that mixes stated and looked-up maxima is as weak as its weakest source.
     *
     * @return void
     */
    public function test_a_mixed_row_takes_the_weakest_provenance(): void {
        $score = $this->sola_score('old_shape_preset', [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 3, 'feedback' => 'a', 'max_score' => 5],
                ['name' => 'Structure & Organization', 'score' => 4, 'feedback' => 'b'],
            ]),
        ]);

        $row = mapper::score_to_row($score, $this->context());

        $this->assertSame('preset_backfill', $row['scoreprovenance']);
        $this->assertSame(7, $row['rawsum']);
        $this->assertSame(10, $row['rawmax']);
        $this->assertSame(70.0, $row['overallpct']);
    }

    /**
     * A stated maximum below 1 isn't a maximum, so the lookup is used instead.
     *
     * @return void
     */
    public function test_a_zero_maximum_falls_through_to_the_rubric(): void {
        $criteria = $this->fixture('criteria');
        $score = $this->sola_score('old_shape_rubric', [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 8, 'feedback' => 'a', 'max_score' => 0],
            ]),
        ]);

        $row = mapper::score_to_row($score, $this->context(['rubric' => $criteria['custom_rubric']]));

        $this->assertSame('rubric_backfill', $row['scoreprovenance']);
        $this->assertSame(10, $row['rawmax']);
        $this->assertSame(80.0, $row['overallpct']);
    }

    /**
     * A rubric score row built from the given criteria, with the custom rubric in the context.
     *
     * @param array $criteria Criteria in the old shape.
     * @param array $override Fields of the score row to replace.
     * @return array The score_to_row result.
     */
    private function rubric_row(array $criteria, array $override = []): array {
        $context = $this->context(['rubric' => $this->fixture('criteria')['custom_rubric']]);

        $score = $this->sola_score('old_shape_rubric', ['scores' => json_encode($criteria)] + $override);

        return mapper::score_to_row($score, $context);
    }

    /**
     * One old shape criterion.
     *
     * @param string $name The criterion name.
     * @param int $score The score.
     * @return array
     */
    private function criterion(string $name, int $score): array {
        return ['name' => $name, 'score' => $score, 'feedback' => 'f'];
    }

    /**
     * A score above the rubric's maximum today proves the lookup wrong, so the row has no percentage.
     *
     * SOLA edits rubrics in place: a criterion that was out of 20 and is now out of 10 would otherwise turn a
     * score of 11 into a made up 100 percent.
     *
     * @return void
     */
    public function test_a_score_above_the_rubric_maximum_is_unreconstructed(): void {
        $row = $this->rubric_row([
            $this->criterion('Delivery & Fluency', 11),
            $this->criterion('Structure & Organization', 4),
        ]);

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(15, $row['rawsum']);
        $this->assertSame(6, $row['legacymeanscore']);
        $this->assertSame([null, 5], array_column(json_decode($row['scores'], true), 'max_score'));
    }

    /**
     * The same for a preset: a score above today's preset maximum isn't believed.
     *
     * @return void
     */
    public function test_a_score_above_the_preset_maximum_is_unreconstructed(): void {
        $scores = json_decode($this->sola_score('old_shape_preset')['scores'], true);
        $scores[4]['score'] = 6;

        $row = mapper::score_to_row($this->sola_score('old_shape_preset', ['scores' => json_encode($scores)]), $this->context());

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(20, $row['rawsum']);
        $this->assertSame(4, $row['legacymeanscore']);
        $this->assertSame([5, 5, 5, 5, null], array_column(json_decode($row['scores'], true), 'max_score'));
    }

    /**
     * A negative score can't have been given against any maximum, so a looked up one isn't believed either.
     *
     * @return void
     */
    public function test_a_negative_score_is_unreconstructed(): void {
        $rubric = $this->rubric_row([
            $this->criterion('Delivery & Fluency', -1),
            $this->criterion('Structure & Organization', 4),
        ]);
        $this->assertSame('unreconstructed', $rubric['scoreprovenance']);
        $this->assertNull($rubric['overallpct']);
        $this->assertSame(0, $rubric['rawmax']);

        $scores = json_decode($this->sola_score('old_shape_preset')['scores'], true);
        $scores[0]['score'] = -2;
        $preset = mapper::score_to_row($this->sola_score('old_shape_preset', ['scores' => json_encode($scores)]), $this->context());
        $this->assertSame('unreconstructed', $preset['scoreprovenance']);
        $this->assertNull($preset['overallpct']);
        $this->assertSame(0, $preset['rawmax']);
    }

    /**
     * Scores that fit under the looked up maximum, right up to it and down to 0, are still backfilled.
     *
     * @return void
     */
    public function test_scores_within_range_are_still_backfilled(): void {
        $row = $this->rubric_row([
            $this->criterion('Delivery & Fluency', 10),
            $this->criterion('Structure & Organization', 0),
            $this->criterion('Content & Relevance', 5),
        ]);

        $this->assertSame('rubric_backfill', $row['scoreprovenance']);
        $this->assertSame(15, $row['rawsum']);
        $this->assertSame(20, $row['rawmax']);
        $this->assertSame(75.0, $row['overallpct']);
        $this->assertSame([10, 5, 5], array_column(json_decode($row['scores'], true), 'max_score'));
    }

    /**
     * A criterion nobody assessed doesn't count, so an odd score on it doesn't take the row's percentage away.
     *
     * @return void
     */
    public function test_an_out_of_range_score_on_an_unassessed_criterion_does_not_block_the_row(): void {
        $row = $this->rubric_row([
            $this->criterion('Delivery & Fluency', 8),
            ['name' => 'Content & Relevance', 'score' => 9, 'feedback' => 'n', 'assessed' => false],
        ]);

        $this->assertSame('rubric_backfill', $row['scoreprovenance']);
        $this->assertSame(8, $row['rawsum']);
        $this->assertSame(10, $row['rawmax']);
        $this->assertSame(80.0, $row['overallpct']);
    }

    /**
     * A criterion's own stated maximum is taken as it stands, even with a score above it.
     *
     * Only looked up maxima are second guessed; the row's own figures are SOLA's record of what happened.
     *
     * @return void
     */
    public function test_an_exact_row_is_not_second_guessed(): void {
        $score = $this->sola_score('new_shape', [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 7, 'feedback' => 'x', 'max_score' => 5, 'assessed' => true],
            ]),
        ]);

        $row = mapper::score_to_row($score, $this->context());

        $this->assertSame('exact', $row['scoreprovenance']);
        $this->assertSame(7, $row['rawsum']);
        $this->assertSame(5, $row['rawmax']);
    }

    /**
     * A score row with no rubricid at all fails closed: there's no telling whether a rubric or a preset scored it.
     *
     * @return void
     */
    public function test_a_missing_rubricid_is_unreconstructed(): void {
        $score = $this->sola_score('old_shape_preset');
        unset($score['rubricid']);

        $row = mapper::score_to_row($score, $this->context());

        $this->assertSame('unreconstructed', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
        $this->assertSame(18, $row['rawsum']);
        $this->assertSame(4, $row['legacymeanscore']);

        $score['rubricid'] = null;
        $this->assertSame('unreconstructed', mapper::score_to_row($score, $this->context())['scoreprovenance']);
        $score['rubricid'] = -1;
        $this->assertSame('unreconstructed', mapper::score_to_row($score, $this->context())['scoreprovenance']);
    }

    /**
     * A missing rubricid costs an exact row nothing, since it never looks anything up.
     *
     * @return void
     */
    public function test_a_missing_rubricid_does_not_touch_an_exact_row(): void {
        $score = $this->sola_score('new_shape');
        unset($score['rubricid']);

        $this->assertSame('exact', mapper::score_to_row($score, $this->context())['scoreprovenance']);
    }

    /**
     * A video attempt is scored against the preset plus the two visual criteria, and is backfilled from both.
     *
     * Against the speech preset alone the visual criteria are in no list, and the row would be unreconstructed;
     * the source reader has to give the video preset for video attempts.
     *
     * @return void
     */
    public function test_a_video_attempt_is_backfilled_from_the_preset_with_the_visual_criteria(): void {
        $criteria = $this->fixture('criteria');
        $scores = json_decode($this->sola_score('old_shape_preset')['scores'], true);
        $scores[] = $this->criterion('Body Language & Gestures', 4);
        $scores[] = $this->criterion('Eye Contact & Camera Presence', 3);
        $score = $this->sola_score('old_shape_preset', ['scores' => json_encode($scores)]);

        $row = mapper::score_to_row($score, $this->context(['preset' => $criteria['video_preset']]));

        $this->assertSame('preset_backfill', $row['scoreprovenance']);
        $this->assertSame(25, $row['rawsum']);
        $this->assertSame(35, $row['rawmax']);
        $this->assertEqualsWithDelta(71.43, $row['overallpct'], 0.001);
        $this->assertSame([5, 5, 5, 5, 5, 5, 5], array_column(json_decode($row['scores'], true), 'max_score'));

        $speechonly = mapper::score_to_row($score, $this->context(['preset' => $criteria['general_preset']]));
        $this->assertSame('unreconstructed', $speechonly['scoreprovenance']);
        $this->assertNull($speechonly['overallpct']);
    }

    /**
     * Criteria nobody could judge, with stated maxima, give an exact row with no percentage.
     *
     * @return void
     */
    public function test_a_row_where_nothing_was_assessed_has_no_percentage(): void {
        $score = $this->sola_score('new_shape', [
            'scores' => json_encode([
                ['name' => 'Delivery & Fluency', 'score' => 0, 'feedback' => 'n', 'max_score' => 5, 'assessed' => false],
                ['name' => 'Time Management', 'score' => 0, 'feedback' => 'n', 'max_score' => 5, 'assessed' => false],
            ]),
            'overall_score' => 0,
        ]);

        $row = mapper::score_to_row($score, $this->context());

        $this->assertSame('exact', $row['scoreprovenance']);
        $this->assertSame(0, $row['rawsum']);
        $this->assertSame(0, $row['rawmax']);
        $this->assertNull($row['overallpct']);
    }

    /**
     * Scores that can't be read at all are unreconstructed, with an empty list rather than the broken text.
     *
     * @return void
     */
    public function test_unreadable_scores_are_unreconstructed(): void {
        foreach (['not json at all', '', '[]', '"a string"'] as $text) {
            $row = mapper::score_to_row($this->sola_score('new_shape', ['scores' => $text]), $this->context());

            $this->assertSame('unreconstructed', $row['scoreprovenance'], $text);
            $this->assertSame('[]', $row['scores'], $text);
            $this->assertSame(0, $row['rawsum'], $text);
            $this->assertSame(0, $row['rawmax'], $text);
            $this->assertNull($row['overallpct'], $text);
            $this->assertSame(4, $row['legacymeanscore'], $text);
        }
    }

    /**
     * The body language prose SOLA kept is dropped (D11) and appears nowhere in the row.
     *
     * @return void
     */
    public function test_visual_observation_is_never_imported(): void {
        $sola = $this->sola_score('new_shape');
        $this->assertStringContainsString('visual_observation', (string) $sola['session_meta']);

        $row = mapper::score_to_row($sola, $this->context());

        $meta = json_decode($row['legacymeta'], true);
        $this->assertArrayNotHasKey('visual_observation', $meta);
        $this->assertStringNotContainsString('lens', json_encode($row));
        $this->assertStringNotContainsString('visual_observation', json_encode($row));
        $expected = json_decode($sola['session_meta'], true);
        unset($expected['visual_observation']);
        $this->assertSame($expected, $meta);
    }

    /**
     * Tips become the activity's JSON list, and visual_assessed travels with the rest of the meta.
     *
     * @return void
     */
    public function test_tips_and_visual_assessed_are_carried(): void {
        $row = mapper::score_to_row($this->sola_score('new_shape'), $this->context());

        $this->assertSame(['Slow down at the close.', 'Pause after each point.'], json_decode($row['tips'], true));
        $meta = json_decode($row['legacymeta'], true);
        $this->assertTrue($meta['visual_assessed']);
        $this->assertSame(6, $meta['assessed_count']);
        $this->assertSame(83, $meta['pct']);
        $this->assertSame('Walking talk', $meta['name']);
    }

    /**
     * Empty and non-scalar tips are cleaned out, and the rest trimmed, as the activity cleans its own.
     *
     * @return void
     */
    public function test_tips_are_cleaned(): void {
        $meta = ['tips' => ['  Slow down.  ', '', ['x'], 'Pause.']];
        $row = mapper::score_to_row($this->sola_score('new_shape', ['session_meta' => json_encode($meta)]), $this->context());

        $this->assertSame('["Slow down.","Pause."]', $row['tips']);
    }

    /**
     * A row with no session meta still maps: no tips, no meta, and nothing else lost.
     *
     * @return void
     */
    public function test_a_row_with_no_session_meta(): void {
        $row = mapper::score_to_row($this->sola_score('null_meta'), $this->context());

        $this->assertNull($row['tips']);
        $this->assertNull($row['legacymeta']);
        $this->assertSame('preset_backfill', $row['scoreprovenance']);
        $this->assertSame(100.0, $row['overallpct']);
        $this->assertSame(5, $row['legacymeanscore']);
        $this->assertSame('Outstanding.', $row['feedback']);
    }

    /**
     * Meta that isn't a JSON object is treated as absent, since it can't be scrubbed of the observation.
     *
     * @return void
     */
    public function test_unreadable_session_meta_is_dropped(): void {
        foreach (['not json', '[1,2]', '"x"', ''] as $text) {
            $row = mapper::score_to_row($this->sola_score('new_shape', ['session_meta' => $text]), $this->context());

            $this->assertNull($row['legacymeta'], $text);
            $this->assertNull($row['tips'], $text);
        }
    }

    /**
     * The fixed fields of a migrated score: AI origin, released, ungraded by any person, and who it belongs to.
     *
     * @return void
     */
    public function test_score_row_fixed_fields(): void {
        $row = mapper::score_to_row($this->sola_score('new_shape'), $this->context(['recordingid' => 9001, 'userid' => 101]));

        $this->assertSame(9001, $row['recordingid']);
        $this->assertSame(101, $row['userid']);
        $this->assertSame(0, $row['rubricid']);
        $this->assertSame('ai', $row['origin']);
        $this->assertSame(1, $row['released']);
        $this->assertSame(0, $row['graderid']);
        $this->assertSame('Clear and confident.', $row['feedback']);
        $this->assertNull($row['visualsummary']);
        $this->assertSame('', $row['visualstatus']);
        $this->assertSame(501, $row['legacyscoreid']);
        $this->assertSame(1790500100, $row['timecreated']);
    }

    /**
     * The row has exactly the keys the runner will insert, no more and no fewer.
     *
     * @return void
     */
    public function test_score_row_keys(): void {
        $row = mapper::score_to_row($this->sola_score('new_shape'), $this->context());
        $keys = array_keys($row);
        sort($keys);

        $expected = [
            'feedback', 'graderid', 'legacymeanscore', 'legacymeta', 'legacyscoreid', 'origin', 'overallpct',
            'rawmax', 'rawsum', 'recordingid', 'released', 'rubricid', 'scoreprovenance', 'scores',
            'timecreated', 'tips', 'userid', 'visualstatus', 'visualsummary',
        ];
        $this->assertSame($expected, $keys);
    }

    /**
     * A missing overall comment stays missing rather than becoming the text "null".
     *
     * @return void
     */
    public function test_a_missing_overall_comment_stays_null(): void {
        $row = mapper::score_to_row($this->sola_score('new_shape', ['ai_feedback' => null]), $this->context());

        $this->assertNull($row['feedback']);
    }

    /**
     * Names are matched the way SOLA's score_speech::normalise_name() does: lower case, runs of space folded.
     *
     * @return void
     */
    public function test_criterion_names_are_normalised_like_sola(): void {
        $this->assertSame('delivery & fluency', mapper::normalise_name("  Delivery &\t Fluency \n"));
        $this->assertSame('', mapper::normalise_name('   '));
        $this->assertSame('éclat', mapper::normalise_name('ÉCLAT'));
    }
}
