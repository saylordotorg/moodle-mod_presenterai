<?php
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

namespace mod_presenterai\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_presenterai\local\rubric_manager;

/**
 * Create or edit one rubric on rubric.php.
 *
 * A title, a type, whether it's active, and repeated rows of criteria: a name,
 * a description, a maximum from 1 to 10, whether the criterion is visual, and
 * five written levels (0.6.0), each a label and a description, prefilled with
 * the default labels. "Start from a preset" is a no-submit button: rubric.php
 * sees it pressed and reloads the page with the preset's criteria filled in,
 * which the teacher then edits and saves like any other.
 *
 * A criterion has levels when at least one level description is written. It
 * is then scored out of 5, and each level that has a description needs a
 * label. A criterion whose level descriptions are all empty is stored without
 * levels, exactly as before 0.6.0, whatever its labels say.
 *
 * The visual flag is what rubric_manager resolves and score rows carry, so it
 * has to survive a save and a reload exactly. SOLA's editor rebuilt each
 * criterion from name, description and maximum only and would have dropped it
 * on the first save (plan 3.4); this one round trips it, and refuses a visual
 * row on a speech rubric, which an audio activity also uses.
 *
 * Custom data: 'rows' (int, rows to show), 'fixedtype' (string, the type of an
 * existing rubric, which can't change), 'hidden' (array of name => value to
 * carry through the page; 'returnurl' is cleaned as a local URL).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_form extends \moodleform {
    /** @var int Blank rows offered on a new rubric. */
    public const NEW_ROWS = 3;

    /**
     * The form elements.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        foreach ((array) ($this->_customdata['hidden'] ?? []) as $name => $value) {
            $mform->addElement('hidden', $name, $value);
            if ($name === 'returnurl') {
                $mform->setType($name, PARAM_LOCALURL);
            } else {
                $mform->setType($name, is_int($value) ? PARAM_INT : PARAM_ALPHANUMEXT);
            }
        }

        $mform->addElement('header', 'rubricheading', get_string('rubricdetails', 'mod_presenterai'));

        $mform->addElement('text', 'title', get_string('rubrictitle', 'mod_presenterai'), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // A required select's first option is the empty value, so nothing is chosen by default.
        $mform->addElement('select', 'type', get_string('rubrictype', 'mod_presenterai'), [
            '' => get_string('choosedots'),
            rubric_manager::TYPE_VIDEO => get_string('rubrictype_video', 'mod_presenterai'),
            rubric_manager::TYPE_SPEECH => get_string('rubrictype_speech', 'mod_presenterai'),
        ]);
        $mform->setType('type', PARAM_ALPHA);
        $mform->addRule('type', null, 'required', null, 'client');
        $mform->addHelpButton('type', 'rubrictype', 'mod_presenterai');
        $fixedtype = $this->fixed_type();
        if ($fixedtype !== '') {
            $mform->setDefault('type', $fixedtype);
            $mform->hardFreeze('type');
        }

        $mform->addElement('advcheckbox', 'active', get_string('rubricactive', 'mod_presenterai'));
        $mform->setDefault('active', 1);
        $mform->addHelpButton('active', 'rubricactive', 'mod_presenterai');

        $presets = ['' => get_string('choosedots')];
        foreach (rubric_manager::PRESETS as $preset) {
            $presets[$preset] = get_string('presentationtype_' . $preset, 'mod_presenterai');
        }
        // The general rubric every activity used before 0.6.0, last, for anyone matching an older activity.
        $presets[rubric_manager::LEVEL_GENERAL] = get_string('presentationtype_general', 'mod_presenterai');
        $group = [
            $mform->createElement('select', 'preset', get_string('rubricpreset', 'mod_presenterai'), $presets),
            $mform->createElement('submit', 'applypreset', get_string('rubricpreset_apply', 'mod_presenterai')),
        ];
        $mform->addGroup($group, 'presetgroup', get_string('rubricpreset', 'mod_presenterai'), ' ', false);
        $mform->setType('preset', PARAM_ALPHANUMEXT);
        $mform->registerNoSubmitButton('applypreset');
        $mform->addHelpButton('presetgroup', 'rubricpreset', 'mod_presenterai');

        $maxoptions = [];
        for ($n = 1; $n <= rubric_manager::MAX_CRITERION_SCORE; $n++) {
            $maxoptions[$n] = (string) $n;
        }
        $row = [
            $mform->createElement('header', 'criterionheading', get_string('rubriccriterion', 'mod_presenterai', '{no}')),
            $mform->createElement('text', 'criterionname', get_string('rubriccriterionname', 'mod_presenterai'), ['size' => 50]),
            $mform->createElement(
                'textarea',
                'criteriondesc',
                get_string('rubriccriteriondesc', 'mod_presenterai'),
                ['rows' => 3, 'cols' => 60]
            ),
            $mform->createElement('select', 'criterionmax', get_string('rubriccriterionmax', 'mod_presenterai'), $maxoptions),
            $mform->createElement('advcheckbox', 'criterionvisual', get_string('rubriccriterionvisual', 'mod_presenterai')),
        ];
        $options = [
            'criterionheading' => ['expanded' => true],
            'criterionname' => ['type' => PARAM_TEXT],
            'criteriondesc' => ['type' => PARAM_TEXT],
            'criterionmax' => ['type' => PARAM_INT, 'default' => rubric_manager::DEFAULT_MAX_SCORE],
            'criterionvisual' => [
                'type' => PARAM_INT,
                'helpbutton' => ['rubriccriterionvisual', 'mod_presenterai'],
                'hideif' => ['type', 'eq', rubric_manager::TYPE_SPEECH],
            ],
        ];
        // Five level rows per criterion, 5 first, each a label and a description.
        $labels = rubric_manager::default_level_labels();
        foreach (rubric_manager::LEVEL_SCORES as $score) {
            $row[] = $mform->createElement(
                'text',
                'level' . $score . 'label',
                get_string('rubriclevellabel', 'mod_presenterai', $score),
                ['size' => 20]
            );
            $row[] = $mform->createElement(
                'textarea',
                'level' . $score . 'desc',
                get_string('rubricleveldesc', 'mod_presenterai', $score),
                ['rows' => 2, 'cols' => 60]
            );
            $options['level' . $score . 'label'] = ['type' => PARAM_TEXT, 'default' => $labels[$score]];
            $options['level' . $score . 'desc'] = ['type' => PARAM_TEXT];
        }
        $options['level5label']['helpbutton'] = ['rubriclevels', 'mod_presenterai'];
        $this->repeat_elements(
            $row,
            max(1, (int) ($this->_customdata['rows'] ?? self::NEW_ROWS)),
            $options,
            'criteria_repeats',
            'criteria_add',
            1,
            get_string('rubricaddcriterion', 'mod_presenterai'),
            true
        );

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Check the rubric: a type, at least one named criterion, unique names, visual rows only on video,
     * and, for a criterion with written levels, a label on every described level and a maximum of 5.
     *
     * @param array $data The submitted values.
     * @param array $files Unused.
     * @return array Element name => error.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $type = $this->fixed_type() !== '' ? $this->fixed_type() : (string) ($data['type'] ?? '');
        if (!in_array($type, [rubric_manager::TYPE_SPEECH, rubric_manager::TYPE_VIDEO], true)) {
            $errors['type'] = get_string('rubricerror_type', 'mod_presenterai');
        }

        $names = (array) ($data['criterionname'] ?? []);
        $descs = (array) ($data['criteriondesc'] ?? []);
        $maxes = (array) ($data['criterionmax'] ?? []);
        $visuals = (array) ($data['criterionvisual'] ?? []);
        $seen = [];
        foreach (array_keys($names + $descs) as $i) {
            $name = trim((string) ($names[$i] ?? ''));
            $desc = trim((string) ($descs[$i] ?? ''));
            if ($name === '') {
                if ($desc !== '') {
                    $errors["criterionname[$i]"] = get_string('rubricerror_noname', 'mod_presenterai');
                }
                continue;
            }
            $key = rubric_manager::normalise_name($name);
            if (isset($seen[$key])) {
                $errors["criterionname[$i]"] = get_string('rubricerror_duplicate', 'mod_presenterai');
            }
            $seen[$key] = true;
            $max = (int) ($maxes[$i] ?? 0);
            if ($max < 1 || $max > rubric_manager::MAX_CRITERION_SCORE) {
                $errors["criterionmax[$i]"] = get_string('rubricerror_max', 'mod_presenterai');
            }
            if (!empty($visuals[$i]) && $type === rubric_manager::TYPE_SPEECH) {
                $errors["criterionvisual[$i]"] = get_string('rubricerror_visualspeech', 'mod_presenterai');
            }
            $levels = self::row_levels($data, $i);
            if ($levels === null) {
                continue;
            }
            foreach ($levels as $level) {
                if ($level['description'] !== '' && $level['label'] === '') {
                    $errors['level' . $level['score'] . "label[$i]"] = get_string('rubricerror_levellabel', 'mod_presenterai');
                }
            }
            if ($max !== rubric_manager::LEVELS_MAX_SCORE && !isset($errors["criterionmax[$i]"])) {
                $errors["criterionmax[$i]"] = get_string('rubricerror_levelsmax', 'mod_presenterai');
            }
        }
        if (empty($seen)) {
            $errors['criterionname[0]'] = get_string('rubricerror_nocriteria', 'mod_presenterai');
        }

        return $errors;
    }

    /**
     * The criteria the submitted rows describe, blank rows left out.
     *
     * A row with at least one level description gets 'levels' and a maximum
     * of 5; an empty label there takes the default one, which validation only
     * allows on a level with no description.
     *
     * @param \stdClass $data The data get_data() returned.
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'], plus 'levels' where written.
     */
    public function to_criteria(\stdClass $data): array {
        $names = (array) ($data->criterionname ?? []);
        $descs = (array) ($data->criteriondesc ?? []);
        $maxes = (array) ($data->criterionmax ?? []);
        $visuals = (array) ($data->criterionvisual ?? []);
        $type = $this->fixed_type() !== '' ? $this->fixed_type() : (string) ($data->type ?? '');

        $out = [];
        foreach (array_keys($names) as $i) {
            $name = trim((string) $names[$i]);
            if ($name === '') {
                continue;
            }
            $criterion = [
                'name' => $name,
                'description' => trim((string) ($descs[$i] ?? '')),
                'max_score' => max(1, min(rubric_manager::MAX_CRITERION_SCORE, (int) ($maxes[$i] ?? 0))),
                'visual' => $type === rubric_manager::TYPE_VIDEO && !empty($visuals[$i]),
            ];
            $levels = self::row_levels((array) $data, $i);
            if ($levels !== null) {
                $defaults = rubric_manager::default_level_labels();
                foreach ($levels as $n => $level) {
                    if ($level['label'] === '') {
                        $levels[$n]['label'] = $defaults[$level['score']];
                    }
                }
                $criterion['max_score'] = rubric_manager::LEVELS_MAX_SCORE;
                $criterion['levels'] = $levels;
            }
            $out[] = $criterion;
        }
        return $out;
    }

    /**
     * The written levels of one submitted row, or null when none of its level descriptions is filled in.
     *
     * @param array $data The submitted values, or get_data() cast to an array.
     * @param int|string $i The row's index.
     * @return array[]|null A list of ['score', 'label', 'description'], 5 first, labels trimmed and possibly empty.
     */
    private static function row_levels(array $data, $i): ?array {
        $levels = [];
        $written = false;
        foreach (rubric_manager::LEVEL_SCORES as $score) {
            $labels = (array) ($data['level' . $score . 'label'] ?? []);
            $descs = (array) ($data['level' . $score . 'desc'] ?? []);
            $description = trim((string) ($descs[$i] ?? ''));
            $written = $written || $description !== '';
            $levels[] = [
                'score' => $score,
                'label' => trim((string) ($labels[$i] ?? '')),
                'description' => $description,
            ];
        }
        return $written ? $levels : null;
    }

    /**
     * The submitted type, or the fixed type of an existing rubric.
     *
     * @param \stdClass $data The data get_data() returned.
     * @return string
     */
    public function to_type(\stdClass $data): string {
        return $this->fixed_type() !== '' ? $this->fixed_type() : (string) ($data->type ?? '');
    }

    /**
     * Fill the form from a title, type, active flag and criteria list.
     *
     * @param string $title The title.
     * @param string $type The type, or '' to leave it unchosen.
     * @param bool $active Whether the rubric is active.
     * @param array $criteria A list of ['name', 'description', 'max_score', 'visual'], plus 'levels' where written.
     * @return void
     */
    public function set_rubric(string $title, string $type, bool $active, array $criteria): void {
        $defaults = [
            'title' => $title,
            'active' => $active ? 1 : 0,
        ];
        if ($this->fixed_type() === '' && $type !== '') {
            $defaults['type'] = $type;
        }
        foreach (array_values(rubric_manager::normalise_criteria($criteria)) as $i => $criterion) {
            $defaults["criterionname[$i]"] = $criterion['name'];
            $defaults["criteriondesc[$i]"] = $criterion['description'];
            $defaults["criterionmax[$i]"] = min(rubric_manager::MAX_CRITERION_SCORE, $criterion['max_score']);
            $defaults["criterionvisual[$i]"] = $criterion['visual'] ? 1 : 0;
            // A criterion without levels shows the default labels and empty
            // descriptions, which save back as no levels.
            $labels = rubric_manager::default_level_labels();
            $written = [];
            foreach ($criterion['levels'] ?? [] as $level) {
                $written[(int) $level['score']] = $level;
            }
            foreach (rubric_manager::LEVEL_SCORES as $score) {
                $defaults['level' . $score . "label[$i]"] = $written[$score]['label'] ?? $labels[$score];
                $defaults['level' . $score . "desc[$i]"] = $written[$score]['description'] ?? '';
            }
        }
        $this->set_data($defaults);
    }

    /**
     * Rows to show for a criteria list: one per criterion plus a blank one.
     *
     * @param array $criteria The criteria the form will be filled with.
     * @return int
     */
    public static function rows_for(array $criteria): int {
        return empty($criteria) ? self::NEW_ROWS : count($criteria) + 1;
    }

    /**
     * The fixed type of an existing rubric, or '' for a new one.
     *
     * @return string
     */
    private function fixed_type(): string {
        return (string) ($this->_customdata['fixedtype'] ?? '');
    }
}
