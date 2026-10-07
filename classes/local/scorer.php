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

namespace mod_presenterai\local;

use mod_presenterai\local\ai\ai_exception;
use mod_presenterai\local\ai\json_parser;
use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\stt_client;
use mod_presenterai\local\ai\usage;
use mod_presenterai\local\vision\judge_unavailable_exception;
use mod_presenterai\local\vision\visual_pipeline;

/**
 * Turns a finished attempt into an AI score row.
 *
 * A port of SOLA's soapbox_scorer::score_recording() and the scoring half of
 * score_speech (classes/soapbox_scorer.php, classes/external/score_speech.php),
 * run from the score_recording adhoc task and never from a web request (D6).
 *
 * The order: transcribe (or reuse the stored transcript), ask slice 3 for the
 * visual evidence, resolve the rubric, drop the visual criteria unless the
 * evidence is usable, add the slides, call the scoring model, keep only the
 * criteria that were written into the prompt (the allowlist), let the visual
 * pipeline gate the learner facing visual strings, and save through
 * score_manager::save_ai_score(), which owns precedence over a teacher's row.
 *
 * Failure has two shapes. A transient one (a 429, a 5xx, a timeout, the
 * plugin's own rate limiter, or the judge being unreachable) is thrown while
 * $mayretry is true, so the task manager runs the task again with backoff and
 * the row stays 'scoring'. Anything else, or a transient failure once the
 * retries are spent, fails the row with one mtrace line naming the recording
 * and the reason, never a key, a URL or the transcript.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scorer {
    /** @var int A transcript shorter than this has nothing to score, as in SOLA. */
    public const MIN_TRANSCRIPT_CHARS = 40;

    /** @var int Output tokens allowed for the scoring call. */
    public const MAX_TOKENS = 4096;

    /** @var int Seconds the scoring call may take. */
    public const TIMEOUT = 120;

    /** @var int Times a rate limited attempt is queued again after its retries run out, before it fails. */
    public const MAX_DEFERRALS = 3;

    /**
     * Score one recording.
     *
     * Acts only on a row that is 'uploaded' or 'scoring'. A rescore also acts
     * on 'scored' and 'failed', but never on an attempt a teacher has scored.
     * An owner without mod/presenterai:useai, or a site with no AI ready, leaves
     * the row as it is.
     *
     * @param int $recordingid The recording id.
     * @param bool $mayretry Whether a transient failure should be thrown for the task to retry.
     * @param bool $rescore Whether this was asked for from the grading screen.
     * @param int $deferrals How many times a rate limit has already deferred this attempt.
     * @return void
     */
    public static function score(int $recordingid, bool $mayretry, bool $rescore = false, int $deferrals = 0): void {
        try {
            [$rec, $instance, $course, , $ctx] = recording_manager::load($recordingid);
        } catch (\moodle_exception $e) {
            // The attempt or its activity was deleted after the task was queued.
            return;
        }

        $status = (string) $rec->status;
        $acts = [recording_manager::STATUS_UPLOADED, recording_manager::STATUS_SCORING];
        if ($rescore) {
            $acts[] = score_manager::RECORDING_STATUS_SCORED;
            $acts[] = recording_manager::STATUS_FAILED;
        }
        if (!in_array($status, $acts, true)) {
            return;
        }
        if ($rescore && score_manager::has_teacher_score((int) $rec->id)) {
            return;
        }
        if (!has_capability('mod/presenterai:useai', $ctx, (int) $rec->userid)) {
            return;
        }
        if (!route_resolver::ai_ready()) {
            self::settle($rec);
            return;
        }

        self::set_status($rec, recording_manager::STATUS_SCORING);

        try {
            self::run($rec, $instance, $course, $ctx, $mayretry);
        } catch (judge_unavailable_exception $e) {
            // The visual pipeline fails closed by itself on an outage a retry
            // can't fix, so this is a transient one, or a stray throw.
            if ($mayretry && $e->transient) {
                throw $e;
            }
            self::fail($rec, 'judge_unavailable');
        } catch (ai_exception $e) {
            if ($mayretry && $e->transient) {
                throw $e;
            }
            if ($e->reason === ai_exception::RATE_LIMITED && $deferrals < self::MAX_DEFERRALS) {
                // The backoff (about 31 minutes) is shorter than the scoring
                // window (an hour), so failing here would fail an attempt the
                // limiter would have let through later. Queue it again for
                // when the window ends; the row stays 'scoring'.
                \mod_presenterai\task\score_recording::queue(
                    $recordingid,
                    $rescore,
                    time() + max(60, $e->retryafter),
                    $deferrals + 1
                );
                mtrace('PresenterAI scoring: recording ' . (int) $rec->id . ' is rate limited; queued again.');
                return;
            }
            self::fail($rec, $e->reason);
        } catch (\Throwable $e) {
            self::fail($rec, self::short_class($e));
        }
    }

    /**
     * The scoring work, which throws on any failure.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $ctx The activity's module context.
     * @param bool $mayretry Passed to the visual pipeline, which throws on a judge outage only while retries remain.
     * @return void
     */
    private static function run(
        \stdClass $rec,
        \stdClass $instance,
        \stdClass $course,
        \context_module $ctx,
        bool $mayretry
    ): void {
        $userid = (int) $rec->userid;

        $transcript = self::transcript($rec, $instance);
        if (\core_text::strlen($transcript) < self::MIN_TRANSCRIPT_CHARS) {
            throw new ai_exception('bad_response', false, 'The transcript is too short to score.');
        }

        $evidence = self::evidence($rec, $instance, $ctx);
        $evidenceok = ($evidence['state'] ?? '') === visual_pipeline::EVIDENCE_OK;

        // The visual criteria are only put in the prompt, and so only allowed
        // into the score, when there's usable evidence for them.
        $rubric = rubric_manager::resolve($instance, $ctx);
        $prompted = [];
        foreach ($rubric['criteria'] as $criterion) {
            if (!empty($criterion['visual']) && !$evidenceok) {
                continue;
            }
            $prompted[rubric_manager::normalise_name((string) $criterion['name'])] = $criterion;
        }
        if (empty($prompted)) {
            throw new ai_exception('bad_response', false, 'No criterion left to score.');
        }

        $slides = slide_context::build($rec, $instance, $ctx, $course);
        $visualblock = '';
        if ($evidenceok) {
            $visualscored = (int) ($instance->visualscored ?? 0) === 1;
            $visualnames = [];
            foreach ($prompted as $criterion) {
                if (!empty($criterion['visual'])) {
                    $visualnames[] = (string) $criterion['name'];
                }
            }
            $visualblock = visual_pipeline::prompt_block(
                $evidence,
                $visualscored,
                visual_pipeline::language_name($userid),
                $visualnames
            );
        }

        $prompt = scoring_prompt::build([
            'transcript' => $transcript,
            'criteria' => array_values($prompted),
            'level' => (string) ($instance->speakinglevel ?? ''),
            'ptype' => (string) ($instance->ptype ?? ''),
            'topictitle' => self::topic_title($rec),
            'targetseconds' => (int) ($instance->maxseconds ?? 0),
            'durationseconds' => (int) ($rec->durationseconds ?? 0),
            'slides' => (string) $slides['text'],
            'visualblock' => $visualblock,
            'wantsummary' => $evidenceok && $visualblock !== '',
        ]);

        $client = route_resolver::client_for(route_resolver::PURPOSE_SCORE);
        if ($client === null) {
            throw new ai_exception('not_configured', false);
        }
        if (rate_limiter::hit('score', $userid, rate_limiter::SCORE_MAX, rate_limiter::SCORE_WINDOW)) {
            throw self::rate_limited('score', $userid, rate_limiter::SCORE_WINDOW);
        }

        $opts = [
            'max_tokens' => self::MAX_TOKENS,
            'timeout' => self::TIMEOUT,
            'contextid' => (int) $ctx->id,
            'userid' => $userid,
        ];
        if ($client->supports_json_schema()) {
            $opts['schema'] = $prompt['schema'];
        }
        try {
            $raw = $client->generate_text($prompt['system'], $prompt['user'], $opts);
        } finally {
            // A call that threw, or whose answer won't parse, still cost the prompt.
            usage::record($client, usage::ACTION_SCORE, (int) $instance->id, (int) $rec->id, $userid);
        }

        $decoded = json_parser::decode_object((string) $raw, ['criteria']);
        if ($decoded === null || !is_array($decoded['criteria'])) {
            throw new ai_exception('bad_response', false, 'The scoring response was not the expected JSON.');
        }

        $criteria = self::allowlist($decoded['criteria'], $prompted);
        if (empty($criteria)) {
            throw new ai_exception('bad_response', false, 'The scoring response named no rubric criterion.');
        }
        if (!self::enough_spoken_assessed($criteria)) {
            // Most likely the learner's own words told the model to drop their
            // weak criteria from the denominator. A teacher can grade it by hand.
            throw new ai_exception('too_few_assessed', false, 'The scoring response left too few spoken criteria assessed.');
        }

        $summary = isset($decoded['visual_summary']) && is_scalar($decoded['visual_summary'])
            ? (string) $decoded['visual_summary']
            : '';
        $overall = isset($decoded['overall']) && is_scalar($decoded['overall']) ? trim((string) $decoded['overall']) : '';
        $tips = [];
        foreach ((array) ($decoded['tips'] ?? []) as $tip) {
            if (is_scalar($tip) && trim((string) $tip) !== '') {
                $tips[] = trim((string) $tip);
            }
        }
        // The pipeline gates every learner facing string while the prompt
        // carried the raw note, not only the visual ones (D21).
        $out = visual_pipeline::finalise($criteria, $summary, $evidence, $rec, $instance, $ctx, $mayretry, $overall, $tips);
        // The pipeline may rewrite feedback and assessed. The allowlist and the
        // rubric's flags are applied again, so nothing it returns can widen them.
        $criteria = self::reapply_rubric((array) ($out['criteria'] ?? []), $prompted);
        if (empty($criteria)) {
            throw new ai_exception('bad_response', false, 'No criterion survived the visual feedback gate.');
        }

        $feedback = (string) ($out['overall'] ?? '');
        $visionnote = trim((string) $slides['visionnote']);
        if ($visionnote !== '') {
            $feedback = trim($feedback . "\n\n" . get_string('slide_design_note', 'mod_presenterai') . ' ' . $visionnote);
        }
        $tips = array_values(array_map('strval', (array) ($out['tips'] ?? [])));

        $summaryout = $out['visualsummary'] ?? null;
        score_manager::save_ai_score(
            $rec,
            $instance,
            $ctx,
            (int) $rubric['rubricid'],
            $criteria,
            $feedback,
            $tips,
            $summaryout === null ? null : (string) $summaryout,
            (string) ($out['visualstatus'] ?? '')
        );
    }

    /**
     * Keep only the model's criteria that were written into the prompt, in the stored shape.
     *
     * Names are matched by rubric_manager::normalise_name() and stored as the
     * rubric spells them. max_score, visual and counts come from the rubric,
     * never from the model. A name returned twice keeps its first entry.
     *
     * assessed is coerced once, here, in the learner's favor for the spoken
     * criteria: an absent flag means assessed, so a model that ignores the
     * field marks as it did before the flag existed, and any falsy value means
     * not assessed. A visual criterion is the other way round (D23): an absent
     * flag means not assessed, so the model can't put a body language mark into
     * the record by leaving the flag out.
     *
     * @param array $returned The model's criteria list.
     * @param array $prompted normalized name => resolved rubric criterion, for those in the prompt.
     * @return array[] A list of ['name', 'score', 'max_score', 'feedback', 'assessed', 'visual', 'counts'].
     */
    public static function allowlist(array $returned, array $prompted): array {
        $out = [];
        foreach ($returned as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry) || !isset($entry['name']) || !is_scalar($entry['name'])) {
                continue;
            }
            $key = rubric_manager::normalise_name((string) $entry['name']);
            if (!isset($prompted[$key]) || isset($out[$key])) {
                continue;
            }
            $def = $prompted[$key];
            $visual = !empty($def['visual']);
            $max = max(1, (int) $def['max_score']);
            $score = isset($entry['score']) && is_numeric($entry['score']) ? (int) round((float) $entry['score']) : 0;

            if (!array_key_exists('assessed', $entry) || $entry['assessed'] === null) {
                $assessed = !$visual;
            } else {
                $assessed = self::truthy($entry['assessed']);
            }

            $out[$key] = [
                'name' => (string) $def['name'],
                'score' => max(0, min($max, $score)),
                'max_score' => $max,
                'feedback' => is_scalar($entry['feedback'] ?? null) ? trim((string) $entry['feedback']) : '',
                'assessed' => $assessed,
                'visual' => $visual,
                'counts' => !empty($def['counts']),
            ];
        }
        return array_values($out);
    }

    /**
     * Whether enough of the spoken criteria were assessed for the score to stand.
     *
     * The assessed only denominator means every criterion the model marks
     * "could not judge" leaves rawmax, so a learner who talks the model into
     * marking their weak criteria unassessed gets a higher percentage. Spoken
     * criteria are judged from a transcript at least 40 characters long, so
     * the model has little honest reason to drop many of them. At most half
     * (rounded down) may go; more than that fails the attempt, for a teacher
     * to grade, rather than letting the model shrink the denominator.
     *
     * @param array $criteria The allowlisted criteria.
     * @return bool
     */
    public static function enough_spoken_assessed(array $criteria): bool {
        $spoken = 0;
        $unassessed = 0;
        foreach ($criteria as $criterion) {
            if (!empty($criterion['visual'])) {
                continue;
            }
            $spoken++;
            if (empty($criterion['assessed'])) {
                $unassessed++;
            }
        }
        return $unassessed <= intdiv($spoken, 2);
    }

    /**
     * Re-apply the allowlist and the rubric's maxima and flags to the pipeline's output.
     *
     * @param array $criteria The criteria visual_pipeline::finalise() returned.
     * @param array $prompted normalized name => resolved rubric criterion.
     * @return array[] The stored shape.
     */
    private static function reapply_rubric(array $criteria, array $prompted): array {
        $out = [];
        foreach ($criteria as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_scalar($entry['name'])) {
                continue;
            }
            $key = rubric_manager::normalise_name((string) $entry['name']);
            if (!isset($prompted[$key]) || isset($out[$key])) {
                continue;
            }
            $def = $prompted[$key];
            $max = max(1, (int) $def['max_score']);
            $out[$key] = [
                'name' => (string) $def['name'],
                'score' => max(0, min($max, (int) ($entry['score'] ?? 0))),
                'max_score' => $max,
                'feedback' => is_scalar($entry['feedback'] ?? null) ? (string) $entry['feedback'] : '',
                'assessed' => ($entry['assessed'] ?? false) === true,
                'visual' => !empty($def['visual']),
                'counts' => !empty($def['counts']),
            ];
        }
        return array_values($out);
    }

    /**
     * The attempt's transcript, transcribing the media when there's none yet.
     *
     * A stored transcript is always reused, so a rescore after the media has
     * gone still works and nothing is paid for twice. Without one the media
     * must still exist (design 8.5: rescore stays possible, retranscribe does
     * not). The transcript is saved as soon as it's back, so a later failure
     * doesn't throw away what transcription cost.
     *
     * @param \stdClass $rec The recording row, whose transcript is updated in place.
     * @param \stdClass $instance The presenterai row.
     * @return string The transcript.
     */
    private static function transcript(\stdClass $rec, \stdClass $instance): string {
        global $DB;

        $transcript = trim((string) ($rec->transcript ?? ''));
        if ($transcript !== '') {
            return $transcript;
        }
        if (empty($rec->storagekey)) {
            throw new ai_exception('bad_response', false, 'The media is gone and there is no transcript.');
        }

        $stt = route_resolver::stt();
        if ($stt === null) {
            throw new ai_exception('not_configured', false);
        }
        $userid = (int) $rec->userid;
        if (rate_limiter::hit('transcribe', $userid, rate_limiter::TRANSCRIBE_MAX, rate_limiter::TRANSCRIBE_WINDOW)) {
            throw self::rate_limited('transcribe', $userid, rate_limiter::TRANSCRIBE_WINDOW);
        }

        // The separate audio track when there is one, else the recording, cut
        // into segments under the service's limit when it's needed and ffmpeg
        // is there to do it (transcription_source).
        $limit = $stt->route() === 'openai' ? stt_client::OPENAI_MAX_BYTES : 0;
        $prepared = transcription_source::prepare($rec, $limit);
        $texts = [];
        $model = $stt->model();
        try {
            foreach ($prepared['files'] as $file) {
                $result = $stt->transcribe($file['path'], $file['mime']);
                $model = (string) ($result['model'] ?? $model);
                $text = trim((string) ($result['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        } finally {
            if ($prepared['workdir'] !== '') {
                remove_dir($prepared['workdir']);
            }
        }
        usage::record($stt, usage::ACTION_TRANSCRIBE, (int) $instance->id, (int) $rec->id, $userid, [
            'route' => $stt->route(),
            'model' => $model,
            'audioseconds' => (int) ($rec->durationseconds ?? 0),
        ]);

        // Segments are joined in the order they were cut.
        $transcript = trim(implode(' ', $texts));
        $DB->update_record('presenterai_recording', (object) [
            'id' => (int) $rec->id,
            'transcript' => $transcript,
            'timemodified' => time(),
        ]);
        $rec->transcript = $transcript;

        return $transcript;
    }

    /**
     * The visual evidence for this attempt, from slice 3's pipeline.
     *
     * The pipeline promises not to throw. If it does anyway the attempt is
     * scored on its spoken criteria, as for any other evidence failure.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @return array ['state', 'note', 'confidence', 'unusable_frames']
     */
    private static function evidence(\stdClass $rec, \stdClass $instance, \context_module $ctx): array {
        try {
            return visual_pipeline::evidence($rec, $instance, $ctx, route_resolver::scoring_route());
        } catch (\Throwable $e) {
            return [
                'state' => visual_pipeline::EVIDENCE_UNAVAILABLE,
                'note' => '',
                'confidence' => 'low',
                'unusable_frames' => 0,
            ];
        }
    }

    /**
     * The attempt's topic title, or ''.
     *
     * @param \stdClass $rec The recording row.
     * @return string
     */
    private static function topic_title(\stdClass $rec): string {
        global $DB;

        if (empty($rec->topicid)) {
            return '';
        }
        return (string) $DB->get_field('presenterai_topic', 'title', ['id' => (int) $rec->topicid]);
    }

    /**
     * Mark the row failed, unless something else has scored it.
     *
     * An attempt that already has a score (a failed rescore, or a teacher who
     * scored it while the model was thinking) goes back to 'scored' instead,
     * because 'failed' takes an attempt out of the attempt count.
     *
     * @param \stdClass $rec The recording row.
     * @param string $reason A short reason for the log. Never a key, URL or transcript.
     * @return void
     */
    private static function fail(\stdClass $rec, string $reason): void {
        if (score_manager::current_score((int) $rec->id) !== null) {
            self::set_status($rec, score_manager::RECORDING_STATUS_SCORED);
        } else {
            self::set_status($rec, recording_manager::STATUS_FAILED);
        }
        $reason = clean_param($reason, PARAM_ALPHANUMEXT);
        mtrace('PresenterAI scoring: recording ' . (int) $rec->id . ' was not scored (' . $reason . ').');
    }

    /**
     * Put a row left 'scoring' by an earlier run back to where it was, when AI is switched off.
     *
     * @param \stdClass $rec The recording row.
     * @return void
     */
    private static function settle(\stdClass $rec): void {
        if ((string) $rec->status !== recording_manager::STATUS_SCORING) {
            return;
        }
        $to = score_manager::current_score((int) $rec->id) !== null
            ? score_manager::RECORDING_STATUS_SCORED
            : recording_manager::STATUS_UPLOADED;
        self::set_status($rec, $to);
    }

    /**
     * Set the row's status, keeping the in-memory copy in step.
     *
     * @param \stdClass $rec The recording row.
     * @param string $status The new status.
     * @return void
     */
    private static function set_status(\stdClass $rec, string $status): void {
        global $DB;

        $DB->update_record('presenterai_recording', (object) [
            'id' => (int) $rec->id,
            'status' => $status,
            'timemodified' => time(),
        ]);
        $rec->status = $status;
    }

    /**
     * The rate_limited exception for a bucket, carrying how long its window has left.
     *
     * @param string $bucket The limiter bucket.
     * @param int $userid The learner.
     * @param int $window The bucket's window, seconds.
     * @return ai_exception
     */
    private static function rate_limited(string $bucket, int $userid, int $window): ai_exception {
        $e = ai_exception::for_reason(ai_exception::RATE_LIMITED, 'Over the ' . $bucket . ' limit.');
        $e->retryafter = rate_limiter::seconds_until_reset($bucket, $userid, $window);
        return $e;
    }

    /**
     * Whether a model's flag means yes. Strings like "false" and "0" mean no.
     *
     * @param mixed $value The value.
     * @return bool
     */
    private static function truthy($value): bool {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
        }
        return (bool) $value;
    }

    /**
     * An exception's class name without its namespace, for a log line.
     *
     * @param \Throwable $e The exception.
     * @return string
     */
    private static function short_class(\Throwable $e): string {
        $parts = explode('\\', get_class($e));
        return (string) end($parts);
    }
}
