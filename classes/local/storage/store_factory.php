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

namespace mod_presenterai\local\storage;

/**
 * The only place a storage backend is chosen, and the only place one is built.
 *
 * Two rules, and both of them are here because getting either one wrong is
 * silent rather than noisy.
 *
 * The first: an existing recording's store comes from the backend named on its
 * row, never from the site setting. Section 4.7 of the plan allows an admin to
 * switch backends without migrating anything, so a site can hold rows on both
 * at once indefinitely. Soapbox builds one storage object before its cleanup
 * loop (classes/task/soapbox_cleanup.php:50) and returns early when storage is
 * unconfigured (:47-49), which on a switched site would skip every surviving
 * row on the other backend forever. for_recording() is the method callers
 * reach for; for_backend() is for the cases that genuinely have a name rather
 * than a row, such as a new attempt or a CLI migration.
 *
 * The second: when s3 is selected and not configured, every path through here
 * throws. Returning fs instead would put a learner's video somewhere other than
 * where the admin believes it goes, and nothing downstream would notice,
 * because an fs_store handed an S3 key simply finds no file. An error an admin
 * can read beats media filed in the wrong place.
 *
 * The one deliberate hole in that second rule is unchecked_store(), which the
 * settings page and the self test need in order to report on a backend that is
 * not working yet.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class store_factory {

    /** @var string Moodle's own file storage. The default, see plan section 4.8. */
    public const BACKEND_FS = 'fs';

    /** @var string An S3 compatible bucket. */
    public const BACKEND_S3 = 's3';

    /**
     * The backend machine names this plugin knows, in the order to offer them.
     *
     * The settings page builds its menu from this, so a third backend is added
     * in one place rather than in a list that drifts away from the factory.
     *
     * @return string[]
     */
    public static function backends(): array {
        return [self::BACKEND_FS, self::BACKEND_S3];
    }

    /**
     * The store that holds this recording's media.
     *
     * This is the method almost every caller wants. Playback, download,
     * transcription, the retention task, instance deletion and the privacy
     * provider all act on a row that already exists, and the row remembers
     * which backend accepted its bytes.
     *
     * @param \stdClass $recording A presenterai_recording row carrying at least its backend column.
     * @return store_interface
     */
    public static function for_recording(\stdClass $recording): store_interface {
        if (!isset($recording->backend)) {
            // Not a misconfiguration but a query that did not select the column.
            // Falling back to the site setting here is exactly the bug this
            // class exists to prevent, so it is an error rather than a guess.
            throw new \coding_exception(
                'presenterai_recording row passed to store_factory::for_recording() has no backend column',
                'Select the backend column, or use for_backend() if you really do only have a name.'
            );
        }

        return self::for_backend((string) $recording->backend);
    }

    /**
     * The store new media should be written to on this site right now.
     *
     * Only for media that does not exist yet. Anything with a row goes through
     * for_recording().
     *
     * @return store_interface
     */
    public static function default_store(): store_interface {
        return self::for_backend(self::default_backend());
    }

    /**
     * The backend machine name the site setting currently selects.
     *
     * Unset means fs, which is the documented default because it works on a
     * stock Moodle with nothing configured. A value this plugin does not know
     * is returned as it stands rather than corrected: for_backend() is where it
     * is rejected, so there is one place that decides what is valid.
     *
     * Stamp a new recording row from the store's own name() rather than from
     * this, because name() is the backend that actually accepted the bytes.
     *
     * @return string
     */
    public static function default_backend(): string {
        $backend = trim((string) get_config('mod_presenterai', 'backend'));

        return $backend !== '' ? $backend : self::BACKEND_FS;
    }

    /**
     * The store for a named backend, refusing one that cannot do its job.
     *
     * Throws on a name this plugin does not have, and on a backend that is
     * selected but not configured. An unconfigured backend is a
     * moodle_exception rather than a coding_exception because an administrator
     * caused it and an administrator can fix it, so it deserves a string they
     * can act on.
     *
     * @param string $backend One of the names in backends().
     * @return store_interface
     */
    public static function for_backend(string $backend): store_interface {
        $store = self::unchecked_store($backend);

        if (!$store->is_configured()) {
            throw new \moodle_exception('errorstorenotconfigured', 'mod_presenterai', '', $backend);
        }

        return $store;
    }

    /**
     * The store for a named backend, without asking whether it is configured.
     *
     * For the settings page and the self test only. Both exist to tell an
     * administrator why a backend is not working, and neither can do that with
     * an exception in place of an object. is_configured() returns false rather
     * than throwing for the same reason, which store_interface records.
     *
     * A fresh instance every call. The stores read their configuration when
     * they use it, and caching one here would only matter if that stopped being
     * true, which is not a bet worth taking to save an object allocation.
     *
     * @param string $backend One of the names in backends().
     * @return store_interface
     */
    public static function unchecked_store(string $backend): store_interface {
        return match ($backend) {
            self::BACKEND_FS => new fs_store(),
            self::BACKEND_S3 => new s3_store(),
            default => throw new \moodle_exception('errorunknownbackend', 'mod_presenterai', '', $backend),
        };
    }
}
