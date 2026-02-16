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

// Enhanced CSS for better table QoL
echo html_writer::tag('style', '
    /* Table Container Improvements */
    .questions-table-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        overflow: hidden;
    }
    
    /* Sticky Header - Compact */
    .questions-table thead th {
        position: sticky;
        top: 0;
        background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 8px 6px;
        z-index: 10;
        white-space: nowrap;
    }
    
    /* Row Styling - Compact */
    .questions-table tbody tr {
        transition: all 0.15s ease;
        border-left: 2px solid transparent;
    }
    
    .questions-table tbody tr:hover {
        background-color: #e3f2fd;
        border-left-color: #2196f3;
    }
    
    .questions-table tbody td {
        padding: 6px 8px;
        vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
    }
    
    /* Row Number - Compact */
    .row-number {
        color: #6c757d;
        font-weight: 600;
        font-size: 0.875rem;
        width: 36px;
        text-align: center;
    }
    
    /* Question Text Link - Compact */
    .question-text-link {
        color: #212529;
        text-decoration: none;
        font-weight: 400;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.9375rem;
        line-height: 1.3;
    }
    
    .question-text-link:hover {
        color: #007bff;
        text-decoration: underline;
    }
    
    /* Image Indicator */
    .has-image-indicator {
        color: #17a2b8;
        font-size: 1.1rem;
    }
    
    /* Badges - Compact */
    .difficulty-badge {
        font-size: 0.8125rem;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
        white-space: nowrap;
        display: inline-block;
    }
    
    .difficulty-easy { background: #d4edda; color: #155724; }
    .difficulty-medium { background: #fff3cd; color: #856404; }
    .difficulty-hard { background: #f8d7da; color: #721c24; }
    
    .bloom-badge {
        font-size: 0.8125rem;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    /* Status Badge - Compact */
    .status-badge {
        font-size: 0.8125rem;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
    }
    
    /* Action Buttons - Compact */
    .action-btn {
        padding: 5px 8px;
        font-size: 0.875rem;
        border-radius: 5px;
        transition: all 0.15s ease;
        margin: 0 2px;
        line-height: 1;
    }
    
    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    
    .action-btn-group {
        display: flex;
        gap: 2px;
        justify-content: flex-end;
    }
    
    /* Search Bar - Compact */
    .questions-search-bar {
        background: #f8f9fa;
        padding: 10px 12px;
        border-radius: 6px;
        margin-bottom: 10px;
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .search-input-wrapper {
        position: relative;
        flex: 1;
        min-width: 200px;
    }
    
    .search-input-wrapper i {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 0.875rem;
    }
    
    .search-input-wrapper input {
        padding-left: 30px;
        padding-top: 8px;
        padding-bottom: 8px;
        border-radius: 16px;
        border: 1px solid #ced4da;
        font-size: 0.9375rem;
    }
    
    .search-input-wrapper input:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.15);
    }
    
    /* No Results Message */
    .no-results {
        text-align: center;
        padding: 30px 20px;
        color: #6c757d;
    }
    
    /* Checkbox Styling */
    .custom-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    /* Sort Icons */
    .sortable-header {
        cursor: pointer;
        user-select: none;
    }
    
    .sortable-header:hover {
        background: #e9ecef;
    }
    
    .sort-icon {
        margin-left: 5px;
        opacity: 0.3;
        font-size: 0.75rem;
    }
    
    .sortable-header:hover .sort-icon,
    .sort-asc .sort-icon,
    .sort-desc .sort-icon {
        opacity: 1;
    }
    
    /* Question Stats - Compact */
    .questions-stats {
        display: flex;
        gap: 15px;
        font-size: 0.9375rem;
        color: #6c757d;
    }

    .questions-stats span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* Table body text */
    .questions-table tbody td {
        font-size: 0.9375rem;
    }

    /* Table header text */
    .questions-table thead th {
        font-size: 0.8125rem;
    }

    /* Highlight Animation */
    .highlight-new {
        animation: highlight-pulse 2s ease-out;
    }
    
    @keyframes highlight-pulse {
        0% { background-color: rgba(23, 162, 184, 0.3); }
        100% { background-color: transparent; }
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        .questions-table thead th:nth-child(4),
        .questions-table thead th:nth-child(6),
        .questions-table tbody td:nth-child(4),
        .questions-table tbody td:nth-child(6) {
            display: none;
        }
        
        .action-btn span {
            display: none;
        }
        
        .questions-search-bar {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-input-wrapper {
            min-width: 100%;
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

// Start Bulk Actions Form
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
    
    // Build table
    $html = '<div class="questions-table-container">';
    $html .= '<div class="questions-search-bar">';
    $html .= '<div class="search-input-wrapper">';
    $html .= '<i class="fa fa-search"></i>';
    $html .= '<input type="text" class="form-control" id="question-search" placeholder="Search questions...">';
    $html .= '</div>';
    $html .= '<div class="questions-stats">';
    $html .= '<span><i class="fa fa-list-ol"></i> <strong>' . count($questions) . '</strong> questions</span>';
    $approved_count = count(array_filter($questions, function($q) { return $q->status === 'approved'; }));
    $html .= '<span><i class="fa fa-check-circle text-success"></i> <strong>' . $approved_count . '</strong> approved</span>';
    $html .= '<span><i class="fa fa-clock-o text-warning"></i> <strong>' . (count($questions) - $approved_count) . '</strong> pending</span>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table questions-table view-compact" id="questions-table-' . $cm->id . '">';
    $html .= '<thead><tr>';
    $html .= '<th style="width: 40px;"><input type="checkbox" class="custom-checkbox selectall-checkbox" title="Select all"></th>';
    $html .= '<th style="width: 50px;">#</th>';
    $html .= '<th class="sortable-header" data-sort="text">Question <i class="fa fa-sort sort-icon"></i></th>';
    $html .= '<th class="sortable-header text-center" data-sort="difficulty" style="width: 140px; min-width: 140px; white-space: nowrap;">Difficulty <i class="fa fa-sort sort-icon"></i></th>';
    $html .= '<th class="sortable-header text-center" data-sort="bloom" style="width: 100px; min-width: 100px; white-space: nowrap;">Level <i class="fa fa-sort sort-icon"></i></th>';
    $html .= '<th class="sortable-header text-center" data-sort="status" style="width: 100px; min-width: 100px; white-space: nowrap;">Status <i class="fa fa-sort sort-icon"></i></th>';
    $html .= '<th class="sortable-header text-center" data-sort="date" style="width: 130px; min-width: 130px; white-space: nowrap;">Created <i class="fa fa-sort sort-icon"></i></th>';
    $html .= '<th style="width: 140px;">Actions</th>';
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
        $html .= '<td><input type="checkbox" name="q[]" value="' . $question->id . '" class="custom-checkbox question-checkbox"></td>';
        $html .= '<td class="row-number">' . $rownum . '</td>';
        $html .= '<td>';
        $html .= '<a href="#" class="question-text-link" data-toggle="modal" data-target="#' . $modalid . '">';
        if (!empty($question->question_image)) {
            $html .= '<i class="fa fa-image has-image-indicator" title="Has image"></i>';
        }
        $html .= '<span>' . $displaytext . '</span>';
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
        
        $statusclass = $question->status === 'approved' ? 'badge-success' : 'badge-warning';
        $statustext = $question->status === 'approved' ? 'Approved' : 'Pending';
        $html .= '<td class="text-center"><span class="status-badge badge ' . $statusclass . '">' . $statustext . '</span></td>';
        
        $createddatetime = date('d/m/Y H:i', $question->timecreated);
        $html .= '<td class="text-center" data-timestamp="' . $question->timecreated . '"><small class="text-muted">' . $createddatetime . '</small></td>';
        
        $html .= '<td><div class="action-btn-group">';
        $html .= '<a href="' . $editurl . '" class="btn btn-sm btn-outline-primary action-btn" title="Edit question"><i class="fa fa-pencil"></i></a>';
        if ($question->status !== 'approved') {
            $html .= '<a href="' . $approveurl . '" class="btn btn-sm btn-outline-success action-btn" title="Approve question" onclick="return confirm(\'Approve this question?\');"><i class="fa fa-check"></i></a>';
        }
        $html .= '<a href="' . $deleteurl . '" class="btn btn-sm btn-outline-danger action-btn" title="Delete question" onclick="return confirm(\'Delete this question?\');"><i class="fa fa-trash"></i></a>';
        $html .= '</div></td>';
        $html .= '</tr>';
        
        $rownum++;
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>'; // table-responsive
    $html .= '<div id="no-search-results" class="no-results" style="display: none;">';
    $html .= '<i class="fa fa-search fa-3x mb-3 text-muted"></i>';
    $html .= '<p>No questions match your search.</p>';
    $html .= '</div>';
    $html .= '</div>'; // questions-table-container
    
    return $html;
}

// Manual Questions Section
if (!empty($manual_questions)) {
    $manual_count = count($manual_questions);
    $collapseid = 'collapse-manual';
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-header bg-white d-flex justify-content-between align-items-center clickable-header', array(
        'data-toggle' => 'collapse',
        'data-target' => '#' . $collapseid,
        'aria-expanded' => 'true',
        'aria-controls' => $collapseid,
        'role' => 'button'
    ));
    echo html_writer::tag(
        'div',
        html_writer::tag('h4', get_string('manualquestions', 'mod_classengage'), array('class' => 'm-0 d-inline-block mr-2')) .
        html_writer::span($manual_count, 'badge badge-primary question-count-badge'),
        array('class' => 'd-flex align-items-center')
    );
    echo html_writer::tag('span', $OUTPUT->pix_icon('t/expanded', get_string('collapse')), array('class' => 'collapse-icon'));
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
    echo html_writer::tag('h3', get_string('generatedquestions', 'mod_classengage'), array('class' => 'mt-4 mb-3'));

    $i = 0;
    foreach ($generated_questions_by_slide as $slide_title => $slide_questions) {
        $i++;
        $slide_count = count($slide_questions);
        $collapseid = 'collapse-generated-' . $i;

        $meta = $slide_metadata[$slide_title] ?? null;
        $ishighlighted = $highlight && $meta && $highlight === 'slide_' . $meta['slide_id'];
        $cardclass = 'card mb-4' . ($ishighlighted ? ' highlight-new' : '');

        echo html_writer::start_div($cardclass);
        echo html_writer::start_div('card-header bg-light d-flex justify-content-between align-items-center clickable-header', array(
            'data-toggle' => 'collapse',
            'data-target' => '#' . $collapseid,
            'aria-expanded' => 'true',
            'aria-controls' => $collapseid,
            'role' => 'button'
        ));
        echo html_writer::tag(
            'div',
            html_writer::tag('h5', get_string('slide', 'mod_classengage') . ': ' . $slide_title, array('class' => 'm-0 d-inline-block mr-2')) .
            html_writer::span($slide_count, 'badge badge-info question-count-badge'),
            array('class' => 'd-flex align-items-center')
        );
        echo html_writer::tag('span', $OUTPUT->pix_icon('t/expanded', get_string('collapse')), array('class' => 'collapse-icon'));
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
    echo html_writer::start_div('d-flex gap-2 mt-3 mb-5');
    echo html_writer::tag('button', '<i class="fa fa-trash mr-1"></i>' . get_string('delete_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-danger',
        'onclick' => "if(confirm('Delete selected questions?')) { document.getElementById('bulkaction').value='bulkdelete'; document.getElementById('questionsform').submit(); }"
    ));
    echo html_writer::tag('button', '<i class="fa fa-check mr-1"></i>' . get_string('approve_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-success ml-2',
        'onclick' => "if(confirm('Approve selected questions?')) { document.getElementById('bulkaction').value='bulkapprove'; document.getElementById('questionsform').submit(); }"
    ));
    echo html_writer::end_div();
}

echo html_writer::end_tag('form');

// Enhanced JavaScript for table interactions
echo html_writer::script("
document.addEventListener('DOMContentLoaded', function() {
    // Select All functionality
    document.querySelectorAll('.selectall-checkbox').forEach(function(selectAll) {
        selectAll.addEventListener('change', function() {
            var table = this.closest('table');
            if (table) {
                table.querySelectorAll('.question-checkbox').forEach(function(checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            }
        });
    });
    
    // Search functionality
    document.querySelectorAll('#question-search').forEach(function(searchInput) {
        searchInput.addEventListener('input', function() {
            var container = this.closest('.questions-table-container');
            var table = container.querySelector('table');
            var noResults = container.querySelector('#no-search-results');
            var searchTerm = this.value.toLowerCase();
            var visibleCount = 0;
            
            table.querySelectorAll('tbody tr').forEach(function(row) {
                var questionText = row.querySelector('.question-text-link').textContent.toLowerCase();
                if (questionText.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && searchTerm !== '') {
                table.style.display = 'none';
                noResults.style.display = 'block';
            } else {
                table.style.display = 'table';
                noResults.style.display = 'none';
            }
        });
    });
    
    // Sortable headers
    document.querySelectorAll('.sortable-header').forEach(function(header) {
        header.addEventListener('click', function() {
            var table = this.closest('table');
            var tbody = table.querySelector('tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var sortType = this.dataset.sort;
            var currentSort = this.classList.contains('sort-asc') ? 'asc' : 
                             (this.classList.contains('sort-desc') ? 'desc' : null);
            var newSort = currentSort === 'asc' ? 'desc' : 'asc';
            
            // Reset other headers
            table.querySelectorAll('.sortable-header').forEach(function(h) {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            this.classList.add('sort-' + newSort);
            
            // Sort rows
            rows.sort(function(a, b) {
                var aVal, bVal;
                var index = Array.from(header.parentNode.children).indexOf(header);
                var aCell = a.children[index];
                var bCell = b.children[index];
                
                if (sortType === 'date') {
                    // Use data-timestamp attribute for numeric sorting
                    aVal = parseInt(aCell.dataset.timestamp) || 0;
                    bVal = parseInt(bCell.dataset.timestamp) || 0;
                    // For dates, we typically want newest first (desc) as default
                    if (newSort === 'asc') {
                        return aVal - bVal;
                    } else {
                        return bVal - aVal;
                    }
                } else {
                    aVal = aCell.textContent.trim().toLowerCase();
                    bVal = bCell.textContent.trim().toLowerCase();
                    if (aVal < bVal) return newSort === 'asc' ? -1 : 1;
                    if (aVal > bVal) return newSort === 'asc' ? 1 : -1;
                    return 0;
                }
            });
            
            // Re-append sorted rows
            rows.forEach(function(row) {
                tbody.appendChild(row);
            });
        });
    });
});
");

echo $OUTPUT->footer();
