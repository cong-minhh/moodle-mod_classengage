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
 * Behat step definitions for mod_classengage
 *
 * @package    mod_classengage
 * @category   test
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * ClassEngage Behat step definitions
 */
class behat_mod_classengage extends behat_base {

    /**
     * Creates a question for the current ClassEngage activity.
     *
     * @Given /^I create a classengage question with:$/
     * @param TableNode $data Question data
     */
    public function i_create_a_classengage_question_with(TableNode $data) {
        global $DB;

        $questiondata = $data->getRowsHash();

        // Get the current classengage instance from the page URL.
        $url = $this->getSession()->getCurrentUrl();
        preg_match('/id=(\d+)/', $url, $matches);

        if (empty($matches[1])) {
            throw new ExpectationException(
                'Could not determine ClassEngage instance from URL',
                $this->getSession()
            );
        }

        $cmid = $matches[1];
        $cm = get_coursemodule_from_id('classengage', $cmid, 0, false, MUST_EXIST);
        $classengageid = $cm->instance;

        // Create the question.
        $question = new stdClass();
        $question->classengageid = $classengageid;
        $question->questiontext = $questiondata['questiontext'] ?? 'Test question';
        $question->questiontype = $questiondata['questiontype'] ?? 'multichoice';
        $question->optiona = $questiondata['optiona'] ?? 'Option A';
        $question->optionb = $questiondata['optionb'] ?? 'Option B';
        $question->optionc = $questiondata['optionc'] ?? 'Option C';
        $question->optiond = $questiondata['optiond'] ?? 'Option D';
        $question->correctanswer = $questiondata['correctanswer'] ?? 'A';
        $question->difficulty = $questiondata['difficulty'] ?? 'medium';
        $question->status = 'approved';
        $question->source = 'manual';
        $question->timecreated = time();
        $question->timemodified = time();

        $DB->insert_record('classengage_questions', $question);
    }

    /**
     * Creates a session for the current ClassEngage activity.
     *
     * @Given /^I create a classengage session with:$/
     * @param TableNode $data Session data
     */
    public function i_create_a_classengage_session_with(TableNode $data) {
        global $DB, $USER;

        $sessiondata = $data->getRowsHash();

        // Get the current classengage instance from the page URL.
        $url = $this->getSession()->getCurrentUrl();
        preg_match('/id=(\d+)/', $url, $matches);

        if (empty($matches[1])) {
            throw new ExpectationException(
                'Could not determine ClassEngage instance from URL',
                $this->getSession()
            );
        }

        $cmid = $matches[1];
        $cm = get_coursemodule_from_id('classengage', $cmid, 0, false, MUST_EXIST);
        $classengageid = $cm->instance;

        // Get current user ID.
        $userid = $DB->get_field('user', 'id', ['username' => 'teacher1']);

        // Create the session.
        $session = new stdClass();
        $session->classengageid = $classengageid;
        $session->name = $sessiondata['name'] ?? 'Test Session';
        $session->numquestions = $sessiondata['numquestions'] ?? 5;
        $session->timelimit = $sessiondata['timelimit'] ?? 30;
        $session->shufflequestions = $sessiondata['shufflequestions'] ?? 1;
        $session->shuffleanswers = $sessiondata['shuffleanswers'] ?? 1;
        $session->status = 'ready';
        $session->currentquestion = 0;
        $session->createdby = $userid;
        $session->timecreated = time();
        $session->timemodified = time();

        $sessionid = $DB->insert_record('classengage_sessions', $session);

        // Link questions to session.
        $questions = $DB->get_records('classengage_questions', [
            'classengageid' => $classengageid,
            'status' => 'approved'
        ], 'id ASC', '*', 0, $session->numquestions);

        $order = 1;
        foreach ($questions as $question) {
            $sq = new stdClass();
            $sq->sessionid = $sessionid;
            $sq->questionid = $question->id;
            $sq->questionorder = $order++;
            $DB->insert_record('classengage_session_questions', $sq);
        }
    }

    /**
     * Starts a ClassEngage session by name.
     *
     * @Given /^I start the classengage session "([^"]*)"$/
     * @param string $sessionname Session name
     */
    public function i_start_the_classengage_session($sessionname) {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['name' => $sessionname], '*', MUST_EXIST);

