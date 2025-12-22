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
 * Integration tests for mod_classengage data export flow
 *
 * Tests the complete export pipeline including:
 * - Full export flow with all data types
 * - Export with large datasets
 * - Data integrity validation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\export_manager
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Export integration tests
 *
 * @group mod_classengage
 * @group mod_classengage_integration
 */
class export_integration_test extends \advanced_testcase
{

    /**
     * Test full export flow with all data types
     */
    public function test_full_export_flow(): void
    {
        $this->resetAfterTest(true);

        // Set up complete test scenario.
        $course = $this->getDataGenerator()->create_course();
        $instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($instructor->id, $course->id, 'editingteacher');

        $students = [];
        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $instructor->id, [
            'status' => 'completed',
            'timestarted' => time() - 600,
            'timecompleted' => time(),
        ]);

        // Create questions and responses.
        $questions = [];
        for ($i = 0; $i < 5; $i++) {
            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $questions[] = $question;
            $generator->link_questions_to_session($session->id, [$question->id]);
        }

        // Create responses for all students.
        foreach ($students as $index => $student) {
            foreach ($questions as $qindex => $question) {
                $iscorrect = (($index + $qindex) % 2 === 0);
                $generator->create_response(
                    $session->id,
                    $question->id,
                    $classengage->id,
                    $student->id,
                    $iscorrect ? 'A' : 'B',
                    $iscorrect
                );
            }
        }

        // Test export.
        $exporter = new export_manager($session->id, $course->id);

        // Session summary.
        $summary = $exporter->get_session_summary_data();
        $this->assertIsArray($summary);
        $this->assertEquals(10, $summary['total_participants']);
        $this->assertEquals(5, $summary['total_questions']);

        // Student participation.
        $participation = $exporter->get_student_participation_data();
        $this->assertIsArray($participation);
        $this->assertCount(10, $participation);

        // Question analysis.
        $questions = $exporter->get_question_analysis_data();
        $this->assertIsArray($questions);
        $this->assertCount(5, $questions);

        // Raw responses.
        $raw = $exporter->get_raw_response_data();
        $this->assertIsArray($raw);
        $this->assertCount(50, $raw); // 10 students x 5 questions.
    }

    /**
     * Test export with large dataset (500+ responses)
     */
    public function test_export_with_large_dataset(): void
    {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($instructor->id, $course->id, 'editingteacher');

        // Create 50 students.
        $students = [];
        for ($i = 0; $i < 50; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $instructor->id, [
            'status' => 'completed',
        ]);

        // Create 10 questions.
        $questions = [];
        for ($i = 0; $i < 10; $i++) {
            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $questions[] = $question;
            $generator->link_questions_to_session($session->id, [$question->id]);
        }

        // Create responses (50 students x 10 questions = 500 responses).
        foreach ($students as $student) {
            foreach ($questions as $qindex => $question) {
                $generator->create_response(
                    $session->id,
                    $question->id,
                    $classengage->id,
                    $student->id,
                    'A',
                    true
                );
            }
        }

        // Measure export performance.
        $starttime = microtime(true);

        $exporter = new export_manager($session->id, $course->id);
        $raw = $exporter->get_raw_response_data();

        $endtime = microtime(true);
        $duration = $endtime - $starttime;

        $this->assertCount(500, $raw);
        // Export should complete within 5 seconds.
        $this->assertLessThan(5.0, $duration, "Export took too long: {$duration}s");
    }

    /**
     * Test data integrity validation
     */
    public function test_export_data_integrity(): void
    {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($instructor->id, $course->id, 'editingteacher');

        $student = $this->getDataGenerator()->create_user(['firstname' => 'Test', 'lastname' => 'Student']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $instructor->id, [
            'name' => 'Integrity Test Session',
            'status' => 'completed',
        ]);

        $question = $generator->create_question($classengage->id, [
            'questiontext' => 'Test Question Text',
            'questiontype' => 'multichoice',
            'correctanswer' => 'B',
        ]);
        $generator->link_questions_to_session($session->id, [$question->id]);

        // Create a specific response.
        $generator->create_response(
            $session->id,
            $question->id,
            $classengage->id,
            $student->id,
            'B',
            true
        );

        $exporter = new export_manager($session->id, $course->id);

        // Verify data integrity.
        $summary = $exporter->get_session_summary_data();
        $this->assertEquals('Integrity Test Session', $summary['session_name']);
        $this->assertEquals(1, $summary['total_participants']);

        $participation = $exporter->get_student_participation_data();
        $this->assertStringContainsString('Test', $participation[0]['student_name']);
        $this->assertEquals(1, $participation[0]['correct_answers']);
        $this->assertEquals(100, $participation[0]['score_percentage']);

        $questiondata = $exporter->get_question_analysis_data();
        $this->assertEquals('B', $questiondata[0]['correct_answer']);
        $this->assertEquals(100, $questiondata[0]['correct_percentage']);

        $raw = $exporter->get_raw_response_data();
        $this->assertEquals('B', $raw[0]['answer']);
        $this->assertTrue($raw[0]['is_correct'] || $raw[0]['is_correct'] === 1 || $raw[0]['is_correct'] === '1');
    }
}
