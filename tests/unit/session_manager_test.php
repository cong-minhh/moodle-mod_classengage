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
 * Unit tests for mod_classengage session manager
 *
 * Tests the session manager including:
 * - Session creation
 * - Session lifecycle (start, stop)
 * - Question advancement
 * - Session deletion
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\session_manager
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Session manager unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class session_manager_test extends \advanced_testcase
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
        $cm = get_coursemodule_from_instance('classengage', $this->classengage->id);
        $this->context = \context_module::instance($cm->id);

        // Create questions.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        for ($i = 0; $i < 5; $i++) {
            $question = $generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $this->questions[] = $question;
        }
    }

    /**
     * Test session start
     */
    public function test_start_session(): void
    {
        global $DB;

        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'ready',
        ]);

        // Link questions.
        $generator->link_questions_to_session($session->id, array_column($this->questions, 'id'));

        $manager->start_session($session->id);

        $updated = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('active', $updated->status);
        $this->assertNotNull($updated->timestarted);
    }

    /**
     * Test session stop
     */
    public function test_stop_session(): void
    {
        global $DB;

        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'timestarted' => time(),
        ]);

        $manager->stop_session($session->id);

        $updated = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('completed', $updated->status);
        $this->assertNotNull($updated->timecompleted);
    }

    /**
     * Test next question advancement
     */
    public function test_next_question(): void
    {
        global $DB;

        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'currentquestion' => 0,
            'timestarted' => time(),
        ]);

        // Link questions.
        $generator->link_questions_to_session($session->id, array_column($this->questions, 'id'));

        $manager->next_question($session->id);

        $updated = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals(1, $updated->currentquestion);
    }

    /**
     * Test get current question
     */
    public function test_get_current_question(): void
    {
        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'currentquestion' => 0,
            'timestarted' => time(),
        ]);

        // Link questions.
        $generator->link_questions_to_session($session->id, array_column($this->questions, 'id'));

        $question = $manager->get_current_question($session->id);

        $this->assertNotNull($question);
        $this->assertIsObject($question);
        $this->assertObjectHasProperty('id', $question);
        $this->assertObjectHasProperty('questiontext', $question);
    }

    /**
     * Test single session deletion
     */
    public function test_delete_session(): void
    {
        global $DB;

        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($this->classengage->id, $this->instructor->id);

        $result = $manager->delete_session($session->id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('classengage_sessions', ['id' => $session->id]));
    }

    /**
     * Test batch session deletion
     */
    public function test_delete_sessions(): void
    {
        global $DB;

        $manager = new session_manager($this->classengage->id, $this->context);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

        $sessionids = [];
        for ($i = 0; $i < 3; $i++) {
            $session = $generator->create_session($this->classengage->id, $this->instructor->id);
            $sessionids[] = $session->id;
        }

        $result = $manager->delete_sessions($sessionids);

        $this->assertTrue($result);
        foreach ($sessionids as $sessionid) {
            $this->assertFalse($DB->record_exists('classengage_sessions', ['id' => $sessionid]));
        }
    }
}
