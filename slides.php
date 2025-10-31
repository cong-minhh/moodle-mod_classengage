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
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/upload_slides_form.php');

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$slideid = optional_param('slideid', 0, PARAM_INT);

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

// Handle actions
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
    require_once(__DIR__.'/classes/nlp_generator.php');
    
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
        redirect($redirecturl, get_string('error:nlpservicefailed', 'mod_classengage'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Form for uploading slides
$mform = new \mod_classengage\form\upload_slides_form($PAGE->url, array('cmid' => $cm->id, 'contextid' => $context->id));

if ($mform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $mform->get_data()) {
    require_once(__DIR__.'/classes/slide_processor.php');
    
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

echo $OUTPUT->heading(format_string($classengage->name));

// Tab navigation
$tabs = array();
$tabs[] = new tabobject('slides', new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id)), 
                       get_string('uploadslides', 'mod_classengage'));
$tabs[] = new tabobject('questions', new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id)), 
                       get_string('managequestions', 'mod_classengage'));
$tabs[] = new tabobject('sessions', new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id)), 
                       get_string('managesessions', 'mod_classengage'));
$tabs[] = new tabobject('analytics', new moodle_url('/mod/classengage/analytics.php', array('id' => $cm->id)), 
                       get_string('analytics', 'mod_classengage'));

print_tabs(array($tabs), 'slides');

echo html_writer::tag('h3', get_string('uploadnewslides', 'mod_classengage'));
$mform->display();

echo html_writer::tag('h3', get_string('uploadedslideslist', 'mod_classengage'), array('class' => 'mt-4'));

// List uploaded slides
$slides = $DB->get_records('classengage_slides', array('classengageid' => $classengage->id), 'timecreated DESC');

if ($slides) {
    $table = new html_table();
    $table->head = array(
        get_string('slidetitle', 'mod_classengage'),
        get_string('filename', 'mod_classengage'),
        get_string('uploaddate', 'mod_classengage'),
        get_string('status', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($slides as $slide) {
        $deleteurl = new moodle_url('/mod/classengage/slides.php', 
            array('id' => $cm->id, 'action' => 'delete', 'slideid' => $slide->id, 'sesskey' => sesskey()));
        $generateurl = new moodle_url('/mod/classengage/slides.php',
            array('id' => $cm->id, 'action' => 'generate', 'slideid' => $slide->id, 'sesskey' => sesskey()));
        
        $deletelink = html_writer::link($deleteurl, get_string('delete', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-danger', 'onclick' => 'return confirm("'.get_string('confirmdelete', 'mod_classengage').'");'));
        
        $generatelink = html_writer::link($generateurl, get_string('generatequestions', 'mod_classengage'),
            array('class' => 'btn btn-sm btn-primary'));
        
        $actions = $generatelink . ' ' . $deletelink;
        
        $table->data[] = array(
            format_string($slide->title),
            $slide->filename,
            userdate($slide->timecreated),
            $slide->status,
            $actions
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noslides', 'mod_classengage'), 'alert alert-info');
}

echo $OUTPUT->footer();

