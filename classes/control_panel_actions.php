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
 * Control panel action handler
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles control panel actions for quiz sessions
 */
class control_panel_actions {
    
    /** @var session_manager Session manager instance */
    private $sessionmanager;
    
    /** @var \context_module Module context */
    private $context;
    
    /**
     * Constructor
     *
     * @param session_manager $sessionmanager Session manager instance
     * @param \context_module $context Module context
     */
    public function __construct($sessionmanager, $context) {
        $this->sessionmanager = $sessionmanager;
        $this->context = $context;
    }
    
    /**
     * Validate and execute an action
     *
     * @param string $action Action to perform
     * @param int $sessionid Session ID
     * @return bool True if action was executed
     * @throws \moodle_exception If action is invalid or sesskey is invalid
     */
    public function execute($action, $sessionid) {
        // Validate sesskey.
        if (!confirm_sesskey()) {
            throw new \moodle_exception('invalidsesskey');
        }
        
        // Validate action.
        if (!in_array($action, constants::VALID_ACTIONS)) {
            throw new \moodle_exception('invalidaction', 'mod_classengage', '', $action);
        }
        
        // Execute action.
        switch ($action) {
            case constants::ACTION_NEXT:
                return $this->handle_next_question($sessionid);
                
            case constants::ACTION_STOP:
                return $this->handle_stop_session($sessionid);
                
            case constants::ACTION_PAUSE:
                return $this->handle_pause_session($sessionid);
                
            case constants::ACTION_RESUME:
                return $this->handle_resume_session($sessionid);
                
            default:
                return false;
        }
    }
    
    /**
     * Handle next question action
     *
     * Uses session_state_manager to properly reset current_question_answered
     * for all connections when advancing to the next question.
     *
     * @param int $sessionid Session ID
     * @return bool True on success
     * @throws \required_capability_exception If user lacks permission
     */
    private function handle_next_question($sessionid) {
        require_capability('mod/classengage:startquiz', $this->context);
        // Use session_state_manager to ensure current_question_answered is reset
        // This is required for accurate connected/answered/pending statistics.
        $statemanager = new session_state_manager();
        $statemanager->next_question($sessionid);
        return true;
    }
    
    /**
     * Handle stop session action
     *
     * @param int $sessionid Session ID
     * @return bool True on success
     * @throws \required_capability_exception If user lacks permission
     */
    private function handle_stop_session($sessionid) {
        require_capability('mod/classengage:startquiz', $this->context);
        $this->sessionmanager->stop_session($sessionid);
        return true;
    }
    
    /**
     * Handle pause session action
     *
     * @param int $sessionid Session ID
     * @return bool True on success
     * @throws \required_capability_exception If user lacks permission
     */
    private function handle_pause_session($sessionid) {
        require_capability('mod/classengage:startquiz', $this->context);
        // TODO: Implement pause functionality in session_manager.
        throw new \moodle_exception('notimplemented', 'mod_classengage');
    }
    
    /**
     * Handle resume session action
     *
     * @param int $sessionid Session ID
     * @return bool True on success
     * @throws \required_capability_exception If user lacks permission
     */
    private function handle_resume_session($sessionid) {
        require_capability('mod/classengage:startquiz', $this->context);
        // TODO: Implement resume functionality in session_manager.
        throw new \moodle_exception('notimplemented', 'mod_classengage');
    }
}
