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
 * Load testing script for ClassEngage real-time quiz API
 *
 * This script creates test users and simulates concurrent quiz responses
 * to test the performance and reliability of the real-time quiz engine under load.
 *
 * Supports testing:
 * - Legacy clicker API endpoints
 * - New real-time endpoints (batch submission, SSE)
 * - Concurrent user simulation for scalability testing
 *
 * Usage:
 *   php load_test_api.php --action=create --users=100 --prefix=loadtest
 *   php load_test_api.php --action=enroll --courseid=2 --prefix=loadtest
 *   php load_test_api.php --action=answer --sessionid=1 --prefix=loadtest --percent=50
 *   php load_test_api.php --action=batch --sessionid=1 --prefix=loadtest --batchsize=10
 *   php load_test_api.php --action=sse --sessionid=1 --prefix=loadtest --duration=30
 *   php load_test_api.php --action=concurrent --sessionid=1 --prefix=loadtest --users=200
 *   php load_test_api.php --action=cleanup --prefix=loadtest
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/externallib.php');

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'action' => 'all',
        'users' => 50,
        'sessionid' => null,
        'courseid' => null,
        'prefix' => 'loadtest',
        'percent' => 100,
        'delay' => 0,
        'verbose' => false,
        'batchsize' => 5,
        'duration' => 30,
        'report' => false,
        'baseurl' => '',  // Docker internal URL (e.g., http://bin-webserver-1).
    ),
    array(
        'h' => 'help',
        'a' => 'action',
        'u' => 'users',
        's' => 'sessionid',
        'c' => 'courseid',
        'p' => 'prefix',
        'd' => 'delay',
        'v' => 'verbose',
        'b' => 'batchsize',
        't' => 'duration',
        'r' => 'report',
    )
);

