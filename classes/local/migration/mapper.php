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

namespace mod_presenterai\local\migration;

use mod_presenterai\local\grader;
use mod_presenterai\local\instance_manager;
use mod_presenterai\local\recording_manager;
use mod_presenterai\local\rubric_manager;
use mod_presenterai\local\score_manager;
use mod_presenterai\local\storage\store_factory;
use mod_presenterai\local\vision\visual_pipeline;

/**
 * Turns SOLA's Soapbox rows into this plugin's, as plain arrays, with no database and no globals.
 *
 * Pure on purpose: every method is static and takes arrays, so the whole of the
 * mapping, which is where the migration can quietly go wrong, is tested without
 * SOLA's tables (IMPLEMENTATION-PLAN 7.2 and 7.4, spec section 4.3).
 *
 * Three rules the rest of the importer leans on:
 *
 * - A percentage is computed from raw sums, never copied. SOLA's overall_score is
 *   a 0 to 5 mean, so reading it as a percentage turns 4 out of 5 into 4 percent.
 * - A score row whose maximum cannot be rebuilt says so ('unreconstructed', no
 *   percentage, SOLA's own mean kept in legacymeanscore) instead of assuming a
 *   maximum of 5. A row that mixes sources is as weak as its weakest source, and
 *   one unresolvable criterion makes the whole row unreconstructed, because a
 *   percentage over some of the criteria would be a number nobody can defend.
 * - Nothing is recomputed that the source already decided: the expiry date is
 *   copied, so no learner's deletion date moves.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mapper {
    /** @var string The criteria carry their own maximum. */
    public const PROVENANCE_EXACT = 'exact';

    /** @var string A maximum looked up in the rubric the score row points at. */
    public const PROVENANCE_RUBRIC = 'rubric_backfill';

    /** @var string A maximum looked up in the speaking level's preset. */
    public const PROVENANCE_PRESET = 'preset_backfill';

    /** @var string No usable maximum: the row has no percentage. */
    public const PROVENANCE_NONE = 'unreconstructed';

    /** @var string The reason written for media SOLA deleted. SOLA does not record which of its two paths did it. */
    private const GONE_REASON = 'retention';

    /** @var string[] Soapbox statuses and the statuses they become when the attempt has no score. */
    private const STATUS_WITHOUT_SCORE = [
        'uploading' => recording_manager::STATUS_ABANDONED,
        'deleted' => recording_manager::STATUS_ABANDONED,
        'uploaded' => recording_manager::STATUS_UPLOADED,
        'scored' => recording_manager::STATUS_UPLOADED,
        'failed' => recording_manager::STATUS_FAILED,
    ];

    /**
     * The criterion key SOLA matches names by, so a lookup here and SOLA's own never disagree.
     *
     * The same expression as local_ai_course_assistant\external\score_speech::normalise_name(): lower case, the
     * ends trimmed, every run of white space folded to one space. The source reader builds its rubric and preset
     * maps with this, and score_to_row() looks names up with it.
     *
     * @param string $name A criterion name.
     * @return string
     */
    public static function normalise_name(string $name): string {
        return (string) preg_replace('/\s+/', ' ', trim(\core_text::strtolower($name)));
    }

    /**
     * The moduleinfo for create_module() that stands in for one Soapbox assignment.
     *
     * Carries no section (the runner finds it) and a placeholder draft item for the intro (the runner swaps in a
     * real draft id if create_module needs one). SOLA's rubricid and speaking_level columns are not carried: no
     * form ever wrote them, and a SOLA rubric id names a row in a table this plugin does not have. The grade is 0
     * so no grade item and no course total moves (D12), and retention follows the site setting (ruling 4).
     *
     * @param array $assign A sbx_assign row.
     * @return array
     */
    public static function assignment_to_instance(array $assign): array {
        $intro = (string) ($assign['intro'] ?? '');
        $format = (int) ($assign['introformat'] ?? FORMAT_HTML);

        return [
            'modulename' => 'presenterai',
            'course' => (int) $assign['courseid'],
            'visible' => empty($assign['visible']) ? 0 : 1,
            'name' => (string) $assign['name'],
            'intro' => $intro,
            'introformat' => $format,
            'introeditor' => ['text' => $intro, 'format' => $format, 'itemid' => 0],
            'ptype' => (string) ($assign['ptype'] ?? instance_manager::PTYPES[0]),
            'mode' => (string) ($assign['mode'] ?? 'video'),
            'minseconds' => (int) $assign['min_seconds'],
            'maxseconds' => (int) $assign['max_seconds'],
            'maxattempts' => (int) $assign['max_attempts'],
            'storedattempts' => (int) $assign['stored_attempts'],
            'slidesenabled' => empty($assign['slides_enabled']) ? 0 : 1,
            'slidevision' => empty($assign['slide_vision']) ? 0 : 1,
            'grade' => 0,
            'retentiondays' => instance_manager::RETENTION_SITE,
            'legacyassignid' => (int) $assign['id'],
        ];
    }

    /**
     * The presenterai_topic row for one Soapbox topic.
     *
     * @param array $topic A sbx_topic row.
     * @param int $instanceid The new activity.
     * @return array
     */
    public static function topic_to_row(array $topic, int $instanceid): array {
        return [
            'presenteraiid' => $instanceid,
            'title' => (string) $topic['title'],
            'instructions' => isset($topic['instructions']) ? (string) $topic['instructions'] : null,
            'instructionsformat' => (int) ($topic['instructionsformat'] ?? FORMAT_HTML),
            'sortorder' => (int) $topic['sortorder'],
            'legacytopicid' => (int) $topic['id'],
            'timecreated' => (int) $topic['timecreated'],
            'timemodified' => (int) $topic['timemodified'],
        ];
    }

    /**
     * The status a migrated attempt gets.
     *
     * A score row makes it scored, whatever SOLA called it. Without one the status says what really happened:
     * a deleted row never carried a score, so it is abandoned; one that was still uploading never finished, so
     * it is abandoned too (left as uploading it would be handed to this plugin's own sweep, which deletes the
     * keys of an upload a day old); one SOLA calls scored with no score row lost its score and is uploaded, so
     * it still counts; a failed one stays failed.
     *
     * @param array $rec A sbx_rec row.
     * @param bool $hasscore Whether a score row exists for it.
     * @return string One of recording_manager's statuses, or 'scored'.
     * @throws \InvalidArgumentException For a status SOLA does not have, which is refused rather than guessed.
     */
    public static function recording_status(array $rec, bool $hasscore): string {
        $status = (string) ($rec['status'] ?? '');
        if (!isset(self::STATUS_WITHOUT_SCORE[$status])) {
            throw new \InvalidArgumentException('Unknown Soapbox recording status: ' . $status);
        }

        return $hasscore ? score_manager::RECORDING_STATUS_SCORED : self::STATUS_WITHOUT_SCORE[$status];
    }

    /**
     * Each attempt's number: per learner and assignment, by time, among the attempts that count.
     *
     * Give it the recordings of one or more assignments; a learner is numbered separately in each, as in two
     * activities. Whether an attempt counts is decided from its mapped status, reading "has a score" from a
     * non-empty scoreid, so a caller whose score row is gone must pass the row with scoreid null (the reader does).
     * Ties in time are broken by id. An attempt that never counted gets 0; a failed one gets the number the next
     * counted attempt will have, as finalize gave it in the activity, and uses none.
     *
     * @param array $recordings sbx_rec rows with id, assignid, userid, status, scoreid and timecreated.
     * @return array Recording id to attempt number, in id order.
     */
    public static function attempt_numbers(array $recordings): array {
        $groups = [];
        foreach ($recordings as $rec) {
            $groups[(int) ($rec['assignid'] ?? 0) . '-' . (int) $rec['userid']][] = $rec;
        }

        $numbers = [];
        foreach ($groups as $group) {
            usort($group, static function (array $a, array $b): int {
                return [(int) $a['timecreated'], (int) $a['id']] <=> [(int) $b['timecreated'], (int) $b['id']];
            });
            $counted = 0;
            foreach ($group as $rec) {
                $numbers[(int) $rec['id']] = self::attempt_number($rec, $counted);
            }
        }
        ksort($numbers);

        return $numbers;
    }

    /**
     * The number for one attempt, and the running count of attempts that count.
     *
     * @param array $rec A sbx_rec row.
     * @param int $counted The attempts of this learner already counted. Increased when this one counts.
     * @return int
     */
    private static function attempt_number(array $rec, int &$counted): int {
        $status = self::recording_status($rec, !empty($rec['scoreid']));
        if (!in_array($status, recording_manager::COUNTED_EXCLUDED, true)) {
            return ++$counted;
        }

        return $status === recording_manager::STATUS_FAILED ? $counted + 1 : 0;
    }

    /**
     * The presenterai_recording row for one Soapbox recording.
     *
     * The three keys are carried as they are, so the object in the bucket is found where SOLA put it; nothing is
     * moved (D10). Where SOLA deleted the media (status deleted, no key) the keys stay null and the deletion is
     * recorded with the date SOLA was going to delete it, which is the only date there is. The expiry itself is
     * copied untouched. scoreid is left null for the runner to set once the score row exists.
     *
     * @param array $rec A sbx_rec row.
     * @param int $instanceid The new activity.
     * @param int|null $topicid The new topic id, or null when the attempt chose none.
     * @param int $attemptnumber From attempt_numbers().
     * @param bool $hasscore Whether a score row exists for it.
     * @return array
     */
    public static function recording_to_row(array $rec, int $instanceid, ?int $topicid, int $attemptnumber, bool $hasscore): array {
        $keys = self::media_keys($rec);
        $gone = (string) $rec['status'] === 'deleted' && $keys['storagekey'] === null;
        $expires = (int) ($rec['expires_at'] ?? 0);

        return [
            'presenteraiid' => $instanceid,
            'userid' => (int) $rec['userid'],
            'topicid' => $topicid,
            'attemptnumber' => $attemptnumber,
            'mode' => in_array($rec['mode'] ?? null, instance_manager::MODES, true) ? $rec['mode'] : 'video',
            'backend' => store_factory::BACKEND_S3,
            'audiokey' => null,
            'audiobytes' => 0,
            'uploadid' => null,
            'clienttoken' => null,
            'slidetimeline' => isset($rec['slide_timeline']) ? (string) $rec['slide_timeline'] : null,
            'visualevidence' => null,
            'visualevidenceat' => 0,
            'visualoptout' => 0,
            'durationseconds' => (int) ($rec['duration_seconds'] ?? 0),
            'sizebytes' => (int) ($rec['size_bytes'] ?? 0),
            'status' => self::recording_status($rec, $hasscore),
            'transcript' => isset($rec['transcript']) ? (string) $rec['transcript'] : null,
            'scoreid' => null,
            'expiresat' => $expires,
            'mediadeletedat' => $gone ? $expires : 0,
            'mediagonereason' => $gone ? self::GONE_REASON : null,
            'deletewarnedat' => 0,
            'legacyrecid' => (int) $rec['id'],
            'legacyscoreid' => (int) ($rec['scoreid'] ?? 0),
            'timecreated' => (int) $rec['timecreated'],
            'timemodified' => (int) $rec['timecreated'],
        ] + $keys;
    }

    /**
     * The recording, deck and frame keys of a row, an empty string counting as no key.
     *
     * @param array $rec A sbx_rec row.
     * @return array With storagekey, deckkey and frameskey.
     */
    private static function media_keys(array $rec): array {
        $key = static fn($value): ?string => ($value === null || (string) $value === '') ? null : (string) $value;

        return [
            'storagekey' => $key($rec['storage_key'] ?? null),
            'deckkey' => $key($rec['deck_key'] ?? null),
            'frameskey' => $key($rec['frames_key'] ?? null),
        ];
    }

    /**
     * The presenterai_score row for one SOLA score.
     *
     * $context carries what the source reader looked up: 'rubric' (criterion name to maximum, normalised, or null
     * when the rubric the score points at is gone), 'preset' (the same for the course's speaking level),
     * 'speaking_level' (which level that preset is, for the caller's report; the arithmetic does not need it) and
     * 'recordingid' and 'userid' (the new recording and its learner).
     *
     * Each criterion keeps SOLA's name, score and feedback and gains its maximum: its own max_score when it has
     * one, else the rubric's (when the score names a rubric), else the preset's (when it names none). A criterion
     * whose maximum cannot be found keeps max_score null, and makes the whole row unreconstructed. An absent
     * assessed flag counts as assessed. Every criterion counts toward the total (counts is true).
     *
     * The row carries no visual summary and no visual status, because the body language observation is never
     * imported (D11); the rest of session_meta is kept in legacymeta, which is where visual_assessed travels.
     * legacyscoreid is also written on the recording row, which is the table that has the column.
     *
     * @param array $score A practice_scores row with scores and session_meta as JSON text.
     * @param array $context See above.
     * @return array
     */
    public static function score_to_row(array $score, array $context): array {
        $meta = self::read_meta($score['session_meta'] ?? null);
        $listed = self::read_criteria((string) ($score['scores'] ?? ''));
        $criteria = self::reconstruct($listed, (int) ($score['rubricid'] ?? 0), $context);
        $reconstructed = $criteria['provenance'] !== self::PROVENANCE_NONE;
        $rawmax = $reconstructed ? $criteria['rawmax'] : 0;

        return [
            'recordingid' => (int) $context['recordingid'],
            'userid' => (int) $context['userid'],
            'rubricid' => 0,
            'origin' => score_manager::ORIGIN_AI,
            'scores' => json_encode($criteria['entries']),
            'rawsum' => $criteria['rawsum'],
            'rawmax' => $rawmax,
            'overallpct' => grader::percent($criteria['rawsum'], $rawmax),
            'scoreprovenance' => $criteria['provenance'],
            'feedback' => isset($score['ai_feedback']) ? (string) $score['ai_feedback'] : null,
            'tips' => self::tips($meta),
            'visualsummary' => null,
            'visualstatus' => visual_pipeline::STATUS_NONE,
            'released' => 1,
            'legacymeanscore' => (int) ($score['overall_score'] ?? 0),
            'legacymeta' => self::legacy_meta($meta),
            'legacyscoreid' => (int) $score['id'],
            'graderid' => 0,
            'timecreated' => (int) $score['timecreated'],
        ];
    }

    /**
     * The criteria a score row stores, as a list of arrays.
     *
     * @param string $json The scores column.
     * @return array[] Empty when the text is not a JSON list of objects.
     */
    private static function read_criteria(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * The session meta as an array, or null when there is none or it is not a JSON object.
     *
     * @param string|null $json The session_meta column.
     * @return array|null
     */
    private static function read_meta(?string $json): ?array {
        $decoded = ($json === null || $json === '') ? null : json_decode($json, true);

        return (is_array($decoded) && ($decoded === [] || !array_is_list($decoded))) ? $decoded : null;
    }

    /**
     * The tips of a session meta as the JSON list the activity stores, or null when it has none.
     *
     * @param array|null $meta The session meta.
     * @return string|null
     */
    private static function tips(?array $meta): ?string {
        if (!isset($meta['tips']) || !is_array($meta['tips'])) {
            return null;
        }
        $tips = array_values(array_filter(array_map(
            static fn($tip): string => is_scalar($tip) ? trim((string) $tip) : '',
            $meta['tips']
        ), 'strlen'));

        return json_encode($tips);
    }

    /**
     * The session meta without the body language observation, as JSON, or null when there is none.
     *
     * @param array|null $meta The session meta.
     * @return string|null
     */
    private static function legacy_meta(?array $meta): ?string {
        if ($meta === null) {
            return null;
        }
        unset($meta['visual_observation']);

        return json_encode($meta === [] ? new \stdClass() : $meta);
    }

    /**
     * Give every criterion its maximum and total the assessed ones.
     *
     * @param array[] $criteria The decoded criteria.
     * @param int $rubricid The rubric the score row points at, 0 for a preset.
     * @param array $context See score_to_row().
     * @return array With entries (the stored list), rawsum, rawmax and provenance. rawmax is only meaningful
     *               when provenance is not unreconstructed.
     */
    private static function reconstruct(array $criteria, int $rubricid, array $context): array {
        $entries = [];
        $sources = [];
        $rawsum = 0;
        $rawmax = 0;
        $complete = !empty($criteria);
        foreach ($criteria as $criterion) {
            [$max, $source] = self::maximum($criterion, $rubricid, $context);
            $entry = self::entry($criterion, $max);
            $entries[] = $entry;
            if (!$entry['assessed']) {
                continue;
            }
            $rawsum += $entry['score'];
            if ($max === null) {
                $complete = false;
                continue;
            }
            $rawmax += $max;
            $sources[] = $source;
        }

        return [
            'entries' => $entries,
            'rawsum' => $rawsum,
            'rawmax' => $rawmax,
            'provenance' => $complete ? self::weakest($sources) : self::PROVENANCE_NONE,
        ];
    }

    /**
     * One criterion as it is stored.
     *
     * @param array $criterion The criterion as SOLA stored it.
     * @param int|null $max Its maximum, or null when it could not be found.
     * @return array
     */
    private static function entry(array $criterion, ?int $max): array {
        return [
            'name' => isset($criterion['name']) && is_scalar($criterion['name']) ? (string) $criterion['name'] : '',
            'score' => isset($criterion['score']) && is_numeric($criterion['score']) ? (int) $criterion['score'] : 0,
            'max_score' => $max,
            'feedback' => isset($criterion['feedback']) && is_scalar($criterion['feedback']) ? (string) $criterion['feedback'] : '',
            'assessed' => rubric_manager::is_assessed($criterion),
            'visual' => false,
            'counts' => true,
        ];
    }

    /**
     * The maximum of one criterion and where it came from.
     *
     * Its own max_score when that is at least 1. Otherwise the rubric when the score row names one, or the preset
     * when it names none: a row scored against a custom rubric is never read against the preset, because the
     * custom rubric's maxima need not be the preset's.
     *
     * @param array $criterion The criterion as SOLA stored it.
     * @param int $rubricid The rubric the score row points at, 0 for a preset.
     * @param array $context See score_to_row().
     * @return array [int|null maximum, string|null source], the source being a PROVENANCE_* value.
     */
    private static function maximum(array $criterion, int $rubricid, array $context): array {
        $stated = isset($criterion['max_score']) && is_numeric($criterion['max_score']) ? (int) $criterion['max_score'] : 0;
        if ($stated >= 1) {
            return [$stated, self::PROVENANCE_EXACT];
        }

        $key = self::normalise_name((string) ($criterion['name'] ?? ''));
        if ($rubricid > 0) {
            return [self::lookup($context['rubric'] ?? null, $key), self::PROVENANCE_RUBRIC];
        }

        return [self::lookup($context['preset'] ?? null, $key), self::PROVENANCE_PRESET];
    }

    /**
     * One maximum out of a rubric or preset map.
     *
     * @param array|null $criteria Normalised criterion name to maximum, or null.
     * @param string $key The normalised name.
     * @return int|null Null when the map has no such criterion or its maximum is below 1.
     */
    private static function lookup(?array $criteria, string $key): ?int {
        $max = is_array($criteria) && isset($criteria[$key]) && is_numeric($criteria[$key]) ? (int) $criteria[$key] : 0;

        return $max >= 1 ? $max : null;
    }

    /**
     * The provenance of a row built from several sources: the weakest one used.
     *
     * @param string[] $sources The PROVENANCE_* value of each assessed criterion.
     * @return string
     */
    private static function weakest(array $sources): string {
        foreach ([self::PROVENANCE_PRESET, self::PROVENANCE_RUBRIC] as $weaker) {
            if (in_array($weaker, $sources, true)) {
                return $weaker;
            }
        }

        return self::PROVENANCE_EXACT;
    }
}
