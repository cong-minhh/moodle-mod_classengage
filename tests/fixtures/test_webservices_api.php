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
 * Enterprise-level API Test & Stress Test Suite for ClassEngage Web Services
 *
 * Features:
 * - Functional endpoint testing
 * - Concurrent load/stress testing with curl_multi
 * - Enterprise metrics (P50, P95, P99, throughput, error rates)
 * - Configurable test parameters via CLI
 * - Professional reporting with detailed statistics
 *
 * Usage:
 *   # Functional tests only
 *   php test_webservices_api.php
 *
 *   # Stress test with 100 concurrent requests
 *   php test_webservices_api.php --stress --concurrent=100
 *
 *   # Full stress test with custom parameters
 *   php test_webservices_api.php --stress --concurrent=200 --duration=60 --rps=50
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// =====================================================================
// CLI ARGUMENT PARSING
// =====================================================================

$options = getopt('', [
    'help',
    'stress',
    'functional',
    'concurrent:',
    'duration:',
    'rps:',
    'endpoint:',
    'verbose',
    'quiet',
    'json',
    'sessionid:',
    'classengageid:',
    'token:',
]);

if (isset($options['help'])) {
    echo <<<HELP
ClassEngage API Test Suite - Enterprise Edition

USAGE:
  php test_webservices_api.php [OPTIONS]

MODES:
  (default)      Run functional tests only
  --stress       Run stress/load tests
  --functional   Run functional tests (default)

STRESS TEST OPTIONS:
  --concurrent=N    Number of concurrent requests (default: 50)
  --duration=N      Test duration in seconds (default: 30)
  --rps=N           Target requests per second, 0=unlimited (default: 0)
  --endpoint=NAME   Specific endpoint to stress test (default: get_active_session)
                    Options: get_active_session, get_current_question, 
                             submit_response, register_clicker

CONFIGURATION:
  --sessionid=N     Session ID for tests (default: 1)
  --classengageid=N ClassEngage activity ID (default: 1)
  --token=TOKEN     Web service token (overrides config)

OUTPUT:
  --verbose         Show detailed output for each request
  --quiet           Minimal output, only show summary
  --json            Output results as JSON (for CI/CD integration)

EXAMPLES:
  # Run functional tests
  php test_webservices_api.php

  # Stress test with 100 concurrent users for 60 seconds
  php test_webservices_api.php --stress --concurrent=100 --duration=60

  # Rate-limited stress test at 50 requests/second
  php test_webservices_api.php --stress --rps=50 --duration=30

  # Stress test specific endpoint
  php test_webservices_api.php --stress --endpoint=submit_response --concurrent=200

HELP;
    exit(0);
}

// =====================================================================
// CONFIGURATION
// =====================================================================

$isDocker = file_exists('/.dockerenv') || (getenv('DOCKER_CONTAINER') !== false);

$config = [
    // Inside Docker: use localhost (direct Apache) instead of host.docker.internal (network bridge)
    // Outside Docker: use localhost:8000 (host machine)
    'wwwroot' => $isDocker ? 'http://localhost' : 'http://localhost:8000',
    'token' => $options['token'] ?? 'de3595043573dd6aee310b659f3878aa',
    'classengageid' => (int) ($options['classengageid'] ?? 1),
    'sessionid' => (int) ($options['sessionid'] ?? 1),
    'userid' => 4,
    'clickerid' => 'STRESS_TEST',

    // Stress test settings
    'concurrent' => (int) ($options['concurrent'] ?? 50),
    'duration' => (int) ($options['duration'] ?? 30),
    'rps' => (int) ($options['rps'] ?? 0),
    'endpoint' => $options['endpoint'] ?? 'get_active_session',

    // Output settings
    'verbose' => isset($options['verbose']),
    'quiet' => isset($options['quiet']),
    'json' => isset($options['json']),
    'stress' => isset($options['stress']),
];

// =====================================================================
// STATISTICS CALCULATOR CLASS
// =====================================================================

class StatsCalculator
{
    private $data = [];

    public function add($value)
    {
        $this->data[] = $value;
    }

    public function addBatch(array $values)
    {
        $this->data = array_merge($this->data, $values);
    }

    public function count()
    {
        return count($this->data);
    }

