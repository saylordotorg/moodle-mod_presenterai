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
 * Where a learner's media lives, and the only way the rest of the plugin touches it.
 *
 * Two implementations: s3_store for an S3-compatible bucket, fs_store for
 * Moodle's own file storage. Nothing outside this namespace opens a file, signs
 * a URL or deletes an object. That rule is the whole point, and it is the rule
 * Soapbox did not have: three separate places there deleted recording rows and
 * only one of them also deleted the bytes, so deleting a course left every
 * learner's video in the bucket permanently.
 *
 * The two backends differ in one structural way that leaks into this interface
 * and cannot be hidden. S3 can take a browser upload directly, so the bytes
 * never touch the web server. The File API cannot, so the upload goes through
 * PHP and has to be chunked to survive upload_max_filesize, post_max_size and
 * max_execution_time on a five to seven minute video. supports_direct_upload()
 * is how a caller asks which world it is in, and the resume_offset and
 * accept_chunk methods exist only for the other one.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface store_interface {
    /**
     * Short machine name of this backend, 's3' or 'fs'.
     *
     * Stored on the recording row so a site that switches backends can still
     * read the media it already has. See section 4.7 of the plan: switching is
     * allowed and is not a migration.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Whether this backend has everything it needs to work.
     *
     * fs_store is always configured. s3_store needs a key, a secret, a bucket
     * and a region, and returns false rather than throwing so the settings page
     * and the self test can report it.
     *
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Whether the browser can upload straight to storage without passing through PHP.
     *
     * True for S3, false for the File API. A caller uses this to decide between
     * handing the browser a presigned target and driving the chunked upload.
     *
     * @return bool
     */
    public function supports_direct_upload(): bool;

    /**
     * Mint a key and return what the browser needs to start uploading.
     *
     * The returned array always carries 'key' and 'method'. For a direct-upload
     * backend it also carries 'url' and 'expires'. For a chunked backend it
     * carries 'uploadid' and 'chunkbytes', the latter negotiated down from the
     * site's real PHP limits rather than assumed.
     *
     * @param media_ref $ref The media being uploaded. Its recordingid may be 0.
     * @return array Upload instructions for the browser.
     */
    public function begin_upload(media_ref $ref): array;

    /**
     * How many bytes of a chunked upload have already landed.
     *
     * Always 0 on a direct-upload backend, which has no staging file to resume
     * into. On the File API backend this is what lets a learner whose connection
     * dropped continue rather than start again, which matters more there than it
     * would on S3 because the upload is slower and more fragile by construction.
     *
     * @param string $uploadid The id returned by begin_upload.
     * @return int Byte offset to resume from.
     */
    public function resume_offset(string $uploadid): int;

    /**
     * Append one chunk to a staging file.
     *
     * Not used by a direct-upload backend. The offset is checked against the
     * current length under a lock, so two requests for the same upload cannot
     * interleave and produce a file that is the right size and the wrong bytes.
     *
     * @param string $uploadid The id returned by begin_upload.
     * @param int $offset Byte offset this chunk claims to start at.
     * @param resource $stream Open read stream for the chunk body.
     * @return int The new total length of the staging file.
     */
    public function accept_chunk(string $uploadid, int $offset, $stream): int;

    /**
     * Finish an upload and confirm the bytes are really there.
     *
     * This is the only proof that a browser did what it said it did. On S3 it is
     * a signed HEAD; on the File API it moves the staging file into the file
     * area. Returns the size in bytes, or null if nothing arrived, which the
     * caller must treat as a failed attempt rather than an empty one.
     *
     * @param media_ref $ref The media being committed, carrying its key.
     * @param string $uploadid The chunked upload id, empty on a direct-upload backend.
     * @return int|null Size in bytes, or null if the object is absent.
     */
    public function commit_upload(media_ref $ref, string $uploadid = ''): ?int;

    /**
     * Whether this key really belongs to this learner in this course.
     *
     * Called before anything is read or deleted on behalf of a request. Soapbox
     * learned this the hard way: an earlier design accepted the visual evidence
     * as a web service parameter, which would have let any learner holding the
     * capability award themselves a score on someone else's frames.
     *
     * @param string $key The stored key.
     * @param int $courseid Expected course.
     * @param int $userid Expected owner.
     * @param int $contextid Expected module context.
     * @return bool
     */
    public function owns(string $key, int $courseid, int $userid, int $contextid): bool;

    /**
     * Size of the stored object in bytes, or null if it is gone.
     *
     * Null is an ordinary answer, not an error. Media is deleted on a retention
     * clock while the attempt row and its feedback survive, so callers must
     * expect an attempt whose bytes no longer exist.
     *
     * @param string $key The stored key.
     * @return int|null
     */
    public function size(string $key): ?int;

    /**
     * A URL the learner's browser can use to play or download the media.
     *
     * A non-empty $downloadname turns this into a download rather than inline
     * playback, and the two backends do that differently: S3 signs
     * response-content-disposition inside the canonical query string, and
     * appending it afterwards produces a URL S3 rejects outright. The File API
     * passes forcedownload through pluginfile.
     *
     * @param string $key The stored key.
     * @param int $ttl Seconds the URL should remain valid, where the backend can control that.
     * @param string $downloadname Filename to save as, or empty for inline playback.
     * @return string
     */
    public function read_url(string $key, int $ttl, string $downloadname = ''): string;

    /**
     * Pull the object onto local disk and return the path.
     *
     * Used by transcription and by the deck renderer, which need a real file.
     * On S3 this is a genuine download, so a large video is pulled to the cron
     * box on every scoring run. On the File API with the stock file system it is
     * free, and the path it returns points at Moodle's own stored file, so a
     * caller must never write to it.
     *
     * @param string $key The stored key.
     * @param string $ext Extension for the temporary file, without the dot.
     * @return string|null Absolute path, or null if the object is gone.
     */
    public function fetch_to_file(string $key, string $ext): ?string;

    /**
     * Read the object into memory, refusing anything larger than $maxbytes.
     *
     * The ceiling is checked before the read starts, not after. Reading first
     * and measuring afterwards is how a learner-supplied object can exhaust
     * cron's memory limit, and a memory_limit breach is a fatal that no catch
     * block intercepts.
     *
     * @param string $key The stored key.
     * @param int $maxbytes Hard ceiling.
     * @return string|null The bytes, or null if absent, too large, or unreadable.
     */
    public function read_bytes(string $key, int $maxbytes): ?string;

    /**
     * Delete the object.
     *
     * Best effort: a failure is reported, never thrown. An object that cannot be
     * deleted must not stop the deletion an administrator asked for, and it must
     * not stop the row being removed either, because a row deleted without its
     * object is at least visible in a bucket listing whereas the reverse is not.
     *
     * @param string $key The stored key.
     * @return bool True if the object is gone or was already gone.
     */
    public function delete(string $key): bool;

    /**
     * Prove the backend actually works, from this server, right now.
     *
     * A round trip rather than a configuration check: write, read back, compare
     * bytes, delete. S3 additionally checks a CORS preflight, because a browser
     * upload fails on CORS long before anything reaches PHP, and checks that an
     * unsigned GET is refused. The File API arm measures the site's real PHP
     * limits inside an HTTP request, because that is the number that decides
     * whether a seven minute video can be uploaded at all.
     *
     * @return array Ordered list of ['step' => string, 'ok' => bool, 'detail' => string].
     */
    public function selftest(): array;
}
