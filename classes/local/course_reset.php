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

namespace mod_presenterai\local;

/**
 * What a course reset does to PresenterAI: learner work goes, the activity stays.
 *
 * A reset is how a course is reused for the next cohort, so every recording,
 * its media and its scores are removed, and the activity's own configuration
 * (topics, rubrics, settings) is kept. Media is deleted through the store
 * before the rows, by media_purger, for the same reason instance deletion
 * does it that way: on S3 nothing else would ever delete the objects (design
 * 8.6, point 5).
 *
 * Spend rows are kept with no person attached, so a site's AI cost totals
 * still add up after a reset. A privacy deletion removes them instead.
 *
 * lib.php's presenterai_reset_* functions are thin wrappers over this class.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_reset {
    /** @var string The reset form element. */
    public const ELEMENT = 'reset_presenterai_recordings';

    /**
     * Add the PresenterAI section to the course reset form.
     *
     * @param \MoodleQuickForm $mform The reset form.
     * @return void
     */
    public static function form_definition($mform): void {
        $mform->addElement('header', 'presenteraiheader', get_string('modulenameplural', 'mod_presenterai'));
        $mform->addElement('advcheckbox', self::ELEMENT, get_string('reset_recordings', 'mod_presenterai'));
        $mform->addElement(
            'static',
            'reset_presenterai_recordings_help',
            '',
            get_string('reset_recordings_help', 'mod_presenterai')
        );
    }

    /**
     * The form's default: remove learner work, as every core activity's reset does by default.
     *
     * @return array Element name => default value.
     */
    public static function form_defaults(): array {
        return [self::ELEMENT => 1];
    }

    /**
     * Do what the reset form asked for.
     *
     * @param \stdClass $data The reset form data, carrying courseid.
     * @return array Status rows for the reset report.
     */
    public static function reset_userdata(\stdClass $data): array {
        global $DB;

        if (empty($data->{self::ELEMENT})) {
            return [];
        }

        $courseid = (int) $data->courseid;
        $ids = $DB->get_fieldset_select('presenterai', 'id', 'course = :course', ['course' => $courseid]);
        foreach ($ids as $id) {
            media_purger::purge_instance((int) $id, media_purger::AIUSAGE_ANONYMISE);
        }

        // Grades go with the work they were for, unless the reset already
        // clears every grade in the course, which calls reset_gradebook itself.
        if (empty($data->reset_gradebook_grades)) {
            self::reset_gradebook($courseid);
        }

        return [[
            'component' => get_string('modulenameplural', 'mod_presenterai'),
            'item' => get_string('reset_recordings_done', 'mod_presenterai'),
            'error' => false,
        ]];
    }

    /**
     * Reset the grades of every graded PresenterAI activity in a course.
     *
     * Only an activity that has a grade and an existing grade item is touched,
     * and an item is never created here: grade 0 means the activity is not in
     * the gradebook at all, and a reset must not put it there.
     *
     * @param int $courseid The course id.
     * @return void
     */
    public static function reset_gradebook(int $courseid): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        $instances = $DB->get_records_select('presenterai', 'course = :course AND grade <> 0', ['course' => $courseid], 'id', 'id');
        foreach ($instances as $instance) {
            $item = \grade_item::fetch([
                'courseid' => $courseid,
                'itemtype' => 'mod',
                'itemmodule' => 'presenterai',
                'iteminstance' => (int) $instance->id,
                'itemnumber' => 0,
            ]);
            if (!$item) {
                continue;
            }
            grade_update('mod/presenterai', $courseid, 'mod', 'presenterai', (int) $instance->id, 0, null, ['reset' => true]);
        }
    }
}
