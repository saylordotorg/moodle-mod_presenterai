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

namespace mod_presenterai\output;

use mod_presenterai\local\access;
use mod_presenterai\local\ai\route_resolver;
use mod_presenterai\local\score_manager;

/**
 * The grading screen for one attempt: the recording, its transcript, the staff
 * only visual note and the scoring form.
 *
 * The player isn't rendered here. The template leaves an empty region and
 * mod_presenterai/grading opens the same player the learner's page uses, so
 * there's one implementation of playback, media gone sentences and URL refresh.
 *
 * The visual evidence note is shown only to holders of
 * mod/presenterai:viewvisualevidence (design 7.3). It's raw, unreviewed model
 * prose about a named learner's body, which is why it's escaped and labeled
 * staff only rather than formatted.
 *
 * "Rescore with AI" is offered only when AI is ready, the viewer may grade
 * the attempt, no teacher has scored it (a rescore never touches a teacher's
 * row) and there's something to score from: a stored transcript or the media
 * to transcribe. With the media gone and no transcript a sentence says so
 * instead of a button that would fail (design 8.5). grade.php checks the same
 * rule again before it queues anything.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_page implements \renderable, \templatable {
    /** @var string The id of the template's root element, which grading.js is given. */
    public const ROOT_ID = 'mod-presenterai-grade';

    /** @var string Rescore is offered. */
    public const RESCORE_AVAILABLE = 'available';

    /** @var string Rescore would be offered, but there's no transcript and no media to make one. */
    public const RESCORE_NOMEDIA = 'nomedia';

    /** @var string Rescore isn't offered and nothing is said about it. */
    public const RESCORE_HIDDEN = '';

    /** @var \stdClass The presenterai row. */
    private \stdClass $instance;

    /** @var \stdClass The recording being graded. */
    private \stdClass $rec;

    /** @var \stdClass The course row. */
    private \stdClass $course;

    /** @var \stdClass|\cm_info The course module. */
    private $cm;

    /** @var \context_module The activity's context. */
    private \context_module $context;

    /** @var int The grader viewing the page. */
    private int $viewerid;

    /** @var string The rendered scoring form. */
    private string $formhtml;

    /**
     * Build the page.
     *
     * @param \stdClass $instance The presenterai row.
     * @param \stdClass $rec The recording, already checked by access::require_gradable_recording().
     * @param \stdClass $course The course row.
     * @param \stdClass|\cm_info $cm The course module.
     * @param \context_module $ctx The activity's context.
     * @param int $viewerid The grader viewing the page.
     * @param string $formhtml The rendered grade_form.
     */
    public function __construct(
        \stdClass $instance,
        \stdClass $rec,
        \stdClass $course,
        $cm,
        \context_module $ctx,
        int $viewerid,
        string $formhtml
    ) {
        $this->instance = $instance;
        $this->rec = $rec;
        $this->course = $course;
        $this->cm = $cm;
        $this->context = $ctx;
        $this->viewerid = $viewerid;
        $this->formhtml = $formhtml;
    }

    /**
     * Context for templates/grade.mustache.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $DB;

        $rec = $this->rec;
        $learner = $DB->get_record('user', ['id' => (int) $rec->userid]);
        $learnername = $learner ? fullname($learner) : '';
        $recorded = userdate((int) $rec->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $transcript = trim((string) ($rec->transcript ?? ''));
        $showvisual = access::may_view_visual_evidence($this->context, $this->viewerid);
        $visual = $showvisual ? self::visual_note($rec->visualevidence ?? null) : '';
        $rescore = self::rescore_state($rec, $this->context, $this->viewerid);

        return [
            'rootid' => self::ROOT_ID,
            'cmid' => (int) $this->cm->id,
            'recordingid' => (int) $rec->id,
            'learnername' => $learnername,
            'attemptlabel' => get_string('grade_attemptlabel', 'mod_presenterai', (int) $rec->attemptnumber),
            'recorded' => $recorded,
            'length' => attempt_row::duration((int) ($rec->durationseconds ?? 0)),
            'status' => attempt_row::status_label($rec),
            'mediaavailable' => attempt_row::has_media($rec),
            'watcharia' => get_string('grade_watch_aria', 'mod_presenterai', (object) [
                'name' => $learnername,
                'date' => $recorded,
            ]),
            'hastranscript' => $transcript !== '',
            'transcript' => $transcript !== '' ? nl2br(s($transcript)) : '',
            'transcriptempty' => get_string('grade_notranscript', 'mod_presenterai'),
            'showvisual' => $showvisual,
            'hasvisual' => $visual !== '',
            'visualevidence' => $visual,
            'visualempty' => get_string('grade_novisual', 'mod_presenterai'),
            'canrescore' => $rescore === self::RESCORE_AVAILABLE,
            'rescorenomedia' => $rescore === self::RESCORE_NOMEDIA,
            'rescoreurl' => (new \moodle_url('/mod/presenterai/grade.php'))->out(false),
            'sesskey' => sesskey(),
            'form' => $this->formhtml,
            'reporturl' => (new \moodle_url('/mod/presenterai/report.php', ['id' => (int) $this->cm->id]))->out(false),
            // The grading module replaces this with the live total as the grader scores.
            'total' => ['label' => get_string('grade_total_none', 'mod_presenterai')],
        ];
    }

    /**
     * Whether, and how, the grading screen offers an AI rescore of this attempt.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The grader.
     * @return string RESCORE_AVAILABLE, RESCORE_NOMEDIA or RESCORE_HIDDEN.
     */
    public static function rescore_state(\stdClass $rec, \context_module $ctx, int $userid): string {
        if (!route_resolver::ai_ready() || !access::may_grade($rec, $ctx, $userid)) {
            return self::RESCORE_HIDDEN;
        }
        if (score_manager::has_teacher_score((int) $rec->id)) {
            return self::RESCORE_HIDDEN;
        }
        if (trim((string) ($rec->transcript ?? '')) === '' && empty($rec->storagekey)) {
            return self::RESCORE_NOMEDIA;
        }
        return self::RESCORE_AVAILABLE;
    }

    /**
     * The visual evidence note as escaped text, ready for the template.
     *
     * The column holds the vision pass's JSON (D18): a note, a confidence and
     * a count of unusable frames, the last two shown on a line after the note.
     * A JSON object with only a note, or plain text from an older writer, is
     * shown as it stands, escaped, rather than hidden, so a grader sees what
     * is stored.
     *
     * @param string|null $raw The presenterai_recording.visualevidence value.
     * @return string Escaped text with line breaks as HTML, or '' when there's no note.
     */
    public static function visual_note(?string $raw): string {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('note', $decoded) || !is_scalar($decoded['note'])) {
            return nl2br(s($raw));
        }

        $note = trim((string) $decoded['note']);
        if ($note === '') {
            return '';
        }
        $confidence = is_scalar($decoded['confidence'] ?? null) ? (string) $decoded['confidence'] : '';
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            return nl2br(s($note));
        }
        $detail = get_string('scoring_evidencedetail', 'mod_presenterai', (object) [
            'confidence' => get_string('scoring_confidence_' . $confidence, 'mod_presenterai'),
            'unusable' => max(0, (int) ($decoded['unusable_frames'] ?? 0)),
        ]);
        return nl2br(s($note . "\n\n" . $detail));
    }
}
