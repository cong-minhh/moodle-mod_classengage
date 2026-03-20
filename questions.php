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
 * Question management page
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/edit_question_form.php');

// Prevent caching - critical for showing newly generated questions
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$questionid = optional_param('questionid', 0, PARAM_INT);
$highlight = optional_param('highlight', '', PARAM_ALPHANUMEXT); // e.g., slide_123

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:managequestions', $context);

$PAGE->set_url('/mod/classengage/questions.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Handle single actions
if ($action === 'delete' && $questionid && confirm_sesskey()) {
    $DB->delete_records('classengage_questions', array('id' => $questionid, 'classengageid' => $classengage->id));
    redirect($PAGE->url, get_string('questiondeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'approve' && $questionid && confirm_sesskey()) {
    $DB->set_field('classengage_questions', 'status', 'approved', array('id' => $questionid));
    redirect($PAGE->url, get_string('questionapproved', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle bulk actions
if (($action === 'bulkdelete' || $action === 'bulkapprove') && confirm_sesskey()) {
    $selectedquestions = optional_param_array('q', [], PARAM_INT);

    if (!empty($selectedquestions)) {
        if ($action === 'bulkdelete') {
            list($insql, $inparams) = $DB->get_in_or_equal($selectedquestions);
            $DB->delete_records_select('classengage_questions', "id $insql AND classengageid = ?", array_merge($inparams, [$classengage->id]));
            redirect($PAGE->url, get_string('questionsdeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        } elseif ($action === 'bulkapprove') {
            list($insql, $inparams) = $DB->get_in_or_equal($selectedquestions);
            $DB->set_field_select('classengage_questions', 'status', 'approved', "id $insql AND classengageid = ?", array_merge($inparams, [$classengage->id]));
            redirect($PAGE->url, get_string('questionsapproved', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } else {
        redirect($PAGE->url, get_string('noquestionsselected', 'mod_classengage'), null, \core\output\notification::NOTIFY_WARNING);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($classengage->name));

// Load AMD module for table interactions
$PAGE->requires->js_call_amd('mod_classengage/questions_table', 'init');

// Enhanced CSS for better table QoL
echo html_writer::tag('style', '
    /* Table Container */
    .questions-table-container {
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    
    /* Table Toolbar */
    .questions-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 15px;
        padding: 16px 20px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-bottom: 1px solid #dee2e6;
    }
    
    .search-box {
        position: relative;
        flex: 0 0 280px;
    }
    
    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #adb5bd;
    }
    
    .search-box input {
        padding: 8px 12px 8px 36px;
        border: 1px solid #ced4da;
        border-radius: 20px;
        font-size: 0.875rem;
        width: 100%;
        background: white;
        transition: all 0.2s;
    }
    
    .search-box input:focus {
        outline: none;
        border-color: #4a90a4;
        box-shadow: 0 0 0 3px rgba(74,144,164,0.15);
    }
    
    .stats-info {
        display: flex;
        gap: 20px;
        font-size: 0.875rem;
        color: #495057;
        margin-left: auto;
    }
    
    .stats-info .stat-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .stats-info .stat-item i {
        color: #6c757d;
    }
    
    .filter-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 6px 14px;
        border: none;
        border-radius: 20px;
        background: white;
        font-size: 0.8125rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    }
    
    .filter-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .filter-btn.active {
        color: white;
        box-shadow: none;
    }
    
    .filter-btn.filter-all.active {
        background: #495057;
    }
    
    .filter-btn.filter-trustworthy.active {
        background: linear-gradient(135deg, #2196F3, #1976D2);
    }
    
    .filter-btn.filter-uncertain.active {
        background: linear-gradient(135deg, #FFC107, #FF9800);
    }
    
    .filter-btn.filter-unlikely.active {
        background: linear-gradient(135deg, #F44336, #D32F2F);
    }
    
    /* Table Styles */
    .questions-table {
        margin-bottom: 0;
    }
    
    .questions-table thead th {
        background: white;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
        padding: 14px 12px;
        border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
    }
    
    .questions-table tbody tr {
        transition: background 0.15s;
    }
    
    .questions-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .questions-table tbody td {
        padding: 12px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f3f4;
        font-size: 0.875rem;
    }
    
    .questions-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    /* Sortable Columns */
    .sortable-col {
        cursor: pointer;
        user-select: none;
        position: relative;
    }
    
    .sortable-col:hover {
        color: #4a90a4;
    }
    
    .sortable-col::after {
        content: "";
        display: inline-block;
        width: 0;
        height: 0;
        margin-left: 6px;
        vertical-align: middle;
        border-left: 4px solid transparent;
        border-right: 4px solid transparent;
        border-top: 4px solid #adb5bd;
        transition: transform 0.2s;
    }
    
    .sortable-col.sort-asc::after {
        border-top: none;
        border-bottom: 4px solid #4a90a4;
    }
    
    .sortable-col.sort-desc::after {
        border-bottom: none;
        border-top: 4px solid #4a90a4;
    }
    
    /* Question Text */
    .question-text-cell {
        max-width: 300px;
    }
    
    .question-text-cell a {
        color: #212529;
        text-decoration: none;
    }
    
    .question-text-cell a:hover {
        color: #4a90a4;
    }
    
    /* Badges */
    .badge {
        font-weight: 500;
        padding: 4px 10px;
        border-radius: 12px;
    }
    
    .badge.trustworthiness-trustworthy {
        background: linear-gradient(135deg, #E3F2FD, #BBDEFB) !important;
        color: #1565C0 !important;
        border: 1px solid rgba(33,150,243,0.3);
    }
    
    .badge.trustworthiness-uncertain {
        background: linear-gradient(135deg, #FFF8E1, #FFECB3) !important;
        color: #E65100 !important;
        border: 1px solid rgba(255,193,7,0.3);
    }
    
    .badge.trustworthiness-unlikely {
        background: linear-gradient(135deg, #FFEBEE, #FFCDD2) !important;
        color: #B71C1C !important;
        border: 1px solid rgba(244,67,54,0.3);
    }
    
    /* Difficulty Badges */
    .badge.badge-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
    }
    
    .badge.badge-warning {
        background: linear-gradient(135deg, #fff3cd, #ffeeba);
        color: #856404;
    }
    
    .badge.badge-danger {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
    }
    
    /* Status Badges */
    .status-badge.badge-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
    }
    
    .status-badge.badge-warning {
        background: linear-gradient(135deg, #fff3cd, #ffeeba);
        color: #856404;
    }
    
    /* Checkbox Styling */
    .question-checkbox,
    .select-all-questions {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #4a90a4;
    }
    
    /* Action Buttons */
    .action-btn {
        padding: 6px 10px;
        border-radius: 6px;
        transition: all 0.15s;
    }
    
    .action-btn:hover {
        transform: scale(1.1);
    }
    
    /* No Results Message */
    .no-results-message {
        background: #f8f9fa;
        border-radius: 0 0 8px 8px;
    }
    
    /* Highlight Animation */
    .highlight-new {
        animation: highlight-pulse 2s ease-out;
    }
    
    @keyframes highlight-pulse {
        0% { background-color: rgba(74,144,164,0.2); }
        100% { background-color: transparent; }
    }
    
    /* Row Numbers */
    .row-number {
        color: #adb5bd;
        font-weight: 500;
    }
    
    /* Mobile Responsive */
    @media (max-width: 992px) {
        .questions-toolbar {
            padding: 12px 16px;
        }
        
        .search-box {
            flex: 1 1 100%;
        }
        
        .stats-info {
            width: 100%;
            justify-content: space-between;
            margin-left: 0;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
        }
        
        .filter-buttons {
            width: 100%;
            justify-content: flex-start;
        }
    }
    
    @media (max-width: 768px) {
        .question-text-cell {
            max-width: 200px;
        }
        
        .questions-table thead th {
            padding: 10px 8px;
            font-size: 0.7rem;
        }
        
        .questions-table tbody td {
            padding: 10px 8px;
        }
    }
');

// Highlight CSS for newly generated questions
if ($highlight) {
    echo html_writer::tag('style', '
        .highlight-new {
            border: 2px solid #17a2b8 !important;
            box-shadow: 0 0 8px rgba(23, 162, 184, 0.3);
            animation: highlight-pulse 2s ease-out;
        }
        @keyframes highlight-pulse {
            0% { box-shadow: 0 0 15px rgba(23, 162, 184, 0.6); }
            100% { box-shadow: 0 0 8px rgba(23, 162, 184, 0.3); }
        }
    ');
}

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

print_tabs(array($tabs), 'questions');

// Add question button
$addurl = new moodle_url('/mod/classengage/editquestion.php', array('id' => $cm->id));
echo html_writer::div(
    html_writer::link($addurl, get_string('addquestion', 'mod_classengage'), array('class' => 'btn btn-primary mr-2 mb-3'))
);

// Fetch questions with slide info including NLP metadata
$sql = "SELECT q.*, s.title as slidetitle, s.id as slide_id,
               s.nlp_provider, s.nlp_model, s.nlp_generation_metadata,
               s.nlp_questions_count, s.nlp_job_completed
        FROM {classengage_questions} q 
        LEFT JOIN {classengage_slides} s ON q.slideid = s.id 
        WHERE q.classengageid = ? 
        ORDER BY q.timecreated DESC";
$questions = $DB->get_records_sql($sql, array($classengage->id));

// Debug: Check total count
$totalquestions = $DB->count_records('classengage_questions', ['classengageid' => $classengage->id]);
if ($totalquestions > 0 && count($questions) === 0) {
    debugging("WARNING: Found {$totalquestions} questions in DB but query returned 0. This may indicate a SQL issue.", DEBUG_DEVELOPER);
}

$manual_questions = [];
$generated_questions_by_slide = [];
$slide_metadata = [];

foreach ($questions as $q) {
    if (empty($q->slideid)) {
        $manual_questions[] = $q;
    } else {
        $slidetitle = $q->slidetitle ? $q->slidetitle : get_string('unknownslide', 'mod_classengage');
        $generated_questions_by_slide[$slidetitle][] = $q;

        if (!isset($slide_metadata[$slidetitle])) {
            $slide_metadata[$slidetitle] = [
                'slide_id' => $q->slide_id,
                'provider' => $q->nlp_provider,
                'model' => $q->nlp_model,
                'metadata' => $q->nlp_generation_metadata ? json_decode($q->nlp_generation_metadata, true) : null,
                'count' => $q->nlp_questions_count,
                'generated_at' => $q->nlp_job_completed
            ];
        }
    }
}

// Start Bulk Actions Form (wraps entire content including tables)
echo html_writer::start_tag('form', array('action' => $PAGE->url, 'method' => 'post', 'id' => 'questionsform'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => '', 'id' => 'bulkaction'));

// Enhanced render function
function render_question_table($questions, $cm) {
    global $OUTPUT;

    if (empty($questions)) {
        return html_writer::div(
            html_writer::tag('i', '', array('class' => 'fa fa-inbox fa-3x mb-3')) .
            html_writer::tag('p', get_string('noquestions', 'mod_classengage')),
            'no-results'
        );
    }
    
    // Output modals first
    $modals_html = '';
    foreach ($questions as $question) {
        $correctanswer = strtoupper($question->correctanswer);
        $modalid = 'question-modal-' . $question->id;

        $modalcontent = '<div class="modal fade" id="' . $modalid . '" tabindex="-1" role="dialog" aria-hidden="true">';
        $modalcontent .= '<div class="modal-dialog modal-lg" role="document">';
        $modalcontent .= '<div class="modal-content">';
        $modalcontent .= '<div class="modal-header bg-light">';
        $modalcontent .= '<h5 class="modal-title"><i class="fa fa-question-circle mr-2 text-primary"></i>Question Preview</h5>';
        $modalcontent .= '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>';
        $modalcontent .= '</div>';
        $modalcontent .= '<div class="modal-body">';

        if (!empty($question->question_image)) {
            $modalcontent .= '<div class="text-center mb-3 p-3 bg-light rounded">';
            $modalcontent .= '<img src="' . s($question->question_image) . '" class="img-fluid rounded shadow-sm" style="max-height: 300px;" alt="Question image">';
            $modalcontent .= '</div>';
        }

        $modalcontent .= '<div class="question-content">';
        $modalcontent .= '<div class="mb-3 p-3 bg-light rounded"><strong class="text-primary">Q:</strong> ' . s($question->questiontext) . '</div>';

        // Trustworthiness display in modal
        $trust_level = $question->trustworthiness_level ?? 'uncertain';
        $trust_score = $question->trustworthiness_score ?? 0;
        $trust_analyzed = !empty($question->trustworthiness_analyzed);
        $trust_class = 'trustworthiness-' . $trust_level;
        $trust_icon = '';
        if ($trust_level === 'trustworthy') {
            $trust_icon = 'fa-check-circle';
            $trust_label = 'Pretty good chance not wrong';
        } else if ($trust_level === 'uncertain') {
            $trust_icon = 'fa-exclamation-triangle';
            $trust_label = 'Could be wrong';
        } else {
            $trust_icon = 'fa-times-circle';
            $trust_label = 'Likely be wrong';
        }

        $modalcontent .= '<div class="mb-3">';
        $modalcontent .= '<label class="text-muted small font-weight-bold">Question Reliability:</label>';
        if (!$trust_analyzed) {
            $modalcontent .= '<span class="badge badge-secondary ml-2">Not analyzed</span>';
        } else {
            $modalcontent .= '<div class="p-2 rounded ' . $trust_class . '" style="display: inline-block; margin-left: 8px;">';
            $modalcontent .= '<i class="fa ' . $trust_icon . ' mr-1"></i>';
            $modalcontent .= '<strong>' . ucfirst($trust_level) . '</strong> (' . $trust_score . '%)';
            $modalcontent .= '<br><small>' . $trust_label . '</small>';
            $modalcontent .= '</div>';

            if (!empty($question->trustworthiness_factors)) {
                $factors = json_decode($question->trustworthiness_factors, true);
                $modalcontent .= '<div class="mt-2 small">';
                $modalcontent .= '<strong>Analysis factors:</strong>';
                $modalcontent .= '<ul class="mb-0 pl-3">';
                foreach ($factors as $factor_key => $factor) {
                    $factor_name = ucfirst(str_replace('_', ' ', $factor_key));
                    $factor_score = $factor['score'] ?? 0;
                    $factor_details = $factor['details'] ?? '';
                    $modalcontent .= '<li><strong>' . $factor_name . ':</strong> ' . $factor_score . '%';
                    if ($factor_details) {
                        $modalcontent .= '<br><em class="text-muted">' . s($factor_details) . '</em>';
                    }
                    $modalcontent .= '</li>';
                }
                $modalcontent .= '</ul>';
                $modalcontent .= '</div>';
            }
        }
        $modalcontent .= '</div>';

        $modalcontent .= '<div class="list-group">';
        $modalcontent .= '<div class="list-group-item d-flex align-items-center ' . ($correctanswer === 'A' ? 'list-group-item-success border-success' : '') . '">';
        $modalcontent .= '<span class="badge badge-light mr-3" style="width: 28px;">A</span>';
        $modalcontent .= '<span class="flex-grow-1">' . s($question->optiona) . '</span>';
        if ($correctanswer === 'A') $modalcontent .= '<i class="fa fa-check-circle text-success"></i>';
        $modalcontent .= '</div>';
        $modalcontent .= '<div class="list-group-item d-flex align-items-center ' . ($correctanswer === 'B' ? 'list-group-item-success border-success' : '') . '">';
        $modalcontent .= '<span class="badge badge-light mr-3" style="width: 28px;">B</span>';
        $modalcontent .= '<span class="flex-grow-1">' . s($question->optionb) . '</span>';
        if ($correctanswer === 'B') $modalcontent .= '<i class="fa fa-check-circle text-success"></i>';
        $modalcontent .= '</div>';
        if (!empty($question->optionc)) {
            $modalcontent .= '<div class="list-group-item d-flex align-items-center ' . ($correctanswer === 'C' ? 'list-group-item-success border-success' : '') . '">';
            $modalcontent .= '<span class="badge badge-light mr-3" style="width: 28px;">C</span>';
            $modalcontent .= '<span class="flex-grow-1">' . s($question->optionc) . '</span>';
            if ($correctanswer === 'C') $modalcontent .= '<i class="fa fa-check-circle text-success"></i>';
            $modalcontent .= '</div>';
        }
        if (!empty($question->optiond)) {
            $modalcontent .= '<div class="list-group-item d-flex align-items-center ' . ($correctanswer === 'D' ? 'list-group-item-success border-success' : '') . '">';
            $modalcontent .= '<span class="badge badge-light mr-3" style="width: 28px;">D</span>';
            $modalcontent .= '<span class="flex-grow-1">' . s($question->optiond) . '</span>';
            if ($correctanswer === 'D') $modalcontent .= '<i class="fa fa-check-circle text-success"></i>';
            $modalcontent .= '</div>';
        }
        $modalcontent .= '</div>';
        if (!empty($question->rationale)) {
            $modalcontent .= '<div class="alert alert-info mt-3"><i class="fa fa-lightbulb-o mr-2"></i><strong>Rationale:</strong> ' . s($question->rationale) . '</div>';
        }
        $modalcontent .= '</div>';
        $modalcontent .= '</div>';
        $modalcontent .= '<div class="modal-footer bg-light">';
        $modalcontent .= '<button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fa fa-times mr-1"></i>Close</button>';
        $modalcontent .= '</div>';
        $modalcontent .= '</div></div></div>';

        $modals_html .= $modalcontent;
    }
    echo $modals_html;

    // Calculate trustworthiness stats
    $trustworthy_count = count(array_filter($questions, function($q) { return ($q->trustworthiness_level ?? '') === 'trustworthy'; }));
    $uncertain_count = count(array_filter($questions, function($q) { return ($q->trustworthiness_level ?? '') === 'uncertain'; }));
    $unlikely_count = count(array_filter($questions, function($q) { return ($q->trustworthiness_level ?? '') === 'unlikely'; }));
    $analyzed_count = count(array_filter($questions, function($q) { return !empty($q->trustworthiness_analyzed); }));

    // Build table
    $html = '<div class="questions-table-container mb-3">';
    $html .= '<div class="questions-toolbar">';
    
    // Search input
    $html .= '<div class="search-box">';
    $html .= '<i class="fa fa-search"></i>';
    $html .= '<input type="text" class="question-search-input form-control" placeholder="Search questions...">';
    $html .= '</div>';

    // Stats
    $approved_count = count(array_filter($questions, function($q) { return $q->status === 'approved'; }));
    $html .= '<div class="stats-info">';
    $html .= '<span class="stat-item"><i class="fa fa-list-ol"></i> ' . count($questions) . ' questions</span>';
    $html .= '<span class="stat-item text-success"><i class="fa fa-check-circle"></i> ' . $approved_count . ' approved</span>';
    $html .= '<span class="stat-item text-warning"><i class="fa fa-clock-o"></i> ' . (count($questions) - $approved_count) . ' pending</span>';
    $html .= '</div>';

    // Trustworthiness filters (type="button" prevents form submission)
    $html .= '<div class="filter-buttons">';
    $html .= '<button type="button" class="filter-btn filter-all active" data-filter="all">All</button>';
    $html .= '<button type="button" class="filter-btn filter-trustworthy" data-filter="trustworthy"><i class="fa fa-check-circle"></i> Reliable</button>';
    $html .= '<button type="button" class="filter-btn filter-uncertain" data-filter="uncertain"><i class="fa fa-exclamation-triangle"></i> Uncertain</button>';
    $html .= '<button type="button" class="filter-btn filter-unlikely" data-filter="unlikely"><i class="fa fa-times-circle"></i> Likely Wrong</button>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table table-sm table-hover questions-table" id="questions-table-' . $cm->id . '">';
    $html .= '<thead class="thead-light"><tr>';
    $html .= '<th style="width: 40px;"><input type="checkbox" class="select-all-questions" title="Select all"></th>';
    $html .= '<th style="width: 40px;">#</th>';
    $html .= '<th class="sortable-col" data-sort="text">Question</th>';
    $html .= '<th class="sortable-col text-center" data-sort="difficulty" style="width: 90px;">Difficulty</th>';
    $html .= '<th class="sortable-col text-center" data-sort="bloom" style="width: 80px;">Level</th>';
    $html .= '<th class="sortable-col text-center" data-sort="trustworthiness" style="width: 100px;">Reliability</th>';
    $html .= '<th class="sortable-col text-center" data-sort="status" style="width: 80px;">Status</th>';
    $html .= '<th class="sortable-col text-center" data-sort="date" style="width: 110px;">Created</th>';
    $html .= '<th style="width: 100px;">Actions</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';
    
    $bloomcolors = [
        'remember' => 'primary',
        'understand' => 'success',
        'apply' => 'warning',
        'analyze' => 'info',
        'evaluate' => 'danger',
        'create' => 'dark'
    ];
    
    $difficultycolors = [
        'easy' => 'difficulty-easy',
        'medium' => 'difficulty-medium',
        'hard' => 'difficulty-hard'
    ];
    
    $rownum = 1;
    foreach ($questions as $question) {
        $editurl = new moodle_url('/mod/classengage/editquestion.php', array('id' => $cm->id, 'questionid' => $question->id));
        $deleteurl = new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id, 'action' => 'delete', 'questionid' => $question->id, 'sesskey' => sesskey()));
        $approveurl = new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id, 'action' => 'approve', 'questionid' => $question->id, 'sesskey' => sesskey()));
        
        $displaytext = format_string($question->questiontext);
        if (strlen($displaytext) > 80) {
            $displaytext = substr($displaytext, 0, 80) . '...';
        }
        
        $modalid = 'question-modal-' . $question->id;
        
        $html .= '<tr data-question-id="' . $question->id . '">';
        $html .= '<td><input type="checkbox" name="q[]" value="' . $question->id . '" class="question-checkbox"></td>';
        $html .= '<td class="row-number text-muted small">' . $rownum . '</td>';
        $html .= '<td class="question-text-cell">';
        $html .= '<a href="#" class="text-dark" data-toggle="modal" data-target="#' . $modalid . '">';
        if (!empty($question->question_image)) {
            $html .= '<i class="fa fa-image text-info mr-1" title="Has image"></i>';
        }
        $html .= $displaytext;
        $html .= '</a>';
        $html .= '</td>';
        
        $difficultyclass = $difficultycolors[$question->difficulty] ?? 'badge-secondary';
        $html .= '<td class="text-center"><span class="difficulty-badge ' . $difficultyclass . '">' . ucfirst($question->difficulty) . '</span></td>';
        
        $bloomlevel = $question->bloomlevel ?? '';
        if (!empty($bloomlevel)) {
            $bloomcolor = $bloomcolors[$bloomlevel] ?? 'secondary';
            $html .= '<td class="text-center"><span class="bloom-badge badge badge-' . $bloomcolor . '">' . ucfirst($bloomlevel) . '</span></td>';
        } else {
            $html .= '<td class="text-center"><span class="bloom-badge badge badge-light">-</span></td>';
        }

        $trust_level = $question->trustworthiness_level ?? 'uncertain';
        $trust_score = $question->trustworthiness_score ?? 0;
        $trust_analyzed = !empty($question->trustworthiness_analyzed);

        $trust_class = 'trustworthiness-' . $trust_level;
        $trust_icon = '';
        if ($trust_level === 'trustworthy') {
            $trust_icon = 'fa-check-circle';
        } else if ($trust_level === 'uncertain') {
            $trust_icon = 'fa-exclamation-triangle';
        } else {
            $trust_icon = 'fa-times-circle';
        }

        if (!$trust_analyzed) {
            $html .= '<td class="text-center" data-trustworthiness="uncertain"><span class="badge badge-secondary">Not analyzed</span></td>';
        } else {
            $trust_tooltip = '';
            if (!empty($question->trustworthiness_factors)) {
                $factors = json_decode($question->trustworthiness_factors, true);
                $tooltip_parts = [];
                foreach ($factors as $factor_key => $factor) {
                    $factor_score = $factor['score'] ?? 0;
                    $tooltip_parts[] = ucfirst(str_replace('_', ' ', $factor_key)) . ': ' . $factor_score . '%';
                }
                $trust_tooltip = htmlspecialchars(implode("\n", $tooltip_parts));
            }

            $trust_attrs = '';
            if ($trust_tooltip) {
                $trust_attrs = ' data-toggle="tooltip" data-placement="top" data-html="true" title="' . $trust_tooltip . '"';
            }

            $html .= '<td class="text-center" data-trustworthiness="' . $trust_level . '">';
            $html .= '<span class="badge ' . $trust_class . '" ' . $trust_attrs . '>';
            $html .= '<i class="fa ' . $trust_icon . '"></i> <span class="trust-score">' . $trust_score . '</span>%';
            $html .= '</span>';
            $html .= '</td>';
        }
        
        $statusclass = $question->status === 'approved' ? 'badge-success' : 'badge-warning';
        $statustext = $question->status === 'approved' ? 'Approved' : 'Pending';
        $html .= '<td class="text-center"><span class="status-badge badge ' . $statusclass . '">' . $statustext . '</span></td>';
        
        $createddatetime = date('d/m/Y H:i', $question->timecreated);
        $html .= '<td class="text-center" data-timestamp="' . $question->timecreated . '"><small class="text-muted">' . $createddatetime . '</small></td>';
        
        $html .= '<td><div class="action-btn-group">';
        $html .= '<a href="' . $editurl . '" class="btn btn-sm btn-outline-primary action-btn" title="Edit"><i class="fa fa-pencil"></i></a>';
        if ($question->status !== 'approved') {
            $html .= '<a href="' . $approveurl . '" class="btn btn-sm btn-outline-success action-btn" title="Approve" onclick="return confirm(\'Approve this question?\');"><i class="fa fa-check"></i></a>';
        }
        $html .= '<a href="' . $deleteurl . '" class="btn btn-sm btn-outline-danger action-btn" title="Delete" onclick="return confirm(\'Delete this question?\');"><i class="fa fa-trash"></i></a>';
        $html .= '</div></td>';
        $html .= '</tr>';
        
        $rownum++;
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>'; // table-responsive
    $html .= '<div class="no-results-message alert alert-light text-center py-4" style="display: none;">';
    $html .= '<i class="fa fa-search fa-2x text-muted mb-2"></i>';
    $html .= '<p class="mb-0 text-muted">No questions match your filter criteria.</p>';
    $html .= '</div>';
    $html .= '</div>'; // questions-table-container
    
    return $html;
}

// Manual Questions Section
if (!empty($manual_questions)) {
    $manual_count = count($manual_questions);
    $collapseid = 'collapse-manual';
    echo html_writer::start_div('card mb-4 border');  // Remove shadow for flatter look
    echo html_writer::start_div('card-header bg-white d-flex justify-content-between align-items-center clickable-header', array(
        'data-toggle' => 'collapse',
        'data-target' => '#' . $collapseid,
        'aria-expanded' => 'true',
        'aria-controls' => $collapseid,
        'role' => 'button'
    ));
    echo html_writer::tag(
        'div',
        html_writer::tag('h5', get_string('manualquestions', 'mod_classengage'), array('class' => 'm-0 mr-2')) .
        html_writer::span($manual_count, 'badge badge-primary'),
        array('class' => 'd-flex align-items-center')
    );
    echo html_writer::tag('span', '<i class="fa fa-chevron-down"></i>', array('class' => 'collapse-icon'));
    echo html_writer::end_div();
    echo html_writer::start_div('collapse show', array('id' => $collapseid));
    echo html_writer::start_div('card-body p-0');
    echo render_question_table($manual_questions, $cm);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Generated Questions Section
if (!empty($generated_questions_by_slide)) {
    echo html_writer::tag('h5', get_string('generatedquestions', 'mod_classengage'), array('class' => 'mt-4 mb-2 text-muted'));

    $i = 0;
    foreach ($generated_questions_by_slide as $slide_title => $slide_questions) {
        $i++;
        $slide_count = count($slide_questions);
        $collapseid = 'collapse-generated-' . $i;

        $meta = $slide_metadata[$slide_title] ?? null;
        $ishighlighted = $highlight && $meta && $highlight === 'slide_' . $meta['slide_id'];
        $cardclass = 'card mb-3' . ($ishighlighted ? ' highlight-new' : '');

        echo html_writer::start_div($cardclass);
        echo html_writer::start_div('card-header bg-white d-flex justify-content-between align-items-center clickable-header', array(
            'data-toggle' => 'collapse',
            'data-target' => '#' . $collapseid,
            'aria-expanded' => 'true',
            'aria-controls' => $collapseid,
            'role' => 'button'
        ));
        echo html_writer::tag(
            'div',
            html_writer::tag('span', get_string('slide', 'mod_classengage') . ': ' . $slide_title, array('class' => 'font-weight-500')) .
            html_writer::span($slide_count, 'badge badge-info ml-2'),
            array('class' => 'd-flex align-items-center')
        );
        echo html_writer::tag('span', '<i class="fa fa-chevron-down"></i>', array('class' => 'collapse-icon'));
        echo html_writer::end_div();
        echo html_writer::start_div('collapse show', array('id' => $collapseid));
        echo html_writer::start_div('card-body p-0');

        if (isset($slide_metadata[$slide_title]) && !empty($slide_metadata[$slide_title]['provider'])) {
            $meta = $slide_metadata[$slide_title];
            $metahtml = html_writer::start_div('generation-metadata-bar bg-light border-bottom px-3 py-2 d-flex flex-wrap align-items-center gap-3');

            if (!empty($meta['provider'])) {
                $providerbadge = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-robot mr-1']) . ucfirst($meta['provider']),
                    'badge badge-dark mr-2'
                );
                $metahtml .= $providerbadge;
            }

            if (!empty($meta['model'])) {
                $modelinfo = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-microchip mr-1']) . $meta['model'],
                    'text-muted small mr-3'
                );
                $metahtml .= $modelinfo;
            }

            if (!empty($meta['generated_at'])) {
                $timeinfo = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-clock-o mr-1']) . userdate($meta['generated_at']),
                    'text-muted small mr-3'
                );
                $metahtml .= $timeinfo;
            }

            if (!empty($meta['metadata']['plan'])) {
                $plan = $meta['metadata']['plan'];
                $plantext = count($plan) . ' ' . get_string('distributionplan', 'mod_classengage');
                $planinfo = html_writer::tag(
                    'span',
                    html_writer::tag('i', '', ['class' => 'fa fa-list mr-1']) . $plantext,
                    ['class' => 'text-muted small', 'title' => json_encode($plan, JSON_PRETTY_PRINT), 'data-toggle' => 'tooltip']
                );
                $metahtml .= $planinfo;
            }

            $metahtml .= html_writer::end_div();
            echo $metahtml;
        }

        echo render_question_table($slide_questions, $cm);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

if (empty($manual_questions) && empty($generated_questions_by_slide)) {
    echo html_writer::div(get_string('noquestions', 'mod_classengage'), 'alert alert-info');
} else {
    echo html_writer::start_div('d-flex gap-2 mt-3 mb-4');
    echo html_writer::tag('button', '<i class="fa fa-trash mr-1"></i>' . get_string('delete_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-sm btn-danger',
        'onclick' => "var checked = document.querySelectorAll('.question-checkbox:checked').length; if(checked == 0) { alert('Please select at least one question.'); } else if(confirm('Delete ' + checked + ' question(s)?')) { var f = document.getElementById('questionsform'); document.getElementById('bulkaction').value='bulkdelete'; f.submit(); }"
    ));
    echo html_writer::tag('button', '<i class="fa fa-check mr-1"></i>' . get_string('approve_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-sm btn-success',
        'onclick' => "var checked = document.querySelectorAll('.question-checkbox:checked').length; if(checked == 0) { alert('Please select at least one question.'); } else if(confirm('Approve ' + checked + ' question(s)?')) { var f = document.getElementById('questionsform'); document.getElementById('bulkaction').value='bulkapprove'; f.submit(); }"
    ));
    echo html_writer::end_div();
}

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
