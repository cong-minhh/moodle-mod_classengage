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
 * Slide upload and management page
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/upload_slides_form.php');

$id = required_param('id', PARAM_INT); // Course module ID
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

// Handle bulk actions
if ($bulkaction === 'delete' && confirm_sesskey()) {
    $selectedslides = optional_param_array('selected_slides', array(), PARAM_INT);

    if (!empty($selectedslides)) {
        $fs = get_file_storage();
        $deletedcount = 0;

        foreach ($selectedslides as $sid) {
            // Verify slide belongs to this activity
            if ($slide = $DB->get_record('classengage_slides', array('id' => $sid, 'classengageid' => $classengage->id))) {
                // Delete file
                $fs->delete_area_files($context->id, 'mod_classengage', 'slides', $sid);

                // Delete questions associated with this slide
                $DB->delete_records('classengage_questions', array('slideid' => $sid));

                // Delete slide record
                $DB->delete_records('classengage_slides', array('id' => $sid));

                // Trigger event
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

    // Delete file
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_classengage', 'slides', $slideid);

    // Delete questions associated with this slide
    $DB->delete_records('classengage_questions', array('slideid' => $slideid));

    // Delete slide record
    $DB->delete_records('classengage_slides', array('id' => $slideid));

    // Trigger event
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
        // Get the stored file
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slideid, 'id', false);

        if (empty($files)) {
            throw new Exception('Slide file not found');
        }

        $file = reset($files);

        // Generate questions by sending file to NLP service
        $generator = new \mod_classengage\nlp_generator();
        $questions = $generator->generate_questions_from_file($file, $classengage->id, $slideid);

        // Trigger event before redirect to avoid session mutation
        $event = \mod_classengage\event\questions_generated::create(array(
            'objectid' => $slideid,
            'context' => $context,
            'other' => array('classengageid' => $classengage->id, 'count' => count($questions))
        ));
        $event->trigger();

        // Create success message and redirect URL
        $message = get_string('questionsgeneratedsuccess', 'mod_classengage', count($questions));
        $redirecturl = new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id));

        redirect($redirecturl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Exception $e) {
        $redirecturl = new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id));
        // Show the actual error message for debugging
        $errormsg = get_string('error:nlpservicefailed', 'mod_classengage') . ': ' . $e->getMessage();
        debugging('NLP generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        redirect($redirecturl, $errormsg, null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Form for uploading slides
$mform = new \mod_classengage\form\upload_slides_form($PAGE->url, array('cmid' => $cm->id, 'contextid' => $context->id));

if ($mform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $mform->get_data()) {
    require_once(__DIR__ . '/classes/slide_processor.php');

    $processor = new \mod_classengage\slide_processor($context);
    $slideid = $processor->process_upload($data, $classengage->id, $USER->id);

    if ($slideid) {
        // Trigger event
        $event = \mod_classengage\event\slide_uploaded::create(array(
            'objectid' => $slideid,
            'context' => $context,
            'other' => array('classengageid' => $classengage->id)
        ));
        $event->trigger();

        redirect($PAGE->url, get_string('slideuploaded', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect($PAGE->url, get_string('erroruploadingfile', 'mod_classengage'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();

// Initialize NLP progress bar AMD module.
$PAGE->requires->js_call_amd('mod_classengage/slides_manager', 'init', [
    ['cmid' => $cm->id]
]);
// Initialize Generator Wizard
$PAGE->requires->js_call_amd('mod_classengage/generator_wizard', 'init', [$cm->id]);

echo $OUTPUT->heading(format_string($classengage->name));

// Tab navigation using shared function.
classengage_render_tabs($cm->id, 'slides');

echo html_writer::tag('h3', get_string('uploadnewslides', 'mod_classengage'));
$mform->display();

echo html_writer::tag('h3', get_string('uploadedslideslist', 'mod_classengage'), array('class' => 'mt-4 mb-3'));

// List uploaded slides
$slides = $DB->get_records('classengage_slides', array('classengageid' => $classengage->id), 'timecreated DESC');

if ($slides) {
    // Bulk action form
    echo html_writer::start_tag('form', array('action' => $PAGE->url, 'method' => 'post', 'id' => 'slides-bulk-form'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'bulkaction', 'value' => 'delete'));

    // Bulk Actions Toolbar
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-3 p-2 slides-bulk-toolbar');
    echo html_writer::start_div('form-check ml-2');
    echo html_writer::checkbox('selectall', 1, false, get_string('selectall'), array('id' => 'select-all-slides', 'class' => 'form-check-input'));
    echo html_writer::label(get_string('selectall'), 'select-all-slides', false, array('class' => 'form-check-label font-weight-bold'));
    echo html_writer::end_div();

    echo html_writer::start_div();
    echo html_writer::tag('button', get_string('delete_selected', 'mod_classengage'), array(
        'type' => 'submit',
        'class' => 'btn btn-danger btn-sm',
        'id' => 'bulk-delete-btn',
        'disabled' => 'disabled',
        'onclick' => 'return confirm("' . get_string('confirmbulkdelete', 'mod_classengage') . '");'
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Grid Layout
    echo html_writer::start_div('row');

    $delay = 0;
    foreach ($slides as $slide) {
        $deleteurl = new moodle_url(
            '/mod/classengage/slides.php',
            array('id' => $cm->id, 'action' => 'delete', 'slideid' => $slide->id, 'sesskey' => sesskey())
        );
        $generateurl = new moodle_url(
            '/mod/classengage/slides.php',
            array('id' => $cm->id, 'action' => 'generate', 'slideid' => $slide->id, 'sesskey' => sesskey())
        );

        // Determine icon based on file extension
        $fileicon = 'fa-file-o';
        if (preg_match('/\.pdf$/i', $slide->filename)) {
            $fileicon = 'fa-file-pdf-o text-danger';
        } else if (preg_match('/\.pptx?$/i', $slide->filename)) {
            $fileicon = 'fa-file-powerpoint-o text-warning';
        }

        // Add data attributes for NLP job tracking.
        $nlpstatus = $slide->nlp_job_status ?? 'idle';
        $nlpprogress = $slide->nlp_job_progress ?? 0;

        echo html_writer::start_div('col-md-6 col-lg-4 mb-4 animate-slide-in', array('style' => 'animation-delay: ' . $delay . 's'));
        echo html_writer::start_div('card h-100 classengage-slide-card', array(
            'data-slideid' => $slide->id,
            'data-nlp-status' => $nlpstatus,
            'data-nlp-progress' => $nlpprogress
        ));

        $delay += 0.1;

        // Card Header with Checkbox and Title
        echo html_writer::start_div('card-header d-flex align-items-center bg-white border-bottom-0 pt-3 pb-0');
        echo html_writer::checkbox('selected_slides[]', $slide->id, false, '', array('class' => 'slide-checkbox mr-2'));
        echo html_writer::tag('h5', format_string($slide->title), array('class' => 'card-title mb-0 text-truncate', 'title' => $slide->title));
        echo html_writer::end_div();

        // Card Body
        echo html_writer::start_div('card-body');
        echo html_writer::start_div('d-flex align-items-center mb-3');
        echo html_writer::tag('i', '', array('class' => 'fa ' . $fileicon . ' fa-3x mr-3'));
        echo html_writer::start_div();
        echo html_writer::div($slide->filename, 'text-muted small text-truncate', array('style' => 'max-width: 150px;'));
        echo html_writer::div(userdate($slide->timecreated), 'text-muted small');

        // Status Badge
        $statusclass = 'badge-secondary';
        $statustext = $slide->status;

        if ($slide->status === 'completed') {
            $statusclass = 'badge-success';
            $statustext = get_string('completed', 'mod_classengage');
        } else if ($slide->status === 'error') {
            $statusclass = 'badge-danger';
            $statustext = get_string('error', 'mod_classengage');
        } else if ($slide->status === 'uploaded') {
            $statusclass = 'badge-info';
            $statustext = get_string('uploaded', 'mod_classengage');
        } else {
            // Fallback for other statuses
            $statustext = get_string($slide->status, 'mod_classengage');
        }
        echo html_writer::span($statustext, 'badge ' . $statusclass . ' mt-1');
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div(); // card-body

        // Card Footer with Actions
        echo html_writer::start_div('card-footer bg-white border-top-0 d-flex justify-content-between');

        // Use button for AJAX-based NLP generation (with link fallback for non-JS).
        $btndisabled = in_array($nlpstatus, ['pending', 'running']);
        // Use button to trigger modal wizard
        echo html_writer::tag(
            'button',
            get_string('generatequestions', 'mod_classengage'),
            array(
                'class' => 'btn btn-primary btn-sm',
                'title' => get_string('generatequestions', 'mod_classengage'),
                'data-action' => 'open-generator',
                'data-slideid' => $slide->id
            )
        );
        echo html_writer::link(
            $deleteurl,
            get_string('delete', 'mod_classengage'),
            array('class' => 'btn btn-outline-danger btn-sm', 'onclick' => 'return confirm("' . get_string('confirmdelete', 'mod_classengage') . '");')
        );
        echo html_writer::end_div();

        echo html_writer::end_div(); // card
        echo html_writer::end_div(); // col
    }

    echo html_writer::end_div(); // row
    echo html_writer::end_tag('form');

    // JavaScript for Bulk Selection
    echo html_writer::script("
        document.addEventListener('DOMContentLoaded', function() {
            var selectAll = document.getElementById('select-all-slides');
            var checkboxes = document.querySelectorAll('.slide-checkbox');
            var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
            
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
        });
    ");

} else {
    echo html_writer::div(get_string('noslides', 'mod_classengage'), 'alert alert-info');
}

echo $OUTPUT->footer();

