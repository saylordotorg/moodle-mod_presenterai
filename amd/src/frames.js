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
 * Sample still frames from a finished recording into one contact sheet, in the browser.
 *
 * A port of SOLA's soapbox_frames.js (v7.5.1) with the shipped numbers, which
 * DECISIONS.md C5 says to use: six frames in 426 by 240 cells, three columns
 * by two rows, one JPEG at quality 0.72, sampled from the middle 80 percent of
 * the recording. The first and last tenth are where a learner is reaching for
 * the mouse or settling into frame, and feedback drawn from those moments would
 * be about operating the recorder rather than about presenting.
 *
 * The frames are taken here because the recording already exists as a Blob in
 * memory, and the server has no ffmpeg to take them with (DECISIONS.md 10).
 *
 * Two things this module refuses to do. It never fails the upload: every path
 * resolves, to a Blob or to null, and the caller treats null as no body
 * language feedback this time. And it never reads video.duration, which for a
 * MediaRecorder WebM blob is commonly Infinity until a large seek forces the
 * browser to re-index; the recorder passes the seconds it measured.
 *
 * @module     mod_presenterai/frames
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number} Frames per sheet. */
export const FRAME_COUNT = 6;

/** @var {number} Columns in the contact sheet. */
export const COLS = 3;

/** @var {number} Width of one cell, in pixels. */
export const CELL_W = 426;

/** @var {number} Height of one cell, in pixels. */
export const CELL_H = 240;

/** @var {number} JPEG quality for the sheet. */
export const QUALITY = 0.72;

/** @var {number} Give up on the whole extraction after this long. */
export const TIMEOUT_MS = 12000;

/** @var {number} Give up on one seek after this long. */
const SEEK_TIMEOUT_MS = 3000;

/**
 * Timestamps to sample, spread evenly across the middle 80 percent of the recording.
 *
 * Pure, and exported, because it decides which moments of a learner's talk
 * get judged and should not only be exercised through a browser.
 *
 * @param {number} duration True elapsed seconds, as measured by the recorder.
 * @param {number} [count] How many frames; FRAME_COUNT by default.
 * @return {number[]} Timestamps in seconds, or [] when the duration is unusable.
 */
export const sampleTimes = (duration, count = FRAME_COUNT) => {
    const d = Number(duration);
    const n = Number(count);
    if (!isFinite(d) || d <= 0 || !isFinite(n) || n < 1) {
        return [];
    }
    if (n === 1) {
        return [d * 0.5];
    }
    const out = [];
    for (let k = 0; k < n; k++) {
        out.push(d * (0.1 + 0.8 * (k / (n - 1))));
    }
    return out;
};

/**
 * Seek a video element and resolve once the frame is actually ready.
 *
 * Resolves on the seeked event rather than after a timer, which races the
 * decoder and yields duplicate or blank frames on a slow device.
 *
 * @param {HTMLVideoElement} video The element.
 * @param {number} t Target time in seconds.
 * @return {Promise<boolean>} True when the seek landed.
 */
const seekTo = (video, t) => new Promise((resolve) => {
    let done = false;
    let onSeeked = null;
    let onError = null;
    const finish = (ok) => {
        if (done) {
            return;
        }
        done = true;
        video.removeEventListener('seeked', onSeeked);
        video.removeEventListener('error', onError);
        resolve(ok);
    };
    onSeeked = () => finish(true);
    onError = () => finish(false);
    video.addEventListener('seeked', onSeeked);
    video.addEventListener('error', onError);
    // A seek that never completes must not hang the whole upload.
    setTimeout(() => finish(false), SEEK_TIMEOUT_MS);
    try {
        video.currentTime = t;
    } catch (e) {
        finish(false);
    }
});

/**
 * Build one contact sheet of stills from a recorded blob.
 *
 * Never rejects. Resolves to null on any failure: no canvas, no video track,
 * a codec the browser will not decode, every seek failing, or the whole thing
 * taking longer than timeoutMs.
 *
 * @param {Blob} blob The recording.
 * @param {object} [options]
 * @param {number} [options.durationSeconds] True elapsed seconds, as the recorder measured them.
 * @param {number} [options.timeoutMs] Give up after this long.
 * @return {Promise<Blob|null>} The JPEG sheet, or null.
 */
export const contactSheet = (blob, {durationSeconds = 0, timeoutMs = TIMEOUT_MS} = {}) => new Promise((resolve) => {
    let url = null;
    let video = null;
    let settled = false;

    const cleanup = () => {
        try {
            if (video) {
                video.pause();
                video.removeAttribute('src');
                video.load();
            }
        } catch (e) {
            // Already tearing down; nothing useful to do.
        }
        if (url) {
            try {
                URL.revokeObjectURL(url);
            } catch (e) {
                // Same.
            }
            url = null;
        }
    };
    const finish = (value) => {
        if (settled) {
            return;
        }
        settled = true;
        cleanup();
        resolve(value);
    };

    // A device can stall between seeks just as easily as during one.
    setTimeout(() => finish(null), timeoutMs);

    try {
        if (!blob || !window.URL || typeof document.createElement('canvas').getContext !== 'function') {
            finish(null);
            return;
        }
        const times = sampleTimes(durationSeconds, FRAME_COUNT);
        if (!times.length) {
            finish(null);
            return;
        }

        const rows = Math.ceil(FRAME_COUNT / COLS);
        const canvas = document.createElement('canvas');
        canvas.width = CELL_W * COLS;
        canvas.height = CELL_H * rows;
        const ctx = canvas.getContext('2d');
        if (!ctx || typeof canvas.toBlob !== 'function') {
            finish(null);
            return;
        }
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        url = URL.createObjectURL(blob);
        video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.src = url;

        let drawn = 0;
        const next = async(i) => {
            if (settled) {
                return;
            }
            if (i >= times.length) {
                if (!drawn) {
                    finish(null);
                    return;
                }
                canvas.toBlob((out) => finish(out || null), 'image/jpeg', QUALITY);
                return;
            }
            let ok = false;
            try {
                ok = await seekTo(video, times[i]);
            } catch (e) {
                ok = false;
            }
            if (ok && video.videoWidth > 0 && video.videoHeight > 0) {
                const cx = (i % COLS) * CELL_W;
                const cy = Math.floor(i / COLS) * CELL_H;
                // Letterbox rather than stretch: a distorted aspect ratio
                // would change what posture looks like.
                const scale = Math.min(CELL_W / video.videoWidth, CELL_H / video.videoHeight);
                const dw = Math.max(1, Math.round(video.videoWidth * scale));
                const dh = Math.max(1, Math.round(video.videoHeight * scale));
                try {
                    ctx.drawImage(video, cx + Math.floor((CELL_W - dw) / 2), cy + Math.floor((CELL_H - dh) / 2), dw, dh);
                    drawn++;
                } catch (e) {
                    // One undrawable frame leaves its cell black.
                }
            }
            next(i + 1);
        };

        video.addEventListener('loadeddata', () => {
            // A blob with no video track yields no frames.
            if (!video.videoWidth && !video.videoHeight && video.readyState >= 2) {
                finish(null);
                return;
            }
            next(0);
        }, {once: true});
        video.addEventListener('error', () => finish(null), {once: true});
    } catch (e) {
        finish(null);
    }
});
