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
 * Controller for the learner's activity page.
 *
 * Reads the recorder settings from the root element's data attributes, creates
 * the attempt row lazily on the first deck or the first Record press, runs the
 * optional deck flow, and wires Watch and Delete in the attempt list.
 *
 * One attempt row per page load. begin_attempt is called at most once and its
 * recording id is reused for the deck and the recording, because both belong
 * to the same attempt and the server reads both keys from that row. Calling it
 * lazily, rather than on page load, means a learner who only looks at their
 * attempts never creates a row.
 *
 * @module     mod_presenterai/view
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString, getStrings} from 'core/str';
import {upload} from 'mod_presenterai/uploader';
import * as Recorder from 'mod_presenterai/recorder';
import * as Slides from 'mod_presenterai/slides';
import * as Player from 'mod_presenterai/player';

/**
 * The recorder settings from the root element's data attributes.
 *
 * @param {HTMLElement} root The page root.
 * @return {object}
 */
const readConfig = (root) => {
    const d = root.dataset;
    const int = (value) => parseInt(value || '0', 10) || 0;
    return {
        cmid: int(d.cmid),
        mode: d.mode === 'audio' ? 'audio' : 'video',
        minseconds: int(d.minseconds),
        maxseconds: int(d.maxseconds),
        width: int(d.width),
        height: int(d.height),
        videokbps: int(d.videokbps),
        audiokbps: int(d.audiokbps),
        slides: d.slides === '1',
        maxbytes: int(d.maxbytes),
        topicid: int(d.topicid),
    };
};

/**
 * Wire the page.
 *
 * @param {string} rootSelector The page root, #mod-presenterai-view.
 */
export const init = (rootSelector) => {
    const root = document.querySelector(rootSelector);
    if (!root) {
        return;
    }
    const config = readConfig(root);
    const live = root.querySelector('[data-region="live"]');
    const playercontainer = root.querySelector('[data-region="player"]');

    /**
     * Speak a message through the live region that was in the page at load.
     *
     * Cleared first and written on the next tick, so the same sentence twice
     * in a row is still announced twice.
     *
     * @param {string} text The message.
     */
    const announce = (text) => {
        if (!live) {
            return;
        }
        live.textContent = '';
        window.setTimeout(() => {
            live.textContent = text;
        }, 50);
    };

    let attempt = null;
    const ensureAttempt = () => {
        if (!attempt) {
            attempt = Ajax.call([{
                methodname: 'mod_presenterai_begin_attempt',
                args: {cmid: config.cmid},
            }])[0].then((result) => result.recordingid).catch((err) => {
                // Let the next press try again rather than caching a failure.
                attempt = null;
                throw err;
            });
        }
        return attempt;
    };

    const getTopicId = () => {
        const select = root.querySelector('[data-region="topic-select"]');
        if (select) {
            return parseInt(select.value, 10) || 0;
        }
        return config.topicid;
    };

    let slides = null;
    const recorderroot = root.querySelector('[data-region="recorder"]');
    const deckinput = recorderroot ? recorderroot.querySelector('[data-region="deck-input"]') : null;
    const recbtn = recorderroot ? recorderroot.querySelector('[data-action="record"]') : null;

    // The recorder exists only when the server rendered one, which it does not
    // for a user without submit, at the cap, or with storage unconfigured.
    if (recorderroot && config.cmid && config.maxseconds) {
        Recorder.init(recorderroot, config, {
            ensureAttempt: ensureAttempt,
            getTopicId: getTopicId,
            getSlides: () => slides,
            announce: announce,
            onStateChange: (state) => {
                // Changing the deck mid recording would leave a timeline that
                // points at slides the recording never showed.
                if (deckinput) {
                    deckinput.disabled = state !== 'idle';
                }
            },
        });
    }

    if (config.slides && deckinput) {
        wireDeck(recorderroot, deckinput, recbtn, ensureAttempt, config, announce, (viewer) => {
            if (slides) {
                slides.destroy();
            }
            slides = viewer;
        });
    }

    root.addEventListener('click', (e) => {
        const watch = e.target.closest('[data-action="watch"]');
        if (watch && root.contains(watch) && playercontainer) {
            e.preventDefault();
            watchRecording(parseInt(watch.dataset.recid, 10), playercontainer, watch.getAttribute('aria-label') || '');
            return;
        }
        const del = e.target.closest('[data-action="delete"]');
        if (del && root.contains(del)) {
            e.preventDefault();
            deleteRecording(del, announce);
        }
    });
};

/**
 * Open the player and move focus to it.
 *
 * @param {number} recordingid The recording row id.
 * @param {HTMLElement} container The player container.
 * @param {string} label The Watch button's accessible name, reused for the media element.
 */
const watchRecording = async(recordingid, container, label) => {
    if (!recordingid) {
        return;
    }
    try {
        await Player.open(recordingid, container, {label: label});
        container.focus();
    } catch (err) {
        container.textContent = '';
        Notification.exception(err);
    }
};

