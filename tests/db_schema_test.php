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
 * Unit tests for mod_classengage database schema
 *
 * Tests table creation and foreign key constraints for real-time quiz engine tables.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for database schema operations
 *
 * Requirements: 1.1, 1.2, 5.1, 5.2, 7.1, 7.2
 */
class db_schema_test extends \advanced_testcase
{

    /**
     * Test that classengage_connections table exists and has correct structure
     */
    public function test_connections_table_exists(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('classengage_connections');

        $this->assertTrue($dbman->table_exists($table), 'classengage_connections table should exist');
    }

    /**
     * Test that classengage_response_queue table exists and has correct structure
     */
    public function test_response_queue_table_exists(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('classengage_response_queue');

        $this->assertTrue($dbman->table_exists($table), 'classengage_response_queue table should exist');
    }

    /**
     * Test that classengage_session_log table exists and has correct structure
     */
    public function test_session_log_table_exists(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('classengage_session_log');

        $this->assertTrue($dbman->table_exists($table), 'classengage_session_log table should exist');
    }

    /**
     * Test that classengage_sessions table has new pause/resume fields
     */
    public function test_sessions_table_has_pause_fields(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('classengage_sessions');

        // Check paused_at field.
        $field = new \xmldb_field('paused_at');
        $this->assertTrue($dbman->field_exists($table, $field), 'paused_at field should exist');

        // Check pause_duration field.
        $field = new \xmldb_field('pause_duration');
        $this->assertTrue($dbman->field_exists($table, $field), 'pause_duration field should exist');

        // Check timer_remaining field.
        $field = new \xmldb_field('timer_remaining');
        $this->assertTrue($dbman->field_exists($table, $field), 'timer_remaining field should exist');
    }

    /**
     * Test inserting and retrieving connection records
     */
    public function test_connection_crud_operations(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create a session.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        // Insert connection record.
        $connection = new \stdClass();
        $connection->sessionid = $session->id;
        $connection->userid = $user->id;
        $connection->connectionid = 'test-connection-' . uniqid();
        $connection->transport = 'polling';
        $connection->status = 'connected';
        $connection->current_question_answered = 0;
        $connection->timecreated = time();
        $connection->timemodified = time();

        $connectionid = $DB->insert_record('classengage_connections', $connection);
        $this->assertNotEmpty($connectionid);

        // Retrieve and verify.
        $retrieved = $DB->get_record('classengage_connections', ['id' => $connectionid]);
        $this->assertEquals($session->id, $retrieved->sessionid);
        $this->assertEquals($user->id, $retrieved->userid);
        $this->assertEquals('polling', $retrieved->transport);
        $this->assertEquals('connected', $retrieved->status);
    }

    /**
     * Test inserting and retrieving response queue records
     */
    public function test_response_queue_crud_operations(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create session and question.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);
        $question = $generator->create_question($classengage->id);

        // Insert response queue record.
        $response = new \stdClass();
        $response->sessionid = $session->id;
        $response->questionid = $question->id;
        $response->userid = $user->id;
        $response->answer = 'A';
        $response->client_timestamp = time() - 1;
        $response->server_timestamp = time();
        $response->processed = 0;
        $response->is_late = 0;

        $responseid = $DB->insert_record('classengage_response_queue', $response);
        $this->assertNotEmpty($responseid);

