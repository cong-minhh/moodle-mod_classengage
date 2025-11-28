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
 * Instructor control panel for managing live quiz sessions with real-time updates
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

use mod_classengage\session_manager;
use mod_classengage\analytics_engine;
use mod_classengage\control_panel_actions;
use mod_classengage\output\control_panel_renderer;
use mod_classengage\constants;

// ============================================================================
// PARAMETER VALIDATION AND SETUP
// ============================================================================

$id = required_param('id', PARAM_INT); // Course module ID.
$sessionid = required_param('sessionid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Validate database records.
$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

// Verify session belongs to this activity.
if ($session->classengageid != $classengage->id) {
    throw new moodle_exception('invalidsession', 'mod_classengage');
}

// Security checks.
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:startquiz', $context);

// Page setup.
$PAGE->set_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// ============================================================================
// INITIALIZE COMPONENTS
// ============================================================================

$sessionmanager = new session_manager($classengage->id, $context);
$analyticsengine = new analytics_engine($classengage->id, $context);
$actionhandler = new control_panel_actions($sessionmanager, $context);
$renderer = new control_panel_renderer();

// ============================================================================
// HANDLE ACTIONS
// ============================================================================

if (!empty($action)) {
    try {
        $actionhandler->execute($action, $sessionid);
        
        // Redirect based on action.
        if ($action === constants::ACTION_STOP) {
            redirect(new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id)),
                get_string('sessionstopped', 'mod_classengage'),
                null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($PAGE->url);
        }
    } catch (Exception $e) {
        // Display error and continue to page.
        \core\notification::error($e->getMessage());
    }
}

// ============================================================================
// PAGE RESOURCES AND INITIALIZATION
// ============================================================================

// Get polling interval from settings.
$pollinginterval = get_config('mod_classengage', 'pollinginterval');
if (empty($pollinginterval)) {
    $pollinginterval = constants::DEFAULT_POLLING_INTERVAL;
}

// Initialize real-time updates with AMD module.
$PAGE->requires->js_call_amd('mod_classengage/controlpanel', 'init', array($sessionid, $pollinginterval));

// Load Chart.js only if session is active (performance optimization).
if ($session->status === constants::SESSION_STATUS_ACTIVE) {
    // TODO: Bundle Chart.js locally instead of using CDN for better performance and CSP compliance.
    $PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'), true);
}

// Add custom CSS for better styling.
$PAGE->requires->css('/mod/classengage/styles.css');

// ============================================================================
// OUTPUT STARTS HERE
// ============================================================================

echo $OUTPUT->header();

// Page heading.
echo $OUTPUT->heading(format_string($classengage->name));

// Standard tab navigation.
classengage_render_tabs($cm->id);

// Control panel subheading.
$subheading = format_string($session->name) . ' - ' . get_string('controlpanel', 'mod_classengage');
echo html_writer::tag('h4', $subheading, array('class' => 'mb-4 text-primary font-weight-bold'));

// Start of Main Control Panel Container
echo html_writer::start_div('mod-classengage-controlpanel');

// ============================================================================
// SESSION STATUS CARDS
// Displays real-time session metrics: question progress, status, participants
// ============================================================================

// Get participant count from analytics engine (uses caching).
$sessionstats = $analyticsengine->get_session_summary($sessionid);
$participantcount = isset($sessionstats->total_participants) ? $sessionstats->total_participants : 0;

// Render status cards using renderer.
echo $renderer->render_status_cards($session, $participantcount);

// ============================================================================
// ACTIVE SESSION DISPLAY
// Shows current question, live response statistics, and control buttons
// ============================================================================

if ($session->status === constants::SESSION_STATUS_ACTIVE) {
    try {
        $currentq = $sessionmanager->get_current_question($sessionid);
        
        if ($currentq) {
            // Display current question text.
            echo $renderer->render_question_display($currentq);
            
            // Display response distribution (table and chart).
            echo $renderer->render_response_distribution($currentq);
            
            // Display overall response rate progress bar.
            echo $renderer->render_response_rate_progress();
            
            // Display control buttons.
            echo $renderer->render_control_buttons($session, $cm->id, $sessionid);
        } else {
            echo $OUTPUT->notification(
                get_string('error:noquestionfound', 'mod_classengage'),
                \core\output\notification::NOTIFY_ERROR
            );
        }
    } catch (Exception $e) {
        echo $OUTPUT->notification(
            get_string('error:cannotloadquestion', 'mod_classengage') . ': ' . $e->getMessage(),
            \core\output\notification::NOTIFY_ERROR
        );
    }
} else {
    // Session is not active - show appropriate message.
    $statusmessage = get_string('sessionnotactive', 'mod_classengage');
    if ($session->status === constants::SESSION_STATUS_COMPLETED) {
        $statusmessage = get_string('sessioncompleted', 'mod_classengage');
    } else if ($session->status === constants::SESSION_STATUS_PAUSED) {
        $statusmessage = get_string('sessionpaused', 'mod_classengage');
    }
    
    echo html_writer::div($statusmessage, 'alert alert-warning');
    
    // Provide link back to sessions page.
    $sessionsurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id));
    echo html_writer::div(
        html_writer::link($sessionsurl, get_string('backtosessions', 'mod_classengage'), 
            array('class' => 'btn btn-secondary')),
        'text-center mt-3'
    );
}

// End of Main Control Panel Container
echo html_writer::end_div();

echo $OUTPUT->footer();

