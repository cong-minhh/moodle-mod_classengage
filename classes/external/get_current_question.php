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
 * External function to get current question
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
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
 * Get current question external function
 */
class get_current_question extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'sessionid' => new external_value(PARAM_INT, 'Session ID'),
            )
        );
    }

    /**
     * Get current question for a session
     *
     * @param int $sessionid Session ID
     * @return array Question information
     */
    public static function execute($sessionid) {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'sessionid' => $sessionid,
        ));

        // Get session
        $session = $DB->get_record('classengage_sessions', array('id' => $params['sessionid']), '*', MUST_EXIST);
        $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context
        self::validate_context($context);

        // Check capability
        require_capability('mod/classengage:view', $context);

        if ($session->status !== 'active') {
            return array(
                'hasquestion' => false,
                'questionid' => 0,
                'questiontext' => '',
                'questionnumber' => 0,
                'timeremaining' => 0,
            );
        }

        // Get current question
        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";

        $question = $DB->get_record_sql($sql, array(
            'sessionid' => $params['sessionid'],
            'questionorder' => $session->currentquestion + 1
        ));

        if (!$question) {
            return array(
                'hasquestion' => false,
                'questionid' => 0,
                'questiontext' => '',
                'questionnumber' => 0,
                'timeremaining' => 0,
            );
        }

        // Calculate time remaining
        $elapsed = time() - $session->questionstarttime;
        $remaining = max(0, $session->timelimit - $elapsed);

        return array(
            'hasquestion' => true,
            'questionid' => $question->id,
            'questiontext' => $question->questiontext,
            'questionnumber' => $session->currentquestion + 1,
            'totalquestions' => $session->numquestions,
            'optiona' => $question->optiona ?? '',
            'optionb' => $question->optionb ?? '',
            'optionc' => $question->optionc ?? '',
            'optiond' => $question->optiond ?? '',
            'timelimit' => $session->timelimit,
            'timeremaining' => $remaining,
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
                'hasquestion' => new external_value(PARAM_BOOL, 'Whether there is a current question'),
                'questionid' => new external_value(PARAM_INT, 'Question ID'),
                'questiontext' => new external_value(PARAM_TEXT, 'Question text'),
                'questionnumber' => new external_value(PARAM_INT, 'Question number'),
                'totalquestions' => new external_value(PARAM_INT, 'Total questions', VALUE_OPTIONAL),
                'optiona' => new external_value(PARAM_TEXT, 'Option A text', VALUE_OPTIONAL),
                'optionb' => new external_value(PARAM_TEXT, 'Option B text', VALUE_OPTIONAL),
                'optionc' => new external_value(PARAM_TEXT, 'Option C text', VALUE_OPTIONAL),
                'optiond' => new external_value(PARAM_TEXT, 'Option D text', VALUE_OPTIONAL),
                'timelimit' => new external_value(PARAM_INT, 'Time limit in seconds', VALUE_OPTIONAL),
                'timeremaining' => new external_value(PARAM_INT, 'Time remaining in seconds'),
            )
        );
    }
}

