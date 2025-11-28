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
 * Export analytics data to CSV format
 *
 * This script exports session analytics data including:
 * - Session name and date
 * - Engagement percentage and level
 * - Comprehension level and summary
 * - Activity counts (questions, polls, reactions)
 * - Responsiveness summary
 * - Difficult concepts list
 * - Teaching recommendations
 *
 * Individual student names and identifiable information are excluded
 * to maintain privacy compliance.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

use mod_classengage\engagement_calculator;
use mod_classengage\comprehension_analyzer;
use mod_classengage\teaching_recommender;
use mod_classengage\analytics_engine;

// ============================================================================
// PARAMETER VALIDATION AND SECURITY
// ============================================================================

$id = required_param('id', PARAM_INT); // Course module ID.
$sessionid = required_param('sessionid', PARAM_INT);

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
require_capability('mod/classengage:viewanalytics', $context);

// ============================================================================
// CALCULATE ANALYTICS DATA
// ============================================================================

try {
    // Instantiate analytics components.
    $engagementcalculator = new engagement_calculator($sessionid, $course->id);
    $engagement = $engagementcalculator->calculate_engagement_level();
    $activitycounts = $engagementcalculator->get_activity_counts();
    $responsiveness = $engagementcalculator->get_responsiveness_indicator();
    
    $comprehensionanalyzer = new comprehension_analyzer($sessionid);
    $comprehension = $comprehensionanalyzer->get_comprehension_summary();
    $conceptdifficulty = $comprehensionanalyzer->get_concept_difficulty();
    
    $teachingrecommender = new teaching_recommender($sessionid, $engagement, $comprehension);
    $recommendations = $teachingrecommender->generate_recommendations();
    
} catch (Exception $e) {
    debugging('Export failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    throw new moodle_exception('error:analyticsfailed', 'mod_classengage');
}

// ============================================================================
// GENERATE CSV OUTPUT
// ============================================================================

// Set headers for CSV download.
$filename = clean_filename($classengage->name . '_' . $session->name . '_analytics_' . date('Y-m-d') . '.csv');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Open output stream.
$output = fopen('php://output', 'w');

// Write UTF-8 BOM for Excel compatibility.
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header row.
fputcsv($output, array(
    get_string('sessionname', 'mod_classengage'),
    get_string('completeddate', 'mod_classengage'),
    get_string('engagementpercentage', 'mod_classengage'),
    get_string('engagementlevel', 'mod_classengage'),
    get_string('comprehensionsummary', 'mod_classengage'),
    get_string('questionsanswered', 'mod_classengage'),
    get_string('pollsubmissions', 'mod_classengage'),
    get_string('reactions', 'mod_classengage'),
    get_string('responsivenesspage', 'mod_classengage'),
    get_string('avgresponsetime', 'mod_classengage'),
    get_string('difficultconcepts', 'mod_classengage'),
    get_string('teachingrecommendations', 'mod_classengage')
));

// Prepare data row.
$sessiondate = $session->timecompleted ? userdate($session->timecompleted, get_string('strftimedatetimeshort', 'langconfig')) : '-';

// Format confused topics list.
$confusedtopics = '';
if (!empty($comprehension->confused_topics)) {
    $confusedtopics = implode('; ', array_map(function($topic) {
        return strip_tags($topic);
    }, $comprehension->confused_topics));
}

// Format difficult concepts list.
$difficultconcepts = '';
if (!empty($conceptdifficulty)) {
    $difficultlist = array();
    foreach ($conceptdifficulty as $concept) {
        if ($concept->difficulty_level === 'difficult') {
            $difficultlist[] = strip_tags($concept->question_text) . ' (' . round($concept->correctness_rate, 1) . '%)';
        }
    }
    $difficultconcepts = implode('; ', $difficultlist);
}

// Format recommendations list.
$recommendationstext = '';
if (!empty($recommendations)) {
    $recommendationstext = implode('; ', array_map(function($rec) {
        return strip_tags($rec->message);
    }, $recommendations));
}

// Write data row.
fputcsv($output, array(
    $session->name,
    $sessiondate,
    round($engagement->percentage, 1) . '%',
    $engagement->level,
    $comprehension->message . ($confusedtopics ? ' (' . $confusedtopics . ')' : ''),
    $activitycounts->questions_answered,
    $activitycounts->poll_submissions,
    $activitycounts->reactions,
    $responsiveness->pace,
    round($responsiveness->avg_time, 1) . 's',
    $difficultconcepts ?: get_string('none'),
    $recommendationstext ?: get_string('none')
));

// Close output stream.
fclose($output);

// Exit to prevent any additional output.
exit;
