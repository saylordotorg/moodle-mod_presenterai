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

use mod_presenterai\local\score_manager;

/**
 * The scoring form for one attempt on grade.php.
 *
 * The rubric comes in as custom data, already resolved on the server, and it
 * is the only source of criterion names and maxima: to_criteria() never reads
 * either from the POST, so a tampered request can't award marks out of a
 * different total. Each criterion has an "assessed" switch because a criterion
 * that doesn't apply to a talk must leave the total entirely rather than score
 * zero, which is how rawmax is defined (plan section 2.2).
 *
 * The score select starts on an empty "Choose..." option rather than 0, so a
 * grader who skips a criterion is told so instead of silently awarding nothing.
 *
 * A criterion whose counts flag is false (D23: the visual criteria, unless the
 * activity scores them) is labeled feedback only and its select carries
 * data-counts="0", so the live total in mod_presenterai/grading leaves it out
 * just as grader::sums() does when the score is saved.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_form extends \moodleform {
    /**
     * The form elements.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $criteria = $this->criteria();

        $mform->addElement('hidden', 'id', (int) $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'recordingid', (int) $this->_customdata['recordingid']);
        $mform->setType('recordingid', PARAM_INT);

        foreach ($criteria as $i => $criterion) {
            $max = max(0, (int) $criterion['max_score']);
            $counts = ($criterion['counts'] ?? true) !== false;

            $mform->addElement('header', 'criterion_' . $i, format_string($criterion['name']));
            $mform->setExpanded('criterion_' . $i, true);
            if (trim((string) ($criterion['description'] ?? '')) !== '') {
                $mform->addElement('static', 'criteriondesc_' . $i, '', s($criterion['description']));
            }
            if (!$counts) {
                $mform->addElement(
                    'static',
                    'criterionfeedbackonly_' . $i,
                    '',
                    get_string('grade_feedbackonly', 'mod_presenterai')
                );
            }

            $mform->addElement(
                'advcheckbox',
                "assessed[$i]",
                get_string('grade_assessed', 'mod_presenterai'),
                '',
                ['data-assessed' => $i],
                [0, 1]
            );
            $mform->setDefault("assessed[$i]", 1);

            $options = ['' => get_string('choosedots')];
            for ($n = 0; $n <= $max; $n++) {
                $options[$n] = (string) $n;
            }
            $mform->addElement(
                'select',
                "score[$i]",
                get_string('col_score', 'mod_presenterai'),
                $options,
                ['data-criterion' => $i, 'data-max' => $max, 'data-counts' => $counts ? 1 : 0]
            );
            $mform->setDefault("score[$i]", '');
            $mform->disabledIf("score[$i]", "assessed[$i]", 'notchecked');

            $mform->addElement('textarea', "criterionfeedback[$i]", get_string('feedback'), ['rows' => 3, 'cols' => 60]);
        }
        $mform->setType('assessed', PARAM_INT);
        // Raw, then checked by validation() against the rubric's maximum.
        $mform->setType('score', PARAM_RAW_TRIMMED);
        $mform->setType('criterionfeedback', PARAM_TEXT);

        $mform->addElement('header', 'overall', get_string('grade_overallfeedback', 'mod_presenterai'));
        $mform->setExpanded('overall', true);
        $mform->addElement(
            'textarea',
            'feedback',
            get_string('grade_overallfeedback', 'mod_presenterai'),
            ['rows' => 5, 'cols' => 60]
        );
        $mform->setType('feedback', PARAM_TEXT);

        $outcomes = $this->_customdata['outcomes'] ?? [];
        if ($outcomes) {
            $mform->addElement('header', 'outcomes', get_string('outcomes', 'grades'));
            $mform->setExpanded('outcomes', true);
            foreach ($outcomes as $outcome) {
                $name = 'outcome_' . (int) $outcome['itemnumber'];
                // Not required, so the first option is the real "No outcome" value 0.
                $mform->addElement('select', $name, $outcome['name'], $outcome['options']);
                $mform->setType($name, PARAM_INT);
                $mform->setDefault($name, (int) $outcome['current']);
            }
        }

        $this->add_action_buttons(true, get_string('grade_save', 'mod_presenterai'));
    }

    /**
     * Every assessed criterion needs a whole score between 0 and its maximum.
     *
     * @param array $data The submitted values.
     * @param array $files Unused.
     * @return array Element name => error.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        foreach ($this->criteria() as $i => $criterion) {
            if (empty($data['assessed'][$i])) {
                continue;
            }
            $value = isset($data['score'][$i]) ? trim((string) $data['score'][$i]) : '';
            $max = max(0, (int) $criterion['max_score']);
            if ($value === '' || !ctype_digit($value) || (int) $value > $max) {
                $errors["score[$i]"] = get_string('grade_scorerequired', 'mod_presenterai');
            }
        }

        return $errors;
    }

    /**
     * Prefill the criteria and the overall feedback from the attempt's current score.
     *
     * Criteria are matched by name, not position, so a score entered against an
     * older version of the rubric still lands on the criteria that survive. A
     * criterion the score doesn't name keeps its empty default.
     *
     * @param \stdClass|null $score The current presenterai_score row, or null.
     * @return void
     */
    public function set_prefill(?\stdClass $score): void {
        if ($score === null) {
            return;
        }

        $byname = [];
        foreach (score_manager::decode_criteria($score) as $stored) {
            $byname[(string) $stored['name']] = $stored;
        }

        // Keyed by the full element name, which is how definition() set the
        // defaults these replace; a nested array would lose to those.
        $defaults = [];
        foreach ($this->criteria() as $i => $criterion) {
            $stored = $byname[(string) $criterion['name']] ?? null;
            if ($stored === null) {
                continue;
            }
            $assessed = !empty($stored['assessed']);
            $defaults["assessed[$i]"] = $assessed ? 1 : 0;
            $defaults["score[$i]"] = ($assessed && $stored['score'] !== null) ? (string) (int) $stored['score'] : '';
            $defaults["criterionfeedback[$i]"] = (string) ($stored['feedback'] ?? '');
        }
        $defaults['feedback'] = (string) ($score->feedback ?? '');

        $this->set_data($defaults);
    }

    /**
     * The criteria list save_teacher_score() expects, built from the resolved rubric.
     *
     * @param \stdClass $data The data get_data() returned.
     * @return array List of ['name', 'max_score', 'score' (null when unassessed), 'assessed', 'feedback'].
     */
    public function to_criteria(\stdClass $data): array {
        $assessedin = (array) ($data->assessed ?? []);
        $scorein = (array) ($data->score ?? []);
        $feedbackin = (array) ($data->criterionfeedback ?? []);

        $out = [];
        foreach ($this->criteria() as $i => $criterion) {
            $assessed = !empty($assessedin[$i]);
            $raw = isset($scorein[$i]) ? trim((string) $scorein[$i]) : '';
            $out[] = [
                'name' => (string) $criterion['name'],
                'max_score' => (int) $criterion['max_score'],
                'score' => ($assessed && $raw !== '') ? (int) $raw : null,
                'assessed' => $assessed,
                'feedback' => trim((string) ($feedbackin[$i] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * The outcome values from the submitted data, keyed by grade item number.
     *
     * @param \stdClass $data The data get_data() returned.
     * @return array itemnumber => value.
     */
    public function to_outcomes(\stdClass $data): array {
        $out = [];
        foreach ($this->_customdata['outcomes'] ?? [] as $outcome) {
            $name = 'outcome_' . (int) $outcome['itemnumber'];
            if (isset($data->$name)) {
                $out[(int) $outcome['itemnumber']] = (int) $data->$name;
            }
        }

        return $out;
    }

    /**
     * The resolved rubric criteria, indexed from 0.
     *
     * @return array
     */
    private function criteria(): array {
        return array_values($this->_customdata['criteria'] ?? []);
    }
}
