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
 * Load testing script for ClassEngage clicker API
 *
 * This script creates test users and simulates concurrent clicker responses
 * to test the performance and reliability of the Web Services API under load.
 *
 * Usage:
 *   php load_test_api.php --users=50 --sessionid=1 --courseid=2
 *   php load_test_api.php --users=100 --sessionid=1 --courseid=2 --cleanup
 *   php load_test_api.php --help
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
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
        'users' => 50,
        'sessionid' => null,
        'courseid' => null,
        'cleanup' => false,
        'delay' => 0,
        'verbose' => false,
    ),
    array(
        'h' => 'help',
        'u' => 'users',
        's' => 'sessionid',
        'c' => 'courseid',
        'd' => 'delay',
        'v' => 'verbose',
    )
);

if ($options['help'] || !$options['sessionid'] || !$options['courseid']) {
    $help = <<<EOT
Load testing script for ClassEngage clicker API.

Creates test users and simulates concurrent clicker responses to test
the performance and reliability of the Web Services API under load.

Options:
  -h, --help            Print this help message
  -u, --users=N         Number of concurrent users to simulate (default: 50)
  -s, --sessionid=N     Session ID to test (required)
  -c, --courseid=N      Course ID for enrollment (required)
  -d, --delay=N         Delay in milliseconds between requests (default: 0)
  --cleanup             Remove test users after completion
  -v, --verbose         Show detailed output

Examples:
  php load_test_api.php --users=50 --sessionid=1 --courseid=2
  php load_test_api.php --users=100 --sessionid=1 --courseid=2 --cleanup
  php load_test_api.php -u 75 -s 1 -c 2 -d 100 -v

Requirements:
  - Active quiz session
  - Web services enabled
  - Admin access for token generation

EOT;
    echo $help;
    exit(0);
}

// Validate parameters.
$numusers = (int)$options['users'];
$sessionid = (int)$options['sessionid'];
$courseid = (int)$options['courseid'];
$cleanup = (bool)$options['cleanup'];
$delay = (int)$options['delay'];
$verbose = (bool)$options['verbose'];

if ($numusers < 1 || $numusers > 1000) {
    cli_error('Number of users must be between 1 and 1000');
}

// Verify session exists and is active.
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', IGNORE_MISSING);
if (!$session) {
    cli_error("Session with ID {$sessionid} not found");
}

if ($session->status !== 'active') {
    cli_error("Session {$sessionid} is not active (status: {$session->status})");
}

// Verify course exists.
$course = $DB->get_record('course', array('id' => $courseid), '*', IGNORE_MISSING);
if (!$course) {
    cli_error("Course with ID {$courseid} not found");
}

// Get classengage instance.
$classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);

cli_heading('ClassEngage Load Test');
echo "Session ID: {$sessionid}\n";
echo "Course ID: {$courseid}\n";
echo "Number of users: {$numusers}\n";
echo "Cleanup after test: " . ($cleanup ? 'Yes' : 'No') . "\n";
echo "Delay between requests: {$delay}ms\n";
echo "\n";

// Step 1: Create test users.
cli_heading('Step 1: Creating test users');
$testusers = array();
$starttime = microtime(true);

for ($i = 1; $i <= $numusers; $i++) {
    $username = 'loadtest_' . time() . '_' . $i;
    
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
        $userid = user_create_user($user, false, false);
        $testusers[] = array(
            'id' => $userid,
            'username' => $username,
            'password' => $user->password,
        );
        
        if ($verbose) {
            echo "Created user: {$username} (ID: {$userid})\n";
        }
    } catch (Exception $e) {
        cli_error("Failed to create user {$username}: " . $e->getMessage());
    }
}

$createtime = microtime(true) - $starttime;
echo "Created {$numusers} users in " . number_format($createtime, 2) . " seconds\n";
echo "\n";

// Step 2: Enroll users in course.
cli_heading('Step 2: Enrolling users in course');
$starttime = microtime(true);

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
    // Create manual enrollment instance.
    $enrolid = $enrol->add_default_instance($course);
    $manualinstance = $DB->get_record('enrol', array('id' => $enrolid));
}

$studentrole = $DB->get_record('role', array('shortname' => 'student'));

foreach ($testusers as $testuser) {
    try {
        // Enroll without sending welcome message to avoid email errors.
        $enrol->enrol_user($manualinstance, $testuser['id'], $studentrole->id, 0, 0, null, false);
        
        if ($verbose) {
            echo "Enrolled user: {$testuser['username']}\n";
        }
    } catch (Exception $e) {
        cli_error("Failed to enroll user {$testuser['username']}: " . $e->getMessage());
    }
}

