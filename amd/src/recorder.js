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
 * devices) and WebM otherwise, and a min/max timer.
 *
 * Body language feedback (D17): when the activity takes still frames, Stop
 * builds one contact sheet in the browser (mod_presenterai/frames) and sends
 * it, best effort, before the recording's own start_upload, which is the
 * order the server's single upload id column needs. When the activity offers
 * the D24 opt out and the learner ticked it, frames.js is never called and no
 * sheet is sent; finalize carries visualoptout so the server deletes anything
 * that arrived anyway. The box is locked while a recording is under way, so
 * what the learner chose is what happened to that recording.
 *
 * Transcription: a camera recording also gets a second, audio only
 * MediaRecorder on the same stream's audio tracks, at a low bitrate (Opus
 * where the browser can), so a long video can still be transcribed under
 * OpenAI's 25 MB limit. After Stop it's sent, best effort, after the frames
 * and before the recording, which is the order the server's upload id column
 * needs. A browser that can't run two recorders just doesn't send one, and
 * the server falls back to the recording. An audio only activity's recording
 * is small already, so it isn't recorded twice.
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
import {contactSheet} from 'mod_presenterai/frames';

/** @var {number} Seconds before the maximum at which the timer warns. */
const NEAR_MAX_SECONDS = 15;

/** @var {number} How long the "uploaded" message shows before the page reloads. */
const RELOAD_DELAY_MS = 1500;

/** @var {number} How long Stop waits for the audio track's last data, in milliseconds. */
const AUDIO_TRACK_WAIT_MS = 3000;

/**
 * Pick a type for the separate audio track, Opus first because it's small at a low bitrate.
 *
 * @return {string} A mime type, or '' when the browser can't say.
 */
