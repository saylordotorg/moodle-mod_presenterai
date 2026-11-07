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
 * Which rubric a score is entered against.
 *
 * Rubrics are scoped by context rather than by course id, which removes the
 * shadowing bug in SOLA's resolve_speech_criteria(), where courseid 0 meant
 * "global" and a course row silently won over a more specific one
 * (IMPLEMENTATION-PLAN 3.4). Resolution walks outward from the activity, so
 * the nearest rubric wins and an explicit choice on the activity beats them all.
 *
 * When nothing is stored the rubric is the in-code preset for the activity's
 * speaking level, a port of SOLA's speech_presets(), plus the two visual
 * criteria when the activity is video with body language feedback on. It's
 * never seeded into the database, so a backup never carries a rubric row
 * nobody created. Score rows store each criterion's name and maximum in their
 * JSON, which keeps an old score self-describing if a preset changes later.
 *
 * Every resolved criterion carries two flags. 'visual' says it judges body
 * language, which only the frames can show. 'counts' says whether it adds to
 * rawsum and rawmax: D23 makes the visual criteria feedback only unless the
 * activity sets visualscored, so counts is derived here from the instance and
 * never taken from a request or a model's output.
 *
 * Since 0.6.0 a criterion may also carry 'levels': exactly five written
 * levels, scores 5 down to 1, each with a label and a description, stored in
 * the same criteria JSON (no schema change). A criterion with levels is scored
 * out of 5, and 0 still means not attempted. A criterion without levels is
 * read exactly as before, and nothing here invents levels for it.
 *
 * Which in-code preset an activity falls back to is its preset column when
 * that names one (activities made with 0.6.0 or later), else its speaking
 * level, which is how every activity made before 0.6.0, every restored older
 * backup and every imported activity keeps the rubric it had.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rubric_manager {
    /** @var string A rubric for spoken delivery, used by audio and video activities. */
    public const TYPE_SPEECH = 'speech';

    /** @var string A rubric for video activities, which may carry visual criteria. */
    public const TYPE_VIDEO = 'video';

    /** @var int The maximum given to a criterion that doesn't state a usable one. */
    public const DEFAULT_MAX_SCORE = 5;

    /** @var int The largest maximum the rubric editor offers for one criterion. */
    public const MAX_CRITERION_SCORE = 10;

    /** @var string General spoken presentation, the default speaking level. */
    public const LEVEL_GENERAL = 'general';

    /** @var string English as a second language, beginner. */
    public const LEVEL_ESL_BEGINNER = 'esl_beginner';

    /** @var string English as a second language, intermediate. */
    public const LEVEL_ESL_INTERMEDIATE = 'esl_intermediate';

    /** @var string English as a second language, advanced. */
    public const LEVEL_ESL_ADVANCED = 'esl_advanced';

    /** @var string[] Every speaking level, in the order the form offers them. */
    public const LEVELS = [
        self::LEVEL_GENERAL,
        self::LEVEL_ESL_BEGINNER,
        self::LEVEL_ESL_INTERMEDIATE,
        self::LEVEL_ESL_ADVANCED,
    ];

    /** @var string The short, encouraging rubric for an introduction or practice talk. */
    public const PRESET_PRACTICE = 'practice';

    /** @var string The informative speech rubric. */
    public const PRESET_INFORMATIVE = 'informative';

    /** @var string The persuasive speech rubric. */
    public const PRESET_PERSUASIVE = 'persuasive';

    /** @var string The speech to entertain rubric, derived from the informative one. */
    public const PRESET_ENTERTAIN = 'entertain';

    /** @var string[] The presets a new activity or a new rubric can start from, in the order the forms offer them. */
    public const PRESETS = [
        self::PRESET_PRACTICE,
        self::PRESET_INFORMATIVE,
        self::PRESET_PERSUASIVE,
        self::PRESET_ENTERTAIN,
        self::LEVEL_ESL_BEGINNER,
        self::LEVEL_ESL_INTERMEDIATE,
        self::LEVEL_ESL_ADVANCED,
    ];

    /** @var int[] The scores of a criterion's written levels, highest first. */
    public const LEVEL_SCORES = [5, 4, 3, 2, 1];

    /** @var int The maximum of a criterion that has written levels. */
    public const LEVELS_MAX_SCORE = 5;

    /**
     * The rubric used when nothing is stored, ported from SOLA's
     * DEFAULT_SPEECH_CRITERIA (classes/rubric_manager.php:57-63).
     *
     * The names and descriptions are read in English whatever the site language
     * (prompt_text): they're copied verbatim into score JSON, and a translated
     * name there would make one score read differently depending on who looked.
     *
     * Since 0.6.0 this is the fallback of activities made before then (the
     * "general" speaking level). It has no written levels, so those activities
     * keep exactly the rubric and the prompt they had.
     *
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'].
     */
    public static function default_criteria(): array {
        return [
            self::speech(prompt_text::get('rubric:default_delivery_name'), prompt_text::get('rubric:default_delivery_desc')),
            self::speech(prompt_text::get('rubric:default_structure_name'), prompt_text::get('rubric:default_structure_desc')),
            self::speech(prompt_text::get('rubric:default_content_name'), prompt_text::get('rubric:default_content_desc')),
            self::speech(prompt_text::get('rubric:default_language_name'), prompt_text::get('rubric:default_language_desc')),
            self::speech(prompt_text::get('rubric:default_time_name'), prompt_text::get('rubric:default_time_desc')),
        ];
    }

    /**
     * The two visual criteria, ported from SOLA's VISUAL_CRITERIA
     * (classes/rubric_manager.php:80-100).
     *
     * "hair or clothing" is struck from the first description (design 12.2
     * F5): a habit involving a learner's own hair or clothing is too close to
     * appearance to be worth a mark, and would fire disproportionately on
     * textured hair, headwear and religious dress. "Playing with an object"
     * stays, because an object isn't a person.
     *
     * Since 0.6.0 both carry five written levels. They're still feedback only
     * unless the activity scores body language (D23).
     *
     * @return array[] A list of ['name', 'description', 'max_score', 'visual', 'levels'].
     */
    public static function visual_criteria(): array {
        return [
            [
                'name' => prompt_text::get('rubric:visual_body_name'),
                'description' => prompt_text::get('rubric:visual_body_desc'),
                'max_score' => self::DEFAULT_MAX_SCORE,
                'visual' => true,
                'levels' => self::preset_levels('visual_body'),
            ],
            [
                'name' => prompt_text::get('rubric:visual_eye_name'),
                'description' => prompt_text::get('rubric:visual_eye_desc'),
                'max_score' => self::DEFAULT_MAX_SCORE,
                'visual' => true,
                'levels' => self::preset_levels('visual_eye'),
            ],
        ];
    }

    /**
     * The in-code presets, keyed by preset: the speaking levels ported from
     * SOLA's speech_presets() (classes/rubric_manager.php:638-688) and the
     * presentation type presets added in 0.6.0.
     *
     * The hint is English prompt data that steers the scoring model's register,
     * not something a person reads, so it's read in English (prompt_text). The
     * presentation type presets use the general hint, because the presentation
     * type has its own hint (scoring_prompt::ptype_hint()).
     *
     * Every preset criterion except the general preset's has five written
     * levels (rubric:<key>_l5 to _l1) with the default labels. The informative
     * and persuasive presets group the questions of standard informative and
     * persuasive speech evaluation forms into criteria. Their Delivery criterion
     * judges what a transcript can show (fluency, pace, fillers, notes read
     * aloud); gestures and eye contact belong to the two visual criteria, which
     * stay feedback only (D23), so a spoken criterion never asks the model to
     * guess at a body from words.
     *
     * @return array preset => ['hint' => string, 'criteria' => array[]]
     */
    public static function speech_presets(): array {
        $criteria = static function (array $keys): array {
            $list = [];
            foreach ($keys as $key) {
                $list[] = self::speech(
                    prompt_text::get("rubric:{$key}_name"),
                    prompt_text::get("rubric:{$key}_desc"),
                    self::preset_levels($key)
                );
            }
            return $list;
        };
        $general = prompt_text::get('rubric:hint_general');

        return [
            self::LEVEL_GENERAL => [
                'hint' => $general,
                'criteria' => self::default_criteria(),
            ],
            self::LEVEL_ESL_BEGINNER => [
                'hint' => prompt_text::get('rubric:hint_beginner'),
                'criteria' => $criteria(['beginner_pron', 'beginner_fluency', 'beginner_grammar', 'beginner_vocab',
                    'beginner_task']),
            ],
            self::LEVEL_ESL_INTERMEDIATE => [
                'hint' => prompt_text::get('rubric:hint_intermediate'),
                'criteria' => $criteria(['intermediate_pron', 'intermediate_fluency', 'intermediate_grammar',
                    'intermediate_vocab', 'intermediate_org']),
            ],
            self::LEVEL_ESL_ADVANCED => [
                'hint' => prompt_text::get('rubric:hint_advanced'),
                'criteria' => $criteria(['advanced_fluency', 'advanced_pron', 'advanced_grammar', 'advanced_vocab',
                    'advanced_coherence']),
            ],
            self::PRESET_PRACTICE => [
                'hint' => $general,
                'criteria' => $criteria(['practice_intro', 'practice_org', 'practice_delivery']),
            ],
            self::PRESET_INFORMATIVE => [
                'hint' => $general,
                'criteria' => $criteria(['speech_intro', 'speech_org', 'speech_support', 'speech_language',
                    'speech_delivery', 'speech_conclusion']),
            ],
            self::PRESET_PERSUASIVE => [
                'hint' => $general,
                'criteria' => $criteria(['persuasive_intro', 'persuasive_org', 'persuasive_support',
                    'persuasive_language', 'speech_delivery', 'persuasive_conclusion', 'persuasive_appeals']),
            ],
            self::PRESET_ENTERTAIN => [
                'hint' => $general,
                'criteria' => $criteria(['speech_intro', 'speech_org', 'entertain_humor', 'speech_language',
                    'speech_delivery', 'speech_conclusion']),
            ],
        ];
    }

    /**
     * The default labels of the five levels, which each rubric can change.
     *
     * English prompt data like the criteria they belong to (prompt_text):
     * 5 Excellent, 4 Proficient, 3 Developing, 2 Beginning, 1 Not yet.
     *
     * @return string[] score => label, 5 first.
     */
    public static function default_level_labels(): array {
        $labels = [];
        foreach (self::LEVEL_SCORES as $score) {
            $labels[$score] = prompt_text::get('rubric:levellabel_' . $score);
        }
        return $labels;
    }

    /**
     * A preset criterion's five levels, from rubric:<key>_l5 to rubric:<key>_l1.
     *
     * @param string $key The criterion's string key, such as speech_intro.
     * @return array[] A list of ['score', 'label', 'description'], 5 first.
     */
    private static function preset_levels(string $key): array {
        $levels = [];
        foreach (self::default_level_labels() as $score => $label) {
            $levels[] = [
                'score' => $score,
                'label' => $label,
                'description' => prompt_text::get("rubric:{$key}_l{$score}"),
            ];
        }
        return $levels;
    }

    /**
     * A known preset key, or '' when the value names none.
     *
     * @param string|null $preset The stored or requested preset.
     * @return string One of PRESETS, general, or ''.
     */
    public static function clean_preset(?string $preset): string {
        $preset = trim((string) $preset);
        return in_array($preset, self::PRESETS, true) || $preset === self::LEVEL_GENERAL ? $preset : '';
    }

    /**
     * The in-code preset an activity falls back to when no stored rubric applies.
     *
     * Its preset column when that names a preset, which the form writes for
     * every activity made with 0.6.0 or later. Otherwise its speaking level,
     * general or an English as a second language level, which is what an
     * activity made before 0.6.0, an older backup and the importer leave.
     *
     * @param \stdClass $instance The presenterai row.
     * @return string A key of speech_presets().
     */
    public static function preset_for(\stdClass $instance): string {
        $preset = self::clean_preset(isset($instance->preset) ? (string) $instance->preset : '');
        return $preset !== '' ? $preset : self::clean_level((string) ($instance->speakinglevel ?? ''));
    }

    /**
     * A known speaking level, or general for anything else including empty.
     *
     * @param string|null $level The stored or requested level.
     * @return string One of LEVELS.
     */
    public static function clean_level(?string $level): string {
        $level = trim((string) $level);
        return in_array($level, self::LEVELS, true) ? $level : self::LEVEL_GENERAL;
    }

    /**
     * The coaching hint for a speaking level, for the scoring prompt.
     *
     * @param string $level One of LEVELS; anything else reads as general.
     * @return string English prompt text.
     */
    public static function preset_hint(string $level): string {
        return self::speech_presets()[self::clean_level($level)]['hint'];
    }

    /**
     * A preset's criteria, as the rubric editor starts a new rubric from them.
     *
     * @param string $preset A key of speech_presets() (one of LEVELS or PRESETS); anything else reads as general.
     * @param bool $withvisual Whether to append visual_criteria(), which the editor does for a video rubric.
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'], plus 'levels' where the preset has them.
     */
    public static function preset_criteria(string $preset, bool $withvisual): array {
        $presets = self::speech_presets();
        $key = trim($preset);
        $criteria = isset($presets[$key]) ? $presets[$key]['criteria'] : $presets[self::LEVEL_GENERAL]['criteria'];
        if ($withvisual) {
            $criteria = array_merge($criteria, self::visual_criteria());
        }
        return self::normalise_criteria($criteria);
    }

    /**
     * The canonical form of a criterion name, for matching names across sources.
     *
     * Lowercased, with runs of whitespace collapsed and the ends trimmed, as
     * SOLA's score_speech::normalise_name() does. It decides which of a model's
     * criteria reach a score (the allowlist) and which rubric entry a teacher's
     * row is matched to, so both use this one definition.
     *
     * @param string $n A criterion name.
     * @return string
     */
    public static function normalise_name(string $n): string {
        return (string) preg_replace('/\s+/u', ' ', trim(\core_text::strtolower($n)));
    }

    /**
     * Whether a resolved criterion adds to rawsum and rawmax (D23).
     *
     * A visual criterion counts only when the activity scores body language;
     * every other criterion always counts.
     *
     * @param bool $visual Whether the criterion is visual.
     * @param \stdClass $instance The presenterai row.
     * @return bool
     */
    public static function counts(bool $visual, \stdClass $instance): bool {
        return !$visual || (int) ($instance->visualscored ?? 0) === 1;
    }

    /**
     * The rubric a score for this activity is entered against.
     *
     * In order: the activity's own rubricid when that row exists and is active;
     * otherwise the nearest active rubric over the activity's context and its
     * parents, preferring 'video' to 'speech' at each level for a video
     * activity and taking 'speech' only for an audio one; otherwise the in-code
     * preset (preset_for()) with rubricid 0, plus the two
     * visual criteria when the activity records video with videovision on. A
     * stored rubric whose criteria are all malformed is skipped, because a
     * rubric with nothing to score can't be used.
     *
     * On an audio activity the visual criteria are dropped from whichever
     * rubric wins, because an audio recording has no body to judge (D17).
     * Every criterion carries 'visual' and 'counts' (see counts()).
     *
     * The context is the activity's own, or its course's when the settings
     * form previews an activity that doesn't exist yet; only that context and
     * its parents are searched.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context $ctx The activity's module context, or its course context before it exists.
     * @return array ['rubricid' => int, 'title' => string, 'criteria' => array[]]
     */
    public static function resolve(\stdClass $instance, \context $ctx): array {
        global $DB;

        $audio = (($instance->mode ?? 'video') === 'audio');

        if (!empty($instance->rubricid)) {
            $row = $DB->get_record('presenterai_rubric', ['id' => (int) $instance->rubricid, 'active' => 1]);
            if ($row) {
                $resolved = self::from_row($row, $instance, $audio);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $types = $audio
            ? [self::TYPE_SPEECH]
            : [self::TYPE_VIDEO, self::TYPE_SPEECH];

        $contextids = array_map('intval', $ctx->get_parent_context_ids(true));
        if (!empty($contextids)) {
            [$ctxsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
            [$typesql, $typeparams] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'typ');
            $rows = $DB->get_records_select(
                'presenterai_rubric',
                "active = 1 AND contextid {$ctxsql} AND type {$typesql}",
                $ctxparams + $typeparams,
                'id DESC'
            );

            // Nearest context first, then the preferred type, then the newest row.
            $bycontext = [];
            foreach ($rows as $row) {
                $bycontext[(int) $row->contextid][(string) $row->type][] = $row;
            }
            foreach ($contextids as $contextid) {
                foreach ($types as $type) {
                    foreach ($bycontext[$contextid][$type] ?? [] as $row) {
                        $resolved = self::from_row($row, $instance, $audio);
                        if ($resolved !== null) {
                            return $resolved;
                        }
                    }
                }
            }
        }

        $withvisual = !$audio && !empty($instance->videovision);
        $criteria = self::preset_criteria(self::preset_for($instance), $withvisual);

        return [
            'rubricid' => 0,
            'title' => get_string('defaultrubric', 'mod_presenterai'),
            'criteria' => self::annotate($criteria, $instance),
        ];
    }

    /**
     * Clean a criteria list read from storage.
     *
     * Entries that aren't arrays, or whose name is empty, are dropped. Every
     * kept entry has a trimmed name, a description string, an integer
     * max_score of at least 1 (5 when missing or unusable) and a boolean
     * visual flag. Unknown keys are dropped too.
     *
     * 'levels' is kept only when it's usable (normalise_levels()), and then
     * the maximum is 5 whatever was stored. An entry without usable levels
     * gets no 'levels' key at all, so a rubric stored before 0.6.0 reads
     * exactly as it did and no levels are invented for it.
     *
     * @param mixed $raw A JSON string, or the already decoded list.
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'], plus 'levels' where usable.
     */
    public static function normalise_criteria($raw): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $criteria = [];
        foreach ($raw as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            if (!is_array($entry) || !isset($entry['name']) || !is_scalar($entry['name'])) {
                continue;
            }
            $name = trim((string) $entry['name']);
            if ($name === '') {
                continue;
            }
            $description = isset($entry['description']) && is_scalar($entry['description'])
                ? (string) $entry['description']
                : '';
            $max = isset($entry['max_score']) && is_numeric($entry['max_score']) ? (int) $entry['max_score'] : 0;
            $clean = [
                'name' => $name,
                'description' => $description,
                'max_score' => $max >= 1 ? $max : self::DEFAULT_MAX_SCORE,
                'visual' => !empty($entry['visual']),
            ];
            $levels = self::normalise_levels($entry['levels'] ?? null);
            if ($levels !== null) {
                $clean['max_score'] = self::LEVELS_MAX_SCORE;
                $clean['levels'] = $levels;
            }
            $criteria[] = $clean;
        }

        return $criteria;
    }

    /**
     * Clean a criterion's written levels, or null when they can't be used.
     *
     * Usable means exactly one entry for each score from 5 down to 1, each with
     * a label that isn't empty once trimmed. A description may be empty. The
     * result is ordered 5 first, whatever order was stored. Anything less is
     * dropped as a whole rather than patched, because a level nobody wrote
     * would put words in the prompt that nobody chose.
     *
     * @param mixed $raw The stored 'levels' value.
     * @return array[]|null A list of ['score', 'label', 'description'], 5 first, or null.
     */
    public static function normalise_levels($raw): ?array {
        if (!is_array($raw) || count($raw) !== count(self::LEVEL_SCORES)) {
            return null;
        }
        $byscore = [];
        foreach ($raw as $level) {
            if (is_object($level)) {
                $level = (array) $level;
            }
            if (!is_array($level) || !isset($level['score']) || !is_numeric($level['score'])) {
                return null;
            }
            $score = (int) $level['score'];
            $label = isset($level['label']) && is_scalar($level['label']) ? trim((string) $level['label']) : '';
            if (!in_array($score, self::LEVEL_SCORES, true) || isset($byscore[$score]) || $label === '') {
                return null;
            }
            $byscore[$score] = [
                'score' => $score,
                'label' => $label,
                'description' => isset($level['description']) && is_scalar($level['description'])
                    ? trim((string) $level['description'])
                    : '',
            ];
        }
        $levels = [];
        foreach (self::LEVEL_SCORES as $score) {
            $levels[] = $byscore[$score];
        }
        return $levels;
    }

    /**
     * The label of the level a score reached, or '' when there's none to name.
     *
     * '' for a criterion without levels, for a score outside 1 to 5 (0 means
     * not attempted, which has no level) and for a criterion not assessed.
     *
     * @param array $criterion A resolved or cleaned criterion.
     * @param int $score The score given.
     * @param bool $assessed Whether the criterion was assessed.
     * @return string
     */
    public static function level_label(array $criterion, int $score, bool $assessed = true): string {
        if (!$assessed || empty($criterion['levels']) || !is_array($criterion['levels'])) {
            return '';
        }
        foreach ($criterion['levels'] as $level) {
            if (is_array($level) && (int) ($level['score'] ?? -1) === $score) {
                return trim((string) ($level['label'] ?? ''));
            }
        }
        return '';
    }

    /**
     * Whether one scored criterion counts toward the total.
     *
     * Only an explicit false excludes it, matching SOLA's is_assessed()
     * (classes/rubric_manager.php:320-327). A row with no flag is every score
     * written before the flag existed, and dropping those would take marks off
     * learners for something they didn't do.
     *
     * @param array $criterion One entry of a scores list.
     * @return bool
     */
    public static function is_assessed(array $criterion): bool {
        return ($criterion['assessed'] ?? null) !== false;
    }

    /**
     * Store a new rubric in a context.
     *
     * @param int $contextid The context the rubric belongs to.
     * @param string $type TYPE_SPEECH or TYPE_VIDEO.
     * @param string $title The rubric's title.
     * @param array $criteria A list of ['name', 'description', 'max_score', 'visual'].
     * @return int The new rubric id.
     */
    public static function create(int $contextid, string $type, string $title, array $criteria): int {
        global $DB;

        $clean = self::validate_for_save($type, $title, $criteria);
        $now = time();
        return (int) $DB->insert_record('presenterai_rubric', (object) [
            'contextid' => $contextid,
            'type' => $type,
            'title' => $clean['title'],
            'criteria' => json_encode($clean['criteria']),
            'active' => 1,
            'legacyrubricid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Change an existing rubric's title, criteria and active flag.
     *
     * The type is fixed at creation, because a score row already entered
     * against a video rubric would otherwise be reinterpreted.
     *
     * @param int $id The rubric id.
     * @param string $title The new title.
     * @param array $criteria A list of ['name', 'description', 'max_score', 'visual'].
     * @param bool $active Whether the rubric may be resolved.
     * @return void
     */
    public static function update(int $id, string $title, array $criteria, bool $active): void {
        global $DB;

        $row = $DB->get_record('presenterai_rubric', ['id' => $id], '*', MUST_EXIST);
        $clean = self::validate_for_save((string) $row->type, $title, $criteria);
        $DB->update_record('presenterai_rubric', (object) [
            'id' => $id,
            'title' => $clean['title'],
            'criteria' => json_encode($clean['criteria']),
            'active' => $active ? 1 : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Delete a rubric.
     *
     * Score rows keep their rubricid as history, and their JSON carries every
     * criterion's name and maximum, so they still read correctly. An activity
     * that chose this rubric explicitly falls back to automatic resolution.
     *
     * @param int $id The rubric id.
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;

        $DB->set_field('presenterai', 'rubricid', null, ['rubricid' => $id]);
        $DB->delete_records('presenterai_rubric', ['id' => $id]);
    }

    /**
     * Every rubric visible from a context: its own and those of its parents, nearest first.
     *
     * @param \context $ctx The context to look out from.
     * @return \stdClass[] presenterai_rubric rows keyed by id, each with 'criteriacount' added.
     */
    public static function list_for_context(\context $ctx): array {
        global $DB;

        $contextids = array_map('intval', $ctx->get_parent_context_ids(true));
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $rows = $DB->get_records_select('presenterai_rubric', "contextid {$insql}", $params, 'id DESC');

        $depth = array_flip($contextids);
        uasort($rows, function (\stdClass $a, \stdClass $b) use ($depth): int {
            return [$depth[(int) $a->contextid], -(int) $a->id] <=> [$depth[(int) $b->contextid], -(int) $b->id];
        });
        foreach ($rows as $row) {
            $row->criteriacount = count(self::normalise_criteria((string) $row->criteria));
        }
        return $rows;
    }

    /**
     * The active rubrics visible from a context, for the activity's rubric choice.
     *
     * @param \context $ctx The module context when editing, the course context when adding.
     * @return \stdClass[] presenterai_rubric rows keyed by id, nearest context first.
     */
    public static function list_active_for_context(\context $ctx): array {
        return array_filter(self::list_for_context($ctx), function (\stdClass $row): bool {
            return !empty($row->active);
        });
    }

    /**
     * Whether a rubric may be chosen by an activity in this context: it exists, is active and is visible from it.
     *
     * @param int $rubricid The rubric id.
     * @param \context $ctx The activity's module context, or its course context before it exists.
     * @return bool
     */
    public static function is_selectable(int $rubricid, \context $ctx): bool {
        global $DB;

        $row = $DB->get_record('presenterai_rubric', ['id' => $rubricid, 'active' => 1], 'id, contextid');
        if (!$row) {
            return false;
        }
        return in_array((int) $row->contextid, array_map('intval', $ctx->get_parent_context_ids(true)), true);
    }

    /**
     * Check a rubric about to be stored and return it cleaned.
     *
     * @param string $type TYPE_SPEECH or TYPE_VIDEO.
     * @param string $title The title.
     * @param array $criteria The criteria.
     * @return array ['title' => string, 'criteria' => array[]]
     */
    private static function validate_for_save(string $type, string $title, array $criteria): array {
        if (!in_array($type, [self::TYPE_SPEECH, self::TYPE_VIDEO], true)) {
            throw new \invalid_parameter_exception('Unknown rubric type.');
        }
        $title = trim($title);
        if ($title === '') {
            throw new \invalid_parameter_exception('A rubric needs a title.');
        }
        $clean = self::normalise_criteria($criteria);
        if (empty($clean)) {
            throw new \invalid_parameter_exception('A rubric needs at least one criterion.');
        }
        $seen = [];
        foreach ($clean as $criterion) {
            $key = self::normalise_name($criterion['name']);
            if (isset($seen[$key])) {
                throw new \invalid_parameter_exception('Two criteria share a name.');
            }
            $seen[$key] = true;
            // A speech rubric is also used by audio activities, which have no frames.
            if ($criterion['visual'] && $type !== self::TYPE_VIDEO) {
                throw new \invalid_parameter_exception('Only a video rubric may have visual criteria.');
            }
        }
        return ['title' => \core_text::substr($title, 0, 255), 'criteria' => $clean];
    }

    /**
     * One speech criterion of a preset, at the default maximum.
     *
     * @param string $name The criterion's name.
     * @param string $description What it judges.
     * @param array|null $levels Its five written levels, or null for none.
     * @return array
     */
    private static function speech(string $name, string $description, ?array $levels = null): array {
        $criterion = ['name' => $name, 'description' => $description, 'max_score' => self::DEFAULT_MAX_SCORE, 'visual' => false];
        if ($levels !== null) {
            $criterion['levels'] = $levels;
        }
        return $criterion;
    }

    /**
     * Add the counts flag to a cleaned criteria list.
     *
     * @param array[] $criteria From normalise_criteria().
     * @param \stdClass $instance The presenterai row.
     * @return array[] The same list, each entry with 'counts'.
     */
    private static function annotate(array $criteria, \stdClass $instance): array {
        foreach ($criteria as $i => $criterion) {
            $criteria[$i]['counts'] = self::counts((bool) $criterion['visual'], $instance);
        }
        return $criteria;
    }

    /**
     * Turn a stored rubric row into the resolved shape.
     *
     * @param \stdClass $row A presenterai_rubric row.
     * @param \stdClass $instance The presenterai row, for the counts flag.
     * @param bool $audio Whether the activity is audio, which drops visual criteria.
     * @return array|null The resolved rubric, or null when no criterion survives cleaning.
     */
    private static function from_row(\stdClass $row, \stdClass $instance, bool $audio): ?array {
        $criteria = self::normalise_criteria((string) $row->criteria);
        if ($audio) {
            $criteria = array_values(array_filter($criteria, function (array $c): bool {
                return !$c['visual'];
            }));
        }
        if (empty($criteria)) {
            return null;
        }
        return [
            'rubricid' => (int) $row->id,
            'title' => (string) $row->title,
            'criteria' => self::annotate($criteria, $instance),
        ];
    }
}
