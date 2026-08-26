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
 * Upgrade steps for quizaccess_sanad_lockdown.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The old plugin version.
 * @return bool
 */
function xmldb_quizaccess_sanad_lockdown_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026070700) {
        upgrade_plugin_savepoint(true, 2026070700, 'quizaccess', 'sanad_lockdown');
    }

    // 2026070901: Widen exitpassword column to 255 chars to safely store bcrypt/argon2 hashes.
    if ($oldversion < 2026070901) {
        $table = new \xmldb_table('quizaccess_sanad_lockdown');
        $field = new \xmldb_field('exitpassword', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'tokenexpiry');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070901, 'quizaccess', 'sanad_lockdown');
    }

    // 2026070903: Add alloweddomains field to the quizaccess_sanad_lockdown table.
    if ($oldversion < 2026070903) {
        $table = new \xmldb_table('quizaccess_sanad_lockdown');
        $field = new \xmldb_field('alloweddomains', XMLDB_TYPE_TEXT, null, null, null, null, null, 'exitpassword');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070903, 'quizaccess', 'sanad_lockdown');
    }

    // 2026071100: Add quizaccess_sanad_devices table.
    if ($oldversion < 2026071100) {
        $table = new \xmldb_table('quizaccess_sanad_devices');

        // Adding fields to table quizaccess_sanad_devices.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('hardwareid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('devicemodel', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('devicebrand', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('expirydate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table quizaccess_sanad_devices.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table quizaccess_sanad_devices.
        $table->add_index('hardwareid_idx', XMLDB_INDEX_UNIQUE, ['hardwareid']);

        // Conditionally launch create table for quizaccess_sanad_devices.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071100, 'quizaccess', 'sanad_lockdown');
    }

    // 2026072200: Add strictness field to quizaccess_sanad_lockdown table.
    if ($oldversion < 2026072200) {
        $table = new \xmldb_table('quizaccess_sanad_lockdown');
        $field = new \xmldb_field('strictness', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'high', 'enabled');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072200, 'quizaccess', 'sanad_lockdown');
    }

    // 2026082600: Hash legacy plaintext exit passwords with bcrypt.
    // Values already starting with '$2y$' (60 chars) are left untouched.
    if ($oldversion < 2026082600) {
        $rs = $DB->get_recordset('quizaccess_sanad_lockdown', [], '', 'id, exitpassword');
        foreach ($rs as $rec) {
            if (empty($rec->exitpassword)) {
                continue;
            }
            if (strpos($rec->exitpassword, '$2y$') === 0 && strlen($rec->exitpassword) === 60) {
                continue; // Already hashed.
            }
            $upd = new stdClass();
            $upd->id = $rec->id;
            $upd->exitpassword = password_hash($rec->exitpassword, PASSWORD_DEFAULT);
            $DB->update_record('quizaccess_sanad_lockdown', $upd);
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026082600, 'quizaccess', 'sanad_lockdown');
    }

    return true;
}
