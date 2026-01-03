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

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$questionid = optional_param('questionid', 0, PARAM_INT);

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


// Add "Generate from Text" button
$genurl = new moodle_url('/mod/classengage/generate_questions.php', array('id' => $cm->id));
echo html_writer::div(
    html_writer::link($addurl, get_string('addquestion', 'mod_classengage'), array('class' => 'btn btn-primary mr-2')) .
    html_writer::link($genurl, get_string('generatefromtext', 'mod_classengage'), array('class' => 'btn btn-info')),
    'mb-3'
);

// Fetch questions with slide info
$sql = "SELECT q.*, s.title as slidetitle 
        FROM {classengage_questions} q 
        LEFT JOIN {classengage_slides} s ON q.slideid = s.id 
        WHERE q.classengageid = ? 
        ORDER BY q.timecreated DESC";
$questions = $DB->get_records_sql($sql, array($classengage->id));

$manual_questions = [];
$generated_questions_by_slide = [];

foreach ($questions as $q) {
    if (empty($q->slideid)) {
        $manual_questions[] = $q;
    } else {
        $slidetitle = $q->slidetitle ? $q->slidetitle : get_string('unknownslide', 'mod_classengage');
        $generated_questions_by_slide[$slidetitle][] = $q;
    }
}

// Start Bulk Actions Form
echo html_writer::start_tag('form', array('action' => $PAGE->url, 'method' => 'post', 'id' => 'questionsform'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => '', 'id' => 'bulkaction'));

// Helper function to render question table
function render_question_table($questions, $cm)
{
    global $OUTPUT;
    $table = new html_table();
    $table->head = array(
        html_writer::checkbox('selectall', 1, false, '', array('class' => 'selectall-checkbox')),
        get_string('questiontext', 'mod_classengage'),
        get_string('difficulty', 'mod_classengage'),
        get_string('status', 'mod_classengage'),
        get_string('created', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable table table-hover';

    foreach ($questions as $question) {
        $editurl = new moodle_url(
            '/mod/classengage/editquestion.php',
            array('id' => $cm->id, 'questionid' => $question->id)
        );
        $deleteurl = new moodle_url(
            '/mod/classengage/questions.php',
            array('id' => $cm->id, 'action' => 'delete', 'questionid' => $question->id, 'sesskey' => sesskey())
        );
        $approveurl = new moodle_url(
            '/mod/classengage/questions.php',
            array('id' => $cm->id, 'action' => 'approve', 'questionid' => $question->id, 'sesskey' => sesskey())
        );

        $actions = [];
        $actions[] = html_writer::link(
            $editurl,
            $OUTPUT->pix_icon('t/edit', get_string('edit')),
            array('class' => 'btn btn-sm btn-light', 'title' => get_string('edit'))
        );

        if ($question->status !== 'approved') {
            $actions[] = html_writer::link(
                $approveurl,
                $OUTPUT->pix_icon('t/check', get_string('approve')),
                array('class' => 'btn btn-sm btn-success', 'title' => get_string('approve'))
            );
        }

        $actions[] = html_writer::link(
            $deleteurl,
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
            array('class' => 'btn btn-sm btn-danger', 'title' => get_string('delete'))
        );

        // Truncate question text
        $questiontext = format_string($question->questiontext);
        if (strlen($questiontext) > 100) {
            $questiontext = substr($questiontext, 0, 100) . '...';
        }

        $statusbadge = $question->status === 'approved' ?
            '<span class="badge badge-success">' . get_string('approved', 'mod_classengage') . '</span>' :
            '<span class="badge badge-warning">' . get_string('pending', 'mod_classengage') . '</span>';

        $difficultybadge = '<span class="badge badge-secondary">' . ucfirst($question->difficulty) . '</span>';

        $table->data[] = array(
            html_writer::checkbox('q[]', $question->id, false, '', array('class' => 'question-checkbox')),
            $questiontext,
            $difficultybadge,
            $statusbadge,
            userdate($question->timecreated),
            implode(' ', $actions)
        );
    }
    return html_writer::table($table);
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
    echo html_writer::end_div(); // collapse
    echo html_writer::end_div(); // card
}

// Generated Questions Section
if (!empty($generated_questions_by_slide)) {
    echo html_writer::tag('h3', get_string('generatedquestions', 'mod_classengage'), array('class' => 'mt-4 mb-3'));

    $i = 0;
    foreach ($generated_questions_by_slide as $slide_title => $slide_questions) {
        $i++;
        $slide_count = count($slide_questions);
        $collapseid = 'collapse-generated-' . $i;

        echo html_writer::start_div('card mb-4');
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
        echo render_question_table($slide_questions, $cm);
        echo html_writer::end_div();
        echo html_writer::end_div(); // collapse
        echo html_writer::end_div(); // card
    }
}

if (empty($manual_questions) && empty($generated_questions_by_slide)) {
    echo html_writer::div(get_string('noquestions', 'mod_classengage'), 'alert alert-info');
} else {
    // Bulk Action Buttons
    echo html_writer::start_div('d-flex gap-2 mt-3 mb-5');
    echo html_writer::tag('button', get_string('delete_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-danger',
        'onclick' => "document.getElementById('bulkaction').value='bulkdelete'; document.getElementById('questionsform').submit();"
    ));
    echo html_writer::tag('button', get_string('approve_selected', 'mod_classengage'), array(
        'type' => 'button',
        'class' => 'btn btn-success ml-2',
        'onclick' => "document.getElementById('bulkaction').value='bulkapprove'; document.getElementById('questionsform').submit();"
    ));
    echo html_writer::end_div();
}

echo html_writer::end_tag('form');

// JavaScript for Select All
echo html_writer::script("
    document.querySelectorAll('.selectall-checkbox').forEach(function(selectAll) {
        selectAll.addEventListener('change', function() {
            var table = this.closest('table');
            var checkboxes = table.querySelectorAll('.question-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    });
");

echo $OUTPUT->footer();
