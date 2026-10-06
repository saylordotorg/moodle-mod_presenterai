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
     * @return array ['key' => lang string key, 'a' => its {$a} or null]
     */
    public static function state(\stdClass $rec, int $now, bool $cleanupenabled): array {
        $status = (string) ($rec->status ?? '');
        if (in_array($status, self::NEVER_UPLOADED, true)) {
            return ['key' => 'attempt_never_uploaded', 'a' => null];
        }

        $expiresat = (int) ($rec->expiresat ?? 0);
        if (self::has_media($rec)) {
            if ($expiresat <= 0 || !$cleanupenabled) {
                // A disabled task never arrives at the date, so promising one
                // would be a statement the site is not going to keep (8.7f).
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
     * @return array
     */
    public static function export(\stdClass $rec, \context_module $ctx, int $userid, int $now, bool $cleanupenabled): array {
        $state = self::state($rec, $now, $cleanupenabled);
        $hasmedia = self::has_media($rec);
        $neveruploaded = in_array((string) $rec->status, self::NEVER_UPLOADED, true);

        // The same string as the visible Recorded cell, so the accessible name
        // of each Watch or Download link identifies its row even when two
        // attempts were made on the same day (design 11, rule 3).
        $recorded = userdate((int) $rec->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $canwatch = $hasmedia && access::may_view($rec, $ctx, $userid);
        $candownload = $hasmedia && access::may_download($rec, $ctx, $userid);
        $candelete = $hasmedia && access::may_delete($rec, $ctx, $userid);

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
