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
 * The upload chunk ladder on the storage check page (DECISIONS.md D19).
 *
 * Walks up a ladder of real POST bodies and stops at the first one that does
 * not come back whole. The limit that bites on a stock install is usually a
 * reverse proxy in front of PHP (nginx's client_max_body_size defaults to 1m),
 * which answers 413 before PHP runs and which no ini setting reveals, so the
 * only way to find it is to send a body and see. The largest rung that came
 * back whole is saved as the site's chunk size.
 *
 * @module     mod_presenterai/probe
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';

/**
 * Build a probe.php URL for one action, carrying the session key.
 *
 * @param {string} base The probe.php URL from the page.
 * @param {Object} params Query parameters to add.
 * @returns {string}
 */
const actionUrl = (base, params) => {
    const url = new URL(base, window.location.href);
    Object.keys(params).forEach((name) => url.searchParams.set(name, params[name]));
    url.searchParams.set('sesskey', M.cfg.sesskey);
    return url.toString();
};

/**
 * POST one rung and say whether the server received every byte of it.
 *
 * A proxy's 413 has no JSON body, and a body PHP discarded for exceeding
 * post_max_size comes back as a short count, so both are failures here.
 *
 * @param {string} base The probe.php URL.
 * @param {number} bytes The body size to send.
 * @returns {Promise<boolean>}
 */
const tryRung = async(base, bytes) => {
    try {
        const response = await fetch(actionUrl(base, {action: 'chunk'}), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/octet-stream'},
            body: new Blob([new Uint8Array(bytes)]),
        });
        if (response.status !== 200) {
            return false;
        }
        const data = await response.json();
        return !!data && data.received === bytes;
    } catch (e) {
        return false;
    }
};

/**
 * Save the measured chunk size as the site setting.
 *
 * @param {string} base The probe.php URL.
 * @param {number} bytes The chunk size to save.
 * @returns {Promise<boolean>}
 */
const saveChunk = async(base, bytes) => {
    try {
        const response = await fetch(actionUrl(base, {action: 'save', chunkbytes: bytes}), {
            method: 'POST',
            credentials: 'same-origin',
        });
        if (response.status !== 200) {
            return false;
        }
        const data = await response.json();
        return !!data && data.saved === bytes;
    } catch (e) {
        return false;
    }
};

/**
 * Add one line to the results region, which announces it.
 *
 * @param {HTMLElement} region The live region.
 * @param {string} text What to say.
 */
const report = (region, text) => {
    const line = document.createElement('p');
    line.textContent = text;
    region.appendChild(line);
};

/**
 * Run the ladder, then save the largest rung that worked.
 *
 * @param {HTMLElement} root The page root carrying data-url and data-rungs.
 * @param {HTMLButtonElement} button The button that started the run.
 * @param {HTMLElement} region The live region for results.
 */
const measure = async(root, button, region) => {
    const base = root.dataset.url;
    let rungs = [];
    try {
        rungs = JSON.parse(root.dataset.rungs || '[]');
    } catch (e) {
        rungs = [];
    }

    button.disabled = true;
    region.textContent = '';
    report(region, await getString('probe_running', 'mod_presenterai'));

    let best = null;
    for (const rung of rungs) {
        const ok = await tryRung(base, rung.bytes);
        report(region, await getString(ok ? 'probe_rungok' : 'probe_rungfail', 'mod_presenterai', rung.label));
        if (!ok) {
            break;
        }
        best = rung;
    }

    if (best === null) {
        report(region, await getString('probe_nonesaved', 'mod_presenterai'));
    } else if (await saveChunk(base, best.bytes)) {
        report(region, await getString('probe_saved', 'mod_presenterai', best.label));
    } else {
        report(region, await getString('probe_savefailed', 'mod_presenterai'));
    }
    button.disabled = false;
};

/**
 * Wire up the Measure button.
 */
export const init = () => {
    const root = document.querySelector('[data-region="mod_presenterai-probe"]');
    if (!root) {
        return;
    }
    const button = root.querySelector('[data-action="measure"]');
    const region = root.querySelector('[data-region="probe-results"]');
    if (!button || !region) {
        return;
    }
    button.addEventListener('click', (e) => {
        e.preventDefault();
        measure(root, button, region);
    });
};