    public function sum()
    {
        return array_sum($this->data);
    }

    public function min()
    {
        return count($this->data) > 0 ? min($this->data) : 0;
    }

    public function max()
    {
        return count($this->data) > 0 ? max($this->data) : 0;
    }

    public function avg()
    {
        return count($this->data) > 0 ? array_sum($this->data) / count($this->data) : 0;
    }

    public function percentile($p)
    {
        if (count($this->data) === 0) {
            return 0;
        }

        $sorted = $this->data;
        sort($sorted);

        $index = ($p / 100) * (count($sorted) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $sorted[(int) $lower];
        }

        return $sorted[(int) $lower] + ($index - $lower) * ($sorted[(int) $upper] - $sorted[(int) $lower]);
    }

    public function stddev()
    {
        if (count($this->data) < 2) {
            return 0;
        }

        $avg = $this->avg();
        $sumSquares = array_reduce($this->data, function ($carry, $val) use ($avg) {
            return $carry + pow($val - $avg, 2);
        }, 0);

        return sqrt($sumSquares / (count($this->data) - 1));
    }

    public function getFullStats()
    {
        return [
            'count' => $this->count(),
            'min' => round($this->min(), 2),
            'max' => round($this->max(), 2),
            'avg' => round($this->avg(), 2),
            'stddev' => round($this->stddev(), 2),
            'p50' => round($this->percentile(50), 2),
            'p75' => round($this->percentile(75), 2),
            'p90' => round($this->percentile(90), 2),
            'p95' => round($this->percentile(95), 2),
            'p99' => round($this->percentile(99), 2),
        ];
    }
}

// =====================================================================
// API TESTER CLASS
// =====================================================================

class ClassEngageAPITester
{
    private $wwwroot;
    private $token;
    private $results = [];
    private $verbose = false;
    private $quiet = false;
    private $isDocker;

    public function __construct($wwwroot, $token)
    {
        $this->wwwroot = rtrim($wwwroot, '/');
        $this->token = $token;
        $this->isDocker = file_exists('/.dockerenv') || (getenv('DOCKER_CONTAINER') !== false);
    }

    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    public function setQuiet($quiet)
    {
        $this->quiet = $quiet;
    }

    /**
     * Make a single API call
     */
    public function callAPI($function, $params = [], $recordResult = true)
    {
        $url = $this->wwwroot . '/webservice/rest/server.php';
        $postdata = array_merge([
            'wstoken' => $this->token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ], $params);

        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postdata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($this->isDocker) {
            $curlOpts[CURLOPT_HTTPHEADER] = ['Host: localhost:8000'];
        }

        curl_setopt_array($ch, $curlOpts);

        $starttime = microtime(true);
        $response = curl_exec($ch);
        $duration = (microtime(true) - $starttime) * 1000;

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = [
            'function' => $function,
            'http_code' => $httpcode,
            'duration_ms' => round($duration, 2),
            'success' => false,
            'response' => null,
            'error' => null,
            'timestamp' => microtime(true),
        ];

        if ($error) {
            $result['error'] = "cURL error: $error";
        } else if ($httpcode !== 200) {
            $result['error'] = "HTTP error: $httpcode";
        } else {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['error'] = "JSON decode error: " . json_last_error_msg();
            } else if (isset($decoded['exception'])) {
                $result['error'] = $decoded['message'] ?? $decoded['exception'];
                $result['response'] = $decoded;
            } else {
                $result['success'] = true;
                $result['response'] = $decoded;
            }
        }

        if ($recordResult) {
            $this->results[] = $result;
        }

