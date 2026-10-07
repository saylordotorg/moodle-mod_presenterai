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

    /**
     * The rubric used when nothing is stored, ported from SOLA's
     * DEFAULT_SPEECH_CRITERIA (classes/rubric_manager.php:57-63).
     *
     * The English names and descriptions are data, not lang strings: they're
     * copied verbatim into score JSON, and a translated name there would make
     * one score read differently depending on who looked at it.
     *
     * @var array[]
     */
    public const DEFAULT_CRITERIA = [
        [
            'name' => 'Delivery & Fluency',
            'description' => 'Pace, clarity, confidence, and smoothness of speaking.',
            'max_score' => 5,
            'visual' => false,
        ],
        [
            'name' => 'Structure & Organization',
            'description' => 'Clear opening, logical flow of ideas, and a strong close.',
            'max_score' => 5,
            'visual' => false,
        ],
        [
            'name' => 'Content & Relevance',
            'description' => 'Ideas stay on topic and are well supported and developed.',
            'max_score' => 5,
            'visual' => false,
        ],
        [
            'name' => 'Language & Vocabulary',
            'description' => 'Word choice, grammar, and varied, precise language.',
            'max_score' => 5,
            'visual' => false,
        ],
        [
            'name' => 'Time Management',
            'description' => 'Fits the target length without rushing or running long.',
            'max_score' => 5,
            'visual' => false,
        ],
    ];

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
     * @var array[]
     */
    public const VISUAL_CRITERIA = [
        [
            'name' => 'Body Language & Gestures',
            'description' => 'Gestures, posture and movement that SUPPORT what you are saying: '
                . 'open hands that mark structure or add emphasis, a steady stance, and weight '
                . 'that stays settled. No repeated habits that DISTRACT from the presentation '
                . '(fidgeting, rocking or pacing, hands in pockets or folded, or playing with an object).',
            'max_score' => 5,
            'visual' => true,
        ],
        [
            'name' => 'Eye Contact & Camera Presence',
            'description' => 'Looking at the camera lens as if it were your audience, rather than '
                . 'reading from notes or watching a second screen. Steady head-and-shoulders '
                . 'framing that keeps your hands in view, a face lit well enough to read, and '
                . 'facial expression that matches what you are saying.',
            'max_score' => 5,
            'visual' => true,
        ],
    ];

    /**
     * The speaking level presets, ported from SOLA's speech_presets()
     * (classes/rubric_manager.php:638-688).
     *
     * The hint is English prompt data that steers the scoring model's register,
     * not something a person reads, so it isn't a lang string. The label is,
     * and lives in lang/en as level_<key>.
     *
     * @return array level => ['hint' => string, 'criteria' => array[]]
     */
    public static function speech_presets(): array {
        return [
            self::LEVEL_GENERAL => [
                'hint' => 'The learner is practising a general spoken presentation; focus your feedback on '
                    . 'presentation and public-speaking skills.',
                'criteria' => self::DEFAULT_CRITERIA,
            ],
            self::LEVEL_ESL_BEGINNER => [
                'hint' => 'The learner is a beginner-level English-as-a-second-language student. Prioritise '
                    . 'intelligibility and communication over native-like accuracy. Use simple, clear language in '
                    . 'your feedback, warmly praise successful communication, and give one small, concrete '
                    . 'improvement per criterion. Do not penalise a noticeable accent.',
                'criteria' => [
                    self::speech(
                        'Pronunciation & Intelligibility',
                        'Sounds, word stress, and being understood by a patient listener.'
                    ),
                    self::speech(
                        'Fluency & Pace',
                        'Speaking in connected phrases without long pauses or heavy hesitation.'
                    ),
                    self::speech('Basic Grammar', 'Simple tenses, subject-verb agreement, and word order.'),
                    self::speech(
                        'Core Vocabulary',
                        'Using common, topic-relevant words and getting meaning across despite gaps.'
                    ),
                    self::speech(
                        'Task Completion',
                        'Staying on topic and saying enough on the prompt to be understood.'
                    ),
                ],
            ],
            self::LEVEL_ESL_INTERMEDIATE => [
                'hint' => 'The learner is an intermediate-level English-as-a-second-language student. Balance '
                    . 'encouragement with targeted correction: acknowledge what works, then name a couple of '
                    . 'concrete, level-appropriate improvements (a grammar pattern, a clearer transition, a more '
                    . 'precise word). Stretch them slightly beyond their comfort without overwhelming them.',
                'criteria' => [
                    self::speech(
                        'Pronunciation & Clarity',
                        'Mostly clear sounds and stress; occasional slips that rarely block understanding.'
                    ),
                    self::speech(
                        'Fluency & Pace',
                        'Reasonably smooth with some hesitation; keeps going through most ideas.'
                    ),
                    self::speech(
                        'Grammar',
                        'Common tenses and structures handled with some errors in more complex forms.'
                    ),
                    self::speech(
                        'Vocabulary',
                        'Adequate range for the topic, with some reach for less common or precise words.'
                    ),
                    self::speech(
                        'Organization & Coherence',
                        'Clear main idea, mostly logical ordering, and basic connectors.'
                    ),
                ],
            ],
            self::LEVEL_ESL_ADVANCED => [
                'hint' => 'The learner is an advanced English-as-a-second-language student. Hold them to a high '
                    . 'standard of fluency, range, and accuracy while staying encouraging. Note subtle errors in '
                    . 'idiom, register, and complex grammar, and push for more natural, native-like phrasing.',
                'criteria' => [
                    self::speech(
                        'Fluency & Naturalness',
                        'Smooth pace, natural rhythm, and self-correction that does not disrupt flow.'
                    ),
                    self::speech(
                        'Pronunciation & Stress',
                        'Clear sounds plus sentence stress and intonation that carry meaning.'
                    ),
                    self::speech('Grammatical Range & Accuracy', 'Varied, complex structures used accurately.'),
                    self::speech(
                        'Vocabulary & Idiom',
                        'Precise, varied, idiomatic word choice and appropriate register.'
                    ),
                    self::speech(
                        'Coherence & Development',
                        'Well-organized ideas with connectors and full development.'
                    ),
                ],
            ],
        ];
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
     * @param string $level One of LEVELS; anything else reads as general.
     * @param bool $withvisual Whether to append VISUAL_CRITERIA, which the editor does for a video rubric.
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'].
     */
    public static function preset_criteria(string $level, bool $withvisual): array {
        $criteria = self::speech_presets()[self::clean_level($level)]['criteria'];
        if ($withvisual) {
            $criteria = array_merge($criteria, self::VISUAL_CRITERIA);
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
     * preset for the activity's speaking level with rubricid 0, plus the two
     * visual criteria when the activity records video with videovision on. A
     * stored rubric whose criteria are all malformed is skipped, because a
     * rubric with nothing to score can't be used.
     *
     * On an audio activity the visual criteria are dropped from whichever
     * rubric wins, because an audio recording has no body to judge (D17).
     * Every criterion carries 'visual' and 'counts' (see counts()).
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @return array ['rubricid' => int, 'title' => string, 'criteria' => array[]]
     */
    public static function resolve(\stdClass $instance, \context_module $ctx): array {
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
        $criteria = self::preset_criteria((string) ($instance->speakinglevel ?? ''), $withvisual);

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
     * @param mixed $raw A JSON string, or the already decoded list.
     * @return array[] A list of ['name', 'description', 'max_score', 'visual'].
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
            $criteria[] = [
                'name' => $name,
                'description' => $description,
                'max_score' => $max >= 1 ? $max : self::DEFAULT_MAX_SCORE,
                'visual' => !empty($entry['visual']),
            ];
        }

        return $criteria;
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
     * @return array
     */
    private static function speech(string $name, string $description): array {
        return ['name' => $name, 'description' => $description, 'max_score' => self::DEFAULT_MAX_SCORE, 'visual' => false];
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
