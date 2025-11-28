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
 * External function to get active session information
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Get active session external function
 */
class get_active_session extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'classengageid' => new external_value(PARAM_INT, 'ClassEngage activity instance ID'),
            )
        );
    }

    /**
     * Get active session for a ClassEngage activity
     *
     * @param int $classengageid ClassEngage activity ID
     * @return array Session information
     */
    public static function execute($classengageid) {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'classengageid' => $classengageid,
        ));

        // Get ClassEngage activity
        $classengage = $DB->get_record('classengage', array('id' => $params['classengageid']), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context
        self::validate_context($context);

        // Check capability
        require_capability('mod/classengage:view', $context);

        // Get active session
        $session = $DB->get_record('classengage_sessions', array(
            'classengageid' => $params['classengageid'],
            'status' => 'active'
        ), '*', IGNORE_MISSING);

        if (!$session) {
            return array(
                'hassession' => false,
                'sessionid' => 0,
                'sessionname' => '',
                'status' => 'none',
                'currentquestion' => 0,
                'totalquestions' => 0,
            );
        }

        return array(
            'hassession' => true,
            'sessionid' => $session->id,
            'sessionname' => $session->name,
            'status' => $session->status,
            'currentquestion' => $session->currentquestion,
            'totalquestions' => $session->numquestions,
            'timelimit' => $session->timelimit,
            'shuffleanswers' => $session->shuffleanswers,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure(
            array(
                'hassession' => new external_value(PARAM_BOOL, 'Whether there is an active session'),
                'sessionid' => new external_value(PARAM_INT, 'Session ID'),
                'sessionname' => new external_value(PARAM_TEXT, 'Session name'),
                'status' => new external_value(PARAM_TEXT, 'Session status'),
                'currentquestion' => new external_value(PARAM_INT, 'Current question number'),
                'totalquestions' => new external_value(PARAM_INT, 'Total number of questions'),
                'timelimit' => new external_value(PARAM_INT, 'Time limit per question', VALUE_OPTIONAL),
                'shuffleanswers' => new external_value(PARAM_BOOL, 'Whether answers are shuffled', VALUE_OPTIONAL),
            )
        );
    }
}

