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
 * Security integration tests for mod_classengage
 *
 * Tests security aspects including:
 * - Role-based access control
 * - Action restrictions by role
 * - Enrollment checks
 * - Course isolation
 * - Capability verification
 * - Rate limiting enforcement
 * - Input sanitization
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Security integration tests
 *
 * @group mod_classengage
 * @group mod_classengage_integration
 */
class security_test extends \advanced_testcase
{

    /** @var \stdClass Test course */
    private $course;

    /** @var \stdClass Test classengage instance */
    private $classengage;

    /** @var \context_module Module context */
    private $context;

    /** @var \stdClass Test instructor */
    private $instructor;

    /** @var \stdClass Test student */
    private $student;

    /** @var \stdClass Test session */
    private $session;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();

        $this->instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->instructor->id, $this->course->id, 'editingteacher');

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $this->classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $this->course->id,
        ]);
        $cm = get_coursemodule_from_instance('classengage', $this->classengage->id);
        $this->context = \context_module::instance($cm->id);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $this->session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'timestarted' => time(),
        ]);
    }

    /**
     * Test student cannot start quiz (instructor capability)
     */
    public function test_student_cannot_start_quiz(): void
    {
        $this->setUser($this->student);

        // Student should not have the startquiz capability.
        $canstart = has_capability('mod/classengage:startquiz', $this->context);
        $this->assertFalse($canstart);
    }

    /**
     * Test instructor has start quiz capability
     */
    public function test_instructor_has_start_quiz_capability(): void
    {
        $this->setUser($this->instructor);

        // Instructor should have the startquiz capability.
        $canstart = has_capability('mod/classengage:startquiz', $this->context);
        $this->assertTrue($canstart);
    }

    /**
     * Test student cannot manage questions
     */
    public function test_student_cannot_manage_questions(): void
    {
        $this->setUser($this->student);

        // Student should not have capability to manage questions.
        $canmanage = has_capability('mod/classengage:managequestions', $this->context);
        $this->assertFalse($canmanage);
    }

    /**
     * Test instructor can manage questions
     */
    public function test_instructor_can_manage_questions(): void
    {
        $this->setUser($this->instructor);

        // Instructor should have capability.
        $canmanage = has_capability('mod/classengage:managequestions', $this->context);
        $this->assertTrue($canmanage);
    }

    /**
     * Test unenrolled user access is denied
     */
    public function test_unenrolled_user_access_denied(): void
    {
        // Create unenrolled user.
        $unenrolleduser = $this->getDataGenerator()->create_user();
        $this->setUser($unenrolleduser);

        // Check enrollment.
        $isenrolled = is_enrolled($this->context, $unenrolleduser->id);
        $this->assertFalse($isenrolled);
    }

    /**
     * Test cross-course access is denied
     */
    public function test_cross_course_access_denied(): void
    {
        // Create another course.
        $othercourse = $this->getDataGenerator()->create_course();
        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otheruser->id, $othercourse->id, 'student');

        $this->setUser($otheruser);

        // User should not be enrolled in original course's activity.
        $isenrolled = is_enrolled($this->context, $otheruser->id);
        $this->assertFalse($isenrolled);
    }

    /**
     * Test session access requires view capability
     */
    public function test_session_access_requires_capability(): void
    {
        $this->setUser($this->student);

        // Student should have view capability.
        $canview = has_capability('mod/classengage:view', $this->context);
        $this->assertTrue($canview);

        // But not startquiz capability.
        $canstart = has_capability('mod/classengage:startquiz', $this->context);
        $this->assertFalse($canstart);
    }

    /**
     * Test rate limiting enforcement
     */
    public function test_rate_limiting_enforcement(): void
    {
        $limiter = new rate_limiter(5, 60); // 5 requests per minute.

        // Use up the limit.
        for ($i = 0; $i < 5; $i++) {
            $result = $limiter->check($this->student->id, 'test_action');
            $this->assertTrue($result->allowed);
        }

        // Next request should be blocked.
        $result = $limiter->check($this->student->id, 'test_action');
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
    }

    /**
     * Test input sanitization for answer values
     */
    public function test_input_sanitization(): void
    {
        $engine = new response_capture_engine();

        // Test XSS attempt in answer.
        $xssattempt = '<script>alert("xss")</script>';
        $result = $engine->validate_response($xssattempt, 'multichoice');
        $this->assertFalse($result->valid);

        // Test SQL injection attempt.
        $sqlinjection = "A' OR '1'='1";
        $result = $engine->validate_response($sqlinjection, 'multichoice');
        $this->assertFalse($result->valid);

        // Valid answer should pass.
        $validanswer = 'A';
        $result = $engine->validate_response($validanswer, 'multichoice');
        $this->assertTrue($result->valid);
    }

    /**
     * Test student can take quiz
     */
    public function test_student_can_take_quiz(): void
    {
        $this->setUser($this->student);

        // Student should have takequiz capability.
        $cantake = has_capability('mod/classengage:takequiz', $this->context);
        $this->assertTrue($cantake);
    }
}
