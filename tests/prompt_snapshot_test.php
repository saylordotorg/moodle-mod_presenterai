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
use mod_presenterai\local\scoring_prompt;
use mod_presenterai\local\slide_context;
use mod_presenterai\local\vision\visual_prompts;

/**
 * Every prompt the plugin sends a model, and the default rubric, are exactly what they were before they moved
 * into lang/en (0.5.2, review issue 16), and they stay English whatever language is chosen.
 *
 * tests/fixtures/prompt_snapshots.json holds the text the 0.5.1 code produced for a fixed set of inputs. A
 * changed word in a prompt changes what the model is told, so any difference here is a decision to make on
 * purpose, not a side effect of editing a lang file. To accept one, regenerate the fixture and say why.
 *
 * @package    mod_presenterai
 * @category   test
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_presenterai\local\prompt_text
 * @covers     \mod_presenterai\local\scoring_prompt
 * @covers     \mod_presenterai\local\slide_context
 * @covers     \mod_presenterai\local\rubric_manager
 * @covers     \mod_presenterai\local\vision\visual_prompts
 */
final class prompt_snapshot_test extends \advanced_testcase {
    /**
     * The stored snapshot.
     *
     * @return array
     */
    private function snapshot(): array {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/prompt_snapshots.json');
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * The body language prompts, byte for byte.
     *
     * @return void
     */
    public function test_visual_prompts_are_unchanged(): void {
        $expected = $this->snapshot()['visual_prompts'];
        $actual = [
            'vision' => visual_prompts::vision(),
            'vision_user' => visual_prompts::vision_user(),
            'summary_english' => visual_prompts::summary('English'),
            'summary_spanish' => visual_prompts::summary('Spanish'),
            'judge' => visual_prompts::judge(),
            'scoring_block_default_names' => visual_prompts::scoring_block('Hands low. Steady stance.', true, 'English'),
            'scoring_block_unscored' => visual_prompts::scoring_block('Hands low. Steady stance.', false, 'English'),
            'scoring_block_two_names' => visual_prompts::scoring_block(
                'Note text',
                true,
                'French',
                ['Body Language & Gestures', 'Eye Contact & Camera Presence']
            ),
            'scoring_block_one_name' => visual_prompts::scoring_block('Note text', true, 'German', ['Gestures']),
        ];
        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $name => $text) {
            $this->assertSame($text, $actual[$name], $name);
        }
    }

    /**
     * The slide design prompt, byte for byte.
     *
     * @return void
     */
    public function test_the_slide_vision_prompt_is_unchanged(): void {
        $expected = $this->snapshot()['slide_vision_prompt'];
        foreach (['informative' => 'informative', 'persuasive' => 'persuasive', 'empty' => ''] as $key => $ptype) {
            $this->assertSame($expected[$key], slide_context::vision_prompt($ptype), 'ptype "' . $ptype . '"');
        }
    }

    /**
     * The presentation type hints, byte for byte.
     *
     * @return void
     */
    public function test_ptype_hints_are_unchanged(): void {
        foreach ($this->snapshot()['ptype_hint'] as $ptype => $text) {
            $this->assertSame($text, scoring_prompt::ptype_hint((string) $ptype), 'ptype "' . $ptype . '"');
        }
    }

    /**
     * The default rubric, the visual criteria and every speaking level preset, byte for byte.
     *
     * @return void
     */
    public function test_the_rubric_defaults_are_unchanged(): void {
        $expected = $this->snapshot()['rubric'];
        $this->assertSame($expected['default_criteria'], rubric_manager::default_criteria());
        $this->assertSame($expected['visual_criteria'], rubric_manager::visual_criteria());
        $this->assertSame($expected['presets'], rubric_manager::speech_presets());
        foreach (rubric_manager::LEVELS as $level) {
            $this->assertSame($expected['hint_' . $level], rubric_manager::preset_hint($level), $level);
            $this->assertSame($expected['criteria_' . $level], rubric_manager::preset_criteria($level, false), $level);
            $this->assertSame($expected['criteria_visual_' . $level], rubric_manager::preset_criteria($level, true), $level);
        }
    }

    /**
     * The inputs the snapshot's scoring prompts were built from.
     *
     * @return array name => the build() input
     */
    private function scoring_inputs(): array {
        $crit = rubric_manager::preset_criteria('general', false);
        $base = [
            'transcript' => 'Hello everyone. Today I will talk about <slides> and rivers.',
            'criteria' => $crit,
            'level' => 'general',
            'ptype' => 'informative',
            'topictitle' => '',
            'minseconds' => 0,
            'maxseconds' => 0,
            'durationseconds' => 0,
            'slides' => '',
            'visualblock' => '',
            'wantsummary' => false,
        ];
        $variants = [
            'plain' => [],
            'persuasive_topic' => ['ptype' => 'persuasive', 'topictitle' => 'Why rivers matter'],
            'range' => ['minseconds' => 120, 'maxseconds' => 300, 'durationseconds' => 200],
            'max_only' => ['maxseconds' => 90, 'durationseconds' => 95],
            'min_only' => ['minseconds' => 60, 'durationseconds' => 30],
            'slides' => ['slides' => "Slide 1: Rivers\nSlide 2: Floods"],
            'visual' => [
                'visualblock' => 'VISUAL EVIDENCE: note',
                'wantsummary' => true,
                'criteria' => rubric_manager::preset_criteria('general', true),
            ],
            'esl_beginner' => ['level' => 'esl_beginner', 'criteria' => rubric_manager::preset_criteria('esl_beginner', false)],
            'esl_intermediate_persuasive' => [
                'level' => 'esl_intermediate',
                'ptype' => 'persuasive',
                'criteria' => rubric_manager::preset_criteria('esl_intermediate', false),
                'slides' => 'Slide 1: Water',
            ],
            'esl_advanced_all' => [
                'level' => 'esl_advanced',
                'ptype' => 'informative',
                'topictitle' => 'Idiom',
                'minseconds' => 30,
                'maxseconds' => 60,
                'durationseconds' => 45,
                'slides' => 'S',
                'visualblock' => 'V',
                'wantsummary' => true,
                'criteria' => rubric_manager::preset_criteria('esl_advanced', true),
            ],
        ];
        $inputs = [];
        foreach ($variants as $name => $over) {
            $inputs[$name] = array_merge($base, $over);
        }
        return $inputs;
    }

    /**
     * The scoring prompt, system and user message, byte for byte, for every combination in the snapshot.
     *
     * @return void
     */
    public function test_scoring_prompts_are_unchanged(): void {
        $expected = $this->snapshot()['scoring_prompt'];
        $this->assertSame($expected['_user_message_const'], scoring_prompt::user_message());
        foreach ($this->scoring_inputs() as $name => $input) {
            $built = scoring_prompt::build($input);
            $this->assertSame($expected[$name]['system'], $built['system'], $name . ' system');
            $this->assertSame($expected[$name]['user'], $built['user'], $name . ' user');
        }
    }

    /**
     * Prompts and rubric data are English whatever language the user or the site has chosen.
     *
     * @return void
     */
    public function test_prompts_stay_english_in_another_language(): void {
        $this->resetAfterTest();
        $expected = $this->snapshot()['scoring_prompt']['esl_beginner'];
        $input = $this->scoring_inputs()['esl_beginner'];

        force_current_language('fr');
        $built = scoring_prompt::build($input);

        $this->assertSame($expected['system'], $built['system']);
        $this->assertSame('Delivery & Fluency', rubric_manager::default_criteria()[0]['name']);
        $this->assertStringStartsWith('You are shown a single image', visual_prompts::vision());
    }
}
