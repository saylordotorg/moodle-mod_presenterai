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

use mod_presenterai\local\storage\store_factory;
use mod_presenterai\task\delete_orphaned_media;

/**
 * Remove attempts completely: their media, scores, gate log, spend rows and the rows themselves.
 *
 * The privacy provider and course reset both need this, and they need it in
 * the same order, which is the whole reason it is one class. Storage ALWAYS
 * goes before rows. fs_store finds a file only through the row that names its
 * key, and an S3 object is unfindable once its row is gone, because the rows
 * are the only record of which objects exist. SOLA's privacy provider deleted
 * rows and left the objects (classes/privacy/provider.php:529-541), which is
 * the orphan this plugin does not repeat.
 *
 * A storage failure never stops the rows going. A privacy deletion or a course
 * reset that refuses to finish because a bucket is briefly down would leave the
 * learner's data in place for longer, which is worse. So an S3 object that
 * cannot be deleted now is handed to delete_orphaned_media, which keeps trying
 * after the rows are gone. Nothing logged here names a key or a URL.
 *
 * Unlike recording_manager::drop_media(), which keeps the attempt and records
 * why its media went, this removes the attempt itself. The attempt is no
 * longer the learner's to keep: they asked for it to go, or the course was
 * reset.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class media_purger {
    /** @var string Delete the spend rows too, as a privacy deletion must. */
    public const AIUSAGE_DELETE = 'delete';

    /** @var string Keep the spend rows with no person attached, as a course reset does, so totals survive. */
    public const AIUSAGE_ANONYMISE = 'anonymise';

    /** @var int Recordings handled per batch. */
    public const BATCH = 200;

    /** @var string[] The file areas a recording's media can occupy on the File API, keyed by itemid = recording id. */
    public const AREAS = ['recording', 'deck', 'frames', 'audio'];

    /**
     * Purge a set of recordings: storage first, then scores, spend rows and the rows themselves.
     *
     * @param \stdClass[] $recs presenterai_recording rows carrying at least id, presenteraiid,
     *                         userid, backend, storagekey, deckkey, frameskey, audiokey and uploadid.
     * @param string $aiusagemode AIUSAGE_DELETE or AIUSAGE_ANONYMISE.
     * @return void
     */
    public static function purge_recordings(array $recs, string $aiusagemode): void {
        global $DB;

        self::check_mode($aiusagemode);
        if (empty($recs)) {
            return;
        }

        $ids = [];
        foreach ($recs as $rec) {
            $ids[] = (int) $rec->id;
        }

        // Step a: objects, through the store, while every row still names them.
        $failed = [];
        foreach ($recs as $rec) {
            $failed = array_merge($failed, self::delete_objects($rec, $ids));
        }

        // Step b: anything left in this attempt's file areas. Normally nothing is,
        // but a file the row stopped naming (a crash between a delete and a row
        // update) is still the learner's, and the area is addressable without a key.
        $fs = get_file_storage();
        $contextids = [];
        foreach ($recs as $rec) {
            if ((string) $rec->backend !== store_factory::BACKEND_FS) {
                continue;
            }
            $instanceid = (int) $rec->presenteraiid;
            if (!array_key_exists($instanceid, $contextids)) {
                $contextids[$instanceid] = self::context_id($instanceid);
            }
            $contextid = $contextids[$instanceid];
            if ($contextid === null) {
                // No course module: core removes the context's files with it.
                continue;
            }
            foreach (self::AREAS as $area) {
                $fs->delete_area_files($contextid, 'mod_presenterai', $area, (int) $rec->id);
            }
        }

        // Step c: the S3 objects that could not be deleted get a task that keeps
        // trying, because after (f) nothing else will remember them.
        if ($failed) {
            delete_orphaned_media::queue(store_factory::BACKEND_S3, $failed);
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'prid');

        // Step d: scores belong to the attempt, and so do the feedback strings
        // the visual gate rejected, which name nobody but are about this learner.
        $DB->delete_records_select('presenterai_score', "recordingid {$insql}", $params);
        $DB->delete_records_select('presenterai_gatelog', "recordingid {$insql}", $params);

        // Step e: spend rows: removed for a privacy request, kept without a person for a reset.
        if ($aiusagemode === self::AIUSAGE_DELETE) {
            $DB->delete_records_select('presenterai_aiusage', "recordingid {$insql}", $params);
        } else {
            $DB->execute("UPDATE {presenterai_aiusage} SET userid = 0, recordingid = 0 WHERE recordingid {$insql}", $params);
        }

        // Step f: and last, the rows.
        $DB->delete_records_select('presenterai_recording', "id {$insql}", $params);
    }

    /**
     * Purge every recording of one activity, and its remaining spend rows.
     *
     * @param int $presenteraiid The activity instance id.
     * @param string $aiusagemode AIUSAGE_DELETE or AIUSAGE_ANONYMISE.
     * @return void
     */
    public static function purge_instance(int $presenteraiid, string $aiusagemode): void {
        global $DB;

        self::check_mode($aiusagemode);
        self::purge_where('presenteraiid = :presenteraiid', ['presenteraiid' => $presenteraiid], $aiusagemode);

        // Spend rows that never had a recording, such as a deck render.
        if ($aiusagemode === self::AIUSAGE_DELETE) {
            $DB->delete_records('presenterai_aiusage', ['presenteraiid' => $presenteraiid]);
        } else {
            $DB->execute(
                'UPDATE {presenterai_aiusage} SET userid = 0, recordingid = 0 WHERE presenteraiid = :presenteraiid',
                ['presenteraiid' => $presenteraiid]
            );
        }
    }

    /**
     * Purge one learner's recordings in one activity, and their remaining spend rows there.
     *
     * @param int $presenteraiid The activity instance id.
     * @param int $userid The learner.
     * @param string $aiusagemode AIUSAGE_DELETE or AIUSAGE_ANONYMISE.
     * @return void
     */
    public static function purge_user(int $presenteraiid, int $userid, string $aiusagemode): void {
        global $DB;

        self::check_mode($aiusagemode);
        self::purge_where(
            'presenteraiid = :presenteraiid AND userid = :userid',
            ['presenteraiid' => $presenteraiid, 'userid' => $userid],
            $aiusagemode
        );

        $params = ['presenteraiid' => $presenteraiid, 'userid' => $userid];
        if ($aiusagemode === self::AIUSAGE_DELETE) {
            $DB->delete_records('presenterai_aiusage', $params);
        } else {
            $DB->execute(
                'UPDATE {presenterai_aiusage} SET userid = 0, recordingid = 0
                  WHERE presenteraiid = :presenteraiid AND userid = :userid',
                $params
            );
        }
    }

    /**
     * Purge the matching recordings a batch at a time.
     *
     * Batches are read by id upwards rather than through one open recordset,
     * because each batch deletes rows from the table being read, and an open
     * recordset over a table that is changing underneath it is not safe on
     * every database Moodle supports.
     *
     * @param string $where SQL selecting presenterai_recording rows.
     * @param array $params Its named parameters.
     * @param string $aiusagemode AIUSAGE_DELETE or AIUSAGE_ANONYMISE.
     * @return void
     */
    private static function purge_where(string $where, array $params, string $aiusagemode): void {
        global $DB;

        $lastid = 0;
        $fields = 'id, presenteraiid, userid, backend, ' . implode(', ', recording_manager::MEDIA_COLUMNS) . ', uploadid';
        while (true) {
            $batch = $DB->get_records_select(
                'presenterai_recording',
                "{$where} AND id > :purgelastid",
                $params + ['purgelastid' => $lastid],
                'id ASC',
                $fields,
                0,
                self::BATCH
            );
            if (empty($batch)) {
                return;
            }
            $lastid = (int) max(array_keys($batch));
            self::purge_recordings($batch, $aiusagemode);
        }
    }

    /**
     * Delete one recording's objects through its store, never throwing.
     *
     * @param \stdClass $rec The recording row.
     * @param int[] $batchids Every recording being purged with it, which do not count as sharing a key.
     * @return string[] S3 keys that could not be deleted and need the orphan task.
     */
    private static function delete_objects(\stdClass $rec, array $batchids): array {
        $backend = (string) $rec->backend;
        $keys = [];
        foreach (recording_manager::MEDIA_COLUMNS as $column) {
            $key = (string) ($rec->$column ?? '');
            // A key a row outside this batch also names (a restored copy on the
            // same site) stays in the bucket for that row.
            if ($key !== '' && !recording_manager::key_in_use_outside($backend, $key, $batchids)) {
                $keys[] = $key;
            }
        }
        if (empty($keys) && empty($rec->uploadid)) {
            return [];
        }

        $failed = [];
        try {
            $store = store_factory::for_recording($rec);
            foreach ($keys as $key) {
                if (!$store->delete($key)) {
                    $failed[] = $key;
                }
            }
            if (!empty($rec->uploadid)) {
                $store->abort_upload((string) $rec->uploadid);
            }
        } catch (\Throwable $e) {
            $failed = $keys;
            debugging(
                'mod_presenterai could not reach the store of recording ' . (int) $rec->id . ' while purging it: '
                    . get_class($e),
                DEBUG_DEVELOPER
            );
        }

        // Only S3 gets a later attempt. An fs file is removed from its area in
        // step (b), or with its context, and fs_store could not resolve the key
        // without the row anyway.
        return $backend === store_factory::BACKEND_S3 ? $failed : [];
    }

    /**
     * The module context id of an activity, or null when it has no course module.
     *
     * @param int $presenteraiid The activity instance id.
     * @return int|null
     */
    private static function context_id(int $presenteraiid): ?int {
        $cm = get_coursemodule_from_instance('presenterai', $presenteraiid, 0, false, IGNORE_MISSING);
        $context = $cm ? \context_module::instance((int) $cm->id, IGNORE_MISSING) : false;

        return $context ? (int) $context->id : null;
    }

    /**
     * Refuse a spend-row mode this class does not know.
     *
     * @param string $mode The mode passed in.
     * @return void
     */
    private static function check_mode(string $mode): void {
        if ($mode !== self::AIUSAGE_DELETE && $mode !== self::AIUSAGE_ANONYMISE) {
            throw new \coding_exception('Unknown aiusage mode: ' . $mode);
        }
    }
}
