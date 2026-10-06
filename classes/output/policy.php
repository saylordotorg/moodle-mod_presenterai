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
use mod_presenterai\local\retention;

/**
 * What a learner is told before they record: the policy callout and the privacy paragraph.
 *
 * Both are Group A in section 9.1 of docs/DESIGN-visual-feedback-and-retention.md:
 * they describe the recording the learner is about to make, so they come from
 * the effective setting now, never from any existing row. That is why every
 * sentence here says "this recording" and never "your recordings": the attempt
 * list below can truthfully show an older attempt with a deletion date while
 * this callout truthfully says new ones are kept (section 8.2).
 *
 * The day count comes from retention::prospective_days() rather than
 * effective_days(), because the number promised here has to be the one the
 * site will actually honour, which is not the configured value when the
 * cleanup task is disabled or a bucket lifecycle rule is declared.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class policy {
    /** @var int What privacy_visualnote promises when the site has not set visualdatadays (design 9.7). */
    public const DEFAULT_VISUAL_DATA_DAYS = 30;

    /**
     * Context for templates/policy_callout.mustache.
     *
     * One of four variants (design 9.3), a support contact appended only to the
     * two that say downloading is off and only when a contact exists, and the
     * pruning warning only when the next recording really will cost the learner
     * their oldest one (design 8.7d).
     *
     * @param \stdClass $instance The presenterai row.
     * @param \context_module $ctx The activity's context.
     * @param int $userid The learner the page is for.
     * @return array ['variant', 'heading', 'body' (HTML), 'prunewarning' (text, '' when none)]
     */
    public static function callout(\stdClass $instance, \context_module $ctx, int $userid): array {
        $days = retention::prospective_days($instance);
        $candownload = access::may_download_own_prospectively($ctx, $userid);

        $variant = ($days > 0 ? 'delete' : 'keep') . '_' . ($candownload ? 'dl' : 'nodl');

        // The keep bodies name where the recording stays; the delete bodies and
        // headings name the day count. Neither is a sentence fragment slotted
        // into another, so a translator sees whole sentences (design 9.4).
        $heading = get_string('record_' . $variant . '_heading', 'mod_presenterai', $days);
        $body = get_string(
            'record_' . $variant . '_body',
            'mod_presenterai',
            $days > 0 ? $days : self::site_name()
        );

        if (!$candownload) {
            // A route out is offered only where one exists (design 9.3).
            $contact = self::support_link();
            if ($contact !== '') {
                // The keep variant has no date, so it gets its own sentence
                // rather than one that says "before that date".
                $body .= ' ' . get_string('record_nodl_contact_' . ($days > 0 ? 'delete' : 'keep'), 'mod_presenterai', $contact);
            }
        }

        return [
            'variant' => $variant,
            'heading' => $heading,
            'body' => $body,
            'prunewarning' => self::prune_warning($instance, $userid),
        ];
    }

    /**
     * The privacy paragraph, assembled from whole sentence clauses (design 9.4).
     *
     * Order: stem, deletion, frames, visual note, kept after, download, joined
     * by single spaces. The frames and visual note clauses render only when
     * still frames are actually taken, which needs both the instance's
     * videovision switch and a mode that records a camera. Soapbox told every
     * audio only learner about frames that were never taken.
     *
     * The returned string is HTML, because the stem carries the site name
     * through format_string().
     *
     * @param \stdClass $instance The presenterai row.
     * @param bool|null $candownload Whether this learner may download their own
     *     recordings, so the paragraph agrees with the callout above it. Null
     *     falls back to the site switch alone.
     * @return string
     */
    public static function privacy_paragraph(\stdClass $instance, ?bool $candownload = null): string {
        $days = retention::prospective_days($instance);
        if ($candownload === null) {
            $candownload = access::site_allows_learner_download();
        }

        $clauses = [get_string('privacy_stem', 'mod_presenterai', self::site_name())];
        $clauses[] = $days > 0
            ? get_string('privacy_delete', 'mod_presenterai', $days)
            : get_string('privacy_keep', 'mod_presenterai');

        if (self::frames_taken($instance)) {
            $clauses[] = $days > 0
                ? get_string('privacy_frames_delete', 'mod_presenterai')
                : get_string('privacy_frames_keep', 'mod_presenterai');
            $clauses[] = get_string('privacy_visualnote', 'mod_presenterai', self::visual_data_days());
        }

        $clauses[] = get_string('privacy_kept_after', 'mod_presenterai');
        $clauses[] = $candownload
            ? get_string('privacy_download_on', 'mod_presenterai')
            : get_string('privacy_download_off', 'mod_presenterai');

        return implode(' ', $clauses);
    }

    /**
     * Whether a recording in this activity has still frames taken from it.
     *
     * @param \stdClass $instance The presenterai row.
     * @return bool
     */
    public static function frames_taken(\stdClass $instance): bool {
        return !empty($instance->videovision) && (string) ($instance->mode ?? 'video') !== 'audio';
    }

    /**
     * The pruning warning, or '' when recording again deletes nothing.
     *
     * Pruning keeps the newest storedattempts recordings with media, so the
     * warning is due once the learner already holds that many: the next
     * finalize pushes the oldest one out.
     *
     * @param \stdClass $instance The presenterai row.
     * @param int $userid The learner.
     * @return string
     */
    public static function prune_warning(\stdClass $instance, int $userid): string {
        global $DB;

        $keep = (int) ($instance->storedattempts ?? 0);
        if ($keep <= 0) {
            return '';
        }

        $select = 'presenteraiid = :p AND userid = :u AND storagekey IS NOT NULL AND status NOT IN (:s1, :s2)';
        $params = ['p' => $instance->id, 'u' => $userid, 's1' => 'uploading', 's2' => 'abandoned'];
        if ($DB->count_records_select('presenterai_recording', $select, $params) < $keep) {
            return '';
        }

        $oldest = $DB->get_records_select(
            'presenterai_recording',
            $select,
            $params,
            'timecreated ASC, id ASC',
            'id, timecreated',
            0,
            1
        );
        $oldest = reset($oldest);

        return get_string(
            'record_prune_warning',
            'mod_presenterai',
            userdate((int) $oldest->timecreated, get_string('strftimedate', 'langconfig'))
        );
    }

    /**
     * The site's support contact as a link, or '' when the site has none.
     *
     * The support page wins over the email address when both are set, because
     * a page can say who to ask and how, and an address cannot.
     *
     * @return string HTML.
     */
    public static function support_link(): string {
        global $CFG;

        $page = trim((string) ($CFG->supportpage ?? ''));
        $email = trim((string) ($CFG->supportemail ?? ''));
        $name = trim((string) ($CFG->supportname ?? ''));

        if ($page !== '') {
            return \html_writer::link(new \moodle_url($page), s($name !== '' ? $name : $page));
        }
        if ($email !== '') {
            return \html_writer::link('mailto:' . $email, s($name !== '' ? $name : $email));
        }

        return '';
    }

    /**
     * How many days the AI's raw note about the frames is kept.
     *
     * There is no forever for this clock (design 7.4), so an unset or
     * non-positive value reads as the default rather than as "kept".
     *
     * @return int
     */
    private static function visual_data_days(): int {
        $days = (int) get_config('mod_presenterai', 'visualdatadays');

        return $days > 0 ? $days : self::DEFAULT_VISUAL_DATA_DAYS;
    }

    /**
     * The site name for {$a}, with no branding token resolver (design 9.2).
     *
     * @return string HTML.
     */
    private static function site_name(): string {
        global $SITE;

        return format_string($SITE->fullname, true, ['context' => \context_system::instance()]);
    }
}
