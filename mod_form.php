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

/**
 * Instance settings form for mod_presenterai.
 *
 * Recording, AI feedback, topics, retention, the grade and completion.
 *
 * The grade is core's modgrade element plus a grading method saying how
 * attempts combine. Outcomes are core's too: declaring FEATURE_GRADE_OUTCOMES
 * makes moodleform_mod list them, so nothing here adds them.
 *
 * Every bound enforced here is enforced again by instance_manager::normalise(),
 * which every write goes through. This form's job is to explain the rule to
 * the teacher; normalise() is what makes it hold.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_presenterai\local\config;
use mod_presenterai\local\grader;
use mod_presenterai\local\instance_manager;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\topic_manager;

/**
 * Instance settings form.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_presenterai_mod_form extends moodleform_mod {
    /** @var int Empty topic rows offered on a new activity. */
    private const NEW_TOPIC_REPEATS = 3;

    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('presenterainame', 'mod_presenterai'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'presenterainame', 'mod_presenterai');

        $this->standard_intro_elements();

        $this->add_recording_elements();
        $this->add_ai_elements();
        $this->add_topic_elements();
        $this->add_retention_elements();

        $this->standard_grading_coursemodule_elements();
        $this->add_gradingmethod_element();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Split stored values into the shape the form's elements use.
     *
     * retentiondays is one column holding three meanings, which the form
     * shows as a choice and a number. Topics are separate rows, which the
     * form shows as repeated element groups.
     *
     * @param array $defaultvalues Values about to be loaded into the form, changed in place.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if (isset($defaultvalues['retentiondays'])) {
            $days = (int) $defaultvalues['retentiondays'];
            if ($days < 0) {
                $defaultvalues['retentionmode'] = 'site';
            } else if ($days === 0) {
                $defaultvalues['retentionmode'] = 'keep';
            } else {
                $defaultvalues['retentionmode'] = 'days';
                $defaultvalues['retentiondaysvalue'] = $days;
            }
        }

        // Null columns read as the form's "automatic" and "general" options.
        if (array_key_exists('rubricid', $defaultvalues)) {
            $defaultvalues['rubricid'] = (int) $defaultvalues['rubricid'];
        }
        if (array_key_exists('speakinglevel', $defaultvalues)) {
            $defaultvalues['speakinglevel'] = (string) $defaultvalues['speakinglevel'];
        }

        // The checkboxes beside the completion numbers aren't columns; they
        // reflect whether a stored number is in force.
        $suffix = $this->get_suffix();
        foreach (['completionsubmit', 'completionminscore'] as $rule) {
            $defaultvalues[$rule . 'enabled' . $suffix] = !empty($defaultvalues[$rule . $suffix]) ? 1 : 0;
        }

        if (empty($this->_instance)) {
            return;
        }

        $fileoptions = topic_manager::file_options($this->get_course());
        $index = 0;
        foreach (topic_manager::get_topics((int) $this->_instance) as $topic) {
            $defaultvalues['topicid'][$index] = (int) $topic->id;
            $defaultvalues['topictitle'][$index] = $topic->title;
            $defaultvalues['topicinstructions'][$index] = [
                'text' => (string) $topic->instructions,
                'format' => (int) $topic->instructionsformat,
            ];
            // A fresh draft area per topic. On a redisplay after a failed
            // validation the submitted draft id wins over this default, so the
            // teacher's unsaved upload is not lost.
            $draftitemid = 0;
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_presenterai',
                topic_manager::FILEAREA,
                (int) $topic->id,
                $fileoptions
            );
            $defaultvalues['topicfile'][$index] = $draftitemid;
            $index++;
        }
    }

    /**
     * Validate the submitted settings.
     *
     * @param array $data Submitted values.
     * @param array $files Uploaded files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $limit = config::max_recording_seconds();
        $min = (int) ($data['minseconds'] ?? 0);
        $max = (int) ($data['maxseconds'] ?? 0);
        $floor = format_time(config::MIN_SECONDS_FLOOR);

        if ($max > $limit) {
            $errors['maxseconds'] = get_string('maxsecondsoverlimit', 'mod_presenterai', format_time($limit));
        } else if ($max < config::MIN_SECONDS_FLOOR) {
            $errors['maxseconds'] = get_string('durationtooshort', 'mod_presenterai', $floor);
        }
        if ($min < config::MIN_SECONDS_FLOOR) {
            $errors['minseconds'] = get_string('durationtooshort', 'mod_presenterai', $floor);
        } else if ($min > $max) {
            $errors['minseconds'] = get_string('minsecondsovermax', 'mod_presenterai');
        }

        foreach (['maxattempts', 'storedattempts'] as $field) {
            if ((int) ($data[$field] ?? 0) < 0) {
                $errors[$field] = get_string('valuenotnegative', 'mod_presenterai');
            }
        }

        // A frozen retention field is not the teacher's to get wrong, and
        // normalise() ignores it anyway.
        $dayschosen = ($data['retentionmode'] ?? '') === 'days';
        if ($dayschosen && $this->can_set_retention() && (int) ($data['retentiondaysvalue'] ?? 0) < 1) {
            $errors['retentiondaysvalue'] = get_string('retentiondaysmin', 'mod_presenterai');
        }

        $suffix = $this->get_suffix();
        if (!empty($data['completionsubmitenabled' . $suffix]) && (int) ($data['completionsubmit' . $suffix] ?? 0) < 1) {
            $errors['completionsubmitgroup' . $suffix] = get_string('error:completionsubmit', 'mod_presenterai');
        }
        if (!empty($data['completionminscoreenabled' . $suffix])) {
            $minscore = (int) ($data['completionminscore' . $suffix] ?? 0);
            if ($minscore < 1 || $minscore > 100) {
                $errors['completionminscoregroup' . $suffix] = get_string('error:completionminscore', 'mod_presenterai');
            }
        }

        return $errors;
    }

    /**
     * Add the custom completion rules.
     *
     * Each rule is a checkbox with a number beside it, as mod_forum does, and
     * the element names carry the suffix core uses when the same form is shown
     * for default completion settings.
     *
     * @return string[] The names of the groups added.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $groups = [];
        foreach (['completionsubmit', 'completionminscore'] as $rule) {
            $enabledel = $rule . 'enabled' . $suffix;
            $valueel = $rule . $suffix;
            $groupel = $rule . 'group' . $suffix;

            $group = [];
            $group[] = $mform->createElement('advcheckbox', $enabledel, '', get_string($rule . 'enabled', 'mod_presenterai'));
            $group[] = $mform->createElement('text', $valueel, get_string($rule, 'mod_presenterai'), ['size' => 3]);
            $mform->setType($valueel, PARAM_INT);
            $mform->addGroup($group, $groupel, get_string($rule, 'mod_presenterai'), ' ', false);
            $mform->addHelpButton($groupel, $rule, 'mod_presenterai');
            $mform->disabledIf($valueel, $enabledel, 'notchecked');
            $groups[] = $groupel;
        }

        return $groups;
    }

    /**
     * Whether any custom completion rule is switched on.
     *
     * @param array $data Submitted values.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        foreach (['completionsubmit', 'completionminscore'] as $rule) {
            if (!empty($data[$rule . 'enabled' . $suffix]) && (int) ($data[$rule . $suffix] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zero a completion number whose checkbox is off, or when completion isn't automatic.
     *
     * @param stdClass $data Submitted values, changed in place.
     * @return void
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        if (empty($data->completionunlocked)) {
            return;
        }
        $suffix = $this->get_suffix();
        $completion = $data->{'completion' . $suffix} ?? COMPLETION_TRACKING_NONE;
        $automatic = (int) $completion === COMPLETION_TRACKING_AUTOMATIC;
        foreach (['completionsubmit', 'completionminscore'] as $rule) {
            if (!$automatic || empty($data->{$rule . 'enabled' . $suffix})) {
                $data->{$rule . $suffix} = 0;
            }
        }
    }

    /**
     * The grading method: how a learner's attempts combine into one grade.
     *
     * Hidden when the activity has no grade, because then there's nothing to combine.
     *
     * @return void
     */
    private function add_gradingmethod_element(): void {
        $mform = $this->_form;

        $options = [];
        foreach (grader::GRADING_METHODS as $method) {
            $options[$method] = get_string('gradingmethod_' . $method, 'mod_presenterai');
        }
        $mform->addElement('select', 'gradingmethod', get_string('gradingmethod', 'mod_presenterai'), $options);
        $mform->setDefault('gradingmethod', grader::DEFAULT_GRADING_METHOD);
        $mform->addHelpButton('gradingmethod', 'gradingmethod', 'mod_presenterai');
        $mform->hideIf('gradingmethod', 'grade[modgrade_type]', 'eq', 'none');
    }

    /**
     * The recording section: mode, length, attempts and slides.
     *
     * @return void
     */
    private function add_recording_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'recordingheading', get_string('recordingheading', 'mod_presenterai'));

        $mform->addElement('select', 'mode', get_string('mode', 'mod_presenterai'), [
            'video' => get_string('modevideo', 'mod_presenterai'),
            'audio' => get_string('modeaudio', 'mod_presenterai'),
        ]);
        $mform->setDefault('mode', 'video');
        $mform->addHelpButton('mode', 'mode', 'mod_presenterai');

        $durationoptions = ['units' => [MINSECS, 1], 'optional' => false];
        $mform->addElement('duration', 'minseconds', get_string('minseconds', 'mod_presenterai'), $durationoptions);
        $mform->setDefault('minseconds', 300);
        $mform->addHelpButton('minseconds', 'minseconds', 'mod_presenterai');

        $mform->addElement('duration', 'maxseconds', get_string('maxseconds', 'mod_presenterai'), $durationoptions);
        $mform->setDefault('maxseconds', 420);
        $mform->addHelpButton('maxseconds', 'maxseconds', 'mod_presenterai');

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_presenterai'), ['size' => 4]);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_presenterai');

        $mform->addElement('advcheckbox', 'slidesenabled', get_string('slidesenabled', 'mod_presenterai'));
        $mform->setDefault('slidesenabled', 0);
        $mform->addHelpButton('slidesenabled', 'slidesenabled', 'mod_presenterai');
    }

    /**
     * The AI feedback section: presentation type, speaking level, rubric and slide vision.
     *
     * The rubric choice lists active rubrics visible from where the activity
     * is being created or edited, so a teacher can't pick one from another
     * course; instance_manager::normalise() checks the choice again.
     *
     * @return void
     */
    private function add_ai_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'aiheading_inst', get_string('aiheading_inst', 'mod_presenterai'));

        $mform->addElement('select', 'ptype', get_string('ptype', 'mod_presenterai'), [
            'informative' => get_string('ptype_informative', 'mod_presenterai'),
            'persuasive' => get_string('ptype_persuasive', 'mod_presenterai'),
        ]);
        $mform->setDefault('ptype', 'informative');
        $mform->addHelpButton('ptype', 'ptype', 'mod_presenterai');

        // The first option is the empty value, which means general.
        $levels = ['' => get_string('level_general', 'mod_presenterai')];
        foreach (rubric_manager::LEVELS as $level) {
            if ($level !== rubric_manager::LEVEL_GENERAL) {
                $levels[$level] = get_string('level_' . $level, 'mod_presenterai');
            }
        }
        $mform->addElement('select', 'speakinglevel', get_string('speakinglevel', 'mod_presenterai'), $levels);
        $mform->setDefault('speakinglevel', '');
        $mform->addHelpButton('speakinglevel', 'speakinglevel', 'mod_presenterai');

        $rubrics = [0 => get_string('rubricid_auto', 'mod_presenterai')];
        foreach (rubric_manager::list_active_for_context($this->context) as $rubric) {
            // Unescaped: the select escapes its option text itself.
            $rubrics[(int) $rubric->id] = format_string($rubric->title, true, ['context' => $this->context, 'escape' => false]);
        }
        $mform->addElement('select', 'rubricid', get_string('rubricid', 'mod_presenterai'), $rubrics);
        $mform->setType('rubricid', PARAM_INT);
        $mform->setDefault('rubricid', 0);
        $mform->addHelpButton('rubricid', 'rubricid', 'mod_presenterai');

        $mform->addElement('advcheckbox', 'slidevision', get_string('slidevision', 'mod_presenterai'));
        $mform->setDefault('slidevision', 0);
        $mform->addHelpButton('slidevision', 'slidevision', 'mod_presenterai');
        $mform->hideIf('slidevision', 'slidesenabled', 'notchecked');
    }

    /**
     * The topics section: one repeated group per topic.
     *
     * A new activity gets three empty rows. An existing one gets a row per
     * topic plus one empty row, so adding a topic never needs the add button.
     *
     * @return void
     */
    private function add_topic_elements(): void {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('header', 'topicsheading', get_string('topicsheading', 'mod_presenterai'));

        $repeatno = self::NEW_TOPIC_REPEATS;
        if (!empty($this->_instance)) {
            $count = $DB->count_records('presenterai_topic', ['presenteraiid' => $this->_instance]);
            if ($count > 0) {
                $repeatno = $count + 1;
            }
        }

        $elements = [
            $mform->createElement('hidden', 'topicid', 0),
            $mform->createElement('text', 'topictitle', get_string('topictitle', 'mod_presenterai'), ['size' => 64]),
            $mform->createElement(
                'editor',
                'topicinstructions',
                get_string('topicinstructions', 'mod_presenterai'),
                ['rows' => 4],
                topic_manager::editor_options($this->context)
            ),
            $mform->createElement(
                'filemanager',
                'topicfile',
                get_string('topicfile', 'mod_presenterai'),
                null,
                topic_manager::file_options($this->get_course())
            ),
        ];
        $options = [
            'topicid' => ['type' => PARAM_INT],
            'topictitle' => ['type' => PARAM_TEXT, 'helpbutton' => ['topictitle', 'mod_presenterai']],
            'topicinstructions' => ['type' => PARAM_RAW],
            'topicfile' => ['helpbutton' => ['topicfile', 'mod_presenterai']],
        ];

        $this->repeat_elements(
            $elements,
            $repeatno,
            $options,
            'topicrepeats',
            'topicaddfields',
            1,
            get_string('addtopics', 'mod_presenterai'),
            true
        );
    }

    /**
     * The retention section: how long recordings are kept, and how many.
     *
     * Without mod/presenterai:setretention the choice is shown frozen with a
     * line saying why (design 7.3). The two warnings below it describe the
     * values as saved, because that is what the form can know when it is
     * drawn; they are re-evaluated on every load.
     *
     * @return void
     */
    private function add_retention_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'retentionheading_inst', get_string('retentionheading_inst', 'mod_presenterai'));

        $sitedays = instance_manager::site_retention_days();
        $sitedesc = $sitedays > 0
            ? get_string('retentionsitedays', 'mod_presenterai', self::days_text($sitedays))
            : get_string('retentionsitekeep', 'mod_presenterai');

        $mform->addElement('select', 'retentionmode', get_string('retentionmode', 'mod_presenterai'), [
            'site' => get_string('retentionmode_site', 'mod_presenterai', $sitedesc),
            'keep' => get_string('retentionmode_keep', 'mod_presenterai'),
            'days' => get_string('retentionmode_days', 'mod_presenterai'),
        ]);
        $mform->setDefault('retentionmode', 'site');
        $mform->addHelpButton('retentionmode', 'retentiondays_inst', 'mod_presenterai');

        $mform->addElement('text', 'retentiondaysvalue', get_string('retentiondaysvalue', 'mod_presenterai'), ['size' => 5]);
        $mform->setType('retentiondaysvalue', PARAM_INT);
        $mform->hideIf('retentiondaysvalue', 'retentionmode', 'neq', 'days');

        $effective = instance_manager::effective_retention_days(
            isset($this->current->retentiondays) ? (int) $this->current->retentiondays : instance_manager::RETENTION_SITE
        );

        if (!$this->can_set_retention()) {
            $mform->freeze(['retentionmode', 'retentiondaysvalue']);
            $locked = $effective > 0
                ? get_string('retentiondays_locked', 'mod_presenterai', self::days_text($effective))
                : get_string('retentiondays_locked_keep', 'mod_presenterai');
            $mform->addElement('static', 'retentionlocked', '', $locked);
        }

        // Design 8.7b. get_config() returns false before the settings page has
        // ever been saved, and the shipped default allows download, so only a
        // stored zero is the combination worth warning about.
        $downloadsetting = get_config('mod_presenterai', 'allowlearnerdownload');
        $downloadblocked = ($downloadsetting !== false && (int) $downloadsetting === 0);
        if ($effective > 0 && $downloadblocked) {
            $mform->addElement('static', 'retentioncombinationwarning', '', html_writer::div(
                get_string('setting_combination_warning', 'mod_presenterai', $effective),
                'alert alert-warning'
            ));
        }

        // Design 8.7a: scoring retries and a cron backlog can outlast a window
        // this short, and the media is then gone before anything reads it.
        if ($effective > 0 && $effective < 3) {
            $mform->addElement('static', 'retentiontooshortwarning', '', html_writer::div(
                get_string('retentiontooshort_warning', 'mod_presenterai', self::days_text($effective)),
                'alert alert-warning'
            ));
        }

        $mform->addElement('text', 'storedattempts', get_string('storedattempts', 'mod_presenterai'), ['size' => 4]);
        $mform->setType('storedattempts', PARAM_INT);
        $mform->setDefault('storedattempts', 0);
        $mform->addHelpButton('storedattempts', 'storedattempts', 'mod_presenterai');
    }

    /**
     * Whether the current user may change this activity's retention.
     *
     * The course context when adding and the module context when updating,
     * which is what $this->context already is.
     *
     * @return bool
     */
    private function can_set_retention(): bool {
        return has_capability('mod/presenterai:setretention', $this->context);
    }

    /**
     * A number of days as words, "1 day" or "N days".
     *
     * @param int $days The number of days.
     * @return string
     */
    private static function days_text(int $days): string {
        return $days === 1 ? get_string('numday', 'moodle', $days) : get_string('numdays', 'moodle', $days);
    }
}
