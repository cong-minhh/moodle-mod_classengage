<?php
// Test script to simulate answer submissions with proper connection tracking.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$sessionid = isset($argv[1]) ? (int) $argv[1] : 3;
$numsubmits = isset($argv[2]) ? (int) $argv[2] : 5;

echo "Testing session statistics for session {$sessionid}\n";
echo "Simulating {$numsubmits} answer submissions with connection tracking\n\n";

$statemanager = new \mod_classengage\session_state_manager();

// Get test users
$testusers = $DB->get_records_select('user', "username LIKE 'testuser_%'", [], '', '*', 0, $numsubmits);
if (empty($testusers)) {
    die("No test users found. Run: php load_test_api.php --action=create\n");
}

// Get current question
$session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$sql = "SELECT q.*
          FROM {classengage_questions} q
          JOIN {classengage_session_questions} sq ON sq.questionid = q.id
         WHERE sq.sessionid = :sessionid
           AND sq.questionorder = :questionorder";
$currentquestion = $DB->get_record_sql($sql, [
    'sessionid' => $sessionid,
    'questionorder' => $session->currentquestion + 1
]);

if (!$currentquestion) {
    die("No current question found\n");
}

echo "Current question: " . substr($currentquestion->questiontext, 0, 50) . "...\n\n";

echo "1. Registering connections for {$numsubmits} users...\n";
foreach ($testusers as $user) {
    $connectionid = 'statstest_' . $sessionid . '_' . $user->id . '_' . time();
    $statemanager->register_connection($sessionid, $user->id, $connectionid, 'test');
    echo "  - Registered connection for user {$user->id}\n";
}

echo "\nStats BEFORE submissions:\n";
$stats = $statemanager->get_session_statistics($sessionid);
echo "  Connected: {$stats['connected']}\n";
echo "  Answered: {$stats['answered']}\n";
echo "  Pending: {$stats['pending']}\n\n";

echo "2. Submitting answers for each user...\n";
$engine = new \mod_classengage\response_capture_engine();
$answers = ['A', 'B', 'C', 'D'];
$submitted = 0;

foreach ($testusers as $user) {
    $answer = $answers[array_rand($answers)];

    // Check if user already answered
    if (
        $DB->record_exists('classengage_responses', [
            'sessionid' => $sessionid,
            'questionid' => $currentquestion->id,
            'userid' => $user->id
        ])
    ) {
        echo "  - User {$user->id}: Already answered (skipped)\n";
        continue;
    }

    // Submit via engine
    $result = $engine->submit_response($sessionid, $currentquestion->id, $answer, $user->id);
    if ($result->success) {
        // Mark as answered (this is what api.php does)
        $statemanager->mark_question_answered($sessionid, $user->id);
        $submitted++;
        echo "  - User {$user->id}: Submitted answer '{$answer}' - Success\n";
    } else {
        echo "  - User {$user->id}: Failed - {$result->error}\n";
    }
}

echo "\n3. Stats AFTER submissions:\n";
$stats = $statemanager->get_session_statistics($sessionid);
echo "  Connected: {$stats['connected']}\n";
echo "  Answered: {$stats['answered']}\n";
echo "  Pending: {$stats['pending']}\n\n";

echo "Summary: Submitted {$submitted} new answers\n";
if ($stats['answered'] != $submitted) {
    echo "WARNING: Expected {$submitted} answered, got {$stats['answered']}\n";
} else {
    echo "SUCCESS: Answered count matches submitted count!\n";
}
