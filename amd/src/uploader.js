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

/**
 * Send a recording or a deck to wherever start_upload said it goes.
 *
 * Two protocols behind one call. On S3 the browser PUTs the whole blob to a
 * presigned URL, retried the way Soapbox's putWithRetry did. On Moodle file
 * storage it POSTs the blob to upload.php in chunks, because stock PHP refuses
 * a body above 8 MB and stock nginx above 1 MB (plan section 4.4).
 *
 * The chunks are strictly sequential. The server appends to one staging file
 * and checks each chunk's offset against what it already holds, so a second
 * chunk in flight would always arrive at the wrong offset. Plan 4.4 says
 * "three requests in flight"; that cannot work against an append-only file.
 *
 * Nothing in here talks to the learner. Errors carry a short code, and the
 * caller turns failure into a translated sentence and a Retry button.
 *
 * @module     mod_presenterai/uploader
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} The smallest chunk the 413 halving will go down to. */
const MIN_CHUNK = 65536;

/** @var {number} The chunk size when the server did not name one, which clears stock nginx. */
const DEFAULT_CHUNK = 524288;

/** @var {number} Attempts per chunk, or per PUT, before giving up. */
const MAX_TRIES = 6;

/**
 * The chunk size to use after a response with this status.
 *
 * A 413 often comes from a proxy in front of PHP, so the body is usually not
 * JSON and the only thing to learn from it is "smaller". Halving converges on
 * whatever the proxy allows in a few requests, and the floor stops a broken
 * server from walking it to nothing.
 *
 * @param {number} current The chunk size just used.
 * @param {number} status The HTTP status received.
 * @return {number}
 */
export const _nextChunkSize = (current, status) => {
    if (status !== 413) {
        return current;
    }
    return Math.max(MIN_CHUNK, Math.floor(current / 2));
};

/**
 * How long to wait before retry number attempt (0 based).
 *
 * @param {number} attempt How many retries have already happened.
 * @return {number} Milliseconds, doubling from 500 and capped at 8 seconds.
 */
export const _backoff = (attempt) => Math.min(8000, 500 * Math.pow(2, attempt));

/**
 * Resolve after a delay.
 *
 * @param {number} ms Milliseconds.
 * @return {Promise}
 */
const wait = (ms) => new Promise((resolve) => {
    setTimeout(resolve, ms);
});

/**
 * An Error carrying a machine readable code and the HTTP status, if any.
 *
 * @param {string} code A short code such as rejected or toolarge.
 * @param {number} status The HTTP status, 0 for a network failure.
 * @return {Error}
 */
const failure = (code, status) => {
    const err = new Error(code);
    err.code = code;
    err.status = status;
    return err;
};

/**
 * Parse a JSON body, or return null when there is none.
 *
 * @param {Response} response A fetch response.
 * @return {Promise<object|null>}
 */
const jsonOrNull = async(response) => {
    try {
        return await response.json();
    } catch (e) {
        return null;
    }
};

/**
 * PUT a blob to a presigned URL, retrying network failures, 429 and 5xx.
 *
 * Any other 4xx is final: an expired or mis-signed URL does not get better on
 * a retry, and the caller's Retry asks start_upload for a fresh one.
 *
 * @param {string} url The presigned URL.
 * @param {Blob} blob The bytes.
 * @param {string} contentType The Content-Type the URL was signed for.
 * @param {function} onProgress Called with a fraction between 0 and 1.
 * @return {Promise<void>}
 */
const putWithRetry = async(url, blob, contentType, onProgress) => {
    for (let attempt = 0; attempt < MAX_TRIES; attempt++) {
        let response = null;
        try {
            response = await fetch(url, {
                method: 'PUT',
                body: blob,
                headers: {'Content-Type': contentType},
            });
        } catch (e) {
            response = null;
        }
        if (response && response.ok) {
            onProgress(1);
            return;
        }
        const status = response ? response.status : 0;
        if (status >= 400 && status < 500 && status !== 429) {
            throw failure('rejected', status);
        }
        if (attempt < MAX_TRIES - 1) {
            await wait(_backoff(attempt));
        }
    }
    throw failure('gaveup', 0);
};

/**
 * POST one chunk, folding a network failure into status 0.
 *
 * @param {string} url The chunk URL.
 * @param {Blob} body The chunk.
 * @return {Promise<object>} {status, data, response}
 */
