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
 * Unit tests for enterprise features
 *
 * Tests the enterprise improvements including:
 * - Analytics cache table
 * - New scheduled tasks
 * - New capabilities
 * - Enterprise settings
 * - New cache definitions
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/classengage/lib.php');

/**
 * Enterprise features test class
 */
class enterprise_features_test extends \advanced_testcase
{

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test that the analytics cache table exists and has correct structure
     */
    public function test_analytics_cache_table_exists(): void
    {
        global $DB;

        $dbman = $DB->get_manager();
        $this->assertTrue(
            $dbman->table_exists('classengage_analytics_cache'),
            'Analytics cache table should exist'
        );
    }

    /**
     * Test analytics cache table can store and retrieve metrics
     */
    public function test_analytics_cache_crud_operations(): void
    {
        global $DB;

        // Create a test course and activity.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create a session.
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Test Session';
        $session->numquestions = 5;
        $session->timelimit = 30;
        $session->shufflequestions = 1;
        $session->shuffleanswers = 1;
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->createdby = 2;
        $session->timecreated = time();
        $session->timemodified = time();
        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Insert a cache entry.
        $cache = new \stdClass();
        $cache->sessionid = $sessionid;
        $cache->metric_type = 'summary';
        $cache->metric_key = 'test_metric';
        $cache->metric_value = json_encode(['total' => 100, 'correct' => 80]);
        $cache->computed_at = time();
        $cache->expires_at = time() + 300;
        $cacheid = $DB->insert_record('classengage_analytics_cache', $cache);

        $this->assertNotEmpty($cacheid, 'Cache entry should be created');

        // Retrieve the cache entry.
        $retrieved = $DB->get_record('classengage_analytics_cache', ['id' => $cacheid]);
        $this->assertEquals('summary', $retrieved->metric_type);
        $this->assertEquals('test_metric', $retrieved->metric_key);

        $data = json_decode($retrieved->metric_value, true);
        $this->assertEquals(100, $data['total']);
        $this->assertEquals(80, $data['correct']);

        // Test unique constraint on sessionid + metric_type + metric_key.
        $duplicate = clone $cache;
        unset($duplicate->id);
        try {
            $DB->insert_record('classengage_analytics_cache', $duplicate);
            $this->fail('Expected exception for duplicate metric key');
        } catch (\dml_exception $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }
    }