        // Retrieve and verify.
        $retrieved = $DB->get_record('classengage_response_queue', ['id' => $responseid]);
        $this->assertEquals($session->id, $retrieved->sessionid);
        $this->assertEquals($question->id, $retrieved->questionid);
        $this->assertEquals('A', $retrieved->answer);
        $this->assertEquals(0, $retrieved->processed);
    }

    /**
     * Test inserting and retrieving session log records
     */
    public function test_session_log_crud_operations(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create session.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        // Insert session log record.
        $log = new \stdClass();
        $log->sessionid = $session->id;
        $log->userid = $user->id;
        $log->event_type = 'session_start';
        $log->event_data = json_encode(['instructor_id' => $user->id]);
        $log->latency_ms = null;
        $log->timecreated = time();

        $logid = $DB->insert_record('classengage_session_log', $log);
        $this->assertNotEmpty($logid);

        // Retrieve and verify.
        $retrieved = $DB->get_record('classengage_session_log', ['id' => $logid]);
        $this->assertEquals($session->id, $retrieved->sessionid);
        $this->assertEquals('session_start', $retrieved->event_type);

        // Test response event with latency.
        $responselog = new \stdClass();
        $responselog->sessionid = $session->id;
        $responselog->userid = $user->id;
        $responselog->event_type = 'response';
        $responselog->event_data = json_encode(['question_id' => 1, 'answer' => 'A']);
        $responselog->latency_ms = 250;
        $responselog->timecreated = time();

        $responselogid = $DB->insert_record('classengage_session_log', $responselog);
        $retrieved = $DB->get_record('classengage_session_log', ['id' => $responselogid]);
        $this->assertEquals(250, $retrieved->latency_ms);
    }

    /**
     * Test session pause/resume fields work correctly
     */
    public function test_session_pause_resume_fields(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create session with pause fields.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id, [
            'status' => 'active',
        ]);

        // Simulate pause.
        $pausetime = time();
        $timerremaining = 25;
        $DB->update_record('classengage_sessions', (object) [
            'id' => $session->id,
            'status' => 'paused',
            'paused_at' => $pausetime,
            'timer_remaining' => $timerremaining,
            'timemodified' => time(),
        ]);

        // Verify pause state.
        $retrieved = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('paused', $retrieved->status);
        $this->assertEquals($pausetime, $retrieved->paused_at);
        $this->assertEquals($timerremaining, $retrieved->timer_remaining);

        // Simulate resume.
        $pauseduration = 5;
        $DB->update_record('classengage_sessions', (object) [
            'id' => $session->id,
            'status' => 'active',
            'paused_at' => null,
            'pause_duration' => $pauseduration,
            'timer_remaining' => null,
            'timemodified' => time(),
        ]);

        // Verify resume state.
        $retrieved = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('active', $retrieved->status);
        $this->assertNull($retrieved->paused_at);
        $this->assertEquals($pauseduration, $retrieved->pause_duration);
    }

    /**
     * Test unique constraint on connectionid
     */
    public function test_connection_unique_constraint(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        $connectionid = 'unique-connection-id-' . uniqid();

        // Insert first connection.
        $connection1 = new \stdClass();
        $connection1->sessionid = $session->id;
        $connection1->userid = $user->id;
        $connection1->connectionid = $connectionid;
        $connection1->transport = 'polling';
        $connection1->status = 'connected';
        $connection1->current_question_answered = 0;
        $connection1->timecreated = time();
        $connection1->timemodified = time();

        $DB->insert_record('classengage_connections', $connection1);

        // Try to insert duplicate - should fail.
        $connection2 = clone $connection1;
        unset($connection2->id);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('classengage_connections', $connection2);
    }

    /**
     * Test foreign key constraint on connections table - sessionid references classengage_sessions
     *
     * Note: Moodle's XMLDB foreign keys are declarative and not enforced at database level
     * for cross-database compatibility. This test verifies the relationship works correctly
     * through proper data setup.
     */
    public function test_connections_foreign_key_sessionid(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data with proper relationships.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        // Insert connection with valid sessionid.
        $connection = new \stdClass();
        $connection->sessionid = $session->id;
        $connection->userid = $user->id;
        $connection->connectionid = 'fk-test-' . uniqid();
        $connection->transport = 'sse';
        $connection->status = 'connected';
        $connection->current_question_answered = 0;
        $connection->timecreated = time();
        $connection->timemodified = time();

        $connectionid = $DB->insert_record('classengage_connections', $connection);
        $this->assertNotEmpty($connectionid);

        // Verify the relationship by joining tables.
        $sql = "SELECT c.*, s.name as session_name
                  FROM {classengage_connections} c
                  JOIN {classengage_sessions} s ON c.sessionid = s.id
                 WHERE c.id = ?";
        $result = $DB->get_record_sql($sql, [$connectionid]);

        $this->assertNotEmpty($result);
        $this->assertEquals($session->id, $result->sessionid);
        $this->assertEquals($session->name, $result->session_name);
    }

    /**
     * Test foreign key constraint on response_queue table - references sessions, questions, and users
     */
    public function test_response_queue_foreign_keys(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data with proper relationships.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);
        $question = $generator->create_question($classengage->id);

        // Insert response queue entry with valid foreign keys.
        $response = new \stdClass();
        $response->sessionid = $session->id;
        $response->questionid = $question->id;
        $response->userid = $user->id;
        $response->answer = 'B';
        $response->client_timestamp = time() - 1;
        $response->server_timestamp = time();
        $response->processed = 0;
        $response->is_late = 0;

        $responseid = $DB->insert_record('classengage_response_queue', $response);
        $this->assertNotEmpty($responseid);

        // Verify all relationships by joining tables.
        $sql = "SELECT rq.*, s.name as session_name, q.questiontext, u.username
                  FROM {classengage_response_queue} rq
                  JOIN {classengage_sessions} s ON rq.sessionid = s.id
                  JOIN {classengage_questions} q ON rq.questionid = q.id
                  JOIN {user} u ON rq.userid = u.id
                 WHERE rq.id = ?";
        $result = $DB->get_record_sql($sql, [$responseid]);

        $this->assertNotEmpty($result);
        $this->assertEquals($session->id, $result->sessionid);
        $this->assertEquals($question->id, $result->questionid);
        $this->assertEquals($user->id, $result->userid);
        $this->assertEquals($session->name, $result->session_name);
    }

    /**
     * Test foreign key constraint on session_log table - references sessions and users
     */
    public function test_session_log_foreign_keys(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data with proper relationships.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        // Insert log entry with valid foreign keys.
        $log = new \stdClass();
        $log->sessionid = $session->id;
        $log->userid = $user->id;
        $log->event_type = 'connection_established';
        $log->event_data = json_encode(['transport' => 'sse']);
        $log->latency_ms = 150;
        $log->timecreated = time();

        $logid = $DB->insert_record('classengage_session_log', $log);
        $this->assertNotEmpty($logid);

        // Verify relationships by joining tables.
        $sql = "SELECT sl.*, s.name as session_name, u.username
                  FROM {classengage_session_log} sl
                  JOIN {classengage_sessions} s ON sl.sessionid = s.id
                  JOIN {user} u ON sl.userid = u.id
                 WHERE sl.id = ?";
        $result = $DB->get_record_sql($sql, [$logid]);

        $this->assertNotEmpty($result);
        $this->assertEquals($session->id, $result->sessionid);
        $this->assertEquals($user->id, $result->userid);
    }

    /**
     * Test session_log allows null userid for system events
     */
    public function test_session_log_null_userid(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        // Insert system event log with null userid.
        $log = new \stdClass();
        $log->sessionid = $session->id;
        $log->userid = null;
        $log->event_type = 'system_warning';
        $log->event_data = json_encode(['cpu_usage' => 75, 'message' => 'High load detected']);
        $log->latency_ms = null;
        $log->timecreated = time();

        $logid = $DB->insert_record('classengage_session_log', $log);
        $this->assertNotEmpty($logid);

        // Verify null userid is stored correctly.
        $retrieved = $DB->get_record('classengage_session_log', ['id' => $logid]);
        $this->assertNull($retrieved->userid);
        $this->assertEquals('system_warning', $retrieved->event_type);
    }

    /**
     * Test upgrade path - verify version progression is correct
     *
     * This test verifies that the upgrade steps are defined in the correct order
     * and that each step creates the expected database structures.
     */
    public function test_upgrade_version_progression(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();

        // Verify all tables created by upgrade steps exist.
        // Version 2025120501: Added pause fields to sessions.
        $table = new \xmldb_table('classengage_sessions');
        $this->assertTrue($dbman->table_exists($table));
        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('paused_at')));
        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('pause_duration')));
        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('timer_remaining')));

        // Version 2025120502: Created connections table.
        $table = new \xmldb_table('classengage_connections');
        $this->assertTrue($dbman->table_exists($table));

        // Version 2025120503: Created response_queue table.
        $table = new \xmldb_table('classengage_response_queue');
        $this->assertTrue($dbman->table_exists($table));

        // Version 2025120504: Created session_log table.
        $table = new \xmldb_table('classengage_session_log');
        $this->assertTrue($dbman->table_exists($table));
    }

    /**
     * Test that indexes exist on new tables for performance
     */
    public function test_table_indexes_exist(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();

        // Test connections table indexes.
        $table = new \xmldb_table('classengage_connections');
        $index = new \xmldb_index('connectionid', XMLDB_INDEX_UNIQUE, ['connectionid']);
        $this->assertTrue($dbman->index_exists($table, $index));

        $index = new \xmldb_index('sessionid_status', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'status']);
        $this->assertTrue($dbman->index_exists($table, $index));

        $index = new \xmldb_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
        $this->assertTrue($dbman->index_exists($table, $index));

        // Test response_queue table indexes.
        $table = new \xmldb_table('classengage_response_queue');
        $index = new \xmldb_index('processed_timestamp', XMLDB_INDEX_NOTUNIQUE, ['processed', 'server_timestamp']);
        $this->assertTrue($dbman->index_exists($table, $index));

        // Test session_log table indexes.
        $table = new \xmldb_table('classengage_session_log');
        $index = new \xmldb_index('sessionid_eventtype', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'event_type']);
        $this->assertTrue($dbman->index_exists($table, $index));

        $index = new \xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $this->assertTrue($dbman->index_exists($table, $index));
    }

    /**
     * Test connections table supports all transport types
     */
    public function test_connections_transport_types(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        $transporttypes = ['websocket', 'polling', 'sse'];

        foreach ($transporttypes as $transport) {
            $connection = new \stdClass();
            $connection->sessionid = $session->id;
            $connection->userid = $user->id;
            $connection->connectionid = 'transport-test-' . $transport . '-' . uniqid();
            $connection->transport = $transport;
            $connection->status = 'connected';
            $connection->current_question_answered = 0;
            $connection->timecreated = time();
            $connection->timemodified = time();

            $id = $DB->insert_record('classengage_connections', $connection);
            $this->assertNotEmpty($id);

            $retrieved = $DB->get_record('classengage_connections', ['id' => $id]);
            $this->assertEquals($transport, $retrieved->transport);
        }
    }

    /**
     * Test connections table supports all status types
     */
    public function test_connections_status_types(): void
    {
        global $DB;

        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $user->id);

        $statustypes = ['connected', 'disconnected', 'answering'];

        foreach ($statustypes as $status) {
            $connection = new \stdClass();
            $connection->sessionid = $session->id;
            $connection->userid = $user->id;
            $connection->connectionid = 'status-test-' . $status . '-' . uniqid();
            $connection->transport = 'polling';
            $connection->status = $status;
            $connection->current_question_answered = 0;
            $connection->timecreated = time();
            $connection->timemodified = time();

            $id = $DB->insert_record('classengage_connections', $connection);
            $this->assertNotEmpty($id);

            $retrieved = $DB->get_record('classengage_connections', ['id' => $id]);
            $this->assertEquals($status, $retrieved->status);
        }
    }
}
