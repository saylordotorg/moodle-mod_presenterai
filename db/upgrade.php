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

    if ($oldversion < 2026100602) {
        // The advance deletion message is sent once per deletion date.
        $table = new xmldb_table('presenterai_recording');
        $field = new xmldb_field('deletewarnedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'mediagonereason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026100602, 'presenterai');
    }

    if ($oldversion < 2026100700) {
        // Phase 3: D23 scoring switch and D24 opt out on the activity.
        $table = new xmldb_table('presenterai');
        $fields = [
            new xmldb_field('visualscored', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'videovision'),
            new xmldb_field('allowvisualoptout', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'visualscored'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // D24: the learner's per attempt opt out.
        $table = new xmldb_table('presenterai_recording');
        $field = new xmldb_field('visualoptout', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'visualevidenceat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // D21: the gated summary and which visual section the learner sees.
        $table = new xmldb_table('presenterai_score');
        $fields = [
            new xmldb_field('visualsummary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'tips'),
            new xmldb_field('visualstatus', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'visualsummary'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Design 5.3: the staff only log of rejected feedback strings.
        $table = new xmldb_table('presenterai_gatelog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('recordingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('target', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('layer', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('gaterule', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rejectedtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('recordingid', XMLDB_KEY_FOREIGN, ['recordingid'], 'presenterai_recording', ['id']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026100700, 'presenterai');
    }

    if ($oldversion < 2026100701) {
        // The gate log's old column name, rule, is a reserved word on SQL Server,
        // where an unquoted insert or select of it is a syntax error. Sites that ran an
        // earlier build of the 2026100700 step have the old name.
        $table = new xmldb_table('presenterai_gatelog');
        $field = new xmldb_field('rule', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'layer');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'gaterule');
        }

        upgrade_mod_savepoint(true, 2026100701, 'presenterai');
    }

    if ($oldversion < 2026100702) {
        // The score table's visualstatus was CHAR NOT NULL with an empty
        // default, which XMLDB refuses to install (it debugs, and a CLI
        // install treats that as a failure). It is nullable now; readers
        // treat null as the empty status.
        $table = new xmldb_table('presenterai_score');
        $field = new xmldb_field('visualstatus', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'visualsummary');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
            $dbman->change_field_notnull($table, $field);
        }

        upgrade_mod_savepoint(true, 2026100702, 'presenterai');
    }

    if ($oldversion < 2026100703) {
        // D28: an activity can hold AI feedback for a teacher to release.
        $table = new xmldb_table('presenterai');
        $field = new xmldb_field(
            'reviewbeforerelease',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'allowvisualoptout'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Every score that exists already was shown when it was written, so
        // the default of 1 is the truth for each of them.
        $table = new xmldb_table('presenterai_score');
        $field = new xmldb_field('released', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'visualstatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026100703, 'presenterai');
    }

    return true;
}
