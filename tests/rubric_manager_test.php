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

use mod_presenterai\local\rubric_manager;

/**
 * Which rubric a score is entered against.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\rubric_manager
 */
final class rubric_manager_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The activity, carrying cmid. */
    private \stdClass $instance;

    /** @var \context_module The activity's context. */
    private \context_module $ctx;

    /** @var \mod_presenterai_generator The plugin generator. */
    private \mod_presenterai_generator $gen;

    /**
     * One course with one video activity.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->ctx = \context_module::instance($this->instance->cmid);
        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_presenterai');
    }

    /**
     * A rubric with one named criterion, so tests can tell rubrics apart.
     *
     * @param int $contextid The context it belongs to.
     * @param string $type speech or video.
     * @param string $name The criterion's name.
     * @param int $active 1 or 0.
     * @return \stdClass The rubric row.
     */
    private function rubric(int $contextid, string $type, string $name, int $active = 1): \stdClass {
        return $this->gen->create_rubric([
            'contextid' => $contextid,
            'type' => $type,
            'title' => $name . ' rubric',
            'criteria' => json_encode([['name' => $name, 'description' => 'd', 'max_score' => 4, 'visual' => false]]),
            'active' => $active,
        ]);
    }

    /**
     * With nothing stored, the in-code default is used and nothing is written.
     *
     * @return void
     */
    public function test_default_when_nothing_stored(): void {
        global $DB;

        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame(0, $resolved['rubricid']);
        $this->assertSame(get_string('defaultrubric', 'mod_presenterai'), $resolved['title']);
        $this->assertCount(5, $resolved['criteria']);
        $this->assertSame('Delivery & Fluency', $resolved['criteria'][0]['name']);
        $this->assertSame(5, $resolved['criteria'][0]['max_score']);
        $this->assertFalse($resolved['criteria'][0]['visual']);
        $this->assertSame(0, $DB->count_records('presenterai_rubric'), 'The default is never seeded.');
    }

    /**
     * An explicit rubric on the activity beats a nearer stored one; an inactive one is ignored.
     *
     * @return void
     */
    public function test_explicit_instance_rubric(): void {
        global $DB;

        $this->rubric($this->ctx->id, 'video', 'Module video');
        $coursectx = \context_course::instance($this->course->id);
        $chosen = $this->rubric($coursectx->id, 'speech', 'Chosen');
        $DB->set_field('presenterai', 'rubricid', $chosen->id, ['id' => $this->instance->id]);
        $instance = $DB->get_record('presenterai', ['id' => $this->instance->id]);

        $resolved = rubric_manager::resolve($instance, $this->ctx);
        $this->assertSame((int) $chosen->id, $resolved['rubricid']);
        $this->assertSame('Chosen', $resolved['criteria'][0]['name']);

        $DB->set_field('presenterai_rubric', 'active', 0, ['id' => $chosen->id]);
        $resolved = rubric_manager::resolve($instance, $this->ctx);
        $this->assertSame('Module video', $resolved['criteria'][0]['name'], 'An inactive explicit rubric falls through.');
    }

    /**
     * Inactive rubrics are skipped during the walk.
     *
     * @return void
     */
    public function test_inactive_rubric_ignored(): void {
        $this->rubric($this->ctx->id, 'speech', 'Off', 0);
        $this->assertSame(0, rubric_manager::resolve($this->instance, $this->ctx)['rubricid']);
    }

    /**
     * The nearest context wins over a farther one, whatever the type preference.
     *
     * @return void
     */
    public function test_nearest_context_wins(): void {
        $system = \context_system::instance();
        $category = \context_coursecat::instance($this->course->category);
        $course = \context_course::instance($this->course->id);

        $this->rubric($system->id, 'video', 'Site');
        $this->assertSame('Site', rubric_manager::resolve($this->instance, $this->ctx)['criteria'][0]['name']);

        $this->rubric($category->id, 'speech', 'Category');
        $this->assertSame('Category', rubric_manager::resolve($this->instance, $this->ctx)['criteria'][0]['name']);

        $this->rubric($course->id, 'speech', 'Course');
        $this->assertSame('Course', rubric_manager::resolve($this->instance, $this->ctx)['criteria'][0]['name']);

        // Another activity's rubric is not on this activity's path.
        $other = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id]);
        $this->rubric(\context_module::instance($other->cmid)->id, 'speech', 'Elsewhere');
        $this->assertSame('Course', rubric_manager::resolve($this->instance, $this->ctx)['criteria'][0]['name']);
    }

    /**
     * At one level, video beats speech for a video activity; an audio one takes speech only.
     *
     * @return void
     */
    public function test_video_before_speech(): void {
        $course = \context_course::instance($this->course->id);
        $this->rubric($course->id, 'speech', 'Speech');
        $this->rubric($course->id, 'video', 'Video');

        $this->assertSame('Video', rubric_manager::resolve($this->instance, $this->ctx)['criteria'][0]['name']);

        $audio = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id, 'mode' => 'audio']);
        $audioctx = \context_module::instance($audio->cmid);
        $this->assertSame('Speech', rubric_manager::resolve($audio, $audioctx)['criteria'][0]['name']);

        // An audio activity never falls back to a video rubric.
        $audio2 = $this->getDataGenerator()->create_module('presenterai', ['course' => $this->course->id, 'mode' => 'audio']);
        $this->rubric(\context_module::instance($audio2->cmid)->id, 'video', 'Video only');
        $this->assertSame(
            'Speech',
            rubric_manager::resolve($audio2, \context_module::instance($audio2->cmid))['criteria'][0]['name']
        );
    }

    /**
     * A stored rubric whose criteria are all junk is skipped.
     *
     * @return void
     */
    public function test_rubric_with_no_usable_criteria_is_skipped(): void {
        $this->gen->create_rubric(['contextid' => $this->ctx->id, 'type' => 'video', 'criteria' => '{not json']);
        $this->assertSame(0, rubric_manager::resolve($this->instance, $this->ctx)['rubricid']);
    }

    /**
     * normalise_criteria drops junk and fills in sane values.
     *
     * @return void
     */
    public function test_normalise_criteria(): void {
        $this->assertSame([], rubric_manager::normalise_criteria('not json'));
        $this->assertSame([], rubric_manager::normalise_criteria(42));

        $clean = rubric_manager::normalise_criteria(json_encode([
            ['name' => '  Pace  ', 'description' => 'Speed', 'max_score' => '3', 'visual' => 1, 'extra' => 'x'],
            ['name' => ''],
            'a string',
            ['description' => 'no name'],
            ['name' => ['an', 'array']],
            ['name' => 'Zero max', 'max_score' => 0],
            ['name' => 'No max'],
        ]));
        $this->assertSame([
            ['name' => 'Pace', 'description' => 'Speed', 'max_score' => 3, 'visual' => true],
            ['name' => 'Zero max', 'description' => '', 'max_score' => 5, 'visual' => false],
            ['name' => 'No max', 'description' => '', 'max_score' => 5, 'visual' => false],
        ], $clean);

        $this->assertSame($clean, rubric_manager::normalise_criteria(json_decode(json_encode([
            ['name' => '  Pace  ', 'description' => 'Speed', 'max_score' => '3', 'visual' => 1],
            ['name' => 'Zero max', 'max_score' => 0],
            ['name' => 'No max'],
        ]), true)), 'Decoded input gives the same result.');
    }

    /**
     * Only an explicit false means not assessed.
     *
     * @return void
     */
    public function test_is_assessed(): void {
        $this->assertTrue(rubric_manager::is_assessed([]));
        $this->assertTrue(rubric_manager::is_assessed(['assessed' => true]));
        $this->assertTrue(rubric_manager::is_assessed(['assessed' => null]));
        $this->assertFalse(rubric_manager::is_assessed(['assessed' => false]));
    }
}