        return $result;
    }

    /**
     * Execute concurrent requests using curl_multi
     */
    public function executeConcurrent($requests, $maxConcurrent = 50)
    {
        $results = [];
        $multiHandle = curl_multi_init();
        $handles = [];
        $startTimes = [];

        $url = $this->wwwroot . '/webservice/rest/server.php';

        // Process requests in batches
        $batches = array_chunk($requests, $maxConcurrent);

        foreach ($batches as $batch) {
            $handles = [];
            $startTimes = [];

            foreach ($batch as $idx => $request) {
                $postdata = array_merge([
                    'wstoken' => $this->token,
                    'wsfunction' => $request['function'],
                    'moodlewsrestformat' => 'json',
                ], $request['params'] ?? []);

                $ch = curl_init();
                $curlOpts = [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($postdata),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => false,
                ];

                if ($this->isDocker) {
                    $curlOpts[CURLOPT_HTTPHEADER] = ['Host: localhost:8000'];
                }

                curl_setopt_array($ch, $curlOpts);
                curl_multi_add_handle($multiHandle, $ch);
                $handles[(int) $ch] = [
                    'handle' => $ch,
                    'request' => $request,
                ];
                $startTimes[(int) $ch] = microtime(true);
            }

            // Execute all handles
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);

            // Collect results
            foreach ($handles as $id => $data) {
                $ch = $data['handle'];
                $request = $data['request'];
                $duration = (microtime(true) - $startTimes[$id]) * 1000;

                $response = curl_multi_getcontent($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);

                $result = [
                    'function' => $request['function'],
                    'http_code' => $httpcode,
                    'duration_ms' => round($duration, 2),
                    'success' => false,
                    'error' => null,
                    'timestamp' => microtime(true),
                ];

                if ($error) {
                    $result['error'] = "cURL error: $error";
                } else if ($httpcode !== 200) {
                    $result['error'] = "HTTP error: $httpcode";
                } else {
                    $decoded = json_decode($response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $result['error'] = "JSON decode error";
                    } else if (isset($decoded['exception'])) {
                        $result['error'] = $decoded['message'] ?? $decoded['exception'];
                    } else {
                        $result['success'] = true;
                    }
                }

                $results[] = $result;
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    // =====================================================================
    // FUNCTIONAL TEST METHODS
    // =====================================================================

    public function testGetActiveSession($classengageid)
    {
        $this->printHeader('Test: Get Active Session');
        $result = $this->callAPI('mod_classengage_get_active_session', [
            'classengageid' => $classengageid,
        ]);
        $this->printResult($result);
        return $result;
    }

    public function testGetCurrentQuestion($sessionid)
    {
        $this->printHeader('Test: Get Current Question');
        $result = $this->callAPI('mod_classengage_get_current_question', [
            'sessionid' => $sessionid,
        ]);
        $this->printResult($result);
        return $result;
    }

    public function testRegisterClicker($userid, $clickerid)
    {
        $this->printHeader('Test: Register Clicker');
        $result = $this->callAPI('mod_classengage_register_clicker', [
            'userid' => $userid,
            'clickerid' => $clickerid,
        ]);
        $this->printResult($result);
        return $result;
    }

    public function testSubmitResponse($sessionid, $userid, $clickerid, $answer)
    {
        $this->printHeader('Test: Submit Response');
        $result = $this->callAPI('mod_classengage_submit_clicker_response', [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'clickerid' => $clickerid,
            'answer' => $answer,
            'timestamp' => time(),
        ]);
        $this->printResult($result);
        return $result;
    }

    public function testBulkResponses($sessionid, $responses)
    {
        $this->printHeader('Test: Bulk Response Submission');

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
        return $result;
    }

    // =====================================================================
    // OUTPUT METHODS
    // =====================================================================

    private function printHeader($title)
    {
        if ($this->quiet)
            return;

        $isCli = php_sapi_name() === 'cli';
        if ($isCli) {
            echo "\n" . str_repeat('─', 60) . "\n";
            echo "  $title\n";
            echo str_repeat('─', 60) . "\n";
        }
    }

    private function printResult($result)
    {
        if ($this->quiet)
            return;

        $status = $result['success'] ? '✓ PASS' : '✗ FAIL';
        $color = $result['success'] ? "\033[32m" : "\033[31m";
        $reset = "\033[0m";

        echo "{$color}{$status}{$reset} - {$result['function']} ({$result['duration_ms']}ms)\n";

        if (!$result['success'] && $result['error']) {
            echo "\033[33m   Error: {$result['error']}{$reset}\n";
        }

        if ($this->verbose && $result['response']) {
            $json = json_encode($result['response'], JSON_PRETTY_PRINT);
            echo "   Response: " . substr($json, 0, 200) . (strlen($json) > 200 ? '...' : '') . "\n";
        }
    }

    public function getSummary()
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, fn($r) => $r['success']));
        $durations = array_column($this->results, 'duration_ms');

        $stats = new StatsCalculator();
        $stats->addBatch($durations);

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0,
            'latency' => $stats->getFullStats(),
        ];
    }

    public function printSummary()
    {
        $summary = $this->getSummary();

        echo "\n" . str_repeat('═', 60) . "\n";
        echo "  📊 FUNCTIONAL TEST SUMMARY\n";
        echo str_repeat('═', 60) . "\n";
        echo "  Total Tests:     {$summary['total']}\n";
        echo "  Passed:          \033[32m{$summary['passed']} ✓\033[0m\n";
        echo "  Failed:          \033[31m{$summary['failed']} ✗\033[0m\n";
        echo "  Pass Rate:       {$summary['pass_rate']}%\n";
        echo "  Avg Latency:     {$summary['latency']['avg']}ms\n";
        echo str_repeat('═', 60) . "\n";
    }

    public function getResults()
    {
        return $this->results;
    }

    public function clearResults()
    {
        $this->results = [];
    }
}

