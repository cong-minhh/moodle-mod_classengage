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
 * Unit tests for mod_classengage scheduled tasks
 *
 * Tests the enterprise scheduled task functionality including:
 * - Stale connection cleanup
 * - Response queue processing
 * - Analytics aggregation
 * - Session log cleanup
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

use mod_classengage\constants;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled tasks unit tests
 */
class scheduled_tasks_test extends \advanced_testcase
{

    /** @var \testing_data_generator */
    protected $generator;

    /** @var \mod_classengage_generator */
    protected $plugingenerator;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = $this->getDataGenerator();
        $this->plugingenerator = $this->generator->get_plugin_generator('mod_classengage');
    }

    // =========================================================================
    // cleanup_stale_connections Task Tests
    // =========================================================================

    /**
     * Test cleanup_stale_connections task name
     */
    public function test_cleanup_stale_connections_get_name(): void
    {
        $task = new cleanup_stale_connections();
        $name = $task->get_name();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * Test cleanup_stale_connections removes stale connections
     */
    public function test_cleanup_stale_connections_removes_stale(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();
        $student = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);

        // Create a stale connection (last activity > timeout).
        $staletime = time() - constants::CONNECTION_STALE_TIMEOUT - 60;
        $this->plugingenerator->create_connection($session->id, $student->id, [
            'timemodified' => $staletime,
            'status' => 'connected',
        ]);

        // Verify connection exists.
        $this->assertEquals(1, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));

        // Run the task.
        $task = new cleanup_stale_connections();
        $task->execute();

        // Verify connection is now disconnected.
        $this->assertEquals(0, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));

        $this->assertEquals(1, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'disconnected',
        ]));
    }

    /**
     * Test cleanup_stale_connections preserves active connections
     */
    public function test_cleanup_stale_connections_preserves_active(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();
        $student = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);

        // Create an active connection (recent activity).
        $this->plugingenerator->create_connection($session->id, $student->id, [
            'timemodified' => time(),
            'status' => 'connected',
        ]);

        // Run the task.
        $task = new cleanup_stale_connections();
        $task->execute();

        // Verify connection is still connected.
        $this->assertEquals(1, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));
    }

    /**
     * Test cleanup_stale_connections deletes very old disconnected connections
     */
    public function test_cleanup_stale_connections_deletes_old(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();
        $student = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);

        // Create an old disconnected connection (> 24 hours).
        $oldtime = time() - (25 * 60 * 60);
        $this->plugingenerator->create_connection($session->id, $student->id, [
            'status' => 'disconnected',
            'timemodified' => $oldtime,
        ]);

        // Run the task.
        $task = new cleanup_stale_connections();
        $task->execute();

        // Verify connection is deleted.
        $this->assertEquals(0, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
        ]));
    }

    // =========================================================================
    // process_response_queue Task Tests
    // =========================================================================

    /**
     * Test process_response_queue task name
     */
    public function test_process_response_queue_get_name(): void
    {
        $task = new process_response_queue();
        $name = $task->get_name();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * Test process_response_queue handles empty queue gracefully
     */
    public function test_process_response_queue_empty(): void
    {
        $this->resetAfterTest(true);

        $task = new process_response_queue();

        // Should not throw exception.
        $task->execute();
        $this->assertTrue(true);
    }

    // =========================================================================
    // aggregate_analytics Task Tests
    // =========================================================================

    /**
     * Test aggregate_analytics task name
     */
    public function test_aggregate_analytics_get_name(): void
    {
        $task = new aggregate_analytics();
        $name = $task->get_name();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * Test aggregate_analytics handles no sessions gracefully
     */
    public function test_aggregate_analytics_empty(): void
    {
        $this->resetAfterTest(true);

        $task = new aggregate_analytics();

        // Should not throw exception.
        $task->execute();
        $this->assertTrue(true);
    }

    /**
     * Test aggregate_analytics caches session summaries
     */
    public function test_aggregate_analytics_caches_summaries(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data with completed session.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();
        $student = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id, [
            'status' => 'completed',
            'timecompleted' => time() - 1800, // 30 minutes ago.
        ]);

        $question = $this->plugingenerator->create_question($classengage->id);
        $this->plugingenerator->link_questions_to_session($session->id, [$question->id]);
        $this->plugingenerator->create_response(
            $session->id,
            $question->id,
            $classengage->id,
            $student->id
        );

        // Run the task.
        $task = new aggregate_analytics();
        $task->execute();

        // Verify summary is cached.
        $cache = \cache::make('mod_classengage', 'analytics_summary');
        $cachekey = "session_summary_{$session->id}";
        $summary = $cache->get($cachekey);

        $this->assertNotFalse($summary);
        $this->assertIsObject($summary);
        $this->assertObjectHasProperty('total_participants', $summary);
        $this->assertObjectHasProperty('total_responses', $summary);
    }

    // =========================================================================
    // cleanup_session_logs Task Tests
    // =========================================================================

    /**
     * Test cleanup_session_logs task name
     */
    public function test_cleanup_session_logs_get_name(): void
    {
        $task = new cleanup_session_logs();
        $name = $task->get_name();

        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * Test cleanup_session_logs removes old logs
     */
    public function test_cleanup_session_logs_removes_old(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);

        // Insert old log entry (100 days ago).
        $oldtime = time() - (100 * 24 * 60 * 60);
        $DB->insert_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $teacher->id,
            'event_type' => 'test_event',
            'event_data' => '{}',
            'timecreated' => $oldtime,
        ]);

        // Insert recent log entry.
        $DB->insert_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $teacher->id,
            'event_type' => 'test_event',
            'event_data' => '{}',
            'timecreated' => time(),
        ]);

        $this->assertEquals(2, $DB->count_records('classengage_session_log', [
            'sessionid' => $session->id,
        ]));

        // Run the task.
        $task = new cleanup_session_logs();
        $task->execute();

        // Verify old log is deleted, recent log preserved.
        $this->assertEquals(1, $DB->count_records('classengage_session_log', [
            'sessionid' => $session->id,
        ]));
    }

    /**
     * Test cleanup_session_logs removes processed queue entries
     */
    public function test_cleanup_session_logs_cleans_queue(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', ['course' => $course->id]);
        $teacher = $this->generator->create_user();
        $student = $this->generator->create_user();

        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);
        $question = $this->plugingenerator->create_question($classengage->id);

        // Insert old processed queue entry (8 days ago).
        $oldtime = time() - (8 * 24 * 60 * 60);
        $DB->insert_record('classengage_response_queue', [
            'sessionid' => $session->id,
            'questionid' => $question->id,
            'userid' => $student->id,
            'answer' => 'A',
            'server_timestamp' => $oldtime,
            'processed' => 1,
        ]);

        // Insert recent processed queue entry.
        $DB->insert_record('classengage_response_queue', [
            'sessionid' => $session->id,
            'questionid' => $question->id,
            'userid' => $student->id,
            'answer' => 'B',
            'server_timestamp' => time(),
            'processed' => 1,
        ]);

        $this->assertEquals(2, $DB->count_records('classengage_response_queue', [
            'sessionid' => $session->id,
        ]));

        // Run the task.
        $task = new cleanup_session_logs();
        $task->execute();

        // Verify old queue entry is deleted, recent preserved.
        $this->assertEquals(1, $DB->count_records('classengage_response_queue', [
            'sessionid' => $session->id,
        ]));
    }

    // =========================================================================
    // Task Registration Tests
    // =========================================================================

    /**
     * Test all scheduled tasks are registered
     */
    public function test_tasks_are_registered(): void
    {
        $tasks = \core\task\manager::get_all_scheduled_tasks();

        $tasklist = array_map(fn($t) => get_class($t), $tasks);

        $this->assertContains(
            'mod_classengage\task\cleanup_stale_connections',
            $tasklist
        );
        $this->assertContains(
            'mod_classengage\task\process_response_queue',
            $tasklist
        );
        $this->assertContains(
            'mod_classengage\task\aggregate_analytics',
            $tasklist
        );
        $this->assertContains(
            'mod_classengage\task\cleanup_session_logs',
            $tasklist
        );
    }
}
