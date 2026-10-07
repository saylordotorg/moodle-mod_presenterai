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

namespace mod_presenterai\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_presenterai\local\media_purger;
use mod_presenterai\local\score_manager;
use mod_presenterai\local\storage\store_factory;

/**
 * What PresenterAI holds about a person, how it is exported and how it is deleted.
 *
 * Three kinds of person appear in this plugin's tables. A learner owns
 * recordings, and with them the scores, transcripts and visual notes about
 * those recordings. A grader is named as graderid on score rows they wrote,
 * which belong to the learner, not to them. And anyone whose activity used AI
 * owns spend rows.
 *
 * The export follows design 5.4. The raw visualevidence note is exported with
 * a sentence saying what it is, because a subject access request is exactly
 * when a person is entitled to read what a model wrote about their body. The
 * learner facing visualsummary goes with each score, and the strings the
 * visual gate withheld (presenterai_gatelog) go with their attempt while the
 * seven day log still has them.
 *
 * A score held for a teacher's review (D28) is exported too, marked as not
 * yet released. It's personal data the site holds about the learner whether
 * or not it has been shown, which is also how mod_assign treats a grade in a
 * marking workflow state other than released: its provider exports the grade
 * and the workflow state alike.
 *
 * Deletion goes through media_purger, so stored objects are deleted through
 * the store BEFORE the rows that name them, on both backends. SOLA's provider
 * deleted the rows and left S3 objects behind for good
 * (classes/privacy/provider.php:529-541 there), which is the defect this
 * order exists to prevent. A grader's rows are not deleted when the grader
 * asks: graderid is set to 0, because the score is the learner's record.
 *
 * S3 media is NOT exported as bytes. export_custom_file() takes a string, and
 * a recording can be 150 MB, so the export carries a note with the backend and
 * the size instead. Whether a subject access request must include the video
 * itself is an open question for the site; on the File API the bytes are
 * exported.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] The File API areas holding attempt media, keyed by itemid = recording id. */
    private const AREAS = ['recording', 'deck', 'frames'];

    /**
     * Describe every table, subsystem and external location that holds personal data.
     *
     * @param collection $collection The collection to add to.
     * @return collection The same collection.
     */
    public static function get_metadata(collection $collection): collection {
        $recordingfields = [
            'userid', 'topicid', 'attemptnumber', 'mode', 'durationseconds', 'sizebytes', 'status', 'slidetimeline',
            'transcript', 'visualevidence', 'visualevidenceat', 'visualoptout', 'expiresat', 'mediadeletedat', 'mediagonereason',
            'deletewarnedat', 'timecreated',
        ];
        $collection->add_database_table(
            'presenterai_recording',
            self::field_strings('presenterai_recording', $recordingfields),
            'privacy:metadata:presenterai_recording'
        );

        $scorefields = [
            'userid', 'origin', 'scores', 'rawsum', 'rawmax', 'overallpct', 'feedback', 'tips', 'visualsummary', 'visualstatus',
            'released', 'graderid', 'legacymeanscore', 'legacymeta', 'timecreated',
        ];
        $collection->add_database_table(
            'presenterai_score',
            self::field_strings('presenterai_score', $scorefields),
            'privacy:metadata:presenterai_score'
        );

        $aiusagefields = [
            'userid', 'recordingid', 'action', 'provider', 'model', 'prompttokens', 'completiontokens', 'imagecount',
            'audioseconds', 'estmicrocents', 'timecreated',
        ];
        $collection->add_database_table(
            'presenterai_aiusage',
            self::field_strings('presenterai_aiusage', $aiusagefields),
            'privacy:metadata:presenterai_aiusage'
        );

        // Design 5.3: the feedback strings the visual gate rejected, kept seven
        // days for staff tuning the word lists. Linked to the attempt, so to
        // the learner, through recordingid.
        $collection->add_database_table(
            'presenterai_gatelog',
            self::field_strings(
                'presenterai_gatelog',
                ['recordingid', 'target', 'layer', 'gaterule', 'rejectedtext', 'timecreated']
            ),
            'privacy:metadata:presenterai_gatelog'
        );

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');
        // When the scoring route is Moodle's own AI subsystem, the transcript
        // and the scoring prompt go through it, and core keeps its own record.
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:core_ai');

        $collection->add_external_location_link('s3', [
            'media' => 'privacy:metadata:s3:media',
            'deck' => 'privacy:metadata:s3:deck',
            'frames' => 'privacy:metadata:s3:frames',
        ], 'privacy:metadata:s3');

        // The AI services transcription, scoring and body language feedback
        // call. What goes depends on the site's route; this declares the most.
        $collection->add_external_location_link('aiservice', [
            'audio' => 'privacy:metadata:aiservice:audio',
            'transcript' => 'privacy:metadata:aiservice:transcript',
            'frames' => 'privacy:metadata:aiservice:frames',
            'slides' => 'privacy:metadata:aiservice:slides',
            'feedback' => 'privacy:metadata:aiservice:feedback',
        ], 'privacy:metadata:aiservice');

        return $collection;
    }

    /**
     * The module contexts where this user owns a recording, graded one, or owns a spend row.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $base = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $params = ['contextlevel' => CONTEXT_MODULE, 'modname' => 'presenterai', 'userid' => $userid];

        $contextlist->add_from_sql(
            $base . " JOIN {presenterai_recording} r ON r.presenteraiid = cm.instance WHERE r.userid = :userid",
            $params
        );
        $contextlist->add_from_sql(
            $base . " JOIN {presenterai_recording} r ON r.presenteraiid = cm.instance
                      JOIN {presenterai_score} s ON s.recordingid = r.id
                     WHERE s.graderid = :userid",
            $params
        );
        $contextlist->add_from_sql(
            $base . " JOIN {presenterai_aiusage} u ON u.presenteraiid = cm.instance WHERE u.userid = :userid",
            $params
        );

        return $contextlist;
    }

    /**
     * Every user with data in one module context.
     *
     * @param userlist $userlist The list to add to, carrying the context.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $params = ['cmid' => $context->instanceid, 'modname' => 'presenterai'];
        $base = "FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module AND m.name = :modname";

        $userlist->add_from_sql(
            'userid',
            "SELECT r.userid {$base} JOIN {presenterai_recording} r ON r.presenteraiid = cm.instance WHERE cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'graderid',
            "SELECT s.graderid {$base}
               JOIN {presenterai_recording} r ON r.presenteraiid = cm.instance
               JOIN {presenterai_score} s ON s.recordingid = r.id
              WHERE cm.id = :cmid AND s.graderid > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT u.userid {$base} JOIN {presenterai_aiusage} u ON u.presenteraiid = cm.instance
              WHERE cm.id = :cmid AND u.userid > 0",
            $params
        );
    }

    /**
     * Export everything this user has in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and the user.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $userid = (int) $user->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('presenterai', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $instanceid = (int) $cm->instance;

            // The activity itself: name, intro and its files, as every module exports.
            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));
            helper::export_context_files($context, $user);

            $recs = $DB->get_records(
                'presenterai_recording',
                ['presenteraiid' => $instanceid, 'userid' => $userid],
                'attemptnumber ASC, id ASC'
            );
            foreach ($recs as $rec) {
                self::export_recording($context, $rec);
            }

            self::export_scores_given($context, $instanceid, $userid);
            self::export_aiusage($context, $instanceid, $userid);
        }
    }

    /**
     * Delete every user's data in one context: the whole activity's learner work.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('presenterai', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        media_purger::purge_instance((int) $cm->instance, media_purger::AIUSAGE_DELETE);
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and the user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $instanceid = self::instance_id($context);
            if ($instanceid !== null) {
                self::delete_user_in_instance($instanceid, $userid);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist The approved users and the context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $instanceid = self::instance_id($userlist->get_context());
        if ($instanceid === null) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_in_instance($instanceid, (int) $userid);
        }
    }

    /**
     * Remove one user from one activity: their attempts entirely, and their name from scores they wrote.
     *
     * @param int $instanceid The activity instance id.
     * @param int $userid The user.
     * @return void
     */
    private static function delete_user_in_instance(int $instanceid, int $userid): void {
        global $DB;

        // Media first, then scores, spend rows and the recording rows.
        media_purger::purge_user($instanceid, $userid, media_purger::AIUSAGE_DELETE);

        // A score a grader wrote is the learner's record, so it stays, without
        // the grader's identity.
        $DB->execute(
            "UPDATE {presenterai_score}
                SET graderid = 0
              WHERE graderid = :userid
                AND recordingid IN (SELECT id FROM {presenterai_recording} WHERE presenteraiid = :instanceid)",
            ['userid' => $userid, 'instanceid' => $instanceid]
        );
    }

    /**
     * Export one attempt: the row, every score on it and, on the File API, its files.
     *
     * @param \context_module $context The module context.
     * @param \stdClass $rec The presenterai_recording row.
     * @return void
     */
    private static function export_recording(\context_module $context, \stdClass $rec): void {
        global $DB;

        $subcontext = [
            get_string('privacy:path:attempts', 'mod_presenterai'),
            'attempt-' . (int) $rec->attemptnumber . '-' . (int) $rec->id,
        ];

        $topic = null;
        if (!empty($rec->topicid)) {
            $title = $DB->get_field('presenterai_topic', 'title', ['id' => $rec->topicid]);
            $topic = $title === false ? null : format_string($title, true, ['context' => $context]);
        }

        $hasmedia = !empty($rec->storagekey);
        $data = [
            'attemptnumber' => (int) $rec->attemptnumber,
            'topic' => $topic,
            'mode' => $rec->mode,
            'status' => $rec->status,
            'durationseconds' => (int) $rec->durationseconds,
            'sizebytes' => (int) $rec->sizebytes,
            'mediastored' => transform::yesno($hasmedia),
            'expiresat' => (int) $rec->expiresat > 0 ? transform::datetime($rec->expiresat) : null,
            'mediadeletedat' => (int) $rec->mediadeletedat > 0 ? transform::datetime($rec->mediadeletedat) : null,
            'mediagonereason' => $rec->mediagonereason,
            'deletewarnedat' => (int) ($rec->deletewarnedat ?? 0) > 0 ? transform::datetime($rec->deletewarnedat) : null,
            'slidetimeline' => $rec->slidetimeline,
            'transcript' => $rec->transcript,
            // Design 5.4: the raw note, declared for what it is.
            'visualevidence' => $rec->visualevidence,
            'visualevidence_note' => (string) ($rec->visualevidence ?? '') !== ''
                ? get_string('privacy:export:visualevidence_note', 'mod_presenterai')
                : null,
            'visualevidenceat' => (int) ($rec->visualevidenceat ?? 0) > 0 ? transform::datetime($rec->visualevidenceat) : null,
            'visualoptout' => transform::yesno(!empty($rec->visualoptout)),
            'timecreated' => transform::datetime($rec->timecreated),
            'scores' => self::scores_for($rec),
            'rejectedfeedback' => self::gatelog_for($rec),
        ];
        if ($hasmedia && (string) $rec->backend === store_factory::BACKEND_S3) {
            $data['media_note'] = get_string('privacy:export:s3media', 'mod_presenterai', display_size((int) $rec->sizebytes));
        }

        $writer = writer::with_context($context);
        $writer->export_data($subcontext, (object) $data);
        if ((string) $rec->backend === store_factory::BACKEND_FS) {
            foreach (self::AREAS as $area) {
                $writer->export_area_files($subcontext, 'mod_presenterai', $area, (int) $rec->id);
            }
        }
    }

    /**
     * Every score row on one attempt, oldest first, without the grader's identity.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @return array List of exportable score objects.
     */
    private static function scores_for(\stdClass $rec): array {
        global $DB;

        $out = [];
        $scores = $DB->get_records('presenterai_score', ['recordingid' => $rec->id], 'timecreated ASC, id ASC');
        foreach ($scores as $score) {
            // Only a score migrated from Soapbox has these. legacymeta is that
            // session's own record, decoded when it's JSON and kept as text when not.
            $legacy = $score->legacymeta !== null || (int) $score->legacymeanscore > 0;
            $legacymeta = null;
            if ($score->legacymeta !== null) {
                $decoded = json_decode((string) $score->legacymeta);
                $legacymeta = $decoded !== null ? $decoded : $score->legacymeta;
            }
            $out[] = (object) [
                'origin' => $score->origin,
                'criteria' => self::criteria($score->scores),
                'rawsum' => (int) $score->rawsum,
                'rawmax' => (int) $score->rawmax,
                'overallpct' => $score->overallpct === null ? null : (float) $score->overallpct,
                'feedback' => $score->feedback,
                'tips' => $score->tips,
                'visualsummary' => $score->visualsummary ?? null,
                'visualstatus' => (string) ($score->visualstatus ?? ''),
                'released' => transform::yesno(score_manager::is_released($score)),
                'held_note' => score_manager::is_released($score)
                    ? null
                    : get_string('privacy:export:heldnote', 'mod_presenterai'),
                'legacymeanscore' => $legacy ? (int) $score->legacymeanscore : null,
                'legacymeta' => $legacymeta,
                'timecreated' => transform::datetime($score->timecreated),
            ];
        }

        return $out;
    }

    /**
     * The feedback strings the visual gate withheld from this attempt, while the log still holds them.
     *
     * They are about the learner, so a subject access request returns them,
     * with the rule that withheld each one.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @return array List of exportable gatelog objects.
     */
    private static function gatelog_for(\stdClass $rec): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records('presenterai_gatelog', ['recordingid' => $rec->id], 'timecreated ASC, id ASC') as $row) {
            $out[] = (object) [
                'target' => $row->target,
                'layer' => (int) $row->layer,
                'rule' => $row->gaterule,
                'rejectedtext' => $row->rejectedtext,
                'timecreated' => transform::datetime($row->timecreated),
            ];
        }

        return $out;
    }

    /**
     * The per-criterion marks of one score row, decoded for reading.
     *
     * @param string|null $json The scores column.
     * @return array List of {name, score, max_score, feedback, assessed, visual, counts}.
     */
    private static function criteria(?string $json): array {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $out[] = (object) [
                'name' => (string) ($criterion['name'] ?? ''),
                'score' => $criterion['score'] ?? null,
                'max_score' => $criterion['max_score'] ?? null,
                'feedback' => $criterion['feedback'] ?? null,
                'assessed' => transform::yesno(!array_key_exists('assessed', $criterion) || !empty($criterion['assessed'])),
                'visual' => transform::yesno(!empty($criterion['visual'])),
                'counts' => transform::yesno(!array_key_exists('counts', $criterion) || $criterion['counts'] !== false),
            ];
        }

        return $out;
    }

    /**
     * The scores this user wrote as a grader, without the learner's identity.
     *
     * @param \context_module $context The module context.
     * @param int $instanceid The activity instance id.
     * @param int $userid The grader.
     * @return void
     */
    private static function export_scores_given(\context_module $context, int $instanceid, int $userid): void {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT s.id, s.origin, s.overallpct, s.feedback, s.timecreated
               FROM {presenterai_score} s
               JOIN {presenterai_recording} r ON r.id = s.recordingid
              WHERE r.presenteraiid = :instanceid AND s.graderid = :userid
           ORDER BY s.timecreated ASC, s.id ASC",
            ['instanceid' => $instanceid, 'userid' => $userid]
        );
        if (!$rows) {
            return;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = (object) [
                'origin' => $row->origin,
                'overallpct' => $row->overallpct === null ? null : (float) $row->overallpct,
                'feedback' => $row->feedback,
                'timecreated' => transform::datetime($row->timecreated),
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:scoresgiven', 'mod_presenterai')],
            (object) ['scores' => $out]
        );
    }

    /**
     * This user's AI spend rows in one activity.
     *
     * @param \context_module $context The module context.
     * @param int $instanceid The activity instance id.
     * @param int $userid The user.
     * @return void
     */
    private static function export_aiusage(\context_module $context, int $instanceid, int $userid): void {
        global $DB;

        $rows = $DB->get_records(
            'presenterai_aiusage',
            ['presenteraiid' => $instanceid, 'userid' => $userid],
            'timecreated ASC, id ASC'
        );
        if (!$rows) {
            return;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = (object) [
                'action' => $row->action,
                'provider' => $row->provider,
                'model' => $row->model,
                'prompttokens' => (int) $row->prompttokens,
                'completiontokens' => (int) $row->completiontokens,
                'imagecount' => (int) $row->imagecount,
                'audioseconds' => (int) $row->audioseconds,
                'estmicrocents' => (int) $row->estmicrocents,
                'timecreated' => transform::datetime($row->timecreated),
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:aiusage', 'mod_presenterai')],
            (object) ['usage' => $out]
        );
    }

    /**
     * The activity instance id behind a module context, or null when it is not one of ours.
     *
     * @param \context $context The context.
     * @return int|null
     */
    private static function instance_id(\context $context): ?int {
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('presenterai', $context->instanceid, 0, false, IGNORE_MISSING);

        return $cm ? (int) $cm->instance : null;
    }

    /**
     * Map field names to their privacy:metadata language string ids.
     *
     * @param string $table The table name.
     * @param string[] $fields The field names.
     * @return array Field name => string id.
     */
    private static function field_strings(string $table, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = 'privacy:metadata:' . $table . ':' . $field;
        }

        return $out;
    }
}
