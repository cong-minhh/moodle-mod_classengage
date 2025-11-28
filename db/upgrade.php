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
 * Upgrade script for mod_classengage
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute classengage upgrade from the given old version
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_classengage_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Add clicker devices table for Web Services integration
    if ($oldversion < 2025110301) {
        
        // Define table classengage_clicker_devices to be created.
        $table = new xmldb_table('classengage_clicker_devices');

        // Adding fields to table classengage_clicker_devices.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('clickerid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table classengage_clicker_devices.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));

        // Adding indexes to table classengage_clicker_devices.
        $table->add_index('clickerid', XMLDB_INDEX_UNIQUE, array('clickerid'));
        $table->add_index('userid_clickerid', XMLDB_INDEX_UNIQUE, array('userid', 'clickerid'));

        // Conditionally launch create table for classengage_clicker_devices.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Classengage savepoint reached.
        upgrade_mod_savepoint(true, 2025110301, 'classengage');
    }

    return true;
}