        // Update session to active status.
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->timestarted = time();
        $session->questionstarttime = time();
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);
    }

    /**
     * Pauses a ClassEngage session by name.
     *
     * @Given /^I pause the classengage session "([^"]*)"$/
     * @param string $sessionname Session name
     */
    public function i_pause_the_classengage_session($sessionname) {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['name' => $sessionname], '*', MUST_EXIST);

        // Calculate remaining time.
        $elapsed = time() - $session->questionstarttime;
        $remaining = max(0, $session->timelimit - $elapsed);

        // Update session to paused status.
        $session->status = 'paused';
        $session->paused_at = time();
        $session->timer_remaining = $remaining;
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);
    }

    /**
     * Resumes a paused ClassEngage session by name.
     *
     * @Given /^I resume the classengage session "([^"]*)"$/
     * @param string $sessionname Session name
     */
    public function i_resume_the_classengage_session($sessionname) {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['name' => $sessionname], '*', MUST_EXIST);

        // Calculate pause duration.
        $pauseduration = time() - $session->paused_at;

        // Update session to active status.
        $session->status = 'active';
        $session->pause_duration = ($session->pause_duration ?? 0) + $pauseduration;
        $session->questionstarttime = time() - ($session->timelimit - $session->timer_remaining);
        $session->paused_at = null;
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);
    }

    /**
     * Advances to the next question in a ClassEngage session.
     *
     * @Given /^I advance to the next question in session "([^"]*)"$/
     * @param string $sessionname Session name
     */
    public function i_advance_to_next_question_in_session($sessionname) {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['name' => $sessionname], '*', MUST_EXIST);

        // Advance to next question.
        $session->currentquestion++;
        $session->questionstarttime = time();
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);
    }

    /**
     * Waits for the quiz question to load via AJAX.
     *
     * @Given /^I wait for the quiz question to load$/
     */
    public function i_wait_for_the_quiz_question_to_load() {
        // Wait for the page to be ready first.
        $this->execute('behat_general::wait_until_the_page_is_ready');

        // Wait for the question container to appear (with timeout).
        $this->spin(
            function($context) {
                $page = $context->getSession()->getPage();
                return $page->find('css', '.quiz-question') !== null ||
                       $page->find('css', '.quiz-container') !== null ||
                       $page->find('css', '#quiz-content') !== null;
            },
            [],
            10,
            null,
            true
        );

        // Additional wait for AJAX to complete.
        $this->getSession()->wait(1000);
    }

    /**
     * Checks that a button is disabled.
     *
     * @Then /^the "([^"]*)" "([^"]*)" should be disabled$/
     * @param string $element Element text
     * @param string $selectortype Selector type
     */
    public function the_element_should_be_disabled($element, $selectortype) {
        $node = $this->find($selectortype, $element);

        if (!$node->hasAttribute('disabled') && !$node->hasClass('disabled')) {
            throw new ExpectationException(
                "The element '$element' is not disabled",
                $this->getSession()
            );
        }
    }

    /**
     * Checks that a student is shown as connected in the control panel.
     *
     * @Then /^I should see "([^"]*)" in the connected students list$/
     * @param string $studentname Student name
     */
    public function i_should_see_student_in_connected_list($studentname) {
        $this->execute('behat_general::assert_element_contains_text', [
            $studentname,
            '#connected-students-list',
            'css_element'
        ]);
    }

    /**
     * Checks that a student has a specific status in the control panel.
     *
     * @Then /^"([^"]*)" should have status "([^"]*)" in the control panel$/
     * @param string $studentname Student name
     * @param string $status Expected status
     */
    public function student_should_have_status($studentname, $status) {
        $xpath = "//div[@id='connected-students-list']//div[contains(., '$studentname')]//span[contains(@class, 'status-$status')]";

        try {
            $this->find('xpath', $xpath);
        } catch (Exception $e) {
            throw new ExpectationException(
                "Student '$studentname' does not have status '$status'",
                $this->getSession()
            );
        }
    }

    /**
     * Checks that aggregate statistics show specific values.
     *
     * @Then /^the session statistics should show "([^"]*)" connected$/
     * @param string $count Expected count
     */
    public function session_statistics_should_show_connected($count) {
        $this->execute('behat_general::assert_element_contains_text', [
            $count,
            '#stat-connected',
            'css_element'
        ]);
    }

    /**
     * Simulates a student joining an active session.
     *
     * @Given /^student "([^"]*)" joins the active session$/
     * @param string $username Student username
     */
    public function student_joins_active_session($username) {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        // Find active session.
        $session = $DB->get_record('classengage_sessions', ['status' => 'active'], '*', MUST_EXIST);

        // Register connection.
        $connection = new stdClass();
        $connection->sessionid = $session->id;
        $connection->userid = $user->id;
        $connection->connectionid = uniqid('behat_' . $user->id . '_', true);
        $connection->transport = 'polling';
        $connection->status = 'connected';
        $connection->last_heartbeat = time();
        $connection->current_question_answered = 0;
        $connection->timecreated = time();
        $connection->timemodified = time();

        $DB->insert_record('classengage_connections', $connection);
    }

    /**
     * Simulates a student disconnecting from a session.
     *
     * @Given /^student "([^"]*)" disconnects from the session$/
     * @param string $username Student username
     */
    public function student_disconnects_from_session($username) {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        // Update connection status.
        $DB->set_field('classengage_connections', 'status', 'disconnected', ['userid' => $user->id]);
    }

    /**
     * Waits for the control panel to update.
     *
     * @Given /^I wait for the control panel to update$/
     */
    public function i_wait_for_control_panel_to_update() {
        // Wait for the page to be ready.
        $this->execute('behat_general::wait_until_the_page_is_ready');

        // Additional wait for AJAX polling to complete.
        $this->getSession()->wait(3000);
    }

    /**
     * Navigates directly to the control panel for a session.
     *
     * @When /^I navigate to the control panel for session "([^"]*)"$/
     * @param string $sessionname Session name
     */
    public function i_navigate_to_control_panel_for_session($sessionname) {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['name' => $sessionname], '*', MUST_EXIST);
        $classengage = $DB->get_record('classengage', ['id' => $session->classengageid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);

        $url = new \moodle_url('/mod/classengage/controlpanel.php', [
            'id' => $cm->id,
            'sessionid' => $session->id,
        ]);

        $this->getSession()->visit($url->out(false));
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Checks that text exists somewhere in the page.
     *
     * @Then /^I should see "([^"]*)" in the page$/
     * @param string $text Text to find
     */
    public function i_should_see_text_in_page($text) {
        $this->execute('behat_general::assert_page_contains_text', [$text]);
    }
}
