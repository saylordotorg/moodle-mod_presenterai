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

use mod_presenterai\local\storage\media_ref;
use mod_presenterai\local\storage\store_factory;
use mod_presenterai\local\storage\store_interface;

/**
 * One attempt's life, from an empty row to media that has gone.
 *
 * DECISIONS.md D16 is the shape: the row exists before the bytes, because the
 * File API needs an itemid before the first byte arrives. So an attempt is
 * begun (row, status uploading, no keys), then each piece of media is started
 * (a key is minted here and written to the row, never accepted from the
 * browser), then the attempt is finalized (the bytes are confirmed by the
 * store, the attempt number and deletion date are fixed). Soapbox did it the
 * other way round, minting a key for the browser and trusting the key the
 * browser sent back at finalize, which is why its finalize needed a prefix
 * check to stop one learner registering another learner's object.
 *
 * Minting the recording's key only when the learner presses Stop, rather than
 * at begin, is what removes the S3 problem of a 900 second upload URL issued
 * before a seven minute recording.
 *
 * The single uploadid column is shared by the deck, the frame sheet and the
 * recording, which works because they never upload at the same time and
 * always in that order: the deck is committed before the frames are started,
 * the frames are committed before the recording is started
 * (commit_pending_deck(), commit_pending_frames()), and a deck cannot be
 * started once frames or the recording have a key. On the File API that makes
 * the rule simple to state: once storagekey is set, uploadid belongs to the
 * recording; before that, once frameskey is set, it belongs to the frames;
 * before that, to the deck.
 *
 * One attempt row belongs to one page. begin() hands out a fresh clienttoken
 * every time it returns a row, and start_upload() and finalize() refuse a
 * token that is no longer the row's. A row is resumed only while nothing has
 * been uploaded into it, so a second tab that resumes a row the first tab has
 * not used yet takes it over cleanly (the first tab's next call is refused and
 * it begins again), and a row that already holds a deck or a recording key is
 * never handed to anybody else. Without that, two tabs shared one row and each
 * deleted the other's upload when it started its own.
 *
 * Media gone is storagekey IS NULL on a row that is not uploading (D8), with
 * mediadeletedat and mediagonereason saying when and why. drop_media() is the
 * only way a finished attempt loses its media, and it never touches status.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_manager {
    /** @var string The row exists and bytes may be arriving. */
    public const STATUS_UPLOADING = 'uploading';

    /** @var string Finalized: the bytes are confirmed and the attempt counts. */
    public const STATUS_UPLOADED = 'uploaded';

    /** @var string Phase 3: transcription and scoring are under way. */
    public const STATUS_SCORING = 'scoring';

    /** @var string Phase 3: scoring failed and the attempt does not count. */
    public const STATUS_FAILED = 'failed';

    /** @var string Never finished uploading, or refused at finalize. Never counts. */
    public const STATUS_ABANDONED = 'abandoned';

    /** @var int Largest PDF deck accepted, 20 MB. */
    public const MAX_DECK_BYTES = 20971520;

    /** @var int Largest frame contact sheet accepted, 8 MB. Six 426 by 240 JPEG cells come to well under 1 MB. */
    public const MAX_FRAMES_BYTES = 8388608;

    /** @var string[] Extensions a frame contact sheet may be uploaded with. frames.js makes JPEG. */
    public const FRAMES_EXTS = ['jpg'];

    /** @var int Most slide-advance events kept from one recording, which guards absurd input. */
    public const MAX_TIMELINE_EVENTS = 500;

    /** @var int Seconds within which an unfinished attempt is resumed rather than a new row made. */
    public const RESUME_WINDOW = 43200;

    /** @var int Seconds after which an unfinished attempt is abandoned by the cleanup task. */
    public const ABANDON_AFTER = DAYSECS;

    /** @var int Unfinished attempts one learner may hold on one activity before begin() refuses another. */
    public const MAX_OPEN_ATTEMPTS = 10;

    /** @var int Length of the per-row token that ties an attempt to the page that began it. */
    private const TOKEN_LENGTH = 32;

    /** @var string[] Extensions a recording may be uploaded with. */
    public const RECORDING_EXTS = ['webm', 'mp4', 'm4a', 'ogg', 'oga'];

    /** @var string[] Extensions a deck may be uploaded with. */
    public const DECK_EXTS = ['pdf'];

    /** @var string[] Statuses that do not use up an attempt. */
    public const COUNTED_EXCLUDED = ['uploading', 'abandoned', 'failed'];

    /** @var string[] Every reason media can go, as stored in mediagonereason (design 8.4). */
    public const GONE_REASONS = ['retention', 'pruned', 'manual', 'learner', 'missing', 'notbackedup'];

    /** @var int Seconds a presigned playback URL lives on S3 (plan 4.6, point 1). */
    public const PLAYBACK_TTL = 300;

    /** @var int Seconds a presigned download URL lives on S3, long enough to finish the transfer. */
    public const DOWNLOAD_TTL = 900;

    /** @var int The default site ceiling for one recording, 150 MB, as fs_store has it. */
    private const DEFAULT_MAX_MEDIA_BYTES = 157286400;

    /** @var string Lock factory namespace serialising finalize per learner per activity. */
    private const LOCK_TYPE = 'mod_presenterai_attempt';

    /** @var int Seconds finalize waits for the attempt lock. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Load a recording and everything needed to authorise a request about it.
     *
     * The instance, course, course module and context are all derived from the
     * row. A web service never accepts a cmid for a row lookup, because a cmid
     * the caller chose is a cmid whose capabilities the caller chose.
     *
     * @param int $recordingid The recording id from the request.
     * @return array [\stdClass $rec, \stdClass $instance, \stdClass $course, \stdClass $cm, \context_module $context]
     */
    public static function load(int $recordingid): array {
        global $DB;

        $rec = $recordingid > 0 ? $DB->get_record('presenterai_recording', ['id' => $recordingid]) : false;
        if (!$rec) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }
        $instance = $DB->get_record('presenterai', ['id' => $rec->presenteraiid]);
        if (!$instance) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }
        $cm = get_coursemodule_from_instance('presenterai', $instance->id, $instance->course, false, IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('error:recordingnotfound', 'mod_presenterai');
        }
        $course = get_course((int) $instance->course);
        $context = \context_module::instance((int) $cm->id);

        return [$rec, $instance, $course, $cm, $context];
    }

    /**
     * The site ceiling for one recording, before any course limit.
     *
     * This plugin's maxmediabytes setting, or 150 MB when it is unset, and no
     * more than $CFG->maxbytes when that is set. fs_store applies the same rule
     * per chunk, privately, because a chunk carries no course.
     *
     * @return int Bytes.
     */
    public static function site_max_media_bytes(): int {
        global $CFG;

        $site = (int) get_config('mod_presenterai', 'maxmediabytes');
        if ($site <= 0) {
            $site = self::DEFAULT_MAX_MEDIA_BYTES;
        }
        $moodle = (int) ($CFG->maxbytes ?? 0);

        return $moodle > 0 ? min($site, $moodle) : $site;
    }

    /**
     * The largest recording this course accepts, in bytes.
     *
     * The site ceiling, lowered by the course's own maxbytes when that is set.
     * Not get_max_upload_file_size(), which also folds in upload_max_filesize
     * and post_max_size: those bind one request, chunking exists so a
     * recording can be larger than one request, and on a stock php.ini they
     * would cap every recording at 2 MB. Moodle's site and course limits are
     * honoured, which is what an administrator set and what plugin review asks.
     *
     * @param \stdClass $course The course row, carrying maxbytes.
     * @return int Bytes.
     */
    public static function max_media_bytes(\stdClass $course): int {
        $ceiling = self::site_max_media_bytes();
        $coursemax = (int) ($course->maxbytes ?? 0);

        return $coursemax > 0 ? min($ceiling, $coursemax) : $ceiling;
    }

    /**
     * How many attempts this learner has used on this activity.
     *
     * An attempt that never finished uploading, one that was abandoned, and one
     * whose scoring failed for a reason that was not the learner's do not count.
     * Deleted media does count: the attempt and its score survive the media.
     *
     * @param int $presenteraiid The activity instance id.
     * @param int $userid The learner.
     * @return int
     */
    public static function counted_attempts(int $presenteraiid, int $userid): int {
        global $DB;

        [$notin, $params] = $DB->get_in_or_equal(self::COUNTED_EXCLUDED, SQL_PARAMS_NAMED, 'st', false);
        $params['presenteraiid'] = $presenteraiid;
        $params['userid'] = $userid;

        return $DB->count_records_select(
            'presenterai_recording',
            "presenteraiid = :presenteraiid AND userid = :userid AND status {$notin}",
            $params
        );
    }

    /**
     * Whether this learner has used every attempt the activity allows.
     *
     * @param \stdClass $instance A presenterai row carrying maxattempts. 0 is unlimited.
     * @param int $userid The learner.
     * @return bool
     */
    public static function cap_reached(\stdClass $instance, int $userid): bool {
        $max = (int) ($instance->maxattempts ?? 0);
        if ($max <= 0) {
            return false;
        }

        return self::counted_attempts((int) $instance->id, $userid) >= $max;
    }

    /**
     * Start an attempt, or pick up the one this learner left unused.
     *
     * Resuming is what bounds row spam: a learner who reloads the page twelve
     * times gets one uploading row, not twelve. Only a row nothing has been
     * uploaded into is resumed, and its token is replaced, so the page that had
     * it before is refused on its next call and begins again. A row that
     * already holds a deck or a recording key belongs to the page that put it
     * there and is left alone; the cleanup task sweeps it within a day if that
     * page never comes back.
     *
     * The cap is checked here so a learner who has no attempts left is told
     * before they speak, and again at finalize under the lock, because two tabs
     * can both pass this check.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $cm The course module row.
     * @param \context_module $ctx The module context.
     * @param int $userid The learner.
     * @return array ['recording' => \stdClass, 'resumed' => bool, 'token' => string]
     */
    public static function begin(\stdClass $instance, \stdClass $cm, \context_module $ctx, int $userid): array {
        return self::with_attempt_lock((int) $instance->id, $userid, function () use ($instance, $userid): array {
            global $DB;

            $now = time();
            $token = random_string(self::TOKEN_LENGTH);
            $existing = $DB->get_records_select(
                'presenterai_recording',
                'presenteraiid = :presenteraiid AND userid = :userid AND status = :status AND timecreated > :since
                    AND storagekey IS NULL AND deckkey IS NULL AND frameskey IS NULL AND uploadid IS NULL',
                [
                    'presenteraiid' => $instance->id,
                    'userid' => $userid,
                    'status' => self::STATUS_UPLOADING,
                    'since' => $now - self::RESUME_WINDOW,
                ],
                'timecreated DESC, id DESC',
                '*',
                0,
                1
            );
            if ($existing) {
                $rec = reset($existing);
                $rec->clienttoken = $token;
                $rec->timemodified = $now;
                $DB->update_record('presenterai_recording', (object) [
                    'id' => $rec->id,
                    'clienttoken' => $token,
                    'timemodified' => $now,
                ]);
                return ['recording' => $rec, 'resumed' => true, 'token' => $token];
            }

            $open = $DB->count_records_select(
                'presenterai_recording',
                'presenteraiid = :presenteraiid AND userid = :userid AND status = :status AND timecreated > :since',
                [
                    'presenteraiid' => $instance->id,
                    'userid' => $userid,
                    'status' => self::STATUS_UPLOADING,
                    'since' => $now - self::ABANDON_AFTER,
                ]
            );
            if ($open >= self::MAX_OPEN_ATTEMPTS) {
                // Each open row can hold a deck, so without this a script could
                // park a 20 MB deck per call until the sweep caught up.
                throw new \moodle_exception('error:toomanyopen', 'mod_presenterai');
            }

            if (self::cap_reached($instance, $userid)) {
                throw new \moodle_exception('error:capreached', 'mod_presenterai');
            }

            // Scoring is set up but transcription isn't, so every attempt would
            // fail to score. Refuse before the learner speaks, not after.
            if (!\mod_presenterai\local\ai\route_resolver::accepts_recordings()) {
                throw new \moodle_exception('error:notranscription', 'mod_presenterai');
            }

            // Throws errorstorenotconfigured when S3 is selected and not
            // configured, which is right: a recording must not quietly go to the
            // other backend. Stamped from the store's own name(), which is the
            // backend that will actually accept the bytes.
            $store = store_factory::default_store();

            $id = $DB->insert_record('presenterai_recording', (object) [
                'presenteraiid' => (int) $instance->id,
                'userid' => $userid,
                // Assigned at finalize, so an attempt that is abandoned never uses up a number.
                'attemptnumber' => 0,
                'mode' => (string) ($instance->mode ?? 'video'),
                'backend' => $store->name(),
                'status' => self::STATUS_UPLOADING,
                'clienttoken' => $token,
                'expiresat' => 0,
                'mediadeletedat' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            return [
                'recording' => $DB->get_record('presenterai_recording', ['id' => $id], '*', MUST_EXIST),
                'resumed' => false,
                'token' => $token,
            ];
        });
    }

    /**
     * Refuse a request from a page that no longer holds this attempt.
     *
     * @param \stdClass $rec The recording row as read under the attempt lock.
     * @param string|null $token The token the page was given by begin(). Null skips
     *                           the check, for server-side callers that act for no page;
     *                           every web service passes the browser's value.
     * @return void
     */
    private static function require_token(\stdClass $rec, ?string $token): void {
        if ($token === null) {
            return;
        }
        $held = (string) ($rec->clienttoken ?? '');
        if ($held === '' || !hash_equals($held, $token)) {
            throw new \moodle_exception('error:attemptsuperseded', 'mod_presenterai');
        }
    }

    /**
     * Mint a key for one piece of media and return where the browser should send it.
     *
     * The key is written to the row here and is never returned to the browser.
     * Finalize reads it back from the row, so a learner cannot point their
     * attempt at somebody else's object by sending a different key, and there
     * is no prefix check to get wrong.
     *
     * Replacing media of the same kind (a learner who picks a different deck, or
     * whose recording upload is retried from scratch) deletes the old object
     * first, while the row still names it, because fs_store cannot resolve a key
     * no row references and an unresolvable file is never deleted by anything.
     *
     * @param \stdClass $rec The recording row, status uploading. Updated in place.
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $ctx The module context.
     * @param string $kind media_ref::KIND_RECORDING, media_ref::KIND_DECK or media_ref::KIND_FRAMES.
     * @param string $ext File extension without the dot.
     * @param int $sizebytes The size the browser says it is about to send.
     * @param string|null $token The page's token from begin(), or null for a server-side caller.
     * @return array ['method', 'url', 'uploadid', 'chunkbytes', 'expires', 'maxbytes']
     */
    public static function start_upload(
        \stdClass $rec,
        \stdClass $instance,
        \stdClass $course,
        \context_module $ctx,
        string $kind,
        string $ext,
        int $sizebytes,
        ?string $token = null
    ): array {
        // Under the same lock as begin(), so a token cannot be replaced between
        // the check below and the key being written.
        return self::with_attempt_lock(
            (int) $instance->id,
            (int) $rec->userid,
            function () use ($rec, $instance, $course, $ctx, $kind, $ext, $sizebytes, $token): array {
                global $DB;

                $fresh = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
                $target = self::start_upload_locked($fresh, $instance, $course, $ctx, $kind, $ext, $sizebytes, $token);
                // Callers keep using the row they passed in, so it is brought up
                // to date in place, as it was before the lock re-read it.
                foreach (get_object_vars($fresh) as $name => $value) {
                    $rec->$name = $value;
                }
                return $target;
            }
        );
    }

    /**
     * start_upload(), with the attempt lock held and the row freshly read.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $ctx The module context.
     * @param string $kind media_ref::KIND_RECORDING, media_ref::KIND_DECK or media_ref::KIND_FRAMES.
     * @param string $ext File extension without the dot.
     * @param int $sizebytes The declared size.
     * @param string|null $token The page's token, or null for a server-side caller.
     * @return array As start_upload().
     */
    private static function start_upload_locked(
        \stdClass $rec,
        \stdClass $instance,
        \stdClass $course,
        \context_module $ctx,
        string $kind,
        string $ext,
        int $sizebytes,
        ?string $token
    ): array {
        global $DB;

        if ((string) $rec->status !== self::STATUS_UPLOADING) {
            throw new \moodle_exception('error:notuploading', 'mod_presenterai');
        }
        self::require_token($rec, $token);
        if (!in_array($kind, [media_ref::KIND_RECORDING, media_ref::KIND_DECK, media_ref::KIND_FRAMES], true)) {
            throw new \invalid_parameter_exception('kind must be recording, deck or frames');
        }
        $isdeck = $kind === media_ref::KIND_DECK;
        $isframes = $kind === media_ref::KIND_FRAMES;
        if ($isdeck && empty($instance->slidesenabled)) {
            throw new \moodle_exception('error:slidesdisabled', 'mod_presenterai');
        }
        if ($isframes && !self::frames_wanted($rec, $instance)) {
            // Frames exist only for a camera recording on an activity that
            // asked for body language feedback (D17). An audio attempt has no
            // body to sample, and nothing is sent that nothing will read.
            throw new \moodle_exception('error:framesdisabled', 'mod_presenterai');
        }
        $ext = strtolower(trim($ext));
        $exts = $isdeck ? self::DECK_EXTS : ($isframes ? self::FRAMES_EXTS : self::RECORDING_EXTS);
        if (!in_array($ext, $exts, true)) {
            throw new \moodle_exception('error:badext', 'mod_presenterai');
        }
        if ($isdeck) {
            $ceiling = self::MAX_DECK_BYTES;
        } else if ($isframes) {
            $ceiling = self::MAX_FRAMES_BYTES;
        } else {
            $ceiling = self::max_media_bytes($course);
        }
        if ($sizebytes > $ceiling) {
            throw new \moodle_exception('error:uploadtoolarge', 'mod_presenterai', '', display_size($ceiling));
        }
        if ($isdeck && (!empty($rec->storagekey) || !empty($rec->frameskey))) {
            // The one uploadid column belongs to the frames or the recording
            // from here on, and a deck swapped after the recording has started
            // would no longer match the slide timeline the learner recorded
            // against.
            throw new \moodle_exception('error:deckafterrecording', 'mod_presenterai');
        }
        if ($isframes && !empty($rec->storagekey)) {
            // The frames come before the recording, so the upload id is free;
            // once the recording has a key it is the recording's.
            throw new \moodle_exception('error:framesdisabled', 'mod_presenterai');
        }

        $store = store_factory::for_recording($rec);
        $chunked = !$store->supports_direct_upload();

        if (!$isdeck) {
            self::commit_pending_deck($rec, $ctx, $course);
        }
        if (!$isdeck && !$isframes) {
            self::commit_pending_frames($rec, $ctx, $course);
        }

        $column = $isdeck ? 'deckkey' : ($isframes ? 'frameskey' : 'storagekey');
        if (!empty($rec->$column)) {
            // Whatever upload id the row holds at this point belongs to this
            // kind: the commits above have just cleared the deck's and the
            // frames', and nothing earlier in the order is started once a
            // later kind has a key.
            if ($chunked && !empty($rec->uploadid)) {
                $store->abort_upload((string) $rec->uploadid);
            }
            if (!$store->delete((string) $rec->$column)) {
                throw new \moodle_exception('error:deletefailed', 'mod_presenterai');
            }
        }

        $ref = new media_ref((int) $rec->id, (int) $ctx->id, (int) $course->id, (int) $rec->userid, $kind, $ext);
        // The declared size is checked against the ceiling above, and on S3 it
        // is signed into the PUT, so the bytes that arrive cannot exceed it.
        $target = $store->begin_upload($ref, max(0, $sizebytes));

        $rec->$column = (string) $target['key'];
        $rec->uploadid = isset($target['uploadid']) ? (string) $target['uploadid'] : null;
        $rec->timemodified = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            $column => $rec->$column,
            'uploadid' => $rec->uploadid,
            'timemodified' => $rec->timemodified,
        ]);

        return [
            'method' => (string) $target['method'],
            'url' => (string) $target['url'],
            'uploadid' => (string) ($target['uploadid'] ?? ''),
            'chunkbytes' => (int) ($target['chunkbytes'] ?? 0),
            'expires' => (int) ($target['expires'] ?? 0),
            'maxbytes' => $ceiling,
        ];
    }

    /**
     * Commit an uploaded deck into storage, or drop it if it never arrived.
     *
     * Idempotent: a deck already in storage reports its size and nothing
     * changes. A deck that is missing, refused by the antivirus scanner or
     * larger than MAX_DECK_BYTES is deleted and its key cleared, and that never
     * fails the attempt. Losing the slides beside a recording is a smaller harm
     * than losing the recording.
     *
     * @param \stdClass $rec The recording row. Updated in place as well as in the database.
     * @param \context_module $ctx The module context.
     * @param \stdClass $course The course row.
     * @return int|null The deck's size in bytes, or null when there is no deck.
     */
    public static function commit_pending_deck(\stdClass $rec, \context_module $ctx, \stdClass $course): ?int {
        global $DB;

        if (empty($rec->deckkey)) {
            return null;
        }

        $store = store_factory::for_recording($rec);
        $chunked = !$store->supports_direct_upload();
        $key = (string) $rec->deckkey;
        // On the File API the upload id is the deck's until the frames or the recording have a key.
        $deckuploadid = ($chunked && empty($rec->storagekey) && empty($rec->frameskey) && !empty($rec->uploadid))
            ? (string) $rec->uploadid : '';

        $size = null;
        try {
            if ($chunked) {
                // Already committed by an earlier call, whose upload id was then cleared.
                $size = $store->size($key);
                if ($size === null && $deckuploadid !== '') {
                    $size = $store->commit_upload(self::ref($rec, $ctx, $course, media_ref::KIND_DECK, $key), $deckuploadid);
                }
            } else {
                $size = $store->commit_upload(self::ref($rec, $ctx, $course, media_ref::KIND_DECK, $key));
            }
        } catch (\core\antivirus\scanner_exception $e) {
            // The scanner has already removed the staging file. Treated as a
            // deck that did not arrive, which is what it now is.
            $size = null;
        }

        if ($size !== null && $size <= self::MAX_DECK_BYTES) {
            if ($deckuploadid !== '') {
                $rec->uploadid = null;
                $DB->set_field('presenterai_recording', 'uploadid', null, ['id' => $rec->id]);
            }
            return $size;
        }

        // Delete while the row still names the key, which fs_store needs.
        if ($deckuploadid !== '') {
            $store->abort_upload($deckuploadid);
        }
        $store->delete($key);
        $rec->deckkey = null;
        if ($deckuploadid !== '') {
            $rec->uploadid = null;
        }
        $rec->timemodified = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            'deckkey' => null,
            'uploadid' => $rec->uploadid,
            'timemodified' => $rec->timemodified,
        ]);

        return null;
    }

    /**
     * Commit an uploaded frame contact sheet into storage, or drop it if it never arrived.
     *
     * The same shape as commit_pending_deck(), for the same reason: idempotent,
     * and a sheet that is missing, refused by the antivirus scanner or larger
     * than MAX_FRAMES_BYTES is deleted and its key cleared without failing the
     * attempt. The frames are best effort from the browser onwards; a learner
     * who loses them gets the "could not be assessed" sentence, never a lost
     * recording.
     *
     * @param \stdClass $rec The recording row. Updated in place as well as in the database.
     * @param \context_module $ctx The module context.
     * @param \stdClass $course The course row.
     * @return int|null The sheet's size in bytes, or null when there is no sheet.
     */
    public static function commit_pending_frames(\stdClass $rec, \context_module $ctx, \stdClass $course): ?int {
        global $DB;

        if (empty($rec->frameskey)) {
            return null;
        }

        $store = store_factory::for_recording($rec);
        $chunked = !$store->supports_direct_upload();
        $key = (string) $rec->frameskey;
        // On the File API the upload id is the frames' until the recording has a key.
        $framesuploadid = ($chunked && empty($rec->storagekey) && !empty($rec->uploadid)) ? (string) $rec->uploadid : '';

        $size = null;
        try {
            if ($chunked) {
                $size = $store->size($key);
                if ($size === null && $framesuploadid !== '') {
                    $size = $store->commit_upload(self::ref($rec, $ctx, $course, media_ref::KIND_FRAMES, $key), $framesuploadid);
                }
            } else {
                $size = $store->commit_upload(self::ref($rec, $ctx, $course, media_ref::KIND_FRAMES, $key));
            }
        } catch (\core\antivirus\scanner_exception $e) {
            $size = null;
        }

        if ($size !== null && $size <= self::MAX_FRAMES_BYTES) {
            if ($framesuploadid !== '') {
                $rec->uploadid = null;
                $DB->set_field('presenterai_recording', 'uploadid', null, ['id' => $rec->id]);
            }
            return $size;
        }

        // Delete while the row still names the key, which fs_store needs.
        if ($framesuploadid !== '') {
            $store->abort_upload($framesuploadid);
        }
        if (!self::key_in_use_elsewhere((string) $rec->backend, $key, (int) $rec->id)) {
            $store->delete($key);
        }
        $rec->frameskey = null;
        if ($framesuploadid !== '') {
            $rec->uploadid = null;
        }
        $rec->timemodified = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            'frameskey' => null,
            'uploadid' => $rec->uploadid,
            'timemodified' => $rec->timemodified,
        ]);

        return null;
    }

    /**
     * Whether this attempt may carry a frame contact sheet at all.
     *
     * A camera recording on a camera activity with video vision on. The
     * learner's opt out is not checked here, because it is only known at
     * finalize; the browser doesn't sample or send frames when it is ticked,
     * and finalize deletes any that arrived anyway (D24).
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @return bool
     */
    public static function frames_wanted(\stdClass $rec, \stdClass $instance): bool {
        return !empty($instance->videovision)
            && (string) ($instance->mode ?? 'video') === 'video'
            && (string) ($rec->mode ?? 'video') === 'video';
    }

    /**
     * Confirm the recording arrived and make the attempt count.
     *
     * Idempotent. A client whose finalize response was lost retries it, and
     * gets the same answer rather than an error for work that succeeded.
     *
     * Runs under a lock per learner per activity, because the cap is
     * re-checked and the attempt number assigned here, and two tabs finalizing
     * at once would otherwise both see the same count.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $ctx The module context.
     * @param int $topicid The chosen topic, or 0.
     * @param int $durationseconds The recorded length the browser reports.
     * @param string $timeline The slide-advance timeline as JSON, or empty.
     * @param string|null $token The page's token from begin(), or null for a server-side caller.
     * @param bool|null $transitioned Set to true only when this call moved the row out of
     *                                uploading, so the caller fires the submitted event once
     *                                even when two requests finalize the same row at once.
     * @param bool $visualoptout Whether the learner ticked the body language opt out (D24).
     *                           Honoured only when the activity offers it.
     * @return \stdClass The updated row.
     */
    public static function finalize(
        \stdClass $rec,
        \stdClass $instance,
        \stdClass $course,
        \context_module $ctx,
        int $topicid,
        int $durationseconds,
        string $timeline,
        ?string $token = null,
        ?bool &$transitioned = null,
        bool $visualoptout = false
    ): \stdClass {
        $transitioned = false;
        if (self::finalized_already($rec)) {
            return $rec;
        }

        return self::with_attempt_lock(
            (int) $instance->id,
            (int) $rec->userid,
            function () use (
                $rec,
                $instance,
                $course,
                $ctx,
                $topicid,
                $durationseconds,
                $timeline,
                $token,
                &$transitioned,
                $visualoptout
            ) {
                return self::finalize_locked(
                    $rec,
                    $instance,
                    $course,
                    $ctx,
                    $topicid,
                    $durationseconds,
                    $timeline,
                    $token,
                    $transitioned,
                    $visualoptout
                );
            }
        );
    }

    /**
     * finalize(), with the attempt lock held.
     *
     * @param \stdClass $rec The recording row.
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $course The course row.
     * @param \context_module $ctx The module context.
     * @param int $topicid The chosen topic, or 0.
     * @param int $durationseconds The recorded length the browser reports.
     * @param string $timeline The slide-advance timeline as JSON, or empty.
     * @param string|null $token The page's token, or null for a server-side caller.
     * @param bool|null $transitioned Set to true when this call made the row uploaded.
     * @param bool $visualoptout Whether the learner ticked the body language opt out (D24).
     * @return \stdClass The updated row.
     */
    private static function finalize_locked(
        \stdClass $rec,
        \stdClass $instance,
        \stdClass $course,
        \context_module $ctx,
        int $topicid,
        int $durationseconds,
        string $timeline,
        ?string $token,
        ?bool &$transitioned,
        bool $visualoptout = false
    ): \stdClass {
        global $DB;

        // Re-read under the lock: another request may have finalized it.
        $rec = $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
        if (self::finalized_already($rec)) {
            return $rec;
        }
        self::require_token($rec, $token);
        if (empty($rec->storagekey)) {
            throw new \moodle_exception('error:uploadmissing', 'mod_presenterai');
        }

        self::commit_pending_deck($rec, $ctx, $course);

        // D24. The opt out is honoured only where the activity offers it, and
        // only for an attempt that could have had frames at all. Frames that
        // arrived anyway (an old page, a crafted request) are deleted here,
        // before the attempt counts, so nothing can ever read them.
        $optedout = $visualoptout && !empty($instance->allowvisualoptout) && self::frames_wanted($rec, $instance);
        if ($optedout) {
            self::discard_frames($rec);
        } else {
            self::commit_pending_frames($rec, $ctx, $course);
        }

        $store = store_factory::for_recording($rec);
        $key = (string) $rec->storagekey;
        $ref = self::ref($rec, $ctx, $course, media_ref::KIND_RECORDING, $key);
        if ($store->supports_direct_upload()) {
            $size = $store->commit_upload($ref);
        } else {
            // A retry after a commit whose row update was lost finds the
            // file already stored; asking first keeps that a success.
            $size = $store->size($key);
            if ($size === null) {
                $size = $store->commit_upload($ref, (string) ($rec->uploadid ?? ''));
            }
        }
        if ($size === null) {
            // The row stays uploading, so the client can upload again and retry.
            throw new \moodle_exception('error:uploadmissing', 'mod_presenterai');
        }

        $ceiling = self::max_media_bytes($course);
        if ($size > $ceiling) {
            self::abandon($rec, $store);
            throw new \moodle_exception('error:uploadtoolarge', 'mod_presenterai', '', display_size($ceiling));
        }

        // This row is uploading, so counted_attempts() already leaves it out.
        $counted = self::counted_attempts((int) $instance->id, (int) $rec->userid);
        $max = (int) ($instance->maxattempts ?? 0);
        if ($max > 0 && $counted >= $max) {
            // The row is left uploading and its bytes kept: nothing the
            // learner did is lost, and the sweep collects it in a day.
            throw new \moodle_exception('error:capreached', 'mod_presenterai');
        }

        // A learner must choose a topic when the activity has any. With exactly
        // one there is nothing to choose, so it is used. With several and none
        // valid, the row is left uploading and its bytes kept, as at the cap:
        // the learner picks a topic and presses Retry, and nothing is lost.
        $topicids = $DB->get_fieldset_select('presenterai_topic', 'id', 'presenteraiid = ?', [$instance->id]);
        $keeptopic = $topicid > 0 && in_array($topicid, array_map('intval', $topicids), true);
        if (!$keeptopic && count($topicids) === 1) {
            $topicid = (int) reset($topicids);
            $keeptopic = true;
        } else if (!$keeptopic && count($topicids) > 1) {
            throw new \moodle_exception('error:topicrequired', 'mod_presenterai');
        }

        $timelinejson = null;
        if (!empty($rec->deckkey)) {
            $events = self::normalise_timeline($timeline);
            if (!empty($events)) {
                $timelinejson = json_encode($events);
            }
        }

        $maxseconds = (int) ($instance->maxseconds ?? 0);
        $configmax = config::max_recording_seconds();
        $limit = ($maxseconds > 0 ? min($maxseconds, $configmax) : $configmax) + 60;
        $duration = max(0, min($durationseconds, $limit));

        $now = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            'topicid' => $keeptopic ? $topicid : null,
            'slidetimeline' => $timelinejson,
            'durationseconds' => $duration,
            'sizebytes' => $size,
            'attemptnumber' => $counted + 1,
            // Written here and nowhere else. A later settings change never
            // rewrites it; cli/apply_retention.php is the only tool that does.
            // The backend is passed so a declared bucket lifecycle shortens it,
            // which keeps the row's date the one the callout promised.
            'expiresat' => retention::expiry_for($instance, $now, (string) $rec->backend),
            'status' => self::STATUS_UPLOADED,
            'uploadid' => null,
            'clienttoken' => null,
            'visualoptout' => $optedout ? 1 : 0,
            'timemodified' => $now,
        ]);
        $transitioned = true;

        // Scored in an adhoc task, never in this web request (plan section 5).
        // With no AI set up the attempt stays uploaded and is graded by hand.
        if (\mod_presenterai\local\ai\route_resolver::ai_ready()) {
            \mod_presenterai\task\score_recording::queue((int) $rec->id);
        }

        return $DB->get_record('presenterai_recording', ['id' => $rec->id], '*', MUST_EXIST);
    }

    /**
     * Delete an attempt's frame sheet and forget its key, for an opted out attempt.
     *
     * Called from finalize, when the recording has a key, so the upload id is
     * the recording's and is left alone. The object goes first, while the row
     * still names it, because fs_store finds a file only through its row.
     *
     * @param \stdClass $rec The recording row. Updated in place as well as in the database.
     * @return void
     */
    private static function discard_frames(\stdClass $rec): void {
        global $DB;

        $key = (string) ($rec->frameskey ?? '');
        if ($key === '') {
            return;
        }
        if (!self::key_in_use_elsewhere((string) $rec->backend, $key, (int) $rec->id)) {
            store_factory::for_recording($rec)->delete($key);
        }
        $rec->frameskey = null;
        $DB->set_field('presenterai_recording', 'frameskey', null, ['id' => $rec->id]);
    }

    /**
     * Run $fn holding the lock for one learner on one activity.
     *
     * begin(), start_upload() and finalize() all take it: begin() because it
     * replaces a row's token, start_upload() because it checks that token and
     * then writes a key, and finalize() because it re-checks the cap and
     * assigns the attempt number.
     *
     * @param int $presenteraiid The activity instance id.
     * @param int $userid The learner.
     * @param callable $fn The work to do.
     * @return mixed Whatever $fn returns.
     */
    private static function with_attempt_lock(int $presenteraiid, int $userid, callable $fn) {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($presenteraiid . '_' . $userid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('error:uploadbusy', 'mod_presenterai');
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Clean a slide-advance timeline into a bounded, sorted list of {t, i}.
     *
     * A port of local_ai_course_assistant\soapbox_config::normalize_slide_timeline,
     * the same in behaviour: t is whole seconds into the recording and i is the
     * zero based slide index. Malformed entries are dropped, negatives clamped
     * to zero, an index past the end clamped to the last slide when the slide
     * count is known, the list sorted by time and cut at MAX_TIMELINE_EVENTS.
     * The input is browser supplied, so nothing in it is trusted.
     *
     * @param mixed $raw A JSON string or an already decoded array.
     * @param int $slidecount Slides in the deck, or 0 when unknown (no upper clamp on the index).
     * @return array List of ['t' => int, 'i' => int].
     */
    public static function normalise_timeline($raw, int $slidecount = 0): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !array_key_exists('t', $entry) || !array_key_exists('i', $entry)) {
                continue;
            }
            $t = (int) round((float) $entry['t']);
            $i = (int) $entry['i'];
            if ($t < 0) {
                $t = 0;
            }
            if ($i < 0) {
                $i = 0;
            }
            if ($slidecount > 0 && $i > $slidecount - 1) {
                $i = $slidecount - 1;
            }
            $out[] = ['t' => $t, 'i' => $i];
        }

        usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
        if (count($out) > self::MAX_TIMELINE_EVENTS) {
            $out = array_slice($out, 0, self::MAX_TIMELINE_EVENTS);
        }

        return $out;
    }

    /**
     * Delete a finished attempt's media and record why, keeping the attempt.
     *
     * The bytes go first and the columns after, because fs_store finds a file
     * only through the row that names its key: clear the row first and the
     * file can never be named again, by this or anything else. If the
     * recording itself cannot be deleted nothing is written, so the next run
     * tries again. The deck and the frame sheet are deleted best effort.
     *
     * Status is never changed (D8). The score, the feedback and the transcript
     * survive, and so does the attempt's place in the grade and the cap.
     *
     * An S3 key that another recording row also names is not deleted from the
     * bucket, but this row's columns are still cleared and the reason still
     * recorded: this attempt no longer has the media, the other one does. That
     * is the shared-key guard, key_in_use_elsewhere().
     *
     * @param \stdClass $rec The recording row, carrying at least id, backend and the key columns.
     * @param string $reason One of GONE_REASONS.
     * @return bool True when the media is gone and the row says so.
     */
    public static function drop_media(\stdClass $rec, string $reason): bool {
        global $DB;

        if (!in_array($reason, self::GONE_REASONS, true)) {
            throw new \coding_exception('Unknown mediagonereason: ' . $reason);
        }

        $store = store_factory::for_recording($rec);
        $backend = (string) $rec->backend;
        $recid = (int) $rec->id;
        // A key another row also names is left in the bucket: this row stops
        // claiming it, the other row keeps its media. See key_in_use_elsewhere().
        $storagekey = (string) ($rec->storagekey ?? '');
        $shared = $storagekey !== '' && self::key_in_use_elsewhere($backend, $storagekey, $recid);
        if ($storagekey !== '' && !$shared && !$store->delete($storagekey)) {
            return false;
        }
        foreach (['deckkey', 'frameskey'] as $column) {
            $key = (string) ($rec->$column ?? '');
            if ($key !== '' && !self::key_in_use_elsewhere($backend, $key, $recid)) {
                $store->delete($key);
            }
        }
        if (!empty($rec->uploadid)) {
            $store->abort_upload((string) $rec->uploadid);
        }

        $now = time();
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            'storagekey' => null,
            'deckkey' => null,
            'frameskey' => null,
            'uploadid' => null,
            'mediadeletedat' => $now,
            'mediagonereason' => $reason,
            // The model's prose about a learner's body must not outlive the
            // video it describes.
            'visualevidence' => null,
            'visualevidenceat' => 0,
            'timemodified' => $now,
        ]);

        return true;
    }

    /**
     * Delete every stored object an activity's recordings point at.
     *
     * Called by presenterai_delete_instance() before it deletes the rows. On
     * the File API core would remove the files with the context anyway; on S3
     * nothing would, and the rows are the only record of which objects exist,
     * so deleting rows first strands every object in the bucket for good. That
     * is the Soapbox defect this plugin was not going to repeat.
     *
     * Best effort and it never throws, because a teacher deleting an activity
     * must not be stopped by a bucket credential somebody cleared. An S3 object
     * that cannot be deleted now, including every object on an S3 backend that
     * is no longer configured, is handed to the delete_orphaned_media task,
     * which keeps trying after the rows are gone (design 8.6, point 5).
     *
     * An S3 object that a recording of another activity also names, which is
     * what a same-site restore or a course copy leaves behind, is kept for that
     * other recording and is not queued either.
     *
     * @param int $presenteraiid The activity instance id.
     * @return void
     */
    public static function delete_all_media_for_instance(int $presenteraiid): void {
        global $DB;

        $rs = $DB->get_recordset_select(
            'presenterai_recording',
            'presenteraiid = :presenteraiid AND (storagekey IS NOT NULL OR deckkey IS NOT NULL
                OR frameskey IS NOT NULL OR uploadid IS NOT NULL)',
            ['presenteraiid' => $presenteraiid],
            'id',
            'id, backend, storagekey, deckkey, frameskey, uploadid'
        );
        $orphans = [];
        foreach ($rs as $rec) {
            $keys = [];
            foreach (['storagekey', 'deckkey', 'frameskey'] as $column) {
                $key = (string) ($rec->$column ?? '');
                // Every row of this activity is about to go, so only a row of
                // another activity (a restored copy) can still need the object.
                if ($key !== '' && !self::key_in_use_outside_instance((string) $rec->backend, $key, $presenteraiid)) {
                    $keys[] = $key;
                }
            }
            try {
                $store = store_factory::for_recording($rec);
                foreach ($keys as $key) {
                    if (!$store->delete($key)) {
                        $orphans[(string) $rec->backend][] = $key;
                    }
                }
                if (!empty($rec->uploadid)) {
                    $store->abort_upload((string) $rec->uploadid);
                }
            } catch (\Throwable $e) {
                foreach ($keys as $key) {
                    $orphans[(string) $rec->backend][] = $key;
                }
                debugging(
                    'mod_presenterai could not reach the store of recording ' . $rec->id . ' while deleting activity '
                        . $presenteraiid . ': ' . get_class($e),
                    DEBUG_DEVELOPER
                );
            }
        }
        $rs->close();

        // Only S3 needs a later attempt: an fs file goes with the context, and
        // fs_store could not find it again once the row is gone anyway.
        if (!empty($orphans[store_factory::BACKEND_S3])) {
            \mod_presenterai\task\delete_orphaned_media::queue(store_factory::BACKEND_S3, $orphans[store_factory::BACKEND_S3]);
        }
    }

    /**
     * Whether another recording row still names this stored object.
     *
     * The shared-key guard. A same-site restore or a course copy with user data
     * keeps each S3 row's keys, because the bytes cannot travel in the backup
     * and the bucket already holds them. Two rows then name one object, and
     * deleting it for one of them (retention, pruning, a learner's delete, a
     * privacy request, a course reset) would silently destroy the media the
     * other row still promises its learner. So every path that deletes an
     * object asks this first, and leaves a shared object alone.
     *
     * Only S3 can share. An fs key is a filename resolved through the row's own
     * context and itemid (D9), and a restore re-keys every fs file it copies,
     * so two fs rows never name the same bytes.
     *
     * @param string $backend The row's backend.
     * @param string $key The stored key.
     * @param int $excludingrecordingid The row asking, which is not "another" row.
     * @return bool True when some other row with the same backend names $key.
     */
    public static function key_in_use_elsewhere(string $backend, string $key, int $excludingrecordingid): bool {
        return self::key_named_by_other_rows($backend, $key, 'id <> :excluding', ['excluding' => $excludingrecordingid]);
    }

    /**
     * Whether a row outside a set of recordings still names this stored object.
     *
     * For a caller deleting a batch of rows at once, where a row inside the
     * batch does not count as keeping the object alive, because it is about to
     * go as well.
     *
     * @param string $backend The rows' backend.
     * @param string $key The stored key.
     * @param int[] $excludingids Ids of the recordings being deleted together.
     * @return bool
     */
    public static function key_in_use_outside(string $backend, string $key, array $excludingids): bool {
        global $DB;

        $excludingids = array_values(array_unique(array_map('intval', $excludingids)));
        if (empty($excludingids)) {
            return self::key_named_by_other_rows($backend, $key, '1 = 1', []);
        }
        [$notin, $params] = $DB->get_in_or_equal($excludingids, SQL_PARAMS_NAMED, 'exid', false);

        return self::key_named_by_other_rows($backend, $key, "id {$notin}", $params);
    }

    /**
     * Whether a row of some other activity still names this stored object.
     *
     * @param string $backend The row's backend.
     * @param string $key The stored key.
     * @param int $presenteraiid The activity whose rows are all being deleted.
     * @return bool
     */
    private static function key_in_use_outside_instance(string $backend, string $key, int $presenteraiid): bool {
        return self::key_named_by_other_rows(
            $backend,
            $key,
            'presenteraiid <> :excludinginstance',
            ['excludinginstance' => $presenteraiid]
        );
    }

    /**
     * The one query behind the shared-key guard.
     *
     * @param string $backend The backend; anything but S3 answers false.
     * @param string $key The stored key.
     * @param string $exclusion SQL naming which rows do not count.
     * @param array $params Its named parameters.
     * @return bool
     */
    private static function key_named_by_other_rows(string $backend, string $key, string $exclusion, array $params): bool {
        global $DB;

        if ($backend !== store_factory::BACKEND_S3 || $key === '') {
            return false;
        }

        return $DB->record_exists_select(
            'presenterai_recording',
            "backend = :sharedbackend AND (storagekey = :sk1 OR deckkey = :sk2 OR frameskey = :sk3) AND {$exclusion}",
            ['sharedbackend' => $backend, 'sk1' => $key, 'sk2' => $key, 'sk3' => $key] + $params
        );
    }

    /**
     * The filename a downloaded recording is saved as.
     *
     * Built from the recording's date and attempt number rather than the key,
     * which is a random token on the File API and a path on S3, neither of
     * which a learner wants on their disk.
     *
     * @param \stdClass $rec The recording row.
     * @return string
     */
    public static function download_name(\stdClass $rec): string {
        $ext = strtolower((string) pathinfo((string) $rec->storagekey, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $name = 'presentation-' . userdate((int) $rec->timecreated, '%Y-%m-%d', 99, false)
            . '-attempt' . (int) $rec->attemptnumber . '.' . $ext;

        return clean_param($name, PARAM_FILE);
    }

    /**
     * Where download.php sends the browser for this recording.
     *
     * Factored out of download.php so the one property that matters can be
     * tested: on S3 the URL must reach the Location header byte for byte as the
     * store signed it. \core\url and moodle_url re-encode the query string, and
     * a SigV4 signature covers the encoded query, so passing the URL through
     * either breaks the signature and the learner gets S3's XML error page.
     *
     * @param \stdClass $rec The recording row, with media.
     * @return array ['kind' => 's3'|'fs', 'url' => string]
     */
    public static function download_target(\stdClass $rec): array {
        $store = store_factory::for_recording($rec);
        $name = self::download_name($rec);
        if ($store->supports_direct_upload()) {
            return [
                'kind' => store_factory::BACKEND_S3,
                'url' => $store->read_url((string) $rec->storagekey, self::DOWNLOAD_TTL, $name),
            ];
        }

        return [
            'kind' => store_factory::BACKEND_FS,
            'url' => $store->read_url((string) $rec->storagekey, 0, $name),
        ];
    }

    /**
     * Whether a row has already been through finalize, throwing if it never can be.
     *
     * @param \stdClass $rec The recording row.
     * @return bool True when finalize should return the row as it is.
     */
    private static function finalized_already(\stdClass $rec): bool {
        $status = (string) $rec->status;
        if ($status === self::STATUS_ABANDONED) {
            throw new \moodle_exception('error:notuploading', 'mod_presenterai');
        }

        return $status !== self::STATUS_UPLOADING;
    }

    /**
     * Give up on an attempt whose recording was refused at finalize.
     *
     * @param \stdClass $rec The recording row.
     * @param store_interface $store Its store.
     * @return void
     */
    private static function abandon(\stdClass $rec, store_interface $store): void {
        global $DB;

        foreach (['storagekey', 'deckkey', 'frameskey'] as $column) {
            $key = (string) ($rec->$column ?? '');
            if ($key !== '' && !self::key_in_use_elsewhere((string) $rec->backend, $key, (int) $rec->id)) {
                $store->delete($key);
            }
        }
        if (!empty($rec->uploadid)) {
            $store->abort_upload((string) $rec->uploadid);
        }
        $DB->update_record('presenterai_recording', (object) [
            'id' => $rec->id,
            'storagekey' => null,
            'deckkey' => null,
            'frameskey' => null,
            'uploadid' => null,
            'status' => self::STATUS_ABANDONED,
            'timemodified' => time(),
        ]);
    }

    /**
     * A media_ref for one of this row's objects.
     *
     * @param \stdClass $rec The recording row.
     * @param \context_module $ctx The module context.
     * @param \stdClass $course The course row.
     * @param string $kind One of the media_ref KIND_* constants.
     * @param string $key The stored key.
     * @return media_ref
     */
    private static function ref(\stdClass $rec, \context_module $ctx, \stdClass $course, string $kind, string $key): media_ref {
        $ext = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));

        return new media_ref((int) $rec->id, (int) $ctx->id, (int) $course->id, (int) $rec->userid, $kind, $ext, $key);
    }
}
