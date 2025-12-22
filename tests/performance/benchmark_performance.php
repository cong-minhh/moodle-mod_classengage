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
 * ClassEngage Plugin Performance Benchmark Suite
 *
 * Tests the raw performance of ClassEngage core functions, bypassing
 * HTTP/Apache overhead. This measures your actual plugin code performance.
 *
 * Usage:
 *   php mod/classengage/tests/benchmark_performance.php
 *   php mod/classengage/tests/benchmark_performance.php --iterations=1000
 *   php mod/classengage/tests/benchmark_performance.php --xdebug
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');


global $DB, $CFG;

// CLI arguments
$options = getopt('', ['iterations:', 'xdebug', 'help', 'verbose', 'sessionid:', 'cleanup']);

if (isset($options['help'])) {
    echo <<<HELP
ClassEngage Plugin Performance Benchmark

USAGE:
  php benchmark_performance.php [OPTIONS]

OPTIONS:
  --iterations=N   Number of iterations per benchmark (default: 100)
  --sessionid=N    Session ID to use for tests (default: auto-detect)
  --xdebug         Enable XDebug profiling (generates cachegrind files)
  --cleanup        Clean up test data after benchmarks
  --verbose        Show detailed output
  --help           Show this help

BENCHMARKS RUN:
  1. Response submission (single)
  2. Response validation
  3. Duplicate detection
  4. Batch response submission
  5. Get current question
  6. Get session state
  7. Connection tracking
  8. Database queries (raw)

OUTPUT:
  Shows min/max/avg/p95 times in microseconds for each operation.

HELP;
    exit(0);
}

$iterations = (int) ($options['iterations'] ?? 100);
$verbose = isset($options['verbose']);
$enableXdebug = isset($options['xdebug']);
$cleanup = isset($options['cleanup']);
$targetSessionId = $options['sessionid'] ?? null;

// =====================================================================
// BENCHMARK RUNNER CLASS
// =====================================================================

class BenchmarkRunner
{
    private $results = [];
    private $verbose;

    public function __construct($verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Run a benchmark
     */
    public function benchmark(string $name, callable $fn, int $iterations, callable $setup = null, callable $teardown = null)
    {
        $times = [];

        // Warmup run
        if ($setup)
            $setup();
        $fn();
        if ($teardown)
            $teardown();

        // Actual benchmark runs
        for ($i = 0; $i < $iterations; $i++) {
            if ($setup)
                $setup();

            $start = hrtime(true); // Nanoseconds
            $fn();
            $end = hrtime(true);

            $times[] = ($end - $start) / 1000; // Convert to microseconds

            if ($teardown)
                $teardown();
        }

        $this->results[$name] = $this->calculateStats($times);

        if ($this->verbose) {
            $this->printBenchmark($name, $this->results[$name]);
        }

        return $this->results[$name];
    }

    /**
     * Calculate statistics from timing data
     */
    private function calculateStats(array $times)
    {
        sort($times);
        $count = count($times);

        return [
            'count' => $count,
            'min' => round($times[0], 2),
            'max' => round($times[$count - 1], 2),
            'avg' => round(array_sum($times) / $count, 2),
            'p50' => round($times[(int) ($count * 0.50)], 2),
            'p95' => round($times[(int) ($count * 0.95)], 2),
            'p99' => round($times[(int) ($count * 0.99)], 2),
            'total_ms' => round(array_sum($times) / 1000, 2),
        ];
    }

    /**
     * Print single benchmark result
     */
    private function printBenchmark(string $name, array $stats)
    {
        printf(
            "  ✓ %-40s avg: %8.2fµs (p95: %8.2fµs)\n",
            $name,
            $stats['avg'],
            $stats['p95']
        );
    }

    /**
     * Print full report
     */
    public function printReport()
    {
        echo "\n" . str_repeat('═', 80) . "\n";
        echo "  📊 CLASSENGAGE PERFORMANCE BENCHMARK RESULTS\n";
        echo str_repeat('═', 80) . "\n\n";

        echo "  ┌─────────────────────────────────────────┬───────────┬───────────┬───────────┐\n";
        echo "  │ Benchmark                               │   Avg(µs) │  P95(µs)  │  P99(µs)  │\n";
        echo "  ├─────────────────────────────────────────┼───────────┼───────────┼───────────┤\n";

        foreach ($this->results as $name => $stats) {
            printf(
                "  │ %-39s │ %9.2f │ %9.2f │ %9.2f │\n",
                substr($name, 0, 39),
                $stats['avg'],
                $stats['p95'],
                $stats['p99']
            );
        }

        echo "  └─────────────────────────────────────────┴───────────┴───────────┴───────────┘\n\n";

        // Summary
        $totalAvg = array_sum(array_column($this->results, 'avg'));
        echo "  📈 SUMMARY\n";
        echo "  ─────────────────────────────────────────────────────────────────────────────\n";
        printf("  Total average time (all operations): %.2fµs (%.4fms)\n", $totalAvg, $totalAvg / 1000);
        printf("  Operations benchmarked: %d\n", count($this->results));
        echo "\n";

        // Performance assessment
        $p95Max = max(array_column($this->results, 'p95'));
        if ($p95Max < 1000) {
            echo "  🟢 EXCELLENT: All operations under 1ms at P95\n";
        } else if ($p95Max < 10000) {
            echo "  🟡 GOOD: All operations under 10ms at P95\n";
        } else {
            echo "  🔴 NEEDS OPTIMIZATION: Some operations exceed 10ms at P95\n";
        }

        echo str_repeat('═', 80) . "\n\n";
    }

    /**
     * Get results as array
     */
    public function getResults()
    {
        return $this->results;
    }
}

// =====================================================================
// XDEBUG PROFILING HELPER
// =====================================================================

class XDebugProfiler
{
    private $enabled = false;
    private $outputDir;

