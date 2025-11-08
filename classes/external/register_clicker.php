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
 * External function to register a clicker device
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
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Register clicker device external function
 */
class register_clicker extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'User ID to associate with clicker'),
                'clickerid' => new external_value(PARAM_TEXT, 'Clicker device ID'),
            )
        );
    }

    /**
     * Register a clicker device to a user
     *
     * @param int $userid User ID
     * @param string $clickerid Clicker device ID
     * @return array Result
     */
    public static function execute($userid, $clickerid) {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'userid' => $userid,
            'clickerid' => $clickerid,
        ));

        // Validate context
        $context = context_system::instance();
        self::validate_context($context);

        // Check if user exists
        $user = $DB->get_record('user', array('id' => $params['userid']), '*', MUST_EXIST);

        // Check if user is trying to register their own clicker or has permission
        if ($USER->id != $params['userid']) {
            require_capability('moodle/site:config', $context);
        }

        // Check if clicker is already registered to another user
        $existing = $DB->get_record('classengage_clicker_devices', 
            array('clickerid' => $params['clickerid']));

        if ($existing && $existing->userid != $params['userid']) {
            return array(
                'success' => false,
                'message' => 'Clicker device is already registered to another user',
            );
        }

        if ($existing) {
            // Update existing registration
            $existing->lastused = time();
            $DB->update_record('classengage_clicker_devices', $existing);

            return array(
                'success' => true,
                'message' => 'Clicker device already registered',
            );
        }

        // Create new registration
        $record = new \stdClass();
        $record->userid = $params['userid'];
        $record->clickerid = $params['clickerid'];
        $record->contextid = $context->id;
        $record->timecreated = time();
        $record->lastused = time();

        $DB->insert_record('classengage_clicker_devices', $record);

        return array(
            'success' => true,
            'message' => 'Clicker device registered successfully',
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
                'success' => new external_value(PARAM_BOOL, 'Whether registration was successful'),
                'message' => new external_value(PARAM_TEXT, 'Result message'),
            )
        );
    }
}

