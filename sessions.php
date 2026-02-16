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
 * Quiz session management page - Clean Professional Design
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/create_session_form.php');
require_once(__DIR__ . '/classes/session_manager.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:startquiz', $context);

$PAGE->set_url('/mod/classengage/sessions.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$sessionmanager = new \mod_classengage\session_manager($classengage->id, $context);

// Handle actions
if ($action === 'start' && $sessionid && confirm_sesskey()) {
    $statemanager = new \mod_classengage\session_state_manager();
    $statemanager->start_session($sessionid);

    $event = \mod_classengage\event\session_started::create(array(
        'objectid' => $sessionid,
        'context' => $context,
        'other' => array('classengageid' => $classengage->id)
    ));
    $event->trigger();

    redirect($PAGE->url, get_string('sessionstarted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'stop' && $sessionid && confirm_sesskey()) {
    $sessionmanager->stop_session($sessionid);

    $event = \mod_classengage\event\session_stopped::create(array(
        'objectid' => $sessionid,
        'context' => $context,
        'other' => array('classengageid' => $classengage->id)
    ));
    $event->trigger();

    redirect($PAGE->url, get_string('sessionstopped', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $sessionid && confirm_sesskey()) {
    if (!$confirm) {
        $continueurl = new moodle_url($PAGE->url, array('action' => 'delete', 'sessionid' => $sessionid, 'confirm' => 1, 'sesskey' => sesskey()));
        $cancelurl = $PAGE->url;
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('deleteconfirm', 'mod_classengage'), $continueurl, $cancelurl);
        echo $OUTPUT->footer();
        exit;
    }

    $sessionmanager->delete_session($sessionid);
    redirect($PAGE->url, get_string('sessiondeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'nextquestion' && $sessionid && confirm_sesskey()) {
    $statemanager = new \mod_classengage\session_state_manager();
    $statemanager->next_question($sessionid);
    redirect(new moodle_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $sessionid)));
}

// Handle Bulk Actions
if ($data = data_submitted() && confirm_sesskey()) {
    $bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
    $selectedsessions = optional_param_array('sessionids', array(), PARAM_INT);

    if (!empty($selectedsessions) && !empty($bulkaction)) {
        if ($bulkaction === 'delete') {
            $sessionmanager->delete_sessions($selectedsessions);
            redirect($PAGE->url, get_string('sessionsdeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($bulkaction === 'stop') {
            foreach ($selectedsessions as $sid) {
                $sessionmanager->stop_session($sid);
            }
            redirect($PAGE->url, get_string('sessionsstopped', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

// Handle modal form submission
$formerror = '';
if (optional_param('createsession', 0, PARAM_INT) && confirm_sesskey()) {
    $name = optional_param('name', '', PARAM_TEXT);
    $numquestions = optional_param('numquestions', 0, PARAM_INT);
    $timelimit = optional_param('timelimit', 0, PARAM_INT);
    $shufflequestions = optional_param('shufflequestions', 0, PARAM_INT);
    $shuffleanswers = optional_param('shuffleanswers', 0, PARAM_INT);
    
    // Validate
    $approvedcount = $DB->count_records('classengage_questions', 
        array('classengageid' => $classengage->id, 'status' => 'approved'));
    
    if (empty($name)) {
        $formerror = get_string('required');
    } elseif ($numquestions < 1) {
        $formerror = get_string('minimumquestions', 'mod_classengage');
    } elseif ($numquestions > $approvedcount) {
        $formerror = get_string('notenoughquestions', 'mod_classengage', $approvedcount);
    } elseif ($timelimit < 5) {
        $formerror = get_string('minimumtimelimit', 'mod_classengage');
    } else {
        // Create session
        $sessiondata = (object)[
            'name' => $name,
            'numquestions' => $numquestions,
            'timelimit' => $timelimit,
            'shufflequestions' => $shufflequestions,
            'shuffleanswers' => $shuffleanswers,
        ];
        $sessionid = $sessionmanager->create_session($sessiondata, $USER->id);
        
        if ($sessionid) {
            redirect($PAGE->url, get_string('sessioncreated', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

// Get default values
$defaultnum = get_config('mod_classengage', 'defaultquestions') ?: 5;
$defaulttime = get_config('mod_classengage', 'defaulttimelimit') ?: 30;
$approvedcount = $DB->count_records('classengage_questions', 
    array('classengageid' => $classengage->id, 'status' => 'approved'));

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

// Clean Professional CSS
echo html_writer::tag('style', '
    /* Page Layout */
    .sessions-page {
        max-width: 1400px;
    }
    
    /* Create Button */
    .create-session-btn {
        margin-bottom: 24px;
    }
    
    .create-session-btn .btn {
        padding: 12px 24px;
        font-size: 1rem;
        font-weight: 500;
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
    
    .create-session-btn .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    /* Section Cards */
    .session-section {
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        margin-bottom: 20px;
        overflow: hidden;
    }
    
    .session-section-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8f9fa;
    }
    
    .session-section-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        color: #495057;
    }
    
    .session-count-badge {
        background: #dee2e6;
        color: #495057;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    
    /* Table Styling */
    .sessions-table {
        margin: 0;
    }
    
    .sessions-table thead th {
        background: #fff;
        border-bottom: 1px solid #dee2e6;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px;
        color: #6c757d;
    }
    
    .sessions-table tbody tr {
        transition: background-color 0.15s ease;
    }
    
    .sessions-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .sessions-table tbody td {
        padding: 12px;
        vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
        font-size: 0.875rem;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .status-active {
        background: #d4edda;
        color: #155724;
    }
    
    .status-paused {
        background: #fff3cd;
        color: #856404;
    }
    
    /* Action Buttons */
    .action-btn {
        padding: 4px 10px;
        font-size: 0.8125rem;
        border-radius: 4px;
        margin: 0 2px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    /* Session Name */
    .session-name {
        font-weight: 500;
        color: #212529;
    }
    
    /* Flex gap utility */
    .gap-2 {
        gap: 8px;
    }
    
    /* Stats */
    .stat-value {
        color: #495057;
        font-weight: 500;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #adb5bd;
    }
    
    .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 12px;
    }
    
    /* Bulk Actions */
    .bulk-actions {
        padding: 12px 20px;
        border-top: 1px solid #e9ecef;
        background: #f8f9fa;
    }
    
    /* Modal Styling */
    .modal-header {
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
    }
    
    .modal-title {
        font-size: 1rem;
        font-weight: 600;
        color: #495057;
    }
    
    /* Form in modal */
    .modal-form .form-group {
        margin-bottom: 16px;
    }
    
    .modal-form label {
        font-weight: 500;
        font-size: 0.875rem;
        color: #495057;
        margin-bottom: 6px;
    }
    
    .modal-form .form-control {
        border-radius: 4px;
        border: 1px solid #ced4da;
    }
    
    .modal-form .form-text {
        font-size: 0.75rem;
        color: #6c757d;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .action-btn span {
            display: none;
        }
    }
');

// Tab navigation
$tabs = array();
$tabs[] = new tabobject(
    'slides',
    new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id)),
    get_string('uploadslides', 'mod_classengage')
);
$tabs[] = new tabobject(
    'questions',
    new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id)),
    get_string('managequestions', 'mod_classengage')
);
$tabs[] = new tabobject(
    'sessions',
    new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id)),
    get_string('managesessions', 'mod_classengage')
);
$tabs[] = new tabobject(
    'analytics',
    new moodle_url('/mod/classengage/analytics.php', array('id' => $cm->id)),
    get_string('analytics', 'mod_classengage')
);

print_tabs(array($tabs), 'sessions');

// Create Session Button
echo html_writer::start_div('create-session-btn');
echo '<button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createSessionModal">';
echo '<i class="fa fa-plus mr-2"></i>' . get_string('createnewsession', 'mod_classengage');
echo '</button>';
echo html_writer::end_div();

// Create Session Modal
if (!empty($formerror)) {
    echo '<div class="alert alert-danger">' . $formerror . '</div>';
}

echo '<div class="modal fade" id="createSessionModal" tabindex="-1" role="dialog" aria-labelledby="createSessionModalLabel" aria-hidden="true">';
echo '<div class="modal-dialog" role="document">';
echo '<div class="modal-content">';
echo '<form method="post" action="' . $PAGE->url . '" class="modal-form">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="createsession" value="1">';

echo '<div class="modal-header">';
echo '<h5 class="modal-title" id="createSessionModalLabel"><i class="fa fa-plus-circle mr-2"></i>' . get_string('createnewsession', 'mod_classengage') . '</h5>';
echo '<button type="button" class="close" data-dismiss="modal" aria-label="Close">';
echo '<span aria-hidden="true">&times;</span>';
echo '</button>';
echo '</div>';

echo '<div class="modal-body">';

// Session name
echo '<div class="form-group">';
echo '<label for="session-name">' . get_string('sessiontitle', 'mod_classengage') . ' <span class="text-danger">*</span></label>';
echo '<input type="text" class="form-control" id="session-name" name="name" required placeholder="Enter session name">';
echo '</div>';

// Number of questions
echo '<div class="form-group">';
echo '<label for="session-questions">' . get_string('numberofquestions', 'mod_classengage') . ' <span class="text-danger">*</span></label>';
echo '<input type="number" class="form-control" id="session-questions" name="numquestions" value="' . $defaultnum . '" min="1" max="' . $approvedcount . '" required>';
echo '<small class="form-text text-muted">Maximum: ' . $approvedcount . ' approved questions available</small>';
echo '</div>';

// Time limit
echo '<div class="form-group">';
echo '<label for="session-timelimit">' . get_string('timelimit', 'mod_classengage') . ' <span class="text-danger">*</span></label>';
echo '<div class="input-group">';
echo '<input type="number" class="form-control" id="session-timelimit" name="timelimit" value="' . $defaulttime . '" min="5" required>';
echo '<div class="input-group-append"><span class="input-group-text">seconds</span></div>';
echo '</div>';
echo '</div>';

// Shuffle options
echo '<div class="form-group">';
echo '<div class="custom-control custom-checkbox">';
echo '<input type="checkbox" class="custom-control-input" id="shuffle-questions" name="shufflequestions" value="1" checked>';
echo '<label class="custom-control-label" for="shuffle-questions">' . get_string('shufflequestions', 'mod_classengage') . '</label>';
echo '</div>';
echo '</div>';

echo '<div class="form-group">';
echo '<div class="custom-control custom-checkbox">';
echo '<input type="checkbox" class="custom-control-input" id="shuffle-answers" name="shuffleanswers" value="1" checked>';
echo '<label class="custom-control-label" for="shuffle-answers">' . get_string('shuffleanswers', 'mod_classengage') . '</label>';
echo '</div>';
echo '</div>';

echo '</div>'; // modal-body

echo '<div class="modal-footer">';
echo '<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>';
echo '<button type="submit" class="btn btn-primary">' . get_string('createsession', 'mod_classengage') . '</button>';
echo '</div>';

echo '</form>';
echo '</div></div></div>';

// Helper function to render session table
function render_session_table($sessions, $cm, $type) {
    global $DB;

    if (!$sessions) {
        $icon = $type === 'active' ? 'fa-play-circle' : ($type === 'completed' ? 'fa-check-circle' : 'fa-clock-o');
        $message = get_string('no' . $type . 'sessions', 'mod_classengage');
        return '<div class="empty-state"><i class="fa ' . $icon . '"></i><p>' . $message . '</p></div>';
    }

    $formurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id));
    $o = html_writer::start_tag('form', array('action' => $formurl, 'method' => 'post'));
    $o .= html_writer::input_hidden_params($formurl);
    $o .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

    $o .= '<div class="table-responsive">';
    $o .= '<table class="table sessions-table">';
    $o .= '<thead><tr>';
    $o .= '<th style="width: 40px;"><input type="checkbox" class="custom-checkbox select-all-toggle" data-target="session-checkbox-' . $type . '" title="Select all"></th>';
    $o .= '<th>' . get_string('sessionname', 'mod_classengage') . '</th>';
    $o .= '<th style="width: 100px;">' . get_string('numberofquestions', 'mod_classengage') . '</th>';
    
    if ($type === 'active' || $type === 'completed') {
        $o .= '<th style="width: 100px;">' . get_string('participants', 'mod_classengage') . '</th>';
    }
    
    if ($type === 'completed') {
        $o .= '<th style="width: 140px;">' . get_string('completeddate', 'mod_classengage') . '</th>';
    }
    
    $o .= '<th style="width: 180px;">' . get_string('actions', 'mod_classengage') . '</th>';
    $o .= '</tr></thead>';
    $o .= '<tbody>';

    foreach ($sessions as $session) {
        $o .= '<tr>';
        $o .= '<td><input type="checkbox" name="sessionids[]" value="' . $session->id . '" class="custom-checkbox session-checkbox-' . $type . '"></td>';
        
        // Session name with status badge inline
        $o .= '<td>';
        $o .= '<div class="d-flex align-items-center flex-wrap gap-2">';
        $o .= '<span class="session-name">' . format_string($session->name) . '</span>';
        
        if ($type === 'active') {
            $statusclass = $session->status === 'paused' ? 'status-paused' : 'status-active';
            $statustext = $session->status === 'paused' ? 'Paused' : 'Active';
            $statusicon = $session->status === 'paused' ? 'fa-pause' : 'fa-play';
            $o .= '<span class="status-badge ' . $statusclass . '">';
            $o .= '<i class="fa ' . $statusicon . '"></i> ' . $statustext;
            $o .= '</span>';
        }
        
        $o .= '</div></td>';
        
        // Questions count
        $o .= '<td><span class="stat-value">' . $session->numquestions . '</span></td>';
        
        // Participants
        if ($type === 'active' || $type === 'completed') {
            $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
            $participantcount = $DB->count_records_sql($sql, array($session->id));
            $o .= '<td><span class="stat-value">' . $participantcount . '</span></td>';
        }
        
        // Completed date
        if ($type === 'completed') {
            $o .= '<td><small>' . date('d/m/Y H:i', $session->timecompleted) . '</small></td>';
        }
        
        // Actions
        $o .= '<td>';
        
        if ($type === 'active') {
            $controlurl = new moodle_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $session->id));
            $o .= '<a href="' . $controlurl . '" class="btn btn-primary action-btn"><i class="fa fa-dashboard"></i> <span>Control</span></a>';
            
            $stopurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'stop', 'sessionid' => $session->id, 'sesskey' => sesskey()));
            $o .= '<a href="' . $stopurl . '" class="btn btn-warning action-btn"><i class="fa fa-stop"></i> <span>Stop</span></a>';
        } else if ($type === 'ready') {
            $starturl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'start', 'sessionid' => $session->id, 'sesskey' => sesskey()));
            $o .= '<a href="' . $starturl . '" class="btn btn-success action-btn"><i class="fa fa-play"></i> <span>Start</span></a>';
        } else if ($type === 'completed') {
            $viewurl = new moodle_url('/mod/classengage/sessionresults.php', array('id' => $cm->id, 'sessionid' => $session->id));
            $o .= '<a href="' . $viewurl . '" class="btn btn-info action-btn"><i class="fa fa-chart-bar"></i> <span>Results</span></a>';
        }
        
        $deleteurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'delete', 'sessionid' => $session->id, 'sesskey' => sesskey()));
        $o .= '<a href="' . $deleteurl . '" class="btn btn-outline-danger action-btn" title="' . get_string('delete') . '" onclick="return confirm(\'Delete this session?\');"><i class="fa fa-trash"></i></a>';
        
        $o .= '</td>';
        $o .= '</tr>';
    }

    $o .= '</tbody></table>';
    $o .= '</div>';

    // Bulk actions
    $o .= '<div class="bulk-actions">';
    $o .= '<span class="text-muted mr-2">' . get_string('withselected', 'mod_classengage') . ':</span>';
    
    $bulkoptions = array('delete' => get_string('delete'));
    if ($type === 'active') {
        $bulkoptions['stop'] = get_string('stop', 'mod_classengage');
    }
    
    $o .= html_writer::select($bulkoptions, 'bulkaction', '', array('' => get_string('choose', 'moodle')), array('class' => 'custom-select custom-select-sm', 'style' => 'width: 120px;'));
    $o .= '<button type="submit" class="btn btn-secondary btn-sm ml-2 action-btn">' . get_string('go') . '</button>';
    $o .= '</div>';

    $o .= html_writer::end_tag('form');

    return $o;
}

// Active Sessions
$sql = "SELECT * FROM {classengage_sessions}
         WHERE classengageid = :classengageid
           AND (status = 'active' OR status = 'paused')
       ORDER BY timecreated DESC";
$activesessions = $DB->get_records_sql($sql, array('classengageid' => $classengage->id));

echo html_writer::start_div('session-section');
echo html_writer::start_div('session-section-header');
echo html_writer::tag('h3', '<i class="fa fa-play-circle text-success mr-2"></i> ' . get_string('activesessions', 'mod_classengage'));
echo html_writer::span(count($activesessions), 'session-count-badge');
echo html_writer::end_div();
echo html_writer::div(render_session_table($activesessions, $cm, 'active'), 'session-section-content');
echo html_writer::end_div();

// Ready Sessions
$readysessions = $DB->get_records('classengage_sessions', array('classengageid' => $classengage->id, 'status' => 'ready'), 'timecreated DESC');

echo html_writer::start_div('session-section');
echo html_writer::start_div('session-section-header');
echo html_writer::tag('h3', '<i class="fa fa-clock-o text-secondary mr-2"></i> ' . get_string('readysessions', 'mod_classengage'));
echo html_writer::span(count($readysessions), 'session-count-badge');
echo html_writer::end_div();
echo html_writer::div(render_session_table($readysessions, $cm, 'ready'), 'session-section-content');
echo html_writer::end_div();

// Completed Sessions
$completedsessions = $DB->get_records('classengage_sessions', array('classengageid' => $classengage->id, 'status' => 'completed'), 'timecreated DESC', '*', 0, 20);

echo html_writer::start_div('session-section');
echo html_writer::start_div('session-section-header');
echo html_writer::tag('h3', '<i class="fa fa-check-circle text-info mr-2"></i> ' . get_string('completedsessions', 'mod_classengage'));
echo html_writer::span(count($completedsessions), 'session-count-badge');
echo html_writer::end_div();
echo html_writer::div(render_session_table($completedsessions, $cm, 'completed'), 'session-section-content');
echo html_writer::end_div();

// JavaScript
echo html_writer::script("
document.addEventListener('DOMContentLoaded', function() {
    // Select All functionality
    document.querySelectorAll('.select-all-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var targetClass = this.getAttribute('data-target');
            document.querySelectorAll('.' + targetClass).forEach(function(checkbox) {
                checkbox.checked = toggle.checked;
            });
        });
    });
    
    // Auto-open modal if there's an error
    " . (!empty($formerror) ? "$('#createSessionModal').modal('show');" : "") . "
});
");

echo $OUTPUT->footer();
