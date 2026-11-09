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
 * Since 0.6.0 the form leads with one choice, the presentation type, right
 * after the name. Picking a type fills in sensible defaults (length, slides,
 * starter topics) through mod_presenterai/form, and the server sets the same
 * defaults for a new activity, so the form works without the script. The
 * rubric the activity will use is shown read only, criteria and levels, with
 * a link to the rubric editor once the activity exists. Recording and topics
 * stay open; everything a course designer rarely changes (attempts, review
 * before release, body language and slide design feedback, retention) is
 * under one collapsed "More settings" heading. A new activity starts with
 * these defaults: no grade, review before release off, recordings kept 30
 * days.
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
use mod_presenterai\local\presentation_types;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\sources;
use mod_presenterai\local\topic_manager;
use mod_presenterai\output\rubrics_page;

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

    /** @var int Days a new activity keeps recordings, for a teacher who may set retention. */
    public const NEW_RETENTION_DAYS = 30;

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

        $this->add_presentationtype_element();

        $this->standard_intro_elements();

        $this->add_recording_elements();
        $this->add_rubric_elements();
        $this->add_topic_elements();
        $this->add_source_elements();
        $this->add_more_settings();

        $this->standard_grading_coursemodule_elements();
        // The default use is ungraded practice, so a new activity has no grade.
        // An existing activity's own grade still wins over this default.
        $mform->setDefault('grade', 0);
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

        // A null rubricid reads as the form's "automatic" option.
        if (array_key_exists('rubricid', $defaultvalues)) {
            $defaultvalues['rubricid'] = (int) $defaultvalues['rubricid'];
        }
        // The one presentation type select stands for ptype, speakinglevel and preset.
        if (!empty($this->_instance)) {
            $defaultvalues['presentationtype'] = presentation_types::from_instance((object) $defaultvalues);
        }

        // The checkboxes beside the completion numbers aren't columns; they
        // reflect whether a stored number is in force.
        $suffix = $this->get_suffix();
        foreach (['completionsubmit', 'completionminscore'] as $rule) {
            $defaultvalues[$rule . 'enabled' . $suffix] = !empty($defaultvalues[$rule . $suffix]) ? 1 : 0;
        }

        if (empty($this->_instance)) {
            // A new activity starts with the default type's starter topics,
            // which the designer can edit or delete.
            if (!isset($defaultvalues['topictitle'])) {
                foreach (presentation_types::defaults(presentation_types::DEFAULT_TYPE)['topics'] as $index => $title) {
                    $defaultvalues['topictitle'][$index] = $title;
                }
            }
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

        $given = count(sources::parse($data['sources'] ?? ''));
        $minsources = (int) ($data['minsources'] ?? 0);
        if ($minsources < 0) {
            $errors['minsources'] = get_string('valuenotnegative', 'mod_presenterai');
        } else if ($minsources > $given) {
            $errors['minsources'] = get_string('error:minsourcesovercount', 'mod_presenterai', $given);
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
     * The presentation type, right after the name (0.6.0).
     *
     * One select in place of the presentation type and speaking level selects
     * before 0.6.0; instance_manager::normalise() stores it as the ptype,
     * speakinglevel and preset columns. An activity made before 0.6.0 is
     * offered its own general_* type as well, so saving it keeps its rubric.
     * mod_presenterai/form fills in the type's defaults when it changes; the
     * data it needs travels as a data attribute on the rubric preview.
     *
     * @return void
     */
    private function add_presentationtype_element(): void {
        global $PAGE;
        $mform = $this->_form;

        $current = !empty($this->current->instance) ? presentation_types::from_instance($this->current) : '';
        $mform->addElement(
            'select',
            'presentationtype',
            get_string('presentationtype', 'mod_presenterai'),
            presentation_types::options($current)
        );
        $mform->setDefault('presentationtype', presentation_types::DEFAULT_TYPE);
        $mform->addHelpButton('presentationtype', 'presentationtype', 'mod_presenterai');

        $PAGE->requires->js_call_amd('mod_presenterai/form', 'init', ['[data-region="presenterai-rubricpreview"]']);
    }

    /**
     * The recording section: mode, length and slides.
     *
     * The defaults are the default presentation type's, so a new activity
     * saved without the form's script gets the same values it would with it.
     *
     * @return void
     */
    private function add_recording_elements(): void {
        $mform = $this->_form;
        $defaults = presentation_types::defaults(presentation_types::DEFAULT_TYPE);

        $mform->addElement('header', 'recordingheading', get_string('recordingheading', 'mod_presenterai'));
        $mform->setExpanded('recordingheading');

        $mform->addElement('select', 'mode', get_string('mode', 'mod_presenterai'), [
            'video' => get_string('modevideo', 'mod_presenterai'),
            'audio' => get_string('modeaudio', 'mod_presenterai'),
        ]);
        $mform->setDefault('mode', 'video');
        $mform->addHelpButton('mode', 'mode', 'mod_presenterai');

        $durationoptions = ['units' => [MINSECS, 1], 'optional' => false];
        $mform->addElement('duration', 'minseconds', get_string('minseconds', 'mod_presenterai'), $durationoptions);
        $mform->setDefault('minseconds', $defaults['minseconds']);
        $mform->addHelpButton('minseconds', 'minseconds', 'mod_presenterai');

        $mform->addElement('duration', 'maxseconds', get_string('maxseconds', 'mod_presenterai'), $durationoptions);
        $mform->setDefault('maxseconds', $defaults['maxseconds']);
        $mform->addHelpButton('maxseconds', 'maxseconds', 'mod_presenterai');

        $mform->addElement('advcheckbox', 'slidesenabled', get_string('slidesenabled', 'mod_presenterai'));
        $mform->setDefault('slidesenabled', $defaults['slidesenabled']);
        $mform->addHelpButton('slidesenabled', 'slidesenabled', 'mod_presenterai');
    }

    /**
     * The rubric section: which rubric scores the activity, and that rubric shown read only.
     *
     * The rubric choice lists active rubrics visible from where the activity
     * is being created or edited, so a teacher can't pick one from another
     * course; instance_manager::normalise() checks the choice again.
     *
     * The preview is rendered for every choice the form can reach (each
     * presentation type with "automatic", and each stored rubric), and
     * mod_presenterai/form shows the one that matches the two selects.
     * Without the script the one matching the saved values shows. There's no
     * second editor here: once the activity exists, "Edit rubric" opens
     * rubric.php in the same window and comes back to this form.
     *
     * @return void
     */
    private function add_rubric_elements(): void {
        global $OUTPUT;
        $mform = $this->_form;

        $mform->addElement('header', 'rubricheading', get_string('rubricheading', 'mod_presenterai'));
        $mform->setExpanded('rubricheading');

        $rubrics = [0 => get_string('rubricid_auto', 'mod_presenterai')];
        $stored = rubric_manager::list_active_for_context($this->context);
        foreach ($stored as $rubric) {
            // Unescaped: the select escapes its option text itself.
            $rubrics[(int) $rubric->id] = format_string($rubric->title, true, ['context' => $this->context, 'escape' => false]);
        }
        $mform->addElement('select', 'rubricid', get_string('rubricid', 'mod_presenterai'), $rubrics);
        $mform->setType('rubricid', PARAM_INT);
        $mform->setDefault('rubricid', 0);
        $mform->addHelpButton('rubricid', 'rubricid', 'mod_presenterai');

        $html = $OUTPUT->render_from_template('mod_presenterai/rubric_preview', $this->rubric_previews($stored));
        $mform->addElement('static', 'rubricpreview', get_string('rubricpreview', 'mod_presenterai'), $html);
    }

    /**
     * Context for templates/rubric_preview.mustache.
     *
     * @param \stdClass[] $stored The active rubrics the rubric select offers.
     * @return array
     */
    private function rubric_previews(array $stored): array {
        $current = !empty($this->current->instance) ? clone $this->current : new \stdClass();
        $currenttype = !empty($this->current->instance)
            ? presentation_types::from_instance($current)
            : presentation_types::DEFAULT_TYPE;
        $currentrubric = (int) ($current->rubricid ?? 0);
        $current->mode = (string) ($current->mode ?? 'video');
        $current->videovision = (int) ($current->videovision ?? 0);

        $previews = [];
        foreach (array_keys(presentation_types::options($currenttype)) as $type) {
            $instance = clone $current;
            foreach (presentation_types::columns($type, $current->ptype ?? null) as $field => $value) {
                $instance->$field = $value;
            }
            $instance->rubricid = 0;
            $previews[] = $this->rubric_preview(
                'type:' . $type,
                rubric_manager::resolve($instance, $this->context),
                $type,
                $currentrubric === 0 && $type === $currenttype
            );
        }
        foreach ($stored as $row) {
            $instance = clone $current;
            $instance->rubricid = (int) $row->id;
            $previews[] = $this->rubric_preview(
                'rubric:' . (int) $row->id,
                rubric_manager::resolve($instance, $this->context),
                $currenttype,
                $currentrubric === (int) $row->id
            );
        }

        return [
            'defaults' => json_encode(presentation_types::all_defaults()),
            // Only a new activity takes a type's defaults: on an existing one a
            // type change must not reset its mode, lengths, slides or topics.
            'isnew' => empty($this->current->instance),
            'previews' => $previews,
        ];
    }

    /**
     * One rubric's read only view, with its edit link when there is one.
     *
     * The link edits a stored rubric the user may edit, or for an in-code
     * preset starts a new rubric in this activity from it, which then wins
     * over the preset. It needs the activity to exist, because the editor is
     * reached through it.
     *
     * @param string $key What the preview stands for, 'type:<type>' or 'rubric:<id>'.
     * @param array $resolved A rubric_manager::resolve() result.
     * @param string $type The presentation type it was resolved for.
     * @param bool $shown Whether it matches the saved values, so it shows without the script.
     * @return array
     */
    private function rubric_preview(string $key, array $resolved, string $type, bool $shown): array {
        global $USER;

        $criteria = [];
        foreach ($resolved['criteria'] as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] ?? [] as $level) {
                $levels[] = [
                    'score' => (int) $level['score'],
                    'label' => $level['label'],
                    'description' => $level['description'],
                ];
            }
            $criteria[] = [
                'name' => $criterion['name'],
                'description' => $criterion['description'],
                'haslevels' => !empty($levels),
                'levels' => $levels,
                'maxtext' => get_string('rubricpreview_max', 'mod_presenterai', (int) $criterion['max_score']),
                'feedbackonly' => empty($criterion['counts']),
            ];
        }

        $editurl = '';
        $cm = $this->get_coursemodule();
        if ($cm && has_capability('mod/presenterai:managerubrics', $this->context)) {
            $returnurl = (new moodle_url('/course/modedit.php', ['update' => (int) $cm->id]))->out(false);
            if ((int) $resolved['rubricid'] > 0) {
                $row = rubric_manager::list_for_context($this->context)[(int) $resolved['rubricid']] ?? null;
                if ($row && rubrics_page::may_edit($row, $this->context, (int) $USER->id)) {
                    $editurl = (new moodle_url('/mod/presenterai/rubric.php', [
                        'id' => (int) $cm->id,
                        'action' => 'edit',
                        'rubricid' => (int) $row->id,
                        'returnurl' => $returnurl,
                    ]))->out(false);
                }
            } else {
                $preset = presentation_types::columns($type)['preset'] ?? rubric_manager::LEVEL_GENERAL;
                $audio = ($this->current->mode ?? 'video') === 'audio';
                $editurl = (new moodle_url('/mod/presenterai/rubric.php', [
                    'id' => (int) $cm->id,
                    'action' => 'edit',
                    'preset' => $preset,
                    'type' => $audio ? rubric_manager::TYPE_SPEECH : rubric_manager::TYPE_VIDEO,
                    'title' => get_string('presentationtype_' . $preset, 'mod_presenterai'),
                    'returnurl' => $returnurl,
                ]))->out(false);
            }
        }

        return [
            'key' => $key,
            'shown' => $shown,
            'title' => $resolved['rubricid'] > 0
                ? format_string($resolved['title'], true, ['context' => $this->context])
                : get_string('rubricpreview_preset', 'mod_presenterai'),
            'criteria' => $criteria,
            'editurl' => $editurl,
            'editnote' => $cm ? '' : get_string('rubricpreview_savefirst', 'mod_presenterai'),
        ];
    }

    /**
     * Everything a course designer rarely changes, under one collapsed heading (0.6.0).
     *
     * Attempts, review before release (D28, off by default), slide design and
     * body language feedback, and retention.
     *
     * Body language feedback is opt in per activity (D17) and forced off for
     * audio. Scoring it is a second, separate opt in (D23), and so is letting
     * a learner opt an attempt out (D24). Both are hidden unless video vision
     * is on and the activity records a camera; instance_manager::normalise()
     * forces them to 0 otherwise.
     *
     * @return void
     */
    private function add_more_settings(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'moresettings', get_string('moresettings', 'mod_presenterai'));
        $mform->setExpanded('moresettings', false);

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'mod_presenterai'), ['size' => 4]);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_presenterai');

        // D28: hold AI scores and feedback until a teacher releases them.
        $mform->addElement('advcheckbox', 'reviewbeforerelease', get_string('reviewbeforerelease', 'mod_presenterai'));
        $mform->setDefault('reviewbeforerelease', 0);
        $mform->addHelpButton('reviewbeforerelease', 'reviewbeforerelease', 'mod_presenterai');

        $mform->addElement('advcheckbox', 'slidevision', get_string('slidevision', 'mod_presenterai'));
        $mform->setDefault('slidevision', 0);
        $mform->addHelpButton('slidevision', 'slidevision', 'mod_presenterai');
        $mform->hideIf('slidevision', 'slidesenabled', 'notchecked');

        $mform->addElement('advcheckbox', 'videovision', get_string('videovision', 'mod_presenterai'));
        $mform->setDefault('videovision', 0);
        $mform->addHelpButton('videovision', 'videovision', 'mod_presenterai');
        $mform->hideIf('videovision', 'mode', 'eq', 'audio');

        $mform->addElement('advcheckbox', 'visualscored', get_string('visualscored', 'mod_presenterai'));
        $mform->setDefault('visualscored', 0);
        $mform->addHelpButton('visualscored', 'visualscored', 'mod_presenterai');
        $mform->hideIf('visualscored', 'videovision', 'notchecked');
        $mform->hideIf('visualscored', 'mode', 'eq', 'audio');

        $mform->addElement('advcheckbox', 'allowvisualoptout', get_string('allowvisualoptout', 'mod_presenterai'));
        $mform->setDefault('allowvisualoptout', 0);
        $mform->addHelpButton('allowvisualoptout', 'allowvisualoptout', 'mod_presenterai');
        $mform->hideIf('allowvisualoptout', 'videovision', 'notchecked');
        $mform->hideIf('allowvisualoptout', 'mode', 'eq', 'audio');

        $this->add_retention_elements();
    }

    /**
     * The topics section: one repeated group per topic.
     *
     * A new activity gets three rows, filled with the default type's starter
     * topics (data_preprocessing()). An existing one gets a row per topic plus
     * one empty row, so adding a topic never needs the add button.
     *
     * @return void
     */
    private function add_topic_elements(): void {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('header', 'topicsheading', get_string('topicsheading', 'mod_presenterai'));
        $mform->setExpanded('topicsheading');
        $mform->addElement('static', 'topicsintro', '', get_string('topicsintro', 'mod_presenterai'));

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
            'topicinstructions' => ['type' => PARAM_CLEANHTML],
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
     * Sources (0.7.0): the sources learners are given to cite, one per line,
     * and how many of them a learner must cite. The AI is told the list and
     * only credits a source from it cited with its author or organization and
     * its year.
     *
     * @return void
     */
    private function add_source_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'sourcesheading', get_string('sourcesheading', 'mod_presenterai'));
        $mform->addElement('textarea', 'sources', get_string('sources', 'mod_presenterai'), ['rows' => 6, 'cols' => 80]);
        $mform->setType('sources', PARAM_TEXT);
        $mform->addHelpButton('sources', 'sources', 'mod_presenterai');

        $mform->addElement('text', 'minsources', get_string('minsources', 'mod_presenterai'), ['size' => 4]);
        $mform->setType('minsources', PARAM_INT);
        $mform->setDefault('minsources', 0);
        $mform->addHelpButton('minsources', 'minsources', 'mod_presenterai');
    }

    /**
     * Retention, inside "More settings": how long recordings are kept, and how many.
     *
     * A new activity keeps recordings 30 days when the teacher may set
     * retention, and follows the site otherwise, which is what normalise()
     * stores for them anyway.
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

        $sitedays = instance_manager::site_retention_days();
        $sitedesc = $sitedays > 0
            ? get_string('retentionsitedays', 'mod_presenterai', self::days_text($sitedays))
            : get_string('retentionsitekeep', 'mod_presenterai');

        $mform->addElement('select', 'retentionmode', get_string('retentionmode', 'mod_presenterai'), [
            'site' => get_string('retentionmode_site', 'mod_presenterai', $sitedesc),
            'keep' => get_string('retentionmode_keep', 'mod_presenterai'),
            'days' => get_string('retentionmode_days', 'mod_presenterai'),
        ]);
        $mform->setDefault('retentionmode', $this->can_set_retention() ? 'days' : 'site');
        $mform->addHelpButton('retentionmode', 'retentiondays_inst', 'mod_presenterai');

        $mform->addElement('text', 'retentiondaysvalue', get_string('retentiondaysvalue', 'mod_presenterai'), ['size' => 5]);
        $mform->setType('retentiondaysvalue', PARAM_INT);
        $mform->setDefault('retentiondaysvalue', self::NEW_RETENTION_DAYS);
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
