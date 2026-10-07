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
        return <<<'PROMPT'
You are shown a single image containing still frames sampled at even intervals
across one learner's recorded presentation, in order from the start of the talk
to the end. There are six of them and they are six separate moments, not
continuous video.

Report ONLY what is visible. Do not score, rate or evaluate anything. Do not
guess at what is not visible. Reporting that you cannot tell is more useful than
a confident guess.

In "note", write four to six sentences of plain prose covering:
- what the hands and arms are doing, and whether that changes across the frames;
- posture and stance, and any repeated movement such as rocking, pacing or
  fidgeting;
- where the eyes appear to be directed, for example toward the camera, downward
  at notes, or off to one side;
- how the speaker is framed: how much of them is in shot, whether the hands are
  in view, and whether the face is lit well enough to read.

Say how many of the six frames each observation is based on. Do not write
"throughout", "for most of the talk" or "consistently": you have six moments and
you cannot see what happened between them.

Never write about the speaker's appearance, face or body except as it bears on
the four points above. Never write about ethnicity, race, age, gender, build,
health, disability, hair, clothing, jewellery or religious dress. Never write
about the room, the background, or anything in it. Never read or transcribe text
in the image. If another person is visible, ignore them completely and report
only on the speaker.

In "unusable_frames", give how many of the frames are too dark, too blurred, too
distant, or cropped so that the hands or the face cannot be seen.

In "confidence", answer "high" when at least four frames are readable and the
hands and face are in shot, "medium" when the frames are readable but part of
the speaker is out of shot, and "low" when three or more frames are unusable.
PROMPT;
    }

    /**
     * The user message that goes with prompt 1 and the image.
     *
     * @return string
     */
    public static function vision_user(): string {
        return 'Report what is visible in these frames now.';
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
        $evidence = "VISUAL EVIDENCE (a description of still frames sampled from the recording, not continuous video):\n"
            . \core_text::substr(trim($note), 0, self::MAX_EVIDENCE_CHARS);

        $visualnames = array_values(array_filter(array_map('trim', array_map('strval', $visualnames)), 'strlen'));
        if (empty($visualnames)) {
            $visualnames = visual_pipeline::VISUAL_CRITERION_NAMES;
        }
        $quoted = implode(', ', array_map(fn($name) => '"' . $name . '"', $visualnames));
        $which = count($visualnames) === 1
            ? 'The visual criterion is ' . $quoted . '.'
            : 'The visual criteria are ' . $quoted . '.';

        if ($visualscored) {
            $rule = $which . ' Score the visual criteria from this evidence ONLY. If the evidence does not let you judge a '
                . 'criterion, set its assessed field to false and its score to 0; never guess. If a behaviour could '
                . 'not have been shown because of how the speaker is seated, positioned or framed, or because of '
                . 'anything the speaker cannot change about themselves, set assessed to false. Score 0 with assessed '
                . 'true only when the behaviour was clearly possible for this speaker in these frames and was absent.';
        } else {
            $rule = $which . ' They are feedback only for this activity and do not affect the learner\'s score. '
                . 'Still give each a score and an assessed flag, and write feedback the learner can act on.';
        }
        $rule .= ' Write about body language (hands, arms, posture, stance, movement, gaze, framing and lighting) only '
            . 'in the visual criteria\'s feedback and in visual_summary, never in the overall comment, the tips or any '
            . 'other criterion\'s feedback.';

        return $evidence . "\n\n" . $rule . "\n\n" . self::summary($languagename);
    }

    /**
     * Prompt 2: the summary block, design section 3.4, with {LANGUAGE} filled.
     *
     * @param string $languagename The English name of the learner's language.
     * @return string
     */
    public static function summary(string $languagename): string {
        $prompt = <<<'PROMPT'
VISUAL SUMMARY. Also produce "visual_summary": two or three sentences, at most
60 words, written to the learner in the second person, saying what the visual
evidence above shows about their presentation. This is the only part of the
visual evidence the learner will ever read, so write it as the plain-language
version of that evidence, not as a repeat of your criterion comments.

Write only about things the learner can do differently in their next recording:
what their hands and arms did, their posture and stance, any repeated movement,
where they were looking, how much of them was in shot, and whether the light let
their face be read. Tie each observation to its effect on the presentation. For
example: "your hands stayed below the desk in every frame, so your gestures were
hard to see".

The evidence comes from six still frames, not from continuous video, so do not
write "throughout", "for most of the talk" or "consistently".

Never write about the learner's appearance, face, body, build, hair, clothing,
jewellery, religious dress, age, gender, ethnicity, race, accent, health or
disability. Never write about the room, the background, or anything in it. Never
describe anyone else who is visible. Never state or guess how the learner was
feeling, or what kind of person they are. Never quote the visual evidence text
directly. Never give a number or a score.

Write it in {LANGUAGE}.

If the visual evidence does not support two sentences of this kind, write one
and stop. If it supports none, return an empty string.
PROMPT;

        return str_replace('{LANGUAGE}', $languagename, $prompt);
    }

    /**
     * Prompt 3: the judge, design section 3.5.
     *
     * @return string
     */
    public static function judge(): string {
        return <<<'PROMPT'
You are checking short pieces of feedback before they are shown to the learner
they are about. Each one should say what a speaker did during a recorded
presentation. None of them may describe the speaker.

Reject an item if any part of it:
- names or implies appearance, face, body, build, hair, clothing, jewellery,
  religious dress, age, gender, ethnicity, race, accent, health or disability;
- names or implies a mobility aid, assistive device or medical equipment;
- describes the room, the background, or anything in it;
- describes any person other than the learner;
- states or guesses the learner's feelings, confidence, personality or
  character;
- says what kind of person the learner is, or compares them with other people;
- gives advice the learner could not act on by changing what they do in their
  next recording.

Accept an item that says what the hands, arms, posture, stance, gaze, framing or
lighting did during the recording and what effect that had, even where the
effect is a negative one. Negative is not a reason to reject. Personal is.

Answer with JSON only, one verdict per numbered item, in order:
{"verdicts":[{"n":1,"pass":true,"rule":""},{"n":2,"pass":false,"rule":"<the one bullet it broke>"}]}
PROMPT;
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
