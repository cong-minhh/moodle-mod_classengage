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
 * Test script for ClassEngage Web Services API endpoints
 *
 * This script tests all the web service endpoints used by ClassEngage.
 * Run from browser or command line with appropriate token.
 *
 * Usage:
 *   Browser: https://yourmoodle.com/mod/classengage/tests/test_webservices_api.php
 *   CLI: php test_webservices_api.php
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// =====================================================================
// CONFIGURATION - UPDATE THESE VALUES
// =====================================================================

$config = [
    // Your Moodle site URL (no trailing slash)
    // Must match your $CFG->wwwroot exactly to avoid 303 redirects
    'wwwroot' => 'http://localhost:8000',

    // Web service token - REPLACE WITH YOUR TOKEN
    'token' => '539cc5f4d0ee2c78e225c3c8f57e003f',

    // Test data - Update these IDs to match your environment
    'classengageid' => 1,      // ClassEngage activity instance ID
    'sessionid' => 1,          // An active session ID for testing
    'userid' => 4,             // A test student user ID
    'clickerid' => 'TEST001',  // A test clicker device ID
];

// =====================================================================
// API ENDPOINT TESTER CLASS
// =====================================================================

class ClassEngageAPITester
{

    private $wwwroot;
    private $token;
    private $results = [];
    private $verbose = true;

    public function __construct($wwwroot, $token)
    {
        $this->wwwroot = rtrim($wwwroot, '/');
        $this->token = $token;
    }

    /**
     * Set verbose mode
     */
    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    /**
     * Make a web service API call
     *
     * @param string $function The web service function name
     * @param array $params Parameters to pass
     * @return array Response data
     */
    public function callAPI($function, $params = [])
    {
        $url = $this->wwwroot . '/webservice/rest/server.php';

        $postdata = array_merge([
            'wstoken' => $this->token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ], $params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postdata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        $starttime = microtime(true);
        $response = curl_exec($ch);
        $duration = round((microtime(true) - $starttime) * 1000, 2);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = [
            'function' => $function,
            'http_code' => $httpcode,
            'duration_ms' => $duration,
            'success' => false,
            'response' => null,
            'error' => null,
        ];

        if ($error) {
            $result['error'] = "cURL error: $error";
        } else if ($httpcode !== 200) {
            $result['error'] = "HTTP error: $httpcode";
        } else {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['error'] = "JSON decode error: " . json_last_error_msg();
                $result['response'] = $response;
            } else if (isset($decoded['exception'])) {
                $result['error'] = $decoded['message'] ?? $decoded['exception'];
                $result['response'] = $decoded;
            } else {
                $result['success'] = true;
                $result['response'] = $decoded;
            }
        }

        $this->results[] = $result;
        return $result;
    }

    /**
     * Log a message
     */
    private function log($message, $type = 'info')
    {
        if (!$this->verbose) {
            return;
        }

        $colors = [
            'info' => "\033[0;36m",    // Cyan
            'success' => "\033[0;32m", // Green
            'error' => "\033[0;31m",   // Red
            'warning' => "\033[0;33m", // Yellow
            'reset' => "\033[0m",
        ];

        $isCli = php_sapi_name() === 'cli';

        if ($isCli) {
            echo $colors[$type] . $message . $colors['reset'] . "\n";
        } else {
            $htmlColors = [
                'info' => '#0891b2',
                'success' => '#16a34a',
                'error' => '#dc2626',
                'warning' => '#ca8a04',
            ];
            echo "<div style='color: {$htmlColors[$type]}; font-family: monospace; margin: 2px 0;'>$message</div>";
        }
    }

    /**
     * Print section header
     */
    private function printHeader($title)
    {
        $isCli = php_sapi_name() === 'cli';
        if ($isCli) {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "  $title\n";
            echo str_repeat('=', 60) . "\n";
        } else {
            echo "<h2 style='margin-top: 20px; border-bottom: 2px solid #333;'>$title</h2>";
        }
    }

    /**
     * Print test result
     */
    private function printResult($result)
    {
        $status = $result['success'] ? '✓ PASS' : '✗ FAIL';
        $type = $result['success'] ? 'success' : 'error';

        $this->log("$status - {$result['function']} ({$result['duration_ms']}ms)", $type);

        if (!$result['success'] && $result['error']) {
            $this->log("   Error: {$result['error']}", 'warning');
        }

        if ($this->verbose && $result['response']) {
            $isCli = php_sapi_name() === 'cli';
            $json = json_encode($result['response'], JSON_PRETTY_PRINT);
            if ($isCli) {
                echo "   Response: " . substr($json, 0, 200) . (strlen($json) > 200 ? '...' : '') . "\n";
            } else {
                echo "<pre style='background: #f5f5f5; padding: 10px; margin: 5px 0 15px 20px; font-size: 12px; max-height: 150px; overflow: auto;'>";
                echo htmlspecialchars($json);
                echo "</pre>";
            }
        }
    }