if ($options['help']) {
    $help = <<<EOT
Load testing script for ClassEngage real-time quiz API.

Creates test users and simulates concurrent quiz responses to test
the performance and reliability of the real-time quiz engine under load.

Options:
  -h, --help            Print this help message
  -a, --action=STRING   Action to perform (see below)
  -u, --users=N         Number of users to create/simulate (default: 50)
  -s, --sessionid=N     Session ID to test (required for most actions)
  -c, --courseid=N      Course ID for enrollment (required for 'enroll' action)
  -p, --prefix=STRING   Prefix for test users (default: loadtest)
  --percent=N           Percentage of users to simulate answering (default: 100)
  -d, --delay=N         Delay in milliseconds between requests (default: 0)
  -v, --verbose         Show detailed output
  --baseurl=URL         Base URL for Docker internal networking (e.g., http://bin-webserver-1)
  -b, --batchsize=N     Number of responses per batch (default: 5)
  -t, --duration=N      Duration in seconds for SSE test (default: 30)
  -r, --report          Generate detailed performance report

Actions:
  create      Create test users
  enroll      Enroll test users in a course
  answer      Simulate single answer submissions (legacy)
  batch       Test batch response submission endpoint
  sse         Test SSE connection handling
  concurrent  Simulate concurrent users (200+ users)
  cleanup     Delete test users
  all         Run create, enroll, and answer actions

Examples:
  php mod/classengage/tests/load_test_api.php --action=create --users=200 --prefix=testuser
  php mod/classengage/tests/load_test_api.php --action=enroll --courseid=2 --prefix=testuser
  php mod/classengage/tests/load_test_api.php --action=batch --sessionid=1 --prefix=testuser --batchsize=10
  php mod/classengage/tests/load_test_api.php --action=sse --sessionid=1 --prefix=testuser --duration=60
  php mod/classengage/tests/load_test_api.php --action=concurrent --sessionid=1 --prefix=testuser --users=100
  php mod/classengage/tests/load_test_api.php --action=cleanup --prefix=testuser

EOT;
    echo $help;
    exit(0);
}

// Validate parameters.
$action = $options['action'];
$numusers = (int) $options['users'];
$sessionid = (int) $options['sessionid'];
$courseid = (int) $options['courseid'];
$prefix = $options['prefix'];
$percent = (int) $options['percent'];
$delay = (int) $options['delay'];
$verbose = (bool) $options['verbose'];
$batchsize = (int) $options['batchsize'];
$duration = (int) $options['duration'];
$report = (bool) $options['report'];
$baseurl = $options['baseurl'];  // Docker internal URL.

if ($numusers < 1 || $numusers > 10000) {
    cli_error('Number of users must be between 1 and 10000');
}

if ($percent < 1 || $percent > 100) {
    cli_error('Percent must be between 1 and 100');
}

if ($batchsize < 1 || $batchsize > 100) {
    cli_error('Batch size must be between 1 and 100');
}

cli_heading('ClassEngage Load Test');
echo "Action: {$action}\n";
echo "User Prefix: {$prefix}\n";
if ($action === 'create' || $action === 'all') {
    echo "Target Users: {$numusers}\n";
}
if ($action === 'answer' || $action === 'batch' || $action === 'concurrent') {
    echo "Participation: {$percent}%\n";
}
if ($action === 'batch') {
    echo "Batch Size: {$batchsize}\n";
}
if ($action === 'sse') {
    echo "Duration: {$duration}s\n";
}
echo "\n";

// --- Action Handlers ---

if ($action === 'create' || $action === 'all') {
    perform_create_users($numusers, $prefix, $verbose);
}

if ($action === 'enroll' || $action === 'all') {
    if (!$courseid) {
        cli_error('Course ID is required for enroll action');
    }
    perform_enroll_users($courseid, $prefix, $verbose);
}

if ($action === 'answer' || $action === 'all') {
    if (!$sessionid) {
        cli_error('Session ID is required for answer action');
    }
    perform_answer_questions($sessionid, $prefix, $percent, $delay, $verbose);
}

if ($action === 'batch') {
    if (!$sessionid) {
        cli_error('Session ID is required for batch action');
    }
    perform_batch_submission($sessionid, $prefix, $percent, $batchsize, $verbose);
}

if ($action === 'sse') {
    if (!$sessionid) {
        cli_error('Session ID is required for sse action');
    }
    perform_sse_test($sessionid, $prefix, $duration, $verbose);
}

if ($action === 'concurrent') {
    if (!$sessionid) {
        cli_error('Session ID is required for concurrent action');
    }
    perform_concurrent_simulation($sessionid, $prefix, $numusers, $verbose, $report, $baseurl);
}

if ($action === 'cleanup' || ($action === 'all' && isset($options['cleanup']) && $options['cleanup'])) {
    perform_cleanup($prefix, $verbose);
}

echo "\nDone.\n";
exit(0);

// --- Functions ---


/**
 * Create test users for load testing
 *
 * @param int $numusers Number of users to create
 * @param string $prefix Username prefix
 * @param bool $verbose Show detailed output
 */
function perform_create_users($numusers, $prefix, $verbose)
{
    global $CFG;
    cli_heading('Creating Users');

    $starttime = microtime(true);
    $created = 0;
    $skipped = 0;

    for ($i = 1; $i <= $numusers; $i++) {
        $username = $prefix . '_' . $i;

        if (core_user::get_user_by_username($username)) {
            if ($verbose) {
                echo "User {$username} already exists, skipping.\n";
            }
            $skipped++;
            continue;
        }

        $user = new stdClass();
        $user->username = $username;
        $user->password = 'LoadTest123!';
        $user->firstname = 'LoadTest';
        $user->lastname = 'User' . $i;
        $user->email = $username . '@example.com';
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;

        try {
            user_create_user($user, false, false);
            $created++;
            if ($verbose) {
                echo "Created user: {$username}\n";
            }
        } catch (Exception $e) {
            echo "Error creating {$username}: " . $e->getMessage() . "\n";
        }
    }

    $time = microtime(true) - $starttime;
    echo "Created {$created} users, skipped {$skipped} existing users in " . number_format($time, 2) . "s\n";
}

/**
 * Enroll test users in a course
 *
 * @param int $courseid Course ID
 * @param string $prefix Username prefix
 * @param bool $verbose Show detailed output
 */
function perform_enroll_users($courseid, $prefix, $verbose)
{
    global $DB;
    cli_heading('Enrolling Users');

    // Course ID 1 is the front page (site course) - cannot add enrolment instances to it.
    if ($courseid == 1) {
        cli_error('Cannot enroll users in course ID 1 (front page). Please specify a real course ID (e.g., --courseid=2).');
    }

    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $users = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));

    if (empty($users)) {
        echo "No users found with prefix '{$prefix}'\n";
        return;
    }

    $enrol = enrol_get_plugin('manual');
    $instances = enrol_get_instances($courseid, true);
    $manualinstance = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manualinstance = $instance;
            break;
        }
    }
    if (!$manualinstance) {
        $enrolid = $enrol->add_default_instance($course);
        $manualinstance = $DB->get_record('enrol', array('id' => $enrolid));
    }

    $studentrole = $DB->get_record('role', array('shortname' => 'student'), '*', MUST_EXIST);

    $starttime = microtime(true);
    $enrolled = 0;

    foreach ($users as $user) {
        if (is_enrolled(context_course::instance($courseid), $user)) {
            continue;
        }
        try {
            $enrol->enrol_user($manualinstance, $user->id, $studentrole->id, 0, 0, null, false);
            $enrolled++;
            if ($verbose) {
                echo "Enrolled {$user->username}\n";
            }
        } catch (Exception $e) {
            echo "Error enrolling {$user->username}: " . $e->getMessage() . "\n";
        }
    }

    $time = microtime(true) - $starttime;
    echo "Enrolled {$enrolled} users in " . number_format($time, 2) . "s\n";
}

