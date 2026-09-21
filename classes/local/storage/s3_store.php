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
 * The S3-compatible backend: the browser uploads straight to the bucket and the
 * bytes never pass through PHP.
 *
 * This is a port of local_ai_course_assistant\soapbox_storage, which has signed
 * correctly in production since 2026. presign_url() below is that code, kept
 * byte-identical in behaviour rather than tidied, because the only evidence a
 * signer works is that it reproduces AWS's published worked example, and a
 * rearrangement that changes one byte of the canonical request changes the
 * signature and breaks everything with an error message that says only
 * SignatureDoesNotMatch.
 *
 * Two things the source did not have are added here.
 *
 * The first is an endpoint override plus a path-style flag. The source hard
 * codes {bucket}.s3.{region}.amazonaws.com, so its "S3 compatible" was
 * aspirational: nothing but AWS could ever have been addressed. A MinIO server
 * in CI is what turns store_interface from a hopeful abstraction into a tested
 * one, and MinIO is addressed path-style on a host with a port. AWS stays the
 * default and its URLs are unchanged.
 *
 * The second is an unsigned GET inside selftest(). A bucket that answers an
 * unsigned GET is a bucket where every learner's video is public, and that is a
 * configuration mistake no amount of correct signing detects.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class s3_store implements store_interface {

    /** @var string Backend name stored on presenterai_recording.backend. */
    public const NAME = 's3';

    /** @var int Lifetime of an upload URL. Long enough for a slow uplink to finish a 130MB body. */
    public const TTL_UPLOAD = 900;

    /** @var int AWS refuses X-Amz-Expires above seven days, so signing a longer one wastes a round trip. */
    public const TTL_MAX = 604800;

    /** @var string Region assumed when the setting is empty. */
    public const DEFAULT_REGION = 'us-east-1';

    /** @var string Key prefix assumed when the setting is empty. */
    public const DEFAULT_PREFIX = 'presenterai/';

    /** @var int Bytes of random payload selftest() writes and reads back. */
    private const SELFTEST_BYTES = 64;

    /**
     * @var array Configuration supplied by the caller, overriding get_config().
     *      Exists so a MinIO container in CI and the pinned signing test can
     *      drive this class without writing to mdl_config_plugins, which is
     *      global state a test cannot hold exclusively.
     */
    private array $overrides;

    /**
     * Build a store, optionally against configuration that is not the site's.
     *
     * @param array $overrides Any of key, secret, bucket, region, prefix, endpoint, pathstyle.
     *        Each is the site setting of the same name minus its s3 prefix, and
     *        anything not listed falls through to get_config().
     */
    public function __construct(array $overrides = []) {
        $this->overrides = $overrides;
    }

    /**
     * Short machine name of this backend.
     *
     * @return string
     */
    public function name(): string {
        return self::NAME;
    }

    /**
     * Whether a key, a secret, a bucket and a region are all present.
     *
     * False rather than an exception: the settings page and the health check
     * both need to say "not configured yet" without handling a throw, and the
     * cleanup task needs to skip an unconfigured site rather than die in cron.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->config('key') !== ''
            && $this->config('secret') !== ''
            && $this->config('bucket') !== ''
            // The RAW setting, not region(), which substitutes a default and
            // made this clause always true. An admin who cleared the region on a
            // eu-west-1 bucket got a page saying the backend was configured
            // while every request signed a us-east-1 scope, and S3 answered
            // PermanentRedirect with nothing on screen pointing at the cause.
            && $this->config('region') !== '';
    }

    /**
     * Whether the browser can upload straight to storage.
     *
     * @return bool
     */
    public function supports_direct_upload(): bool {
        return true;
    }

    /**
     * Mint a key and return a presigned PUT the browser can upload to.
     *
     * @param media_ref $ref The media being uploaded. Its recordingid may be 0.
     * @return array Upload instructions for the browser.
     */
    public function begin_upload(media_ref $ref): array {
        if (!$ref->has_valid_kind()) {
            throw new \coding_exception('Unknown media kind: ' . $ref->kind);
        }
        if (!$this->is_configured()) {
            throw new \coding_exception('s3_store::begin_upload called while the backend is unconfigured');
        }
        $key = $this->make_key($ref);
        return [
            'key' => $key,
            'method' => 'PUT',
            'url' => $this->sign('PUT', $key, self::TTL_UPLOAD),
            'expires' => self::TTL_UPLOAD,
        ];
    }

    /**
     * How many bytes of a chunked upload have already landed.
     *
     * Always zero. There is no staging file to resume into, because the bytes
     * go browser to bucket and PHP never sees them. store_interface documents
     * zero as the answer for a direct-upload backend, so a caller that asks
     * generically gets a true answer rather than an exception.
     *
     * @param string $uploadid The id returned by begin_upload. Never issued by this backend.
     * @return int Byte offset to resume from.
     */
    public function resume_offset(string $uploadid): int {
        return 0;
    }

    /**
     * Append one chunk to a staging file. Never valid on this backend.
     *
     * Throws rather than returning a length. A caller that reaches here has
     * ignored supports_direct_upload() and is feeding chunks to nothing; a
     * plausible-looking length would let it run to a commit that reports a size
     * for an object that was never written.
     *
     * @param string $uploadid The id returned by begin_upload.
     * @param int $offset Byte offset this chunk claims to start at.
     * @param resource $stream Open read stream for the chunk body.
     * @return int The new total length of the staging file.
     */
    public function accept_chunk(string $uploadid, int $offset, $stream): int {
        throw new \coding_exception(
            's3_store::accept_chunk is not implemented; check supports_direct_upload() before chunking'
        );
    }

    /**
     * Confirm the browser really uploaded, by asking the bucket rather than the browser.
     *
     * @param media_ref $ref The media being committed, carrying its key.
     * @param string $uploadid The chunked upload id, which must be empty here.
     * @return int|null Size in bytes, or null if the object is absent.
     */
    public function commit_upload(media_ref $ref, string $uploadid = ''): ?int {
        if ($uploadid !== '') {
            throw new \coding_exception('s3_store::commit_upload was given a chunked upload id, which it cannot commit');
        }
        if ($ref->key === '') {
            throw new \coding_exception('s3_store::commit_upload needs a ref carrying the key begin_upload minted');
        }
        // null, never 0. store_interface is explicit that a caller treats null
        // as a failed attempt rather than an empty one, and fs_store already
        // returns null here. A zero would be reported as a successful upload of
        // nothing.
        $size = $this->size($ref->key);

        return ($size !== null && $size > 0) ? $size : null;
    }

    /**
     * Whether this key really belongs to this learner in this course.
     *
     * A string compare on the key's own prefix, which is exactly the check
     * soapbox_finalize_recording.php:123-124 makes today. It works because
     * make_key() is the only thing that mints a key and it always files one
     * under prefix/courseid/userid/.
     *
     * $contextid is accepted and not used. An S3 key encodes the course and the
     * owner and nothing else, so the module context cannot be checked from the
     * key; the caller checks it against the recording row. Saying so here is the
     * point, because a reader who assumed this method covered the context would
     * skip the check that does.
     *
     * @param string $key The stored key.
     * @param int $courseid Expected course.
     * @param int $userid Expected owner.
     * @param int $contextid Expected module context, not checkable from an S3 key.
     * @return bool
     */
    public function owns(string $key, int $courseid, int $userid, int $contextid): bool {
        if ($key === '' || $courseid <= 0 || $userid <= 0) {
            return false;
        }
        $expected = $this->prefix() . $courseid . '/' . $userid . '/';
        return strpos($key, $expected) === 0;
    }

    /**
     * Size of the stored object in bytes, or null if it is gone.
     *
     * A signed HEAD. The method is part of the SigV4 canonical request, so a
     * GET-signed URL cannot be reused for this.
     *
     * @param string $key The stored key.
     * @return int|null
     */
    public function size(string $key): ?int {
        if ($key === '' || !$this->is_configured()) {
            return null;
        }
        $curl = self::new_curl();
        $curl->head($this->sign('HEAD', $key, 300));
        if ($curl->get_errno()) {
            return null;
        }
        $info = $curl->get_info();
        if ((int) ($info['http_code'] ?? 0) !== 200) {
            return null;
        }
        $size = $info['download_content_length'] ?? null;
        return ($size !== null && $size >= 0) ? (int) $size : null;
    }

    /**
     * A presigned GET the learner's browser can play or download.
     *
     * Both response-* parameters are merged into the query BEFORE the ksort and
     * the signature, which is what presign_url()'s extraquery is for. Appending
     * either to the finished URL produces a URL S3 rejects with
     * SignatureDoesNotMatch, and what the learner sees is an XML error page
     * where their video should be.
     *
     * response-cache-control is signed on every read, not only downloads,
     * because a presigned URL is a bearer token and a shared proxy that cached
     * one would serve a learner's video to whoever asked next. See section 4.6
     * of docs/IMPLEMENTATION-PLAN.md.
     *
     * @param string $key The stored key.
     * @param int $ttl Seconds the URL should remain valid.
     * @param string $downloadname Filename to save as, or empty for inline playback.
     * @return string
     */
    public function read_url(string $key, int $ttl, string $downloadname = ''): string {
        $extra = ['response-cache-control' => 'private, no-store'];
        if ($downloadname !== '') {
            // The name reaches the Content-Disposition header S3 sends back, so
            // a quote or a newline in it would be header injection on a value
            // the learner can influence through the file they uploaded.
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($downloadname));
            $extra['response-content-disposition'] = 'attachment; filename="' . $safe . '"';
        }
        return $this->sign('GET', $key, $ttl, $extra);
    }

    /**
     * Download the object to a per-request temp file and return the path.
     *
     * A real download on this backend, so scoring a 65MB video pulls 65MB onto
     * the cron box every run. The file lands under make_request_directory()
     * (lib/setuplib.php:1479), which Moodle removes when the request ends, so a
     * failed scoring run leaves nothing behind.
     *
     * @param string $key The stored key.
     * @param string $ext Extension for the temporary file, without the dot.
     * @return string|null Absolute path, or null if the object is gone.
     */
    public function fetch_to_file(string $key, string $ext): ?string {
        if ($key === '' || !$this->is_configured()) {
            return null;
        }
        $safeext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        if ($safeext === '') {
            $safeext = 'bin';
        }
        $path = make_request_directory() . '/media.' . $safeext;

        $curl = self::new_curl();
        $curl->download_one($this->sign('GET', $key, 900), null, [
            'filepath' => $path,
            'timeout' => 180,
            'followlocation' => true,
        ]);

        // curl is not asked to fail on an HTTP error, so a 403 or a 404 writes
        // S3's XML error document into the file and download_one still reports
        // success. Without this check the transcriber would be handed a few
        // hundred bytes of XML and would report a learner who said nothing.
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code !== 200 || !file_exists($path) || filesize($path) === 0) {
            if (file_exists($path)) {
                unlink($path);
            }
            return null;
        }
        return $path;
    }

    /**
     * Read the object into memory, refusing anything larger than $maxbytes.
     *
     * Bounded three times, and the order is the point. object_size() first, so
     * an oversized object costs one HEAD instead of a transfer. CURLOPT_MAXFILESIZE
     * during, which curl applies before the transfer starts using the
     * Content-Length S3 sends. strlen() after, which is the only one that holds
     * if a non-AWS server omits Content-Length.
     *
     * Reading first and measuring afterwards is what this replaces: the object
     * is learner-supplied, a query-signed PUT carries no content-length-range,
     * and a memory_limit breach is E_ERROR, which no catch block intercepts. In
     * cron that takes down whatever else was queued behind it.
     *
     * @param string $key The stored key.
     * @param int $maxbytes Hard ceiling.
     * @return string|null The bytes, or null if absent, too large, or unreadable.
     */
    public function read_bytes(string $key, int $maxbytes): ?string {
        if ($key === '' || $maxbytes <= 0 || !$this->is_configured()) {
            return null;
        }
        $size = $this->size($key);
        if ($size === null || $size > $maxbytes) {
            return null;
        }
        $curl = self::new_curl();
        $curl->setopt(['CURLOPT_MAXFILESIZE' => $maxbytes]);
        $bytes = $curl->get($this->sign('GET', $key, 300));
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code !== 200 || !is_string($bytes) || strlen($bytes) > $maxbytes) {
            return null;
        }
        return $bytes;
    }

    /**
     * Delete the object, best effort.
     *
     * @param string $key The stored key.
     * @return bool True if the object is gone or was already gone.
     */
    public function delete(string $key): bool {
        // Best effort, and it must NEVER throw. selftest() calls this from
        // inside a finally, so a throw here would discard every step the
        // administrator had already collected, which is the outcome that
        // cleanup block exists to prevent. \curl::request() can throw a
        // coding_exception on a redirect count, so this is reachable.
        try {
            if ($key === '' || !$this->is_configured()) {
                return false;
            }
            $curl = self::new_curl();
            // Moodle's curl::delete() sets CURLOPT_USERPWD ('anonymous: ...') when
            // the caller does not (lib/filelib.php:4214-4216 on 4.5), which makes
            // curl add an Authorization: Basic header. S3 then sees two auth
            // mechanisms on one request, the query signature and that header, and
            // answers 400 InvalidArgument. Passing an explicit CURLOPT_HTTPHEADER
            // is what clears it: apply_opt() sets the headers first and then loops
            // over the options (:3554 then :3583-3592), so an option of that name
            // is applied last and wins.
            $curl->delete($this->sign('DELETE', $key, 300), [], ['CURLOPT_HTTPHEADER' => ['Authorization:']]);
            $code = (int) ($curl->get_info()['http_code'] ?? 0);
            return ($code >= 200 && $code < 300) || $code === 404;
        } catch (\Throwable $e) {
            debugging(
                'mod_presenterai s3_store could not delete ' . $key . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }
    }

    /**
     * Prove the backend works from this server, right now, with a real round trip.
     *
     * The object written here is a few dozen bytes under prefix/selftest/, which
     * owns() rejects for every learner, so it can never be confused with media.
     * The delete runs whatever the earlier steps did, because a self test that
     * leaves litter in the bucket on failure is a self test administrators stop
     * running.
     *
     * @return array Ordered list of ['step' => string, 'ok' => bool, 'detail' => string].
     */
    public function selftest(): array {
        $steps = [];
        if (!$this->is_configured()) {
            $steps[] = self::step('configuration', false, 'Key, secret, bucket and region are not all set.');
            return $steps;
        }
        $parts = $this->endpoint_parts();
        $steps[] = self::step('configuration', true, 'Addressing ' . $parts['scheme'] . '://' . $parts['host']
            . ' as bucket ' . $this->config('bucket') . ' in region ' . $this->region()
            . ($this->is_path_style() ? ' (path style)' : ' (virtual hosted style)'));

        $key = $this->prefix() . 'selftest/' . random_string(24) . '.txt';
        $payload = random_string(self::SELFTEST_BYTES);

        try {
            $curl = self::new_curl();
            $curl->put($this->sign('PUT', $key, 300), $payload);
            $code = (int) ($curl->get_info()['http_code'] ?? 0);
            $steps[] = self::step('write', $code >= 200 && $code < 300,
                'Presigned PUT returned HTTP ' . $code . '.');

            $size = $this->size($key);
            $steps[] = self::step('size', $size === strlen($payload),
                $size === null ? 'Signed HEAD found no object.'
                    : 'Signed HEAD reported ' . $size . ' bytes, expected ' . strlen($payload) . '.');

            $readback = $this->read_bytes($key, 1024);
            $steps[] = self::step('read', $readback === $payload,
                $readback === null ? 'Signed GET returned nothing readable.'
                    : ($readback === $payload ? 'Signed GET returned the same bytes that were written.'
                        : 'Signed GET returned different bytes than were written.'));

            $steps[] = $this->check_unsigned_get_refused($key);
            $steps[] = $this->check_cors_preflight($key);
        } catch (\Throwable $e) {
            // Caught rather than thrown so the caller still gets the steps that
            // did run, and so the delete below still happens. An administrator
            // reading a half-finished list learns more than one reading a stack
            // trace, and a self test that aborts before its own cleanup leaves
            // the litter it was trying not to leave.
            $steps[] = self::step('exception', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            $gone = $this->delete($key);
            $steps[] = self::step('delete', $gone,
                $gone ? 'Signed DELETE removed the test object.'
                    : 'Signed DELETE failed; a stray object may remain at ' . $key . '.');
        }

        return $steps;
    }

    /**
     * Check that the bucket refuses a request carrying no signature.
     *
     * This is the step that matters most and the one nothing else can catch. A
     * correctly signed plugin talking to a public bucket behaves perfectly while
     * every learner's video is readable by anyone who guesses a key, and the
     * plugin has no other way to notice.
     *
     * @param string $key The test object's key, which is known to exist at this point.
     * @return array One ['step', 'ok', 'detail'] entry.
     */
    private function check_unsigned_get_refused(string $key): array {
        $curl = self::new_curl();
        $curl->get($this->object_url($key));
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code === 200) {
            return self::step('private', false,
                'An unsigned GET succeeded: this bucket is PUBLIC and every stored recording is readable by anyone.');
        }
        if ($code === 0) {
            return self::step('private', false,
                'An unsigned GET could not be made at all, so it proves nothing: ' . $curl->error);
        }
        return self::step('private', true, 'An unsigned GET was refused with HTTP ' . $code . '.');
    }

    /**
     * Check that the bucket answers a browser's CORS preflight for an upload.
     *
     * A missing CORS rule fails in the browser before anything reaches PHP, so
     * the only symptom is a learner who finished speaking and then watched the
     * upload fail with nothing in any server log.
     *
     * @param string $key The test object's key, used only as a preflight target.
     * @return array One ['step', 'ok', 'detail'] entry.
     */
    private function check_cors_preflight(string $key): array {
        global $CFG;
        $curl = self::new_curl();
        $curl->setHeader([
            'Origin: ' . $CFG->wwwroot,
            'Access-Control-Request-Method: PUT',
            'Access-Control-Request-Headers: content-type',
        ]);
        $curl->options($this->object_url($key));
        $raw = $curl->get_raw_response();
        $allow = '';
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (stripos((string) $line, 'access-control-allow-origin:') === 0) {
                $allow = trim(substr((string) $line, strlen('access-control-allow-origin:')));
                break;
            }
        }
        if ($allow === '') {
            return self::step('cors', false,
                'The preflight returned no Access-Control-Allow-Origin, so a browser upload from '
                . $CFG->wwwroot . ' will fail before it reaches this site.');
        }
        return self::step('cors', true, 'The preflight allows ' . $allow . '.');
    }

    /**
     * One selftest row.
     *
     * @param string $step Machine name of the step.
     * @param bool $ok Whether it passed.
     * @param string $detail What was observed, in words an administrator can act on.
     * @return array
     */
    private static function step(string $step, bool $ok, string $detail): array {
        return ['step' => $step, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * Build the object key for a new piece of media.
     *
     * The recording shape is byte-identical to soapbox_storage::make_object_key()
     * (prefix/courseid/userid/token.ext), and decks and frame sheets keep their
     * extra segment under the same learner path. That is not nostalgia: the
     * Soapbox migration carries keys across unchanged, and owns() matches on
     * prefix/courseid/userid/, so a key built any other way is rejected as
     * foreign by the check that protects one learner's media from another.
     *
     * @param media_ref $ref The media to file. Its key, if any, is ignored.
     * @return string
     */
    private function make_key(media_ref $ref): string {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ref->ext));
        if ($ext === '') {
            $ext = 'bin';
        }
        $dir = $this->prefix() . $ref->courseid . '/' . $ref->userid . '/';
        if ($ref->kind !== media_ref::KIND_RECORDING) {
            $dir .= $ref->kind . '/';
        }
        return $dir . random_string(24) . '.' . $ext;
    }

    /**
     * Sign one request against the configured endpoint.
     *
     * @param string $method HTTP method, which SigV4 signs, so a GET URL cannot serve a HEAD.
     * @param string $key The object key.
     * @param int $expires Requested lifetime in seconds.
     * @param array $extraquery Query parameters to merge in before the sort and the signature.
     * @return string
     */
    private function sign(string $method, string $key, int $expires, array $extraquery = []): string {
        $parts = $this->endpoint_parts();
        return self::presign_url([
            'scheme' => $parts['scheme'],
            'host' => $parts['host'],
            'region' => $this->region(),
            'service' => 's3',
            'accesskey' => $this->config('key'),
            'secretkey' => $this->config('secret'),
            'method' => $method,
            'uri' => $parts['uriprefix'] . self::encode_key_path($key),
            'expires' => self::clamp_ttl($expires),
            'timestamp' => time(),
            'extraquery' => $extraquery,
        ]);
    }

    /**
     * The unsigned URL of an object, for the one check that must carry no signature.
     *
     * @param string $key The object key.
     * @return string
     */
    private function object_url(string $key): string {
        $parts = $this->endpoint_parts();
        return $parts['scheme'] . '://' . $parts['host'] . $parts['uriprefix'] . self::encode_key_path($key);
    }

    /**
     * Where requests go, and how much of the path the bucket occupies.
     *
     * With no endpoint override this is AWS virtual-hosted style, exactly as the
     * source hard-coded it, so existing Saylor keys sign to the same URLs they
     * signed before the port.
     *
     * With an override the host comes from the setting. Path style then puts the
     * bucket in the URI, which is what MinIO and most self-hosted gateways
     * expect and what makes an ip:port endpoint work at all: a virtual-hosted
     * bucket needs a DNS name per bucket, and there is no such name for a
     * container. The port stays in the host string because SigV4 signs the Host
     * header as the client will send it, port included.
     *
     * @return array scheme, host (with port when not default), and uriprefix (encoded, no trailing slash).
     */
    private function endpoint_parts(): array {
        $bucket = $this->config('bucket');
        $endpoint = $this->config('endpoint');

        if ($endpoint === '') {
            return [
                'scheme' => 'https',
                'host' => $bucket . '.s3.' . $this->region() . '.amazonaws.com',
                'uriprefix' => '',
            ];
        }

        // Without a scheme parse_url returns 's3.example.org' as a path and no
        // host at all, which would sign every request against an empty Host
        // header. ('minio:9000' happens to parse as host and port, so the bug
        // would only show up on the endpoints that have no port.) The settings
        // page saves this as PARAM_URL and refuses a schemeless value, but the
        // constructor overrides bypass the settings page and a CI container is
        // where a bare host gets typed.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        $bits = parse_url($endpoint);
        $scheme = strtolower((string) ($bits['scheme'] ?? 'https'));
        $host = (string) ($bits['host'] ?? '');
        $port = isset($bits['port']) ? (int) $bits['port'] : 0;
        $basepath = rtrim((string) ($bits['path'] ?? ''), '/');

        $uriprefix = $basepath === '' ? '' : self::encode_key_path($basepath);
        if ($this->is_path_style()) {
            $uriprefix .= '/' . rawurlencode($bucket);
        } else {
            $host = $bucket . '.' . $host;
        }
        if ($port > 0) {
            $host .= ':' . $port;
        }

        return ['scheme' => $scheme, 'host' => $host, 'uriprefix' => $uriprefix];
    }

    /**
     * Whether the bucket goes in the path rather than the host.
     *
     * Only honoured alongside an endpoint override. On AWS the host carries the
     * bucket, so respecting this flag there would silently change every URL the
     * Saylor bucket has ever been addressed by, which is the one thing this port
     * is not allowed to do.
     *
     * @return bool
     */
    private function is_path_style(): bool {
        return $this->config('endpoint') !== '' && (int) $this->config('pathstyle') === 1;
    }

    /**
     * The configured key prefix, always ending with a slash and never starting with one.
     *
     * @return string
     */
    private function prefix(): string {
        $value = $this->config('prefix');
        if ($value === '') {
            $value = self::DEFAULT_PREFIX;
        }
        return rtrim(ltrim($value, '/'), '/') . '/';
    }

    /**
     * The configured region.
     *
     * @return string
     */
    private function region(): string {
        $value = $this->config('region');
        return $value !== '' ? $value : self::DEFAULT_REGION;
    }

    /**
     * One configuration value, from the constructor overrides or from the site.
     *
     * The site settings are named s3bucket, s3region and so on in settings.php,
     * so the short name is that name without its s3 prefix.
     *
     * @param string $name Short name: key, secret, bucket, region, prefix, endpoint or pathstyle.
     * @return string
     */
    private function config(string $name): string {
        if (array_key_exists($name, $this->overrides)) {
            return trim((string) $this->overrides[$name]);
        }
        return trim((string) get_config('mod_presenterai', 's3' . $name));
    }

    /**
     * A curl client, with filelib loaded.
     *
     * Moodle's \curl rather than curl_init(), which the plugin directory review
     * rejects: \curl is what applies the site's SSRF blocklist and proxy. A
     * MinIO endpoint on a private address is blocked by that list until an
     * administrator allows it under Site administration, HTTP security, and
     * that refusal is correct rather than a bug to work around.
     *
     * @return \curl
     */
    private static function new_curl(): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return new \curl();
    }

    /**
     * Keep a requested lifetime inside what AWS will accept.
     *
     * @param int $ttl Requested seconds.
     * @return int
     */
    private static function clamp_ttl(int $ttl): int {
        if ($ttl < 1) {
            return 1;
        }
        return min($ttl, self::TTL_MAX);
    }

    /**
     * URI-encode an object key path, preserving the slashes between segments.
     *
     * @param string $key The object key, with or without a leading slash.
     * @return string Canonical URI path, beginning with a slash.
     */
    public static function encode_key_path(string $key): string {
        $segments = explode('/', ltrim($key, '/'));
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Build an AWS Signature Version 4 presigned URL, with the auth in the query string.
     *
     * Deliberately static and deliberately free of get_config(): given identical
     * inputs it returns an identical URL, which is what lets a test pin it
     * against AWS's own published worked example (GET
     * https://examplebucket.s3.amazonaws.com/test.txt, us-east-1, 2013-05-24,
     * X-Amz-Expires=86400, signature
     * aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404). That
     * pin is the only evidence this signs correctly, so nothing here may be
     * rearranged for tidiness: the canonical request is hashed, so a reordered
     * query string or a differently encoded path is a different signature and
     * the only error S3 gives back is SignatureDoesNotMatch.
     *
     * 'uri' must already be a canonical per-segment-encoded path beginning with
     * '/', which encode_key_path() produces.
     *
     * @param array $o host, region, service, accesskey, secretkey, method, uri,
     *                 expires (int), timestamp (int), optional extraquery (assoc),
     *                 optional scheme.
     * @return string
     */
    public static function presign_url(array $o): string {
        $method = strtoupper((string) $o['method']);
        $host = (string) $o['host'];
        $region = (string) $o['region'];
        $service = (string) ($o['service'] ?? 's3');
        $uri = (string) $o['uri'];
        $expires = (int) $o['expires'];
        $ts = (int) $o['timestamp'];
        $scheme = (string) ($o['scheme'] ?? 'https');

        $amzdate = gmdate('Ymd\THis\Z', $ts);
        $datestamp = gmdate('Ymd', $ts);
        $algorithm = 'AWS4-HMAC-SHA256';
        $scope = $datestamp . '/' . $region . '/' . $service . '/aws4_request';

        $query = array_merge([
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $o['accesskey'] . '/' . $scope,
            'X-Amz-Date' => $amzdate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ], $o['extraquery'] ?? []);
        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $canonicalquery = implode('&', $pairs);

        $canonicalheaders = 'host:' . $host . "\n";
        $signedheaders = 'host';
        $payloadhash = 'UNSIGNED-PAYLOAD';
        $canonicalrequest = $method . "\n" . $uri . "\n" . $canonicalquery . "\n"
            . $canonicalheaders . "\n" . $signedheaders . "\n" . $payloadhash;

        $stringtosign = $algorithm . "\n" . $amzdate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalrequest);

        $kdate = hash_hmac('sha256', $datestamp, 'AWS4' . $o['secretkey'], true);
        $kregion = hash_hmac('sha256', $region, $kdate, true);
        $kservice = hash_hmac('sha256', $service, $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        $signature = hash_hmac('sha256', $stringtosign, $ksigning);

        return $scheme . '://' . $host . $uri . '?' . $canonicalquery . '&X-Amz-Signature=' . $signature;
    }
}