    public function __construct($enable = false)
    {
        $this->outputDir = sys_get_temp_dir() . '/classengage_profiles';

        if ($enable) {
            if (!extension_loaded('xdebug')) {
                echo "⚠️  XDebug extension not loaded. Profiling disabled.\n";
                echo "   To enable, add to php.ini:\n";
                echo "   zend_extension=xdebug\n";
                echo "   xdebug.mode=profile\n";
                echo "   xdebug.output_dir={$this->outputDir}\n\n";
                return;
            }

            if (!is_dir($this->outputDir)) {
                mkdir($this->outputDir, 0777, true);
            }

            // Check if profiling mode is enabled
            if (function_exists('xdebug_info')) {
                $modes = xdebug_info('mode');
                if (!in_array('profile', $modes)) {
                    echo "⚠️  XDebug profiling mode not enabled.\n";
                    echo "   Add to php.ini: xdebug.mode=profile\n\n";
                    return;
                }
            }

            $this->enabled = true;
            echo "🔍 XDebug profiling enabled. Output: {$this->outputDir}\n\n";
        }
    }

    public function start($name)
    {
        if (!$this->enabled)
            return;

        if (function_exists('xdebug_start_trace')) {
            $filename = $this->outputDir . '/trace_' . preg_replace('/[^a-z0-9]/', '_', strtolower($name));
            xdebug_start_trace($filename);
        }
    }

    public function stop()
    {
        if (!$this->enabled)
            return;

        if (function_exists('xdebug_stop_trace')) {
            xdebug_stop_trace();
        }
    }