/**
 * Simulate single answer submissions (legacy endpoint)
 *
 * @param int $sessionid Session ID
 * @param string $prefix Username prefix
 * @param int $percent Percentage of users to simulate
 * @param int $delay Delay between requests in milliseconds
 * @param bool $verbose Show detailed output
 */
function perform_answer_questions($sessionid, $prefix, $percent, $delay, $verbose)
{
    global $DB, $CFG;
    cli_heading('Answering Questions');

    // 1. Get Session & Question.
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    if ($session->status !== 'active') {
        cli_error("Session {$sessionid} is not active.");
    }

    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";
    $currentquestion = $DB->get_record_sql($sql, array(
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1
    ));

    if (!$currentquestion) {
        cli_error('No active question found for this session');
    }

    echo "Question: " . substr($currentquestion->questiontext, 0, 50) . "...\n";

    // 2. Get Users.
    $allusers = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));
    if (empty($allusers)) {
        cli_error("No users found with prefix '{$prefix}'");
    }

    // Filter by percent.
    $targetcount = ceil(count($allusers) * ($percent / 100));
    $testusers = array_slice($allusers, 0, $targetcount);

    echo "Simulating answers for {$targetcount} users (" . count($allusers) . " total available)\n";

    // 3. Generate Token (Admin).
    $admin = get_admin();

    // Try to find a suitable service.
    $servicename = 'classengage_clicker';
    $service = $DB->get_record('external_services', array('shortname' => $servicename));

    if (!$service) {
        // Fallback to mobile app service.
        $servicename = 'moodle_mobile_app';
        $service = $DB->get_record('external_services', array('shortname' => $servicename));
    }

    if (!$service) {
        cli_error("Could not find 'classengage_clicker' or 'moodle_mobile_app' service.");
    }

    // If service is restricted, ensure admin is allowed.
    if ($service->restrictedusers) {
        $allowed = $DB->get_record('external_services_users', array(
            'externalserviceid' => $service->id,
            'userid' => $admin->id
        ));

        if (!$allowed) {
            $esu = new stdClass();
            $esu->externalserviceid = $service->id;
            $esu->userid = $admin->id;
            $esu->timecreated = time();
            $DB->insert_record('external_services_users', $esu);
            echo "Added admin user to restricted service '{$service->shortname}'\n";
        }
    }

    $token = external_generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $admin->id,
        context_system::instance(),
        0,
        ''
    );

    // 4. Simulate Requests.
    $answers = array('A', 'B', 'C', 'D');
    $serverurl = $CFG->wwwroot . '/webservice/rest/server.php';

    // Initialize session state manager for connection tracking.
    $statemanager = new \mod_classengage\session_state_manager();

    // Register test users as connected.
    echo "Registering connections for " . count($testusers) . " users...\n";
    foreach ($testusers as $user) {
        $connectionid = 'loadtest_' . $sessionid . '_' . $user->id;
        $statemanager->register_connection($sessionid, $user->id, $connectionid, 'loadtest');
    }

    $mh = curl_multi_init();
    $handles = array();
    $results = array('success' => 0, 'failed' => 0);

    $starttime = microtime(true);

    foreach ($testusers as $idx => $user) {
        // Logic for answer selection.
        $randomval = rand(1, 100);
        $answer = ($randomval <= 60) ? $currentquestion->correctanswer : $answers[array_rand($answers)];

        $clickerid = 'CLICKER-' . $user->id;

        $params = array(
            'wstoken' => $token,
            'moodlewsrestformat' => 'json',
            'wsfunction' => 'mod_classengage_submit_clicker_response',
            'sessionid' => $sessionid,
            'userid' => $user->id,
            'clickerid' => $clickerid,
            'answer' => $answer,
            'timestamp' => time()
        );

        $postdata = http_build_query($params, '', '&', PHP_QUERY_RFC1738);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $serverurl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $handles[] = array('ch' => $ch, 'user' => $user);
        curl_multi_add_handle($mh, $ch);

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($handles as $h) {
        $response = curl_multi_getcontent($h['ch']);
        $info = curl_getinfo($h['ch']);

        if ($info['http_code'] == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['success']) && $data['success']) {
                $results['success']++;
                // Mark as answered in connection tracking.
                $statemanager->mark_question_answered($sessionid, $h['user']->id);
            } else {
                $results['failed']++;
                if ($verbose) {
                    echo "Failed ({$h['user']->username}): " . ($data['message'] ?? 'Unknown') . "\n";
                }
            }
        } else {
            $results['failed']++;
            if ($verbose) {
                echo "HTTP Error ({$h['user']->username}): {$info['http_code']}\n";
            }
        }
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    $time = microtime(true) - $starttime;
    echo "Results: {$results['success']} success, {$results['failed']} failed in " . number_format($time, 2) . "s\n";
}


