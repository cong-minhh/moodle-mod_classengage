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
 * Unit tests for mod_classengage engagement calculator
 *
 * Tests the engagement calculator including:
 * - Engagement level calculation
 * - Activity counts
 * - Responsiveness indicators
 * - Cache invalidation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\engagement_calculator
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Engagement calculator unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class engagement_calculator_test extends \advanced_testcase
{

    /** @var \stdClass Test course */
    private $course;

    /** @var \stdClass Test classengage instance */
    private $classengage;

    /** @var \stdClass Test instructor */
    private $instructor;

    /** @var array Test students */
    private $students = [];

    /** @var \stdClass Test session */
    private $session;

    /** @var array Test questions */
    private $questions = [];

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest(true);

        // Create course.
        $this->course = $this->getDataGenerator()->create_course();

        // Create instructor.
        $this->instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->instructor->id, $this->course->id, 'editingteacher');

        // Create 10 students.
        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }

        // Create classengage instance.
        $this->classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $this->course->id,
        ]);

        // Create session and questions.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $this->session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'timelimit' => 30,
            'numquestions' => 5,
            'timestarted' => time(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $question = $generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $this->questions[] = $question;
            $generator->link_questions_to_session($this->session->id, [$question->id]);
        }
    }

    /**
     * Helper to create responses
     */
    private function create_responses(int $count, array $studentids = null): void
    {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $studentids = $studentids ?? array_slice(array_column($this->students, 'id'), 0, $count);

        foreach ($studentids as $studentid) {
            $generator->create_response(
                $this->session->id,
                $this->questions[0]->id,
                $this->classengage->id,
                $studentid,
                ['answer' => 'A', 'iscorrect' => 1]
            );
        }
    }

    /**
     * Test high engagement level (70%+ participation)
     */
    public function test_calculate_engagement_level_high(): void
    {
        // 8 of 10 students respond = 80% participation.
        $this->create_responses(8);

        $calculator = new engagement_calculator($this->session->id, $this->course->id);
        $result = $calculator->calculate_engagement_level();

        $this->assertIsObject($result);
        $this->assertObjectHasProperty('percentage', $result);
        $this->assertObjectHasProperty('level', $result);
        $this->assertObjectHasProperty('message', $result);
        $this->assertGreaterThanOrEqual(70, $result->percentage);
        $this->assertEquals('high', $result->level);
    }

    /**
     * Test moderate engagement level (40-70% participation)
     */
    public function test_calculate_engagement_level_moderate(): void
    {
        // 5 of 10 students respond = 50% participation.
        $this->create_responses(5);

        $calculator = new engagement_calculator($this->session->id, $this->course->id);
        $result = $calculator->calculate_engagement_level();

        $this->assertIsObject($result);
        $this->assertGreaterThanOrEqual(40, $result->percentage);
        $this->assertLessThan(70, $result->percentage);
        $this->assertEquals('moderate', $result->level);
    }

    /**
     * Test low engagement level (<40% participation)
     */
    public function test_calculate_engagement_level_low(): void
    {
        // 2 of 10 students respond = 20% participation.
        $this->create_responses(2);

        $calculator = new engagement_calculator($this->session->id, $this->course->id);
        $result = $calculator->calculate_engagement_level();

        $this->assertIsObject($result);
        $this->assertLessThan(40, $result->percentage);
        $this->assertEquals('low', $result->level);
    }

    /**
     * Test get_activity_counts returns expected structure
     */
    public function test_get_activity_counts(): void
    {
        $this->create_responses(5);

        $calculator = new engagement_calculator($this->session->id, $this->course->id);
        $counts = $calculator->get_activity_counts();

        $this->assertIsObject($counts);
        $this->assertObjectHasProperty('questions_answered', $counts);
        $this->assertObjectHasProperty('poll_submissions', $counts);
        $this->assertObjectHasProperty('reactions', $counts);
        $this->assertEquals(5, $counts->questions_answered);
    }

    /**
     * Test get_responsiveness_indicator returns expected structure
     */
    public function test_get_responsiveness_indicator(): void
    {
        $this->create_responses(5);

        $calculator = new engagement_calculator($this->session->id, $this->course->id);
        $indicator = $calculator->get_responsiveness_indicator();

        $this->assertIsObject($indicator);
        $this->assertObjectHasProperty('avg_time', $indicator);
        $this->assertObjectHasProperty('median_time', $indicator);
        $this->assertObjectHasProperty('pace', $indicator);
        $this->assertObjectHasProperty('message', $indicator);
    }

    /**
     * Test invalidate_cache clears cached data
     */
    public function test_invalidate_cache(): void
    {
        $calculator = new engagement_calculator($this->session->id, $this->course->id);

        // First call to populate cache.
        $calculator->calculate_engagement_level();

        // Invalidate cache - should not throw exception.
        $calculator->invalidate_cache();
        $this->assertTrue(true);
    }

    /**
     * Test empty session handling
     */
    public function test_empty_session_handling(): void
    {
        // No responses created.
        $calculator = new engagement_calculator($this->session->id, $this->course->id);

        $result = $calculator->calculate_engagement_level();
        $this->assertIsObject($result);
        $this->assertEquals(0, $result->percentage);
        $this->assertEquals('low', $result->level);
    }

    /**
     * Test get_enrolled_student_count returns correct count
     */
    public function test_enrolled_student_count(): void
    {
        $calculator = new engagement_calculator($this->session->id, $this->course->id);

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($calculator);
        $method = $reflection->getMethod('get_enrolled_student_count');
        $method->setAccessible(true);

        $count = $method->invoke($calculator);

        // Should be 10 students enrolled.
        $this->assertEquals(10, $count);
    }

    /**
     * Test determine_pace categorizes correctly
     */
    public function test_determine_pace(): void
    {
        $calculator = new engagement_calculator($this->session->id, $this->course->id);

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($calculator);
        $method = $reflection->getMethod('determine_pace');
        $method->setAccessible(true);

        // Fast pace: avg < median.
        $result = $method->invoke($calculator, 5.0, 10.0, 2.0);
        $this->assertEquals('fast', $result['pace']);

        // Slow pace: avg > median significantly.
        $result = $method->invoke($calculator, 15.0, 10.0, 2.0);
        $this->assertEquals('slow', $result['pace']);
    }
}
