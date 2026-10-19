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
 * The identities the Soapbox importer and SOLA's interlock agree on.
 *
 * A row of presenterai_migration_map says "this source row, on this site, became
 * that target row". SOLA's soapbox_cleanup task asks the same table whether a
 * recording has been taken over, so the table name and the site id it looks for
 * must be byte for byte what the importer wrote. They live here, once, and a
 * test in each plugin pins the literals: a mismatch would make the interlock
 * silently never fire.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ids {
    /** @var string presenterai_migration_map.sourcetable for a recording row. */
    public const SOURCE_REC = 'local_ai_course_assistant_sbx_rec';

    /** @var string presenterai_migration_map.sourcetable for an assignment row. */
    public const SOURCE_ASSIGN = 'local_ai_course_assistant_sbx_assign';

    /** @var string presenterai_migration_map.sourcetable for a topic row. */
    public const SOURCE_TOPIC = 'local_ai_course_assistant_sbx_topic';

    /**
     * The id of this site as the map records it in sourcesiteid.
     *
     * Source ids are only unique within a site, so a course restored from another
     * site brings source rows whose ids collide with this site's. Hashing the
     * address together with Moodle's site identifier tells the two apart, and a
     * hash keeps neither value on a page or in a log.
     *
     * @return string Forty hexadecimal characters.
     */
    public static function site_id(): string {
        global $CFG;

        return sha1($CFG->wwwroot . '|' . get_site_identifier());
    }
}