    // =====================================================================
    // TEST METHODS FOR EACH ENDPOINT
    // =====================================================================

    /**
     * Test 1: Get Active Session
     * Endpoint: mod_classengage_get_active_session
     */
    public function testGetActiveSession($classengageid)
    {
        $this->printHeader('Test 1: Get Active Session');
        $this->log("Testing mod_classengage_get_active_session with classengageid=$classengageid");

        $result = $this->callAPI('mod_classengage_get_active_session', [
            'classengageid' => $classengageid,
        ]);

        $this->printResult($result);

        // Validate response structure
        if ($result['success'] && $result['response']) {
            $expectedKeys = ['hassession', 'sessionid', 'sessionname', 'status', 'currentquestion', 'totalquestions'];
            $missing = array_diff($expectedKeys, array_keys($result['response']));
            if (!empty($missing)) {
                $this->log("   Warning: Missing expected keys: " . implode(', ', $missing), 'warning');
            }
        }

        return $result;
    }

    /**
     * Test 2: Get Current Question
     * Endpoint: mod_classengage_get_current_question
     */
    public function testGetCurrentQuestion($sessionid)
    {
        $this->printHeader('Test 2: Get Current Question');
        $this->log("Testing mod_classengage_get_current_question with sessionid=$sessionid");

        $result = $this->callAPI('mod_classengage_get_current_question', [
            'sessionid' => $sessionid,
        ]);

        $this->printResult($result);

        // Validate response structure
        if ($result['success'] && $result['response']) {
            $expectedKeys = ['hasquestion', 'questionid', 'questiontext', 'questionnumber', 'timeremaining'];
            $missing = array_diff($expectedKeys, array_keys($result['response']));
            if (!empty($missing)) {
                $this->log("   Warning: Missing expected keys: " . implode(', ', $missing), 'warning');
            }
        }

        return $result;
    }

    /**
     * Test 3: Register Clicker
     * Endpoint: mod_classengage_register_clicker
     */
    public function testRegisterClicker($userid, $clickerid)
    {
        $this->printHeader('Test 3: Register Clicker Device');
        $this->log("Testing mod_classengage_register_clicker with userid=$userid, clickerid=$clickerid");

        $result = $this->callAPI('mod_classengage_register_clicker', [
            'userid' => $userid,
            'clickerid' => $clickerid,
        ]);

        $this->printResult($result);

        // Validate response structure
        if ($result['success'] && $result['response']) {
            $expectedKeys = ['success', 'message'];
            $missing = array_diff($expectedKeys, array_keys($result['response']));
            if (!empty($missing)) {
                $this->log("   Warning: Missing expected keys: " . implode(', ', $missing), 'warning');
            }
        }

        return $result;
    }

    /**
     * Test 4: Submit Clicker Response
     * Endpoint: mod_classengage_submit_clicker_response
     */
    public function testSubmitClickerResponse($sessionid, $userid, $clickerid = '', $answer = 'A')
    {
        $this->printHeader('Test 4: Submit Clicker Response');
        $this->log("Testing mod_classengage_submit_clicker_response");
        $this->log("   sessionid=$sessionid, userid=$userid, clickerid=$clickerid, answer=$answer");

        $result = $this->callAPI('mod_classengage_submit_clicker_response', [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'clickerid' => $clickerid,
            'answer' => $answer,
            'timestamp' => time(),
        ]);

        $this->printResult($result);

        // Validate response structure
        if ($result['success'] && $result['response']) {
            $expectedKeys = ['success', 'message', 'iscorrect', 'correctanswer'];
            $missing = array_diff($expectedKeys, array_keys($result['response']));
            if (!empty($missing)) {
                $this->log("   Warning: Missing expected keys: " . implode(', ', $missing), 'warning');
            }
        }

        return $result;
    }

