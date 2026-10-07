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
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_page implements \renderable, \templatable {
    /** @var string The id of the template's root element, which grading.js is given. */
    public const ROOT_ID = 'mod-presenterai-grade';

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
            'form' => $this->formhtml,
            'reporturl' => (new \moodle_url('/mod/presenterai/report.php', ['id' => (int) $this->cm->id]))->out(false),
            // The grading module replaces this with the live total as the grader scores.
            'total' => ['label' => get_string('grade_total_none', 'mod_presenterai')],
        ];
    }

    /**
     * The visual evidence note as escaped text, ready for the template.
     *
     * The column holds either the note itself or a JSON object carrying it
     * under 'note', depending on what wrote it. Anything else is shown as it
     * stands, escaped, rather than hidden, so a grader sees what is stored.
     *
     * @param string|null $raw The presenterai_recording.visualevidence value.
     * @return string Escaped text, or '' when there's no note.
     */
    public static function visual_note(?string $raw): string {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('note', $decoded) && is_scalar($decoded['note'])) {
            $note = trim((string) $decoded['note']);
            return $note === '' ? '' : nl2br(s($note));
        }

        return nl2br(s($raw));
    }
}