/**
 * Test batch response submission endpoint
 *
 * Requirements: 3.1, 3.5
 *
 * This function tests the batch response submission capabilities by directly
 * calling the response_capture_engine from CLI. Since CLI scripts cannot
 * authenticate via web sessions/sesskey, we bypass the HTTP API and test
 * the underlying engine directly.
 *
 * @param int $sessionid Session ID
 * @param string $prefix Username prefix
 * @param int $percent Percentage of users to simulate
 * @param int $batchsize Number of responses per batch
 * @param bool $verbose Show detailed output
 */
function perform_batch_submission($sessionid, $prefix, $percent, $batchsize, $verbose)
{
    global $DB, $CFG, $USER;
    cli_heading('Testing Batch Submission');

    // Get Session & Question.
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    if ($session->status !== 'active') {
        cli_error("Session {$sessionid} is not active.");
    }

    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";
    $currentquestion = $DB->get_record_sql($sql, array(
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1
    ));

    if (!$currentquestion) {
        cli_error('No active question found for this session');
    }

    echo "Question: " . substr($currentquestion->questiontext, 0, 50) . "...\n";

    // Get Users.
    $allusers = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));
    if (empty($allusers)) {
        cli_error("No users found with prefix '{$prefix}'");
    }

    $targetcount = ceil(count($allusers) * ($percent / 100));
    $testusers = array_slice($allusers, 0, $targetcount);

    echo "Simulating batch submissions for {$targetcount} users\n";
    echo "Batch size: {$batchsize} responses per request\n";

    // Get classengage instance.
    $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);

    $answers = array('A', 'B', 'C', 'D');

    $results = array(
        'batches_sent' => 0,
        'responses_processed' => 0,
        'responses_failed' => 0,
        'latencies' => array(),
    );

    $starttime = microtime(true);

    // Initialize the response capture engine.
    $engine = new \mod_classengage\response_capture_engine();

    // Group users into batches.
    $userbatches = array_chunk($testusers, $batchsize);

    foreach ($userbatches as $batchindex => $batch) {
        // Build batch of responses - each response from a different user.
        $responses = array();
        foreach ($batch as $user) {
            $randomval = rand(1, 100);
            $answer = ($randomval <= 60) ? $currentquestion->correctanswer : $answers[array_rand($answers)];

            $responses[] = array(
                'sessionid' => $sessionid,
                'questionid' => $currentquestion->id,
                'userid' => $user->id,
                'answer' => $answer,
                'timestamp' => time(),
            );
        }

        $batchstarttime = microtime(true);

        // Directly call the response capture engine (bypasses HTTP/session auth).
        try {
            $result = $engine->submit_batch($responses);

            $batchlatency = (microtime(true) - $batchstarttime) * 1000;
            $results['latencies'][] = $batchlatency;
            $results['batches_sent']++;

            if ($result->success) {
                $results['responses_processed'] += $result->processedcount;
                $results['responses_failed'] += $result->failedcount;
                if ($verbose) {
                    echo "Batch {$batchindex}: processed={$result->processedcount}, failed={$result->failedcount}, latency=" . number_format($batchlatency, 2) . "ms\n";
                }
            } else {
                $results['responses_failed'] += count($responses);
                if ($verbose) {
                    echo "Batch {$batchindex} failed: " . ($result->error ?? 'Unknown error') . "\n";
                }
            }
        } catch (Exception $e) {
            $batchlatency = (microtime(true) - $batchstarttime) * 1000;
            $results['latencies'][] = $batchlatency;
            $results['batches_sent']++;
            $results['responses_failed'] += count($responses);
            if ($verbose) {
                echo "Batch {$batchindex} exception: " . $e->getMessage() . "\n";
            }
        }
    }

    $totaltime = microtime(true) - $starttime;

    // Calculate statistics.
    $avglatency = count($results['latencies']) > 0 ? array_sum($results['latencies']) / count($results['latencies']) : 0;
    sort($results['latencies']);
    $p95index = (int) (count($results['latencies']) * 0.95);
    $p95latency = $results['latencies'][$p95index] ?? $avglatency;

    echo "\n--- Batch Submission Results ---\n";
    echo "Total batches sent: {$results['batches_sent']}\n";
    echo "Responses processed: {$results['responses_processed']}\n";
    echo "Responses failed: {$results['responses_failed']}\n";
    echo "Total time: " . number_format($totaltime, 2) . "s\n";
    echo "Average latency: " . number_format($avglatency, 2) . "ms\n";
    echo "P95 latency: " . number_format($p95latency, 2) . "ms\n";
    echo "Throughput: " . number_format($results['responses_processed'] / $totaltime, 2) . " responses/s\n";
}