    /**
     * Test 5: Submit Bulk Responses
     * Endpoint: mod_classengage_submit_bulk_responses
     */
    public function testSubmitBulkResponses($sessionid, $responses)
    {
        $this->printHeader('Test 5: Submit Bulk Responses');
        $this->log("Testing mod_classengage_submit_bulk_responses with " . count($responses) . " responses");

        // Format responses for API call
        $formattedResponses = [];
        foreach ($responses as $idx => $resp) {
            $formattedResponses["responses[$idx][userid]"] = $resp['userid'] ?? 0;
            $formattedResponses["responses[$idx][clickerid]"] = $resp['clickerid'];
            $formattedResponses["responses[$idx][answer]"] = $resp['answer'];
            $formattedResponses["responses[$idx][timestamp]"] = $resp['timestamp'] ?? time();
        }

        $result = $this->callAPI('mod_classengage_submit_bulk_responses', array_merge(
            ['sessionid' => $sessionid],
            $formattedResponses
        ));

        $this->printResult($result);

        // Validate response structure
        if ($result['success'] && $result['response']) {
            $expectedKeys = ['success', 'message', 'processed', 'failed', 'results'];
            $missing = array_diff($expectedKeys, array_keys($result['response']));
            if (!empty($missing)) {
                $this->log("   Warning: Missing expected keys: " . implode(', ', $missing), 'warning');
            }
        }

        return $result;
    }

    // =====================================================================
    // ADDITIONAL UTILITY TESTS
    // =====================================================================

    /**
     * Test: Validate Token
     * Check if the token is valid by making a simple core API call
     */
    public function testTokenValidity()
    {
        $this->printHeader('Prerequisite: Token Validation');
        $this->log("Testing token validity with core_webservice_get_site_info");

        $result = $this->callAPI('core_webservice_get_site_info');
        $this->printResult($result);

        if ($result['success'] && isset($result['response']['sitename'])) {
            $this->log("   Site: " . $result['response']['sitename'], 'success');
            $this->log("   User: " . ($result['response']['fullname'] ?? 'Unknown'), 'success');
        }

        return $result;
    }

    /**
     * Test: Invalid parameters handling
     */
    public function testInvalidParameters()
    {
        $this->printHeader('Test: Error Handling - Invalid Parameters');

        // Test with invalid session ID
        $this->log("Testing with invalid sessionid=-999");
        $result = $this->callAPI('mod_classengage_get_current_question', [
            'sessionid' => -999,
        ]);
        $this->printResult($result);

        // Test with invalid answer
        $this->log("Testing with invalid answer='Z'");
        $result = $this->callAPI('mod_classengage_submit_clicker_response', [
            'sessionid' => 1,
            'userid' => 1,
            'clickerid' => 'TEST',
            'answer' => 'Z',
            'timestamp' => time(),
        ]);
        $this->printResult($result);

        return $result;
    }

    // =====================================================================
    // SUMMARY AND REPORTING
    // =====================================================================

    /**
     * Get test summary
     */
    public function getSummary()
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, fn($r) => $r['success']));
        $failed = $total - $passed;
        $avgDuration = $total > 0
            ? round(array_sum(array_column($this->results, 'duration_ms')) / $total, 2)
            : 0;

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 1) : 0,
            'avg_duration_ms' => $avgDuration,
        ];
    }

    /**
     * Print summary report
     */
    public function printSummary()
    {
        $summary = $this->getSummary();

        $isCli = php_sapi_name() === 'cli';

        if ($isCli) {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "  TEST SUMMARY\n";
            echo str_repeat('=', 60) . "\n";
            echo "  Total Tests:     {$summary['total']}\n";
            echo "  Passed:          {$summary['passed']} ✓\n";
            echo "  Failed:          {$summary['failed']} ✗\n";
            echo "  Pass Rate:       {$summary['pass_rate']}%\n";
            echo "  Avg Duration:    {$summary['avg_duration_ms']}ms\n";
            echo str_repeat('=', 60) . "\n";
        } else {
            echo "<div style='background: #f0f9ff; border: 2px solid #0284c7; padding: 20px; margin-top: 30px; border-radius: 8px;'>";
            echo "<h2 style='margin-top: 0; color: #0369a1;'>📊 Test Summary</h2>";
            echo "<table style='border-collapse: collapse;'>";
            echo "<tr><td style='padding: 5px 15px 5px 0;'>Total Tests:</td><td><strong>{$summary['total']}</strong></td></tr>";
            echo "<tr><td style='padding: 5px 15px 5px 0;'>Passed:</td><td style='color: #16a34a;'><strong>{$summary['passed']} ✓</strong></td></tr>";
            echo "<tr><td style='padding: 5px 15px 5px 0;'>Failed:</td><td style='color: #dc2626;'><strong>{$summary['failed']} ✗</strong></td></tr>";
            echo "<tr><td style='padding: 5px 15px 5px 0;'>Pass Rate:</td><td><strong>{$summary['pass_rate']}%</strong></td></tr>";
            echo "<tr><td style='padding: 5px 15px 5px 0;'>Avg Duration:</td><td><strong>{$summary['avg_duration_ms']}ms</strong></td></tr>";
            echo "</table>";
            echo "</div>";
        }
    }

    /**
     * Get all results
     */
    public function getResults()
    {
        return $this->results;
    }
}