export const _pickAudioTrackMime = () => {
    const list = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
    if (!window.MediaRecorder || typeof window.MediaRecorder.isTypeSupported !== 'function') {
        return '';
    }
    return list.find((type) => window.MediaRecorder.isTypeSupported(type)) || '';
};

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
 * @param {number} [config.videovision] 1 when the activity takes still frames for body language feedback.
 * @param {number} [config.allowvisualoptout] 1 when the learner may opt an attempt out of it.
 * @param {number} [config.warmstt] 1 to warm the speech to text service when recording starts.
 * @param {number} [config.audiotrack] 1 to record a separate audio only track beside a camera recording.
 * @param {number} [config.audiotrackkbps] The audio track's bitrate in kbps.
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
    const optoutbox = config.allowvisualoptout ? root.querySelector('[data-region="visualoptout"]') : null;
    const notify = typeof hooks.onStateChange === 'function' ? hooks.onStateChange : () => undefined;
    const takesFrames = !!config.videovision && config.mode !== 'audio';
    const takesAudioTrack = !!config.audiotrack && config.mode !== 'audio';

    let stream = null;
    let recorder = null;
    let chunks = [];
    let startedat = 0;
    let timerid = null;
    let mime = '';
    // The separate audio only track, when this browser could start one.
    let audiorecorder = null;
    let audiochunks = [];
    let audiomime = '';
    let audiostopped = Promise.resolve();
    let busy = false;
    // The finished recording waiting to be sent, kept until the server has it.
    let pending = null;
    // Whether the speech to text service has been warmed on this page.
    let warmed = false;

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
        // The opt out applies to the recording being made, so it can't change
        // under one, nor between Stop and the server having it.
        if (optoutbox) {
            optoutbox.disabled = value || pending !== null;
        }
        notify(state);
    };

    /**
     * Whether the learner opted this recording out of body language feedback.
     *
     * @return {boolean}
     */
    const optedOut = () => !!(optoutbox && optoutbox.checked);

    /**
     * Warm the speech to text service once per page, ignoring the answer.
     */
    const warm = () => {
        if (!config.warmstt || warmed) {
            return;
        }
        warmed = true;
        try {
            Ajax.call([{
                methodname: 'mod_presenterai_warm_stt',
                args: {cmid: config.cmid},
            }])[0].catch(() => undefined);
        } catch (e) {
            // Warming is a courtesy; nothing depends on it.
        }
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
            /**
             * Send the frame sheet, best effort: any failure leaves the attempt without body language feedback.
             *
             * Tried once per attempt row. It must come before the recording's
             * start_upload, which the server refuses frames after.
             *
             * @param {object} attempt {recordingid, token}.
             * @return {Promise<void>}
             */
            const sendFrames = async(attempt) => {
                if (!pending.sheet || pending.framesTriedFor === attempt.recordingid) {
                    return;
                }
                pending.framesTriedFor = attempt.recordingid;
                try {
                    const sheet = await pending.sheet;
                    if (!sheet || !sheet.size) {
                        return;
                    }
                    const target = await Ajax.call([{
                        methodname: 'mod_presenterai_start_upload',
                        args: {recordingid: attempt.recordingid, kind: 'frames', ext: 'jpg', sizebytes: sheet.size,
                            attempttoken: attempt.token},
                    }])[0];
                    await upload(target, sheet, {
                        cmid: config.cmid,
                        recordingid: attempt.recordingid,
                        contentType: 'image/jpeg',
                    });
                } catch (e) {
                    // Body language feedback is the one thing lost; the recording goes on.
                }
            };
            /**
             * Send the audio only track, best effort: without it the server transcribes the recording.
             *
             * Tried once per attempt row, after the frames and before the
             * recording's start_upload, which the server refuses it after.
             *
             * @param {object} attempt {recordingid, token}.
             * @return {Promise<void>}
             */
            const sendAudioTrack = async(attempt) => {
                if (!pending.audio || pending.audioTriedFor === attempt.recordingid) {
                    return;
                }
                pending.audioTriedFor = attempt.recordingid;
                try {
                    const target = await Ajax.call([{
                        methodname: 'mod_presenterai_start_upload',
                        args: {recordingid: attempt.recordingid, kind: 'audio', ext: pending.audio.ext,
                            sizebytes: pending.audio.blob.size, attempttoken: attempt.token},
                    }])[0];
                    await upload(target, pending.audio.blob, {
                        cmid: config.cmid,
                        recordingid: attempt.recordingid,
                        contentType: pending.audio.mime,
                    });
                } catch (e) {
                    // The recording itself is still sent and can be transcribed from.
                }
            };
            const send = async(attempt) => {
                const recordingid = attempt.recordingid;
                await sendFrames(attempt);
                await sendAudioTrack(attempt);
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
                    visualoptout: pending.optout ? 1 : 0,
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
            if (optoutbox) {
                optoutbox.disabled = false;
            }
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
                if (optoutbox) {
                    optoutbox.disabled = false;
                }
                return;
            }
            show(retrywrap, true);
            if (recbtn) {
                // Starting again would replace the recording waiting in Retry.
                recbtn.disabled = true;
            }
        }
    };

    /**
     * The audio track's recording, once its recorder has handed over its last data, or null.
     *
     * @return {Promise<object|null>} {blob, mime, ext}, or null when there's no usable track.
     */
    const takeAudioTrack = async() => {
        if (!audiorecorder) {
            return null;
        }
        // A recorder that never stops cleanly mustn't hold up the recording.
        await Promise.race([audiostopped, new Promise((resolve) => window.setTimeout(resolve, AUDIO_TRACK_WAIT_MS))]);
        const type = audiomime || 'audio/webm';
        const blob = new Blob(audiochunks, {type: type});
        audiorecorder = null;
        audiochunks = [];
        return blob.size ? {blob: blob, mime: type, ext: _extFor(type)} : null;
    };

    const handleStop = async() => {
        const elapsed = Math.floor((Date.now() - startedat) / 1000);
        const audio = await takeAudioTrack();
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
        const blob = new Blob(chunks, {type: type});
        const optout = optedOut();
        pending = {
            blob: blob,
            mime: type,
            ext: _extFor(type),
            duration: elapsed,
            timeline: slides ? JSON.stringify(slides.getTimeline()) : '',
            optout: optout,
            // D24: an opted out recording is never sampled, so there is nothing to send.
            sheet: takesFrames && !optout ? contactSheet(blob, {durationSeconds: elapsed}) : null,
            framesTriedFor: 0,
            audio: audio,
            audioTriedFor: 0,
        };
        chunks = [];
        await submit();
    };

    const stop = () => {
        stopTimer();
        if (stopbtn) {
            stopbtn.disabled = true;
        }
        if (audiorecorder && audiorecorder.state !== 'inactive') {
            try {
                audiorecorder.stop();
            } catch (e) {
                // Its data is optional; the recording goes on without it.
            }
        }
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    /**
     * Start the audio only recorder on the stream's audio tracks, or leave it off.
     *
     * Any failure here just means no separate track: the browser may not
     * allow a second recorder, or may not support the type.
     */
    const startAudioTrack = () => {
        audiorecorder = null;
        audiochunks = [];
        audiostopped = Promise.resolve();
        if (!takesAudioTrack || !stream || typeof window.MediaStream !== 'function') {
            return;
        }
        try {
            const tracks = stream.getAudioTracks();
            if (!tracks.length) {
                return;
            }
            audiomime = _pickAudioTrackMime();
            const opts = {audioBitsPerSecond: (config.audiotrackkbps || 32) * 1000};
            if (audiomime) {
                opts.mimeType = audiomime;
            }
            const track = new window.MediaRecorder(new window.MediaStream(tracks), opts);
            audiomime = track.mimeType || audiomime;
            track.ondataavailable = (e) => {
                if (e.data && e.data.size) {
                    audiochunks.push(e.data);
                }
            };
            audiostopped = new Promise((resolve) => {
                track.onstop = resolve;
                track.onerror = resolve;
            });
            track.start();
            audiorecorder = track;
        } catch (e) {
            audiorecorder = null;
            audiostopped = Promise.resolve();
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
            startAudioTrack();
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
        warm();
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