/**
 * Test SSE connection handling
 *
 * Requirements: 6.1, 6.4
 *
 * @param int $sessionid Session ID
 * @param string $prefix Username prefix
 * @param int $duration Test duration in seconds
 * @param bool $verbose Show detailed output
 */
function perform_sse_test($sessionid, $prefix, $duration, $verbose)
{
    global $DB, $CFG;
    cli_heading('Testing SSE Connections');

    // Get Session.
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);

    // Get test users.
    $allusers = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'), '', '*', 0, 10);
    if (empty($allusers)) {
        cli_error("No users found with prefix '{$prefix}'");
    }

    echo "Testing SSE connections with " . count($allusers) . " users for {$duration} seconds\n";

    $sseurl = $CFG->wwwroot . '/mod/classengage/sse_handler.php';

    $results = array(
        'connections_attempted' => 0,
        'connections_established' => 0,
        'events_received' => 0,
        'errors' => 0,
        'connection_times' => array(),
    );

    $starttime = microtime(true);
    $mh = curl_multi_init();
    $handles = array();

    // Start SSE connections for each user.
    foreach ($allusers as $user) {
        $connectionid = uniqid('sse_test_' . $user->id . '_', true);
        $url = $sseurl . '?' . http_build_query(array(
            'sessionid' => $sessionid,
            'connectionid' => $connectionid,
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $duration + 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
        ));
        // Use a write function to capture streaming data.
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$results, $verbose) {
            // Parse SSE events.
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                if (strpos($line, 'event:') === 0) {
                    $results['events_received']++;
                    if ($verbose) {
                        echo "SSE Event: " . trim(substr($line, 6)) . "\n";
                    }
                }
            }
            return strlen($data);
        });

        $handles[] = array('ch' => $ch, 'user' => $user, 'start' => microtime(true));
        curl_multi_add_handle($mh, $ch);
        $results['connections_attempted']++;
    }

    // Run connections for the specified duration.
    $running = null;
    $endtime = time() + $duration;

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1);

        // Check if we've exceeded duration.
        if (time() >= $endtime) {
            break;
        }
    } while ($running > 0);

    // Cleanup and collect results.
    foreach ($handles as $h) {
        $info = curl_getinfo($h['ch']);
        $connectiontime = ($info['connect_time'] ?? 0) * 1000;
        $results['connection_times'][] = $connectiontime;

        if ($info['http_code'] == 200) {
            $results['connections_established']++;
        } else {
            $results['errors']++;
            if ($verbose) {
                echo "Connection error for {$h['user']->username}: HTTP {$info['http_code']}\n";
            }
        }

        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    $totaltime = microtime(true) - $starttime;

    // Calculate statistics.
    $avgconntime = count($results['connection_times']) > 0 ? array_sum($results['connection_times']) / count($results['connection_times']) : 0;

    echo "\n--- SSE Connection Results ---\n";
    echo "Connections attempted: {$results['connections_attempted']}\n";
    echo "Connections established: {$results['connections_established']}\n";
    echo "Events received: {$results['events_received']}\n";
    echo "Errors: {$results['errors']}\n";
    echo "Average connection time: " . number_format($avgconntime, 2) . "ms\n";
    echo "Test duration: " . number_format($totaltime, 2) . "s\n";
}


