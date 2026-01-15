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
    html_writer::link($addurl, get_string('addquestion', 'mod_classengage'), array('class' => 'btn btn-primary mr-2'))
    //html_writer::link($genurl, get_string('generatefromtext', 'mod_classengage'), array('class' => 'btn btn-info')),
    //'mb-3'
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

$manual_questions = [];
$generated_questions_by_slide = [];
$slide_metadata = []; // Store slide metadata separately

foreach ($questions as $q) {
    if (empty($q->slideid)) {
        $manual_questions[] = $q;
    } else {
        $slidetitle = $q->slidetitle ? $q->slidetitle : get_string('unknownslide', 'mod_classengage');
        $generated_questions_by_slide[$slidetitle][] = $q;

        // Store slide metadata (only once per slide)
        if (!isset($slide_metadata[$slidetitle])) {
            $slide_metadata[$slidetitle] = [
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

// Helper function to render question table
function render_question_table($questions, $cm)
{
    global $OUTPUT;
    $table = new html_table();
    $table->head = array(
        html_writer::checkbox('selectall', 1, false, '', array('class' => 'selectall-checkbox')),
        get_string('questiontext', 'mod_classengage'),
        get_string('difficulty', 'mod_classengage'),
        get_string('cognitivelevel', 'mod_classengage'),
        get_string('status', 'mod_classengage'),
        get_string('created', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable table table-hover';

    // Bloom level badge colors
    $bloomcolors = [
        'remember' => 'primary',
        'understand' => 'success',
        'apply' => 'warning',
        'analyze' => 'info',
        'evaluate' => 'danger',
        'create' => 'dark'
    ];

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

        // Truncate question text for display
        $displaytext = format_string($question->questiontext);
        if (strlen($displaytext) > 80) {
            $displaytext = substr($displaytext, 0, 80) . '...';
        }

        // Build question preview tooltip with answers (HTML formatted)
        $correctanswer = strtoupper($question->correctanswer);
        $tooltiphtml = '<div class="question-tooltip-content">';
        $tooltiphtml .= '<div class="tooltip-question"><strong>Q:</strong> ' . s($question->questiontext) . '</div>';
        $tooltiphtml .= '<div class="tooltip-answers">';
        $tooltiphtml .= '<div class="tooltip-option' . ($correctanswer === 'A' ? ' correct' : '') . '">'
            . ($correctanswer === 'A' ? '✓ ' : '&nbsp;&nbsp;&nbsp;')
            . '<strong>A:</strong> ' . s($question->optiona) . '</div>';
        $tooltiphtml .= '<div class="tooltip-option' . ($correctanswer === 'B' ? ' correct' : '') . '">'
            . ($correctanswer === 'B' ? '✓ ' : '&nbsp;&nbsp;&nbsp;')
            . '<strong>B:</strong> ' . s($question->optionb) . '</div>';
        if (!empty($question->optionc)) {
            $tooltiphtml .= '<div class="tooltip-option' . ($correctanswer === 'C' ? ' correct' : '') . '">'
                . ($correctanswer === 'C' ? '✓ ' : '&nbsp;&nbsp;&nbsp;')
                . '<strong>C:</strong> ' . s($question->optionc) . '</div>';
        }
        if (!empty($question->optiond)) {
            $tooltiphtml .= '<div class="tooltip-option' . ($correctanswer === 'D' ? ' correct' : '') . '">'
                . ($correctanswer === 'D' ? '✓ ' : '&nbsp;&nbsp;&nbsp;')
                . '<strong>D:</strong> ' . s($question->optiond) . '</div>';
        }
        $tooltiphtml .= '</div>';
        if (!empty($question->rationale)) {
            $tooltiphtml .= '<div class="tooltip-rationale"><strong>💡 Rationale:</strong> ' . s($question->rationale) . '</div>';
        }
        $tooltiphtml .= '</div>';

        // Wrap question text with popover (focus trigger for auto-dismiss)
        $questiontext = html_writer::tag('a', $displaytext, [
            'href' => '#',
            'class' => 'question-text-hover',
            'data-toggle' => 'popover',
            'data-trigger' => 'focus',
            'data-placement' => 'right',
            'data-html' => 'true',
            'data-content' => $tooltiphtml,
            'title' => 'Question Preview',
            'tabindex' => '0',
            'onclick' => 'return false;'
        ]);

        $statusbadge = $question->status === 'approved' ?
            '<span class="badge badge-success">' . get_string('approved', 'mod_classengage') . '</span>' :
            '<span class="badge badge-warning">' . get_string('pending', 'mod_classengage') . '</span>';

        $difficultybadge = '<span class="badge badge-secondary">' . ucfirst($question->difficulty) . '</span>';

        // Create bloom level badge with color coding
        $bloomlevel = $question->bloomlevel ?? '';
        if (!empty($bloomlevel)) {
            $bloomcolor = $bloomcolors[$bloomlevel] ?? 'secondary';
            $bloombadge = '<span class="badge badge-' . $bloomcolor . '">' . ucfirst($bloomlevel) . '</span>';
        } else {
            $bloombadge = '<span class="badge badge-light">-</span>';
        }

        // Format date as dd/mm/yyyy HH:mm (military time)
        $createddatetime = date('d/m/Y H:i', $question->timecreated);

        $table->data[] = array(
            html_writer::checkbox('q[]', $question->id, false, '', array('class' => 'question-checkbox')),
            $questiontext,
            $difficultybadge,
            $bloombadge,
            $statusbadge,
            $createddatetime,
            implode(' ', $actions)
        );
    }

    // Wrap table in responsive container
    return html_writer::div(html_writer::table($table), 'table-responsive');
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

        // Display generation metadata if available
        if (isset($slide_metadata[$slide_title]) && !empty($slide_metadata[$slide_title]['provider'])) {
            $meta = $slide_metadata[$slide_title];
            $metahtml = html_writer::start_div('generation-metadata-bar bg-light border-bottom px-3 py-2 d-flex flex-wrap align-items-center gap-3');

            // Provider badge
            if (!empty($meta['provider'])) {
                $providerbadge = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-robot mr-1']) . ucfirst($meta['provider']),
                    'badge badge-dark mr-2'
                );
                $metahtml .= $providerbadge;
            }

            // Model info
            if (!empty($meta['model'])) {
                $modelinfo = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-microchip mr-1']) . $meta['model'],
                    'text-muted small mr-3'
                );
                $metahtml .= $modelinfo;
            }

            // Generation timestamp
            if (!empty($meta['generated_at'])) {
                $timeinfo = html_writer::span(
                    html_writer::tag('i', '', ['class' => 'fa fa-clock-o mr-1']) . userdate($meta['generated_at']),
                    'text-muted small mr-3'
                );
                $metahtml .= $timeinfo;
            }

            // Distribution plan summary (if available)
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

// JavaScript for Select All and Popover initialization
echo html_writer::script("
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap popovers if available
    if (typeof jQuery !== 'undefined' && jQuery.fn.popover) {
        jQuery('[data-toggle=\"popover\"]').popover({
            container: 'body',
            boundary: 'viewport'
        });
        
        // Close popover when clicking outside
        jQuery('body').on('click', function(e) {
            jQuery('[data-toggle=\"popover\"]').each(function() {
                if (!jQuery(this).is(e.target) && jQuery(this).has(e.target).length === 0 && jQuery('.popover').has(e.target).length === 0) {
                    jQuery(this).popover('hide');
                }
            });
        });
    }
    
    // Select All checkbox functionality
    var selectAllCheckboxes = document.querySelectorAll('.selectall-checkbox');
    selectAllCheckboxes.forEach(function(selectAll) {
        selectAll.addEventListener('change', function() {
            var table = this.closest('table');
            if (table) {
                var checkboxes = table.querySelectorAll('.question-checkbox');
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            }
        });
    });
});
");

echo $OUTPUT->footer();
