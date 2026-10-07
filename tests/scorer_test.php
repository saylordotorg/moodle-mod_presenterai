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

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\client_interface;
use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\stt_interface;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\score_manager;
use mod_presenterai\local\scorer;

/**
 * The scorer: transcription, the scoring call, the allowlist, and what it writes.
 *
 * No test here reaches a network. The scoring model and the transcriber are
 * doubles handed to route_resolver, which is the only place the scorer gets
 * either from.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\scorer
 */
final class scorer_test extends \advanced_testcase {
    /** @var string A transcript long enough to score. */
    private const TRANSCRIPT = 'Good afternoon. Today I will explain how wind turbines turn moving air into electricity, '
        . 'why their blades are shaped the way they are, and what that means for the cost of power.';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * One graded video activity, body language off, and one learner.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        route_resolver::reset_test_doubles();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
        ]);
        $this->ctx = \context_module::instance($this->instance->cmid);
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * Never leave a double behind for the next test class.
     *
     * @return void
     */
    protected function tearDown(): void {
        route_resolver::reset_test_doubles();
        parent::tearDown();
    }

    /**
     * A scoring model double that answers from a queue and records each call.
     *
     * @param array $answers Strings to return, or Throwables to throw, in order.
     * @param bool $schema Whether it claims structured output.
     * @return client_interface
     */
    public static function fake_client(array $answers, bool $schema = true): client_interface {
        return new class ($answers, $schema) implements client_interface {
            /** @var array Each call's [system, user, opts]. */
            public array $calls = [];

            /**
             * Hold the answers.
             *
             * @param array $answers Strings or Throwables.
             * @param bool $schema Whether structured output is claimed.
             */
            public function __construct(
                /** @var array Strings or Throwables. */
                private array $answers,
                /** @var bool Whether structured output is claimed. */
                private bool $schema
            ) {
            }

            /**
             * Answer the next queued item.
             *
             * @param string $system The system prompt.
             * @param string $user The user message.
             * @param array $opts The options.
             * @return string
             */
            public function generate_text(string $system, string $user, array $opts = []): string {
                $this->calls[] = [$system, $user, $opts];
                $next = array_shift($this->answers);
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return (string) $next;
            }

            /**
             * Whether images are accepted.
             *
             * @return bool
             */
            public function supports_images(): bool {
                return true;
            }

            /**
             * Whether structured output is supported.
             *
             * @return bool
             */
            public function supports_json_schema(): bool {
                return $this->schema;
            }

            /**
             * The route.
             *
             * @return string
             */
            public function route(): string {
                return 'openai';
            }

            /**
             * The model.
             *
             * @return string
             */
            public function model(): string {
                return 'fake-model';
            }

            /**
             * The last call's token usage.
             *
             * @return array
             */
            public function last_usage(): array {
                return ['prompttokens' => 1200, 'completiontokens' => 300, 'model' => 'fake-model', 'provider' => 'openai'];
            }
        };
    }

    /**
     * A transcriber double that answers from a queue and counts its calls.
     *
     * @param array $answers Transcript strings, or Throwables to throw, in order.
     * @return stt_interface
     */
    public static function fake_stt(array $answers): stt_interface {
        return new class ($answers) implements stt_interface {
            /** @var array Each call's [path, mime]. */
            public array $calls = [];

            /**
             * Hold the answers.
             *
             * @param array $answers Strings or Throwables.
             */
            public function __construct(
                /** @var array Strings or Throwables. */
                private array $answers
            ) {
            }

            /**
             * Answer the next queued item.
             *
             * @param string $filepath The media file.
             * @param string $mime Its type.
             * @return array
             */
            public function transcribe(string $filepath, string $mime): array {
                $this->calls[] = [$filepath, $mime];
                $next = array_shift($this->answers);
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return ['text' => (string) $next, 'model' => 'whisper-1'];
            }

            /**
             * The model.
             *
             * @return string
             */
            public function model(): string {
                return 'whisper-1';
            }

            /**
             * The route.
             *
             * @return string
             */
            public function route(): string {
                return 'openai';
            }
        };
    }

    /**
     * A model answer scoring the named criteria.
     *
     * @param array $criteria name => [score, assessed] (assessed null leaves the key out).
     * @return string JSON.
     */
    public static function answer(array $criteria): string {
        $list = [];
        foreach ($criteria as $name => [$score, $assessed]) {
            $entry = ['name' => $name, 'score' => $score, 'feedback' => 'Feedback on ' . $name . '.'];
            if ($assessed !== null) {
                $entry['assessed'] = $assessed;
            }
            $list[] = $entry;
        }
        return json_encode(['criteria' => $list, 'overall' => 'A clear talk.', 'tips' => ['Slow down.', 'Look up.', '']]);
    }

    /**
     * Every default criterion at 4 out of 5, assessed.
     *
     * @return string JSON.
     */
    private static function default_answer(): string {
        $scores = [];
        foreach (rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $scores[$criterion['name']] = [4, true];
        }
        return self::answer($scores);
    }

    /**
     * A finished attempt with its media stored on the File API.
     *
     * @param array $fields Overrides.
     * @return \stdClass The recording row.
     */
    private function recording_with_media(array $fields = []): \stdClass {
        $key = random_string(16) . '.webm';
        $rec = $this->gen->create_recording($fields + [
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => $key,
            'durationseconds' => 95,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->ctx->id,
            'component' => 'mod_presenterai',
            'filearea' => 'recording',
            'itemid' => $rec->id,
            'filepath' => '/',
            'filename' => $key,
        ], 'not really webm');
        return $rec;
    }

    /**
     * Run the scorer, swallowing its mtrace line.
     *
     * @param int $recordingid The recording.
     * @param bool $mayretry Whether transient failures throw.
     * @param bool $rescore Whether this is a rescore.
     * @param int $deferrals Rate limit deferrals so far.
     * @return string What it printed.
     */
    private function score(int $recordingid, bool $mayretry = true, bool $rescore = false, int $deferrals = 0): string {
        ob_start();
        try {
            scorer::score($recordingid, $mayretry, $rescore, $deferrals);
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /**
     * The row as stored.
     *
     * @param int $id The recording id.
     * @return \stdClass
     */
    private function reload(int $id): \stdClass {
        global $DB;
        return $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * A clean run transcribes, scores, saves the transcript and an AI row, and pushes the grade.
     *
     * @return void
     */
    public function test_success(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $stt = self::fake_stt([self::TRANSCRIPT]);
        $client = self::fake_client([self::default_answer()]);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media();

        $this->score((int) $rec->id);

        $after = $this->reload((int) $rec->id);
        $this->assertSame('scored', $after->status);
        $this->assertSame(self::TRANSCRIPT, $after->transcript);
        $this->assertCount(1, $stt->calls);
        $this->assertSame('video/webm', $stt->calls[0][1]);

        $rows = $DB->get_records('presenterai_score', ['recordingid' => $rec->id]);
        $this->assertCount(1, $rows);
        $score = reset($rows);
        $this->assertSame('ai', $score->origin);
        $this->assertSame(0, (int) $score->graderid);
        $this->assertSame((int) $score->id, (int) $after->scoreid);
        $this->assertSame(20, (int) $score->rawsum);
        $this->assertSame(25, (int) $score->rawmax);
        $this->assertEqualsWithDelta(80.0, (float) $score->overallpct, 0.001);
        $this->assertSame('A clear talk.', $score->feedback);
        $this->assertSame(['Slow down.', 'Look up.'], json_decode($score->tips, true));
        $this->assertNull($score->visualsummary);

        $grades = grade_get_grades($this->course->id, 'mod', 'presenterai', $this->instance->id, $this->learner->id);
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$this->learner->id]->grade, 0.001);

        // The request: system and user split, contextid and userid always, the schema when supported.
        [$system, $user, $opts] = $client->calls[0];
        $this->assertStringEndsWith('Produce the feedback JSON now.', $user);
        $this->assertStringContainsString('RUBRIC:', $system);
        $this->assertStringContainsString("<transcript>\n" . self::TRANSCRIPT . "\n</transcript>", $user);
        $this->assertStringNotContainsString(self::TRANSCRIPT, $system);
        $this->assertSame((int) $this->ctx->id, $opts['contextid']);
        $this->assertSame((int) $this->learner->id, $opts['userid']);
        $this->assertSame('presentation_feedback', $opts['schema']['name']);
    }

    /**
     * A client without structured output gets no schema, and the prompt still asks for JSON.
     *
     * @return void
     */
    public function test_no_schema_when_unsupported(): void {
        $client = self::fake_client([self::default_answer()], false);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);

        $this->assertSame('scored', $this->reload((int) $rec->id)->status);
        $this->assertArrayNotHasKey('schema', $client->calls[0][2]);
        $this->assertStringContainsString('Respond with JSON only', $client->calls[0][0]);
    }

    /**
     * Spend is recorded for transcription and for scoring, against the learner.
     *
     * @return void
     */
    public function test_usage_rows(): void {
        global $DB;

        route_resolver::set_test_stt(self::fake_stt([self::TRANSCRIPT]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::default_answer()]));
        $rec = $this->recording_with_media();

        $this->score((int) $rec->id);

        $transcribe = $DB->get_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'transcribe']);
        $this->assertCount(1, $transcribe);
        $row = reset($transcribe);
        $this->assertSame(95, (int) $row->audioseconds);
        $this->assertSame((int) $this->learner->id, (int) $row->userid);
        $this->assertSame((int) $this->instance->id, (int) $row->presenteraiid);

        $score = $DB->get_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'score']);
        $this->assertCount(1, $score);
        $this->assertSame((int) $this->learner->id, (int) reset($score)->userid);
    }

    /**
     * Only assessed criteria are in the sums; an unassessed one leaves both.
     *
     * @return void
     */
    public function test_assessed_only_arithmetic(): void {
        global $DB;

        $scores = [];
        foreach (rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $scores[$criterion['name']] = [5, true];
        }
        $scores['Time Management'] = [0, false];
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::answer($scores)]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);

        $score = $DB->get_record('presenterai_score', ['recordingid' => $rec->id], '*', MUST_EXIST);
        $this->assertSame(20, (int) $score->rawsum);
        $this->assertSame(20, (int) $score->rawmax, 'The unassessed criterion inflated the maximum.');
        $this->assertEqualsWithDelta(100.0, (float) $score->overallpct, 0.001);
        $stored = score_manager::decode_criteria($score);
        $this->assertFalse($stored[4]['assessed']);
    }

    /**
     * A reply that drops most spoken criteria from the denominator fails for a teacher, not a 100 percent.
     *
     * A learner who tells the model, in their talk or on a slide, to mark
     * every criterion but Content as not assessed would otherwise get full
     * marks on Content alone.
     *
     * @return void
     */
    public function test_too_few_spoken_assessed_fails(): void {
        global $DB;

        $scores = [];
        foreach (rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $scores[$criterion['name']] = [0, false];
        }
        $first = rubric_manager::DEFAULT_CRITERIA[0]['name'];
        $scores[$first] = [5, true];
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::answer($scores)]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $out = $this->score((int) $rec->id);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
        $this->assertStringContainsString('too_few_assessed', $out);
        $this->assertFalse($DB->record_exists('presenterai_score', ['recordingid' => $rec->id]));

        // At most half, rounded down, may leave.
        $five = array_fill(0, 5, ['assessed' => true, 'visual' => false]);
        $this->assertTrue(scorer::enough_spoken_assessed($five));
        $five[0]['assessed'] = $five[1]['assessed'] = false;
        $this->assertTrue(scorer::enough_spoken_assessed($five));
        $five[2]['assessed'] = false;
        $this->assertFalse(scorer::enough_spoken_assessed($five));
        // Visual criteria are outside the count (D23 has its own rules for them).
        $five[] = ['assessed' => false, 'visual' => true];
        $five[2]['assessed'] = true;
        $this->assertTrue(scorer::enough_spoken_assessed($five));
    }

    /**
     * An invented criterion and a visual one returned without visual evidence never reach the score.
     *
     * @return void
     */
    public function test_allowlist_drops_invented_and_unevidenced_visual(): void {
        global $DB;

        // A course video rubric with one visual criterion; this activity has body language off.
        $this->gen->create_rubric([
            'contextid' => \context_course::instance($this->course->id)->id,
            'type' => 'video',
            'title' => 'Course video rubric',
            'criteria' => json_encode([
                ['name' => 'Content', 'description' => 'What you said.', 'max_score' => 5, 'visual' => false],
                ['name' => 'Delivery', 'description' => 'How you said it.', 'max_score' => 5, 'visual' => false],
                ['name' => 'Body Language & Gestures', 'description' => 'Hands.', 'max_score' => 5, 'visual' => true],
            ]),
        ]);
        $client = self::fake_client([self::answer([
            'content' => [3, true],
            'Delivery' => [4, true],
            'Hair Styling' => [5, true],
            'Body Language & Gestures' => [5, true],
        ])]);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);

        $score = $DB->get_record('presenterai_score', ['recordingid' => $rec->id], '*', MUST_EXIST);
        $names = array_column(score_manager::decode_criteria($score), 'name');
        $this->assertSame(['Content', 'Delivery'], $names, 'Only criteria written into the prompt may be scored.');
        $this->assertSame(7, (int) $score->rawsum);
        $this->assertSame(10, (int) $score->rawmax);
        $system = $client->calls[0][0];
        $this->assertStringNotContainsString('Body Language', $system, 'A visual criterion was prompted without evidence.');
    }

    /**
     * The allowlist itself: matching, maxima, flags, and the two assessed defaults.
     *
     * @return void
     */
    public function test_allowlist_rules(): void {
        $prompted = [
            'pace' => ['name' => 'Pace', 'max_score' => 4, 'visual' => false, 'counts' => true],
            'body language & gestures' => [
                'name' => 'Body Language & Gestures', 'max_score' => 5, 'visual' => true, 'counts' => false,
            ],
        ];
        $out = scorer::allowlist([
            ['name' => '  PACE ', 'score' => 9, 'feedback' => ' Steady. ', 'max_score' => 100, 'counts' => true],
            ['name' => 'Pace', 'score' => 1, 'feedback' => 'Second copy.'],
            ['name' => 'Body   Language & Gestures', 'score' => 4, 'feedback' => 'Open hands.', 'counts' => true],
            ['name' => 'Hair Styling', 'score' => 5, 'assessed' => true],
            'junk',
        ], $prompted);

        $this->assertSame([
            ['name' => 'Pace', 'score' => 4, 'max_score' => 4, 'feedback' => 'Steady.', 'assessed' => true,
                'visual' => false, 'counts' => true],
            ['name' => 'Body Language & Gestures', 'score' => 4, 'max_score' => 5, 'feedback' => 'Open hands.',
                'assessed' => false, 'visual' => true, 'counts' => false],
        ], $out, 'Absent assessed is true for a spoken criterion and false for a visual one (D23).');

        $falsy = scorer::allowlist([
            ['name' => 'Pace', 'score' => 2, 'assessed' => 'false'],
            ['name' => 'Body Language & Gestures', 'score' => 2, 'assessed' => 0],
        ], $prompted);
        $this->assertFalse($falsy[0]['assessed']);
        $this->assertFalse($falsy[1]['assessed']);

        $truthy = scorer::allowlist([['name' => 'Body Language & Gestures', 'score' => 2, 'assessed' => true]], $prompted);
        $this->assertTrue($truthy[0]['assessed']);
        $this->assertFalse($truthy[0]['counts'], 'counts comes from the rubric, never from the model.');
    }

    /**
     * An answer that isn't JSON fails the row at once, and both calls are still paid for.
     *
     * @return void
     */
    public function test_parse_failure(): void {
        global $DB;

        route_resolver::set_test_stt(self::fake_stt([self::TRANSCRIPT]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client(['I would rather not.']));
        $rec = $this->recording_with_media();

        $out = $this->score((int) $rec->id, true);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
        $this->assertSame(self::TRANSCRIPT, $this->reload((int) $rec->id)->transcript, 'The transcript was thrown away.');
        $this->assertSame(0, $DB->count_records('presenterai_score', ['recordingid' => $rec->id]));
        $this->assertSame(1, $DB->count_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'transcribe']));
        $this->assertSame(1, $DB->count_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'score']));
        $this->assertStringContainsString('recording ' . $rec->id, $out);
        $this->assertStringNotContainsString($rec->storagekey, $out, 'A storage key reached the task log.');
        $this->assertStringNotContainsString('wind turbines', $out, 'The transcript reached the task log.');
    }

    /**
     * Valid JSON with no rubric criterion in it is a failure too.
     *
     * @return void
     */
    public function test_answer_naming_no_criterion_fails(): void {
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([
            self::answer(['Hair Styling' => [5, true]]),
        ]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
    }

    /**
     * A transient model failure is thrown for the task to retry, with the spend recorded and the row left scoring.
     *
     * @return void
     */
    public function test_transient_client_failure_is_rethrown(): void {
        global $DB;

        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([
            new ai_exception('http_5xx', true),
        ]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        try {
            $this->score((int) $rec->id, true);
            $this->fail('A transient failure was swallowed while retries remained.');
        } catch (ai_exception $e) {
            $this->assertSame('http_5xx', $e->reason);
        }
        $this->assertSame('scoring', $this->reload((int) $rec->id)->status);
        $this->assertSame(1, $DB->count_records('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => 'score']));
    }

    /**
     * Without retries left, the same failure fails the row.
     *
     * @return void
     */
    public function test_transient_failure_without_retry_fails(): void {
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([
            new ai_exception('timeout', true),
        ]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id, false);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
    }

    /**
     * A refusal is never retried.
     *
     * @return void
     */
    public function test_non_transient_failure_fails_at_once(): void {
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([
            new ai_exception('refusal', false),
        ]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id, true);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
    }

    /**
     * The plugin's own transcription limit is a transient failure.
     *
     * @return void
     */
    public function test_transcription_rate_limit_is_transient(): void {
        $stt = self::fake_stt([self::TRANSCRIPT]);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::default_answer()]));
        $rec = $this->recording_with_media();
        for ($i = 0; $i < rate_limiter::TRANSCRIBE_MAX; $i++) {
            $userid = (int) $this->learner->id;
            rate_limiter::hit('transcribe', $userid, rate_limiter::TRANSCRIBE_MAX, rate_limiter::TRANSCRIBE_WINDOW);
        }

        try {
            $this->score((int) $rec->id, true);
            $this->fail('A rate limited transcription was not retried.');
        } catch (ai_exception $e) {
            $this->assertTrue($e->transient);
        }
        $this->assertCount(0, $stt->calls);
        $this->assertSame('scoring', $this->reload((int) $rec->id)->status);
    }

    /**
     * Rate limited with the backoff spent: queued again for when the window ends, never failed, and never counted.
     *
     * @return void
     */
    public function test_rate_limited_without_retry_is_deferred_not_failed(): void {
        global $DB;

        route_resolver::set_test_stt(self::fake_stt([]));
        $client = self::fake_client([self::default_answer()]);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);
        $userid = (int) $this->learner->id;
        $start = time();
        rate_limiter::set_test_now($start);
        try {
            for ($i = 0; $i < rate_limiter::SCORE_MAX; $i++) {
                rate_limiter::hit('score', $userid, rate_limiter::SCORE_MAX, rate_limiter::SCORE_WINDOW);
            }
            // A run about 31 minutes in, with no retries left.
            rate_limiter::set_test_now($start + 1860);
            $DB->delete_records('task_adhoc', ['classname' => '\\mod_presenterai\\task\\score_recording']);
            $this->score((int) $rec->id, false);

            $this->assertSame('scoring', $this->reload((int) $rec->id)->status, 'A rate limited attempt was failed.');
            $this->assertCount(0, $client->calls);
            $tasks = \core\task\manager::get_adhoc_tasks('\\mod_presenterai\\task\\score_recording');
            $this->assertCount(1, $tasks);
            $task = reset($tasks);
            $this->assertSame(1, (int) $task->get_custom_data()->deferrals);
            $this->assertGreaterThanOrEqual(time() + 60, (int) $task->get_next_run_time());

            // The refused call wasn't counted: once the window ends the learner has the full allowance.
            $this->assertSame(
                rate_limiter::SCORE_WINDOW - 1860,
                rate_limiter::seconds_until_reset('score', $userid, rate_limiter::SCORE_WINDOW)
            );
            rate_limiter::set_test_now($start + rate_limiter::SCORE_WINDOW);
            $this->score((int) $rec->id, false, false, 1);
            $this->assertSame('scored', $this->reload((int) $rec->id)->status);

            // Out of deferrals, a rate limit fails the row as before.
            $other = $this->recording_with_media(['transcript' => self::TRANSCRIPT, 'attemptnumber' => 2]);
            for ($i = 0; $i < rate_limiter::SCORE_MAX; $i++) {
                rate_limiter::hit('score', $userid, rate_limiter::SCORE_MAX, rate_limiter::SCORE_WINDOW);
            }
            $this->score((int) $other->id, false, false, scorer::MAX_DEFERRALS);
            $this->assertSame('failed', $this->reload((int) $other->id)->status);
        } finally {
            rate_limiter::set_test_now(null);
        }
    }

    /**
     * A transcript under 40 characters isn't scored.
     *
     * @return void
     */
    public function test_short_transcript_fails(): void {
        $client = self::fake_client([self::default_answer()]);
        route_resolver::set_test_stt(self::fake_stt(['Hello.']));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media();

        $this->score((int) $rec->id);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
        $this->assertCount(0, $client->calls, 'A transcript too short to score was sent to the model.');
    }

    /**
     * A stored transcript is reused, so nothing is transcribed twice.
     *
     * @return void
     */
    public function test_stored_transcript_is_reused(): void {
        $stt = self::fake_stt([]);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::default_answer()]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);

        $this->assertCount(0, $stt->calls);
        $this->assertSame('scored', $this->reload((int) $rec->id)->status);
    }

    /**
     * With the media gone and no transcript there is nothing to score from.
     *
     * @return void
     */
    public function test_no_media_and_no_transcript_fails(): void {
        $stt = self::fake_stt([self::TRANSCRIPT]);
        route_resolver::set_test_stt($stt);
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([self::default_answer()]));
        $rec = $this->gen->create_recording([
            'presenteraiid' => $this->instance->id,
            'userid' => $this->learner->id,
            'storagekey' => null,
            'mediagonereason' => 'retention',
        ]);

        $this->score((int) $rec->id);

        $this->assertSame('failed', $this->reload((int) $rec->id)->status);
        $this->assertCount(0, $stt->calls);
    }

    /**
     * With no AI ready the row is left uploaded; a learner without useai is left alone.
     *
     * @return void
     */
    public function test_not_ready_and_no_useai_leave_the_row(): void {
        global $DB;

        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);
        $this->score((int) $rec->id);
        $this->assertSame('uploaded', $this->reload((int) $rec->id)->status);

        $client = self::fake_client([self::default_answer()]);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/presenterai:useai', CAP_PROHIBIT, $studentrole, $this->ctx->id, true);

        $this->score((int) $rec->id);

        $this->assertSame('uploaded', $this->reload((int) $rec->id)->status);
        $this->assertCount(0, $client->calls);
    }

    /**
     * Without a rescore, only uploaded and scoring rows are acted on.
     *
     * @return void
     */
    public function test_status_guard(): void {
        $client = self::fake_client([self::default_answer(), self::default_answer()]);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);

        foreach (['scored', 'failed', 'uploading', 'abandoned'] as $status) {
            $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT, 'status' => $status]);
            $this->score((int) $rec->id);
            $this->assertSame($status, $this->reload((int) $rec->id)->status);
        }
        $this->assertCount(0, $client->calls);

        // A retry of a run that threw is still 'scoring', and goes ahead.
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT, 'status' => 'scoring']);
        $this->score((int) $rec->id);
        $this->assertSame('scored', $this->reload((int) $rec->id)->status);
    }

    /**
     * A rescore never touches an attempt a teacher has scored, and never asks the model.
     *
     * @return void
     */
    public function test_rescore_refuses_a_teacher_scored_attempt(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);
        $teacherrow = score_manager::save_teacher_score($rec, $this->instance, $this->ctx, (int) $teacher->id, 0, [
            ['name' => 'Delivery & Fluency', 'max_score' => 5, 'score' => 2, 'assessed' => true, 'feedback' => ''],
        ], 'Teacher feedback.');
        $before = $this->reload((int) $rec->id);

        $client = self::fake_client([self::default_answer()]);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $this->score((int) $rec->id, true, true);

        $this->assertCount(0, $client->calls);
        $this->assertSame(0, $DB->count_records('presenterai_score', ['recordingid' => $rec->id, 'origin' => 'ai']));
        $after = $this->reload((int) $rec->id);
        $this->assertSame($before->status, $after->status);
        $this->assertSame((int) $teacherrow->id, (int) $after->scoreid);
        $current = score_manager::current_score((int) $rec->id);
        $this->assertSame((int) $teacherrow->id, (int) $current->id);
        $this->assertSame(2, (int) $current->rawsum);
        $this->assertSame(5, (int) $current->rawmax);
    }

    /**
     * A rescore replaces the current AI score; a failed one puts the attempt back to scored.
     *
     * @return void
     */
    public function test_rescore_of_an_ai_scored_attempt(): void {
        global $DB;

        $scores = [];
        foreach (rubric_manager::DEFAULT_CRITERIA as $criterion) {
            $scores[$criterion['name']] = [2, true];
        }
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, self::fake_client([
            self::default_answer(),
            self::answer($scores),
            'not json',
        ]));
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT]);

        $this->score((int) $rec->id);
        $first = score_manager::current_score((int) $rec->id);
        $this->assertSame(20, (int) $first->rawsum);

        $this->score((int) $rec->id, true, true);
        $second = score_manager::current_score((int) $rec->id);
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertSame(10, (int) $second->rawsum);
        $this->assertSame((int) $second->id, (int) $this->reload((int) $rec->id)->scoreid);
        $this->assertSame(2, $DB->count_records('presenterai_score', ['recordingid' => $rec->id, 'origin' => 'ai']));

        $this->score((int) $rec->id, true, true);
        $status = $this->reload((int) $rec->id)->status;
        $this->assertSame('scored', $status, 'A failed rescore took a scored attempt out of the count.');
        $this->assertSame((int) $second->id, (int) score_manager::current_score((int) $rec->id)->id);
    }

    /**
     * The speaking level and presentation type steer the prompt.
     *
     * @return void
     */
    public function test_level_and_type_reach_the_prompt(): void {
        global $DB;

        $DB->update_record('presenterai', (object) [
            'id' => $this->instance->id,
            'speakinglevel' => 'esl_beginner',
            'ptype' => 'persuasive',
        ]);
        $topic = $this->gen->create_topic(['presenteraiid' => $this->instance->id, 'title' => 'Renewable energy']);
        $scores = [];
        foreach (rubric_manager::preset_criteria('esl_beginner', false) as $criterion) {
            $scores[$criterion['name']] = [3, true];
        }
        $client = self::fake_client([self::answer($scores)]);
        route_resolver::set_test_stt(self::fake_stt([]));
        route_resolver::set_test_client(route_resolver::PURPOSE_SCORE, $client);
        $rec = $this->recording_with_media(['transcript' => self::TRANSCRIPT, 'topicid' => $topic->id]);

        $this->score((int) $rec->id);

        $system = $client->calls[0][0];
        $this->assertStringContainsString('beginner-level English-as-a-second-language', $system);
        $this->assertStringContainsString('PERSUASIVE', $system);
        $this->assertStringContainsString('"Renewable energy"', $system);
        $this->assertStringContainsString('- Pronunciation & Intelligibility:', $system);
        $this->assertSame('scored', $this->reload((int) $rec->id)->status);
    }

    /**
     * On the core route a video attempt with body language and slide design
     * on is scored from the transcript alone: no vision, slide vision or
     * judge call, nothing withheld, and the learner told the frames weren't
     * analyzed (D26). Vendor keys set on the site make no difference.
     *
     * @return void
     */
    public function test_core_route_scores_without_vision_or_judge(): void {
        global $DB;

        set_config('airoute', 'core', 'mod_presenterai');
        set_config('claudeapikey', 'c', 'mod_presenterai');
        set_config('openaiapikey', 'o', 'mod_presenterai');
        $prompts = [];
        \mod_presenterai\local\ai\core_ai_client::set_test_processor(
            function (int $contextid, int $userid, string $prompt) use (&$prompts): array {
                $prompts[] = $prompt;
                return ['success' => true, 'text' => self::default_answer(), 'errorcode' => 0];
            }
        );
        route_resolver::set_test_stt(self::fake_stt([self::TRANSCRIPT]));

        $instance = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $this->course->id,
            'grade' => 100,
            'videovision' => 1,
            'slidesenabled' => 1,
            'slidevision' => 1,
        ]);
        $this->instance = $instance;
        $this->ctx = \context_module::instance($instance->cmid);
        $rec = $this->recording_with_media(['presenteraiid' => $instance->id, 'frameskey' => 'sheet.jpg']);

        try {
            $this->score((int) $rec->id);
        } finally {
            \mod_presenterai\local\ai\core_ai_client::set_test_processor(null);
        }

        $after = $this->reload((int) $rec->id);
        $this->assertSame('scored', $after->status);
        $this->assertCount(1, $prompts, 'Only the scoring call went to core.');
        $score = $DB->get_record('presenterai_score', ['recordingid' => $rec->id], '*', MUST_EXIST);
        $this->assertSame('notanalysed', $score->visualstatus);
        $this->assertNull($score->visualsummary);
        $this->assertStringNotContainsString(get_string('feedback_withheld', 'mod_presenterai'), (string) $score->feedback);
        $this->assertGreaterThan(0, (int) $score->rawmax);
        foreach (['video_vision', 'slide_vision', 'judge'] as $action) {
            $this->assertFalse(
                $DB->record_exists('presenterai_aiusage', ['recordingid' => $rec->id, 'action' => $action]),
                $action . ' ran on the core route.'
            );
        }
        $this->assertFalse($DB->record_exists('presenterai_gatelog', ['recordingid' => $rec->id]));
    }
}