$enrolltime = microtime(true) - $starttime;
echo "Enrolled {$numusers} users in " . number_format($enrolltime, 2) . " seconds\n";
echo "\n";

// Step 3: Generate web service token.
cli_heading('Step 3: Generating web service token');

// Get or create a web service token for admin user.
$admin = get_admin();

// Check if token already exists for this user.
$existingtoken = $DB->get_record('external_tokens', array(
    'userid' => $admin->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT
), '*', IGNORE_MULTIPLE);

if ($existingtoken) {
    $token = $existingtoken->token;
    if ($verbose) {
        echo "Using existing token for user {$admin->username}\n";
    }
} else {
    // Create new token.
    // Parameters: tokentype, serviceorid, userid, contextorid, validuntil, iprestriction.
    $token = external_generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        null,  // No specific service (allows all).
        $admin->id,
        context_system::instance(),
        0,  // No expiry.
        ''  // No IP restriction.
    );
    
    if ($verbose) {
        echo "Created new token for user {$admin->username}\n";
    }
}

if (!$token) {
    cli_error('Failed to generate web service token');
}

echo "Token: " . substr($token, 0, 10) . "...\n";
echo "\n";

// Step 4: Get current question.
cli_heading('Step 4: Getting current question');

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

echo "Current question: " . substr($currentquestion->questiontext, 0, 60) . "...\n";
echo "Correct answer: {$currentquestion->correctanswer}\n";
echo "\n";

// Step 5: Simulate concurrent API requests.
cli_heading('Step 5: Simulating concurrent clicker responses');

$answers = array('A', 'B', 'C', 'D');
$serverurl = $CFG->wwwroot . '/webservice/rest/server.php';
$results = array(
    'success' => 0,
    'failed' => 0,
    'errors' => array(),
);

$starttime = microtime(true);

// Use curl_multi for concurrent requests.
$mh = curl_multi_init();
$handles = array();

foreach ($testusers as $idx => $testuser) {
    // Randomly select an answer (with bias toward correct answer for realism).
    $randomval = rand(1, 100);
    if ($randomval <= 60) {
        // 60% chance of correct answer.
        $answer = $currentquestion->correctanswer;
    } else {
        // 40% chance of random answer.
        $answer = $answers[array_rand($answers)];
    }
    
    $clickerid = 'CLICKER-' . str_pad($idx + 1, 4, '0', STR_PAD_LEFT);
    
    $params = array(
        'wstoken' => $token,
        'moodlewsrestformat' => 'json',
        'wsfunction' => 'mod_classengage_submit_clicker_response',
        'sessionid' => $sessionid,
        'userid' => $testuser['id'],
        'clickerid' => $clickerid,
        'answer' => $answer,
        'timestamp' => time()
    );
    
    // Build POST data (use POST to avoid URL encoding issues).
    // Use PHP_QUERY_RFC1738 (default) which uses & as separator.
    $postdata = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    
    // Debug first request.
    if ($idx == 0 && $verbose) {
        echo "Sample request:\n";
        echo "URL: {$serverurl}\n";
        echo "POST data (raw): " . $postdata . "\n";
        echo "POST data (decoded): " . htmlspecialchars_decode($postdata) . "\n";
        echo "POST data length: " . strlen($postdata) . " bytes\n\n";
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $serverurl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Content-Length: ' . strlen($postdata)
    ));
    
    $handles[$idx] = array(
        'handle' => $ch,
        'user' => $testuser,
        'answer' => $answer,
        'clickerid' => $clickerid,
        'postdata' => $postdata,
    );
    
    curl_multi_add_handle($mh, $ch);
    
    // Add delay if specified.
    if ($delay > 0 && $idx < count($testusers) - 1) {
        usleep($delay * 1000);
    }
}

// Execute all requests concurrently.
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

// Collect results.
$firstfailure = null;
$uniqueerrors = array();