const postChunk = async(url, body) => {
    let response = null;
    try {
        response = await fetch(url, {
            method: 'POST',
            body: body,
            headers: {'Content-Type': 'application/octet-stream'},
            credentials: 'same-origin',
        });
    } catch (e) {
        return {status: 0, data: null, response: null};
    }
    return {status: response.status, data: await jsonOrNull(response), response: response};
};

/**
 * How long to wait before retrying, honouring a Retry-After header if the server sent one.
 *
 * @param {Response|null} response The failed response, or null.
 * @param {number} tries Failed tries so far, at least 1.
 * @return {number} Milliseconds.
 */
const retryDelay = (response, tries) => {
    const delay = _backoff(tries - 1);
    const retryafter = response ? parseInt(response.headers.get('Retry-After') || '', 10) : NaN;
    return retryafter > 0 ? Math.max(delay, retryafter * 1000) : delay;
};

/**
 * Upload a blob to upload.php in sequential chunks.
 *
 * @param {object} target The start_upload result.
 * @param {Blob} blob The bytes.
 * @param {number} cmid The course module id.
 * @param {number} recordingid The recording row id.
 * @param {function} onProgress Called with a fraction between 0 and 1.
 * @return {Promise<void>}
 */
const postChunks = async(target, blob, cmid, recordingid, onProgress) => {
    const endpoint = (extra) => {
        const params = new URLSearchParams(Object.assign({
            id: cmid,
            recordingid: recordingid,
            uploadid: target.uploadid,
            sesskey: M.cfg.sesskey,
        }, extra));
        return target.url + (target.url.indexOf('?') === -1 ? '?' : '&') + params.toString();
    };

    // The server's own count of bytes received. Asked after anything that
    // leaves us unsure, such as a dropped connection whose chunk may or may
    // not have landed.
    const serverOffset = async() => {
        const response = await fetch(endpoint({action: 'offset'}), {credentials: 'same-origin'});
        const data = response.ok ? await jsonOrNull(response) : null;
        if (!data || typeof data.offset !== 'number') {
            throw failure('nooffset', response.status);
        }
        return data.offset;
    };

    let chunk = target.chunkbytes > 0 ? target.chunkbytes : DEFAULT_CHUNK;
    let offset = 0;
    let tries = 0;

    onProgress(0);
    while (offset < blob.size) {
        const end = Math.min(blob.size, offset + chunk);
        const {status, data, response} = await postChunk(endpoint({offset: offset}), blob.slice(offset, end));

        if (status === 200 && data && typeof data.offset === 'number' && data.offset > offset) {
            offset = data.offset;
            tries = 0;
            onProgress(offset / blob.size);
            continue;
        }

        // Every path below is a failed try, including the ones that are only
        // resyncs, so a server answering nonsense cannot loop us forever.
        tries++;
        if (tries >= MAX_TRIES) {
            throw failure('gaveup', status);
        }

        if (status === 409 && data && typeof data.offset === 'number') {
            if (data.offset > blob.size) {
                throw failure('offset', status);
            }
            offset = data.offset;
            continue;
        }
        if (status === 413) {
            if (chunk <= MIN_CHUNK) {
                throw failure('toolarge', status);
            }
            chunk = _nextChunkSize(chunk, status);
            continue;
        }
        if (status === 400 || status === 404) {
            throw failure('rejected', status);
        }

        // 503 busy, another 5xx, a 200 that did not advance, or no response.
        await wait(retryDelay(response, tries));
        try {
            offset = await serverOffset();
        } catch (e) {
            // Keep the offset we had; the next POST's 409 will correct it.
        }
    }
    onProgress(1);
};

/**
 * Upload a blob to the target start_upload returned.
 *
 * @param {object} target The start_upload result: {method, url, uploadid, chunkbytes, expires, maxbytes}.
 * @param {Blob} blob The bytes to send.
 * @param {object} options
 * @param {number} options.cmid The course module id (chunked upload only).
 * @param {number} options.recordingid The recording row id (chunked upload only).
 * @param {string} options.contentType The blob's type, for the PUT.
 * @param {function} [options.onProgress] Called with a fraction between 0 and 1.
 * @return {Promise<void>} Rejects with an Error whose code says why.
 */
export const upload = (target, blob, {cmid, recordingid, contentType, onProgress} = {}) => {
    const progress = typeof onProgress === 'function' ? onProgress : () => undefined;
    if (target.maxbytes > 0 && blob.size > target.maxbytes) {
        return Promise.reject(failure('toolarge', 0));
    }
    if (target.method === 'PUT') {
        return putWithRetry(target.url, blob, contentType || 'application/octet-stream', progress);
    }
    return postChunks(target, blob, cmid, recordingid, progress);
};
