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

    /**
     * The speaking level presets and the presentation type presets, all spoken criteria at 5 with an English hint;
     * unknown levels read as general.
     *
     * @return void
     */
    public function test_presets(): void {
        $presets = rubric_manager::speech_presets();
        $this->assertSame(array_merge(rubric_manager::LEVELS, [
            rubric_manager::PRESET_PRACTICE,
            rubric_manager::PRESET_INFORMATIVE,
            rubric_manager::PRESET_PERSUASIVE,
            rubric_manager::PRESET_ENTERTAIN,
        ]), array_keys($presets));
        $counts = ['practice' => 3, 'informative' => 6, 'persuasive' => 7, 'entertain' => 6];
        foreach ($presets as $level => $preset) {
            $this->assertCount($counts[$level] ?? 5, $preset['criteria'], $level);
            $this->assertNotSame('', $preset['hint']);
            foreach (rubric_manager::preset_criteria($level, false) as $criterion) {
                $this->assertSame(5, $criterion['max_score']);
                $this->assertFalse($criterion['visual']);
            }
        }
        foreach (rubric_manager::LEVELS as $level) {
            $this->assertNotEmpty(get_string('level_' . $level, 'mod_presenterai'));
        }
        foreach (rubric_manager::PRESETS as $preset) {
            $this->assertArrayHasKey($preset, $presets);
            $this->assertNotEmpty(get_string('presentationtype_' . $preset, 'mod_presenterai'));
        }
        $this->assertSame(rubric_manager::default_criteria(), $presets['general']['criteria']);
        $this->assertStringContainsString('beginner-level', rubric_manager::preset_hint('esl_beginner'));
        $this->assertSame(rubric_manager::preset_hint('general'), rubric_manager::preset_hint('nonsense'));
        $this->assertSame('general', rubric_manager::clean_level(null));
        $this->assertSame('esl_advanced', rubric_manager::clean_level('esl_advanced'));

        $video = rubric_manager::preset_criteria('esl_intermediate', true);
        $this->assertCount(7, $video);
        $visualnames = array_column(array_slice($video, 5), 'name');
        $this->assertSame(['Body Language & Gestures', 'Eye Contact & Camera Presence'], $visualnames);
        $this->assertSame([true, true], array_column(array_slice($video, 5), 'visual'));
    }

    /**
     * The visual criteria are SOLA's, with "hair or clothing" struck (design 12.2 F5).
     *
     * @return void
     */
    public function test_visual_criteria_text(): void {
        $this->assertCount(2, rubric_manager::visual_criteria());
        $body = rubric_manager::visual_criteria()[0]['description'];
        $this->assertStringNotContainsString('hair', $body);
        $this->assertStringNotContainsString('clothing', $body);
        $this->assertStringEndsWith('hands in pockets or folded, or playing with an object).', $body);
        foreach (rubric_manager::visual_criteria() as $criterion) {
            $this->assertTrue($criterion['visual']);
            $this->assertSame(5, $criterion['max_score']);
        }
    }

    /**
     * The default follows the speaking level, and gains the visual criteria only for video with videovision.
     *
     * @return void
     */
    public function test_default_follows_level_and_videovision(): void {
        $this->instance->speakinglevel = 'esl_beginner';
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame(0, $resolved['rubricid']);
        $this->assertSame('Pronunciation & Intelligibility', $resolved['criteria'][0]['name']);
        $this->assertCount(5, $resolved['criteria']);
        $this->assertSame([true, true, true, true, true], array_column($resolved['criteria'], 'counts'));

        $this->instance->videovision = 1;
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertCount(7, $resolved['criteria']);
        $this->assertSame([false, false, false, false, false, true, true], array_column($resolved['criteria'], 'visual'));
        $this->assertSame(
            [true, true, true, true, true, false, false],
            array_column($resolved['criteria'], 'counts'),
            'Visual criteria are feedback only unless the activity scores them (D23).'
        );

        $this->instance->visualscored = 1;
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame([true, true, true, true, true, true, true], array_column($resolved['criteria'], 'counts'));

        $this->instance->mode = 'audio';
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertCount(5, $resolved['criteria'], 'An audio activity was given visual criteria.');
    }

    /**
     * A stored video rubric's visual flag survives, carries counts, and is dropped for audio.
     *
     * @return void
     */
    public function test_stored_visual_flag(): void {
        $id = rubric_manager::create((int) $this->ctx->id, 'video', 'Mine', [
            ['name' => 'Content', 'description' => 'c', 'max_score' => 4, 'visual' => false],
            ['name' => 'Gestures', 'description' => 'g', 'max_score' => 3, 'visual' => true],
        ]);
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame($id, $resolved['rubricid']);
        $this->assertSame([
            ['name' => 'Content', 'description' => 'c', 'max_score' => 4, 'visual' => false, 'counts' => true],
            ['name' => 'Gestures', 'description' => 'g', 'max_score' => 3, 'visual' => true, 'counts' => false],
        ], $resolved['criteria']);

        $audio = clone $this->instance;
        $audio->mode = 'audio';
        $audio->rubricid = $id;
        $this->assertSame(['Content'], array_column(rubric_manager::resolve($audio, $this->ctx)['criteria'], 'name'));
    }

    /**
     * Create, update, list and delete, with the checks a stored rubric must pass.
     *
     * @return void
     */
    public function test_crud(): void {
        global $DB;

        $coursectx = \context_course::instance($this->course->id);
        $courseid = rubric_manager::create((int) $coursectx->id, 'speech', ' Course ', [
            ['name' => 'Pace', 'description' => 'p', 'max_score' => 5],
        ]);
        $modid = rubric_manager::create((int) $this->ctx->id, 'video', 'Module', [
            ['name' => 'Pace', 'description' => 'p', 'max_score' => 5],
            ['name' => 'Eye contact', 'description' => 'e', 'max_score' => 5, 'visual' => true],
        ]);
        $other = $this->getDataGenerator()->create_course();
        rubric_manager::create((int) \context_course::instance($other->id)->id, 'speech', 'Elsewhere', [
            ['name' => 'Pace', 'max_score' => 5],
        ]);

        $row = $DB->get_record('presenterai_rubric', ['id' => $courseid]);
        $this->assertSame('Course', $row->title);
        $this->assertSame(1, (int) $row->active);

        $list = rubric_manager::list_for_context($this->ctx);
        $this->assertSame([$modid, $courseid], array_keys($list), 'Nearest context first, and nothing from other courses.');
        $this->assertSame(2, (int) $list[$modid]->criteriacount);

        rubric_manager::update($modid, 'Module two', [['name' => 'Only', 'max_score' => 7, 'visual' => true]], false);
        $row = $DB->get_record('presenterai_rubric', ['id' => $modid]);
        $this->assertSame('Module two', $row->title);
        $this->assertSame(0, (int) $row->active);
        $this->assertSame(
            [['name' => 'Only', 'description' => '', 'max_score' => 7, 'visual' => true]],
            json_decode($row->criteria, true)
        );
        $this->assertSame([$courseid], array_keys(rubric_manager::list_active_for_context($this->ctx)));
        $this->assertFalse(rubric_manager::is_selectable($modid, $this->ctx), 'An inactive rubric was selectable.');
        $this->assertTrue(rubric_manager::is_selectable($courseid, $this->ctx));
        $this->assertTrue(rubric_manager::is_selectable($courseid, $coursectx));
        $this->assertFalse(rubric_manager::is_selectable($courseid, \context_course::instance($other->id)));

        $DB->set_field('presenterai', 'rubricid', $courseid, ['id' => $this->instance->id]);
        rubric_manager::delete($courseid);
        $this->assertFalse($DB->record_exists('presenterai_rubric', ['id' => $courseid]));
        $this->assertNull($DB->get_field('presenterai', 'rubricid', ['id' => $this->instance->id]));
    }

    /**
     * A stored rubric needs a known type, a title, criteria with unique names, and visual rows only on video.
     *
     * @return void
     */
    public function test_create_refuses_bad_rubrics(): void {
        $cases = [
            ['essay', 'T', [['name' => 'A']]],
            ['speech', '  ', [['name' => 'A']]],
            ['speech', 'T', []],
            ['speech', 'T', [['name' => 'Pace'], ['name' => ' pace ']]],
            ['speech', 'T', [['name' => 'Gestures', 'visual' => true]]],
        ];
        foreach ($cases as [$type, $title, $criteria]) {
            try {
                rubric_manager::create((int) $this->ctx->id, $type, $title, $criteria);
                $this->fail('A bad rubric was stored: ' . json_encode([$type, $title, $criteria]));
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
    }

    /**
     * Every criterion of every preset a new activity can use, and both visual criteria, has five written levels,
     * scores 5 to 1, each with a label and a description; the general preset every older activity uses has none.
     *
     * @return void
     */
    public function test_preset_levels_are_complete(): void {
        $checked = 0;
        $criteria = rubric_manager::visual_criteria();
        foreach (rubric_manager::PRESETS as $preset) {
            $criteria = array_merge($criteria, rubric_manager::speech_presets()[$preset]['criteria']);
        }
        foreach ($criteria as $criterion) {
            $name = $criterion['name'];
            $this->assertArrayHasKey('levels', $criterion, $name);
            $this->assertSame(rubric_manager::LEVEL_SCORES, array_column($criterion['levels'], 'score'), $name);
            foreach ($criterion['levels'] as $level) {
                $this->assertNotSame('', trim($level['label']), $name);
                $this->assertNotSame('', trim($level['description']), $name . ' level ' . $level['score']);
            }
            // Clean already: storing and reading back changes nothing.
            $this->assertSame($criterion['levels'], rubric_manager::normalise_levels($criterion['levels']), $name);
            $checked++;
        }
        $this->assertSame(2 + 3 + 6 + 7 + 6 + 5 + 5 + 5, $checked);

        $this->assertSame(
            ['Excellent', 'Proficient', 'Developing', 'Beginning', 'Not yet'],
            array_values(rubric_manager::default_level_labels())
        );
        $this->assertSame(
            array_values(rubric_manager::default_level_labels()),
            array_column(rubric_manager::preset_criteria('informative', false)[0]['levels'], 'label')
        );
        foreach (rubric_manager::default_criteria() as $criterion) {
            $this->assertArrayNotHasKey('levels', $criterion, 'The general preset was given levels.');
        }

        // The persuasive preset has the appeals criterion the informative one doesn't, and entertain swaps support
        // and sources for humor and storytelling.
        $names = fn(string $preset) => array_column(rubric_manager::preset_criteria($preset, false), 'name');
        $this->assertSame(['Introduction', 'Organization and transitions', 'Support and sources', 'Language', 'Delivery',
            'Conclusion'], $names('informative'));
        $this->assertContains('Persuasive appeals', $names('persuasive'));
        $this->assertNotContains('Persuasive appeals', $names('informative'));
        $this->assertContains('Humor and storytelling', $names('entertain'));
        $this->assertNotContains('Support and sources', $names('entertain'));
        $this->assertSame(['Clear introduction of yourself', 'Organization', 'Delivery'], $names('practice'));
    }

    /**
     * Levels survive a save and a read back; a criterion stored without them, as every rubric before 0.6.0 was,
     * reads exactly as before and gets none.
     *
     * @return void
     */
    public function test_levels_round_trip_and_back_compat(): void {
        global $DB;

        $levels = [];
        foreach ([1, 3, 5, 2, 4] as $score) {
            $levels[] = ['score' => (string) $score, 'label' => " L{$score} ", 'description' => " D{$score} "];
        }
        $criteria = [
            ['name' => 'Leveled', 'description' => 'd', 'max_score' => 8, 'visual' => false, 'levels' => $levels],
            ['name' => 'Plain', 'description' => 'p', 'max_score' => 4, 'visual' => false],
        ];
        $id = rubric_manager::create((int) $this->ctx->id, 'speech', 'Levels', $criteria);
        $stored = rubric_manager::normalise_criteria((string) $DB->get_field('presenterai_rubric', 'criteria', ['id' => $id]));

        $this->assertSame(5, $stored[0]['max_score'], 'A criterion with levels is scored out of 5.');
        $this->assertSame([5, 4, 3, 2, 1], array_column($stored[0]['levels'], 'score'));
        $this->assertSame(['L5', 'L4', 'L3', 'L2', 'L1'], array_column($stored[0]['levels'], 'label'));
        $this->assertSame('D3', $stored[0]['levels'][2]['description']);
        $this->assertSame(['name' => 'Plain', 'description' => 'p', 'max_score' => 4, 'visual' => false], $stored[1]);
        $this->assertSame($stored, rubric_manager::normalise_criteria(json_encode($stored)), 'Stable on a second read.');

        // The resolved rubric carries them to the scorer.
        $this->instance->rubricid = $id;
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame($stored[0]['levels'], $resolved['criteria'][0]['levels']);
        $this->assertArrayNotHasKey('levels', $resolved['criteria'][1]);

        // A row written before 0.6.0, read now: same shape, no levels.
        $old = $this->rubric((int) $this->ctx->id, 'video', 'Old');
        $this->assertSame(
            [['name' => 'Old', 'description' => 'd', 'max_score' => 4, 'visual' => false]],
            rubric_manager::normalise_criteria((string) $old->criteria)
        );
    }

    /**
     * Unusable levels are dropped as a whole, and the criterion keeps its own maximum.
     *
     * @return void
     */
    public function test_unusable_levels_are_dropped(): void {
        $good = [];
        foreach (rubric_manager::LEVEL_SCORES as $score) {
            $good[] = ['score' => $score, 'label' => 'L' . $score, 'description' => ''];
        }
        $this->assertNotNull(rubric_manager::normalise_levels($good), 'Empty descriptions are allowed.');

        $four = array_slice($good, 0, 4);
        $duplicate = $good;
        $duplicate[4]['score'] = 5;
        $nolabel = $good;
        $nolabel[2]['label'] = '  ';
        $outofrange = $good;
        $outofrange[4]['score'] = 0;
        foreach (
            ['four' => $four, 'duplicate' => $duplicate, 'nolabel' => $nolabel, 'outofrange' => $outofrange,
                'string' => 'levels', 'null' => null] as $case => $levels
        ) {
            $this->assertNull(rubric_manager::normalise_levels($levels), $case);
            $clean = rubric_manager::normalise_criteria([['name' => 'C', 'max_score' => 7, 'levels' => $levels]]);
            $this->assertSame([['name' => 'C', 'description' => '', 'max_score' => 7, 'visual' => false]], $clean, $case);
        }
    }

    /**
     * The label of the level a score reached, and none for 0, for an unassessed criterion or one without levels.
     *
     * @return void
     */
    public function test_level_label(): void {
        $criterion = rubric_manager::preset_criteria('informative', false)[0];
        $this->assertSame('Proficient', rubric_manager::level_label($criterion, 4));
        $this->assertSame('Not yet', rubric_manager::level_label($criterion, 1));
        $this->assertSame('', rubric_manager::level_label($criterion, 0));
        $this->assertSame('', rubric_manager::level_label($criterion, 4, false));
        $this->assertSame('', rubric_manager::level_label(rubric_manager::default_criteria()[0], 4));
    }

    /**
     * The fallback preset: the preset column when it names one, else the speaking level, which is what keeps an
     * activity made before 0.6.0 on the general rubric.
     *
     * @return void
     */
    public function test_preset_for(): void {
        $this->assertSame('general', rubric_manager::preset_for((object) []));
        $this->assertSame('general', rubric_manager::preset_for((object) ['ptype' => 'persuasive', 'speakinglevel' => null]));
        $this->assertSame('esl_beginner', rubric_manager::preset_for((object) ['speakinglevel' => 'esl_beginner']));
        $this->assertSame('persuasive', rubric_manager::preset_for((object) ['preset' => 'persuasive']));
        $this->assertSame('esl_advanced', rubric_manager::preset_for((object) ['preset' => 'nonsense',
            'speakinglevel' => 'esl_advanced']));
        $this->assertSame('', rubric_manager::clean_preset('esl'));

        // An activity on a preset resolves to it when nothing is stored.
        $this->instance->preset = 'practice';
        $resolved = rubric_manager::resolve($this->instance, $this->ctx);
        $this->assertSame(0, $resolved['rubricid']);
        $this->assertSame('Clear introduction of yourself', $resolved['criteria'][0]['name']);
        $this->assertCount(5, $resolved['criteria'][0]['levels']);

        // The course context works too, for the settings form's preview of an activity that doesn't exist yet.
        $resolved = rubric_manager::resolve($this->instance, \context_course::instance($this->course->id));
        $this->assertSame('Clear introduction of yourself', $resolved['criteria'][0]['name']);
    }

    /**
     * Names match case and whitespace insensitively.
     *
     * @return void
     */
    public function test_normalise_name(): void {
        $this->assertSame('body language & gestures', rubric_manager::normalise_name("  Body\tLanguage   &\nGestures "));
        $this->assertSame('élan', rubric_manager::normalise_name('ÉLAN'));
        $this->assertSame('', rubric_manager::normalise_name('   '));
    }
}
