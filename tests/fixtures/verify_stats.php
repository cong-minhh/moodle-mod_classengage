<?php
// Quick verification script for session statistics fix.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$sessionid = isset($argv[1]) ? (int) $argv[1] : 3;

echo "Checking session statistics for session {$sessionid}\n\n";

// Get current stats.
$statemanager = new \mod_classengage\session_state_manager();
$stats = $statemanager->get_session_statistics($sessionid);

echo "Current statistics:\n";
echo "  Connected: {$stats['connected']}\n";
echo "  Answered: {$stats['answered']}\n";
echo "  Pending: {$stats['pending']}\n\n";

// Show raw connection records.
$connections = $DB->get_records('classengage_connections', ['sessionid' => $sessionid], '', 'id, userid, status, current_question_answered');
echo "Connection records: " . count($connections) . "\n";

$connectedCount = 0;
$answeredCount = 0;
foreach ($connections as $c) {
    if ($c->status === 'connected') {
        $connectedCount++;
        if ($c->current_question_answered == 1) {
            $answeredCount++;
        }
    }
}

echo "  Raw connected (status='connected'): {$connectedCount}\n";
echo "  Raw answered (status='connected' AND current_question_answered=1): {$answeredCount}\n\n";

// Show all unique users with answers.
$responseCount = $DB->count_records('classengage_responses', ['sessionid' => $sessionid]);
echo "Total responses in session: {$responseCount}\n";