/**
 * Upload a deck, render it, and hand the viewer back.
 *
 * The deck is optional: no deck, or a deck the server cannot preview, still
 * leaves Record available. Record is held only while a deck is in flight,
 * because the server commits a pending deck when the recording upload starts,
 * and a half sent deck at that moment would be dropped.
 *
 * @param {HTMLElement} recorderroot The recorder element.
 * @param {HTMLInputElement} input The deck file input.
 * @param {HTMLElement|null} recbtn The Record button.
 * @param {function} ensureAttempt Resolves to the recording id.
 * @param {object} config The page config.
 * @param {function} announce Speaks a message.
 * @param {function} onViewer Receives a new slide viewer.
 */
const wireDeck = (recorderroot, input, recbtn, ensureAttempt, config, announce, onViewer) => {
    const status = recorderroot.querySelector('[data-region="deck-status"]');
    const viewer = recorderroot.querySelector('[data-region="slide-viewer"]');
    const say = async(key, a) => {
        try {
            const text = await getString(key, 'mod_presenterai', a);
            if (status) {
                status.textContent = text;
            }
            announce(text);
        } catch (e) {
            // A missing string is not worth failing the deck over.
        }
    };

    input.addEventListener('change', async() => {
        const file = input.files && input.files[0];
        if (!file) {
            return;
        }
        if (file.type !== 'application/pdf' && !(/\.pdf$/i).test(file.name)) {
            await say('deck_choose_pdf');
            return;
        }

        input.disabled = true;
        const recwasdisabled = recbtn ? recbtn.disabled : true;
        if (recbtn) {
            recbtn.disabled = true;
        }
        try {
            const recordingid = await ensureAttempt();
            await say('deck_uploading');
            const target = await Ajax.call([{
                methodname: 'mod_presenterai_start_upload',
                args: {recordingid: recordingid, kind: 'deck', ext: 'pdf', sizebytes: file.size},
            }])[0];
            await upload(target, file, {
                cmid: config.cmid,
                recordingid: recordingid,
                contentType: 'application/pdf',
            });
            await say('deck_preparing');
            const rendered = await Ajax.call([{
                methodname: 'mod_presenterai_render_deck',
                args: {recordingid: recordingid},
            }])[0];
            if (viewer) {
                viewer.textContent = '';
            }
            if (rendered.available && rendered.pages && rendered.pages.length && viewer) {
                onViewer(Slides.create({pages: rendered.pages, container: viewer}));
                await say('deck_ready', rendered.pages.length);
            } else {
                // The deck is saved, but there is nothing to show while
                // speaking, so no advances can be recorded either.
                onViewer(null);
                await say('deck_nopreview');
            }
        } catch (err) {
            if (err && err.errorcode && typeof err.message === 'string') {
                if (status) {
                    status.textContent = err.message;
                }
                announce(err.message);
            } else {
                await say('deck_failed');
            }
        } finally {
            input.disabled = false;
            if (recbtn) {
                recbtn.disabled = recwasdisabled;
            }
        }
    });
};

/**
 * Confirm, delete a recording's media, and update its row in place.
 *
 * The attempt stays in the list with its date, length and status, because
 * deleting media does not delete the attempt (design 8.5). Its state cell
 * takes the sentence attempt_row would now render, and focus moves there, since
 * the button that had it is gone.
 *
 * @param {HTMLElement} button The Delete button.
 * @param {function} announce Speaks a message.
 */
const deleteRecording = async(button, announce) => {
    const recordingid = parseInt(button.dataset.recid, 10);
    if (!recordingid) {
        return;
    }
    const row = button.closest('tr');
    const [title, question, label, done, gonenote] = await getStrings([
        {key: 'delete_confirm_title', component: 'mod_presenterai'},
        {key: 'delete_confirm', component: 'mod_presenterai', param: button.dataset.recorded || ''},
        {key: 'delete', component: 'mod_presenterai'},
        {key: 'delete_done', component: 'mod_presenterai'},
        {key: 'attempt_gone_note', component: 'mod_presenterai'},
    ]);

    try {
        await Notification.deleteCancelPromise(title, question, label);
    } catch (e) {
        // Cancelled.
        return;
    }

    try {
        button.disabled = true;
        const result = await Ajax.call([{
            methodname: 'mod_presenterai_delete_recording',
            args: {recordingid: recordingid},
        }])[0];
        const text = await Player.goneText(result.mediagonereason, result.mediadeletedat);
        if (row) {
            const state = row.querySelector('[data-region="state"]');
            const actions = row.querySelector('[data-region="actions"]');
            row.classList.add('mod-presenterai-attempt-nomedia');
            if (actions) {
                actions.textContent = '';
                const note = document.createElement('span');
                note.className = 'mod-presenterai-gone-note';
                note.textContent = gonenote;
                actions.append(note);
            }
            if (state) {
                state.textContent = text;
                state.focus();
            }
        }
        announce(done);
    } catch (err) {
        button.disabled = false;
        Notification.exception(err);
    }
};
