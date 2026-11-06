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
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\config;
use mod_presenterai\local\gradebook;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\retention;
use mod_presenterai\local\storage\store_factory;
use mod_presenterai\local\topic_manager;
use mod_presenterai\local\vision\visual_pipeline;

/**
 * The learner's page for one activity: three steps, then every attempt.
 *
 * Since 0.6.0 the page is a numbered panel: 1 choose a topic (only when the
 * activity has topics), 2 record, 3 read your feedback, with the latest
 * attempt's feedback in step 3 and the full attempt table below, collapsed.
 *
 * The order is reading order and it is deliberate. The retention line comes
 * before the record button because a learner who learns their recording will
 * be deleted, or cannot be downloaded, after speaking for seven minutes has
 * been misled by omission (design 9.3). It is a sibling of the recorder and
 * never a child of it: SOLA's old speech tool nested its retention statement
 * inside the storage-ready branch, so on a site with storage unconfigured the
 * table showed and the policy did not. One line replaces the callout and the
 * privacy paragraph (policy::retention_line()).
 *
 * While the latest attempt is being scored, mod_presenterai/view refreshes
 * its card every 20 seconds through core's fragment API
 * (mod_presenterai_output_fragment_latest() in lib.php, which renders
 * latest_card()), so no new web service is needed.
 *
 * The recorder's configuration travels as data attributes on the root element
 * rather than as js_call_amd() arguments, which Moodle warns about above 1024
 * characters and which would put the same values in two places.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class view_page implements \renderable, \templatable {
    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \stdClass The course row. */
    private \stdClass $course;

    /** @var \context_module The activity's context. */
    private \context_module $context;

    /** @var int The user the page is for. */
    private int $userid;

    /** @var int The time attempt states are judged against. */
    private int $now;

    /**
     * Build the page for one user.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $context The activity's context.
     * @param int $userid The user the page is for.
     * @param int|null $now The time to judge deletion dates against; null for now.
     */
    public function __construct(\stdClass $instance, \stdClass $course, \context_module $context, int $userid, ?int $now = null) {
        $this->instance = $instance;
        $this->course = $course;
        $this->context = $context;
        $this->userid = $userid;
        $this->now = $now ?? time();
    }

    /**
     * Context for templates/view.mustache.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $candownload = access::may_download_own_prospectively($this->context, $this->userid);
        $attempts = $this->attempts();

        $hasmedia = false;
        foreach ($attempts as $attempt) {
            $hasmedia = $hasmedia || $attempt['mediaavailable'];
        }

        $data = [
            'cmid' => (int) $this->context->instanceid,
            'contextid' => (int) $this->context->id,
            'topics' => $this->topics(),
            'retentionline' => policy::retention_line($this->instance, $this->context, $this->userid),
            'prunewarning' => policy::prune_warning($this->instance, $this->userid),
            'latest' => $this->card(self::latest_row($attempts)),
            'canrecord' => has_capability('mod/presenterai:submit', $this->context, $this->userid),
            // Staff reach the submissions report from here as well as from the
            // activity's settings menu.
            'showsubmissions' => has_capability('mod/presenterai:viewallattempts', $this->context, $this->userid),
            'submissionsurl' => (new \moodle_url('/mod/presenterai/report.php', [
                'id' => (int) $this->context->instanceid,
            ]))->out(false),
            'blocked' => '',
            'recorder' => null,
            'config' => null,
            'attempts' => [
                'hasattempts' => !empty($attempts),
                'count' => count($attempts),
                'rows' => $attempts,
                // Once, below the table, and only when there is something a
                // learner might look for a Download link against (design 9.5).
                'showdownloadoffnote' => !$candownload && $hasmedia,
            ],
        ];

        if ($data['canrecord']) {
            $blocked = $this->blocked_reason();
            if ($blocked !== null) {
                $data['blocked'] = $blocked;
            } else {
                $data['recorder'] = $this->recorder();
                $data['config'] = $this->recorder_config();
            }
        }

        return $data;
    }

    /**
     * The latest attempt's feedback card, as the fragment refreshes it.
     *
     * @return array Context for templates/latest.mustache.
     */
    public function latest_card(): array {
        return $this->card(self::latest_row($this->attempts()));
    }

    /** @var int How long an uploaded attempt may wait for the scorer before the card stops promising a score soon. */
    public const SCORING_PROMISE_SECONDS = 900;

    /**
     * The attempt the feedback card is about: the newest one that isn't abandoned.
     *
     * An abandoned row never reached the scorer, so it would only hide the
     * feedback of the attempt before it. A failed one is still shown, because
     * the learner needs to know it failed.
     *
     * @param array[] $attempts Exported rows, newest first, each with 'statuscode'.
     * @return array|null
     */
    public static function latest_row(array $attempts): ?array {
        foreach ($attempts as $row) {
            if (($row['statuscode'] ?? '') !== 'abandoned') {
                return $row;
            }
        }
        return null;
    }

    /**
     * What the card says about an attempt that has no score yet.
     *
     * 'scoring' (the page polls, and the card promises a score in about two
     * minutes) only while the scorer has it, or while it's freshly uploaded
     * on a site that can transcribe and score. An uploaded row can stay
     * uploaded for good: a site with AI off, where scoring settles back to
     * uploaded, or an attempt imported unscored from SOLA's old speech tool.
     * Those read 'waiting', which promises nothing and isn't polled.
     *
     * @param string $status The recording's status.
     * @param int $since When the row last changed.
     * @param int $now The time to judge against.
     * @param bool $aiready Whether the site can transcribe and score (route_resolver::ai_ready()).
     * @return string 'scoring', 'waiting' or '' for any other status.
     */
    public static function pending_state(string $status, int $since, int $now, bool $aiready): string {
        if ($status === 'scoring') {
            return 'scoring';
        }
        if ($status !== 'uploaded') {
            return '';
        }
        return $aiready && $now - $since < self::SCORING_PROMISE_SECONDS ? 'scoring' : 'waiting';
    }

    /**
     * Context for templates/latest.mustache, from one exported attempt row.
     *
     * The status reads "Scoring, usually about 2 minutes" while
     * pending_state() says so (which is what the page polls on), "Waiting to
     * be scored" when it says waiting, "Ready" once a released score is
     * there, the review sentence while a teacher holds it (D28), and the
     * row's own status otherwise. Everything else,
     * including whether the gradebook hides the score, comes from the row as
     * attempt_row::export() built it, so the card can't say more than the
     * table below it.
     *
     * @param array|null $row An attempt_row::export() result, or null when there's no attempt yet.
     * @return array
     */
    private function card(?array $row): array {
        if ($row === null) {
            return ['hasattempt' => false, 'scoring' => false];
        }
        $statuscode = (string) ($row['statuscode'] ?? '');
        $pending = $row['inreview'] ? '' : self::pending_state(
            $statuscode,
            (int) ($row['changedat'] ?? 0),
            $this->now,
            in_array($statuscode, ['uploaded', 'scoring'], true) && route_resolver::ai_ready()
        );
        $scoring = $pending === 'scoring';
        $ready = $statuscode === 'scored' && !$row['inreview'] && $row['hasfeedback'];
        if ($scoring) {
            $status = get_string('card_status_scoring', 'mod_presenterai');
        } else if ($pending === 'waiting') {
            $status = get_string('card_status_waiting', 'mod_presenterai');
        } else if ($ready) {
            $status = get_string('card_status_ready', 'mod_presenterai');
        } else {
            $status = $row['status'];
        }

        return [
            'hasattempt' => true,
            'recid' => $row['recid'],
            'heading' => get_string('card_heading', 'mod_presenterai', $row['recorded']),
            'status' => $status,
            'scoring' => $scoring,
            'failed' => $statuscode === 'failed',
            'inreview' => $row['inreview'],
            'reviewnote' => $row['reviewnote'],
            'hasscore' => $row['hasscore'],
            'score' => $row['score'],
            'hasfeedback' => $row['hasfeedback'],
            'feedback' => $row['feedback'],
            'canwatch' => $row['canwatch'],
            'watcharia' => $row['watcharia'],
        ];
    }

    /**
     * Why this learner cannot start a recording right now, or null when they can.
     *
     * In order of what an admin or learner can do about it. Storage first,
     * because nothing else matters if the bytes have nowhere to go. The size
     * check is the published ceiling of plan section 4.4: a recording that
     * could exceed it at this activity's length is refused before the learner
     * starts, never after they have finished speaking.
     *
     * @return string|null The message to show in place of the recorder.
     */
    public function blocked_reason(): ?string {
        try {
            store_factory::default_store();
        } catch (\moodle_exception $e) {
            return get_string('nostorage', 'mod_presenterai');
        }

        if (recording_manager::cap_reached($this->instance, $this->userid)) {
            return get_string('recording_blocked_cap', 'mod_presenterai', (int) $this->instance->maxattempts);
        }

        $limit = recording_manager::max_media_bytes($this->course);
        if ($limit > 0 && config::estimated_bytes((string) $this->instance->mode, $this->max_seconds()) > $limit) {
            return get_string('recording_blocked_size', 'mod_presenterai', (object) ['limit' => display_size($limit)]);
        }

        return null;
    }

    /**
     * The longest recording this activity allows, after the site ceiling.
     *
     * @return int Seconds.
     */
    private function max_seconds(): int {
        $instancemax = (int) $this->instance->maxseconds;
        $sitemax = config::max_recording_seconds();

        return $instancemax > 0 ? min($instancemax, $sitemax) : $sitemax;
    }

    /**
     * Context for templates/recorder.mustache.
     *
     * @return array
     */
    private function recorder(): array {
        $isaudio = (string) $this->instance->mode === 'audio';
        $min = max(0, (int) $this->instance->minseconds);
        $frames = visual_pipeline::takes_frames($this->instance);

        return [
            'isaudio' => $isaudio,
            'isvideo' => !$isaudio,
            'slides' => !empty($this->instance->slidesenabled),
            // DECISIONS.md Part 1: disclosure is not consent, and it is cheap.
            // One sentence of static text wherever frames are taken.
            'visualdisclosure' => $frames,
            // D24: the opt out and the sentence saying it costs no marks, only
            // where the activity offers it and frames would be taken.
            'visualoptout' => $frames && !empty($this->instance->allowvisualoptout),
            // Beside the timer: "minimum 0:20, maximum 1:30" (0.6.0).
            'timerlimits' => get_string('rec_timer_limits', 'mod_presenterai', (object) [
                'min' => attempt_row::duration($min),
                'max' => attempt_row::duration($this->max_seconds()),
            ]),
            // How long the AI's note about the frames is kept, which the
            // privacy paragraph said before 0.6.0, now beside the disclosure.
            'visualnote' => $frames
                ? get_string('privacy_visualnote', 'mod_presenterai', visual_pipeline::visual_data_days())
                : '',
        ];
    }

    /**
     * The recorder settings, rendered as data attributes on the root element.
     *
     * @return array
     */
    private function recorder_config(): array {
        $quality = config::quality();

        return [
            'mode' => (string) $this->instance->mode === 'audio' ? 'audio' : 'video',
            'minseconds' => max(0, (int) $this->instance->minseconds),
            'maxseconds' => $this->max_seconds(),
            'width' => (int) $quality['width'],
            'height' => (int) $quality['height'],
            'videokbps' => (int) $quality['videokbps'],
            'audiokbps' => (int) $quality['audiokbps'],
            'slides' => !empty($this->instance->slidesenabled) ? 1 : 0,
            'maxbytes' => recording_manager::max_media_bytes($this->course),
            // Whether the recorder samples frames after Stop, and whether it
            // reads the opt out box first. The server checks both again.
            'videovision' => visual_pipeline::takes_frames($this->instance) ? 1 : 0,
            'allowvisualoptout' => visual_pipeline::takes_frames($this->instance)
                && !empty($this->instance->allowvisualoptout) ? 1 : 0,
            // B7: warm the speech to text endpoint when recording starts.
            'warmstt' => (int) get_config('mod_presenterai', 'sttwarm'),
            // A second, audio only recorder beside a camera recording, so a
            // long video still transcribes under a 25 MB limit. An audio only
            // activity's recording is small already and isn't recorded twice.
            'audiotrack' => (string) $this->instance->mode === 'audio' ? 0 : 1,
            'audiotrackkbps' => \mod_presenterai\local\transcription_source::AUDIO_TRACK_KBPS,
        ];
    }

    /**
     * Context for templates/topics.mustache.
     *
     * Two or more topics get a picker whose first option asks the learner to
     * choose; a single topic is shown but there is nothing to choose between,
     * so its id travels on the root element instead.
     *
     * @return array
     */
    private function topics(): array {
        $topics = topic_manager::export_for_view($this->instance, $this->context);

        return [
            'hastopics' => !empty($topics),
            'topicselect' => count($topics) > 1,
            'singletopicid' => count($topics) === 1 ? (int) $topics[0]['id'] : 0,
            'topics' => array_values($topics),
        ];
    }

    /**
     * The user's own attempts, newest first, each ready for the template.
     *
     * Rows still uploading are left out: they are the attempt being made now,
     * or one the learner may resume, not an attempt that exists yet. Rows whose
     * media is gone are kept in, with their date, length and status, because
     * the attempt still counts and its feedback still belongs to the learner
     * (plan section 7.4).
     *
     * @return array
     */
    private function attempts(): array {
        global $DB;

        $rows = $DB->get_records_select(
            'presenterai_recording',
            'presenteraiid = :p AND userid = :u AND status <> :uploading',
            ['p' => $this->instance->id, 'u' => $this->userid, 'uploading' => 'uploading'],
            'timecreated DESC, id DESC'
        );

        $cleanupenabled = retention::cleanup_task_enabled();
        $s3lifecycle = retention::lifecycle_days(store_factory::BACKEND_S3) > 0;
        $scorehidden = !empty($rows) && gradebook::hidden_from($this->instance, $this->userid);
        $out = [];
        foreach ($rows as $row) {
            $out[] = attempt_row::export(
                $row,
                $this->context,
                $this->userid,
                $this->now,
                $cleanupenabled,
                $s3lifecycle,
                $scorehidden
            ) + [
                'statuscode' => (string) $row->status,
                'changedat' => (int) ($row->timemodified ?: $row->timecreated),
            ];
        }

        return $out;
    }
}
