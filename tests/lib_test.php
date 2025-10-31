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
 * Unit tests for mod_classengage lib
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/classengage/lib.php');

/**
 * Unit tests for lib.php
 */
class lib_test extends \advanced_testcase {

    /**
     * Test classengage_supports function
     */
    public function test_classengage_supports() {
        $this->assertTrue(classengage_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(classengage_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(classengage_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(classengage_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(classengage_supports(FEATURE_GROUPINGS));
    }

    /**
     * Test adding a classengage instance
     */
    public function test_classengage_add_instance() {
        global $DB;

        $this->resetAfterTest(true);

        // Create course
        $course = $this->getDataGenerator()->create_course();

        // Create classengage instance
        $classengage = new \stdClass();
        $classengage->course = $course->id;
        $classengage->name = 'Test ClassEngage';
        $classengage->intro = 'Test introduction';
        $classengage->introformat = FORMAT_HTML;
        $classengage->grade = 100;

        $id = classengage_add_instance($classengage, null);

        $this->assertNotEmpty($id);

        $record = $DB->get_record('classengage', array('id' => $id));
        $this->assertNotEmpty($record);
        $this->assertEquals('Test ClassEngage', $record->name);
        $this->assertEquals(100, $record->grade);
    }

    /**
     * Test updating a classengage instance
     */
    public function test_classengage_update_instance() {
        global $DB;

        $this->resetAfterTest(true);

        // Create course and module
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', array('course' => $course->id));

        // Update instance
        $classengage->name = 'Updated Name';
        $classengage->grade = 50;
        $classengage->instance = $classengage->id;

        $result = classengage_update_instance($classengage, null);

        $this->assertTrue($result);

        $record = $DB->get_record('classengage', array('id' => $classengage->id));
        $this->assertEquals('Updated Name', $record->name);
        $this->assertEquals(50, $record->grade);
    }

    /**
     * Test deleting a classengage instance
     */
    public function test_classengage_delete_instance() {
        global $DB;

        $this->resetAfterTest(true);

        // Create course and module
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', array('course' => $course->id));

        $result = classengage_delete_instance($classengage->id);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('classengage', array('id' => $classengage->id)));
    }

    /**
     * Test grade item update
     */
    public function test_classengage_grade_item_update() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', array(
            'course' => $course->id,
            'grade' => 100
        ));

        $result = classengage_grade_item_update($classengage);
        $this->assertEquals(GRADE_UPDATE_OK, $result);
    }
}

