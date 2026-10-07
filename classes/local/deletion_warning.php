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
 * The advance message a learner gets before their recording is deleted on its deletion date.
 *
 * deletewarndays (design 7.1) says how many days ahead. The date in the message
 * is the row's own expiresat, never one worked out from the settings, because
 * expiresat is the promise the learner was already shown and a settings change
 * never rewrites it (design 8.2, D22). A message computed from today's setting
 * could name a date the cleanup task is not going to act on.
 *
 * Each row is messaged once per deletion date. deletewarnedat records the
 * send, and retention_tool resets it to 0 whenever it rewrites expiresat, so a
 * new date gets its own message. A send that fails is still recorded, so a
 * learner whose message preferences reject it is not retried every hour for
 * days.
 *
 * Only a deletion date sends this. Pruning has no date and is announced on the
 * recorder screen instead (design 8.7d), and a deletion by a person is not
 * scheduled at all.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deletion_warning {
    /**
     * Send every warning that is due now.
     *
     * Due means the media still exists, the attempt is finished, the deletion
     * date is in the future and no more than deletewarndays away, and this date
     * has not been warned about yet.
     *
     * @param int $now The run's notion of now.
     * @return int Messages sent.
     */
    public static function send_due(int $now): int {
        global $DB;

        $days = (int) get_config('mod_presenterai', 'deletewarndays');
        if ($days <= 0) {
            return 0;
        }

        $rs = $DB->get_recordset_select(
            'presenterai_recording',
            'storagekey IS NOT NULL AND status <> :uploading AND expiresat > :now AND expiresat <= :horizon
                AND deletewarnedat = 0',
            [
                'uploading' => recording_manager::STATUS_UPLOADING,
                'now' => $now,
                'horizon' => $now + $days * DAYSECS,
            ],
            'expiresat, id'
        );
        $sent = 0;
        foreach ($rs as $rec) {
            try {
                if (self::send_one($rec, $now)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                // One learner's broken course must not stop everybody else's
                // message. The class name only: a message can carry a URL.
                debugging(
                    'mod_presenterai could not send the deletion warning for recording ' . (int) $rec->id . ': '
                        . get_class($e),
                    DEBUG_DEVELOPER
                );
            }
            // Recorded whether or not it went, so no row is retried forever.
            $DB->set_field('presenterai_recording', 'deletewarnedat', $now, ['id' => $rec->id]);
        }
        $rs->close();

        return $sent;
    }

    /**
     * Send the warning for one recording.
     *
     * Skipped, returning false, for a learner who is deleted or suspended and
     * for an activity hidden from learners, where the link in the message would
     * lead nowhere.
     *
     * @param \stdClass $rec The presenterai_recording row.
     * @param int $now The run's notion of now.
     * @return bool True when the message was handed to the message API.
     */
    public static function send_one(\stdClass $rec, int $now): bool {
        global $DB;

        $instance = $DB->get_record('presenterai', ['id' => $rec->presenteraiid]);
        if (!$instance) {
            return false;
        }
        $cm = get_coursemodule_from_instance('presenterai', $instance->id, $instance->course, false, IGNORE_MISSING);
        if (!$cm || empty($cm->visible)) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $rec->userid]);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return false;
        }
        $expiresat = (int) $rec->expiresat;
        if ($expiresat <= $now) {
            return false;
        }

        $ctx = \context_module::instance((int) $cm->id);
        $url = new \moodle_url('/mod/presenterai/view.php', ['id' => $cm->id]);
        // The plain-text parts get the name unescaped, so '&' doesn't arrive as '&amp;'.
        $activity = format_string($instance->name, true, ['context' => $ctx, 'escape' => false]);

        $a = (object) [
            'activity' => $activity,
            // From the row, in the learner's timezone (D22).
            'date' => userdate($expiresat, get_string('strftimedatetime', 'langconfig'), $user->timezone ?? 99),
        ];
        $downloadkey = access::may_download($rec, $ctx, (int) $rec->userid)
            ? 'message_deletionwarning_download'
            : 'message_deletionwarning_nodownload';
        $downloadtext = get_string($downloadkey, 'mod_presenterai');

        $message = new \core\message\message();
        $message->component = 'mod_presenterai';
        $message->name = 'deletionwarning';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->courseid = (int) $instance->course;
        $message->notification = 1;
        $message->subject = get_string('message_deletionwarning_subject', 'mod_presenterai', $a);
        $message->fullmessage = get_string('message_deletionwarning_body', 'mod_presenterai', $a) . "\n\n"
            . $downloadtext . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $ahtml = clone $a;
        $ahtml->activity = format_string($instance->name, true, ['context' => $ctx]);
        $message->fullmessagehtml = get_string('message_deletionwarning_bodyhtml', 'mod_presenterai', $ahtml)
            . \html_writer::tag('p', s($downloadtext));
        $message->smallmessage = get_string('message_deletionwarning_small', 'mod_presenterai', $a);
        $message->contexturl = $url->out(false);
        $message->contexturlname = $activity;

        return (bool) message_send($message);
    }
}
