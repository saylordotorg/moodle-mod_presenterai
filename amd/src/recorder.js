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
 * The in-browser recorder, and the submit flow that follows Stop.
 *
 * Ported from Soapbox's soapbox_recorder.js: getUserMedia and MediaRecorder at
 * a capped bitrate, MP4 where the browser can record it (it plays on more
 * devices) and WebM otherwise, and a min/max timer. Dropped from the port:
 * still frame sampling and the speech-to-text warm-up, both phase 3.
 *
 * After Stop: start_upload, upload, finalize_recording. The recording row
 * already exists (begin_attempt, DECISIONS.md D16) and the storage key is
 * minted only now, after Stop, so an S3 URL cannot expire while the learner is
 * still speaking. If anything fails the blob stays in memory behind a Retry
 * button: a learner must not lose a seven minute presentation to one failed
 * request.
 *
 * @module     mod_presenterai/recorder
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';
import {upload} from 'mod_presenterai/uploader';

/** @var {number} Seconds before the maximum at which the timer warns. */
const NEAR_MAX_SECONDS = 15;

/** @var {number} How long the "uploaded" message shows before the page reloads. */
const RELOAD_DELAY_MS = 1500;

/**
 * Pick a MediaRecorder type this browser supports, MP4 first.
 *
 * @param {string} mode video or audio.
 * @return {string} A mime type, or '' to let the browser choose.
 */
