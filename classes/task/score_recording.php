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

namespace mod_presenterai\task;

/**
 * Transcribe and score one recording, off the web request.
 *
 * Queued when an attempt is finalized and when a grader asks for a rescore.
 * Scoring never runs in a web request (D6): core AI sets no timeout on its
 * request path, and a model call can take a minute.
 *
 * Custom data: {recordingid: int, rescore: bool}, plus deferrals: int on a
 * task the scorer queued again because PresenterAI's own rate limiter was
 * still refusing it when the backoff ran out.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_recording extends \core\task\adhoc_task {
    /** @var int The fail delay from which a transient failure fails the row instead of retrying. */
    public const RETRY_UNTIL_DELAY = 960;

    /**
     * Queue scoring for one recording.
     *
     * An identical task already queued isn't queued again, so a double click
     * on Rescore, or a finalize retried by the browser, scores once.
     *
     * @param int $recordingid The recording id.
     * @param bool $rescore Whether this is a rescore from the grading screen.
     * @param int $runat When to run, a timestamp; 0 for as soon as possible.
     * @param int $deferrals How many times a rate limit has already deferred this attempt.
     * @return void
     */
    public static function queue(int $recordingid, bool $rescore = false, int $runat = 0, int $deferrals = 0): void {
        $task = new self();
        $task->set_component('mod_presenterai');
        $data = ['recordingid' => $recordingid, 'rescore' => $rescore];
        if ($deferrals > 0) {
            $data['deferrals'] = $deferrals;
        }
        $task->set_custom_data($data);
        if ($runat > 0) {
            $task->set_next_run_time($runat);
        }
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * A name for the task, for the admin task logs.
     *
     * @return string
     */
    public function get_name() {
        return get_string('scoring_task', 'mod_presenterai');
    }

    /**
     * Score the recording named in the custom data.
     *
     * Transient failures are retried while the backoff is young. The adhoc
     * fail delay doubles from 60 seconds (60, 120, 240, 480, 960), so a delay
     * of 960 is about the fifth run, roughly 30 minutes in. From then on the
     * scorer is told not to retry and fails the row, so a permanently broken
     * provider can't keep a task looping forever (SOLA's
     * classes/task/score_recording.php, kept verbatim in effect).
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $recordingid = (int) ($data->recordingid ?? 0);
        if ($recordingid <= 0) {
            return;
        }
        $mayretry = $this->get_fail_delay() < self::RETRY_UNTIL_DELAY;
        \mod_presenterai\local\scorer::score($recordingid, $mayretry, !empty($data->rescore), (int) ($data->deferrals ?? 0));
    }
}