    public function getOutputDir()
    {
        return $this->outputDir;
    }
}

// =====================================================================
// MAIN BENCHMARK EXECUTION
// =====================================================================

echo "\n" . str_repeat('═', 80) . "\n";
echo "  🚀 ClassEngage Plugin Performance Benchmark\n";
echo str_repeat('═', 80) . "\n\n";

echo "  Configuration:\n";
echo "    • Iterations: $iterations\n";
echo "    • XDebug Profiling: " . ($enableXdebug ? "Enabled" : "Disabled") . "\n";
echo "    • Cleanup after: " . ($cleanup ? "Yes" : "No") . "\n";
echo "\n";

// Initialize profiler
$profiler = new XDebugProfiler($enableXdebug);

// Find a session to use for testing
if ($targetSessionId) {
    $session = $DB->get_record('classengage_sessions', ['id' => $targetSessionId]);
} else {
    $session = $DB->get_record_sql(
        "SELECT * FROM {classengage_sessions} WHERE status = 'active' ORDER BY id DESC LIMIT 1"
    );
    if (!$session) {
        $session = $DB->get_record_sql(
            "SELECT * FROM {classengage_sessions} ORDER BY id DESC LIMIT 1"
        );
    }
}

if (!$session) {
    echo "❌ No sessions found. Please create a ClassEngage session first.\n";
    exit(1);
}

echo "  Using session: #{$session->id} ({$session->name})\n\n";

// Get a question for testing
$sessionQuestion = $DB->get_record('classengage_session_questions', ['sessionid' => $session->id]);
if (!$sessionQuestion) {
    echo "❌ No questions found for session. Please add questions first.\n";
    exit(1);
}

$question = $DB->get_record('classengage_questions', ['id' => $sessionQuestion->questionid]);
if (!$question) {
    echo "❌ Question record not found.\n";
    exit(1);
}

echo "  Using question: #{$question->id}\n\n";

// Create benchmark runner
$runner = new BenchmarkRunner($verbose);

echo "  Running benchmarks...\n\n";

// =====================================================================
// BENCHMARK: Response Validation
// =====================================================================

$profiler->start('response_validation');

use mod_classengage\response_capture_engine;

$engine = new response_capture_engine();

$runner->benchmark('Response Validation (multichoice)', function () use ($engine) {
    $engine->validate_response('A', 'multichoice');
}, $iterations);

$runner->benchmark('Response Validation (truefalse)', function () use ($engine) {
    $engine->validate_response('true', 'truefalse');
}, $iterations);

$profiler->stop();

// =====================================================================
// BENCHMARK: Duplicate Detection
// =====================================================================

$profiler->start('duplicate_detection');

$runner->benchmark('Duplicate Detection Check', function () use ($engine, $session, $question) {
    $engine->is_duplicate($session->id, $question->id, 99999); // Non-existent user
}, $iterations);

$profiler->stop();

// =====================================================================
// BENCHMARK: Get Session Statistics
// =====================================================================

$profiler->start('get_session_statistics');

use mod_classengage\session_state_manager;

$stateManager = new session_state_manager();

$runner->benchmark('Get Session Statistics', function () use ($stateManager, $session) {
    $stateManager->get_session_statistics($session->id);
}, $iterations);

$profiler->stop();

// =====================================================================
// BENCHMARK: Get Session State
// =====================================================================

$profiler->start('get_session_state');

$runner->benchmark('Get Session State', function () use ($stateManager, $session) {
    $stateManager->get_session_state($session->id);
}, $iterations);

$profiler->stop();

// =====================================================================
// BENCHMARK: Response Submission (with cleanup)
// =====================================================================

$profiler->start('response_submission');

$testUserId = 99900; // High ID to avoid conflicts
$submittedResponseIds = [];

$runner->benchmark('Submit Single Response', function () use ($engine, $session, $question, &$testUserId, &$submittedResponseIds, $DB) {
    $testUserId++;
    $result = $engine->submit_response(
        $session->id,
        $question->id,
        'A',
        $testUserId,
        time()
    );
    if ($result->success && $result->responseid) {
        $submittedResponseIds[] = $result->responseid;
    }
}, min($iterations, 50)); // Limit to 50 to avoid too much DB noise

$profiler->stop();

// =====================================================================
// BENCHMARK: Database Operations (Raw)
// =====================================================================

$profiler->start('database_operations');

$runner->benchmark('DB: get_record (session)', function () use ($DB, $session) {
    $DB->get_record('classengage_sessions', ['id' => $session->id]);
}, $iterations);

$runner->benchmark('DB: get_records (responses)', function () use ($DB, $session, $question) {
    $DB->get_records('classengage_responses', [
        'sessionid' => $session->id,
        'questionid' => $question->id
    ], '', '*', 0, 10);
}, $iterations);

$runner->benchmark('DB: count_records', function () use ($DB, $session) {
    $DB->count_records('classengage_responses', ['sessionid' => $session->id]);
}, $iterations);

$runner->benchmark('DB: record_exists', function () use ($DB, $session, $question) {
    $DB->record_exists('classengage_responses', [
        'sessionid' => $session->id,
        'questionid' => $question->id,
        'userid' => 99999
    ]);
}, $iterations);

$profiler->stop();

// =====================================================================
// BENCHMARK: Connection Tracking
// =====================================================================

$profiler->start('connection_tracking');

$runner->benchmark('Register Connection', function () use ($stateManager, $session) {
    static $userId = 88800;
    static $connId = 0;
    $userId++;
    $connId++;
    $stateManager->register_connection($session->id, $userId, 'bench_' . $connId, 'sse');
}, min($iterations, 50));

$runner->benchmark('Get Connected Students', function () use ($stateManager, $session) {
    $stateManager->get_connected_students($session->id);
}, $iterations);

$profiler->stop();

// =====================================================================
// CLEANUP
// =====================================================================

if ($cleanup) {
    echo "\n  Cleaning up test data...\n";

    // Clean up test responses
    if (!empty($submittedResponseIds)) {
        list($inSql, $params) = $DB->get_in_or_equal($submittedResponseIds);
        $DB->delete_records_select('classengage_responses', "id $inSql", $params);
        echo "    ✓ Deleted " . count($submittedResponseIds) . " test responses\n";
    }

    // Clean up test connections
    $DB->delete_records_select('classengage_connections', 'userid >= 88800 AND userid < 99999');
    echo "    ✓ Cleaned up test connections\n";
}

// =====================================================================
// PRINT RESULTS
// =====================================================================

$runner->printReport();

// XDebug output location
if ($enableXdebug && extension_loaded('xdebug')) {
    echo "  📁 XDebug profiles saved to: " . $profiler->getOutputDir() . "\n";
    echo "     View with: kcachegrind <profile_file>\n\n";
}

// Output as JSON for CI
if (isset($options['json'])) {
    echo json_encode($runner->getResults(), JSON_PRETTY_PRINT) . "\n";
}

echo "  ✅ Benchmarks complete!\n\n";