    /**
     * Test new database indexes exist
     */
    public function test_new_indexes_exist(): void
    {
        global $DB;

        $dbman = $DB->get_manager();

        // Check classengage_questions has the new index.
        $table = new \xmldb_table('classengage_questions');
        $index = new \xmldb_index('classengageid_status', XMLDB_INDEX_NOTUNIQUE, ['classengageid', 'status']);
        $this->assertTrue(
            $dbman->index_exists($table, $index),
            'Index classengageid_status should exist on classengage_questions'
        );

        // Check classengage_responses has the new index.
        $table = new \xmldb_table('classengage_responses');
        $index = new \xmldb_index('classengageid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['classengageid', 'timecreated']);
        $this->assertTrue(
            $dbman->index_exists($table, $index),
            'Index classengageid_timecreated should exist on classengage_responses'
        );

        // Check classengage_session_log has the new index.
        $table = new \xmldb_table('classengage_session_log');
        $index = new \xmldb_index('userid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $this->assertTrue(
            $dbman->index_exists($table, $index),
            'Index userid_timecreated should exist on classengage_session_log'
        );
    }

    /**
     * Test new capabilities are defined
     */
    public function test_new_capabilities_exist(): void
    {
        $capabilities = get_all_capabilities();
        $capnames = array_column($capabilities, 'name');

        $this->assertContains(
            'mod/classengage:viewownresults',
            $capnames,
            'Capability viewownresults should exist'
        );

        $this->assertContains(
            'mod/classengage:exportdata',
            $capnames,
            'Capability exportdata should exist'
        );
    }

    /**
     * Test viewownresults capability is assigned to students by default
     */
    public function test_viewownresults_capability_for_students(): void
    {
        global $DB;

        // Create test context.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a student user and enrol them.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Check student has the capability.
        $this->assertTrue(
            has_capability('mod/classengage:viewownresults', $context, $student),
            'Students should have viewownresults capability'
        );
    }

    /**
     * Test exportdata capability is NOT assigned to students by default
     */
    public function test_exportdata_capability_not_for_students(): void
    {
        global $DB;

        // Create test context.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a student user and enrol them.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Check student does NOT have the capability.
        $this->assertFalse(
            has_capability('mod/classengage:exportdata', $context, $student),
            'Students should NOT have exportdata capability'
        );
    }

    /**
     * Test exportdata capability is assigned to editing teachers by default
     */
    public function test_exportdata_capability_for_teachers(): void
    {
        global $DB;

        // Create test context.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create an editing teacher user and enrol them.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Check teacher has the capability.
        $this->assertTrue(
            has_capability('mod/classengage:exportdata', $context, $teacher),
            'Editing teachers should have exportdata capability'
        );
    }

    /**
     * Test scheduled tasks are registered
     */
    public function test_scheduled_tasks_registered(): void
    {
        global $DB;

        $tasks = $DB->get_records('task_scheduled', ['component' => 'mod_classengage']);
        $taskclasses = array_column($tasks, 'classname');

        $this->assertContains(
            '\mod_classengage\task\archive_old_sessions',
            $taskclasses,
            'archive_old_sessions task should be registered'
        );

        $this->assertContains(
            '\mod_classengage\task\warm_active_caches',
            $taskclasses,
            'warm_active_caches task should be registered'
        );
    }

    /**
     * Test archive_old_sessions task can be instantiated
     */
    public function test_archive_old_sessions_task_instantiation(): void
    {
        $task = new \mod_classengage\task\archive_old_sessions();

        $this->assertInstanceOf(
            \core\task\scheduled_task::class,
            $task,
            'archive_old_sessions should be a scheduled task'
        );

        $name = $task->get_name();
        $this->assertNotEmpty($name, 'Task should have a name');
    }

    /**
     * Test warm_active_caches task can be instantiated
     */
    public function test_warm_active_caches_task_instantiation(): void
    {
        $task = new \mod_classengage\task\warm_active_caches();

        $this->assertInstanceOf(
            \core\task\scheduled_task::class,
            $task,
            'warm_active_caches should be a scheduled task'
        );

        $name = $task->get_name();
        $this->assertNotEmpty($name, 'Task should have a name');
    }

    /**
     * Test enterprise settings have correct default values
     */
    public function test_enterprise_settings_defaults(): void
    {
        // These should have default values from the upgrade or first use.
        $logretention = get_config('mod_classengage', 'log_retention_days');
        $this->assertEquals(90, $logretention, 'Log retention default should be 90 days');

        // Note: Other settings may not have defaults until admin sets them.
        // We just verify the config system works for the plugin.
    }

    /**
     * Test cache definitions are registered by attempting to instantiate them
     */
    public function test_cache_definitions_registered(): void
    {
        // Test user_performance cache can be created (proves definition exists).
        $cache = \cache::make('mod_classengage', 'user_performance');
        $this->assertInstanceOf(
            \cache::class,
            $cache,
            'user_performance cache should be creatable'
        );

        // Test session_summary cache can be created (proves definition exists).
        $cache = \cache::make('mod_classengage', 'session_summary');
        $this->assertInstanceOf(
            \cache::class,
            $cache,
            'session_summary cache should be creatable'
        );
    }

    /**
     * Test user_performance cache can store and retrieve data
     */
    public function test_user_performance_cache_operations(): void
    {
        $cache = \cache::make('mod_classengage', 'user_performance');

        // Store some data.
        $data = ['score' => 85, 'rank' => 3, 'responses' => 10];
        $key = 'session_1_user_2';
        $cache->set($key, $data);

        // Retrieve the data.
        $retrieved = $cache->get($key);
        $this->assertEquals($data, $retrieved, 'Cache should return stored data');

        // Delete the data.
        $cache->delete($key);
        $this->assertFalse($cache->get($key), 'Deleted cache entry should return false');
    }

    /**
     * Test session_summary cache can store and retrieve data
     */
    public function test_session_summary_cache_operations(): void
    {
        $cache = \cache::make('mod_classengage', 'session_summary');

        // Store some data.
        $data = [
            'total_responses' => 150,
            'participants' => 25,
            'accuracy' => 78.5,
        ];
        $key = 'session_42';
        $cache->set($key, $data);

        // Retrieve the data.
        $retrieved = $cache->get($key);
        $this->assertEquals($data, $retrieved, 'Cache should return stored data');
    }

    /**
     * Test plugin version is correct
     */
    public function test_plugin_version(): void
    {
        global $DB;

        $version = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'mod_classengage',
            'name' => 'version'
        ]);

        $this->assertEquals(
            2025122005,
            (int) $version,
            'Plugin version should be 2025122005'
        );
    }

    /**
     * Test archive task execution with no old sessions
     */
    public function test_archive_task_execution_empty(): void
    {
        $task = new \mod_classengage\task\archive_old_sessions();

        // This should run without errors even with no data.
        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'No old sessions to archive',
            $output,
            'Task should report no sessions to archive'
        );
    }

    /**
     * Test warm caches task execution with no active sessions
     */
    public function test_warm_caches_task_execution_empty(): void
    {
        $task = new \mod_classengage\task\warm_active_caches();

        // This should run without errors even with no data.
        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'No active sessions',
            $output,
            'Task should report no active sessions'
        );
    }

    /**
     * Test warm caches task execution with an active session
     */
    public function test_warm_caches_task_with_active_session(): void
    {
        global $DB;

        // Create a test course and activity.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);

        // Create an active session.
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Active Test Session';
        $session->numquestions = 5;
        $session->timelimit = 30;
        $session->shufflequestions = 1;
        $session->shuffleanswers = 1;
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->createdby = 2;
        $session->timecreated = time();
        $session->timemodified = time();
        $DB->insert_record('classengage_sessions', $session);

        // Run the task.
        $task = new \mod_classengage\task\warm_active_caches();

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'Warmed caches for 1 active sessions',
            $output,
            'Task should warm cache for the active session'
        );
    }
}
