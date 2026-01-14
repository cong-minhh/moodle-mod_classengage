<?php
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$sessionid = required_param('sessionid', PARAM_INT);

$PAGE->set_context(context_system::instance());
require_login();

// Validate session and capabilities
$session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', ['id' => $session->classengageid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('mod/classengage:viewanalytics', $context);

$response = ['success' => false];

try {
    // 1. Gather Session Data
    // We need to instantiate the analytics calculators to get the data
    // This logic mimics what analytics.php does to prepare data

    $completion = new \completion_info(get_course($classengage->course));

    // Engagement
    $engagementcalc = new \mod_classengage\engagement_calculator($sessionid, $classengage->course);
    $engagement_stats = $engagementcalc->calculate_engagement_level(); // Returns object
    $engagement_stats_array = (array) $engagement_stats; // Cast to array for easier usage if needed, or access as object

    // Comprehension
    $comprehensionanalyzer = new \mod_classengage\comprehension_analyzer($sessionid);
    $comprehension_stats = $comprehensionanalyzer->get_comprehension_summary(); // Returns object
    $comprehension_stats_array = (array) $comprehension_stats;
    $concept_difficulty = $comprehensionanalyzer->get_concept_difficulty(); // Assuming this method exists or similar data

    // Activity Counts (simplified)
    $questions_answered = $DB->count_records('classengage_responses', ['sessionid' => $sessionid]);
    $poll_submissions = 0; // Poll feature not yet implemented

    $session_data = [
        'engagement' => [
            'percentage' => $engagement_stats->percentage ?? 0,
            'level' => $engagement_stats->level ?? 'Unknown',
            'unique_participants' => $engagement_stats->unique_participants ?? 0,
            'total_enrolled' => $engagement_stats->total_enrolled ?? 0
        ],
        'comprehension' => [
            'level' => $comprehension_stats->level ?? 'Unknown',
            'avg_correctness' => $comprehension_stats->avg_correctness ?? 0,
            'confused_topics' => $comprehension_stats->confused_topics ?? []
        ],
        'activity_counts' => [
            'questions_answered' => $questions_answered,
            'poll_submissions' => $poll_submissions,
            'reactions' => 0 // Placeholder
        ],
        'concept_difficulty' => [] // Map existing concept difficulty to simple format if needed
    ];

    // Map strict concept structure if available
    if (!empty($concept_difficulty)) {
        foreach ($concept_difficulty as $concept) {
            $session_data['concept_difficulty'][] = [
                'question_order' => $concept->question_order ?? 0,
                'difficulty_level' => $concept->difficulty ?? 'medium',
                'correctness_rate' => $concept->correctness ?? 0,
                'question_text' => $concept->text ?? ''
            ];
        }
    }

    // 2. Call AI Service
    require_once(__DIR__ . '/classes/nlp_generator.php');
    $generator = new \mod_classengage\nlp_generator();
    $analysis_result = $generator->analyze_session($session_data);

    // 3. Render Result using Mustache
    $renderer = $PAGE->get_renderer('mod_classengage', 'analytics');
    $html = $renderer->render_from_template('mod_classengage/ai_analysis_result', $analysis_result);

    $response = [
        'success' => true,
        'html' => $html
    ];

} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
