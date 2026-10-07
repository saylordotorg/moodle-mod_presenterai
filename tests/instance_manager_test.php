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

namespace mod_presenterai;

use mod_presenterai\local\instance_manager;

/**
 * The bounds every write of an activity goes through.
 *
 * The retention cases matter most: who may decide how long a learner's
 * recording is kept is a policy question (design 7.3), and a teacher posting a
 * value the form showed them as frozen must not change it.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\instance_manager
 */
final class instance_manager_test extends \advanced_testcase {
    /**
     * A course with one activity, returning the data a form save would carry.
     *
     * @param array $fields Extra fields for the data object.
     * @return array [\stdClass course, \stdClass instance, \stdClass data]
     */
    private function setup_activity(array $fields = []): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $data = (object) array_merge(['course' => $course->id, 'coursemodule' => $instance->cmid], $fields);
        return [$course, $instance, $data];
    }

    /**
     * Log in as an editing teacher of the course, who lacks setretention by default.
     *
     * @param \stdClass $course The course.
     * @return void
     */
    private function login_as_teacher(\stdClass $course): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * An unknown mode becomes video; a known one is kept.
     *
     * @return void
     */
    public function test_mode(): void {
        $this->resetAfterTest();

        $this->assertSame('video', instance_manager::normalise((object) ['mode' => 'hologram'], null)->mode);
        $this->assertSame('audio', instance_manager::normalise((object) ['mode' => 'audio'], null)->mode);
    }

    /**
     * maxseconds sits between the floor and the site ceiling.
     *
     * @return void
     */
    public function test_maxseconds_clamped(): void {
        $this->resetAfterTest();

        $this->assertSame(10, instance_manager::normalise((object) ['maxseconds' => 3], null)->maxseconds);
        $this->assertSame(720, instance_manager::normalise((object) ['maxseconds' => 5000], null)->maxseconds);

        set_config('maxrecordingseconds', 900, 'mod_presenterai');
        $this->assertSame(900, instance_manager::normalise((object) ['maxseconds' => 5000], null)->maxseconds);
    }

    /**
     * minseconds sits between the floor and the maximum that will be stored.
     *
     * @return void
     */
    public function test_minseconds_clamped(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['minseconds' => 600, 'maxseconds' => 420], null);
        $this->assertSame(420, $data->minseconds);

        $data = instance_manager::normalise((object) ['minseconds' => 2, 'maxseconds' => 420], null);
        $this->assertSame(10, $data->minseconds);

        // With no maxseconds in the write, the stored one is the bound.
        $existing = (object) ['maxseconds' => 200, 'retentiondays' => -1];
        $data = instance_manager::normalise((object) ['minseconds' => 300], $existing);
        $this->assertSame(200, $data->minseconds);
        $this->assertFalse(property_exists($data, 'maxseconds'));
    }

    /**
     * Attempt counts are never negative, and storedattempts 0 survives: no floor of 1.
     *
     * @return void
     */
    public function test_attempt_counts(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['maxattempts' => -3, 'storedattempts' => 0], null);
        $this->assertSame(0, $data->maxattempts);
        $this->assertSame(0, $data->storedattempts);

        $data = instance_manager::normalise((object) ['maxattempts' => 4, 'storedattempts' => -1], null);
        $this->assertSame(4, $data->maxattempts);
        $this->assertSame(0, $data->storedattempts);

        $data = instance_manager::normalise((object) ['storedattempts' => 2], null);
        $this->assertSame(2, $data->storedattempts);
    }

    /**
     * slidesenabled is 0 or 1, and slide vision cannot outlive slides.
     *
     * @return void
     */
    public function test_slides_and_slidevision(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['slidesenabled' => 'yes', 'slidevision' => 1], null);
        $this->assertSame(1, $data->slidesenabled);
        $this->assertSame(1, $data->slidevision);

        $data = instance_manager::normalise((object) ['slidesenabled' => 0, 'slidevision' => 1], null);
        $this->assertSame(0, $data->slidesenabled);
        $this->assertSame(0, $data->slidevision);

        // Phase 1's form has no slidevision field: switching slides off still clears it.
        $data = instance_manager::normalise((object) ['slidesenabled' => 0], null);
        $this->assertSame(0, $data->slidevision);
    }

    /**
     * Video vision is forced off in audio mode and kept in video mode.
     *
     * @return void
     */
    public function test_videovision_forced_off_for_audio(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['mode' => 'audio', 'videovision' => 1], null);
        $this->assertSame(0, $data->videovision);

        $data = instance_manager::normalise((object) ['mode' => 'video', 'videovision' => 1], null);
        $this->assertSame(1, $data->videovision);

        // Mode not in the write: the stored mode decides.
        $existing = (object) ['mode' => 'audio', 'retentiondays' => -1];
        $data = instance_manager::normalise((object) ['videovision' => 1], $existing);
        $this->assertSame(0, $data->videovision);
    }

    /**
     * Fields absent from the write stay absent, so update_record() leaves them alone.
     *
     * @return void
     */
    public function test_absent_fields_left_absent(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['name' => 'X'], (object) ['retentiondays' => 14, 'mode' => 'video']);

        foreach (['mode', 'minseconds', 'maxseconds', 'maxattempts', 'storedattempts', 'slidesenabled', 'videovision'] as $field) {
            $this->assertFalse(property_exists($data, $field), $field);
        }
        $this->assertSame(14, $data->retentiondays);
    }

    /**
     * With the capability, the form's mode and day count become retentiondays.
     *
     * @return void
     */
    public function test_retention_merge_with_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $data] = $this->setup_activity();

        $cases = [
            [['retentionmode' => 'site'], -1],
            [['retentionmode' => 'keep', 'retentiondaysvalue' => 30], 0],
            [['retentionmode' => 'days', 'retentiondaysvalue' => 14], 14],
            [['retentionmode' => 'days', 'retentiondaysvalue' => 0], 1],
        ];
        foreach ($cases as [$fields, $expected]) {
            $posted = (object) array_merge((array) $data, $fields);
            $result = instance_manager::normalise($posted, (object) ['retentiondays' => 7]);
            $this->assertSame($expected, $result->retentiondays, json_encode($fields));
            $this->assertFalse(property_exists($result, 'retentionmode'));
            $this->assertFalse(property_exists($result, 'retentiondaysvalue'));
        }
    }

    /**
     * Without the capability, an update keeps the stored retention whatever was posted.
     *
     * @return void
     */
    public function test_retention_update_without_capability_keeps_existing(): void {
        $this->resetAfterTest();
        [$course, , $data] = $this->setup_activity(['retentionmode' => 'keep']);
        $this->login_as_teacher($course);

        $this->assertFalse(instance_manager::has_setretention($data));
        $result = instance_manager::normalise($data, (object) ['retentiondays' => 30]);
        $this->assertSame(30, $result->retentiondays);
    }

    /**
     * Without the capability, a new activity uses the site value whatever was posted.
     *
     * @return void
     */
    public function test_retention_add_without_capability_uses_site(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->login_as_teacher($course);

        // No coursemodule: the course context is what decides.
        $data = (object) ['course' => $course->id, 'retentionmode' => 'days', 'retentiondaysvalue' => 2];
        $this->assertFalse(instance_manager::has_setretention($data));
        $this->assertSame(-1, instance_manager::normalise($data, null)->retentiondays);
    }

    /**
     * A manager in the course holds setretention and can change it.
     *
     * @return void
     */
    public function test_retention_manager_can_change(): void {
        $this->resetAfterTest();
        [$course, , $data] = $this->setup_activity(['retentionmode' => 'days', 'retentiondaysvalue' => 90]);
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->setUser($manager);

        $this->assertTrue(instance_manager::has_setretention($data));
        $this->assertSame(90, instance_manager::normalise($data, (object) ['retentiondays' => -1])->retentiondays);
    }

    /**
     * A caller setting retentiondays directly is code, and its value is kept within bounds.
     *
     * @return void
     */
    public function test_retention_direct_value(): void {
        $this->resetAfterTest();

        $this->assertSame(21, instance_manager::normalise((object) ['retentiondays' => 21], null)->retentiondays);
        $this->assertSame(-1, instance_manager::normalise((object) ['retentiondays' => -40], null)->retentiondays);
        $this->assertSame(-1, instance_manager::normalise((object) [], null)->retentiondays);
    }

    /**
     * The effective value resolves -1 through the site setting, and a non-positive site value means keep.
     *
     * @return void
     */
    public function test_effective_retention_days(): void {
        $this->resetAfterTest();

        $this->assertSame(0, instance_manager::effective_retention_days(-1));
        set_config('retentiondays', 45, 'mod_presenterai');
        $this->assertSame(45, instance_manager::effective_retention_days(-1));
        $this->assertSame(0, instance_manager::effective_retention_days(0));
        $this->assertSame(7, instance_manager::effective_retention_days(7));
        set_config('retentiondays', -3, 'mod_presenterai');
        $this->assertSame(0, instance_manager::effective_retention_days(-1));
    }

    /**
     * An unknown grading method becomes highest; a known one is kept.
     *
     * @return void
     */
    public function test_gradingmethod(): void {
        $this->resetAfterTest();

        foreach (['highest', 'latest', 'average', 'first'] as $method) {
            $this->assertSame($method, instance_manager::normalise((object) ['gradingmethod' => $method], null)->gradingmethod);
        }
        $this->assertSame('highest', instance_manager::normalise((object) ['gradingmethod' => 'best'], null)->gradingmethod);
        $this->assertSame('highest', instance_manager::normalise((object) ['gradingmethod' => null], null)->gradingmethod);
    }

    /**
     * The completion numbers are bounded: submissions at 0 or more, the score at 0 to 100.
     *
     * @return void
     */
    public function test_completion_values(): void {
        $this->resetAfterTest();

        $this->assertSame(0, instance_manager::normalise((object) ['completionsubmit' => -4], null)->completionsubmit);
        $this->assertSame(3, instance_manager::normalise((object) ['completionsubmit' => '3'], null)->completionsubmit);
        $this->assertSame(0, instance_manager::normalise((object) ['completionminscore' => -1], null)->completionminscore);
        $this->assertSame(100, instance_manager::normalise((object) ['completionminscore' => 250], null)->completionminscore);
        $this->assertSame(65, instance_manager::normalise((object) ['completionminscore' => 65], null)->completionminscore);
    }

    /**
     * Grade and completion fields that weren't sent aren't added, and the grade passes through untouched.
     *
     * @return void
     */
    public function test_grade_fields_only_when_present(): void {
        $this->resetAfterTest();

        $data = instance_manager::normalise((object) ['mode' => 'video'], null);
        $this->assertFalse(property_exists($data, 'gradingmethod'));
        $this->assertFalse(property_exists($data, 'completionsubmit'));
        $this->assertFalse(property_exists($data, 'completionminscore'));

        $this->assertSame(-7, instance_manager::normalise((object) ['grade' => -7], null)->grade);
        $this->assertSame(250, instance_manager::normalise((object) ['grade' => 250], null)->grade);
    }

    /**
     * Presentation type and speaking level fall back to their defaults; the rubric must be visible and active.
     *
     * @return void
     */
    public function test_ptype_level_and_rubric(): void {
        $this->resetAfterTest();

        $this->assertSame('informative', instance_manager::normalise((object) ['ptype' => 'rant'], null)->ptype);
        $this->assertSame('persuasive', instance_manager::normalise((object) ['ptype' => 'persuasive'], null)->ptype);
        $this->assertNull(instance_manager::normalise((object) ['speakinglevel' => ''], null)->speakinglevel);
        $this->assertNull(instance_manager::normalise((object) ['speakinglevel' => 'general'], null)->speakinglevel);
        $this->assertNull(instance_manager::normalise((object) ['speakinglevel' => 'klingon'], null)->speakinglevel);
        $this->assertSame(
            'esl_intermediate',
            instance_manager::normalise((object) ['speakinglevel' => 'esl_intermediate'], null)->speakinglevel
        );

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $mine = \mod_presenterai\local\rubric_manager::create(
            (int) \context_course::instance($course->id)->id,
            'speech',
            'Mine',
            [['name' => 'Pace']]
        );
        $theirs = \mod_presenterai\local\rubric_manager::create(
            (int) \context_course::instance($other->id)->id,
            'speech',
            'Theirs',
            [['name' => 'Pace']]
        );

        $this->assertNull(instance_manager::normalise((object) ['rubricid' => 0, 'course' => $course->id], null)->rubricid);
        $chosen = instance_manager::normalise((object) ['rubricid' => $mine, 'course' => $course->id], null);
        $this->assertSame($mine, $chosen->rubricid);
        $this->assertNull(
            instance_manager::normalise((object) ['rubricid' => $theirs, 'course' => $course->id], null)->rubricid,
            'A rubric from another course was accepted.'
        );
        \mod_presenterai\local\rubric_manager::update($mine, 'Mine', [['name' => 'Pace']], false);
        $this->assertNull(instance_manager::normalise((object) ['rubricid' => $mine, 'course' => $course->id], null)->rubricid);
    }
}
