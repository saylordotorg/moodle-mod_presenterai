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
 * Slide viewer, and the timeline of slide advances made while recording.
 *
 * Ported from Soapbox's soapbox_slides.js. One page image at a time, moved by
 * buttons, Arrow and Page keys, or a swipe. While a recording runs, every
 * advance is written to a timeline of {t, i} pairs, which the player replays
 * later. The timeline is a pure factory so it can be tested without a DOM.
 *
 * @module     mod_presenterai/slides
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString, getStrings} from 'core/str';

/** @var {number} Horizontal travel, in pixels, that counts as a swipe rather than a tap. */
const SWIPE_PX = 40;

/**
 * A slide advance timeline. Records {t, i} entries while active.
 *
 * @param {function} getElapsed Returns seconds into the recording.
 * @return {object} {start, stop, record, get, isActive}
 */
export const _makeTimeline = (getElapsed) => {
    let events = [];
    let active = false;
    let clock = getElapsed;

    return {
        /**
         * Begin a timeline at the slide showing now.
         *
         * @param {number} startIndex The slide index at time zero.
         * @param {function} [getElapsedOverride] A clock to use instead of the one given at creation.
         */
        start(startIndex, getElapsedOverride) {
            if (typeof getElapsedOverride === 'function') {
                clock = getElapsedOverride;
            }
            events = [{t: 0, i: startIndex || 0}];
            active = true;
        },

        /**
         * Stop recording advances; the events so far are kept.
         */
        stop() {
            active = false;
        },

        /**
         * Note that slide index is now showing.
         *
         * @param {number} index The slide index.
         */
        record(index) {
            if (!active) {
                return;
            }
            const t = Math.max(0, Math.round(clock()));
            const last = events[events.length - 1];
            // Collapse a same-second advance into the latest index, so a rapid
            // double tap does not leave two entries at the same timestamp.
            if (last && last.t === t) {
                last.i = index;
            } else {
                events.push({t: t, i: index});
            }
        },

        /**
         * A copy of the events so far.
         *
         * @return {Array} [{t, i}]
         */
        get() {
            return events.map((e) => ({t: e.t, i: e.i}));
        },

        /**
         * Whether advances are being recorded.
         *
         * @return {boolean}
         */
        isActive() {
            return active;
        },
    };
};

/**
 * Create a slide viewer in a container.
 *
 * @param {object} opts
 * @param {string[]} opts.pages Page images, as URLs or data URIs.
 * @param {HTMLElement} opts.container Where to render.
 * @param {function} [opts.getElapsed] Returns seconds into the recording.
 * @return {object} {next, prev, goTo, current, count, startCapture, stopCapture, getTimeline, destroy}
 */
export const create = (opts) => {
    const pages = opts.pages || [];
    const container = opts.container;
    const timeline = _makeTimeline(opts.getElapsed || (() => 0));
    let index = 0;
    let renderid = 0;

    const img = document.createElement('img');
    img.className = 'mod-presenterai-slide-img';
    img.alt = '';

    const counter = document.createElement('span');
    counter.className = 'mod-presenterai-slide-counter';

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'btn btn-secondary btn-sm';

    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'btn btn-secondary btn-sm';

    const controls = document.createElement('div');
    controls.className = 'mod-presenterai-slide-controls d-flex align-items-center';
    controls.append(prev, counter, next);

    getStrings([
        {key: 'slide_prev', component: 'mod_presenterai'},
        {key: 'slide_next', component: 'mod_presenterai'},
    ]).then(([prevlabel, nextlabel]) => {
        prev.textContent = prevlabel;
        next.textContent = nextlabel;
        return null;
    }).catch(() => null);

    const render = () => {
        if (pages.length) {
            img.src = pages[index];
        }
        prev.disabled = index <= 0;
        next.disabled = index >= pages.length - 1;
        // The counter doubles as the image's text alternative. A newer render
        // may finish first, so only the latest one is allowed to write.
        const mine = ++renderid;
        getString('slide_counter', 'mod_presenterai', {current: index + 1, total: pages.length})
            .then((text) => {
                if (mine === renderid) {
                    counter.textContent = text;
                    img.alt = text;
                }
                return null;
            })
            .catch(() => null);
    };

    const goTo = (i) => {
        const clamped = Math.max(0, Math.min(pages.length - 1, i));
        if (clamped === index) {
            return;
        }
        index = clamped;
        render();
        timeline.record(index);
    };
    const goNext = () => goTo(index + 1);
    const goPrev = () => goTo(index - 1);

    const onKey = (e) => {
        if (e.key === 'ArrowRight' || e.key === 'PageDown') {
            e.preventDefault();
            goNext();
        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
            e.preventDefault();
            goPrev();
        }
    };

    let touchx = null;
    const onTouchStart = (e) => {
        touchx = e.changedTouches[0].clientX;
    };
    const onTouchEnd = (e) => {
        if (touchx === null) {
            return;
        }
        const dx = e.changedTouches[0].clientX - touchx;
        if (Math.abs(dx) > SWIPE_PX) {
            if (dx < 0) {
                goNext();
            } else {
                goPrev();
            }
        }
        touchx = null;
    };

    next.addEventListener('click', goNext);
    prev.addEventListener('click', goPrev);
    // Focusable so the arrow keys work while a learner is speaking, without
    // having to aim for a small button.
    container.setAttribute('tabindex', '0');
    container.addEventListener('keydown', onKey);
    container.addEventListener('touchstart', onTouchStart, {passive: true});
    container.addEventListener('touchend', onTouchEnd);
    container.append(img, controls);
    render();

    return {
        next: goNext,
        prev: goPrev,
        goTo: goTo,
        current: () => index,
        count: () => pages.length,
        startCapture: (getElapsedOverride) => timeline.start(index, getElapsedOverride),
        stopCapture: () => timeline.stop(),
        getTimeline: () => timeline.get(),
        destroy: () => {
            container.removeEventListener('keydown', onKey);
            container.removeEventListener('touchstart', onTouchStart);
            container.removeEventListener('touchend', onTouchEnd);
            img.remove();
            controls.remove();
        },
    };
};
