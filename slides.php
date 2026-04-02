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
 * Slide upload and management page - Clean Professional Design
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/upload_slides_form.php');

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$slideid = optional_param('slideid', 0, PARAM_INT);
$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:uploadslides', $context);

$PAGE->set_url('/mod/classengage/slides.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/classengage/styles.css');

// Handle bulk actions
if ($bulkaction === 'delete' && confirm_sesskey()) {
    $selectedslides = optional_param_array('selected_slides', array(), PARAM_INT);

    if (!empty($selectedslides)) {
        $fs = get_file_storage();
        $deletedcount = 0;

        foreach ($selectedslides as $sid) {
            if ($slide = $DB->get_record('classengage_slides', array('id' => $sid, 'classengageid' => $classengage->id))) {
                $fs->delete_area_files($context->id, 'mod_classengage', 'slides', $sid);
                $DB->delete_records('classengage_questions', array('slideid' => $sid));
                $DB->delete_records('classengage_slides', array('id' => $sid));

                $event = \mod_classengage\event\slide_deleted::create(array(
                    'objectid' => $sid,
                    'context' => $context,
                    'other' => array('classengageid' => $classengage->id)
                ));
                $event->trigger();

                $deletedcount++;
            }
        }

        if ($deletedcount > 0) {
            redirect($PAGE->url, get_string('slidesdeleted', 'mod_classengage', $deletedcount), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

// Handle single actions
if ($action === 'delete' && $slideid && confirm_sesskey()) {
    $slide = $DB->get_record('classengage_slides', array('id' => $slideid, 'classengageid' => $classengage->id), '*', MUST_EXIST);

    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_classengage', 'slides', $slideid);
    $DB->delete_records('classengage_questions', array('slideid' => $slideid));
    $DB->delete_records('classengage_slides', array('id' => $slideid));

    $event = \mod_classengage\event\slide_deleted::create(array(
        'objectid' => $slideid,
        'context' => $context,
        'other' => array('classengageid' => $classengage->id)
    ));
    $event->trigger();

    redirect($PAGE->url, get_string('slidedeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'generate' && $slideid && confirm_sesskey()) {
    require_once(__DIR__ . '/classes/nlp_generator.php');

    $slide = $DB->get_record('classengage_slides', array('id' => $slideid, 'classengageid' => $classengage->id), '*', MUST_EXIST);

    try {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slideid, 'id', false);

        if (empty($files)) {
            throw new Exception('Slide file not found');
        }

        $file = reset($files);
        $generator = new \mod_classengage\nlp_generator();
        $questions = $generator->generate_questions_from_file($file, $classengage->id, $slideid);

        $event = \mod_classengage\event\questions_generated::create(array(
            'objectid' => $slideid,
            'context' => $context,
            'other' => array('classengageid' => $classengage->id, 'count' => count($questions))
        ));
        $event->trigger();

        $message = get_string('questionsgeneratedsuccess', 'mod_classengage', count($questions));
        $redirecturl = new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id));

        redirect($redirecturl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        $redirecturl = new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id));
        $errormsg = get_string('error:nlpservicefailed', 'mod_classengage') . ': ' . $e->getMessage();
        debugging('NLP generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        redirect($redirecturl, $errormsg, null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle modal form submission
$formerror = '';
$showmodal = false;
if (optional_param('uploadslide', 0, PARAM_INT) && confirm_sesskey()) {
    require_once(__DIR__ . '/classes/slide_processor.php');
    
    $title = optional_param('title', '', PARAM_TEXT);
    $usefilename = optional_param('usefilename', 0, PARAM_INT);
    
    // Validate file first
    if (empty($_FILES['slidefile']) || $_FILES['slidefile']['error'] !== UPLOAD_ERR_OK) {
        $formerror = get_string('erroruploadingfile', 'mod_classengage');
        $showmodal = true;
    } else {
        // If usefilename is checked, derive title from filename
        if ($usefilename) {
            $filename = $_FILES['slidefile']['name'];
            $title = pathinfo($filename, PATHINFO_FILENAME);
        }
        
        // Now validate title
        if (empty($title)) {
            $formerror = get_string('required');
            $showmodal = true;
        } else {
            // Save uploaded file to draft area
            $fs = get_file_storage();
            $usercontext = \context_user::instance($USER->id);
            
            // Create a draft itemid
            $draftitemid = file_get_unused_draft_itemid();
            
            // Prepare file record
            $filerecord = [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $_FILES['slidefile']['name'],
                'userid' => $USER->id,
            ];
            
            // Save the file from the upload
            $tempfilepath = $_FILES['slidefile']['tmp_name'];
            $file = $fs->create_file_from_pathname($filerecord, $tempfilepath);
            
            if ($file) {
                // Prepare data for processor
                $data = (object)[
                    'title' => $title,
                    'slidefile' => $draftitemid,
                ];
                
                $processor = new \mod_classengage\slide_processor($context);
                $slideid = $processor->process_upload($data, $classengage->id, $USER->id);

                if ($slideid) {
                    $event = \mod_classengage\event\slide_uploaded::create(array(
                        'objectid' => $slideid,
                        'context' => $context,
                        'other' => array('classengageid' => $classengage->id)
                    ));
                    $event->trigger();

                    redirect($PAGE->url, get_string('slideuploaded', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    $formerror = get_string('erroruploadingfile', 'mod_classengage');
                    $showmodal = true;
                }
            } else {
                $formerror = get_string('erroruploadingfile', 'mod_classengage');
                $showmodal = true;
            }
        }
    }
}

// Initialize AMD modules
$PAGE->requires->js_call_amd('mod_classengage/slides_manager', 'init', [
    ['cmid' => $cm->id]
]);
$PAGE->requires->js_call_amd('mod_classengage/generator_wizard', 'init', [$cm->id]);

echo $OUTPUT->header();

echo classengage_render_brand_header(
    format_string($classengage->name),
    get_string('modulename', 'mod_classengage'),
    get_string('slidespage', 'mod_classengage')
);

// Clean Professional CSS
echo html_writer::tag('style', '
    /* Upload Button */
    .upload-btn {
        margin-bottom: 24px;
    }
    
    .upload-btn .btn {
        padding: 12px 24px;
        font-size: 1rem;
        font-weight: 500;
        border-radius: 6px;
    }
    
    /* Section Card */
    .slides-section {
        background: #fff;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        overflow: hidden;
    }
    
    .slides-section-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e9ecef;
        background: #f8f9fa;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .slides-section-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        color: #495057;
    }
    
    .slide-count-badge {
        background: #dee2e6;
        color: #495057;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    
    /* Toolbar */
    .slides-toolbar {
        padding: 12px 20px;
        border-bottom: 1px solid #e9ecef;
        background: #fff;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .search-box {
        position: relative;
        flex: 1;
        max-width: 300px;
    }
    
    .search-box i {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }
    
    .search-box input {
        padding-left: 32px;
        border-radius: 4px;
    }
    
    /* Table Styling */
    .slides-table {
        margin: 0;
    }
    
    .slides-table thead th {
        background: #fff;
        border-bottom: 1px solid #dee2e6;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px;
        color: #6c757d;
    }
    
    .slides-table tbody tr {
        transition: background-color 0.15s ease;
    }
    
    .slides-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .slides-table tbody td {
        padding: 12px;
        vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
        font-size: 0.875rem;
    }
    
    /* File Icon */
    .file-icon {
        font-size: 1.5rem;
        width: 40px;
        text-align: center;
    }
    
    /* Slide Info */
    .slide-title {
        font-weight: 500;
        color: #212529;
    }
    
    .slide-filename {
        color: #6c757d;
        font-size: 0.8125rem;
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
    
    .status-completed {
        background: #d4edda;
        color: #155724;
    }
    
    .status-error {
        background: #f8d7da;
        color: #721c24;
    }
    
    .status-uploaded {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .status-processing {
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
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #adb5bd;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 16px;
    }
    
    /* Bulk Actions */
    .bulk-actions {
        padding: 12px 20px;
        border-top: 1px solid #e9ecef;
        background: #f8f9fa;
    }
    
    /* Checkbox */
    .custom-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    /* Modal Improvements */
    .upload-modal .modal-content {
        border: none;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    
    .upload-modal .modal-header {
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        padding: 16px 24px;
    }
    
    .upload-modal .modal-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #495057;
    }
    
    .upload-modal .modal-body {
        padding: 24px;
    }
    
    .upload-modal .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #dee2e6;
        background: #f8f9fa;
    }
    
    /* Form in modal */
    .upload-form .form-group {
        margin-bottom: 20px;
    }
    
    .upload-form .form-group:last-child {
        margin-bottom: 0;
    }
    
    .upload-form label {
        font-weight: 500;
        font-size: 0.875rem;
        color: #495057;
        margin-bottom: 8px;
        display: block;
    }
    
    .upload-form .form-control {
        border: 1px solid #ced4da;
        border-radius: 4px;
        padding: 10px 12px;
        font-size: 0.9375rem;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    }
    
    .upload-form .form-control:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
    }
    
    /* Checkbox styling */
    .upload-form .form-check {
        padding-left: 0;
        margin-bottom: 0;
    }
    
    .upload-form .form-check-input {
        width: 20px;
        height: 20px;
        margin-top: 0;
        margin-right: 8px;
        cursor: pointer;
    }
    
    .upload-form .form-check-label {
        font-size: 0.9375rem;
        color: #495057;
        cursor: pointer;
        margin-bottom: 0;
        display: inline;
        vertical-align: middle;
    }
    
    .upload-form .form-check-input:checked + .form-check-label {
        color: #212529;
    }
    
    /* File input */
    .upload-form .custom-file {
        height: 44px;
    }
    
    .upload-form .custom-file-input {
        height: 44px;
        cursor: pointer;
    }
    
    .upload-form .custom-file-label {
        height: 44px;
        padding: 10px 12px;
        line-height: 1.5;
        color: #6c757d;
        border: 1px solid #ced4da;
        border-radius: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .upload-form .custom-file-label::after {
        height: 42px;
        padding: 10px 16px;
        line-height: 1.5;
        background-color: #e9ecef;
        border-left: 1px solid #ced4da;
    }
    
    .upload-form .form-text {
        font-size: 0.8125rem;
        color: #6c757d;
        margin-top: 8px;
    }
    
    /* Buttons */
    .upload-modal .btn {
        padding: 8px 20px;
        font-size: 0.9375rem;
        font-weight: 500;
    }
    
    .upload-modal .btn-secondary {
        background: #6c757d;
        border-color: #6c757d;
    }
    
    .upload-modal .btn-secondary:hover {
        background: #5a6268;
        border-color: #545b62;
    }
    
    /* Alert */
    .alert {
        margin-bottom: 20px;
    }
');

// Tab navigation
classengage_render_tabs($cm->id, 'slides');

// Upload Button
echo html_writer::start_div('upload-btn');
echo '<button type="button" class="btn btn-primary" data-toggle="modal" data-target="#uploadSlideModal">';
echo '<i class="fa fa-upload mr-2"></i>' . get_string('uploadnewslides', 'mod_classengage');
echo '</button>';
echo html_writer::end_div();

// Upload Modal
if (!empty($formerror)) {
    echo '<div class="alert alert-danger">' . $formerror . '</div>';
}

echo '<div class="modal fade upload-modal" id="uploadSlideModal" tabindex="-1" role="dialog" aria-labelledby="uploadSlideModalLabel" aria-hidden="true">';
echo '<div class="modal-dialog modal-lg" role="document">';
echo '<div class="modal-content">';

echo '<div class="modal-header">';
echo '<h5 class="modal-title" id="uploadSlideModalLabel"><i class="fa fa-upload mr-2 text-primary"></i>' . get_string('uploadnewslides', 'mod_classengage') . '</h5>';
echo '<button type="button" class="close" data-dismiss="modal" aria-label="Close">';
echo '<span aria-hidden="true">&times;</span>';
echo '</button>';
echo '</div>';

echo '<form method="post" action="' . $PAGE->url . '" enctype="multipart/form-data" class="upload-form">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="uploadslide" value="1">';

echo '<div class="modal-body">';

// Use filename checkbox
echo '<div class="form-group">';
echo '<div class="form-check">';
echo '<input type="checkbox" class="form-check-input" id="use-filename" name="usefilename" value="1" checked>';
echo '<label class="form-check-label" for="use-filename">Use file name as title</label>';
echo '</div>';
echo '</div>';

// Title input (hidden by default)
echo '<div class="form-group" id="title-group" style="display: none;">';
echo '<label for="slide-title">Slide Title <span class="text-danger">*</span></label>';
echo '<input type="text" class="form-control" id="slide-title" name="title" placeholder="Enter slide title">';
echo '</div>';

// File input
echo '<div class="form-group">';
echo '<label for="slide-file">Upload File <span class="text-danger">*</span></label>';
echo '<div class="custom-file">';
echo '<input type="file" class="custom-file-input" id="slide-file" name="slidefile" accept=".pdf,.ppt,.pptx,.doc,.docx" required>';
echo '<label class="custom-file-label" for="slide-file">Choose file...</label>';
echo '</div>';
echo '<small class="form-text text-muted">Supported formats: PDF, PowerPoint (.ppt, .pptx), Word (.doc, .docx)</small>';
echo '</div>';

echo '</div>'; // modal-body

echo '<div class="modal-footer">';
echo '<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>';
echo '<button type="submit" class="btn btn-primary"><i class="fa fa-upload mr-2"></i>Upload</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';
echo '</div>';

// List uploaded slides
$slides = $DB->get_records('classengage_slides', array('classengageid' => $classengage->id), 'timecreated DESC');

// Section Header
echo html_writer::start_div('slides-section');
echo html_writer::start_div('slides-section-header');
echo html_writer::tag('h3', '<i class="fa fa-files-o text-primary mr-2"></i> ' . get_string('uploadedslideslist', 'mod_classengage'));
echo html_writer::span(count($slides), 'slide-count-badge');
echo html_writer::end_div();

if ($slides) {
    // Toolbar with search
    echo html_writer::start_div('slides-toolbar');
    echo html_writer::start_div('d-flex align-items-center');
    echo html_writer::checkbox('selectall', 1, false, '', array('id' => 'select-all-slides', 'class' => 'custom-checkbox mr-2'));
    echo html_writer::label(get_string('selectall'), 'select-all-slides', false, array('class' => 'mb-0 font-weight-bold text-muted'));
    echo html_writer::end_div();
    
    echo '<div class="search-box">';
    echo '<i class="fa fa-search"></i>';
    echo '<input type="text" class="form-control form-control-sm" id="slide-search" placeholder="Search slides...">';
    echo '</div>';
    echo html_writer::end_div();

    // Table
    echo html_writer::start_tag('form', array('action' => $PAGE->url, 'method' => 'post', 'id' => 'slides-bulk-form'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'bulkaction', 'value' => 'delete'));
    
    echo '<div class="table-responsive">';
    echo '<table class="table slides-table" id="slides-table">';
    echo '<thead><tr>';
    echo '<th style="width: 40px;"></th>';
    echo '<th style="width: 50px;"></th>';
    echo '<th>Slide</th>';
    echo '<th style="width: 120px;">Status</th>';
    echo '<th style="width: 150px;">Uploaded</th>';
    echo '<th style="width: 200px;">Actions</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($slides as $slide) {
        $deleteurl = new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id, 'action' => 'delete', 'slideid' => $slide->id, 'sesskey' => sesskey()));
        
        // File icon
        $fileicon = 'fa-file-o';
        $iconcolor = 'text-muted';
        if (preg_match('/\.pdf$/i', $slide->filename)) {
            $fileicon = 'fa-file-pdf-o';
            $iconcolor = 'text-danger';
        } else if (preg_match('/\.pptx?$/i', $slide->filename)) {
            $fileicon = 'fa-file-powerpoint-o';
            $iconcolor = 'text-warning';
        } else if (preg_match('/\.docx?$/i', $slide->filename)) {
            $fileicon = 'fa-file-word-o';
            $iconcolor = 'text-primary';
        }
        
        // Status
        $statusclass = '';
        $statustext = '';
        $statusicon = '';
        
        if ($slide->status === 'completed') {
            $statusclass = 'status-completed';
            $statustext = 'Completed';
            $statusicon = 'fa-check';
        } else if ($slide->status === 'error') {
            $statusclass = 'status-error';
            $statustext = 'Error';
            $statusicon = 'fa-exclamation';
        } else if ($slide->status === 'uploaded') {
            $statusclass = 'status-uploaded';
            $statustext = 'Uploaded';
            $statusicon = 'fa-upload';
        } else {
            $statusclass = 'status-processing';
            $statustext = ucfirst($slide->status);
            $statusicon = 'fa-clock-o';
        }
        
        echo '<tr data-slide-id="' . $slide->id . '">';
        echo '<td><input type="checkbox" name="selected_slides[]" value="' . $slide->id . '" class="custom-checkbox slide-checkbox"></td>';
        echo '<td><i class="fa ' . $fileicon . ' ' . $iconcolor . ' file-icon"></i></td>';
        echo '<td>';
        echo '<div class="slide-title">' . format_string($slide->title) . '</div>';
        echo '<div class="slide-filename">' . $slide->filename . '</div>';
        echo '</td>';
        echo '<td><span class="status-badge ' . $statusclass . '"><i class="fa ' . $statusicon . '"></i> ' . $statustext . '</span></td>';
        echo '<td><small>' . userdate($slide->timecreated, '%d/%m/%Y %H:%M') . '</small></td>';
        echo '<td>';
        
        // Generate button
        echo '<button type="button" class="btn btn-primary action-btn" data-action="open-generator" data-slideid="' . $slide->id . '">';
        echo '<i class="fa fa-magic"></i> Generate';
        echo '</button>';
        
        // Delete link
        echo '<a href="' . $deleteurl . '" class="btn btn-outline-danger action-btn" onclick="return confirm(\'Delete this slide?\');">';
        echo '<i class="fa fa-trash"></i>';
        echo '</a>';
        
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    // Bulk actions
    echo '<div class="bulk-actions">';
    echo '<span class="text-muted mr-2">With selected:</span>';
    echo '<button type="submit" class="btn btn-danger btn-sm action-btn" id="bulk-delete-btn" disabled>';
    echo '<i class="fa fa-trash"></i> Delete';
    echo '</button>';
    echo '</div>';
    
    echo html_writer::end_tag('form');
    
    // JavaScript
    echo html_writer::script("
        document.addEventListener('DOMContentLoaded', function() {
            var selectAll = document.getElementById('select-all-slides');
            var checkboxes = document.querySelectorAll('.slide-checkbox');
            var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
            var searchInput = document.getElementById('slide-search');
            var table = document.getElementById('slides-table');
            
            function updateBulkButton() {
                var checkedCount = 0;
                checkboxes.forEach(function(cb) {
                    if (cb.checked) checkedCount++;
                });
                bulkDeleteBtn.disabled = checkedCount === 0;
            }
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(function(cb) {
                        cb.checked = selectAll.checked;
                    });
                    updateBulkButton();
                });
            }
            
            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    updateBulkButton();
                    if (!cb.checked && selectAll) {
                        selectAll.checked = false;
                    }
                });
            });
            
            // Search functionality
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var searchTerm = this.value.toLowerCase();
                    var rows = table.querySelectorAll('tbody tr');
                    
                    rows.forEach(function(row) {
                        var title = row.querySelector('.slide-title').textContent.toLowerCase();
                        var filename = row.querySelector('.slide-filename').textContent.toLowerCase();
                        
                        if (title.includes(searchTerm) || filename.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
            
            // Custom file input
            document.querySelector('.custom-file-input').addEventListener('change', function(e) {
                var fileName = e.target.files[0].name;
                document.querySelector('.custom-file-label').textContent = fileName;
            });
            
            // Use filename checkbox toggle
            var useFilenameCheckbox = document.getElementById('use-filename');
            var titleGroup = document.getElementById('title-group');
            var titleInput = document.getElementById('slide-title');
            
            if (useFilenameCheckbox) {
                useFilenameCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        titleGroup.style.display = 'none';
                        titleInput.removeAttribute('required');
                    } else {
                        titleGroup.style.display = 'block';
                        titleInput.setAttribute('required', 'required');
                        titleInput.focus();
                    }
                });
            }
            
            // Auto-open modal on error
            " . ($showmodal ? "$('#uploadSlideModal').modal('show');" : "") . "
        });
    ");

} else {
    echo '<div class="empty-state">';
    echo '<i class="fa fa-files-o"></i>';
    echo '<p>' . get_string('noslides', 'mod_classengage') . '</p>';
    echo '<p class="text-muted">Upload your first slide to get started</p>';
    echo '</div>';
}

echo html_writer::end_div(); // slides-section

echo $OUTPUT->footer();
