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

namespace mod_presenterai\local\vision;

use mod_presenterai\local\prompt_text;

/**
 * The three prompts of the body language pipeline, verbatim from the design.
 *
 * Prompt 1 is the vision pass (design 3.3), prompt 2 the summary block the
 * scoring prompt carries (design 3.4) and prompt 3 the judge (design 3.5).
 * They stay in English whatever the learner's language (design 10): the
 * output language is named inside prompt 2, and a system prompt translated
 * 46 ways is 46 things to keep in step that follow instructions worse.
 *
 * The scoring prompt itself belongs to the scorer. What this class gives it
 * is the block that goes after the transcript: the evidence, the rule for
 * how the two visual criteria count (D23), and prompt 2.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class visual_prompts {
    /** @var int Characters of the note the scoring prompt carries, as SOLA's score_speech.php:211 did. */
    public const MAX_EVIDENCE_CHARS = 2000;

    /**
     * Prompt 1: the vision pass, design section 3.3.
     *
     * @return string
     */
    public static function vision(): string {
        return prompt_text::get('prompt:vision');
    }

    /**
     * The user message that goes with prompt 1 and the image.
     *
     * @return string
     */
    public static function vision_user(): string {
        return prompt_text::get('prompt:vision_user');
    }

    /**
     * The JSON schema for the vision pass.
     *
     * Every property required and additionalProperties false, with no enum
     * and no bounds, per the client rules: strict structured output rejects
     * some of those keywords over raw HTTP, so confidence is checked in PHP.
     *
     * @return array ['name' => string, 'schema' => array]
     */
    public static function vision_schema(): array {
        return [
            'name' => 'visual_evidence',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'note' => ['type' => 'string'],
                    'unusable_frames' => ['type' => 'integer'],
                    'confidence' => ['type' => 'string'],
                ],
                'required' => ['note', 'unusable_frames', 'confidence'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * The VISUAL EVIDENCE block, the counting rule and prompt 2, for the scoring prompt.
     *
     * The evidence block is a port of SOLA's score_speech.php:209-215. Its
     * last sentence there ("Scoring 0 with assessed true means you could see
     * the behaviour and it was absent") steered a learner whose behaviour is
     * visibly and permanently absent toward an assessed zero, so it is gone
     * (DECISIONS.md 9.17). In its place: when the activity scores body
     * language, the D23 counterweight; when it doesn't, the feedback only rule.
     *
     * Both rules name the visual criteria. A teacher can flag any criterion as
     * visual under any name, and there may be one or three of them, so "the
     * visual criteria" or "these two" leaves the model to guess which rubric
     * lines the counterweight covers, and a guess that misses "Stage presence"
     * is an assessed zero for a learner who can't stand in frame.
     *
     * The block also keeps body language in the two places the D21 gate
     * checks: the summary and the visual criteria's feedback.
     *
     * @param string $note The vision pass's note.
     * @param bool $visualscored Whether the activity counts the visual criteria (D23).
     * @param string $languagename The English name of the learner's language.
     * @param string[] $visualnames The names of the visual criteria in the prompt's rubric; [] for the seed names.
     * @return string
     */
    public static function scoring_block(string $note, bool $visualscored, string $languagename, array $visualnames = []): string {
        $evidence = prompt_text::get('prompt:visualevidence') . "\n"
            . \core_text::substr(trim($note), 0, self::MAX_EVIDENCE_CHARS);

        $visualnames = array_values(array_filter(array_map('trim', array_map('strval', $visualnames)), 'strlen'));
        if (empty($visualnames)) {
            $visualnames = visual_pipeline::visual_criterion_names();
        }
        $quoted = implode(', ', array_map(fn($name) => '"' . $name . '"', $visualnames));
        $which = prompt_text::get(count($visualnames) === 1 ? 'prompt:visualwhich_one' : 'prompt:visualwhich_many', $quoted);

        if ($visualscored) {
            $rule = $which . ' ' . prompt_text::get('prompt:visualrule_scored');
        } else {
            $rule = $which . ' ' . prompt_text::get('prompt:visualrule_feedbackonly');
        }
        $rule .= ' ' . prompt_text::get('prompt:visualrule_bodylanguage');

        return $evidence . "\n\n" . $rule . "\n\n" . self::summary($languagename);
    }

    /**
     * Prompt 2: the summary block, design section 3.4, with {LANGUAGE} filled.
     *
     * @param string $languagename The English name of the learner's language.
     * @return string
     */
    public static function summary(string $languagename): string {
        return prompt_text::get('prompt:visualsummary', $languagename);
    }

    /**
     * Prompt 3: the judge, design section 3.5.
     *
     * @return string
     */
    public static function judge(): string {
        return prompt_text::get('prompt:judge');
    }

    /**
     * The JSON schema for the judge's reply.
     *
     * @return array ['name' => string, 'schema' => array]
     */
    public static function judge_schema(): array {
        return [
            'name' => 'judge_verdicts',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'verdicts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'n' => ['type' => 'integer'],
                                'pass' => ['type' => 'boolean'],
                                'rule' => ['type' => 'string'],
                            ],
                            'required' => ['n', 'pass', 'rule'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['verdicts'],
                'additionalProperties' => false,
            ],
        ];
    }
}
