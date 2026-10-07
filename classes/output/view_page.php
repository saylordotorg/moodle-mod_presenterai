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
use mod_presenterai\local\config;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\retention;
use mod_presenterai\local\storage\store_factory;
use mod_presenterai\local\topic_manager;

/**
 * The learner's page for one activity: topics, policy, recorder and attempts.
 *
 * The order is reading order and it is deliberate. The policy callout comes
 * before the record button because a learner who learns their recording will
 * be deleted, or cannot be downloaded, after speaking for seven minutes has
 * been misled by omission (design 9.3). The callout is a sibling of the attempt
 * table and never a child of the recorder: Soapbox nested its retention
 * statement inside the storage-ready branch, so on a site with storage
 * unconfigured the table showed and the policy did not.
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
            'topics' => $this->topics(),
            'callout' => policy::callout($this->instance, $this->context, $this->userid),
            'privacy' => policy::privacy_paragraph($this->instance, $candownload),
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

        return [
            'isaudio' => $isaudio,
            'isvideo' => !$isaudio,
            'slides' => !empty($this->instance->slidesenabled),
            'limits' => get_string('rec_limits', 'mod_presenterai', (object) [
                'min' => attempt_row::duration($min),
                'max' => attempt_row::duration($this->max_seconds()),
                'size' => display_size(recording_manager::max_media_bytes($this->course)),
            ]),
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
        $out = [];
        foreach ($rows as $row) {
            $out[] = attempt_row::export($row, $this->context, $this->userid, $this->now, $cleanupenabled, $s3lifecycle);
        }

        return $out;
    }
}
