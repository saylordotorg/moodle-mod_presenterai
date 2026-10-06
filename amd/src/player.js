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
 * Play a recording back, with its slides following the stored timeline.
 *
 * Ported from Soapbox's soapbox_player.js, with two changes the design asks
 * for. A recording whose media is gone is a sentence, not an error: get_playback
 * returns a structured "media gone" answer and this renders why (design 8.5).
 * And because an S3 playback URL lives 300 seconds (plan 4.6), a media error is
 * answered once by asking for a fresh URL and resuming where playback stopped,
 * so a learner who pauses for six minutes does not find a dead player.
 *
 * @module     mod_presenterai/player
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString, getStrings} from 'core/str';
import UserDate from 'core/user_date';

/** @var {string[]} Reasons whose sentence takes the deletion date, as in attempt_row. */
const GONE_WITH_DATE = ['pruned', 'manual', 'learner'];

/** @var {string[]} Reasons with their own sentence and no date. */
const GONE_WITHOUT_DATE = ['missing', 'notbackedup'];

/**
 * The slide showing at time t: the last timeline entry at or before it.
 *
 * @param {Array} timeline [{t, i}] sorted by t, as the server stores it.
 * @param {number} t Seconds into playback.
 * @return {number} The slide index, 0 before the first entry.
 */
export const _slideForTime = (timeline, t) => {
    let idx = 0;
    for (let k = 0; k < timeline.length; k++) {
        if (timeline[k].t <= t) {
            idx = timeline[k].i;
        } else {
            break;
        }
    }
    return idx;
};

/**
 * Which attempt_* string describes media that is gone, mirroring attempt_row::state().
 *
 * Kept in step with the PHP by hand, because the two render the same rows: the
 * list on page load from PHP, and a row the learner just deleted from here.
 *
 * @param {string} reason The row's mediagonereason, possibly empty.
 * @param {number} deletedat The row's mediadeletedat.
 * @return {object} {key, dated}
 */
export const _goneState = (reason, deletedat) => {
    if (!deletedat) {
        return {key: 'attempt_deleted', dated: false};
    }
    if (GONE_WITH_DATE.indexOf(reason) !== -1) {
        return {key: 'attempt_gone_' + reason, dated: true};
    }
    if (GONE_WITHOUT_DATE.indexOf(reason) !== -1) {
        return {key: 'attempt_gone_' + reason, dated: false};
    }
    return {key: 'attempt_deleted_on', dated: true};
};

/**
 * The sentence for media that is gone, with the date formatted by the server.
 *
 * The date goes through core/user_date so it reads exactly like the dates PHP
 * rendered in the same table, in the user's own timezone.
 *
 * @param {string} reason The row's mediagonereason.
 * @param {number} deletedat The row's mediadeletedat.
 * @return {Promise<string>}
 */
export const goneText = async(reason, deletedat) => {
    const state = _goneState(reason || '', deletedat || 0);
    if (!state.dated) {
        return getString(state.key, 'mod_presenterai');
    }
    const format = await getString('strftimedatefullshort', 'langconfig');
    const [date] = await UserDate.get([{timestamp: deletedat, format: format}]);
    return getString(state.key, 'mod_presenterai', date);
};

/**
 * Ask the server how to play a recording.
 *
 * @param {number} recordingid The recording row id.
 * @return {Promise<object>} The get_playback result.
 */
const fetchPlayback = (recordingid) => Ajax.call([{
    methodname: 'mod_presenterai_get_playback',
    args: {recordingid: recordingid},
}])[0];

/**
 * Replace the container's content with the media gone sentences.
 *
 * @param {HTMLElement} container The player container.
 * @param {object} data A get_playback result with mediaavailable false.
 * @return {Promise<void>}
 */
const renderGone = async(container, data) => {
    const [heading, reason] = await Promise.all([
        getString('player_media_gone', 'mod_presenterai'),
        goneText(data.mediagonereason, data.mediadeletedat),
    ]);
    container.textContent = '';
    const p = document.createElement('p');
    p.className = 'mod-presenterai-player-gone';
    p.textContent = heading + ' ' + reason;
    container.append(p);
};

/**
 * Open a recording in a container.
 *
 * @param {number} recordingid The recording row id.
 * @param {HTMLElement} container Where the player goes; its content is replaced.
 * @param {object} [options]
 * @param {string} [options.label] An accessible name for the media element, such as watch_aria.
 * @return {Promise<object>} Resolves with the get_playback result.
 */
export const open = async(recordingid, container, {label = ''} = {}) => {
    const [loading] = await getStrings([{key: 'player_loading', component: 'mod_presenterai'}]);
    container.textContent = loading;

    const data = await fetchPlayback(recordingid);
    if (!data.mediaavailable) {
        await renderGone(container, data);
        return data;
    }

    let timeline = [];
    try {
        timeline = JSON.parse(data.timeline || '[]');
    } catch (e) {
        timeline = [];
    }
    if (!Array.isArray(timeline)) {
        timeline = [];
    }

    const media = document.createElement(data.mode === 'audio' ? 'audio' : 'video');
    media.controls = true;
    media.preload = 'metadata';
    media.src = data.mediaurl;
    media.className = 'mod-presenterai-play-media';
    if (label) {
        media.setAttribute('aria-label', label);
    }
    if (data.mode !== 'audio') {
        media.setAttribute('playsinline', 'playsinline');
    }

    // One fresh URL per open, not a loop: a second failure is a real failure
    // and the browser's own player shows it.
    let refreshed = false;
    media.addEventListener('error', async() => {
        if (refreshed) {
            return;
        }
        refreshed = true;
        const resumeat = media.currentTime || 0;
        try {
            const fresh = await fetchPlayback(recordingid);
            if (!fresh.mediaavailable) {
                await renderGone(container, fresh);
                return;
            }
            media.addEventListener('loadedmetadata', () => {
                media.currentTime = resumeat;
            }, {once: true});
            media.src = fresh.mediaurl;
            media.load();
        } catch (e) {
            // Leave the player as it is; its own error state is showing.
        }
    });

    const wrap = document.createElement('div');
    wrap.className = 'mod-presenterai-play d-flex flex-wrap';
    const mediacol = document.createElement('div');
    mediacol.className = 'mod-presenterai-play-col';
    mediacol.append(media);
    wrap.append(mediacol);

    const pages = Array.isArray(data.pages) ? data.pages : [];
    if (pages.length) {
        const slidecol = document.createElement('div');
        slidecol.className = 'mod-presenterai-play-col';
        const slide = document.createElement('img');
        slide.className = 'mod-presenterai-play-slide';
        slide.alt = '';
        slide.src = pages[0];
        slidecol.append(slide);
        wrap.append(slidecol);

        const sync = () => {
            const i = _slideForTime(timeline, media.currentTime);
            if (pages[i] && slide.src !== pages[i]) {
                slide.src = pages[i];
            }
        };
        media.addEventListener('timeupdate', sync);
        media.addEventListener('seeked', sync);
    }

    container.textContent = '';
    container.append(wrap);
    return data;
};