export const _pickMime = (mode) => {
    const video = ['video/mp4;codecs=avc1', 'video/mp4',
        'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
    const audio = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
    const list = mode === 'audio' ? audio : video;
    if (!window.MediaRecorder || typeof window.MediaRecorder.isTypeSupported !== 'function') {
        return '';
    }
    return list.find((type) => window.MediaRecorder.isTypeSupported(type)) || '';
};

/**
 * The file extension start_upload expects for a recorded type.
 *
 * @param {string} mime The recorded mime type.
 * @return {string} One of mp4, m4a, ogg, webm.
 */
export const _extFor = (mime) => {
    if (mime.indexOf('video/mp4') === 0) {
        return 'mp4';
    }
    if (mime.indexOf('audio/mp4') === 0) {
        return 'm4a';
    }
    if (mime.indexOf('ogg') !== -1) {
        return 'ogg';
    }
    return 'webm';
};

/**
 * Seconds as m:ss, the same shape as the attempt list's Length column.
 *
 * @param {number} s Seconds.
 * @return {string}
 */
export const _fmt = (s) => {
    const m = Math.floor(s / 60);
    const ss = s % 60;
    return m + ':' + (ss < 10 ? '0' : '') + ss;
};

/**
 * Whether a core/ajax failure is the given server error code.
 *
 * The code arrives as the exception's errorcode, which for this plugin's
 * strings is "error:<name>".
 *
 * @param {object} err A core/ajax rejection.
 * @param {string} name The code without its prefix.
 * @return {boolean}
 */
const isError = (err, name) => !!err && typeof err.errorcode === 'string'
    && (err.errorcode === name || err.errorcode === 'error:' + name);

/**
 * Wire a recorder to its markup.
 *
 * @param {HTMLElement} root The element with data-region="recorder".
 * @param {object} config From the page root's data attributes.
 * @param {number} config.cmid
 * @param {string} config.mode video or audio.
 * @param {number} config.minseconds
 * @param {number} config.maxseconds
 * @param {number} config.width
 * @param {number} config.height
 * @param {number} config.videokbps
 * @param {number} config.audiokbps
 * @param {object} hooks What the page controller provides.
 * @param {function} hooks.ensureAttempt Resolves to {recordingid, token}, creating the row on first use.
 * @param {function} hooks.forgetAttempt Drops the cached attempt so the next ensureAttempt begins again.
 * @param {function} hooks.getTopicId Returns the chosen topic id, 0 for none.
 * @param {function} [hooks.needsTopic] True while the activity has topics to choose from and none is chosen.
 * @param {function} [hooks.focusTopic] Moves focus to the topic choice.
 * @param {function} hooks.getSlides Returns the slide viewer, or null.
 * @param {function} hooks.announce Speaks a message through the page's live region.
 * @param {function} [hooks.onStateChange] Called with 'recording', 'submitting' or 'idle'.
 * @return {object|null} {isBusy}, or null when the markup is missing.
 */
export const init = (root, config, hooks) => {
    if (!root) {
        return null;
    }
    const preview = root.querySelector('[data-region="preview"]');
    const recbtn = root.querySelector('[data-action="record"]');
    const stopbtn = root.querySelector('[data-action="stop"]');
    const timer = root.querySelector('[data-region="timer"]');
    const indicator = root.querySelector('[data-region="recording-indicator"]');
    const nearmax = root.querySelector('[data-region="near-max"]');
    const status = root.querySelector('[data-region="status"]');
    const retrywrap = root.querySelector('[data-region="retry"]');
    const retrybtn = root.querySelector('[data-action="retry"]');
    const notify = typeof hooks.onStateChange === 'function' ? hooks.onStateChange : () => undefined;

    let stream = null;
    let recorder = null;
    let chunks = [];
    let startedat = 0;
    let timerid = null;
    let mime = '';
    let busy = false;
    // The finished recording waiting to be sent, kept until the server has it.
    let pending = null;

    const setStatus = (text, announce) => {
        if (status) {
            status.textContent = text;
        }
        if (announce) {
            hooks.announce(text);
        }
    };
    const say = async(key, a, announce) => {
        try {
            setStatus(await getString(key, 'mod_presenterai', a), announce);
        } catch (e) {
            setStatus('', false);
        }
    };
    const show = (el, visible) => {
        if (el) {
            el.hidden = !visible;
        }
    };
    const setBusy = (value, state) => {
        busy = value;
        if (recbtn) {
            recbtn.disabled = value;
        }
        notify(state);
    };

    const stopStream = () => {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (preview) {
            preview.srcObject = null;
        }
    };

    const stopTimer = () => {
        if (timerid) {
            window.clearInterval(timerid);
            timerid = null;
        }
    };

    const constraints = () => {
        if (config.mode === 'audio') {
            return {audio: true, video: false};
        }
        return {
            audio: true,
            video: {
                width: {ideal: config.width},
                height: {ideal: config.height},
                frameRate: {max: 24},
            },
        };
    };

    // Leaving the page mid upload would discard the recording, so the browser
    // asks first. The prompt's wording is the browser's own.
    const guard = (e) => {
        e.preventDefault();
        e.returnValue = '';
    };

    /**
     * Send the pending recording, then finalize it.
     *
     * @return {Promise<void>}
     */
    const submit = async() => {
        setBusy(true, 'submitting');
        show(retrywrap, false);
        window.addEventListener('beforeunload', guard);
        try {
            const send = async(attempt) => {
                const recordingid = attempt.recordingid;
                const target = await Ajax.call([{
                    methodname: 'mod_presenterai_start_upload',
                    args: {recordingid: recordingid, kind: 'recording', ext: pending.ext, sizebytes: pending.blob.size,
                        attempttoken: attempt.token},
                }])[0];
                let lastpct = -1;
                let sending = true;
                try {
                    await upload(target, pending.blob, {
                        cmid: config.cmid,
                        recordingid: recordingid,
                        contentType: pending.mime,
                        onProgress: (fraction) => {
                            const pct = Math.floor(fraction * 100);
                            // A late progress string must not overwrite the
                            // status that follows the upload.
                            if (sending && pct !== lastpct) {
                                lastpct = pct;
                                say('rec_status_uploading', pct, false);
                            }
                        },
                    });
                } finally {
                    sending = false;
                }
            };
            const finalize = (attempt) => Ajax.call([{
                methodname: 'mod_presenterai_finalize_recording',
                args: {
                    recordingid: attempt.recordingid,
                    attempttoken: attempt.token,
                    topicid: hooks.getTopicId(),
                    durationseconds: pending.duration,
                    slidetimeline: pending.timeline,
                },
            }])[0];

            const deliver = async(attempt) => {
                await say('rec_status_uploading', 0, true);
                await send(attempt);
                await say('rec_status_finalizing', null, false);
                try {
                    await finalize(attempt);
                } catch (err) {
                    // The server did not find the bytes. Send them once more, then
                    // ask again; a second miss goes to the Retry button.
                    if (!isError(err, 'uploadmissing')) {
                        throw err;
                    }
                    await send(attempt);
                    await finalize(attempt);
                }
            };

            try {
                await deliver(await hooks.ensureAttempt());
            } catch (err) {
                // Another tab took the row over, or the cleanup task closed it.
                // The recording is still in memory, so it goes to a new
                // attempt rather than to the Retry button, and a Retry later
                // does the same rather than reusing a row it cannot have.
                if (!isError(err, 'attemptsuperseded') && !isError(err, 'notuploading')) {
                    throw err;
                }
                hooks.forgetAttempt();
                await deliver(await hooks.ensureAttempt());
            }

            pending = null;
            window.removeEventListener('beforeunload', guard);
            await say('rec_status_uploaded', null, true);
            // A reload is the simplest way to show the new row exactly as the
            // server renders it, deletion date and all.
            window.setTimeout(() => window.location.reload(), RELOAD_DELAY_MS);
        } catch (err) {
            window.removeEventListener('beforeunload', guard);
            setBusy(false, 'idle');
            // A server message is already translated; anything else gets ours.
            if (err && typeof err.message === 'string' && err.errorcode) {
                setStatus(err.message, true);
            } else {
                await say('rec_status_failed', null, true);
            }
            if (isError(err, 'uploadtoolarge') || (err && err.code === 'toolarge')) {
                // Sending the same bytes again cannot succeed, so there is
                // nothing worth keeping a Retry button for.
                pending = null;
                return;
            }
            show(retrywrap, true);
            if (recbtn) {
                // Starting again would replace the recording waiting in Retry.
                recbtn.disabled = true;
            }
        }
    };

    const handleStop = async() => {
        const elapsed = Math.floor((Date.now() - startedat) / 1000);
        stopStream();
        show(indicator, false);
        show(nearmax, false);
        root.classList.remove('mod-presenterai-recording', 'mod-presenterai-near-max');
        const slides = hooks.getSlides();
        if (slides) {
            slides.stopCapture();
        }
        if (config.minseconds && elapsed < config.minseconds) {
            setBusy(false, 'idle');
            await say('rec_too_short', _fmt(config.minseconds), true);
            return;
        }
        const type = mime || (config.mode === 'audio' ? 'audio/webm' : 'video/webm');
        pending = {
            blob: new Blob(chunks, {type: type}),
            mime: type,
            ext: _extFor(type),
            duration: elapsed,
            timeline: slides ? JSON.stringify(slides.getTimeline()) : '',
        };
        chunks = [];
        await submit();
    };

    const stop = () => {
        stopTimer();
        if (stopbtn) {
            stopbtn.disabled = true;
        }
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    const tick = () => {
        const elapsed = Math.floor((Date.now() - startedat) / 1000);
        if (timer) {
            timer.textContent = _fmt(elapsed);
        }
        if (config.maxseconds && elapsed >= config.maxseconds - NEAR_MAX_SECONDS
                && !root.classList.contains('mod-presenterai-near-max')) {
            // Words as well as a colour change (design section 11, rule 9).
            root.classList.add('mod-presenterai-near-max');
            show(nearmax, true);
        }
        if (config.maxseconds && elapsed >= config.maxseconds) {
            stop();
        }
    };

    const start = async() => {
        setBusy(true, 'recording');
        show(retrywrap, false);
        try {
            // The row exists before any bytes do (D16), and a learner at the
            // attempt cap finds out now rather than after speaking.
            await hooks.ensureAttempt();
        } catch (err) {
            setBusy(false, 'idle');
            setStatus(err && err.message ? err.message : '', true);
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia(constraints());
            if (preview && config.mode !== 'audio') {
                preview.srcObject = stream;
                preview.muted = true;
                await preview.play();
            }
            mime = _pickMime(config.mode);
            const opts = {audioBitsPerSecond: config.audiokbps * 1000};
            if (mime) {
                opts.mimeType = mime;
            }
            if (config.mode !== 'audio') {
                opts.videoBitsPerSecond = config.videokbps * 1000;
            }
            chunks = [];
            recorder = new window.MediaRecorder(stream, opts);
            // The browser may settle on a type other than the one asked for.
            mime = recorder.mimeType || mime;
            recorder.ondataavailable = (e) => {
                if (e.data && e.data.size) {
                    chunks.push(e.data);
                }
            };
            recorder.onstop = handleStop;
            recorder.start();
        } catch (err) {
            // Release the camera and microphone before reporting anything. A
            // MediaRecorder constructor throw or a preview.play() rejection
            // lands here after the stream was acquired, and without this the
            // camera light stays on with no control left on screen that could
            // turn it off.
            stopStream();
            setBusy(false, 'idle');
            const denied = err && ['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].indexOf(err.name) !== -1;
            if (denied) {
                await say('rec_mic_denied', null, true);
            } else {
                await say('rec_start_failed', err && err.name ? err.name : '', true);
            }
            return;
        }

        startedat = Date.now();
        const slides = hooks.getSlides();
        if (slides) {
            slides.startCapture(() => (Date.now() - startedat) / 1000);
        }
        root.classList.remove('mod-presenterai-near-max');
        root.classList.add('mod-presenterai-recording');
        show(nearmax, false);
        show(indicator, true);
        if (timer) {
            timer.textContent = _fmt(0);
        }
        if (stopbtn) {
            stopbtn.disabled = false;
            stopbtn.focus();
        }
        timerid = window.setInterval(tick, 500);
        await say('rec_status_recording', null, true);
    };

    if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (recbtn) {
            recbtn.disabled = true;
        }
        say('rec_unsupported', null, false);
        return {isBusy: () => true};
    }

    if (recbtn) {
        recbtn.addEventListener('click', () => {
            if (busy) {
                return;
            }
            // A topic has to be chosen first; the server refuses a recording
            // without one, and it is kinder to say so before the camera starts.
            if (hooks.needsTopic && hooks.needsTopic()) {
                say('rec_choosetopic', null, true);
                if (hooks.focusTopic) {
                    hooks.focusTopic();
                }
                return;
            }
            start();
        });
    }
    if (stopbtn) {
        stopbtn.disabled = true;
        stopbtn.addEventListener('click', stop);
    }
    if (retrybtn) {
        retrybtn.addEventListener('click', () => {
            if (pending && !busy) {
                submit();
            }
        });
    }

    return {
        isBusy: () => busy || pending !== null,
    };
};