// =====================================================================
// MAIN EXECUTION
// =====================================================================

// Determine if running in CLI or browser
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>ClassEngage API Test</title>";
    echo "<style>body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }</style>";
    echo "</head><body>";
    echo "<h1>🧪 ClassEngage Web Services API Test Suite</h1>";
    echo "<p style='color: #666;'>Testing all API endpoints for the ClassEngage Clicker Service</p>";
}

// Check configuration
if ($config['token'] === 'YOUR_TOKEN_HERE') {
    if ($isCli) {
        echo "\n⚠️  ERROR: You must configure your web service token!\n";
        echo "Edit this file and update the \$config array with your token and test data.\n\n";
    } else {
        echo "<div style='background: #fef2f2; border: 2px solid #dc2626; padding: 20px; border-radius: 8px;'>";
        echo "<h2 style='color: #dc2626; margin-top: 0;'>⚠️ Configuration Required</h2>";
        echo "<p>You must edit this file and configure the following:</p>";
        echo "<ul>";
        echo "<li><strong>\$config['token']</strong> - Your web service token</li>";
        echo "<li><strong>\$config['classengageid']</strong> - A ClassEngage activity ID</li>";
        echo "<li><strong>\$config['sessionid']</strong> - An active session ID</li>";
        echo "<li><strong>\$config['userid']</strong> - A test student user ID</li>";
        echo "</ul>";
        echo "</div>";
    }
    exit(1);
}

// Create tester instance
$tester = new ClassEngageAPITester($config['wwwroot'], $config['token']);

// Run tests
try {
    // Prerequisite: Check token
    $tokenResult = $tester->testTokenValidity();
    if (!$tokenResult['success']) {
        echo $isCli
            ? "\n❌ Token validation failed. Please check your token configuration.\n"
            : "<div style='color: red; font-weight: bold;'>❌ Token validation failed. Cannot proceed.</div>";
        $tester->printSummary();
        exit(1);
    }

    // Test 1: Get Active Session
    $sessionResult = $tester->testGetActiveSession($config['classengageid']);

    // Use session from config or from the get_active_session response
    $activeSessionId = $config['sessionid'];
    if (
        $sessionResult['success'] &&
        isset($sessionResult['response']['hassession']) &&
        $sessionResult['response']['hassession'] &&
        isset($sessionResult['response']['sessionid'])
    ) {
        $activeSessionId = $sessionResult['response']['sessionid'];
    }

    // Test 2: Get Current Question
    $tester->testGetCurrentQuestion($activeSessionId);

    // Test 3: Register Clicker
    $tester->testRegisterClicker($config['userid'], $config['clickerid']);

    // Test 4: Submit Single Response
    // Note: This may fail if the user has already answered
    $tester->testSubmitClickerResponse(
        $activeSessionId,
        $config['userid'],
        $config['clickerid'],
        'B'
    );

    // Test 5: Submit Bulk Responses
    // Note: This requires the submitclicker capability
    $bulkResponses = [
        ['clickerid' => 'BULK001', 'answer' => 'A', 'userid' => $config['userid'] + 1],
        ['clickerid' => 'BULK002', 'answer' => 'C', 'userid' => $config['userid'] + 2],
    ];
    $tester->testSubmitBulkResponses($activeSessionId, $bulkResponses);

    // Test error handling
    $tester->testInvalidParameters();

} catch (Exception $e) {
    echo $isCli
        ? "\n❌ Unexpected error: " . $e->getMessage() . "\n"
        : "<div style='color: red;'>❌ Unexpected error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Print summary
$tester->printSummary();

if (!$isCli) {
    echo "</body></html>";
}

// Return exit code for CLI
if ($isCli) {
    $summary = $tester->getSummary();
    exit($summary['failed'] > 0 ? 1 : 0);
}
