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

namespace mod_presenterai\output;

use mod_presenterai\local\access;
use mod_presenterai\local\score_manager;
use mod_presenterai\local\storage\store_factory;
use mod_presenterai\local\vision\fallback_template;
use mod_presenterai\local\vision\visual_pipeline;

/**
 * One row of a learner's attempt list.
 *
 * Everything this class says about a recording's media comes from the row and
 * nothing else. That is the rule in section 9.1 of
 * docs/DESIGN-visual-feedback-and-retention.md (Group B, retrospective), and it
 * is why there is no get_config() call anywhere in this file:
 * retention_message_source_test scans for one. A deletion date is a promise
 * made to the learner at finalize and frozen on the row (section 8.2), so an
 * admin who turns retention off afterwards does not get to make the page claim
 * the recording is kept while cron goes on deleting it on the promised date.
 *
 * The single input that is not the row, whether the cleanup task is enabled,
 * is passed in rather than looked up. Section 8.7f says a date the site will
 * not honour must never be shown, and taking it as a parameter keeps state()
 * pure, so the table in section 9.5 can be tested without a database.
 *
 * Download, by contrast, is a permission exercised now and is read live
 * through access::may_download(). The asymmetry is deliberate (section 9.1).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_row {
    /** @var string[] Statuses whose row never received its bytes, so it has no media to describe. */
    private const NEVER_UPLOADED = ['uploading', 'abandoned'];

    /** @var string[] Reasons with their own sentence that takes the deletion date. */
    private const GONE_WITH_DATE = ['pruned', 'manual', 'learner'];

    /** @var string[] Reasons with their own sentence and no date to put in it. */
    private const GONE_WITHOUT_DATE = ['missing', 'notbackedup'];

    /** @var string[] Every status this plugin can write, each with a status_* string. */
    private const STATUSES = ['uploading', 'uploaded', 'abandoned', 'scoring', 'scored', 'failed'];

    /**
     * Which recording state sentence a row gets, per the table in design section 9.5.
     *
     * Never returns an empty key. An empty cell is announced as blank, which a
     * screen reader user cannot tell apart from "not applicable" (design 11, rule 6).
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param int $now The time to compare expiresat against.
     * @param bool $cleanupenabled Whether the cleanup task that honours expiresat is enabled.
     * @param bool $s3lifecycle Whether a bucket lifecycle rule is declared for S3, which
     *                          deletes S3 recordings whether or not the task runs.
     * @return array ['key' => lang string key, 'a' => its {$a} or null]
     */
    public static function state(\stdClass $rec, int $now, bool $cleanupenabled, bool $s3lifecycle = false): array {
        $status = (string) ($rec->status ?? '');
        if (in_array($status, self::NEVER_UPLOADED, true)) {
            return ['key' => 'attempt_never_uploaded', 'a' => null];
        }

        $expiresat = (int) ($rec->expiresat ?? 0);
        if (self::has_media($rec)) {
            // A disabled task never arrives at the date, so promising one would
            // be a statement the site is not going to keep (8.7f). A declared
            // bucket lifecycle is the exception: the bucket deletes whether the
            // task runs or not, and the row must never say "kept" then (8.6, 1).
            $bucketdeletes = $s3lifecycle && (string) ($rec->backend ?? '') === store_factory::BACKEND_S3;
            if ($expiresat <= 0 || (!$cleanupenabled && !$bucketdeletes)) {
                return ['key' => 'attempt_kept', 'a' => null];
            }
            if ($expiresat > $now) {
                return ['key' => 'attempt_deletes_on', 'a' => self::date($expiresat)];
            }
            // Cron is late or has not reached this row yet. Showing the date
            // would tell the learner about a deletion "on" a day already gone.
            return ['key' => 'attempt_deletes_due', 'a' => null];
        }

        $deletedat = (int) ($rec->mediadeletedat ?? 0);
        if ($deletedat <= 0) {
            // A row that lost its media before anything recorded when, which is
            // what a Soapbox migration produces (DECISIONS.md D8).
            return ['key' => 'attempt_deleted', 'a' => null];
        }

        $reason = (string) ($rec->mediagonereason ?? '');
        if (in_array($reason, self::GONE_WITH_DATE, true)) {
            return ['key' => 'attempt_gone_' . $reason, 'a' => self::date($deletedat)];
        }
        if (in_array($reason, self::GONE_WITHOUT_DATE, true)) {
            return ['key' => 'attempt_gone_' . $reason, 'a' => null];
        }

        // Retention, and any reason a later version writes that this one does
        // not know, both read as a plain dated deletion rather than as nothing.
        return ['key' => 'attempt_deleted_on', 'a' => self::date($deletedat)];
    }

    /**
     * The sentence for a state() result.
     *
     * @param array $state A state() result.
     * @return string
     */
    public static function text(array $state): string {
        return get_string($state['key'], 'mod_presenterai', $state['a']);
    }

    /**
     * The learner facing name of a row's status.
     *
     * A status this version does not know is shown escaped as it stands rather
     * than hidden, so a row from a newer version still has a status cell.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @return string
     */
    public static function status_label(\stdClass $rec): string {
        $status = (string) ($rec->status ?? '');
        if (in_array($status, self::STATUSES, true)) {
            return get_string('status_' . $status, 'mod_presenterai');
        }

        return s($status);
    }

    /**
     * Whether the row still points at media.
     *
     * An uploading row can carry a storage key before its bytes have arrived
     * (the key is minted by start_upload), so a key alone is not media.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @return bool
     */
    public static function has_media(\stdClass $rec): bool {
        return (string) ($rec->storagekey ?? '') !== ''
            && !in_array((string) ($rec->status ?? ''), self::NEVER_UPLOADED, true);
    }

    /**
     * Everything templates/attempts.mustache needs for one row.
     *
     * @param \stdClass $rec A presenterai_recording row owned by, or visible to, $userid.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The user the page is being built for.
     * @param int $now The time to compare expiresat against.
     * @param bool $cleanupenabled Whether the cleanup task is enabled.
     * @param bool $s3lifecycle Whether a bucket lifecycle rule is declared for S3.
     * @param bool $scorehidden Whether the gradebook hides the grade from the viewer
     *                          (gradebook::hidden_from()), which leaves the score
     *                          and feedback out of the row.
     * @return array
     */
    public static function export(
        \stdClass $rec,
        \context_module $ctx,
        int $userid,
        int $now,
        bool $cleanupenabled,
        bool $s3lifecycle = false,
        bool $scorehidden = false
    ): array {
        $state = self::state($rec, $now, $cleanupenabled, $s3lifecycle);
        $hasmedia = self::has_media($rec);
        $neveruploaded = in_array((string) $rec->status, self::NEVER_UPLOADED, true);

        // The same string as the visible Recorded cell, so the accessible name
        // of each Watch or Download link identifies its row even when two
        // attempts were made on the same day (design 11, rule 3).
        $recorded = userdate((int) $rec->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $canwatch = $hasmedia && access::may_view($rec, $ctx, $userid);
        $candownload = $hasmedia && access::may_download($rec, $ctx, $userid);
        $candelete = $hasmedia && access::may_delete($rec, $ctx, $userid);

        $score = $scorehidden ? null : score_manager::current_score((int) $rec->id);
        $feedback = $score ? self::feedback($score) : null;

        return [
            'recid' => (int) $rec->id,
            // Abandoned rows never consumed an attempt number (it is assigned at
            // finalize), so the default of 1 on the row would be a false claim.
            'attempt' => $neveruploaded ? '-' : (string) (int) $rec->attemptnumber,
            'recorded' => $recorded,
            'length' => self::duration((int) ($rec->durationseconds ?? 0)),
            'status' => self::status_label($rec),
            'statekey' => $state['key'],
            'state' => self::text($state),
            'mediaavailable' => $hasmedia,
            'canwatch' => $canwatch,
            'watcharia' => get_string('watch_aria', 'mod_presenterai', $recorded),
            'candownload' => $candownload,
            'downloadurl' => $candownload
                ? (new \moodle_url('/mod/presenterai/download.php', ['id' => (int) $rec->id]))->out(false)
                : '',
            'downloadaria' => get_string('download_aria', 'mod_presenterai', $recorded),
            'candelete' => $candelete,
            'deletearia' => get_string('delete_aria', 'mod_presenterai', $recorded),
            // The actions cell says in words why there is nothing to press,
            // rather than relying on the row being greyed out (design 11, rule 9).
            // A row that never uploaded has no recording to be "no longer"
            // available, and its state cell already says so.
            'gonenote' => (!$hasmedia && !$neveruploaded) ? get_string('attempt_gone_note', 'mod_presenterai') : '',
            // The current score: the latest teacher row, else the latest AI
            // row (plan section 2.3). A score that assessed nothing has no
            // percentage and shows an empty cell rather than 0%.
            'hasscore' => $score !== null && $score->overallpct !== null,
            'score' => ($score !== null && $score->overallpct !== null) ? format_float((float) $score->overallpct, 2) . '%' : '',
            'hasfeedback' => $feedback !== null,
            'feedback' => $feedback ?? self::empty_feedback(),
            'feedbackaria' => get_string('feedback_toggle_aria', 'mod_presenterai', $recorded),
        ];
    }

    /**
     * The per criterion and overall feedback of a score, ready for the template.
     *
     * Every criterion is listed, including those not assessed, so the learner
     * can see which parts of the rubric their score covers. A criterion that
     * doesn't count toward the score (D23: body language is feedback only by
     * default) says so in words beside its mark.
     *
     * The visual section follows the score row's visualstatus (design 6, 9.6).
     * It shows the gated summary, never the raw note, and is never empty: a
     * status it can't fill shows nothing at all rather than a heading over a
     * hole.
     *
     * @param \stdClass $score A presenterai_score row.
     * @return array ['criteria' => list of ['name', 'scoretext', 'feedback', 'notcounted'], 'overall' => HTML,
     *     'hastips', 'tips', 'hasvisual', 'visualstatus', 'visualheading', 'visualnote', 'visualtext']
     */
    private static function feedback(\stdClass $score): array {
        $criteria = [];
        $flags = self::criterion_flags($score);
        foreach (score_manager::decode_criteria($score) as $i => $criterion) {
            $assessed = !empty($criterion['assessed']) && $criterion['score'] !== null;
            $counts = array_key_exists('counts', $criterion) ? $criterion['counts'] : ($flags[$i]['counts'] ?? true);
            $criteria[] = [
                'name' => format_string((string) $criterion['name']),
                'scoretext' => $assessed
                    ? get_string('feedback_criterion_score', 'mod_presenterai', (object) [
                        'score' => (int) $criterion['score'],
                        'max' => (int) $criterion['max_score'],
                    ])
                    : get_string('feedback_notassessed', 'mod_presenterai'),
                'feedback' => trim((string) ($criterion['feedback'] ?? '')),
                'notcounted' => $counts === false,
            ];
        }

        $overall = trim((string) ($score->feedback ?? ''));
        $tips = self::tips($score);

        return [
            'criteria' => $criteria,
            'overall' => $overall !== '' ? nl2br(s($overall)) : '',
            'hastips' => !empty($tips),
            'tips' => $tips,
        ] + self::visual_section($score);
    }

    /**
     * The feedback context for a row with no score, so the template always sees the same keys.
     *
     * @return array
     */
    private static function empty_feedback(): array {
        return [
            'criteria' => [],
            'overall' => '',
            'hastips' => false,
            'tips' => [],
            'hasvisual' => false,
            'visualstatus' => '',
            'visualheading' => '',
            'visualnote' => '',
            'visualtext' => '',
        ];
    }

    /**
     * The visual and counts flags of each criterion, straight from the stored JSON.
     *
     * Aligned with score_manager::decode_criteria(), which skips the same
     * malformed entries, so a renderer sees the D23 flag even where the decoder
     * does not carry it.
     *
     * @param \stdClass $score A presenterai_score row.
     * @return array List of ['visual' => bool, 'counts' => bool].
     */
    private static function criterion_flags(\stdClass $score): array {
        $raw = json_decode((string) ($score->scores ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = [
                'visual' => !empty($entry['visual']),
                'counts' => !array_key_exists('counts', $entry) || $entry['counts'] !== false,
            ];
        }

        return $out;
    }

    /**
     * The tips for next time, as plain strings (B2: a JSON list, null on teacher rows).
     *
     * @param \stdClass $score A presenterai_score row.
     * @return string[]
     */
    private static function tips(\stdClass $score): array {
        $decoded = json_decode((string) ($score->tips ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $tip) {
            if (is_scalar($tip) && trim((string) $tip) !== '') {
                $out[] = trim((string) $tip);
            }
        }

        return $out;
    }

    /**
     * The body language section of the feedback panel, by the score's visualstatus.
     *
     * summary: the gated summary, with a note saying where it came from.
     * fallback: the deterministic template (design 6 b). notassessed: the
     * learner's setup is why (6 a1). notanalysed: nothing the learner did
     * caused it, and no advice (6 a2). optedout: the learner's choice (D24).
     * Empty: no section. The raw visualevidence note is never read here.
     *
     * @param \stdClass $score A presenterai_score row.
     * @return array ['hasvisual', 'visualstatus', 'visualheading', 'visualnote', 'visualtext']
     */
    private static function visual_section(\stdClass $score): array {
        $status = (string) ($score->visualstatus ?? '');
        $summary = trim((string) ($score->visualsummary ?? ''));
        $note = '';
        switch ($status) {
            case visual_pipeline::STATUS_SUMMARY:
            case visual_pipeline::STATUS_FALLBACK:
                if ($status === visual_pipeline::STATUS_SUMMARY && $summary !== '') {
                    $note = get_string('visual_summary_note', 'mod_presenterai');
                }
                if ($summary === '') {
                    // A status that promised words and has none still gets the
                    // template, never an empty section.
                    $criteria = [];
                    $flags = self::criterion_flags($score);
                    foreach (score_manager::decode_criteria($score) as $i => $criterion) {
                        $criterion['visual'] = $criterion['visual'] ?? ($flags[$i]['visual'] ?? false);
                        $criteria[] = $criterion;
                    }
                    $summary = fallback_template::build($criteria);
                }
                $text = $summary;
                break;
            case visual_pipeline::STATUS_NOTASSESSED:
                $text = get_string('visual_not_assessed', 'mod_presenterai');
                break;
            case visual_pipeline::STATUS_NOTANALYSED:
                $text = get_string('visual_not_analysed', 'mod_presenterai');
                break;
            case visual_pipeline::STATUS_OPTEDOUT:
                $text = get_string('visual_optedout', 'mod_presenterai');
                break;
            default:
                $text = '';
        }

        return [
            'hasvisual' => $text !== '',
            'visualstatus' => $text !== '' ? $status : '',
            'visualheading' => $text !== '' ? get_string('visual_summary_heading', 'mod_presenterai') : '',
            'visualnote' => $note,
            'visualtext' => $text,
        ];
    }

    /**
     * A length as minutes and seconds, which is how a learner thinks about a talk.
     *
     * @param int $seconds Length in seconds.
     * @return string For example 7:05.
     */
    public static function duration(int $seconds): string {
        $seconds = max(0, $seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * A date as the attempt list shows it.
     *
     * @param int $timestamp Unix time.
     * @return string
     */
    private static function date(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'));
    }
}
