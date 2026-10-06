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

/**
 * Upgrade steps for mod_presenterai.
 *
 * @package    mod_presenterai
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bring an installed mod_presenterai up to the current schema.
 *
 * Every step checks before it changes anything, because a site may have been
 * installed from an install.xml that already had part of the change.
 *
 * @param int $oldversion The version the site is upgrading from.
 * @return bool Always true.
 */
function xmldb_presenterai_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026100600) {
        // Why a recording's media is gone (design 8.4). Nullable for the same
        // XMLDB reason as uploadid: an empty default on CHAR NOT NULL is refused.
        $table = new xmldb_table('presenterai_recording');
        $field = new xmldb_field('mediagonereason', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'mediadeletedat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // D22: keep every attempt's recording unless the activity says otherwise.
        $instancetable = new xmldb_table('presenterai');
        $stored = new xmldb_field('storedattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'maxattempts');
        $dbman->change_field_default($instancetable, $stored);

        // The retention and abandoned sweeps query on expiresat alone and on
        // status with timecreated, so the old combined index served neither.
        $old = new xmldb_index('statusexp', XMLDB_INDEX_NOTUNIQUE, ['status', 'expiresat']);
        if ($dbman->index_exists($table, $old)) {
            $dbman->drop_index($table, $old);
        }
        $indexes = [
            new xmldb_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expiresat']),
            new xmldb_index('statustime', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']),
            new xmldb_index('uploadid', XMLDB_INDEX_NOTUNIQUE, ['uploadid']),
            new xmldb_index('deckkey', XMLDB_INDEX_NOTUNIQUE, ['deckkey']),
            new xmldb_index('frameskey', XMLDB_INDEX_NOTUNIQUE, ['frameskey']),
        ];
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_mod_savepoint(true, 2026100600, 'presenterai');
    }

    if ($oldversion < 2026100601) {
        // One page per unfinished attempt: two tabs sharing a row each deleted
        // the other's upload. Null on existing rows, which begin_attempt
        // replaces the next time it hands a row out.
        $table = new xmldb_table('presenterai_recording');
        $field = new xmldb_field('clienttoken', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'uploadid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026100601, 'presenterai');
    }

    return true;
}
