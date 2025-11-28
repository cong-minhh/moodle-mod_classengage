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
 * Library of interface functions and constants for module classengage
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the features supported by this module
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function classengage_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_GROUPS:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the classengage into the database
 *
 * @param stdClass $classengage An object from the form in mod_form.php
 * @param mod_classengage_mod_form $mform The form instance
 * @return int The id of the newly inserted classengage record
 */
function classengage_add_instance(stdClass $classengage, mod_classengage_mod_form $mform = null) {
    global $DB;

    $classengage->timecreated = time();
    $classengage->timemodified = time();

    $classengage->id = $DB->insert_record('classengage', $classengage);

    // Create grade item
    classengage_grade_item_update($classengage);

    return $classengage->id;
}

/**
 * Updates an instance of the classengage in the database
 *
 * @param stdClass $classengage An object from the form in mod_form.php
 * @param mod_classengage_mod_form $mform The form instance
 * @return boolean Success/Fail
 */
function classengage_update_instance(stdClass $classengage, mod_classengage_mod_form $mform = null) {
    global $DB;

    $classengage->timemodified = time();
    $classengage->id = $classengage->instance;

    $result = $DB->update_record('classengage', $classengage);

    // Update grade item
    classengage_grade_item_update($classengage);

    return $result;
}

/**
 * Removes an instance of the classengage from the database
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function classengage_delete_instance($id) {
    global $DB;

    if (!$classengage = $DB->get_record('classengage', array('id' => $id))) {
        return false;
    }

    // Delete all dependent records
    $DB->delete_records('classengage_slides', array('classengageid' => $id));
    $DB->delete_records('classengage_questions', array('classengageid' => $id));
    
    // Get all sessions for this activity
    $sessions = $DB->get_records('classengage_sessions', array('classengageid' => $id));
    foreach ($sessions as $session) {
        $DB->delete_records('classengage_responses', array('sessionid' => $session->id));
    }
    $DB->delete_records('classengage_sessions', array('classengageid' => $id));

    // Delete the main record
    $DB->delete_records('classengage', array('id' => $id));

    // Delete grade item
    classengage_grade_item_delete($classengage);

    return true;
}

/**
 * Create/update grade item for given classengage
 *
 * @param stdClass $classengage object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function classengage_grade_item_update($classengage, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = array('itemname' => $classengage->name);
    
    if (isset($classengage->grade) && $classengage->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $classengage->grade;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/classengage', $classengage->course, 'mod', 'classengage',
                       $classengage->id, 0, $grades, $params);
}

/**
 * Delete grade item for given classengage
 *
 * @param stdClass $classengage object
 * @return int 0 if ok, error code otherwise
 */
function classengage_grade_item_delete($classengage) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/classengage', $classengage->course, 'mod', 'classengage',
                       $classengage->id, 0, null, array('deleted' => 1));
}

/**
 * Update grades in gradebook
 *
 * @param stdClass $classengage object
 * @param int $userid specific user only, 0 means all
 * @param bool $nullifnone return null if grade does not exist
 */
function classengage_update_grades($classengage, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($grades = classengage_get_user_grades($classengage, $userid)) {
        classengage_grade_item_update($classengage, $grades);
    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        classengage_grade_item_update($classengage, $grade);
    } else {
        classengage_grade_item_update($classengage);
    }
}

/**
 * Return grade for given user or all users
 *
 * @param stdClass $classengage object
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function classengage_get_user_grades($classengage, $userid = 0) {
    global $DB;

    $params = array('classengageid' => $classengage->id);
    $usersql = '';
    if ($userid) {
        $params['userid'] = $userid;
        $usersql = ' AND userid = :userid';
    }

    $sql = "SELECT userid, MAX(score) as rawgrade
              FROM {classengage_responses}
             WHERE classengageid = :classengageid $usersql
          GROUP BY userid";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function classengage_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function classengage_reset_userdata($data) {
    return array();
}

/**
 * Render standard ClassEngage tab navigation
 *
 * This function generates consistent tab navigation across all ClassEngage pages.
 * Tabs include: Upload Slides, Manage Questions, Quiz Sessions, and Analytics.
 *
 * @param int $cmid Course module ID
 * @param string|null $activetab Active tab identifier ('slides', 'questions', 'sessions', 'analytics') or null for none
 * @return void Outputs HTML directly
 */
function classengage_render_tabs($cmid, $activetab = null) {
    $tabs = array();
    $tabs[] = new tabobject(
        'slides',
        new moodle_url('/mod/classengage/slides.php', array('id' => $cmid)),
        get_string('uploadslides', 'mod_classengage')
    );
    $tabs[] = new tabobject(
        'questions',
        new moodle_url('/mod/classengage/questions.php', array('id' => $cmid)),
        get_string('managequestions', 'mod_classengage')
    );
    $tabs[] = new tabobject(
        'sessions',
        new moodle_url('/mod/classengage/sessions.php', array('id' => $cmid)),
        get_string('managesessions', 'mod_classengage')
    );
    $tabs[] = new tabobject(
        'analytics',
        new moodle_url('/mod/classengage/analytics.php', array('id' => $cmid)),
        get_string('analytics', 'mod_classengage')
    );

    print_tabs(array($tabs), $activetab);
}

