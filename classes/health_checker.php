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
 * Health Checker for ClassEngage plugin
 *
 * Provides enterprise monitoring capabilities including database connectivity,
 * cache health, SSE capability, and system metrics for operational dashboards.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Health checker class for enterprise monitoring
 */
class health_checker {
    /** @var int Activity instance ID (optional) */
    private ?int $classengageid;

    /**
     * Constructor
     *
     * @param int|null $classengageid Optional activity ID for activity-specific checks
     */
    public function __construct(?int $classengageid = null) {
        $this->classengageid = $classengageid;
    }

    /**
     * Run all health checks
     *
     * @return array Array of health_check_result objects
     */
    public function run_all_checks(): array {
        return [
            $this->check_database(),
            $this->check_cache(),
            $this->check_sse_capability(),
            $this->check_tables(),
            $this->check_disk_space(),
            $this->check_memory(),
        ];
    }

    /**
     * Check database connectivity and performance
     *
     * @return health_check_result
     */
    public function check_database(): health_check_result {
        global $DB;

        $start = microtime(true);

        try {
            // Test basic connectivity.
            $result = $DB->get_record_sql("SELECT 1 as test");
            $elapsed = (microtime(true) - $start) * 1000;

            if ($result && $result->test == 1) {
                // Test classengage table access.
                $tablestart = microtime(true);
                $count = $DB->count_records('classengage');
                $tableelapsed = (microtime(true) - $tablestart) * 1000;

                return new health_check_result(
                    'database',
                    true,
                    'Database connection healthy',
                    $elapsed,
                    [
                        'test_query_ms' => round($elapsed, 2),
                        'table_query_ms' => round($tableelapsed, 2),
                        'classengage_count' => $count,
                    ]
                );
            }

            return new health_check_result(
                'database',
                false,
                'Database query returned unexpected result',
                $elapsed
            );

        } catch (\Exception $e) {
            $elapsed = (microtime(true) - $start) * 1000;
            return new health_check_result(
                'database',
                false,
                'Database error: ' . $e->getMessage(),
                $elapsed
            );
        }
    }

    /**
     * Check cache system health
     *
     * @return health_check_result
     */
    public function check_cache(): health_check_result {
        $start = microtime(true);

        try {
            // Test each cache definition.
            $cachedefs = [
                'response_stats',
                'session_state',
                'connection_status',
                'question_broadcast',
            ];

            $results = [];
            $allhealthy = true;

            foreach ($cachedefs as $def) {
                try {
                    $cache = \cache::make('mod_classengage', $def);
                    $testkey = 'health_check_' . time();
                    $testvalue = ['test' => true, 'timestamp' => time()];

                    // Test set.
                    $cache->set($testkey, $testvalue);

                    // Test get.
                    $retrieved = $cache->get($testkey);

                    // Test delete.
                    $cache->delete($testkey);

                    $results[$def] = [
                        'healthy' => ($retrieved && $retrieved['test'] === true),
                        'error' => null,
                    ];

                    if (!$results[$def]['healthy']) {
                        $allhealthy = false;
                    }

                } catch (\Exception $e) {
                    $results[$def] = [
                        'healthy' => false,
                        'error' => $e->getMessage(),
                    ];
                    $allhealthy = false;
                }
            }

            $elapsed = (microtime(true) - $start) * 1000;

            return new health_check_result(
                'cache',
                $allhealthy,
                $allhealthy ? 'All caches operational' : 'Some caches failed',
                $elapsed,
                ['caches' => $results]
            );

        } catch (\Exception $e) {
            $elapsed = (microtime(true) - $start) * 1000;
            return new health_check_result(
                'cache',
                false,
                'Cache system error: ' . $e->getMessage(),
                $elapsed
            );
        }
    }

    /**
     * Check SSE capability
     *
     * @return health_check_result
     */
    public function check_sse_capability(): health_check_result {
        $start = microtime(true);

        $checks = [
            'output_buffering_controllable' => true,
            'set_time_limit_available' => function_exists('set_time_limit'),
            'ignore_user_abort_available' => function_exists('ignore_user_abort'),
            'flush_available' => function_exists('flush'),
        ];

        // Check if output buffering can be controlled.
        $oblevel = ob_get_level();
        $checks['current_ob_level'] = $oblevel;

        // Check for common issues.
        $issues = [];

        if (!$checks['set_time_limit_available']) {
            $issues[] = 'set_time_limit not available (may be disabled in safe mode)';
        }

        // Check if gzip compression might interfere.
        if (ini_get('zlib.output_compression')) {
            $checks['zlib_compression'] = true;
            $issues[] = 'zlib.output_compression is enabled (may need to disable for SSE)';
        } else {
            $checks['zlib_compression'] = false;
        }

        $elapsed = (microtime(true) - $start) * 1000;
        $healthy = empty($issues) || count($issues) < 2;

        return new health_check_result(
            'sse_capability',
            $healthy,
            $healthy ? 'SSE capable' : 'SSE may have issues',
            $elapsed,
            [
                'checks' => $checks,
                'issues' => $issues,
            ]
        );
    }