// =====================================================================
// STRESS TESTER CLASS
// =====================================================================

class StressTestRunner
{
    private $tester;
    private $config;
    private $results = [];
    private $errors = [];
    private $startTime;
    private $endTime;

    public function __construct(ClassEngageAPITester $tester, array $config)
    {
        $this->tester = $tester;
        $this->config = $config;
    }

    /**
     * Generate test requests based on endpoint
     */
    private function generateRequests($count, $endpoint)
    {
        $requests = [];
        $answers = ['A', 'B', 'C', 'D'];

        for ($i = 0; $i < $count; $i++) {
            switch ($endpoint) {
                case 'get_active_session':
                    $requests[] = [
                        'function' => 'mod_classengage_get_active_session',
                        'params' => ['classengageid' => $this->config['classengageid']],
                    ];
                    break;

                case 'get_current_question':
                    $requests[] = [
                        'function' => 'mod_classengage_get_current_question',
                        'params' => ['sessionid' => $this->config['sessionid']],
                    ];
                    break;

                case 'submit_response':
                    $requests[] = [
                        'function' => 'mod_classengage_submit_clicker_response',
                        'params' => [
                            'sessionid' => $this->config['sessionid'],
                            'userid' => $this->config['userid'] + $i,
                            'clickerid' => 'STRESS_' . str_pad($i, 5, '0', STR_PAD_LEFT),
                            'answer' => $answers[array_rand($answers)],
                            'timestamp' => time(),
                        ],
                    ];
                    break;

                case 'register_clicker':
                    $requests[] = [
                        'function' => 'mod_classengage_register_clicker',
                        'params' => [
                            'userid' => $this->config['userid'] + $i,
                            'clickerid' => 'STRESS_' . str_pad($i, 5, '0', STR_PAD_LEFT),
                        ],
                    ];
                    break;

                default:
                    $requests[] = [
                        'function' => 'mod_classengage_get_active_session',
                        'params' => ['classengageid' => $this->config['classengageid']],
                    ];
            }
        }

        return $requests;
    }

