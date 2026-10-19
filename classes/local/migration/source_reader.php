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

/**
 * Everything the importer reads from SOLA, behind one interface.
 *
 * The runner, the verifier and the command read SOLA through this and nothing
 * else, so a test double that returns arrays covers all of them on a site that
 * has no SOLA tables. The one implementation, local_aica_reader, runs SELECTs
 * against SOLA's tables and calls SOLA's classes only behind class_exists().
 * Nothing here writes: an implementation must never insert, update or delete a
 * row of a table whose name starts with local_ai_course_assistant_ (D13).
 *
 * Rows are plain arrays keyed by SOLA's own column names, and no method returns
 * a stdClass, so what the mapper receives is exactly what a test double can
 * build by hand.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface source_reader {
    /**
     * The id of the site being read, as the map records it.
     *
     * @return string Forty hexadecimal characters, the value of ids::site_id().
     */
    public function site_id(): string;

    /**
     * The courses that hold any Soapbox assignment.
     *
     * @return int[] Course ids, ascending.
     */
    public function course_ids_with_data(): array;

    /**
     * The Soapbox assignments of one course.
     *
     * max_attempts must be the EFFECTIVE cap, not the stored column. In SOLA a stored 0 means "as many as the
     * admin maximum allows" (soapbox_config::effective_recording_cap(): 0 gives the admin cap, otherwise the
     * smaller of the value and the admin cap), while in this plugin 0 means unlimited. mapper::assignment_to_instance() copies the
     * number as given and never reinterprets 0, so a reader that passed the raw column would turn a capped
     * assignment into an unlimited one.
     *
     * @param int $courseid The course.
     * @return array[] Rows with id, courseid, name, intro, introformat, ptype, mode, min_seconds, max_seconds,
     *                 max_attempts (the effective cap), stored_attempts, rubricid, speaking_level, slides_enabled,
     *                 slide_vision, visible, timecreated and timemodified, in id order.
     */
    public function assignments(int $courseid): array;

    /**
     * The topics of one assignment.
     *
     * @param int $assignid The source assignment id.
     * @return array[] Rows with id, assignid, title, instructions, instructionsformat, sortorder, timecreated
     *                 and timemodified, in sort order then id order.
     */
    public function topics(int $assignid): array;

    /**
     * Every recording of one assignment, whatever its status, including those whose media has gone.
     *
     * @param int $assignid The source assignment id.
     * @return array[] Rows with id, assignid, userid, topicid, mode, storage_key, deck_key, frames_key,
     *                 duration_seconds, size_bytes, status, transcript, slide_timeline, scoreid, expires_at
     *                 and timecreated, in id order. A row whose score row no longer exists is returned
     *                 with scoreid null, so that the mapper and the runner agree on "has a score".
     */
    public function recordings(int $assignid): array;

    /**
     * One practice score, reached only through a recording's scoreid.
     *
     * @param int $scoreid The practice_scores id.
     * @return array|null The row with id, rubricid, userid, courseid, scores (the JSON text as stored),
     *                    overall_score, ai_feedback, session_meta (the JSON text as stored, or null) and
     *                    timecreated; null when there's no such row.
     */
    public function score(int $scoreid): ?array;

    /**
     * How many speech scores of the course belong to no recording.
     *
     * Counted for the report and left alone: a crash between SOLA writing the score and writing its id back
     * leaves one.
     *
     * @param int $courseid The course.
     * @return int
     */
    public function orphan_score_count(int $courseid): int;

    /**
     * The scores that more than one recording of the course points at.
     *
     * Migrating such a pair would make two score rows from one source row, so the course is refused.
     *
     * @param int $courseid The course.
     * @return int[] Score ids.
     */
    public function shared_score_ids(int $courseid): array;

    /**
     * The speaking level SOLA scored the course's attempts with.
     *
     * The per course setting soapbox_level_course_<id>; the assignment's own speaking_level column is dead.
     *
     * @param int $courseid The course.
     * @return string A level key, or '' or 'general' when the course never set one.
     */
    public function speaking_level_for_course(int $courseid): string;

    /**
     * The criteria of a SOLA rubric, for rebuilding the maximum of an old score.
     *
     * @param int $rubricid The rubric id a score row carries.
     * @return array|null Criterion name to maximum score, the names normalised by mapper::normalise_name();
     *                    null when the rubric no longer exists.
     */
    public function rubric_criteria(int $rubricid): ?array;

    /**
     * The criteria of one speaking level's preset, in the same shape as rubric_criteria().
     *
     * @param string $level A level key, '' meaning the general preset.
     * @return array Criterion name to maximum score, normalised.
     */
    public function preset_criteria(string $level): array;

    /**
     * SOLA's media storage settings, which the destination must match exactly.
     *
     * @return array With bucket, region and prefix, from soapbox_storage_*. Never a key.
     */
    public function storage_settings(): array;

    /**
     * Whether SOLA carries the interlock that stops its cleanup from deleting media this plugin owns.
     *
     * @return bool
     */
    public function interlock_present(): bool;

    /**
     * How many score_recording adhoc tasks are queued or running.
     *
     * @return int
     */
    public function queued_scoring_task_count(): int;

    /**
     * The course page stand-ins that point at a Soapbox assignment, all of them.
     *
     * Every mod_url activity that matches, so that two for one assignment can be refused rather than collapsed.
     *
     * @param int $courseid The course.
     * @return array[] Rows of ['cmid' => int, 'assignid' => int, 'externalurl' => string, 'visible' => int,
     *                 'visibleoncoursepage' => int].
     */
    public function standins(int $courseid): array;
}
