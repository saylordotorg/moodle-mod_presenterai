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
 * Controller for the grading screen.
 *
 * Opens the shared player into the page's player region and keeps a live
 * total beside the form as the grader scores. The total is a preview only:
 * the server recomputes and stores its own when the form is saved, so this
 * file decides nothing.
 *
 * @module     mod_presenterai/grading
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getString} from 'core/str';
import * as Player from 'mod_presenterai/player';

/**
 * A percentage rounded to 2 decimals, the way grader::percent() rounds it.
 *
 * PHP's round() rounds half away from zero; Math.round() rounds half up,
 * which is the same for the non-negative values a total can have. The
 * epsilon keeps 1.005 from becoming 1.00 through floating point.
 *
 * @param {number} sum The points awarded.
 * @param {number} max The points available.
 * @return {number|null} The percentage, or null when nothing is available.
 */
export const _percent = (sum, max) => {
    if (max <= 0) {
        return null;
    }
    return Math.round((100 * sum / max + Number.EPSILON) * 100) / 100;
};

/**
 * Sum the chosen scores of the assessed criteria in a form.
 *
 * A criterion counts only when its assessed box is ticked and a score is
 * chosen, so an unassessed criterion leaves both the sum and the maximum.
 *
 * @param {HTMLElement} root The page root.
 * @return {object} {sum, max, chosen}
 */
export const _total = (root) => {
    let sum = 0;
    let max = 0;
    let chosen = 0;
    root.querySelectorAll('select[data-criterion]').forEach((select) => {
        const index = select.dataset.criterion;
        const box = root.querySelector('input[type="checkbox"][data-assessed="' + index + '"]');
        if (box && !box.checked) {
            return;
        }
        if (select.value === '') {
            return;
        }
        sum += parseInt(select.value, 10) || 0;
        max += parseInt(select.dataset.max, 10) || 0;
        chosen++;
    });
    return {sum: sum, max: max, chosen: chosen};
};

/**
 * Write the current total into the total region.
 *
 * @param {HTMLElement} root The page root.
 * @param {HTMLElement} region The total region.
 * @return {Promise<void>}
 */
const renderTotal = async(root, region) => {
    const total = _total(root);
    const pct = _percent(total.sum, total.max);
    if (total.chosen === 0 || pct === null) {
        region.textContent = await getString('grade_total_none', 'mod_presenterai');
        return;
    }
    region.textContent = await getString('grade_total', 'mod_presenterai', {
        sum: total.sum,
        max: total.max,
        pct: pct.toFixed(2),
    });
};

/**
 * Wire the grading screen.
 *
 * @param {string} rootSelector The page root's selector.
 */
export const init = (rootSelector) => {
    const root = document.querySelector(rootSelector);
    if (!root) {
        return;
    }

    const container = root.querySelector('[data-region="player"]');
    if (container) {
        Player.open(parseInt(root.dataset.recordingid, 10), container, {label: root.dataset.watcharia || ''})
            .catch((err) => {
                container.textContent = '';
                Notification.exception(err);
            });
    }

    const region = root.querySelector('[data-region="total"]');
    if (!region) {
        return;
    }
    root.addEventListener('change', (e) => {
        if (e.target.closest('select[data-criterion], input[data-assessed]')) {
            renderTotal(root, region);
        }
    });
    // A form redisplayed after a failed save, or prefilled from a saved
    // score, already has choices in it.
    renderTotal(root, region);
};