    /**
     * Run the stress test
     */
    public function run()
    {
        $concurrent = $this->config['concurrent'];
        $duration = $this->config['duration'];
        $rps = $this->config['rps'];
        $endpoint = $this->config['endpoint'];
        $quiet = $this->config['quiet'];

        if (!$quiet) {
            echo "\n" . str_repeat('═', 70) . "\n";
            echo "  🔥 STRESS TEST - Enterprise Load Testing Suite\n";
            echo str_repeat('═', 70) . "\n";
            echo "  Configuration:\n";
            echo "    • Concurrent Requests: $concurrent\n";
            echo "    • Duration: {$duration}s\n";
            echo "    • Rate Limit: " . ($rps > 0 ? "{$rps} req/s" : "Unlimited") . "\n";
            echo "    • Target Endpoint: $endpoint\n";
            echo str_repeat('─', 70) . "\n\n";
        }

        $this->startTime = microtime(true);
        $this->results = [];
        $this->errors = [];

        $iterationCount = 0;
        $totalRequests = 0;
        $targetEndTime = $this->startTime + $duration;

        // Progress tracking
        $lastProgressTime = $this->startTime;
        $progressInterval = 5; // Update every 5 seconds

        while (microtime(true) < $targetEndTime) {
            $iterationStart = microtime(true);

            // Rate limiting
            if ($rps > 0) {
                $requestsThisSecond = min($concurrent, $rps);
                $requests = $this->generateRequests($requestsThisSecond, $endpoint);
            } else {
                $requests = $this->generateRequests($concurrent, $endpoint);
            }

            // Execute concurrent batch
            $batchResults = $this->tester->executeConcurrent($requests, $concurrent);
            $this->results = array_merge($this->results, $batchResults);
            $totalRequests += count($batchResults);

            // Count errors
            foreach ($batchResults as $result) {
                if (!$result['success']) {
                    $errorKey = $result['error'] ?? 'Unknown error';
                    $this->errors[$errorKey] = ($this->errors[$errorKey] ?? 0) + 1;
                }
            }

            // Progress update
            $now = microtime(true);
            if (!$quiet && ($now - $lastProgressTime) >= $progressInterval) {
                $elapsed = round($now - $this->startTime);
                $successCount = count(array_filter($batchResults, fn($r) => $r['success']));
                $currentRps = $totalRequests / max(1, $now - $this->startTime);
                echo sprintf(
                    "  [%3ds] Requests: %6d | Success Rate: %5.1f%% | RPS: %6.1f\n",
                    $elapsed,
                    $totalRequests,
                    count(array_filter($this->results, fn($r) => $r['success'])) / max(1, count($this->results)) * 100,
                    $currentRps
                );
                $lastProgressTime = $now;
            }

            // Rate limiting delay
            if ($rps > 0) {
                $iterationDuration = microtime(true) - $iterationStart;
                $targetDuration = 1.0; // 1 second per RPS iteration
                if ($iterationDuration < $targetDuration) {
                    usleep((int) (($targetDuration - $iterationDuration) * 1000000));
                }
            }

            $iterationCount++;
        }

        $this->endTime = microtime(true);

        return $this->generateReport();
    }

    /**
     * Generate comprehensive stress test report
     */
    private function generateReport()
    {
        $duration = $this->endTime - $this->startTime;
        $totalRequests = count($this->results);
        $successCount = count(array_filter($this->results, fn($r) => $r['success']));
        $failCount = $totalRequests - $successCount;

        // Calculate latency statistics
        $latencies = array_column($this->results, 'duration_ms');
        $latencyStats = new StatsCalculator();
        $latencyStats->addBatch($latencies);

        // Calculate throughput
        $throughput = $totalRequests / max(0.001, $duration);
        $successThroughput = $successCount / max(0.001, $duration);

        // Error breakdown
        $errorBreakdown = $this->errors;
        arsort($errorBreakdown);

        // NFR compliance checks
        $nfrCompliance = [
            'latency_p95_under_1s' => $latencyStats->percentile(95) < 1000,
            'latency_p99_under_2s' => $latencyStats->percentile(99) < 2000,
            'success_rate_above_95' => ($successCount / max(1, $totalRequests)) >= 0.95,
            'success_rate_above_99' => ($successCount / max(1, $totalRequests)) >= 0.99,
        ];

        $report = [
            'summary' => [
                'test_duration_seconds' => round($duration, 2),
                'total_requests' => $totalRequests,
                'successful_requests' => $successCount,
                'failed_requests' => $failCount,
                'success_rate_percent' => round(($successCount / max(1, $totalRequests)) * 100, 2),
                'error_rate_percent' => round(($failCount / max(1, $totalRequests)) * 100, 2),
            ],
            'throughput' => [
                'total_rps' => round($throughput, 2),
                'successful_rps' => round($successThroughput, 2),
                'requests_per_minute' => round($throughput * 60, 0),
            ],
            'latency_ms' => $latencyStats->getFullStats(),
            'errors' => [
                'total_errors' => $failCount,
                'unique_error_types' => count($errorBreakdown),
                'breakdown' => array_slice($errorBreakdown, 0, 10), // Top 10 errors
            ],
            'nfr_compliance' => $nfrCompliance,
            'test_config' => [
                'concurrent_users' => $this->config['concurrent'],
                'target_duration' => $this->config['duration'],
                'rate_limit_rps' => $this->config['rps'],
                'endpoint_tested' => $this->config['endpoint'],
            ],
        ];

        return $report;
    }

