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
use mod_presenterai\local\presentation_types;
use mod_presenterai\local\rubric_manager;

/**
 * The presentation types of the settings form (0.6.0): their defaults, and how each is stored.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\presentation_types
 * @covers     \mod_presenterai\local\instance_manager
 */
final class presentation_types_test extends \advanced_testcase {
    /**
     * Every type's suggested length, slides and starter topics, as the brief sets them.
     *
     * @return void
     */
    public function test_defaults_per_type(): void {
        $expected = [
            'practice' => [20, 90, 0],
            'informative' => [180, 300, 1],
            'persuasive' => [240, 360, 1],
            'entertain' => [180, 300, 1],
            'esl_beginner' => [60, 180, 1],
            'esl_intermediate' => [60, 180, 1],
            'esl_advanced' => [60, 180, 1],
        ];
        $this->assertSame(array_keys($expected), presentation_types::types());
        foreach ($expected as $type => [$min, $max, $slides]) {
            $defaults = presentation_types::defaults($type);
            $this->assertSame('video', $defaults['mode'], $type);
            $this->assertSame($min, $defaults['minseconds'], $type);
            $this->assertSame($max, $defaults['maxseconds'], $type);
            $this->assertSame($slides, $defaults['slidesenabled'], $type);
            $this->assertGreaterThanOrEqual(2, count($defaults['topics']), $type);
            $this->assertLessThanOrEqual(3, count($defaults['topics']), $type);
            foreach ($defaults['topics'] as $topic) {
                $this->assertNotSame('', trim($topic), $type);
            }
        }
        $this->assertSame(presentation_types::defaults('informative'), presentation_types::defaults('nonsense'));
        $this->assertSame(presentation_types::defaults('persuasive'), presentation_types::defaults('general_persuasive'));
        $this->assertSame(array_keys($expected), array_keys(presentation_types::all_defaults()));
        $this->assertSame('informative', presentation_types::DEFAULT_TYPE);
    }

    /**
     * Each type is stored as ptype, speakinglevel and preset, and an existing activity reads back as its type.
     *
     * @return void
     */
    public function test_columns_and_round_trip(): void {
        $this->assertSame(
            ['ptype' => 'practice', 'speakinglevel' => null, 'preset' => 'practice'],
            presentation_types::columns('practice')
        );
        $this->assertSame(
            ['ptype' => 'entertain', 'speakinglevel' => null, 'preset' => 'entertain'],
            presentation_types::columns('entertain')
        );
        $this->assertSame(
            ['ptype' => 'informative', 'speakinglevel' => 'esl_beginner', 'preset' => 'esl_beginner'],
            presentation_types::columns('esl_beginner')
        );
        $this->assertSame(
            'persuasive',
            presentation_types::columns('esl_advanced', 'persuasive')['ptype'],
            'An ESL activity keeps its persuasive ptype.'
        );
        $this->assertSame(
            ['ptype' => 'persuasive', 'speakinglevel' => null, 'preset' => null],
            presentation_types::columns('general_persuasive')
        );
        $this->assertSame(presentation_types::columns('informative'), presentation_types::columns('rant'));

        foreach (array_merge(presentation_types::types(), ['general_informative', 'general_persuasive']) as $type) {
            $row = (object) presentation_types::columns($type);
            $this->assertSame($type, presentation_types::from_instance($row), $type);
            $this->assertContains(rubric_manager::preset_for($row), array_keys(rubric_manager::speech_presets()));
            $this->assertContains($row->ptype, instance_manager::PTYPES, $type);
        }

        // Activities made before 0.6.0.
        $this->assertSame('general_informative', presentation_types::from_instance((object) ['ptype' => 'informative']));
        $this->assertSame('general_persuasive', presentation_types::from_instance((object) ['ptype' => 'persuasive']));
        $this->assertSame(
            'esl_intermediate',
            presentation_types::from_instance((object) ['ptype' => 'persuasive', 'speakinglevel' => 'esl_intermediate'])
        );
    }

    /**
     * A new activity is offered the seven types; an older one also its own general type, and only that one.
     *
     * @return void
     */
    public function test_options(): void {
        $this->assertSame(presentation_types::types(), array_keys(presentation_types::options()));
        $this->assertSame(
            array_merge(presentation_types::types(), ['general_persuasive']),
            array_keys(presentation_types::options('general_persuasive'))
        );
        $this->assertSame(presentation_types::types(), array_keys(presentation_types::options('esl_beginner')));
        $this->assertSame('Introduction or practice', presentation_types::options()['practice']);
        $this->assertSame('ESL Advanced', presentation_types::options()['esl_advanced']);
    }

    /**
     * normalise() turns the form's type into the three columns, and keeps an older activity on the general rubric.
     *
     * @return void
     */
    public function test_normalise_maps_the_type(): void {
        $data = instance_manager::normalise((object) ['presentationtype' => 'persuasive'], null);
        $this->assertSame('persuasive', $data->ptype);
        $this->assertNull($data->speakinglevel);
        $this->assertSame('persuasive', $data->preset);
        $this->assertFalse(property_exists($data, 'presentationtype'), 'The form field is not a column.');

        $existing = (object) ['ptype' => 'persuasive', 'speakinglevel' => null, 'preset' => null];
        $data = instance_manager::normalise((object) ['presentationtype' => 'esl_beginner'], $existing);
        $this->assertSame(['persuasive', 'esl_beginner', 'esl_beginner'], [$data->ptype, $data->speakinglevel, $data->preset]);

        $data = instance_manager::normalise((object) ['presentationtype' => 'general_informative'], $existing);
        $this->assertSame(['informative', null, null], [$data->ptype, $data->speakinglevel, $data->preset]);

        $this->assertSame('practice', instance_manager::normalise((object) ['ptype' => 'practice'], null)->ptype);
        $this->assertSame('entertain', instance_manager::normalise((object) ['ptype' => 'entertain'], null)->ptype);
        $this->assertNull(instance_manager::normalise((object) ['preset' => 'nonsense'], null)->preset);
        $this->assertSame('practice', instance_manager::normalise((object) ['preset' => 'practice'], null)->preset);
    }

    /**
     * A new activity made through add_moduleinfo() with a type stores its preset and resolves to it; one made
     * without a type, as the importer and older callers do, stays on the general rubric.
     *
     * @return void
     */
    public function test_new_activity_gets_its_preset(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $typed = $this->getDataGenerator()->create_module('presenterai', [
            'course' => $course->id,
            'presentationtype' => 'persuasive',
        ]);
        $row = $DB->get_record('presenterai', ['id' => $typed->id], '*', MUST_EXIST);
        $this->assertSame('persuasive', $row->preset);
        $resolved = rubric_manager::resolve($row, \context_module::instance($typed->cmid));
        $this->assertSame('Introduction', $resolved['criteria'][0]['name']);
        $this->assertContains('Persuasive appeals', array_column($resolved['criteria'], 'name'));

        $plain = $this->getDataGenerator()->create_module('presenterai', ['course' => $course->id]);
        $row = $DB->get_record('presenterai', ['id' => $plain->id], '*', MUST_EXIST);
        $this->assertNull($row->preset);
        $resolved = rubric_manager::resolve($row, \context_module::instance($plain->cmid));
        $this->assertSame('Delivery & Fluency', $resolved['criteria'][0]['name'], 'An activity without a type changed rubric.');
    }
}
