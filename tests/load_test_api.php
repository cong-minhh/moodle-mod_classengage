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
 *   php load_test_api.php --action=create --users=100 --prefix=loadtest
 *   php load_test_api.php --action=enroll --courseid=2 --prefix=loadtest
 *   php load_test_api.php --action=answer --sessionid=1 --prefix=loadtest --percent=50
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
    )
);

if ($options['help']) {
    $help = <<<EOT
Load testing script for ClassEngage clicker API.

Creates test users and simulates concurrent clicker responses to test
the performance and reliability of the Web Services API under load.

Options:
  -h, --help            Print this help message
  -a, --action=STRING   Action to perform: create, enroll, answer, cleanup, all (default: all)
  -u, --users=N         Number of users to create/simulate (default: 50)
  -s, --sessionid=N     Session ID to test (required for 'answer' action)
  -c, --courseid=N      Course ID for enrollment (required for 'enroll' action)
  -p, --prefix=STRING   Prefix for test users (default: loadtest)
  --percent=N           Percentage of users to simulate answering (default: 100)
  -d, --delay=N         Delay in milliseconds between requests (default: 0)
  -v, --verbose         Show detailed output

Examples:
  php load_test_api.php --action=create --users=100 --prefix=testuser
  php load_test_api.php --action=enroll --courseid=2 --prefix=testuser
  php load_test_api.php --action=answer --sessionid=1 --prefix=testuser --percent=50
  php load_test_api.php --action=cleanup --prefix=testuser

EOT;
    echo $help;
    exit(0);
}

// Validate parameters.
$action = $options['action'];
$numusers = (int)$options['users'];
$sessionid = (int)$options['sessionid'];
$courseid = (int)$options['courseid'];
$prefix = $options['prefix'];
$percent = (int)$options['percent'];
$delay = (int)$options['delay'];
$verbose = (bool)$options['verbose'];

if ($numusers < 1 || $numusers > 10000) {
    cli_error('Number of users must be between 1 and 10000');
}

if ($percent < 1 || $percent > 100) {
    cli_error('Percent must be between 1 and 100');
}

cli_heading('ClassEngage Load Test');
echo "Action: {$action}\n";
echo "User Prefix: {$prefix}\n";
if ($action === 'create' || $action === 'all') {
    echo "Target Users: {$numusers}\n";
}
if ($action === 'answer') {
    echo "Participation: {$percent}%\n";
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

if ($action === 'cleanup' || ($action === 'all' && isset($options['cleanup']) && $options['cleanup'])) {
    perform_cleanup($prefix, $verbose);
}

echo "\nDone.\n";
exit(0);

// --- Functions ---

function perform_create_users($numusers, $prefix, $verbose) {
    global $CFG;
    cli_heading('Creating Users');
    
    $starttime = microtime(true);
    $created = 0;
    $skipped = 0;

    for ($i = 1; $i <= $numusers; $i++) {
        $username = $prefix . '_' . $i;
        
        if (core_user::get_user_by_username($username)) {
            if ($verbose) echo "User {$username} already exists, skipping.\n";
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
            if ($verbose) echo "Created user: {$username}\n";
        } catch (Exception $e) {
            echo "Error creating {$username}: " . $e->getMessage() . "\n";
        }
    }

    $time = microtime(true) - $starttime;
    echo "Created {$created} users, skipped {$skipped} existing users in " . number_format($time, 2) . "s\n";
}

function perform_enroll_users($courseid, $prefix, $verbose) {
    global $DB;
    cli_heading('Enrolling Users');
    
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
            if ($verbose) echo "Enrolled {$user->username}\n";
        } catch (Exception $e) {
            echo "Error enrolling {$user->username}: " . $e->getMessage() . "\n";
        }
    }

    $time = microtime(true) - $starttime;
    echo "Enrolled {$enrolled} users in " . number_format($time, 2) . "s\n";
}

function perform_answer_questions($sessionid, $prefix, $percent, $delay, $verbose) {
    global $DB, $CFG;
    cli_heading('Answering Questions');

    // 1. Get Session & Question
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

    // 2. Get Users
    $allusers = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));
    if (empty($allusers)) {
        cli_error("No users found with prefix '{$prefix}'");
    }
    
    // Filter by percent
    $target_count = ceil(count($allusers) * ($percent / 100));
    $testusers = array_slice($allusers, 0, $target_count);
    
    echo "Simulating answers for {$target_count} users (" . count($allusers) . " total available)\n";

    // 3. Generate Token (Admin)
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
        EXTERNAL_TOKEN_PERMANENT, $service, $admin->id, context_system::instance(), 0, ''
    );

    // 4. Simulate Requests
    $answers = array('A', 'B', 'C', 'D');
    $serverurl = $CFG->wwwroot . '/webservice/rest/server.php';
    
    $mh = curl_multi_init();
    $handles = array();
    $results = array('success' => 0, 'failed' => 0);
    
    $starttime = microtime(true);

    foreach ($testusers as $idx => $user) {
        // Logic for answer selection
        $randomval = rand(1, 100);
        $answer = ($randomval <= 60) ? $currentquestion->correctanswer : $answers[array_rand($answers)];
        
        $clickerid = 'CLICKER-' . $user->id; // Use User ID for stability
        
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
        
        if ($delay > 0) usleep($delay * 1000);
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
            } else {
                $results['failed']++;
                if ($verbose) echo "Failed ({$h['user']->username}): " . ($data['message'] ?? 'Unknown') . "\n";
            }
        } else {
            $results['failed']++;
            if ($verbose) echo "HTTP Error ({$h['user']->username}): {$info['http_code']}\n";
        }
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    $time = microtime(true) - $starttime;
    echo "Results: {$results['success']} success, {$results['failed']} failed in " . number_format($time, 2) . "s\n";
}

function perform_cleanup($prefix, $verbose) {
    global $DB;
    cli_heading('Cleaning Up');
    
    $users = $DB->get_records_select('user', "username LIKE :prefix", array('prefix' => $prefix . '_%'));
    $count = 0;
    
    foreach ($users as $user) {
        try {
            delete_user($user);
            $count++;
            if ($verbose) echo "Deleted {$user->username}\n";
        } catch (Exception $e) {
            echo "Error deleting {$user->username}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Deleted {$count} users.\n";
}