    /**
     * Print the stress test report
     */
    public function printReport($report, $asJson = false)
    {
        if ($asJson) {
            echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
            return;
        }

        $s = $report['summary'];
        $t = $report['throughput'];
        $l = $report['latency_ms'];
        $e = $report['errors'];
        $n = $report['nfr_compliance'];

        echo "\n" . str_repeat('═', 70) . "\n";
        echo "  📈 STRESS TEST REPORT\n";
        echo str_repeat('═', 70) . "\n\n";

        // Summary Section
        echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
        echo "  │ SUMMARY                                                         │\n";
        echo "  ├─────────────────────────────────────────────────────────────────┤\n";
        printf("  │ Test Duration:      %10.2f seconds                         │\n", $s['test_duration_seconds']);
        printf("  │ Total Requests:     %10d                                  │\n", $s['total_requests']);
        printf("  │ Successful:         %10d (%5.1f%%)                         │\n", $s['successful_requests'], $s['success_rate_percent']);
        printf("  │ Failed:             %10d (%5.1f%%)                         │\n", $s['failed_requests'], $s['error_rate_percent']);
        echo "  └─────────────────────────────────────────────────────────────────┘\n\n";

        // Throughput Section
        echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
        echo "  │ THROUGHPUT                                                      │\n";
        echo "  ├─────────────────────────────────────────────────────────────────┤\n";
        printf("  │ Total RPS:          %10.2f req/sec                          │\n", $t['total_rps']);
        printf("  │ Successful RPS:     %10.2f req/sec                          │\n", $t['successful_rps']);
        printf("  │ Per Minute:         %10d requests                         │\n", $t['requests_per_minute']);
        echo "  └─────────────────────────────────────────────────────────────────┘\n\n";

        // Latency Section
        echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
        echo "  │ LATENCY (milliseconds)                                          │\n";
        echo "  ├─────────────────────────────────────────────────────────────────┤\n";
        printf("  │ Min:      %8.2f ms    │    P50:     %8.2f ms              │\n", $l['min'], $l['p50']);
        printf("  │ Max:      %8.2f ms    │    P75:     %8.2f ms              │\n", $l['max'], $l['p75']);
        printf("  │ Avg:      %8.2f ms    │    P90:     %8.2f ms              │\n", $l['avg'], $l['p90']);
        printf("  │ StdDev:   %8.2f ms    │    P95:     %8.2f ms              │\n", $l['stddev'], $l['p95']);
        printf("  │                          │    P99:     %8.2f ms              │\n", $l['p99']);
        echo "  └─────────────────────────────────────────────────────────────────┘\n\n";

        // NFR Compliance
        echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
        echo "  │ NFR COMPLIANCE                                                  │\n";
        echo "  ├─────────────────────────────────────────────────────────────────┤\n";
        $icon = $n['latency_p95_under_1s'] ? '✅' : '❌';
        echo "  │ {$icon} P95 Latency < 1 second                                   │\n";
        $icon = $n['latency_p99_under_2s'] ? '✅' : '❌';
        echo "  │ {$icon} P99 Latency < 2 seconds                                  │\n";
        $icon = $n['success_rate_above_95'] ? '✅' : '❌';
        echo "  │ {$icon} Success Rate ≥ 95%                                       │\n";
        $icon = $n['success_rate_above_99'] ? '✅' : '❌';
        echo "  │ {$icon} Success Rate ≥ 99%                                       │\n";
        echo "  └─────────────────────────────────────────────────────────────────┘\n\n";

        // Error Breakdown (if any)
        if ($e['total_errors'] > 0) {
            echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
            echo "  │ ERROR BREAKDOWN (Top 10)                                        │\n";
            echo "  ├─────────────────────────────────────────────────────────────────┤\n";
            foreach ($e['breakdown'] as $error => $count) {
                $shortError = strlen($error) > 45 ? substr($error, 0, 42) . '...' : $error;
                printf("  │ %5d │ %-55s │\n", $count, $shortError);
            }
            echo "  └─────────────────────────────────────────────────────────────────┘\n";
        }

        echo "\n" . str_repeat('═', 70) . "\n";

        // Overall Assessment
        $allPassed = $n['latency_p95_under_1s'] && $n['success_rate_above_95'];
        if ($allPassed) {
            echo "  \033[32m🎉 ALL CRITICAL NFR REQUIREMENTS PASSED\033[0m\n";
        } else {
            echo "  \033[31m⚠️  SOME NFR REQUIREMENTS FAILED - Review results above\033[0m\n";
        }
        echo str_repeat('═', 70) . "\n\n";
    }
}

