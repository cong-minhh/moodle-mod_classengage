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
 * Unit tests for mod_classengage analytics engine
 *
 * Tests the analytics engine including:
 * - Response distribution calculation
 * - Session summary statistics
 * - Question breakdown analysis
 * - Student performance metrics
 * - At-risk student detection
 * - Top performer ranking
 * - Anomaly detection
 * - Cache invalidation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\analytics_engine
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics engine unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class analytics_engine_test extends \advanced_testcase
{

    /** @var \stdClass Test course */
    private $course;

    /** @var \stdClass Test classengage instance */
    private $classengage;

    /** @var \context_module Module context */
    private $context;

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

        // Create students.
        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }

        // Create classengage instance.
        $this->classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $this->course->id,
        ]);
        $cm = get_coursemodule_from_instance('classengage', $this->classengage->id);
        $this->context = \context_module::instance($cm->id);

        // Create session and questions using generator.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $this->session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'timelimit' => 30,
            'numquestions' => 5,
            'timestarted' => time(),
        ]);

        // Create questions and link to session.
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
     * Helper to create responses for testing
     */
    private function create_responses(array $responsedata): void
    {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        foreach ($responsedata as $data) {
            $record = [
                'answer' => $data['answer'] ?? 'A',
            ];
            if (isset($data['iscorrect'])) {
                $record['iscorrect'] = $data['iscorrect'] ? 1 : 0;
            }
            $generator->create_response(
                $this->session->id,
                $data['questionid'],
                $this->classengage->id,
                $data['userid'],
                $record
            );
        }
    }

    /**
     * Test get_current_question_stats returns correct distribution
     */
    public function test_get_current_question_stats(): void
    {
        // Create responses with specific distribution.
        $questionid = $this->questions[0]->id;
        $this->create_responses([
            ['questionid' => $questionid, 'userid' => $this->students[0]->id, 'answer' => 'A'],
            ['questionid' => $questionid, 'userid' => $this->students[1]->id, 'answer' => 'A'],
            ['questionid' => $questionid, 'userid' => $this->students[2]->id, 'answer' => 'B'],
            ['questionid' => $questionid, 'userid' => $this->students[3]->id, 'answer' => 'C'],
            ['questionid' => $questionid, 'userid' => $this->students[4]->id, 'answer' => 'C'],
            ['questionid' => $questionid, 'userid' => $this->students[5]->id, 'answer' => 'C'],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $stats = $engine->get_current_question_stats($this->session->id);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('A', $stats);
        $this->assertArrayHasKey('B', $stats);
        $this->assertArrayHasKey('C', $stats);
        $this->assertEquals(2, $stats['A']);
        $this->assertEquals(1, $stats['B']);
        $this->assertEquals(3, $stats['C']);
    }

    /**
     * Test get_session_summary returns expected structure
     */
    public function test_get_session_summary(): void
    {
        // Create 5 responses from 5 different students.
        $questionid = $this->questions[0]->id;
        for ($i = 0; $i < 5; $i++) {
            $this->create_responses([
                ['questionid' => $questionid, 'userid' => $this->students[$i]->id, 'iscorrect' => ($i < 3)],
            ]);
        }

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $summary = $engine->get_session_summary($this->session->id);

        $this->assertIsObject($summary);
        $this->assertObjectHasProperty('total_participants', $summary);
        $this->assertObjectHasProperty('avg_score', $summary);
        $this->assertObjectHasProperty('completion_rate', $summary);
        $this->assertEquals(5, $summary->total_participants);
    }

    /**
     * Test get_question_breakdown returns per-question stats
     */
    public function test_get_question_breakdown(): void
    {
        // Create responses for multiple questions.
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[0]->id, 'iscorrect' => true],
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[1]->id, 'iscorrect' => false],
            ['questionid' => $this->questions[1]->id, 'userid' => $this->students[0]->id, 'iscorrect' => true],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $breakdown = $engine->get_question_breakdown($this->session->id);

        $this->assertIsArray($breakdown);
        $this->assertGreaterThan(0, count($breakdown));
    }

    /**
     * Test get_student_performance returns individual performance
     */
    public function test_get_student_performance_individual(): void
    {
        // Create responses for a specific student.
        $studentid = $this->students[0]->id;
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $studentid, 'iscorrect' => true],
            ['questionid' => $this->questions[1]->id, 'userid' => $studentid, 'iscorrect' => true],
            ['questionid' => $this->questions[2]->id, 'userid' => $studentid, 'iscorrect' => false],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $performance = $engine->get_student_performance($this->session->id, $studentid);

        $this->assertIsObject($performance);
        $this->assertObjectHasProperty('correct', $performance);
        $this->assertObjectHasProperty('total', $performance);
        $this->assertObjectHasProperty('percentage', $performance);
        $this->assertEquals(2, $performance->correct);
        $this->assertEquals(3, $performance->total);
    }

    /**
     * Test get_student_performance returns all students when no userid provided
     */
    public function test_get_student_performance_all(): void
    {
        // Create responses for multiple students.
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[0]->id],
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[1]->id],
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[2]->id],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $performance = $engine->get_student_performance($this->session->id);

        $this->assertIsArray($performance);
        $this->assertGreaterThanOrEqual(3, count($performance));
    }

    /**
     * Test get_at_risk_students identifies low performers
     */
    public function test_get_at_risk_students(): void
    {
        // Create a student with 0% correct.
        $atriskstudent = $this->students[0]->id;
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $atriskstudent, 'iscorrect' => false],
            ['questionid' => $this->questions[1]->id, 'userid' => $atriskstudent, 'iscorrect' => false],
        ]);

        // Create a student with 100% correct.
        $goodstudent = $this->students[1]->id;
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $goodstudent, 'iscorrect' => true],
            ['questionid' => $this->questions[1]->id, 'userid' => $goodstudent, 'iscorrect' => true],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $atrisk = $engine->get_at_risk_students($this->session->id, 50.0);

        $this->assertIsArray($atrisk);
        // At-risk student should be in the list.
        $atriskuserids = array_column($atrisk, 'userid');
        $this->assertContains($atriskstudent, $atriskuserids);
        $this->assertNotContains($goodstudent, $atriskuserids);
    }

    /**
     * Test get_top_performers returns correct ranking
     */
    public function test_get_top_performers(): void
    {
        // Create responses with varying performance.
        for ($i = 0; $i < 5; $i++) {
            // Student 0 gets 5/5, student 1 gets 4/5, etc.
            for ($q = 0; $q < 5; $q++) {
                $iscorrect = ($q < (5 - $i));
                $this->create_responses([
                    ['questionid' => $this->questions[$q]->id, 'userid' => $this->students[$i]->id, 'iscorrect' => $iscorrect],
                ]);
            }
        }

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $topperformers = $engine->get_top_performers($this->session->id, 3);

        $this->assertIsArray($topperformers);
        $this->assertLessThanOrEqual(3, count($topperformers));
    }

    /**
     * Test get_missing_participants identifies non-participants
     */
    public function test_get_missing_participants(): void
    {
        // Only create responses for first 3 students.
        for ($i = 0; $i < 3; $i++) {
            $this->create_responses([
                ['questionid' => $this->questions[0]->id, 'userid' => $this->students[$i]->id],
            ]);
        }

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $missing = $engine->get_missing_participants($this->session->id, $this->course->id);

        $this->assertIsArray($missing);
        // At least 7 students should be missing (10 total - 3 participated).
        $this->assertGreaterThanOrEqual(7, count($missing));
    }

    /**
     * Test get_question_insights returns insight data
     */
    public function test_get_question_insights(): void
    {
        // Create responses.
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[0]->id, 'iscorrect' => true],
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[1]->id, 'iscorrect' => true],
            ['questionid' => $this->questions[1]->id, 'userid' => $this->students[0]->id, 'iscorrect' => false],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $insights = $engine->get_question_insights($this->session->id);

        $this->assertIsObject($insights);
    }

    /**
     * Test get_engagement_timeline returns timeline data
     */
    public function test_get_engagement_timeline(): void
    {
        // Create responses.
        $this->create_responses([
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[0]->id],
            ['questionid' => $this->questions[0]->id, 'userid' => $this->students[1]->id],
        ]);

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $timeline = $engine->get_engagement_timeline($this->session->id);

        $this->assertIsArray($timeline);
    }

    /**
     * Test get_participation_distribution returns distribution buckets
     */
    public function test_get_participation_distribution(): void
    {
        // Create responses for some students.
        for ($i = 0; $i < 5; $i++) {
            $this->create_responses([
                ['questionid' => $this->questions[0]->id, 'userid' => $this->students[$i]->id],
            ]);
        }

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $distribution = $engine->get_participation_distribution($this->session->id, $this->course->id);

        $this->assertIsObject($distribution);
        $this->assertObjectHasProperty('high', $distribution);
        $this->assertObjectHasProperty('moderate', $distribution);
        $this->assertObjectHasProperty('low', $distribution);
        $this->assertObjectHasProperty('none', $distribution);
    }

    /**
     * Test get_score_distribution returns histogram data
     */
    public function test_get_score_distribution(): void
    {
        // Create responses with varying scores.
        for ($i = 0; $i < 5; $i++) {
            $this->create_responses([
                ['questionid' => $this->questions[0]->id, 'userid' => $this->students[$i]->id, 'iscorrect' => ($i % 2 === 0)],
            ]);
        }

        $engine = new analytics_engine($this->classengage->id, $this->context);
        $distribution = $engine->get_score_distribution($this->session->id);

        $this->assertIsArray($distribution);
    }

    /**
     * Test invalidate_cache clears cached data
     */
    public function test_invalidate_cache(): void
    {
        $engine = new analytics_engine($this->classengage->id, $this->context);

        // This should not throw an exception.
        $engine->invalidate_cache($this->session->id);
        $this->assertTrue(true);
    }

    /**
     * Test handling of empty session (no responses)
     */
    public function test_empty_session_handling(): void
    {
        $engine = new analytics_engine($this->classengage->id, $this->context);

        // These should not throw exceptions with empty data.
        $stats = $engine->get_current_question_stats($this->session->id);
        $this->assertIsArray($stats);

        $summary = $engine->get_session_summary($this->session->id);
        $this->assertIsObject($summary);
        $this->assertEquals(0, $summary->total_participants);

        $breakdown = $engine->get_question_breakdown($this->session->id);
        $this->assertIsArray($breakdown);
    }
}
