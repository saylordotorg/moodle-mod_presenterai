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

use mod_presenterai\local\deletion_warning;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\storage\fs_store;
use mod_presenterai\local\storage\store_factory;

/**
 * Hourly housekeeping for recordings: abandoned uploads, deletion warnings, retention, pruning.
 *
 * A port of local_ai_course_assistant\task\soapbox_cleanup with the three
 * defects that file carried fixed, because each one deletes the wrong thing or
 * fails to delete the right one.
 *
 * The store is resolved per row from the row's own backend column. Soapbox
 * built one storage object before the loop and returned early when it was
 * unconfigured (soapbox_cleanup.php:47-50), so a site that had switched
 * backends skipped every row on the other one forever. Here one row on an
 * unconfigured backend is reported and skipped and the rest carry on.
 *
 * Retention never changes status. Soapbox overwrote a scored row with
 * 'deleted', which is how a learner's grade went to null a week after it
 * landed (plan section 3.3). Here the key goes null and mediagonereason says
 * why (D8).
 *
 * Pruning walks a recordset ordered by user. Soapbox keyed get_records_sql on a
 * column two learners share (soapbox_cleanup.php:73-82), so later rows
 * overwrote earlier ones and pruning ran for one learner per assignment. And
 * pruning only happens when an activity asks for it: storedattempts 0, the
 * default, keeps every attempt (D22), where Soapbox forced a minimum of one.
 *
 * Nothing printed here names a key or a URL. mtrace output ends up in task
 * logs that more people can read than can read the bucket.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var string[] Statuses whose media pruning never deletes: scoring hasn't read it yet. */
    public const PRUNE_PROTECTED = [recording_manager::STATUS_UPLOADED, recording_manager::STATUS_SCORING];

    /**
     * Name shown on the scheduled tasks page.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'mod_presenterai');
    }

    /**
     * Run the steps in order.
     *
     * The advance warnings go before retention. The two never pick the same
     * row (a warning needs a date still ahead, retention a date passed), and
     * this order means a run that fails partway through has sent the messages
     * before it deleted anything.
     *
     * @return void
     */
    public function execute() {
        $now = time();
        $this->sweep_abandoned($now);
        $this->warn_upcoming_deletions($now);
        $this->apply_retention($now);
        $this->prune_stored_attempts();
    }

    /**
     * Step 1: give up on attempts that never finished uploading.
     *
     * After a day an uploading row is not coming back. Its staged bytes and any
     * object it did manage to write are deleted, and the row is marked
     * abandoned. mediadeletedat and mediagonereason are left alone on purpose:
     * nothing was ever finished, so the attempt list says "not uploaded" rather
     * than announcing the deletion of a recording that never existed.
     *
     * @param int $now The run's notion of now.
     * @return void
     */
    protected function sweep_abandoned(int $now): void {
        global $DB;

        $abandoned = 0;
        $rs = $DB->get_recordset_select(
            'presenterai_recording',
            'status = :status AND timecreated < :cutoff',
            ['status' => recording_manager::STATUS_UPLOADING, 'cutoff' => $now - recording_manager::ABANDON_AFTER],
            'id'
        );
        foreach ($rs as $rec) {
            try {
                $store = store_factory::for_recording($rec);
                if (!empty($rec->uploadid)) {
                    $store->abort_upload((string) $rec->uploadid);
                }
                // The row still names its keys at this point, which is what lets
                // fs_store resolve them, and a key whose bytes never arrived is
                // already gone, so that delete succeeds too.
                $deleted = true;
                foreach (['storagekey', 'deckkey', 'frameskey'] as $column) {
                    if (!empty($rec->$column) && !$store->delete((string) $rec->$column)) {
                        $deleted = false;
                    }
                }
                if (!$deleted) {
                    mtrace("  recording {$rec->id}: could not delete an unfinished upload, will retry next run");
                    continue;
                }
                $DB->update_record('presenterai_recording', (object) [
                    'id' => $rec->id,
                    'storagekey' => null,
                    'deckkey' => null,
                    'frameskey' => null,
                    'uploadid' => null,
                    'status' => recording_manager::STATUS_ABANDONED,
                    'timemodified' => time(),
                ]);
                $abandoned++;
            } catch (\Throwable $e) {
                mtrace("  recording {$rec->id}: skipped, " . self::describe($e));
            }
        }
        $rs->close();

        $swept = fs_store::sweep_stale_staging(recording_manager::ABANDON_AFTER);
        mtrace("PresenterAI cleanup: {$abandoned} unfinished uploads abandoned, {$swept} stale staging files removed.");
    }

    /**
     * Step 2: tell learners whose recording is about to reach its deletion date.
     *
     * deletion_warning decides what is due (design 7.1, deletewarndays) and
     * records each send on the row, so a learner is told once per date.
     *
     * @param int $now The run's notion of now.
     * @return void
     */
    protected function warn_upcoming_deletions(int $now): void {
        $sent = deletion_warning::send_due($now);
        mtrace("PresenterAI cleanup: {$sent} advance deletion messages sent.");
    }

    /**
     * Step 3: delete media whose deletion date has passed.
     *
     * expiresat 0 means never and is excluded by the query. The hard floor in
     * design 8.7a is applied on top: a recording finished in the last day is
     * not deleted whatever its date says, so a site that set a very short
     * window, or a backlog that delayed scoring, cannot delete media before
     * transcription has read it.
     *
     * @param int $now The run's notion of now.
     * @return void
     */
    protected function apply_retention(int $now): void {
        global $DB;

        $dropped = 0;
        $deferred = 0;
        $failed = 0;
        $rs = $DB->get_recordset_select(
            'presenterai_recording',
            'storagekey IS NOT NULL AND expiresat > 0 AND expiresat <= :now AND status <> :uploading',
            ['now' => $now, 'uploading' => recording_manager::STATUS_UPLOADING],
            'expiresat, id'
        );
        $floorstatuses = [recording_manager::STATUS_UPLOADED, recording_manager::STATUS_SCORING];
        foreach ($rs as $rec) {
            $fresh = (int) $rec->timecreated > $now - DAYSECS;
            if ($fresh && in_array((string) $rec->status, $floorstatuses, true)) {
                $deferred++;
                continue;
            }
            try {
                if (recording_manager::drop_media($rec, 'retention')) {
                    $dropped++;
                } else {
                    $failed++;
                    mtrace("  recording {$rec->id}: media could not be deleted, will retry next run");
                }
            } catch (\Throwable $e) {
                $failed++;
                mtrace("  recording {$rec->id}: skipped, " . self::describe($e));
            }
        }
        $rs->close();

        mtrace("PresenterAI cleanup: {$dropped} recordings deleted on their deletion date, "
            . "{$deferred} held by the one day floor, {$failed} left for the next run.");
    }

    /**
     * Step 4: keep only the newest N recordings' media per learner where an activity asks for it.
     *
     * Only rows that still have media are counted, so a learner who deleted
     * their newest recording's media keeps the next one. The attempt rows, the
     * scores and the feedback all survive.
     *
     * An attempt that is uploaded or scoring is counted but never pruned: its
     * media is what transcription is about to read, or what a teacher grading
     * by hand is about to watch, and nothing has been made from it yet.
     *
     * @return void
     */
    protected function prune_stored_attempts(): void {
        global $DB;

        $pruned = 0;
        $failed = 0;
        $instances = $DB->get_records_select('presenterai', 'storedattempts > 0', null, 'id', 'id, storedattempts');
        [$notin, $params] = $DB->get_in_or_equal(
            [recording_manager::STATUS_UPLOADING, recording_manager::STATUS_ABANDONED],
            SQL_PARAMS_NAMED,
            'st',
            false
        );

        foreach ($instances as $instance) {
            $keep = (int) $instance->storedattempts;
            $params['presenteraiid'] = (int) $instance->id;
            $rs = $DB->get_recordset_select(
                'presenterai_recording',
                "presenteraiid = :presenteraiid AND storagekey IS NOT NULL AND status {$notin}",
                $params,
                'userid, timecreated DESC, id DESC'
            );
            $userid = null;
            $seen = 0;
            foreach ($rs as $rec) {
                if ((int) $rec->userid !== $userid) {
                    $userid = (int) $rec->userid;
                    $seen = 0;
                }
                $seen++;
                if ($seen <= $keep || in_array((string) $rec->status, self::PRUNE_PROTECTED, true)) {
                    continue;
                }
                try {
                    if (recording_manager::drop_media($rec, 'pruned')) {
                        $pruned++;
                    } else {
                        $failed++;
                        mtrace("  recording {$rec->id}: media could not be pruned, will retry next run");
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    mtrace("  recording {$rec->id}: skipped, " . self::describe($e));
                }
            }
            $rs->close();
        }

        mtrace("PresenterAI cleanup: {$pruned} older recordings pruned, {$failed} left for the next run.");
    }

    /**
     * A one line reason for a skipped row that names no key and no URL.
     *
     * The exception's message is left out because a curl failure can carry
     * the signed URL it was given.
     *
     * @param \Throwable $e What went wrong.
     * @return string
     */
    private static function describe(\Throwable $e): string {
        if ($e instanceof \moodle_exception) {
            return get_class($e) . ' ' . $e->errorcode;
        }

        return get_class($e);
    }
}
