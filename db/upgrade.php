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

    // Add real-time quiz engine tables and fields (Requirements 5.1, 5.2, 7.1, 7.2).
    if ($oldversion < 2025120501) {

        // Add new fields to classengage_sessions table for pause/resume functionality.
        $table = new xmldb_table('classengage_sessions');

        // Add paused_at field.
        $field = new xmldb_field('paused_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'questionstarttime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add pause_duration field.
        $field = new xmldb_field('pause_duration', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'paused_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add timer_remaining field.
        $field = new xmldb_field('timer_remaining', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'pause_duration');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Classengage savepoint reached.
        upgrade_mod_savepoint(true, 2025120501, 'classengage');
    }

    if ($oldversion < 2025120502) {

        // Define table classengage_connections to be created.
        $table = new xmldb_table('classengage_connections');

        // Adding fields to table classengage_connections.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('connectionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('transport', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'polling');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'connected');
        $table->add_field('last_heartbeat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('current_question_answered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table classengage_connections.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'classengage_sessions', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table classengage_connections.
        $table->add_index('connectionid', XMLDB_INDEX_UNIQUE, ['connectionid']);
        $table->add_index('sessionid_status', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'status']);
        $table->add_index('sessionid_userid', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'userid']);
        $table->add_index('last_heartbeat', XMLDB_INDEX_NOTUNIQUE, ['last_heartbeat']);

        // Conditionally launch create table for classengage_connections.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Classengage savepoint reached.
        upgrade_mod_savepoint(true, 2025120502, 'classengage');
    }

    if ($oldversion < 2025120503) {

        // Define table classengage_response_queue to be created.
        $table = new xmldb_table('classengage_response_queue');

        // Adding fields to table classengage_response_queue.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('answer', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('client_timestamp', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('server_timestamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('processed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('is_late', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table classengage_response_queue.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'classengage_sessions', ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'classengage_questions', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table classengage_response_queue.
        $table->add_index('processed_timestamp', XMLDB_INDEX_NOTUNIQUE, ['processed', 'server_timestamp']);
        $table->add_index('session_question_user', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'questionid', 'userid']);

        // Conditionally launch create table for classengage_response_queue.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Classengage savepoint reached.
        upgrade_mod_savepoint(true, 2025120503, 'classengage');
    }

    if ($oldversion < 2025120504) {

        // Define table classengage_session_log to be created.
        $table = new xmldb_table('classengage_session_log');

        // Adding fields to table classengage_session_log.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('event_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('event_data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('latency_ms', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table classengage_session_log.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'classengage_sessions', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table classengage_session_log.
        $table->add_index('sessionid_eventtype', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'event_type']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        // Conditionally launch create table for classengage_session_log.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Classengage savepoint reached.
        upgrade_mod_savepoint(true, 2025120504, 'classengage');
    }

    return true;
}

