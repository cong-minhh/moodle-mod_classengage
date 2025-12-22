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
 * Unit tests for mod_classengage export manager
 *
 * Tests the export manager including:
 * - Session summary data export
 * - Student participation data export
 * - Question analysis data export
 * - Raw response data export
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\export_manager
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Export manager unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class export_manager_test extends \advanced_testcase
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

        $this->course = $this->getDataGenerator()->create_course();
        $this->instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->instructor->id, $this->course->id, 'editingteacher');

        for ($i = 0; $i < 5; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }

        $this->classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $this->course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $this->session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'completed',
            'timelimit' => 30,
            'numquestions' => 3,
            'timestarted' => time() - 600,
            'timecompleted' => time(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $question = $generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $this->questions[] = $question;
            $generator->link_questions_to_session($this->session->id, [$question->id]);
        }

        // Create responses.
        for ($i = 0; $i < 5; $i++) {
            foreach ($this->questions as $question) {
                $iscorrect = ($i % 2 === 0);
                $generator->create_response(
                    $this->session->id,
                    $question->id,
                    $this->classengage->id,
                    $this->students[$i]->id,
                    ['answer' => $iscorrect ? 'A' : 'B', 'iscorrect' => $iscorrect ? 1 : 0]
                );
            }
        }
    }

    /**
     * Test get_session_summary_data returns expected structure
     */
    public function test_get_session_summary_data(): void
    {
        $exporter = new export_manager($this->session->id, $this->course->id);
        $data = $exporter->get_session_summary_data();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('session_name', $data);
        $this->assertArrayHasKey('start_time', $data);
        $this->assertArrayHasKey('total_participants', $data);
        $this->assertArrayHasKey('total_questions', $data);
        $this->assertArrayHasKey('average_score', $data);
    }

    /**
     * Test get_student_participation_data returns expected structure
     */
    public function test_get_student_participation_data(): void
    {
        $exporter = new export_manager($this->session->id, $this->course->id);
        $data = $exporter->get_student_participation_data();

        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));

        // Check first row structure.
        $firstrow = reset($data);
        $this->assertArrayHasKey('student_name', $firstrow);
        $this->assertArrayHasKey('questions_answered', $firstrow);
        $this->assertArrayHasKey('correct_answers', $firstrow);
        $this->assertArrayHasKey('score_percentage', $firstrow);
    }

    /**
     * Test get_question_analysis_data returns expected structure
     */
    public function test_get_question_analysis_data(): void
    {
        $exporter = new export_manager($this->session->id, $this->course->id);
        $data = $exporter->get_question_analysis_data();

        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));

        // Check first row structure.
        $firstrow = reset($data);
        $this->assertArrayHasKey('question_number', $firstrow);
        $this->assertArrayHasKey('question_text', $firstrow);
        $this->assertArrayHasKey('correct_answer', $firstrow);
        $this->assertArrayHasKey('response_count', $firstrow);
        $this->assertArrayHasKey('correct_percentage', $firstrow);
    }

    /**
     * Test get_raw_response_data returns expected structure
     */
    public function test_get_raw_response_data(): void
    {
        $exporter = new export_manager($this->session->id, $this->course->id);
        $data = $exporter->get_raw_response_data();

        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));

        // Check first row structure.
        $firstrow = reset($data);
        $this->assertArrayHasKey('student_name', $firstrow);
        $this->assertArrayHasKey('question_number', $firstrow);
        $this->assertArrayHasKey('answer', $firstrow);
        $this->assertArrayHasKey('is_correct', $firstrow);
        $this->assertArrayHasKey('response_time', $firstrow);
    }

    /**
     * Test empty session export handling
     */
    public function test_empty_session_export(): void
    {
        // Create a new session with no responses.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $emptysession = $generator->create_session($this->classengage->id, $this->instructor->id);

        $exporter = new export_manager($emptysession->id, $this->course->id);

        $summary = $exporter->get_session_summary_data();
        $this->assertIsArray($summary);
        $this->assertEquals(0, $summary['total_participants']);

        $participation = $exporter->get_student_participation_data();
        $this->assertIsArray($participation);

        $questions = $exporter->get_question_analysis_data();
        $this->assertIsArray($questions);

        $raw = $exporter->get_raw_response_data();
        $this->assertIsArray($raw);
    }

    /**
     * Test data format compliance
     */
    public function test_data_format_compliance(): void
    {
        $exporter = new export_manager($this->session->id, $this->course->id);

        // Summary should have numeric values.
        $summary = $exporter->get_session_summary_data();
        $this->assertIsNumeric($summary['total_participants']);
        $this->assertIsNumeric($summary['total_questions']);

        // Participation should have percentage as numeric.
        $participation = $exporter->get_student_participation_data();
        if (count($participation) > 0) {
            $this->assertIsNumeric($participation[0]['score_percentage']);
        }
    }
}
