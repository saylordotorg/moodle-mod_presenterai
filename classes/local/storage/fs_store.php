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

namespace mod_presenterai\local\storage;

/**
 * Storage backed by Moodle's own File API, the default for a fresh install.
 *
 * This backend has to work on a site where nobody configured anything, which is
 * the whole reason it is the default (plan section 4.8). That constraint drives
 * every hard decision in here.
 *
 * The browser cannot write to the File API, so the bytes come through PHP, and a
 * five to seven minute video is far larger than a stock php.ini will accept in
 * one request. So the upload is chunked: begin_upload() mints an id and tells
 * the client how large a chunk this site can really take, accept_chunk() appends
 * under a lock, and commit_upload() moves the finished staging file into the
 * file area. Nothing lands in the file area until it is complete, because a
 * partial stored_file is indistinguishable from a whole one.
 *
 * Keys here are the filename alone, as plan section 3.3 settles: a course
 * restore gives the module a new context id and a new instance id, so anything
 * else in the key would be a second source of truth that can disagree with the
 * row holding it. The other five File API coordinates are derived from the
 * recording row at read time.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fs_store implements store_interface {
    /** @var string Backend name, written to presenterai_recording.backend. */
    public const NAME = 'fs';

    /** @var string The component owning every file area this store writes. */
    private const COMPONENT = 'mod_presenterai';

    /** @var string Directory under $CFG->tempdir holding in-progress uploads. */
    private const STAGING_DIR = 'presenterai';

    /** @var string Suffix on a staging file, so a half-written upload is obvious on disk. */
    private const STAGING_SUFFIX = '.part';

    /** @var int Characters in an upload id and in a minted key token. */
    private const TOKEN_LENGTH = 32;

    /** @var int Smallest chunk worth offering. Below this the request count dominates the transfer. */
    private const CHUNK_MIN = 65536;

    /** @var int Chunk offered unless a site has probed for something larger. See chunk_bytes(). */
    private const CHUNK_DEFAULT = 524288;

    /** @var int Largest chunk offered however generous the PHP limits are. See chunk_bytes(). */
    private const CHUNK_MAX = 5242880;

    /** @var int Chunk sizes are rounded down to a multiple of this, so the client sees round numbers. */
    private const CHUNK_GRAIN = 65536;

    /** @var int Bytes read from the client stream at a time while appending. */
    private const COPY_BUFFER = 8192;

    /**
     * Default ceiling for one piece of media, 150 MB.
     *
     * Roughly a seven minute 720p recording at the shipped quality preset with
     * room to spare. An administrator can raise or lower it; this is only what a
     * site that never touches the setting gets.
     */
    private const DEFAULT_MAX_MEDIA_BYTES = 157286400;

    /** @var string Lock factory namespace for per-upload locks. */
    private const LOCK_TYPE = 'mod_presenterai_upload';

    /** @var int Seconds a request waits for another request's chunk to finish. */
    private const LOCK_TIMEOUT = 20;

    /** @var int Seconds after which an abandoned lock is assumed dead. */
    private const LOCK_LIFETIME = 600;

    /**
     * Short machine name of this backend.
     *
     * @return string
     */
    public function name(): string {
        return self::NAME;
    }

    /**
     * Whether this backend has everything it needs to work.
     *
     * Always true. The File API is part of Moodle, so there is no credential to
     * be missing. Whether the site's PHP limits allow a usable chunk size is a
     * separate question, and selftest() is where it is answered.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return true;
    }

    /**
     * Whether the browser can upload straight to storage without passing through PHP.
     *
     * @return bool
     */
    public function supports_direct_upload(): bool {
        return false;
    }

    /**
     * Mint a key and return what the browser needs to start uploading.
     *
     * Creates the staging file immediately, empty. That turns an unwritable
     * $CFG->tempdir into a failure before the learner records anything, rather
     * than into a failure after they have spoken for seven minutes.
     *
     * @param media_ref $ref The media being uploaded. Its recordingid may be 0.
     * @return array Upload instructions for the browser.
     */
    public function begin_upload(media_ref $ref): array {
        if (!$ref->has_valid_kind()) {
            throw new \coding_exception('Unknown media kind: ' . $ref->kind);
        }

        $uploadid = random_string(self::TOKEN_LENGTH);
        $path = $this->staging_path($uploadid);
        if (file_put_contents($path, '') === false) {
            throw new \moodle_exception('error:stagingunwritable', 'mod_presenterai');
        }

        return [
            'key' => $this->mint_key($ref->ext),
            'method' => 'POST',
            // Not a presigned target and not time limited: every chunk is an
            // ordinary authenticated Moodle request, so there is no 'expires'
            // to report. upload.php arrives with the web service layer.
            'url' => (new \core\url('/mod/presenterai/upload.php'))->out(false),
            'uploadid' => $uploadid,
            'chunkbytes' => $this->chunk_bytes(),
        ];
    }

    /**
     * How many bytes of a chunked upload have already landed.
     *
     * Zero covers three different situations on purpose: an id that was never
     * minted, an upload whose staging file has been cleaned up, and an upload
     * that has genuinely received nothing. A client that resumes from 0 in any
     * of them is correct, and commit_upload() is what distinguishes them.
     *
     * @param string $uploadid The id returned by begin_upload.
     * @return int Byte offset to resume from.
     */
    public function resume_offset(string $uploadid): int {
        if (!$this->is_valid_uploadid($uploadid)) {
            return 0;
        }

        $path = $this->staging_path($uploadid, false);
        clearstatcache(true, $path);
        if (!is_readable($path)) {
            return 0;
        }
        $size = filesize($path);

        return $size === false ? 0 : (int) $size;
    }

    /**
     * Append one chunk to a staging file.
     *
     * Held under a Moodle lock rather than flock. flock is advisory and is tied
     * to one open handle on one machine, and $CFG->tempdir is required to be
     * shared storage across cluster nodes (lib/setuplib.php:1536 make_temp_directory,
     * whose docblock says exactly that), which in practice means NFS, where
     * flock is either unsupported or silently degraded. Moodle's lock API
     * resolves to a database backed factory on MySQL and PostgreSQL, and every
     * web node shares the one database, so there the lock genuinely holds across
     * the cluster.
     *
     * It does NOT hold everywhere, and the earlier version of this comment said
     * it did. lock_config::get_lock_factory_class() builds
     * \core\lock\{dbfamily}_lock_factory and falls back to file_lock_factory
     * when that class is absent, and only mysql_lock_factory and
     * postgres_lock_factory exist in lib/classes/lock/. On SQL Server, which
     * Moodle 4.5 supports, the fallback locks with flock() on a dataroot
     * directory, which is the very mechanism the paragraph above rejects. So on
     * a clustered SQL Server site this lock is advisory at best. The offset
     * check below still refuses a mismatched chunk, so the failure mode is a
     * rejected upload rather than a corrupted one, which is the right direction
     * to fail in.
     *
     * The offset check inside the lock is what the lock is for. Two requests
     * both appending 512 KiB to a 1 MiB file produce a 2 MiB file whose second
     * and third megabytes are in whichever order the kernel chose, which is a
     * file of exactly the right length and the wrong bytes, and nothing
     * downstream can detect that.
     *
     * @param string $uploadid The id returned by begin_upload.
     * @param int $offset Byte offset this chunk claims to start at.
     * @param resource $stream Open read stream for the chunk body.
     * @return int The new total length of the staging file.
     */
    public function accept_chunk(string $uploadid, int $offset, $stream): int {
        if (!$this->is_valid_uploadid($uploadid)) {
            throw new \moodle_exception('error:uploadid', 'mod_presenterai');
        }
        if (!is_resource($stream)) {
            throw new \coding_exception('accept_chunk() needs an open read stream.');
        }
        if ($offset < 0) {
            throw new \coding_exception('accept_chunk() offset cannot be negative.');
        }

        $path = $this->staging_path($uploadid);
        $ceiling = $this->max_media_bytes();
        $lock = $this->acquire_lock($uploadid);

        try {
            clearstatcache(true, $path);
            $current = file_exists($path) ? (int) filesize($path) : 0;
            if ($offset !== $current) {
                // The true offset travels in the exception so the caller can
                // answer 409 with it, which is also how a client resumes after a
                // dropped connection.
                throw new \moodle_exception(
                    'error:chunkoffset',
                    'mod_presenterai',
                    '',
                    (object) ['claimed' => $offset, 'actual' => $current]
                );
            }

            $out = fopen($path, 'ab');
            if ($out === false) {
                throw new \moodle_exception('error:stagingunwritable', 'mod_presenterai');
            }

            $written = 0;
            try {
                while (true) {
                    $buffer = fread($stream, self::COPY_BUFFER);
                    if ($buffer === false) {
                        throw new \moodle_exception('error:chunkread', 'mod_presenterai');
                    }
                    if ($buffer === '') {
                        // An empty read is the end of the stream, or a blocking
                        // stream with nothing ready yet. Only feof() tells them
                        // apart, and treating the second as the first would
                        // truncate the chunk silently.
                        if (feof($stream)) {
                            break;
                        }
                        continue;
                    }
                    // PHP's fwrite() returns a SHORT COUNT, not false, when it cannot
                    // write the whole buffer: a quota boundary, a full disk, an
                    // NFS hiccup. Adding that short count to $written and then
                    // reading the next block discards the unwritten tail, and
                    // nothing downstream can detect it. The file ends up the
                    // length the client's arithmetic expects and corrupt in the
                    // middle, which is the same "right size, wrong bytes"
                    // outcome the lock above exists to prevent, reached through
                    // a different door. So write the remainder until it is gone.
                    $bufoffset = 0;
                    $buflen = strlen($buffer);
                    while ($bufoffset < $buflen) {
                        $bytes = fwrite($out, substr($buffer, $bufoffset));
                        // Zero on a retry means no forward progress, which is a
                        // full filesystem rather than a slow one.
                        if ($bytes === false || $bytes === 0) {
                            throw new \moodle_exception('error:stagingunwritable', 'mod_presenterai');
                        }
                        $bufoffset += $bytes;
                        $written += $bytes;

                        // F3: bound the staging file here, where the bytes are
                        // actually written. A learner holding a valid uploadid
                        // could otherwise POST without limit into $CFG->tempdir,
                        // which is under dataroot and is shared storage on a
                        // cluster. Enforcing this in the one caller would be a
                        // ceiling the second caller forgets, and the store owns
                        // the bytes.
                        if (($current + $written) > $ceiling) {
                            throw new \moodle_exception(
                                'error:uploadtoolarge',
                                'mod_presenterai',
                                '',
                                display_size($ceiling)
                            );
                        }
                    }
                }
                fflush($out);
            } finally {
                fclose($out);
            }

            clearstatcache(true, $path);
            $size = filesize($path);

            return $size === false ? $current + $written : (int) $size;
        } finally {
            $lock->release();
        }
    }

    /**
     * Finish an upload and confirm the bytes are really there.
     *
     * Takes the same lock accept_chunk() takes, so a late chunk from a retrying
     * client cannot extend the file between the size being read and the file
     * being copied in.
     *
     * Returns the size the File API reports after the copy, not the size of the
     * staging file, because the stored file is what every later read sees.
     *
     * @param media_ref $ref The media being committed, carrying its key.
     * @param string $uploadid The chunked upload id, empty on a direct-upload backend.
     * @return int|null Size in bytes, or null if the object is absent.
     */
    public function commit_upload(media_ref $ref, string $uploadid = ''): ?int {
        if (!$ref->has_valid_kind()) {
            throw new \coding_exception('Unknown media kind: ' . $ref->kind);
        }
        if ($ref->recordingid <= 0) {
            throw new \coding_exception('commit_upload() needs the recording id, which is the File API itemid.');
        }
        if ($ref->key === '') {
            throw new \coding_exception('commit_upload() needs the key minted by begin_upload().');
        }

        $fs = get_file_storage();
        $area = $this->area_for_kind($ref->kind);

        if (!$this->is_valid_uploadid($uploadid)) {
            return null;
        }

        $path = $this->staging_path($uploadid, false);

        // The lock comes FIRST, before the existence check and before any
        // unlink. Two things went wrong when it did not.
        //
        // discard_staging() unlinking outside the lock could remove a path a
        // concurrent accept_chunk() was appending to inside it. On POSIX the
        // appender's descriptor survives the unlink, so its writes land in an
        // unlinked inode and vanish, and its filesize() then reports a length no
        // file on disk has. The lock protected nothing, because the other party
        // never took it.
        //
        // And checking get_file() before the lock made a duplicate commit report
        // FAILURE for work that succeeded: B sees no stored file, blocks on the
        // lock, A creates the file and unlinks the staging path, B wakes to a
        // missing staging file and returns null. Per store_interface a null
        // commit is a failed attempt, so the caller would tear down a row whose
        // bytes are safely stored, and F1 then makes that file unreachable
        // forever. That is exactly the retry-after-timeout case this code was
        // written to handle.
        $lock = $this->acquire_lock($uploadid);

        try {
            $existing = $fs->get_file($ref->contextid, self::COMPONENT, $area, $ref->recordingid, '/', $ref->key);
            if ($existing) {
                $this->discard_staging($uploadid);
                $size = (int) $existing->get_filesize();
                return $size > 0 ? $size : null;
            }

            clearstatcache(true, $path);
            if (!is_readable($path)) {
                return null;
            }
            $size = (int) filesize($path);
            if ($size <= 0) {
                // Nothing arrived. A failed attempt, not an empty one, so the
                // staging file goes rather than becoming a zero byte recording.
                @unlink($path);
                return null;
            }

            // The File API does not scan on the way in, so a site with an
            // antivirus plugin enabled gets no protection unless a plugin asks
            // for it. Throws \core\antivirus\scanner_exception on an infected
            // file and deletes the staging file itself
            // (lib/classes/antivirus/manager.php:70-130).
            \core\antivirus\manager::scan_file($path, $ref->key, true);

            $filerecord = (object) [
                'contextid' => $ref->contextid,
                'component' => self::COMPONENT,
                'filearea' => $area,
                'itemid' => $ref->recordingid,
                'filepath' => '/',
                'filename' => $ref->key,
                'userid' => $ref->userid,
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            // Copies rather than moves (lib/filestorage/file_storage.php:1276),
            // so the staging file is ours to remove afterwards.
            $stored = $fs->create_file_from_pathname($filerecord, $path);
            @unlink($path);

            return (int) $stored->get_filesize();
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether this key really belongs to this learner in this course.
     *
     * There is no prefix to compare here, unlike S3: the key is a bare filename
     * by design, so ownership can only come from the row that points at it. The
     * context is resolved from the course module rather than trusted from the
     * caller, which is the point of the check.
     *
     * @param string $key The stored key.
     * @param int $courseid Expected course.
     * @param int $userid Expected owner.
     * @param int $contextid Expected module context.
     * @return bool
     */
    public function owns(string $key, int $courseid, int $userid, int $contextid): bool {
        global $DB;

        if ($key === '' || $courseid <= 0 || $userid <= 0 || $contextid <= 0) {
            return false;
        }

        $sql = "SELECT r.id, r.presenteraiid
                  FROM {presenterai_recording} r
                  JOIN {presenterai} p ON p.id = r.presenteraiid
                 WHERE (r.storagekey = :k1 OR r.deckkey = :k2 OR r.frameskey = :k3)
                       AND r.userid = :userid
                       AND p.course = :courseid";
        $rows = $DB->get_records_sql($sql, [
            'k1' => $key,
            'k2' => $key,
            'k3' => $key,
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        foreach ($rows as $row) {
            $context = $this->module_context((int) $row->presenteraiid, $courseid);
            if ($context !== null && $context->id === $contextid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Size of the stored object in bytes, or null if it is gone.
     *
     * @param string $key The stored key.
     * @return int|null
     */
    public function size(string $key): ?int {
        $file = $this->locate($key);
        if ($file === null) {
            return null;
        }

        return (int) $file->get_filesize();
    }

    /**
     * A URL the learner's browser can use to play or download the media.
     *
     * $ttl is accepted and not used. A pluginfile URL is not a bearer token: it
     * carries no signature and no expiry, and every request through it is
     * re-authorised by mod_presenterai_pluginfile(). That is the asymmetry plan
     * section 4.6 records, and expressing a TTL here would misrepresent it.
     *
     * The download name cannot go in the path, because the path segment is the
     * key pluginfile.php looks the file up by. It travels as a 'dl' parameter
     * that the pluginfile handler cleans with PARAM_FILE and passes to
     * send_stored_file() as options['filename'] (lib/filelib.php:2694-2696).
     *
     * @param string $key The stored key.
     * @param int $ttl Seconds the URL should remain valid, where the backend can control that.
     * @param string $downloadname Filename to save as, or empty for inline playback.
     * @return string
     */
    public function read_url(string $key, int $ttl, string $downloadname = ''): string {
        $file = $this->locate($key);
        if ($file === null) {
            return '';
        }

        $forcedownload = $downloadname !== '';
        $url = \core\url::make_pluginfile_url(
            $file->get_contextid(),
            self::COMPONENT,
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            $forcedownload
        );

        if ($forcedownload) {
            $name = clean_param($downloadname, PARAM_FILE);
            if ($name !== '') {
                $url->param('dl', $name);
            }
        }

        return $url->out(false);
    }

    /**
     * Pull the object onto local disk and return the path.
     *
     * With the stock file system the bytes are already on local disk, so this
     * returns the filedir path and copies nothing. THE CALLER MUST NOT WRITE TO
     * THE RETURNED PATH: filedir is content addressed and deduplicated, so that
     * path may be the content of other files in other courses as well.
     *
     * $ext therefore only shapes the copy, which happens when an alternative
     * file system (tool_objectfs and friends) has the content remote. A caller
     * that needs a particular filename, rather than a particular path, should
     * set it where it sends the file: the filedir path has no extension at all.
     *
     * @param string $key The stored key.
     * @param string $ext Extension for the temporary file, without the dot.
     * @return string|null Absolute path, or null if the object is gone.
     */
    public function fetch_to_file(string $key, string $ext): ?string {
        $file = $this->locate($key);
        if ($file === null) {
            return null;
        }

        // False: ask whether it is already here, do not trigger a fetch, because
        // the answer decides whether a fetch is needed at all
        // (lib/filestorage/file_system.php:119-131).
        $system = get_file_storage()->get_file_system();
        if ($system->is_file_readable_locally_by_storedfile($file, false)) {
            return $system->get_local_path_from_storedfile($file, false);
        }

        // A request directory is removed when the request ends, so a scoring run
        // that dies part way through leaves nothing behind (lib/setuplib.php:1479).
        $path = make_request_directory() . '/media.' . $this->clean_ext($ext);
        if (!$file->copy_content_to($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Read the object into memory, refusing anything larger than $maxbytes.
     *
     * The stored size is authoritative here and is known before a byte is read,
     * which is the whole advantage this backend has over a remote GET.
     *
     * @param string $key The stored key.
     * @param int $maxbytes Hard ceiling.
     * @return string|null The bytes, or null if absent, too large, or unreadable.
     */
    public function read_bytes(string $key, int $maxbytes): ?string {
        if ($maxbytes <= 0) {
            return null;
        }

        $file = $this->locate($key);
        if ($file === null) {
            return null;
        }

        $size = (int) $file->get_filesize();
        if ($size <= 0 || $size > $maxbytes) {
            return null;
        }

        try {
            $content = $file->get_content();
        } catch (\Throwable $e) {
            debugging('mod_presenterai fs_store could not read ' . $key . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        return is_string($content) ? $content : null;
    }

    /**
     * Delete the object.
     *
     * Absent counts as deleted, so a retention run that already removed the
     * bytes and then failed to clear the row succeeds on its second pass.
     *
     * Deletion here is not instant on disk: the row goes at once, the content
     * moves to trash only when no other file shares its hash, and the trash is
     * emptied by core\task\file_trash_cleanup_task
     * (lib/filestorage/file_system_filedir.php:287-296). Unreachable within
     * seconds, gone from disk within about a day.
     *
     * @param string $key The stored key.
     * @return bool True if the object is gone or was already gone.
     */
    public function delete(string $key): bool {
        if ($key === '') {
            // Nothing was ever stored, so nothing is left behind.
            return true;
        }

        try {
            // A null from locate() covers two situations that must not get the
            // same answer, so they are separated here.
            //
            // A row still references this key and the stored file is already
            // gone: the media is genuinely deleted, so deleting again succeeds.
            // A retention pass that removed bytes and then failed to clear the
            // row has to get past its second attempt.
            //
            // NO row references this key: the key cannot be resolved at all. An
            // fs key is a bare filename carrying no context, area or itemid, so
            // without a row there is no way left to name the file. It would sit
            // in the file area in no listing, not in the trash, unreachable by
            // pluginfile, removed by nothing short of deleting the whole
            // context. Reporting success there would be the Soapbox failure this
            // namespace exists to prevent, arriving from the other direction,
            // and store_interface's consolation does not apply: an orphan here
            // is visible nowhere, where an S3 orphan at least shows in a bucket
            // listing. So callers must delete media BEFORE clearing the row.
            $file = $this->locate($key);
            if ($file === null) {
                if ($this->key_is_referenced($key)) {
                    return true;
                }
                debugging(
                    'mod_presenterai fs_store cannot resolve key ' . $key
                        . ', so the stored file cannot be deleted and can no longer be named. '
                        . 'Delete media before clearing the recording row.',
                    DEBUG_DEVELOPER
                );
                return false;
            }
            $file->delete();
        } catch (\Throwable $e) {
            debugging('mod_presenterai fs_store could not delete ' . $key . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }

        return true;
    }

    /**
     * Prove the backend actually works, from this server, right now.
     *
     * The limits arm is the one that matters. It reports what this SAPI sees,
     * and CLI is not what a learner uploads through, so a run from the command
     * line says so rather than quietly reporting numbers nobody will meet.
     *
     * @return array Ordered list of ['step' => string, 'ok' => bool, 'detail' => string].
     */
    public function selftest(): array {
        global $CFG;

        $steps = [];

        // 1. Staging directory.
        try {
            $uploadid = random_string(self::TOKEN_LENGTH);
            $path = $this->staging_path($uploadid);
            $written = file_put_contents($path, 'presenterai') !== false;
            $readback = $written && file_get_contents($path) === 'presenterai';
            @unlink($path);
            $steps[] = [
                'step' => 'staging',
                'ok' => $readback,
                'detail' => get_string('selftest:staging', 'mod_presenterai', $CFG->tempdir . '/' . self::STAGING_DIR),
            ];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'staging', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 2. Locking, which is what makes concurrent chunks safe.
        try {
            $lock = $this->acquire_lock(random_string(self::TOKEN_LENGTH));
            $lock->release();
            $steps[] = [
                'step' => 'lock',
                'ok' => true,
                'detail' => get_string('selftest:lock', 'mod_presenterai', \core\lock\lock_config::get_lock_factory_class()),
            ];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'lock', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 3. File area round trip, in the system context so nothing is written
        // into a course that an administrator would then have to explain.
        try {
            $fs = get_file_storage();
            $context = \context_system::instance();
            $filename = 'selftest-' . random_string(self::TOKEN_LENGTH) . '.bin';
            $payload = random_string(64);
            $stored = $fs->create_file_from_string((object) [
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'filearea' => 'selftest',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], $payload);
            $matched = $stored->get_content() === $payload && (int) $stored->get_filesize() === strlen($payload);
            $stored->delete();
            $gone = $fs->get_file($context->id, self::COMPONENT, 'selftest', 0, '/', $filename) === false;
            $steps[] = [
                'step' => 'roundtrip',
                'ok' => $matched && $gone,
                'detail' => get_string('selftest:roundtrip', 'mod_presenterai'),
            ];
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'roundtrip', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // 4. The PHP limits, and the chunk size they permit.
        $chunk = $this->chunk_bytes();
        $steps[] = [
            'step' => 'phplimits',
            'ok' => $chunk >= self::CHUNK_MIN,
            'detail' => get_string('selftest:phplimits', 'mod_presenterai', (object) [
                'sapi' => PHP_SAPI,
                'uploadmax' => (string) ini_get('upload_max_filesize'),
                'postmax' => (string) ini_get('post_max_size'),
                'memory' => (string) ini_get('memory_limit'),
                'inputtime' => (string) ini_get('max_input_time'),
                'exectime' => (string) ini_get('max_execution_time'),
                'chunk' => display_size($chunk),
            ]),
        ];

        // 5. Byteserving, which is whether the learner can seek in their own video.
        $byteserving = empty($CFG->disablebyteserving);
        $steps[] = [
            'step' => 'byteserving',
            'ok' => $byteserving,
            'detail' => $byteserving
                ? get_string('selftest:byteservingon', 'mod_presenterai', empty($CFG->xsendfile) ? '-' : $CFG->xsendfile)
                : get_string('selftest:byteservingoff', 'mod_presenterai'),
        ];

        return $steps;
    }

    /**
     * The chunk size to offer this client, in bytes.
     *
     * Three ini settings are consulted and the smallest wins. post_max_size
     * binds a raw php://input body; upload_max_filesize binds a multipart part,
     * which is what the same endpoint receives if a client ever posts a form
     * rather than a raw body; memory_limit does not bind the append loop, which
     * reads in fixed 8 KiB pieces, but it does bind whatever buffering sits in
     * front of it. The store cannot see which of those a given request will take,
     * so it takes the smallest. The only cost of being wrong in this direction
     * is more requests.
     *
     * A share of that budget is left free rather than all of it being spent. A
     * chunk sized at exactly the limit fails: the body carries the chunk plus
     * whatever else the request encoding adds, and post_max_size is measured
     * against the whole of it.
     *
     * The result is then capped at CHUNK_DEFAULT unless a site has probed and
     * cached something larger, because the binding limit on a stock install is
     * usually not PHP at all. nginx's client_max_body_size defaults to 1m and
     * returns 413 before PHP is reached, and no ini setting reveals it. The
     * probe described in plan section 4.4 is what raises this, by writing the
     * largest rung that actually returned 200 to the fschunkbytes setting.
     *
     * @return int
     */
    private function chunk_bytes(): int {
        $budget = 0;
        foreach (['upload_max_filesize', 'post_max_size', 'memory_limit'] as $setting) {
            $bytes = $this->limit_bytes($setting);
            if ($bytes > 0 && ($budget === 0 || $bytes < $budget)) {
                $budget = $bytes;
            }
        }

        if ($budget === 0) {
            // Every limit is unlimited or unreadable, so PHP is not the
            // constraint and only the site ceiling applies.
            $ceiling = self::CHUNK_MAX;
        } else {
            // Three quarters, so a chunk never sits at the limit itself.
            $ceiling = (int) floor($budget * 3 / 4);
        }

        $probed = (int) get_config(self::COMPONENT, 'fschunkbytes');
        $chunk = min($ceiling, $probed > 0 ? $probed : self::CHUNK_DEFAULT, self::CHUNK_MAX);
        $chunk = (int) (floor($chunk / self::CHUNK_GRAIN) * self::CHUNK_GRAIN);

        return max(self::CHUNK_MIN, $chunk);
    }

    /**
     * One ini size setting in bytes, or 0 when it is unset or unlimited.
     *
     * get_real_size() returns -1 unchanged for an unlimited memory_limit
     * (lib/setuplib.php:1164-1187), and a negative number would win a min()
     * comparison, so it is normalised to 0 for "does not constrain" here.
     *
     * @param string $setting The ini setting name.
     * @return int Bytes, or 0 for no constraint.
     */
    private function limit_bytes(string $setting): int {
        $raw = ini_get($setting);
        if ($raw === false || trim((string) $raw) === '') {
            return 0;
        }
        $bytes = get_real_size(trim((string) $raw));

        return $bytes > 0 ? (int) $bytes : 0;
    }

    /**
     * Whether any recording row still names this key.
     *
     * The difference between "the media is already deleted", which is a success,
     * and "this key cannot be resolved", which is not. Kept separate from
     * locate() because locate() answers a different question, whether the bytes
     * are reachable right now, and folding the two together is what made
     * delete() report success for an unreachable orphan.
     *
     * @param string $key The stored key.
     * @return bool True when a recording row still references it.
     */
    private function key_is_referenced(string $key): bool {
        global $DB;

        if ($key === '') {
            return false;
        }

        return $DB->record_exists_select(
            'presenterai_recording',
            'storagekey = :k1 OR deckkey = :k2 OR frameskey = :k3',
            ['k1' => $key, 'k2' => $key, 'k3' => $key]
        );
    }

    /**
     * The largest a single piece of media may be, in bytes.
     *
     * The smaller of the site's own limit and Moodle's effective maximum upload
     * size. Honouring get_max_upload_file_size() is a condition of passing
     * Moodle plugin review, and it is also the number an administrator believes
     * they set, so ignoring it would make the site setting a lie.
     *
     * Read on every chunk rather than cached, because an upload spans many
     * requests and an administrator may lower the limit between two of them.
     * The cost is one get_config() against a cache.
     *
     * @return int Ceiling in bytes.
     */
    private function max_media_bytes(): int {
        global $CFG;

        $site = (int) get_config('mod_presenterai', 'maxmediabytes');
        if ($site <= 0) {
            $site = self::DEFAULT_MAX_MEDIA_BYTES;
        }

        // Moodle's get_max_upload_file_size() returns -1 for unlimited, which must not
        // win a min().
        $moodlemax = (int) get_max_upload_file_size($CFG->maxbytes);
        if ($moodlemax <= 0) {
            return $site;
        }

        return min($site, $moodlemax);
    }

    /**
     * Absolute path of the staging file for one upload.
     *
     * Staging lives under $CFG->tempdir, not in a request directory, because a
     * chunked upload spans many requests and, on a cluster, many web nodes.
     * $CFG->tempdir is under $CFG->dataroot and is required to be shared storage
     * (lib/setuplib.php:1536-1545), so chunk 40 finds what chunk 39 wrote even
     * when the load balancer sent them to different machines.
     *
     * @param string $uploadid A validated upload id.
     * @param bool $createdir Whether to create the staging directory if it is missing.
     * @return string
     */
    private function staging_path(string $uploadid, bool $createdir = true): string {
        global $CFG;

        if (!$this->is_valid_uploadid($uploadid)) {
            // Reached only through a bug: every public entry point validates
            // first. The id becomes a path segment, so this is not a place to
            // be lenient.
            throw new \coding_exception('Invalid upload id reached staging_path().');
        }

        $dir = $createdir
            ? make_temp_directory(self::STAGING_DIR)
            : $CFG->tempdir . '/' . self::STAGING_DIR;

        return $dir . '/' . $uploadid . self::STAGING_SUFFIX;
    }

    /**
     * Remove a staging file, ignoring one that is not there.
     *
     * @param string $uploadid The upload id, which may be empty or invalid.
     * @return void
     */
    private function discard_staging(string $uploadid): void {
        if (!$this->is_valid_uploadid($uploadid)) {
            return;
        }
        $path = $this->staging_path($uploadid, false);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Whether an upload id is one this store could have minted.
     *
     * Client supplied, and it becomes a filename, so the shape is checked rather
     * than the string being escaped: an id is 32 alphanumerics from
     * random_string() (lib/moodlelib.php:7913) or it is not ours.
     *
     * @param string $uploadid The candidate id.
     * @return bool
     */
    private function is_valid_uploadid(string $uploadid): bool {
        return (bool) preg_match('/^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}$/', $uploadid);
    }

    /**
     * Take the lock that serialises work on one upload.
     *
     * @param string $uploadid A validated upload id.
     * @return \core\lock\lock
     */
    private function acquire_lock(string $uploadid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock($uploadid, self::LOCK_TIMEOUT, self::LOCK_LIFETIME);
        if ($lock === false) {
            throw new \moodle_exception('error:uploadbusy', 'mod_presenterai');
        }

        return $lock;
    }

    /**
     * Mint a key: a filename, and nothing else.
     *
     * @param string $ext File extension without the dot.
     * @return string
     */
    private function mint_key(string $ext): string {
        return random_string(self::TOKEN_LENGTH) . '.' . $this->clean_ext($ext);
    }

    /**
     * An extension reduced to something safe to put in a filename.
     *
     * @param string $ext Candidate extension without the dot.
     * @return string
     */
    private function clean_ext(string $ext): string {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower($ext));

        return ($clean === null || $clean === '') ? 'bin' : $clean;
    }

    /**
     * The file area for one media kind.
     *
     * The areas are named for the kinds, and this method exists so that stays a
     * decision rather than an accident: backup, restore and the privacy provider
     * all enumerate area names, and a kind arriving from anywhere else must not
     * be able to name an area.
     *
     * @param string $kind One of the media_ref KIND_* constants.
     * @return string
     */
    private function area_for_kind(string $kind): string {
        switch ($kind) {
            case media_ref::KIND_RECORDING:
                return 'recording';
            case media_ref::KIND_DECK:
                return 'deck';
            case media_ref::KIND_FRAMES:
                return 'frames';
            default:
                throw new \coding_exception('Unknown media kind: ' . $kind);
        }
    }

    /**
     * The stored file a key names, or null if there is no longer one.
     *
     * The key is a bare filename, so the other five File API coordinates come
     * from the recording row that holds it. A key that no row points at is
     * unreachable by design: that is what makes clearing the column equivalent
     * to the media being gone.
     *
     * @param string $key The stored key.
     * @return \stored_file|null
     */
    private function locate(string $key): ?\stored_file {
        global $DB;

        if ($key === '') {
            return null;
        }

        $sql = "SELECT r.id, r.presenteraiid, r.storagekey, r.deckkey, r.frameskey, p.course
                  FROM {presenterai_recording} r
                  JOIN {presenterai} p ON p.id = r.presenteraiid
                 WHERE r.storagekey = :k1 OR r.deckkey = :k2 OR r.frameskey = :k3";
        $rows = $DB->get_records_sql($sql, ['k1' => $key, 'k2' => $key, 'k3' => $key]);

        $fs = get_file_storage();
        foreach ($rows as $row) {
            $context = $this->module_context((int) $row->presenteraiid, (int) $row->course);
            if ($context === null) {
                continue;
            }
            if ((string) $row->storagekey === $key) {
                $area = 'recording';
            } else if ((string) $row->deckkey === $key) {
                $area = 'deck';
            } else {
                $area = 'frames';
            }
            $file = $fs->get_file($context->id, self::COMPONENT, $area, (int) $row->id, '/', $key);
            if ($file && !$file->is_directory()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The module context for one activity instance, or null if there is no course module.
     *
     * Null is reachable during the window between the instance row existing and
     * the course module existing, which is where mod_form leaves it, and during
     * a course delete.
     *
     * @param int $presenteraiid The activity instance id.
     * @param int $courseid The course the instance belongs to.
     * @return \context_module|null
     */
    private function module_context(int $presenteraiid, int $courseid): ?\context_module {
        $cm = get_coursemodule_from_instance('presenterai', $presenteraiid, $courseid, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }

        return \context_module::instance($cm->id, IGNORE_MISSING) ?: null;
    }
}
