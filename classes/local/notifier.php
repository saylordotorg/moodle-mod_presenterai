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
 * Tells a learner that one of their attempts has a score to read.
 *
 * The message says only that a score is ready and where to find it. It
 * doesn't name who scored, because at Saylor nobody does by hand and the same
 * message will carry AI scores in phase 3, and it promises nothing about what
 * happens next (DECISIONS.md, "there is no teacher").
 *
 * On an activity that holds AI feedback for a teacher's review (D28) it's
 * sent when the attempt is released, not when the AI scores it.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notifier {
    /**
     * Send the recordingscored message to the attempt's learner.
     *
     * @param \stdClass $rec The presenterai_recording row that was scored.
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's module context.
     * @param \stdClass $score The presenterai_score row that was written.
     * @return bool Whether a message was sent.
     */
    public static function recording_scored(\stdClass $rec, \stdClass $instance, \context_module $ctx, \stdClass $score): bool {
        $learner = \core_user::get_user((int) $rec->userid);
        if (!$learner || !empty($learner->deleted) || !empty($learner->suspended)) {
            return false;
        }
        // A grade the gradebook hides isn't announced. Nothing is sent later
        // when it's released; the gradebook's own release is the learner's cue.
        if (gradebook::hidden_from($instance, (int) $learner->id)) {
            return false;
        }

        // Built in the learner's language, not the grader's.
        $oldlang = force_current_language((string) ($learner->lang ?? ''));
        try {
            // The plain-text parts get the name unescaped, so '&' doesn't arrive as '&amp;'.
            $activity = format_string((string) $instance->name, true, ['context' => $ctx, 'escape' => false]);
            $url = new \moodle_url('/mod/presenterai/view.php', ['id' => $ctx->instanceid]);
            $a = (object) [
                'activity' => $activity,
                'attempt' => (int) $rec->attemptnumber,
                'url' => $url->out(false),
            ];
            // The HTML body puts the link in an attribute, so it gets the escaped forms.
            $ahtml = clone $a;
            $ahtml->activity = format_string((string) $instance->name, true, ['context' => $ctx]);
            $ahtml->url = $url->out(true);

            $message = new \core\message\message();
            $message->component = 'mod_presenterai';
            $message->name = 'recordingscored';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $learner;
            $message->courseid = (int) $instance->course;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $activity;
            $message->subject = get_string('message_recordingscored_subject', 'mod_presenterai', $a);
            $message->fullmessage = get_string('message_recordingscored_body', 'mod_presenterai', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = get_string('message_recordingscored_bodyhtml', 'mod_presenterai', $ahtml);
            $message->smallmessage = get_string('message_recordingscored_small', 'mod_presenterai', $a);

            return (bool) message_send($message);
        } finally {
            force_current_language($oldlang);
        }
    }
}
