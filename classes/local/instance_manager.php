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
 * Turns submitted activity settings into the values that are written.
 *
 * The port of SOLA's soapbox_config::clamp_assignment()
 * (classes/soapbox_config.php:146-189), moved to the one place every write of
 * an instance goes through: presenterai_add_instance() and
 * presenterai_update_instance() call normalise() before touching the database.
 * The form validates as well, but the form is not the only caller of those two
 * functions (course restore of an older version, the generator, a web service
 * that creates modules), so the bounds live here and the form's validation is
 * the friendly half of the same rules.
 *
 * Two departures from SOLA, both deliberate:
 *
 * - storedattempts has no floor of 1. SOLA forced max(1, ...), which made "keep
 *   every attempt" impossible to express; here 0 means exactly that (D22 and
 *   design section 8.4).
 * - Retention is guarded by a capability. Who may change how long a learner's
 *   recording is kept is a policy question (design section 7.3), so without
 *   mod/presenterai:setretention the value posted from the form is ignored
 *   rather than trusted, whatever the browser sent.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instance_manager {
    /** @var string[] Recording modes an activity may use. */
    public const MODES = ['video', 'audio'];

    /** @var int retentiondays value meaning "use the site setting" (db/install.xml default). */
    public const RETENTION_SITE = -1;

    /** @var int retentiondays value meaning "keep until someone deletes it". */
    public const RETENTION_KEEP = 0;

    /**
     * Clamp and complete instance data before it is written.
     *
     * Only fields present on $data are changed or added, apart from
     * retentiondays, which is always resolved, and slidevision and
     * videovision, which are forced to 0 when slides are off or the mode is
     * audio. That matters on update:
     * update_record() writes every column it is given, so a field filled in
     * here with a default would silently overwrite whatever the activity had.
     *
     * @param \stdClass $data Instance data from the form or another caller. Changed in place and returned.
     * @param \stdClass|null $existing The current instance row on update, null on add.
     * @return \stdClass The same object, normalised.
     */
    public static function normalise(\stdClass $data, ?\stdClass $existing): \stdClass {
        if (property_exists($data, 'mode')) {
            $data->mode = in_array($data->mode, self::MODES, true) ? $data->mode : 'video';
        }

        $ceiling = config::max_recording_seconds();
        if (property_exists($data, 'maxseconds')) {
            $data->maxseconds = self::clamp((int) $data->maxseconds, config::MIN_SECONDS_FLOOR, $ceiling);
        }
        if (property_exists($data, 'minseconds')) {
            // Bounded by the maximum this write leaves in place, which is the
            // submitted one when there is one and the stored one otherwise.
            $max = $data->maxseconds ?? ($existing->maxseconds ?? $ceiling);
            $max = self::clamp((int) $max, config::MIN_SECONDS_FLOOR, $ceiling);
            $data->minseconds = self::clamp((int) $data->minseconds, config::MIN_SECONDS_FLOOR, $max);
        }

        if (property_exists($data, 'maxattempts')) {
            $data->maxattempts = max(0, (int) $data->maxattempts);
        }
        if (property_exists($data, 'storedattempts')) {
            // No floor of 1. Zero keeps every attempt's recording (D22).
            $data->storedattempts = max(0, (int) $data->storedattempts);
        }

        if (property_exists($data, 'slidesenabled')) {
            $data->slidesenabled = empty($data->slidesenabled) ? 0 : 1;
        }
        // Slide vision reads the deck, so with slides off it has nothing to read.
        // Phase 1 has no form field for it; this keeps a stale 1 from surviving
        // a teacher switching slides off.
        $slidesenabled = $data->slidesenabled ?? ($existing->slidesenabled ?? 0);
        if (empty($slidesenabled)) {
            $data->slidevision = 0;
        } else if (property_exists($data, 'slidevision')) {
            $data->slidevision = empty($data->slidevision) ? 0 : 1;
        }
        // Video vision reads frames of the speaker, and an audio activity has none.
        $mode = $data->mode ?? ($existing->mode ?? 'video');
        if ($mode === 'audio') {
            $data->videovision = 0;
        } else if (property_exists($data, 'videovision')) {
            $data->videovision = empty($data->videovision) ? 0 : 1;
        }

        $data->retentiondays = self::resolve_retention($data, $existing);
        // The form's two retention fields are not columns. Removed so nothing
        // downstream mistakes them for the stored value.
        unset($data->retentionmode, $data->retentiondaysvalue);

        return $data;
    }

    /**
     * Whether the current user may change this activity's retention.
     *
     * Checked in the module context when the course module exists, which it
     * does on update and, because add_moduleinfo() creates the course module
     * before calling presenterai_add_instance(), on add as well. The course
     * context is the fallback for a caller that has no course module yet, and
     * is what the form checks when adding.
     *
     * @param \stdClass $data Instance data carrying coursemodule and/or course.
     * @return bool
     */
    public static function has_setretention(\stdClass $data): bool {
        $context = null;
        if (!empty($data->coursemodule)) {
            $context = \context_module::instance((int) $data->coursemodule, IGNORE_MISSING);
        }
        if (!$context && !empty($data->course)) {
            $context = \context_course::instance((int) $data->course, IGNORE_MISSING);
        }
        if (!$context) {
            return false;
        }
        return has_capability('mod/presenterai:setretention', $context);
    }

    /**
     * The site's retention, in days, with anything at or below zero meaning keep.
     *
     * Design section 8.1 resolves a non-positive site value to "no automatic
     * deletion"; this reads it the same way.
     *
     * @return int Days, or 0 for no automatic deletion.
     */
    public static function site_retention_days(): int {
        return max(0, (int) get_config('mod_presenterai', 'retentiondays'));
    }

    /**
     * The retention that applies to an activity storing this retentiondays value.
     *
     * Used by the form to describe what is in force. A thin wrapper over the
     * retention class, so the form and the expiry date written at finalize
     * cannot drift apart.
     *
     * @param int $retentiondays The instance column: -1 site, 0 keep, N days.
     * @return int Days, or 0 for no automatic deletion.
     */
    public static function effective_retention_days(int $retentiondays): int {
        return retention::effective_days((object) ['retentiondays' => $retentiondays]);
    }

    /**
     * Work out the retentiondays value to write.
     *
     * The form sends a mode and a day count, and those are the only retention
     * values a browser can reach: moodleform::get_data() returns registered
     * elements only, and retentiondays is not one. So the capability guard sits
     * on the form's fields. A user without it gets what the activity already
     * had, or the site value for a new activity (design 7.3: frozen at the
     * site value), whatever was posted.
     *
     * A caller that sets retentiondays directly is code, not a browser (a
     * generator, a CLI script, a later migration), and is trusted to have made
     * its own decision; the value is only bounded. Guarding that path too
     * would make every test and script that creates an instance depend on
     * which user happened to be set when it ran.
     *
     * @param \stdClass $data Instance data.
     * @param \stdClass|null $existing The current row on update, null on add.
     * @return int The value for the retentiondays column.
     */
    private static function resolve_retention(\stdClass $data, ?\stdClass $existing): int {
        $fallback = $existing ? (int) $existing->retentiondays : self::RETENTION_SITE;

        if (isset($data->retentionmode)) {
            if (!self::has_setretention($data)) {
                return $fallback;
            }
            switch ($data->retentionmode) {
                case 'site':
                    return self::RETENTION_SITE;
                case 'keep':
                    return self::RETENTION_KEEP;
                case 'days':
                    // The minimum positive value is 1, so "delete immediately"
                    // cannot be typed by accident (design 8.7a).
                    return max(1, (int) ($data->retentiondaysvalue ?? 1));
                default:
                    return $fallback;
            }
        }

        if (property_exists($data, 'retentiondays')) {
            // Anything below -1 is not a value with a meaning; treat it as the site's.
            return max(self::RETENTION_SITE, (int) $data->retentiondays);
        }

        return $fallback;
    }

    /**
     * Clamp an integer to a range.
     *
     * @param int $value The value.
     * @param int $min The lowest allowed value.
     * @param int $max The highest allowed value. When below $min, $min wins.
     * @return int
     */
    private static function clamp(int $value, int $min, int $max): int {
        return max($min, min($value, max($min, $max)));
    }
}
