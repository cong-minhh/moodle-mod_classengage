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
 * Web service definitions for ClassEngage
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = array(

    // Submit clicker response from hardware device
    'mod_classengage_submit_clicker_response' => array(
        'classname'     => 'mod_classengage\external\submit_clicker_response',
        'methodname'    => 'execute',
        'classpath'     => '',
        'description'   => 'Submit a student response from a classroom clicker device',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/classengage:takequiz',
        'services'      => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),

    // Submit bulk clicker responses (multiple students at once)
    'mod_classengage_submit_bulk_responses' => array(
        'classname'     => 'mod_classengage\external\submit_bulk_responses',
        'methodname'    => 'execute',
        'classpath'     => '',
        'description'   => 'Submit multiple student responses from classroom clicker hub',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/classengage:submitclicker',
        'services'      => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),

    // Get active session information
    'mod_classengage_get_active_session' => array(
        'classname'     => 'mod_classengage\external\get_active_session',
        'methodname'    => 'execute',
        'classpath'     => '',
        'description'   => 'Get currently active quiz session for a ClassEngage activity',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'mod/classengage:view',
        'services'      => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),

    // Get current question
    'mod_classengage_get_current_question' => array(
        'classname'     => 'mod_classengage\external\get_current_question',
        'methodname'    => 'execute',
        'classpath'     => '',
        'description'   => 'Get the current question for an active session',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'mod/classengage:view',
        'services'      => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),

    // Register clicker device
    'mod_classengage_register_clicker' => array(
        'classname'     => 'mod_classengage\external\register_clicker',
        'methodname'    => 'execute',
        'classpath'     => '',
        'description'   => 'Register/map a clicker device ID to a Moodle user',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'mod/classengage:takequiz',
        'services'      => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),

);

// Define the ClassEngage clicker service
$services = array(
    'ClassEngage Clicker Service' => array(
        'functions' => array(
            'mod_classengage_submit_clicker_response',
            'mod_classengage_submit_bulk_responses',
            'mod_classengage_get_active_session',
            'mod_classengage_get_current_question',
            'mod_classengage_register_clicker',
        ),
        'requiredcapability' => '',
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'classengage_clicker',
        'downloadfiles' => 0,
        'uploadfiles' => 0
    ),
);