foreach ($handles as $idx => $handledata) {
    $ch = $handledata['handle'];
    $response = curl_multi_getcontent($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpcode == 200 && $response) {
        // Try JSON first.
        $data = json_decode($response, true);
        
        // If JSON decode failed, try XML (Moodle returns XML for some errors).
        if ($data === null && strpos($response, '<?xml') === 0) {
            $xml = simplexml_load_string($response);
            if ($xml && isset($xml->MESSAGE)) {
                $errormsg = (string)$xml->MESSAGE;
                $data = array('error' => $errormsg);
            }
        }
        
        if (isset($data['success']) && $data['success']) {
            $results['success']++;
            
            if ($verbose) {
                echo "✓ User {$handledata['user']['username']}: {$handledata['answer']} - " . 
                     ($data['iscorrect'] ? 'Correct' : 'Incorrect') . "\n";
            }
        } else {
            $results['failed']++;
            $errormsg = isset($data['message']) ? $data['message'] : 
                       (isset($data['error']) ? $data['error'] : 
                       (isset($data['exception']) ? $data['exception'] : 'Unknown error'));
            
            $results['errors'][] = array(
                'user' => $handledata['user']['username'],
                'error' => $errormsg,
                'response' => $response,
            );
            
            // Store unique error messages.
            if (!isset($uniqueerrors[$errormsg])) {
                $uniqueerrors[$errormsg] = 0;
            }
            $uniqueerrors[$errormsg]++;
            
            // Capture first failure for debugging.
            if ($firstfailure === null) {
                $firstfailure = array(
                    'user' => $handledata['user']['username'],
                    'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    'httpcode' => $httpcode,
                    'response' => $response,
                    'error' => $errormsg,
                    'postdata' => isset($handledata['postdata']) ? $handledata['postdata'] : '',
                );
            }
            
            if ($verbose) {
                echo "✗ User {$handledata['user']['username']}: Failed - {$errormsg}\n";
            }
        }
    } else {
        $results['failed']++;
        $curlerror = curl_error($ch);
        $results['errors'][] = array(
            'user' => $handledata['user']['username'],
            'error' => "HTTP {$httpcode}: {$curlerror}",
            'response' => $response,
        );
        
        if ($verbose) {
            echo "✗ User {$handledata['user']['username']}: HTTP {$httpcode} - {$curlerror}\n";
        }
    }
    
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

$requesttime = microtime(true) - $starttime;

echo "\n";
cli_heading('Results');
echo "Total requests: {$numusers}\n";
echo "Successful: {$results['success']}\n";
echo "Failed: {$results['failed']}\n";
echo "Success rate: " . number_format(($results['success'] / $numusers) * 100, 2) . "%\n";
echo "Total time: " . number_format($requesttime, 2) . " seconds\n";
echo "Throughput: " . number_format($numusers / $requesttime, 2) . " requests/second\n";
echo "Average response time: " . number_format(($requesttime / $numusers) * 1000, 2) . " ms\n";
echo "\n";

// Show first failure details for debugging.
if ($firstfailure !== null) {
    cli_heading('First Failure Debug Info');
    echo "User: {$firstfailure['user']}\n";
    echo "HTTP Code: {$firstfailure['httpcode']}\n";
    echo "Error: {$firstfailure['error']}\n";
    echo "URL: {$firstfailure['url']}\n";
    if (!empty($firstfailure['postdata'])) {
        echo "POST Data: {$firstfailure['postdata']}\n";
    }
    echo "Response:\n";
    echo $firstfailure['response'] . "\n";
    echo "\n";
}

// Show unique error summary.
if (!empty($uniqueerrors)) {
    cli_heading('Error Summary');
    $errorcount = 0;
    foreach ($uniqueerrors as $error => $count) {
        echo "({$count}x) {$error}\n";
        $errorcount++;
        if ($errorcount >= 10) {
            echo "... and " . (count($uniqueerrors) - 10) . " more unique errors\n";
            break;
        }
    }
    echo "\n";
}

// Step 6: Verify database integrity.
cli_heading('Step 6: Verifying database integrity');

$responsecount = $DB->count_records('classengage_responses', array('sessionid' => $sessionid));
echo "Responses in database: {$responsecount}\n";

if ($responsecount != $results['success']) {
    echo "WARNING: Response count mismatch! Expected {$results['success']}, found {$responsecount}\n";
} else {
    echo "✓ Database integrity verified\n";
}
echo "\n";

// Step 7: Cleanup (if requested).
if ($cleanup) {
    cli_heading('Step 7: Cleaning up test users');
    $starttime = microtime(true);
    
    foreach ($testusers as $testuser) {
        try {
            delete_user($DB->get_record('user', array('id' => $testuser['id'])));
            
            if ($verbose) {
                echo "Deleted user: {$testuser['username']}\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to delete user {$testuser['username']}: " . $e->getMessage() . "\n";
        }
    }
    
    $cleanuptime = microtime(true) - $starttime;
    echo "Cleaned up {$numusers} users in " . number_format($cleanuptime, 2) . " seconds\n";
    echo "\n";
}

cli_heading('Load test completed');
exit(0);
