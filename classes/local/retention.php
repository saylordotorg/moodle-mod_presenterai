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

use mod_presenterai\local\storage\store_factory;

/**
 * How long a recording's media is kept, worked out in one place.
 *
 * Section 8 of docs/DESIGN-visual-feedback-and-retention.md is the
 * specification and DECISIONS.md D5 and D22 are the reasons. The one rule worth
 * repeating here, because every caller depends on it: expiresat is a fact about
 * a row, written once at finalize from expiry_for() and never rewritten by a
 * settings change. Nothing in this class reads a row. It answers "what would a
 * recording made now be promised", and the row remembers the answer it got.
 *
 * Soapbox clamped the window to 1..28 days (soapbox_config::clamp_retention_days)
 * and wrote now + days at finalize unconditionally, so "keep forever" could not
 * be expressed at all. Here anything at or below zero means no automatic
 * deletion and there is no upper clamp.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class retention {
    /** @var int The per activity value that means "use the site setting". */
    public const USE_SITE = -1;

    /**
     * The number of days an activity keeps media, or 0 for no automatic deletion.
     *
     * A port of design section 8.1. The activity value -1 defers to the site
     * setting, 0 keeps forever, N is N days. A negative site value, which the
     * settings page accepts rather than refuses, also resolves to 0 here, so
     * there is one rule about what a non-positive number means and it lives
     * where the number is acted on.
     *
     * @param \stdClass $instance A presenterai row carrying retentiondays.
     * @return int Days, or 0 for never.
     */
    public static function effective_days(\stdClass $instance): int {
        $days = (int) ($instance->retentiondays ?? self::USE_SITE);
        if ($days === self::USE_SITE) {
            $days = (int) get_config('mod_presenterai', 'retentiondays');
        }

        return $days > 0 ? $days : 0;
    }

    /**
     * The expiresat value for a recording finalized at $now.
     *
     * When the recording is on S3 and the administrator has declared a bucket
     * lifecycle rule, the bucket deletes on its own clock, so the shorter of the
     * two is written, and a lifecycle with no plugin retention is still a date
     * (design 8.6, point 1). That is the same arithmetic prospective_days()
     * uses for the callout, so the date a learner was promised before speaking
     * and the date their row shows afterwards agree. Writing the earlier date
     * also lets the cleanup task record the deletion on the row rather than
     * finding the object gone later.
     *
     * @param \stdClass $instance A presenterai row carrying retentiondays.
     * @param int $now The finalize time.
     * @param string $backend The recording's backend, or '' when it is not known.
     * @return int A unix time, or 0 when nothing will delete the media on a clock.
     */
    public static function expiry_for(\stdClass $instance, int $now, string $backend = ''): int {
        $days = self::bounded_by_lifecycle(self::effective_days($instance), $backend);

        return $days > 0 ? $now + $days * DAYSECS : 0;
    }

    /**
     * The days a bucket lifecycle rule allows a recording on this backend, or 0.
     *
     * Read live from the setting, because the declaration describes the bucket
     * as it is now. Only S3 has a lifecycle.
     *
     * @param string $backend A store_factory BACKEND_* name.
     * @return int Days, or 0 when no lifecycle rule is declared for this backend.
     */
    public static function lifecycle_days(string $backend): int {
        if ($backend !== store_factory::BACKEND_S3) {
            return 0;
        }
        $lifecycle = (int) get_config('mod_presenterai', 's3lifecycledays');

        return $lifecycle > 0 ? $lifecycle : 0;
    }

    /**
     * Shorten a retention period to a declared bucket lifecycle.
     *
     * @param int $days Plugin retention in days, 0 for none.
     * @param string $backend A store_factory BACKEND_* name.
     * @return int Days, or 0 when nothing deletes on a clock.
     */
    private static function bounded_by_lifecycle(int $days, string $backend): int {
        $lifecycle = self::lifecycle_days($backend);
        if ($lifecycle > 0) {
            return $days > 0 ? min($days, $lifecycle) : $lifecycle;
        }

        return $days;
    }

    /**
     * What the callout above the recorder may promise about a recording made now.
     *
     * Not the same number as effective_days(), in two ways, and both are about
     * not telling a learner something the site will not do.
     *
     * When the cleanup task is disabled nothing deletes on a clock, whatever
     * the setting says, so the promise is "kept until removed" (design 8.7f).
     *
     * When the S3 backend is in use and the administrator has declared a bucket
     * lifecycle rule, the bucket deletes on its own clock whatever the database
     * believes, so the shorter of the two is the true answer, and a rule with no
     * plugin retention at all is still a deletion date (design 8.6, point 1).
     * The declaration is read for the default backend only, because a new
     * recording is written to the default backend.
     *
     * @param \stdClass $instance A presenterai row carrying retentiondays.
     * @return int Days, or 0 when a recording made now is kept until someone removes it.
     */
    public static function prospective_days(\stdClass $instance): int {
        $days = self::cleanup_task_enabled() ? self::effective_days($instance) : 0;

        return self::bounded_by_lifecycle($days, store_factory::default_backend());
    }

    /**
     * Whether the cleanup task exists and is allowed to run.
     *
     * Checked at render time from the scheduled task record, so an
     * administrator who disables the task stops the plugin quoting deletion
     * dates nobody is going to honour on the next page load.
     *
     * @return bool
     */
    public static function cleanup_task_enabled(): bool {
        $task = \core\task\manager::get_scheduled_task(\mod_presenterai\task\cleanup::class);
        if (!$task) {
            return false;
        }

        return !$task->get_disabled();
    }
}
