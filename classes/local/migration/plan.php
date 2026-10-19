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
 * What a Soapbox import of one course would do, or did: the value the dry run prints.
 *
 * runner::plan_course() fills it without writing anything; runner::apply_course() fills the same shape and then
 * the outcome of each assignment. It holds ids, counts, codes and messages only: no transcript, no feedback, no
 * learner's name (assignment names are a teacher's titles and aren't kept here either).
 *
 * Shape of one entry of $assignments, keyed by the source assignment id:
 *
 * - sourceid (int), decision (CREATE, RESUME, SKIP_VERIFIED or REFUSE), code (the refusal code, '' otherwise),
 *   message (the refusal's message, '' otherwise);
 * - standin (a STANDIN_* value, '' for a skipped assignment), standincmid (the stand-in's cmid, 0 for none),
 *   standinmessage, section (the section number the activity goes into, or is in);
 * - counts: topics, recordings, bystatus (mapped status to count, sorted by status), scores, learners;
 * - outcome ('' when nothing was attempted, OUTCOME_DONE or OUTCOME_FAILED), error (the failure's message),
 *   targetid and targetcmid (the activity, 0 when none), state (the map state after the run, '' when none).
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plan {
    /** @var string A new activity is created. */
    public const CREATE = 'create';

    /** @var string An activity created by an interrupted run is completed. */
    public const RESUME = 'resume';

    /** @var string Already imported; nothing is read or written. */
    public const SKIP_VERIFIED = 'skip_verified';

    /** @var string Not imported, for the reason in code and message. */
    public const REFUSE = 'refuse';

    /** @var string The stand-in points at the new activity and is hidden from the course page (stealth). */
    public const STANDIN_REWRITE = 'rewrite';

    /** @var string The stand-in points at the new activity but stays visible: stealth isn't available (D15). */
    public const STANDIN_VISIBLE = 'leave_visible_no_stealth';

    /** @var string No stand-in: the activity goes into the course's last section. */
    public const STANDIN_NONE = 'none_fallback_last_section';

    /** @var string More than one stand-in points at the assignment, so it's refused. */
    public const STANDIN_DUPLICATE = 'refuse_duplicate_standins';

    /** @var string The assignment was imported completely by this run. */
    public const OUTCOME_DONE = 'done';

    /** @var string The assignment failed part way; what committed stays, and a re-run resumes it. */
    public const OUTCOME_FAILED = 'failed';

    /** @var int The course. */
    public int $courseid;

    /** @var bool True when apply_course() built this plan, false for a dry run. */
    public bool $applied = false;

    /** @var string The run id written on every map row this apply wrote, '' for a dry run. */
    public string $runid = '';

    /** @var array[] Every precondition checked: ['code' => string, 'met' => bool, 'message' => string]. */
    public array $preconditions = [];

    /** @var array[] Reasons the whole course is refused: ['code' => string, 'message' => string]. */
    public array $refusals = [];

    /** @var array[] Things the operator should know that stop nothing: ['code' => string, 'message' => string]. */
    public array $notes = [];

    /** @var array[] Source assignment id to its entry (see the class comment). */
    public array $assignments = [];

    /** @var int Speech scores of the course no recording owns, freeform practice included. */
    public int $orphans = 0;

    /** @var int Distinct learners with a recording in the course, across the assignments read. */
    public int $learners = 0;

    /** @var string The PresenterAI speaking level the course's activities get. */
    public string $speakinglevel = '';

    /**
     * An empty plan for one course.
     *
     * @param int $courseid The course.
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Record a precondition.
     *
     * @param string $code Its code.
     * @param bool $met Whether it holds.
     * @param string $message What to tell the operator when it doesn't, '' when it does.
     */
    public function precondition(string $code, bool $met, string $message = ''): void {
        $this->preconditions[] = ['code' => $code, 'met' => $met, 'message' => $message];
    }

    /**
     * The preconditions that don't hold.
     *
     * @return array[] ['code' => string, 'message' => string], in the order they were checked.
     */
    public function unmet(): array {
        $unmet = [];
        foreach ($this->preconditions as $check) {
            if (!$check['met']) {
                $unmet[] = ['code' => $check['code'], 'message' => $check['message']];
            }
        }

        return $unmet;
    }

    /**
     * Refuse the whole course.
     *
     * @param string $code The refusal code.
     * @param string $message The message.
     */
    public function refuse(string $code, string $message): void {
        $this->refusals[] = ['code' => $code, 'message' => $message];
    }

    /**
     * Add a note.
     *
     * @param string $code The note's code.
     * @param string $message The message.
     */
    public function note(string $code, string $message): void {
        $this->notes[] = ['code' => $code, 'message' => $message];
    }

    /**
     * Whether any assignment of the course may be imported: every precondition holds and the course isn't refused.
     *
     * @return bool
     */
    public function can_proceed(): bool {
        return $this->unmet() === [] && $this->refusals === [];
    }

    /**
     * The course's totals over the assignments read (a skipped one isn't read, so counts nothing).
     *
     * @return array With assignments (every source assignment), topics, recordings, bystatus, scores, learners,
     *               orphans, and decisions (decision to how many).
     */
    public function counts(): array {
        $totals = ['assignments' => count($this->assignments), 'topics' => 0, 'recordings' => 0, 'bystatus' => [],
            'scores' => 0, 'learners' => $this->learners, 'orphans' => $this->orphans, 'decisions' => []];
        foreach ($this->assignments as $entry) {
            $totals['topics'] += $entry['counts']['topics'];
            $totals['recordings'] += $entry['counts']['recordings'];
            $totals['scores'] += $entry['counts']['scores'];
            foreach ($entry['counts']['bystatus'] as $status => $count) {
                $totals['bystatus'][$status] = ($totals['bystatus'][$status] ?? 0) + $count;
            }
            $totals['decisions'][$entry['decision']] = ($totals['decisions'][$entry['decision']] ?? 0) + 1;
        }
        ksort($totals['bystatus']);
        ksort($totals['decisions']);

        return $totals;
    }

    /**
     * A new entry for one source assignment, nothing decided yet.
     *
     * @param int $sourceid The source assignment id.
     * @return array
     */
    public static function blank_entry(int $sourceid): array {
        return [
            'sourceid' => $sourceid, 'decision' => self::CREATE, 'code' => '', 'message' => '',
            'standin' => '', 'standincmid' => 0, 'standinmessage' => '', 'section' => 0,
            'counts' => ['topics' => 0, 'recordings' => 0, 'bystatus' => [], 'scores' => 0, 'learners' => 0],
            'outcome' => '', 'error' => '', 'targetid' => 0, 'targetcmid' => 0, 'state' => '',
        ];
    }
}
