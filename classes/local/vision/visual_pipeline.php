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

namespace mod_presenterai\local\vision;

use mod_presenterai\event\visual_summary_judge_unavailable;
use mod_presenterai\event\visual_summary_rejected;
use mod_presenterai\local\ai\rate_limiter;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\ai\usage;

/**
 * Body language feedback from frames to learner facing words, for the scorer to call.
 *
 * Three calls, in the order the scorer makes them (design 3.2):
 *
 * evidence() before the scoring call: decides whether there is usable visual
 * evidence for this attempt, running the vision pass and layer 0 of the gate,
 * and stores the raw note for staff on the visualdatadays clock (D5, D21).
 * It never throws. Its state says why there is or isn't evidence, because the
 * learner is told different things for different causes (design 6).
 *
 * prompt_block() for the scoring prompt: the evidence, the D23 rule for how
 * the two visual criteria count, and the summary instruction (prompt 2).
 * Empty unless the evidence is usable, so the model is never in a position to
 * guess at body language from a transcript.
 *
 * finalise() after the scoring call: runs the gate (layers 1 to 4) over the
 * summary and the visual criteria's feedback, replaces whatever it rejects,
 * and says which visual section the learner sees.
 *
 * On the core AI scoring route there is no body language feedback at all
 * (design 3.6): core stores every prompt forever in ai_action_generate_text,
 * outside every clock D5 built, and has no schema to compel the summary. So
 * evidence() answers unavailable there without calling anything.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class visual_pipeline {
    /** @var string No visual section: the activity takes no frames for this attempt. */
    public const STATUS_NONE = '';

    /** @var string The gated model summary is shown. */
    public const STATUS_SUMMARY = 'summary';

    /** @var string The deterministic template is shown in place of the summary (design 6 b). */
    public const STATUS_FALLBACK = 'fallback';

    /** @var string No usable evidence and the learner's setup is the reason (design 6 a1). */
    public const STATUS_NOTASSESSED = 'notassessed';

    /** @var string No usable evidence and nothing the learner did caused it (design 6 a2). */
    public const STATUS_NOTANALYSED = 'notanalysed';

    /** @var string The learner opted this attempt out (D24). */
    public const STATUS_OPTEDOUT = 'optedout';

    /** @var string No frames are taken for this attempt at all. */
    public const EVIDENCE_NONE = 'none';

    /** @var string The learner opted out (D24). */
    public const EVIDENCE_OPTEDOUT = 'optedout';

    /** @var string Frames were missing, dark or unreadable (layer 0). */
    public const EVIDENCE_UNUSABLE = 'unusable';

    /** @var string The site could not analyse them: core route, no client, rate limit, fetch or call failure. */
    public const EVIDENCE_UNAVAILABLE = 'unavailable';

    /** @var string Usable evidence. */
    public const EVIDENCE_OK = 'ok';

    /** @var int Frames the model may call unusable before the evidence is (design 4.2). */
    public const MAX_UNUSABLE_FRAMES = 2;

    /** @var int Default days the raw note is kept (design 7.1). */
    public const DEFAULT_VISUAL_DATA_DAYS = 30;

    /** @var string[] The seed visual criteria, recognised by name when a criterion has no visual flag. */
    public const VISUAL_CRITERION_NAMES = ['Body Language & Gestures', 'Eye Contact & Camera Presence'];

    /**
     * Whether there is usable visual evidence for this attempt, and what it says.
     *
     * Never throws. The order of the checks is the order of the causes a
     * learner is told about: an activity that takes no frames, an opt out,
     * then the site's reasons (unavailable) and the learner's (unusable).
     *
     * @param \stdClass $rec The recording row. visualevidence and visualevidenceat are updated in place when stored.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The module context.
     * @param string $scoringroute The route the scoring call will take, route_resolver::scoring_route().
     * @return array ['state' => EVIDENCE_*, 'note' => string, 'confidence' => string, 'unusable_frames' => int]
     */
    public static function evidence(\stdClass $rec, \stdClass $instance, \context_module $ctx, string $scoringroute): array {
        try {
            return self::evidence_unguarded($rec, $instance, $ctx, $scoringroute);
        } catch (\Throwable $e) {
            debugging(
                'mod_presenterai visual evidence failed for recording ' . (int) $rec->id . ': ' . get_class($e),
                DEBUG_DEVELOPER
            );
            return self::state(self::EVIDENCE_UNAVAILABLE);
        }
    }

    /**
     * evidence(), allowed to throw.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The module context.
     * @param string $scoringroute The scoring route.
     * @return array As evidence().
     */
    private static function evidence_unguarded(
        \stdClass $rec,
        \stdClass $instance,
        \context_module $ctx,
        string $scoringroute
    ): array {
        if (!self::takes_frames($instance) || (string) ($rec->mode ?? 'video') === 'audio') {
            return self::state(self::EVIDENCE_NONE);
        }
        if (!empty($rec->visualoptout)) {
            // D24: nothing was uploaded and nothing is called.
            return self::state(self::EVIDENCE_OPTEDOUT);
        }
        if ($scoringroute === 'core') {
            // Design 3.6: the raw note never goes into a prompt core will keep forever.
            return self::state(self::EVIDENCE_UNAVAILABLE);
        }
        if (empty($rec->frameskey)) {
            return self::state(self::EVIDENCE_UNUSABLE);
        }
        $client = route_resolver::client_for(route_resolver::PURPOSE_VISION);
        if ($client === null) {
            return self::state(self::EVIDENCE_UNAVAILABLE);
        }
        $owner = (int) $rec->userid;
        if (rate_limiter::hit('video_vision', $owner, rate_limiter::VISION_MAX, rate_limiter::VISION_WINDOW)) {
            return self::state(self::EVIDENCE_UNAVAILABLE);
        }

        $called = false;
        try {
            $seen = video_vision::observe($rec, $instance, $ctx, $client, $called);
        } catch (\Throwable $e) {
            debugging(
                'mod_presenterai vision pass failed for recording ' . (int) $rec->id . ': ' . get_class($e),
                DEBUG_DEVELOPER
            );
            $seen = null;
        } finally {
            if ($called) {
                try {
                    usage::record(
                        $client,
                        usage::ACTION_VIDEO_VISION,
                        (int) $instance->id,
                        (int) $rec->id,
                        $owner,
                        ['imagecount' => 1]
                    );
                } catch (\Throwable $e) {
                    debugging('mod_presenterai could not record vision usage: ' . get_class($e), DEBUG_DEVELOPER);
                }
            }
        }
        if ($seen === null) {
            return self::state(self::EVIDENCE_UNAVAILABLE);
        }

        // Layer 0. The luminance half is evidence; the rest is the model's
        // own report, worth having and not independent (design 4.2).
        $unusable = !empty($seen['badimage'])
            || ($seen['dark'] !== null && (int) $seen['dark'] > luminance::MAX_DARK_CELLS)
            || trim((string) $seen['note']) === ''
            || (string) $seen['confidence'] === 'low'
            || (int) $seen['unusable_frames'] > self::MAX_UNUSABLE_FRAMES;
        if ($unusable) {
            return self::state(self::EVIDENCE_UNUSABLE);
        }

        $evidence = [
            'state' => self::EVIDENCE_OK,
            'note' => (string) $seen['note'],
            'confidence' => (string) $seen['confidence'],
            'unusable_frames' => (int) $seen['unusable_frames'],
        ];
        if (self::store_evidence_enabled()) {
            self::store_evidence($rec, $evidence);
        }

        return $evidence;
    }

    /**
     * Write the raw note for staff, starting its visualdatadays clock.
     *
     * @param \stdClass $rec The recording row, updated in place.
     * @param array $evidence An ok evidence() result.
     * @return void
     */
    private static function store_evidence(\stdClass $rec, array $evidence): void {
        global $DB;

        $json = json_encode([
            'note' => $evidence['note'],
            'confidence' => $evidence['confidence'],
            'unusable_frames' => $evidence['unusable_frames'],
        ], JSON_UNESCAPED_UNICODE);
        $now = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => (int) $rec->id,
            'visualevidence' => $json,
            'visualevidenceat' => $now,
        ]);
        $rec->visualevidence = $json;
        $rec->visualevidenceat = $now;
    }

    /**
     * The block the scoring prompt carries after the transcript, or '' when there is no usable evidence.
     *
     * @param array $evidence An evidence() result.
     * @param bool $visualscored Whether the activity counts the visual criteria (D23).
     * @param string $languagename The English name of the learner's language, language_name().
     * @param string[] $visualnames The names of the visual criteria written into the prompt.
     * @return string
     */
    public static function prompt_block(
        array $evidence,
        bool $visualscored,
        string $languagename,
        array $visualnames = []
    ): string {
        if (($evidence['state'] ?? '') !== self::EVIDENCE_OK || trim((string) ($evidence['note'] ?? '')) === '') {
            return '';
        }

        return visual_prompts::scoring_block((string) $evidence['note'], $visualscored, $languagename, $visualnames);
    }

    /**
     * The visual section a learner sees for an evidence state that produced no evidence.
     *
     * For EVIDENCE_OK the answer depends on the gate and comes from finalise().
     *
     * @param array $evidence An evidence() result.
     * @return string One of the STATUS_* constants.
     */
    public static function status_for(array $evidence): string {
        switch ((string) ($evidence['state'] ?? '')) {
            case self::EVIDENCE_OPTEDOUT:
                return self::STATUS_OPTEDOUT;
            case self::EVIDENCE_UNUSABLE:
                return self::STATUS_NOTASSESSED;
            case self::EVIDENCE_UNAVAILABLE:
                return self::STATUS_NOTANALYSED;
            case self::EVIDENCE_OK:
                return self::STATUS_FALLBACK;
            default:
                return self::STATUS_NONE;
        }
    }

    /**
     * The learner's Moodle language code, from the user record and never the session.
     *
     * Not current_language(): in a cron run scoring a queue it can carry the
     * previous learner's session language, and learner B would get feedback in
     * learner A's language (design 3.4, F13).
     *
     * @param int $userid The learner.
     * @return string A Moodle language code such as en or pt_br.
     */
    public static function language_code(int $userid): string {
        global $CFG, $DB;

        $lang = $userid > 0 ? (string) $DB->get_field('user', 'lang', ['id' => $userid]) : '';
        if ($lang === '') {
            $lang = (string) ($CFG->lang ?? '');
        }

        return $lang !== '' ? $lang : 'en';
    }

    /**
     * The English name of the learner's language, for {LANGUAGE} in prompt 2.
     *
     * @param int $userid The learner.
     * @return string For example "Spanish". The code itself when no name is known.
     */
    public static function language_name(int $userid): string {
        $code = self::language_code($userid);
        $base = strtolower((string) preg_replace('/[_-].*$/', '', $code));
        $names = get_string_manager()->get_list_of_languages('en');
        $name = (string) ($names[$base] ?? $names[$code] ?? $code);

        // ISO lists give "Spanish; Castilian"; the first name is the one a model expects.
        return trim(explode(';', $name)[0]);
    }

    /**
     * Gate the model's visual feedback and decide what the learner sees.
     *
     * A criterion whose feedback is rejected gets visual_criterion_withheld and
     * assessed false, so it leaves both sums: the learner is not marked on
     * words nobody was willing to show them (design 4.7). A rejected or empty
     * summary is replaced by the fallback template. Every rejection fires
     * visual_summary_rejected and is written, with its text, to the staff only
     * gatelog. Rejections are never retried.
     *
     * The scoring prompt carried the raw note, so the rest of the reply is
     * gated too, in the gate's spoken mode: the overall comment, each tip and
     * every other criterion's feedback (D21). A rejected tip is dropped; a
     * rejected overall comment or spoken criterion comment is replaced by
     * feedback_withheld, and the spoken criterion keeps its score, because
     * the score is about what was said.
     *
     * A judge outage is not a verdict. It fires visual_summary_judge_unavailable
     * and, while the task may still retry and the outage is transient, throws
     * judge_unavailable_exception so it does. Out of retries, or for an outage
     * a retry can't fix, it fails closed: the template, every visual
     * criterion withheld and unassessed, every spoken string that would have
     * gone to the judge withheld, and a gatelog row for each.
     *
     * @param array $criteria The scored criteria in the B2 shape.
     * @param string $summary The model's visual_summary, possibly empty.
     * @param array $evidence The evidence() result the scoring call used.
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The module context.
     * @param bool $mayretry Whether the scoring task will retry a transient failure.
     * @param string $overall The model's overall comment.
     * @param string[] $tips The model's tips.
     * @return array ['criteria' => array, 'visualsummary' => string|null, 'visualstatus' => string,
     *     'overall' => string, 'tips' => string[]]
     * @throws judge_unavailable_exception On a transient judge outage while $mayretry.
     */
    public static function finalise(
        array $criteria,
        string $summary,
        array $evidence,
        \stdClass $rec,
        \stdClass $instance,
        \context_module $ctx,
        bool $mayretry,
        string $overall = '',
        array $tips = []
    ): array {
        $criteria = array_values($criteria);
        $overall = trim($overall);
        $cleantips = [];
        foreach ($tips as $tip) {
            if (is_scalar($tip) && trim((string) $tip) !== '') {
                $cleantips[] = trim((string) $tip);
            }
        }
        $tips = $cleantips;
        if (($evidence['state'] ?? '') !== self::EVIDENCE_OK) {
            return [
                'criteria' => $criteria,
                'visualsummary' => null,
                'visualstatus' => self::status_for($evidence),
                'overall' => $overall,
                'tips' => $tips,
            ];
        }

        $summary = trim($summary);
        // Each item: the gate's target, text and mode, and what it is in this reply.
        $texts = [['target' => summary_gate::TARGET_SUMMARY, 'text' => $summary, 'kind' => 'summary', 'index' => 0]];
        $visualidx = [];
        foreach ($criteria as $i => $criterion) {
            if (self::is_visual_criterion($criterion)) {
                $criteria[$i]['visual'] = true;
                $visualidx[] = $i;
                $texts[] = [
                    'target' => (string) $criterion['name'],
                    'text' => trim((string) ($criterion['feedback'] ?? '')),
                    'kind' => 'visual',
                    'index' => $i,
                ];
            }
        }
        foreach ($criteria as $i => $criterion) {
            $feedback = trim((string) ($criterion['feedback'] ?? ''));
            if (!in_array($i, $visualidx, true) && $feedback !== '') {
                $texts[] = [
                    'target' => (string) $criterion['name'],
                    'text' => $feedback,
                    'mode' => summary_gate::MODE_SPOKEN,
                    'kind' => 'criterion',
                    'index' => $i,
                ];
            }
        }
        if ($overall !== '') {
            $texts[] = [
                'target' => summary_gate::TARGET_OVERALL,
                'text' => $overall,
                'mode' => summary_gate::MODE_SPOKEN,
                'kind' => 'overall',
                'index' => 0,
            ];
        }
        foreach ($tips as $j => $tip) {
            $texts[] = [
                'target' => summary_gate::TARGET_TIP,
                'text' => $tip,
                'mode' => summary_gate::MODE_SPOKEN,
                'kind' => 'tip',
                'index' => $j,
            ];
        }

        $names = [];
        foreach ($criteria as $criterion) {
            $names[] = (string) ($criterion['name'] ?? '');
        }
        $context = [
            'note' => (string) ($evidence['note'] ?? ''),
            'lang' => self::language_code((int) $rec->userid),
            'criterionnames' => $names,
            'judge' => self::judge_enabled(),
            'presenteraiid' => (int) $instance->id,
            'recordingid' => (int) $rec->id,
            'userid' => (int) $rec->userid,
        ];

        $gateitems = array_map(fn($item) => array_intersect_key($item, array_flip(['target', 'text', 'mode'])), $texts);
        try {
            $results = summary_gate::check_batch($gateitems, $context);
        } catch (judge_unavailable_exception $e) {
            visual_summary_judge_unavailable::create_from_recording($rec, $ctx, ['reason' => $e->reason])->trigger();
            if ($mayretry && $e->transient) {
                throw $e;
            }
            return self::fail_closed($criteria, $texts, $gateitems, $context, $rec, $summary, $overall, $tips);
        }

        $rejected = [];
        foreach ($results as $pos => $result) {
            if ($result['pass']) {
                continue;
            }
            $item = $texts[$pos];
            self::log_rejection(
                $rec,
                $ctx,
                (string) $item['target'],
                (int) $result['layer'],
                (string) $result['rule'],
                (string) $item['text']
            );
            $rejected[] = $pos;
        }
        $summarypassed = $summary !== '' && !in_array(0, $rejected, true);

        return self::apply_rejections($criteria, $texts, $rejected, $summarypassed ? $summary : null, $overall, $tips);
    }

    /**
     * The fail closed result after a judge outage that won't be retried.
     *
     * Every visual string is withheld, as is every spoken string the
     * deterministic layers reject or that would have gone to the judge. Each
     * gets a layer 0 gatelog row; no rejection events fire, because nothing
     * was judged.
     *
     * @param array $criteria The criteria.
     * @param array $texts The batch with its kinds, as finalise() built it.
     * @param array $gateitems The same batch as the gate takes it.
     * @param array $context The gate context.
     * @param \stdClass $rec The recording row.
     * @param string $summary The summary.
     * @param string $overall The overall comment.
     * @param string[] $tips The tips.
     * @return array As finalise().
     */
    private static function fail_closed(
        array $criteria,
        array $texts,
        array $gateitems,
        array $context,
        \stdClass $rec,
        string $summary,
        string $overall,
        array $tips
    ): array {
        $deterministic = summary_gate::check_batch($gateitems, ['judge' => false] + $context);
        $list = summary_gate::denylist_class((string) $context['lang']);
        $withheld = [];
        foreach ($texts as $pos => $item) {
            $spoken = ($item['mode'] ?? '') === summary_gate::MODE_SPOKEN;
            $harmless = $deterministic[$pos]['pass'] && !summary_gate::mentions_body_language((string) $item['text'], $list);
            if ($spoken && $harmless) {
                continue;
            }
            $withheld[] = $pos;
            if (trim((string) $item['text']) !== '') {
                self::gatelog(
                    $rec,
                    (string) $item['target'],
                    summary_gate::LAYER_UNAVAILABLE,
                    'judge_unavailable',
                    (string) $item['text']
                );
            }
        }

        return self::apply_rejections($criteria, $texts, $withheld, null, $overall, $tips);
    }

    /**
     * Apply the gate's rejections to the reply and say what the learner sees.
     *
     * @param array $criteria The criteria, with visual flags set.
     * @param array $texts The batch with its kinds.
     * @param int[] $rejected Positions in $texts that were rejected or withheld.
     * @param string|null $summary The summary when it passed, or null for the template.
     * @param string $overall The overall comment.
     * @param string[] $tips The tips.
     * @return array As finalise().
     */
    private static function apply_rejections(
        array $criteria,
        array $texts,
        array $rejected,
        ?string $summary,
        string $overall,
        array $tips
    ): array {
        $droptips = [];
        foreach ($rejected as $pos) {
            $item = $texts[$pos];
            switch ($item['kind']) {
                case 'visual':
                    self::withhold($criteria[$item['index']]);
                    break;
                case 'criterion':
                    $criteria[$item['index']]['feedback'] = get_string('feedback_withheld', 'mod_presenterai');
                    break;
                case 'overall':
                    $overall = get_string('feedback_withheld', 'mod_presenterai');
                    break;
                case 'tip':
                    $droptips[(int) $item['index']] = true;
                    break;
            }
        }
        $keptips = [];
        foreach ($tips as $j => $tip) {
            if (!isset($droptips[$j])) {
                $keptips[] = $tip;
            }
        }

        if ($summary !== null) {
            return [
                'criteria' => $criteria,
                'visualsummary' => $summary,
                'visualstatus' => self::STATUS_SUMMARY,
                'overall' => $overall,
                'tips' => $keptips,
            ];
        }

        return [
            'criteria' => $criteria,
            'visualsummary' => fallback_template::build($criteria),
            'visualstatus' => self::STATUS_FALLBACK,
            'overall' => $overall,
            'tips' => $keptips,
        ];
    }

    /**
     * Replace a criterion's feedback with the withheld sentence and take it out of the sums.
     *
     * @param array $criterion The criterion, changed in place.
     * @return void
     */
    private static function withhold(array &$criterion): void {
        $criterion['feedback'] = get_string('visual_criterion_withheld', 'mod_presenterai');
        $criterion['assessed'] = false;
    }

    /**
     * Fire the rejection event, which never carries the text, and log the text for staff.
     *
     * @param \stdClass $rec The recording row.
     * @param \context_module $ctx The module context.
     * @param string $target 'summary' or the criterion name.
     * @param int $layer The layer that rejected it.
     * @param string $rule The rule that fired.
     * @param string $text The rejected text.
     * @return void
     */
    private static function log_rejection(
        \stdClass $rec,
        \context_module $ctx,
        string $target,
        int $layer,
        string $rule,
        string $text
    ): void {
        visual_summary_rejected::create_from_recording($rec, $ctx, [
            'target' => $target,
            'layer' => $layer,
            'rule' => $rule,
        ])->trigger();
        self::gatelog($rec, $target, $layer, $rule, $text);
    }

    /**
     * One row in the staff only gatelog (design 5.3), kept seven days.
     *
     * @param \stdClass $rec The recording row.
     * @param string $target 'summary' or the criterion name.
     * @param int $layer The layer.
     * @param string $rule The rule.
     * @param string $text The rejected text.
     * @return void
     */
    private static function gatelog(\stdClass $rec, string $target, int $layer, string $rule, string $text): void {
        global $DB;

        $DB->insert_record('presenterai_gatelog', (object) [
            'recordingid' => (int) $rec->id,
            'target' => \core_text::substr($target, 0, 255),
            'layer' => $layer,
            'gaterule' => \core_text::substr($rule, 0, 64),
            'rejectedtext' => $text,
            'timecreated' => time(),
        ]);
    }

    /**
     * Whether a criterion is one of the visual ones.
     *
     * The visual flag decides when it is present (B2). A criterion without the
     * flag is recognised by the seed names, so rows written before the flag
     * existed still have their body language feedback gated.
     *
     * @param mixed $criterion A criterion.
     * @return bool
     */
    public static function is_visual_criterion($criterion): bool {
        if (!is_array($criterion)) {
            return false;
        }
        if (array_key_exists('visual', $criterion)) {
            return !empty($criterion['visual']);
        }
        $name = summary_gate::normalise_name((string) ($criterion['name'] ?? ''));
        foreach (self::VISUAL_CRITERION_NAMES as $visualname) {
            if ($name !== '' && $name === summary_gate::normalise_name($visualname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this activity takes still frames at all: video vision on, a camera recording, and a site that analyzes them.
     *
     * On a site whose scoring resolves to Moodle core AI no frames are
     * analyzed (D26), so none are sampled or uploaded either, and the learner
     * is told nothing about them: the recorder, the policy callout, the
     * privacy paragraph and the upload all ask this one question.
     *
     * @param \stdClass $instance The presenterai row.
     * @return bool
     */
    public static function takes_frames(\stdClass $instance): bool {
        return !empty($instance->videovision)
            && (string) ($instance->mode ?? 'video') === 'video'
            && !route_resolver::scoring_on_core();
    }

    /**
     * Whether the raw note is written for staff (storevisualevidence, on when unset).
     *
     * @return bool
     */
    public static function store_evidence_enabled(): bool {
        $value = get_config('mod_presenterai', 'storevisualevidence');

        return $value === false || $value === null || (int) $value === 1;
    }

    /**
     * Whether layer 4 runs (visualsummaryjudge, on when unset).
     *
     * @return bool
     */
    public static function judge_enabled(): bool {
        $value = get_config('mod_presenterai', 'visualsummaryjudge');

        return $value === false || $value === null || (int) $value === 1;
    }

    /**
     * How many days the raw note is kept: visualdatadays, 30 when unset, never less than 1.
     *
     * There is no forever for this clock (design 7.4). Zero or a negative
     * number reads as the minimum, not as "keep".
     *
     * @return int
     */
    public static function visual_data_days(): int {
        $value = get_config('mod_presenterai', 'visualdatadays');
        if ($value === false || $value === null || trim((string) $value) === '') {
            return self::DEFAULT_VISUAL_DATA_DAYS;
        }

        return max(1, (int) $value);
    }

    /**
     * An evidence() result carrying only a state.
     *
     * @param string $state One of the EVIDENCE_* constants.
     * @return array
     */
    private static function state(string $state): array {
        return ['state' => $state, 'note' => '', 'confidence' => '', 'unusable_frames' => 0];
    }
}