    /**
     * Check database tables exist and have expected structure
     *
     * @return health_check_result
     */
    public function check_tables(): health_check_result {
        global $DB;

        $start = microtime(true);

        $requiredtables = [
            'classengage',
            'classengage_slides',
            'classengage_questions',
            'classengage_sessions',
            'classengage_session_questions',
            'classengage_responses',
            'classengage_connections',
            'classengage_response_queue',
            'classengage_session_log',
            'classengage_clicker_devices',
        ];

        $dbman = $DB->get_manager();
        $results = [];
        $allexist = true;

        foreach ($requiredtables as $table) {
            $exists = $dbman->table_exists($table);
            $results[$table] = $exists;
            if (!$exists) {
                $allexist = false;
            }
        }

        $elapsed = (microtime(true) - $start) * 1000;

        return new health_check_result(
            'tables',
            $allexist,
            $allexist ? 'All required tables exist' : 'Missing required tables',
            $elapsed,
            ['tables' => $results]
        );
    }

    /**
     * Check available disk space
     *
     * @return health_check_result
     */
    public function check_disk_space(): health_check_result {
        global $CFG;

        $start = microtime(true);

        try {
            $dataroot = $CFG->dataroot;
            $freebytes = disk_free_space($dataroot);
            $totalbytes = disk_total_space($dataroot);

            if ($freebytes === false || $totalbytes === false) {
                return new health_check_result(
                    'disk_space',
                    false,
                    'Unable to determine disk space',
                    (microtime(true) - $start) * 1000
                );
            }

            $freegb = round($freebytes / (1024 * 1024 * 1024), 2);
            $totalgb = round($totalbytes / (1024 * 1024 * 1024), 2);
            $usedpercent = round((1 - ($freebytes / $totalbytes)) * 100, 1);

            // Warning if less than 10% free or less than 1GB.
            $healthy = ($freebytes / $totalbytes) > 0.1 && $freegb > 1;

            $elapsed = (microtime(true) - $start) * 1000;

            return new health_check_result(
                'disk_space',
                $healthy,
                $healthy ? 'Disk space adequate' : 'Low disk space warning',
                $elapsed,
                [
                    'free_gb' => $freegb,
                    'total_gb' => $totalgb,
                    'used_percent' => $usedpercent,
                ]
            );

        } catch (\Exception $e) {
            return new health_check_result(
                'disk_space',
                false,
                'Error checking disk space: ' . $e->getMessage(),
                (microtime(true) - $start) * 1000
            );
        }
    }

    /**
     * Check memory usage
     *
     * @return health_check_result
     */
    public function check_memory(): health_check_result {
        $start = microtime(true);

        $memoryusage = memory_get_usage(true);
        $memorypeak = memory_get_peak_usage(true);
        $memorylimit = $this->parse_memory_limit(ini_get('memory_limit'));

        $usedmb = round($memoryusage / (1024 * 1024), 2);
        $peakmb = round($memorypeak / (1024 * 1024), 2);
        $limitmb = round($memorylimit / (1024 * 1024), 2);

        // Warning if using more than 80% of limit.
        $usedpercent = ($memorylimit > 0) ? ($memoryusage / $memorylimit) * 100 : 0;
        $healthy = $usedpercent < 80;

        $elapsed = (microtime(true) - $start) * 1000;

        return new health_check_result(
            'memory',
            $healthy,
            $healthy ? 'Memory usage acceptable' : 'High memory usage warning',
            $elapsed,
            [
                'current_mb' => $usedmb,
                'peak_mb' => $peakmb,
                'limit_mb' => $limitmb,
                'used_percent' => round($usedpercent, 1),
            ]
        );
    }

    /**
     * Get comprehensive system metrics
     *
     * @return array Metrics array
     */
    public function get_metrics(): array {
        global $DB;

        $metrics = [
            'timestamp' => time(),
            'php_version' => PHP_VERSION,
            'moodle_version' => get_config('', 'version'),
        ];

        // Activity metrics.
        if ($this->classengageid) {
            $metrics['activity'] = [
                'id' => $this->classengageid,
                'questions' => $DB->count_records('classengage_questions',
                    ['classengageid' => $this->classengageid]),
                'sessions' => $DB->count_records('classengage_sessions',
                    ['classengageid' => $this->classengageid]),
                'responses' => $DB->count_records('classengage_responses',
                    ['classengageid' => $this->classengageid]),
            ];

            // Active sessions.
            $metrics['activity']['active_sessions'] = $DB->count_records('classengage_sessions', [
                'classengageid' => $this->classengageid,
                'status' => 'active',
            ]);
        }

        // Global metrics.
        $metrics['global'] = [
            'total_activities' => $DB->count_records('classengage'),
            'total_sessions' => $DB->count_records('classengage_sessions'),
            'total_responses' => $DB->count_records('classengage_responses'),
            'active_connections' => $DB->count_records('classengage_connections', ['status' => 'connected']),
            'pending_queue' => $DB->count_records('classengage_response_queue', ['processed' => 0]),
        ];

        return $metrics;
    }

    /**
     * Export health report as JSON
     *
     * @return string JSON report
     */
    public function export_report(): string {
        $checks = $this->run_all_checks();
        $metrics = $this->get_metrics();

        $report = [
            'generated_at' => date('c'),
            'overall_healthy' => true,
            'checks' => [],
            'metrics' => $metrics,
        ];

        foreach ($checks as $check) {
            $report['checks'][] = $check->to_array();
            if (!$check->healthy) {
                $report['overall_healthy'] = false;
            }
        }

        return json_encode($report, JSON_PRETTY_PRINT);
    }

    /**
     * Parse PHP memory limit string
     *
     * @param string $limit Memory limit string (e.g., '256M')
     * @return int Bytes
     */
    private function parse_memory_limit(string $limit): int {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // Fall through.
            case 'm':
                $value *= 1024;
                // Fall through.
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