// =====================================================================
// MAIN EXECUTION
// =====================================================================

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    die("This script must be run from the command line.\n");
}

// Validate token
if (empty($config['token']) || $config['token'] === 'YOUR_TOKEN_HERE') {
    echo "\n⚠️  ERROR: You must configure your web service token!\n";
    echo "Use --token=YOUR_TOKEN or edit the script configuration.\n\n";
    exit(1);
}

// Create tester instance
$tester = new ClassEngageAPITester($config['wwwroot'], $config['token']);
$tester->setVerbose($config['verbose']);
$tester->setQuiet($config['quiet']);

if ($config['stress']) {
    // =====================================================================
    // STRESS TEST MODE
    // =====================================================================

    // Quick connectivity check first
    if (!$config['quiet']) {
        echo "\n🔌 Checking API connectivity...\n";
    }

    $checkResult = $tester->callAPI('mod_classengage_get_active_session', [
        'classengageid' => $config['classengageid'],
    ], false);

    if (!$checkResult['success']) {
        echo "\n❌ API connectivity check failed!\n";
        echo "   Error: {$checkResult['error']}\n";
        echo "   Please verify your token and configuration.\n\n";
        exit(1);
    }

    if (!$config['quiet']) {
        echo "✅ API is accessible. Starting stress test...\n";
    }

    $stressRunner = new StressTestRunner($tester, $config);
    $report = $stressRunner->run();
    $stressRunner->printReport($report, $config['json']);

    // Exit code based on NFR compliance
    $passed = $report['nfr_compliance']['latency_p95_under_1s'] &&
        $report['nfr_compliance']['success_rate_above_95'];
    exit($passed ? 0 : 1);

} else {
    // =====================================================================
    // FUNCTIONAL TEST MODE
    // =====================================================================

    if (!$config['quiet']) {
        echo "\n" . str_repeat('═', 60) . "\n";
        echo "  🧪 ClassEngage API Functional Test Suite\n";
        echo str_repeat('═', 60) . "\n";
    }

    try {
        // Test 1: Get Active Session (also validates token)
        $sessionResult = $tester->testGetActiveSession($config['classengageid']);

        if (!$sessionResult['success']) {
            echo "\n❌ Initial connectivity test failed. Check your configuration.\n";
            $tester->printSummary();
            exit(1);
        }

        $activeSessionId = $config['sessionid'];
        if ($sessionResult['response']['hassession'] ?? false) {
            $activeSessionId = $sessionResult['response']['sessionid'];
        }

        // Test 2: Get Current Question
        $tester->testGetCurrentQuestion($activeSessionId);

        // Test 3: Register Clicker
        $tester->testRegisterClicker($config['userid'], $config['clickerid']);

        // Test 4: Submit Response
        $tester->testSubmitResponse(
            $activeSessionId,
            $config['userid'],
            $config['clickerid'],
            'B'
        );

        // Test 5: Bulk Responses
        $bulkResponses = [
            ['clickerid' => 'BULK001', 'answer' => 'A', 'userid' => $config['userid'] + 1],
            ['clickerid' => 'BULK002', 'answer' => 'C', 'userid' => $config['userid'] + 2],
        ];
        $tester->testBulkResponses($activeSessionId, $bulkResponses);

    } catch (Exception $e) {
        echo "\n❌ Unexpected error: " . $e->getMessage() . "\n";
    }

    // Print summary
    $tester->printSummary();

    // Output JSON if requested
    if ($config['json']) {
        echo "\n" . json_encode($tester->getSummary(), JSON_PRETTY_PRINT) . "\n";
    }

    // Exit code based on test results
    $summary = $tester->getSummary();
    exit($summary['failed'] > 0 ? 1 : 0);
}
