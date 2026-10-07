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
 * When nothing is stored the rubric is the in-code default, a port of SOLA's
 * DEFAULT_SPEECH_CRITERIA. It's never seeded into the database, so a backup
 * never carries a rubric row nobody created. Score rows store each criterion's
 * name and maximum in their JSON, which keeps an old score self-describing if
 * this default changes later.
 *
 * Phase 2 has no rubric editor; this class only reads.
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
     * The rubric a score for this activity is entered against.
     *
     * In order: the activity's own rubricid when that row exists and is active;
     * otherwise the nearest active rubric over the activity's context and its
     * parents, preferring 'video' to 'speech' at each level for a video
     * activity and taking 'speech' only for an audio one; otherwise the in-code
     * default with rubricid 0. A stored rubric whose criteria are all malformed
     * is skipped, because a rubric with nothing to score can't be used.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @return array ['rubricid' => int, 'title' => string, 'criteria' => array[]]
     */
    public static function resolve(\stdClass $instance, \context_module $ctx): array {
        global $DB;

        if (!empty($instance->rubricid)) {
            $row = $DB->get_record('presenterai_rubric', ['id' => (int) $instance->rubricid, 'active' => 1]);
            if ($row) {
                $resolved = self::from_row($row);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $types = (($instance->mode ?? 'video') === 'audio')
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
                        $resolved = self::from_row($row);
                        if ($resolved !== null) {
                            return $resolved;
                        }
                    }
                }
            }
        }

        return [
            'rubricid' => 0,
            'title' => get_string('defaultrubric', 'mod_presenterai'),
            'criteria' => self::normalise_criteria(self::DEFAULT_CRITERIA),
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
     * Turn a stored rubric row into the resolved shape.
     *
     * @param \stdClass $row A presenterai_rubric row.
     * @return array|null The resolved rubric, or null when no criterion survives cleaning.
     */
    private static function from_row(\stdClass $row): ?array {
        $criteria = self::normalise_criteria((string) $row->criteria);
        if (empty($criteria)) {
            return null;
        }
        return [
            'rubricid' => (int) $row->id,
            'title' => (string) $row->title,
            'criteria' => $criteria,
        ];
    }
}
