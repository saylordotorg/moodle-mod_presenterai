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
 * The activity settings form's presentation type: defaults and the rubric preview.
 *
 * When the course designer picks a presentation type for a new activity,
 * the length range, camera recording, slides and starter topics change to
 * that type's defaults. On an existing activity changing the type changes
 * only the type and the rubric preview: its recording settings and topics
 * are the designer's and are never touched (data-isnew on the preview).
 * A topic title is replaced only while it's empty or still the previous
 * type's starter, so nothing the designer typed is overwritten. Defaults are
 * filled in only on a change the designer makes, never on load, so opening an
 * existing activity changes nothing.
 *
 * The rubric preview rendered by the form holds one block per choice; this
 * shows the one matching the presentation type and rubric selects. The
 * defaults travel as JSON in the preview's data-defaults attribute.
 *
 * @module     mod_presenterai/form
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Set a select or input's value and tell the form's own listeners (hideIf) it changed.
 *
 * @param {HTMLElement|null} el The element.
 * @param {string} value The new value.
 */
const setValue = (el, value) => {
    if (!el || el.value === value) {
        return;
    }
    el.value = value;
    el.dispatchEvent(new Event('change', {bubbles: true}));
};

/**
 * Set a duration element to a number of seconds, in minutes when that's whole.
 *
 * @param {HTMLFormElement} form The form.
 * @param {string} name The duration element's name.
 * @param {number} seconds The value.
 */
const setDuration = (form, name, seconds) => {
    const number = form.querySelector('[name="' + name + '[number]"]');
    const unit = form.querySelector('[name="' + name + '[timeunit]"]');
    if (!number || !unit) {
        return;
    }
    const minutes = seconds % 60 === 0;
    setValue(unit, minutes ? '60' : '1');
    setValue(number, String(minutes ? seconds / 60 : seconds));
};

/**
 * Wire the form.
 *
 * @param {string} previewSelector The rubric preview's root.
 */
export const init = (previewSelector) => {
    const preview = document.querySelector(previewSelector);
    const form = preview ? preview.closest('form') : null;
    if (!form) {
        return;
    }
    const typeselect = form.querySelector('select[name="presentationtype"]');
    const rubricselect = form.querySelector('select[name="rubricid"]');
    let defaults = {};
    try {
        defaults = JSON.parse(preview.dataset.defaults || '{}');
    } catch (e) {
        defaults = {};
    }

    const showPreview = () => {
        const rubricid = rubricselect ? parseInt(rubricselect.value, 10) || 0 : 0;
        const key = rubricid > 0 ? 'rubric:' + rubricid : 'type:' + (typeselect ? typeselect.value : '');
        preview.querySelectorAll('[data-previewfor]').forEach((item) => {
            item.hidden = item.dataset.previewfor !== key;
        });
    };

    let previous = typeselect ? typeselect.value : '';
    const applyDefaults = () => {
        const type = typeselect.value;
        const next = defaults[type];
        if (!next) {
            previous = type;
            return;
        }
        const before = defaults[previous] ? defaults[previous].topics : [];
        setValue(form.querySelector('select[name="mode"]'), next.mode);
        setDuration(form, 'minseconds', next.minseconds);
        setDuration(form, 'maxseconds', next.maxseconds);
        const slides = form.querySelector('input[type="checkbox"][name="slidesenabled"]');
        if (slides && slides.checked !== !!next.slidesenabled) {
            slides.checked = !!next.slidesenabled;
            slides.dispatchEvent(new Event('change', {bubbles: true}));
        }
        // A title is the designer's once they've changed it, so only empty
        // titles and the previous type's untouched starters are replaced.
        form.querySelectorAll('input[name^="topictitle["]').forEach((input) => {
            const index = parseInt(input.name.replace(/\D/g, ''), 10);
            const value = input.value.trim();
            if (value === '' || value === (before[index] || '')) {
                input.value = next.topics[index] || '';
            }
        });
        previous = type;
    };

    const isnew = preview.dataset.isnew === '1';
    if (typeselect) {
        typeselect.addEventListener('change', () => {
            if (isnew) {
                applyDefaults();
            }
            showPreview();
        });
    }
    if (rubricselect) {
        rubricselect.addEventListener('change', showPreview);
    }
    showPreview();
};
