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
 * Unit tests for analytics_engine timeline and participation methods
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for analytics_engine timeline and participation distribution
 */
class analytics_engine_timeline_test extends \advanced_testcase {

    /**
     * Test engagement timeline returns max 20 intervals
     */
    public function test_engagement_timeline_max_intervals() {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a session
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Test Session';
        $session->status = 'completed';
        $session->numquestions = 5;
        $session->currentquestion = 0;
        $session->timecreated = time();
        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Create responses spread over time (30 responses over 100 seconds)
        $basetime = time() - 100;
        for ($i = 0; $i < 30; $i++) {
            $response = new \stdClass();
            $response->sessionid = $sessionid;
            $response->userid = 2; // Admin user
            $response->questionid = 1;
            $response->answer = 'A';
            $response->iscorrect = 1;
            $response->responsetime = 5;
            $response->timecreated = $basetime + ($i * 3); // Spread over time
            $DB->insert_record('classengage_responses', $response);
        }

        // Test the method
        $engine = new analytics_engine($classengage->id, $context);
        $timeline = $engine->get_engagement_timeline($sessionid);

        // Verify max 20 intervals
        $this->assertLessThanOrEqual(20, count($timeline));

        // Verify each interval has required fields
        foreach ($timeline as $interval) {
            $this->assertArrayHasKey('interval', $interval);
            $this->assertArrayHasKey('timestamp', $interval);
            $this->assertArrayHasKey('label', $interval);
            $this->assertArrayHasKey('count', $interval);
            $this->assertArrayHasKey('is_peak', $interval);
            $this->assertArrayHasKey('is_dip', $interval);
        }
    }

    /**
     * Test engagement timeline identifies peaks and dips
     */
    public function test_engagement_timeline_peaks_and_dips() {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a session
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Test Session';
        $session->status = 'completed';
        $session->numquestions = 5;
        $session->currentquestion = 0;
        $session->timecreated = time();
        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Create responses with clear peak and dip pattern
        $basetime = time() - 100;
        $pattern = [10, 5, 2, 8, 15, 3, 12, 4, 1, 9]; // 15 is peak, 1 is dip
        $offset = 0;
        foreach ($pattern as $count) {
            for ($i = 0; $i < $count; $i++) {
                $response = new \stdClass();
                $response->sessionid = $sessionid;
                $response->userid = 2;
                $response->questionid = 1;
                $response->answer = 'A';
                $response->iscorrect = 1;
                $response->responsetime = 5;
                $response->timecreated = $basetime + $offset + $i;
                $DB->insert_record('classengage_responses', $response);
            }
            $offset += 10; // Move to next interval
        }

        // Test the method
        $engine = new analytics_engine($classengage->id, $context);
        $timeline = $engine->get_engagement_timeline($sessionid);

        // Verify at least one peak is identified
        $haspe = false;
        $hasdip = false;
        foreach ($timeline as $interval) {
            if ($interval['is_peak']) {
                $haspeak = true;
            }
            if ($interval['is_dip']) {
                $hasdip = true;
            }
        }

        $this->assertTrue($haspeak, 'Timeline should identify at least one peak');
    }

    /**
     * Test participation distribution categorizes students correctly
     */
    public function test_participation_distribution_categories() {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a session
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Test Session';
        $session->status = 'completed';
        $session->numquestions = 5;
        $session->currentquestion = 0;
        $session->timecreated = time();
        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Create students with different participation levels
        $students = [];
        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        // Student 0: 6 responses (high)
        for ($i = 0; $i < 6; $i++) {
            $response = new \stdClass();
            $response->sessionid = $sessionid;
            $response->userid = $students[0]->id;
            $response->questionid = 1;
            $response->answer = 'A';
            $response->iscorrect = 1;
            $response->responsetime = 5;
            $response->timecreated = time();
            $DB->insert_record('classengage_responses', $response);
        }

        // Student 1: 3 responses (moderate)
        for ($i = 0; $i < 3; $i++) {
            $response = new \stdClass();
            $response->sessionid = $sessionid;
            $response->userid = $students[1]->id;
            $response->questionid = 1;
            $response->answer = 'A';
            $response->iscorrect = 1;
            $response->responsetime = 5;
            $response->timecreated = time();
            $DB->insert_record('classengage_responses', $response);
        }

        // Student 2: 1 response (low)
        $response = new \stdClass();
        $response->sessionid = $sessionid;
        $response->userid = $students[2]->id;
        $response->questionid = 1;
        $response->answer = 'A';
        $response->iscorrect = 1;
        $response->responsetime = 5;
        $response->timecreated = time();
        $DB->insert_record('classengage_responses', $response);

        // Students 3-9: 0 responses (none)

        // Test the method
        $engine = new analytics_engine($classengage->id, $context);
        $distribution = $engine->get_participation_distribution($sessionid, $course->id);

        // Verify distribution
        $this->assertEquals(1, $distribution->high, 'Should have 1 high participation student');
        $this->assertEquals(1, $distribution->moderate, 'Should have 1 moderate participation student');
        $this->assertEquals(1, $distribution->low, 'Should have 1 low participation student');
        $this->assertEquals(7, $distribution->none, 'Should have 7 non-participating students');
        $this->assertEquals(10, $distribution->total_enrolled, 'Should have 10 total enrolled students');
    }

    /**
     * Test participation distribution message generation
     */
    public function test_participation_distribution_messages() {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
        $context = \context_module::instance($classengage->cmid);

        // Create a session
        $session = new \stdClass();
        $session->classengageid = $classengage->id;
        $session->name = 'Test Session';
        $session->status = 'completed';
        $session->numquestions = 5;
        $session->currentquestion = 0;
        $session->timecreated = time();
        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Create 10 students, 8 will participate (80% > 75%)
        $students = [];
        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        // Make 8 students participate
        for ($i = 0; $i < 8; $i++) {
            $response = new \stdClass();
            $response->sessionid = $sessionid;
            $response->userid = $students[$i]->id;
            $response->questionid = 1;
            $response->answer = 'A';
            $response->iscorrect = 1;
            $response->responsetime = 5;
            $response->timecreated = time();
            $DB->insert_record('classengage_responses', $response);
        }

        // Test the method
        $engine = new analytics_engine($classengage->id, $context);
        $distribution = $engine->get_participation_distribution($sessionid, $course->id);

        // Verify broad participation message
        $this->assertNotEmpty($distribution->message);
        $this->assertStringContainsString('broad', strtolower($distribution->message));
    }
}
