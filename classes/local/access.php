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

/**
 * Who may watch, download or delete a recording's media. The single authority.
 *
 * Every entry point asks here rather than combining capabilities itself:
 * pluginfile, download.php, the playback and delete web services and the
 * attempt list. Soapbox spread the same decision over four files and they
 * disagreed, which is how a teacher who could watch a recording also turned
 * out to be able to download it.
 *
 * Two rules from design section 7.5 and DECISIONS.md D22 shape the download
 * methods. Viewing to grade and taking a copy away are different acts, so
 * viewallattempts never implies downloadany. And allowlearnerdownload governs
 * what a learner may keep of their own recording and nothing else, so it never
 * limits a grader holding downloadany.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string[] Statuses of an attempt that never finished arriving, so there is nothing to score. */
    private const NOT_GRADABLE = ['uploading', 'abandoned'];

    /**
     * Whether the site lets learners download their own recordings at all.
     *
     * get_config() returns false for a setting that has never been saved, and
     * the shipped default is on, so only a stored zero switches it off.
     *
     * @return bool
     */
    public static function site_allows_learner_download(): bool {
        $value = get_config('mod_presenterai', 'allowlearnerdownload');
        if ($value === false) {
            return true;
        }

        return (int) $value === 1;
    }

    /**
     * Whether the user may watch this recording and read its playback details.
     *
     * Deliberately silent about whether the media still exists: a learner whose
     * recording was deleted still opens the attempt to read why, and the
     * playback service answers that with a structured media gone result.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function may_view(\stdClass $rec, \context_module $ctx, int $userid): bool {
        if (self::is_owner($rec, $userid) && has_capability('mod/presenterai:view', $ctx, $userid)) {
            return true;
        }

        return has_capability('mod/presenterai:viewallattempts', $ctx, $userid)
            && self::group_allows($rec, $ctx, $userid);
    }

    /**
     * Whether the user may take a copy of this recording away. Design 7.5, verbatim.
     *
     * Download permission is read live and never stored per attempt. A
     * learner whose download right is withdrawn loses it on their next request,
     * and one granted it gains it for every recording they already have.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function may_download(\stdClass $rec, \context_module $ctx, int $userid): bool {
        if (!self::has_media($rec)) {
            return false;
        }

        if (self::is_owner($rec, $userid)) {
            return self::site_allows_learner_download()
                && has_capability('mod/presenterai:downloadown', $ctx, $userid);
        }

        // Not implied by viewallattempts, and not governed by allowlearnerdownload.
        return has_capability('mod/presenterai:downloadany', $ctx, $userid)
            && self::group_allows($rec, $ctx, $userid);
    }

    /**
     * Whether the user may delete this recording's media early.
     *
     * Media only. The attempt row, its score and its feedback survive, so the
     * attempt still counts toward the grade and toward the attempt cap and a
     * learner cannot delete their way out of a bad mark (design 8.5). That is
     * why a learner's own delete is safe to offer, which is the recommended
     * answer to open question 9.21.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function may_delete(\stdClass $rec, \context_module $ctx, int $userid): bool {
        if (!self::has_media($rec)) {
            return false;
        }

        if (self::is_owner($rec, $userid)) {
            return has_capability('mod/presenterai:deleteownmedia', $ctx, $userid);
        }

        return has_capability('mod/presenterai:deleteanyrecording', $ctx, $userid)
            && self::group_allows($rec, $ctx, $userid);
    }

    /**
     * Whether a recording this user makes now could be downloaded by them later.
     *
     * For the callout above the recorder, which must tell a learner before they
     * speak whether they will be able to keep a copy (design 8.7b). Answered
     * from the same two inputs may_download() uses for an owner, so the promise
     * and the eventual answer cannot drift apart.
     *
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user about to record.
     * @return bool
     */
    public static function may_download_own_prospectively(\context_module $ctx, int $userid): bool {
        return self::site_allows_learner_download()
            && has_capability('mod/presenterai:downloadown', $ctx, $userid);
    }

    /**
     * Whether the user may score this attempt on the grading screen.
     *
     * The same group rule as watching, so a grader in separate groups cannot
     * reach a learner outside their groups by guessing an id. An attempt that
     * never finished uploading has nothing to score. An owner who holds the
     * grade capability (a teacher trying the activity out) may score their own
     * attempt through that capability and no other route.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function may_grade(\stdClass $rec, \context_module $ctx, int $userid): bool {
        if (in_array((string) ($rec->status ?? ''), self::NOT_GRADABLE, true)) {
            return false;
        }

        return has_capability('mod/presenterai:grade', $ctx, $userid)
            && self::group_allows($rec, $ctx, $userid);
    }

    /**
     * Load a recording named in a request and confirm the user may grade it.
     *
     * The id in the URL is only a claim. The row must belong to this course
     * module's instance and pass may_grade(). A missing row and a forbidden one
     * raise the same error, so the response does not reveal which ids exist.
     *
     * @param \stdClass|\cm_info $cm The course module the request came through. A cm_info
     *                              is accepted because that is what get_course_and_cm_from_cmid() returns.
     * @param \context_module $ctx That course module's context.
     * @param int $recordingid The recording id from the request.
     * @param int $userid The user asking.
     * @return \stdClass The presenterai_recording row.
     * @throws \moodle_exception error:recordingnotfound
     */
    public static function require_gradable_recording(
        \stdClass|\cm_info $cm,
        \context_module $ctx,
        int $recordingid,
        int $userid
    ): \stdClass {
        global $DB;

        $rec = $DB->get_record('presenterai_recording', ['id' => $recordingid, 'presenteraiid' => (int) $cm->instance]);
        if (!$rec || !self::may_grade($rec, $ctx, $userid)) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }

        return $rec;
    }

    /**
     * Whether the user may read the raw body language note on the grading screen.
     *
     * The note is unreviewed prose about a named learner's body (design 7.3),
     * so it has a capability of its own rather than riding on grade.
     *
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function may_view_visual_evidence(\context_module $ctx, int $userid): bool {
        return has_capability('mod/presenterai:viewvisualevidence', $ctx, $userid);
    }

    /**
     * The learners a staff member sees on the submissions report.
     *
     * Everyone actively enrolled who may submit, narrowed to one group when a
     * group is chosen. In separate groups the caller passes the active group
     * from groups_get_activity_group(), which core already limits to the
     * viewer's own groups unless they hold moodle/site:accessallgroups. The
     * capability is checked for the current user, who is the viewer.
     *
     * @param \stdClass|\cm_info $cm The course module.
     * @param \context_module $ctx Its context.
     * @param int $groupid The active group, or 0 for everyone.
     * @return int[] User ids.
     */
    public static function visible_learner_ids(\stdClass|\cm_info $cm, \context_module $ctx, int $groupid): array {
        // A viewer in separate groups who is in no group gets 0 back from core
        // as the active group. That must mean nobody, not everybody.
        if (
            $groupid <= 0
            && (int) groups_get_activity_groupmode($cm) === SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $ctx)
        ) {
            return [];
        }

        $users = get_enrolled_users($ctx, 'mod/presenterai:submit', max(0, $groupid), 'u.id', null, 0, 0, true);

        return array_map('intval', array_keys($users));
    }

    /**
     * Whether the row describes finished media that still exists.
     *
     * An uploading row can carry a key before any byte has arrived (D16), so
     * a key alone is not proof of media. DECISIONS.md D8 defines media gone as
     * a null key, and an unfinished attempt has no media to act on yet.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @return bool
     */
    private static function has_media(\stdClass $rec): bool {
        return !empty($rec->storagekey) && (string) ($rec->status ?? '') !== recording_manager::STATUS_UPLOADING;
    }

    /**
     * Whether group mode lets the user act on another learner's recording.
     *
     * In separate groups a teacher sees only the learners who share a group
     * with them, unless they hold moodle/site:accessallgroups, which is the
     * rule every core activity applies to its grading screens. The activity
     * declares FEATURE_GROUPS, so without this a non-editing teacher in one
     * group could watch any learner's recording by guessing its id. Visible
     * groups and no groups restrict nothing. The course module comes from the
     * context, never from the request.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param \context_module $ctx The activity's module context.
     * @param int $userid The user asking, who is not the owner.
     * @return bool
     */
    private static function group_allows(\stdClass $rec, \context_module $ctx, int $userid): bool {
        $cm = get_coursemodule_from_id('presenterai', (int) $ctx->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        if ((int) groups_get_activity_groupmode($cm) !== SEPARATEGROUPS) {
            return true;
        }
        if (has_capability('moodle/site:accessallgroups', $ctx, $userid)) {
            return true;
        }

        $mine = groups_get_all_groups((int) $cm->course, $userid, (int) $cm->groupingid, 'g.id');
        if (empty($mine)) {
            return false;
        }
        $theirs = groups_get_all_groups((int) $cm->course, (int) $rec->userid, (int) $cm->groupingid, 'g.id');

        return !empty(array_intersect_key($mine, $theirs));
    }

    /**
     * Whether the user is the learner who made the recording.
     *
     * @param \stdClass $rec A presenterai_recording row.
     * @param int $userid The user asking.
     * @return bool
     */
    private static function is_owner(\stdClass $rec, int $userid): bool {
        return $userid > 0 && (int) $rec->userid === $userid;
    }
}