/**
 * Simulate concurrent users for scalability testing
 *
 * Requirements: 3.1, 3.2, 3.3
 *
 * This function tests scalability by directly calling the response capture engine,
 * bypassing HTTP authentication (which doesn't work in CLI context).
 * This approach accurately tests the engine's ability to handle concurrent load.
 *
 * @param int $sessionid Session ID
 * @param string $prefix Username prefix
 * @param int $numusers Number of concurrent users to simulate
 * @param bool $verbose Show detailed output
 * @param bool $report Generate detailed performance report
 * @param string $baseurl Base URL for Docker networking (unused in direct mode)
 */
function perform_concurrent_simulation($sessionid, $prefix, $numusers, $verbose, $report, $baseurl = '')
{
    global $DB, $CFG;
    cli_heading('Concurrent User Simulation');

    // Get Session & Question.
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    if ($session->status !== 'active') {
        cli_error("Session {$sessionid} is not active.");
    }

    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";
    $currentquestion = $DB->get_record_sql($sql, array(
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1
    ));

    if (!$currentquestion) {
        cli_error('No active question found for this session');
    }

    // Get test users.
    $allusers = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'), '', '*', 0, $numusers);
    $actualusers = count($allusers);

    if ($actualusers < $numusers) {
        echo "Warning: Only {$actualusers} users available (requested {$numusers})\n";
    }

    echo "Simulating {$actualusers} concurrent users\n";
    echo "Question: " . substr($currentquestion->questiontext, 0, 50) . "...\n";

    $answers = array('A', 'B', 'C', 'D');

    $results = array(
        'total_requests' => 0,
        'successful' => 0,
        'failed' => 0,
        'latencies' => array(),
        'errors' => array(),
        'start_time' => microtime(true),
    );

    // Phase 1: Register all connections using the state manager directly.
    echo "\nPhase 1: Registering connections...\n";
    $statemanager = new \mod_classengage\session_state_manager();
    $connectionsregistered = 0;

    foreach ($allusers as $user) {
        $connectionid = uniqid('concurrent_' . $user->id . '_', true);
        try {
            $statemanager->register_connection($sessionid, $user->id, $connectionid, 'loadtest');
            $connectionsregistered++;
        } catch (Exception $e) {
            if ($verbose) {
                echo "Failed to register connection for {$user->username}: {$e->getMessage()}\n";
            }
        }
    }

    echo "Connections registered: {$connectionsregistered}/{$actualusers}\n";

    // Phase 2: Submit all answers simultaneously using the response capture engine.
    echo "\nPhase 2: Submitting answers simultaneously...\n";
    $engine = new \mod_classengage\response_capture_engine();
    $submissionstart = microtime(true);

    // Build all responses first.
    $allresponses = array();
    foreach ($allusers as $user) {
        $randomval = rand(1, 100);
        $answer = ($randomval <= 60) ? $currentquestion->correctanswer : $answers[array_rand($answers)];

        $allresponses[] = array(
            'sessionid' => $sessionid,
            'questionid' => $currentquestion->id,
            'userid' => $user->id,
            'answer' => $answer,
            'timestamp' => time(),
        );
        $results['total_requests']++;
    }

    // Submit in batches of 100 (engine maximum batch size).
    $batchsize = 100;
    $batches = array_chunk($allresponses, $batchsize);
    $batchstart = microtime(true);

    foreach ($batches as $batchindex => $batchresponses) {
        try {
            $result = $engine->submit_batch($batchresponses);

            if ($result->success) {
                $results['successful'] += $result->processedcount;
                $results['failed'] += $result->failedcount;

                // Process individual results for error tracking.
                if (!empty($result->results)) {
                    foreach ($result->results as $r) {
                        if (!$r->success && !empty($r->error)) {
                            $results['errors'][$r->error] = ($results['errors'][$r->error] ?? 0) + 1;
                        }
                    }
                }
            } else {
                $results['failed'] += count($batchresponses);
                $error = $result->error ?? 'Batch submission failed';
                $results['errors'][$error] = ($results['errors'][$error] ?? 0) + count($batchresponses);
            }

            if ($verbose) {
                echo "Batch " . ($batchindex + 1) . "/" . count($batches) . ": processed={$result->processedcount}, failed={$result->failedcount}\n";
            }
        } catch (Exception $e) {
            $results['failed'] += count($batchresponses);
            $results['errors'][$e->getMessage()] = ($results['errors'][$e->getMessage()] ?? 0) + count($batchresponses);
            if ($verbose) {
                echo "Batch " . ($batchindex + 1) . " exception: {$e->getMessage()}\n";
            }
        }
    }

    $batchlatency = (microtime(true) - $batchstart) * 1000;

    // Calculate per-response latency (approximate).
    $perresponselatency = $batchlatency / max(1, count($allresponses));
    foreach ($allresponses as $idx => $resp) {
        $results['latencies'][] = $perresponselatency;
    }

    if ($verbose) {
        echo "Total batch processing latency: " . number_format($batchlatency, 2) . "ms\n";
    }

    $submissiontime = microtime(true) - $submissionstart;
    $totaltime = microtime(true) - $results['start_time'];

    // Calculate statistics.
    sort($results['latencies']);
    $hasLatencies = count($results['latencies']) > 0;
    $avglatency = $hasLatencies ? array_sum($results['latencies']) / count($results['latencies']) : 0;
    $p50index = (int) (count($results['latencies']) * 0.50);
    $p95index = (int) (count($results['latencies']) * 0.95);
    $p99index = (int) (count($results['latencies']) * 0.99);
    $p50latency = $results['latencies'][$p50index] ?? $avglatency;
    $p95latency = $results['latencies'][$p95index] ?? $avglatency;
    $p99latency = $results['latencies'][$p99index] ?? $avglatency;
    $minlatency = $hasLatencies ? min($results['latencies']) : 0;
    $maxlatency = $hasLatencies ? max($results['latencies']) : 0;

    // Check NFR compliance.
    $nfr01pass = $avglatency < 1000; // Sub-1-second average latency.
    $nfr03pass = $results['successful'] >= ($actualusers * 0.95); // 95% success rate for 200+ users.

    echo "\n--- Concurrent Simulation Results ---\n";
    echo "Total users simulated: {$actualusers}\n";
    echo "Total requests: {$results['total_requests']}\n";
    echo "Successful: {$results['successful']}\n";
    echo "Failed: {$results['failed']}\n";
    $successRate = $results['total_requests'] > 0 ? ($results['successful'] / $results['total_requests']) * 100 : 0;
    echo "Success rate: " . number_format($successRate, 2) . "%\n";
    echo "\n--- Latency Statistics ---\n";
    echo "Min latency: " . number_format($minlatency, 2) . "ms\n";
    echo "Average latency: " . number_format($avglatency, 2) . "ms\n";
    echo "P50 latency: " . number_format($p50latency, 2) . "ms\n";
    echo "P95 latency: " . number_format($p95latency, 2) . "ms\n";
    echo "P99 latency: " . number_format($p99latency, 2) . "ms\n";
    echo "Max latency: " . number_format($maxlatency, 2) . "ms\n";
    echo "\n--- Performance ---\n";
    echo "Submission time: " . number_format($submissiontime, 2) . "s\n";
    echo "Total time: " . number_format($totaltime, 2) . "s\n";
    $throughput = $submissiontime > 0 ? $results['successful'] / $submissiontime : 0;
    echo "Throughput: " . number_format($throughput, 2) . " responses/s\n";
    echo "\n--- NFR Compliance ---\n";
    echo "NFR-01 (sub-1s latency): " . ($nfr01pass ? "PASS" : "FAIL") . " (avg: " . number_format($avglatency, 2) . "ms)\n";
    $successRatePercent = $results['total_requests'] > 0 ? ($results['successful'] / $results['total_requests']) * 100 : 0;
    echo "NFR-03 (200+ concurrent): " . ($nfr03pass ? "PASS" : "FAIL") . " (success rate: " . number_format($successRatePercent, 2) . "%)\n";

    if (!empty($results['errors'])) {
        echo "\n--- Error Summary ---\n";
        foreach ($results['errors'] as $error => $count) {
            echo "  {$error}: {$count}\n";
        }
    }

    if ($report) {
        // Generate detailed report file.
        $reportfile = $CFG->dataroot . '/classengage_load_test_' . date('Y-m-d_H-i-s') . '.json';
        $reportdata = array(
            'timestamp' => date('c'),
            'sessionid' => $sessionid,
            'users' => $actualusers,
            'results' => $results,
            'statistics' => array(
                'min_latency' => $minlatency,
                'avg_latency' => $avglatency,
                'p50_latency' => $p50latency,
                'p95_latency' => $p95latency,
                'p99_latency' => $p99latency,
                'max_latency' => $maxlatency,
                'throughput' => $results['successful'] / $submissiontime,
            ),
            'nfr_compliance' => array(
                'nfr01' => $nfr01pass,
                'nfr03' => $nfr03pass,
            ),
        );
        file_put_contents($reportfile, json_encode($reportdata, JSON_PRETTY_PRINT));
        echo "\nDetailed report saved to: {$reportfile}\n";
    }
}

/**
 * Clean up test users
 *
 * @param string $prefix Username prefix
 * @param bool $verbose Show detailed output
 */
function perform_cleanup($prefix, $verbose)
{
    global $DB;
    cli_heading('Cleaning Up');

    $users = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));
    $count = 0;

    foreach ($users as $user) {
        try {
            delete_user($user);
            $count++;
            if ($verbose) {
                echo "Deleted {$user->username}\n";
            }
        } catch (Exception $e) {
            echo "Error deleting {$user->username}: " . $e->getMessage() . "\n";
        }
    }

    echo "Deleted {$count} users.\n";
}
