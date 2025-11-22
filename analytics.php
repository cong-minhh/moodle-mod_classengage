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
 * Analytics dashboard page with two-tab interface
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

use mod_classengage\engagement_calculator;
use mod_classengage\comprehension_analyzer;
use mod_classengage\teaching_recommender;
use mod_classengage\analytics_engine;
use mod_classengage\output\analytics_renderer;
use mod_classengage\chart_data_transformer;

$id = required_param('id', PARAM_INT); // Course module ID.
$sessionid = optional_param('sessionid', 0, PARAM_INT); // Session ID.
$tab = optional_param('tab', 'simple', PARAM_ALPHA); // Tab: simple or advanced.

// Validate tab parameter.
if (!in_array($tab, ['simple', 'advanced'])) {
    $tab = 'simple';
}

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:viewanalytics', $context);

$PAGE->set_url('/mod/classengage/analytics.php', array('id' => $cm->id, 'sessionid' => $sessionid, 'tab' => $tab));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Include Chart.js from CDN (only for advanced tab).
// TODO: Bundle Chart.js locally via npm for better performance and privacy.
if ($tab === 'advanced') {
    $PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'), true);
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

// Render standard tab navigation.
classengage_render_tabs($cm->id, 'analytics');

echo html_writer::tag('h3', get_string('analyticspage', 'mod_classengage'));

// Session selector (limit to last 50 sessions for performance).
$sessions = $DB->get_records_menu('classengage_sessions', 
    array('classengageid' => $classengage->id, 'status' => 'completed'), 
    'timecreated DESC', 
    'id,name',
    0,
    50);

if (empty($sessions)) {
    echo html_writer::div(get_string('nocompletedsessions', 'mod_classengage'), 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

if (!$sessionid || !isset($sessions[$sessionid])) {
    $sessionid = key($sessions);
}

// Session selector form.
echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('selectsession', 'mod_classengage'), 'sessionselect');
echo ' ';
$url = new moodle_url('/mod/classengage/analytics.php', array('id' => $cm->id, 'tab' => $tab));
echo html_writer::select($sessions, 'sessionid', $sessionid, null, array(
    'id' => 'sessionselect',
    'aria-label' => get_string('selectsession', 'mod_classengage'),
    'data-baseurl' => $url->out(false)
));
echo html_writer::end_div();

// Add JavaScript for session selector (XSS-safe).
$PAGE->requires->js_amd_inline("
require(['jquery'], function($) {
    $('#sessionselect').on('change', function() {
        var baseurl = $(this).data('baseurl');
        window.location = baseurl + '&sessionid=' + encodeURIComponent(this.value);
    });
});
");

// Validate session exists and belongs to this activity.
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

// Initialize last updated timestamp variable.
$lastupdatedtime = null;

// Calculate analytics data with error handling.
try {
    // Instantiate engagement calculator.
    $engagementcalculator = new engagement_calculator($sessionid, $course->id);
    $engagement = $engagementcalculator->calculate_engagement_level();
    $activitycounts = $engagementcalculator->get_activity_counts();
    $responsiveness = $engagementcalculator->get_responsiveness_indicator();
    
    // Get the cached timestamp from engagement data.
    if (isset($engagement->cached_at)) {
        $lastupdatedtime = $engagement->cached_at;
    }
    
    // Display last updated timestamp if available.
    if ($lastupdatedtime !== null) {
        $timeago = userdate($lastupdatedtime, get_string('strftimedatetimeshort', 'langconfig'));
        echo html_writer::div(
            get_string('lastupdated', 'mod_classengage', $timeago),
            'text-muted small mb-3'
        );
    }

    // Instantiate comprehension analyzer.
    $comprehensionanalyzer = new comprehension_analyzer($sessionid);
    $comprehension = $comprehensionanalyzer->get_comprehension_summary();
    $conceptdifficulty = $comprehensionanalyzer->get_concept_difficulty();
    $responsetrends = $comprehensionanalyzer->get_response_trends();

    // Instantiate teaching recommender.
    $teachingrecommender = new teaching_recommender($sessionid, $engagement, $comprehension);
    $recommendations = $teachingrecommender->generate_recommendations();

    // Instantiate analytics engine for timeline and distribution.
    $analyticsengine = new analytics_engine($classengage->id, $context);
    $engagementtimeline = $analyticsengine->get_engagement_timeline($sessionid);
    $participationdistribution = $analyticsengine->get_participation_distribution($sessionid, $course->id);
} catch (Exception $e) {
    debugging('Analytics calculation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    \core\notification::error(get_string('error:analyticsfailed', 'mod_classengage'));
    echo $OUTPUT->footer();
    exit;
}

// Instantiate analytics renderer.
$renderer = $PAGE->get_renderer('mod_classengage', 'analytics');

// Render tab navigation at top.
echo $renderer->render_tab_navigation($tab);

// Start tab content container.
echo html_writer::start_div('tab-content');

// Render Simple Analysis tab content.
$simpledata = new stdClass();
$simpledata->engagement = $engagement;
$simpledata->comprehension = $comprehension;
$simpledata->activity_counts = $activitycounts;
$simpledata->responsiveness = $responsiveness;

echo $renderer->render_simple_analysis($simpledata);

// Render Advanced Analysis tab content.
$advanceddata = new stdClass();
$advanceddata->concept_difficulty = $conceptdifficulty;
$advanceddata->response_trends = $responsetrends;
$advanceddata->recommendations = $recommendations;
$advanceddata->participation_distribution = $participationdistribution;

echo $renderer->render_advanced_analysis($advanceddata);

// End tab content container.
echo html_writer::end_div();

// Prepare chart data for JavaScript using transformer.
$chartdata = chart_data_transformer::transform_all_chart_data(
    $engagementtimeline,
    $conceptdifficulty,
    $participationdistribution
);

// Pass chart data via data attribute (better for large datasets).
echo html_writer::div('', '', [
    'id' => 'analytics-chart-data',
    'data-chartdata' => json_encode($chartdata),
    'style' => 'display:none;'
]);

// Load AMD modules using $PAGE->requires->js_call_amd().
$PAGE->requires->js_call_amd('mod_classengage/analytics_charts', 'init', []);
$PAGE->requires->js_call_amd('mod_classengage/analytics_tabs', 'init', [$cm->id, $sessionid]);

// Export button.
echo html_writer::start_div('mt-3');
$exporturl = new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid));
echo html_writer::link($exporturl, get_string('exportanalytics', 'mod_classengage'), 
    array('class' => 'btn btn-secondary'));
echo html_writer::end_div();

echo $OUTPUT->footer();

