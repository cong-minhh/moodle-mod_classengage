#!/usr/bin/env php
<?php
/**
 * ClassEngage Task Worker - High-Performance Database Polling Worker
 * 
 * This worker runs continuously and polls the database for pending adhoc tasks.
 * When it finds ClassEngage NLP tasks, it executes them immediately.
 * 
 * Features:
 * - Polls every 2 seconds by default (configurable)
 * - Processes tasks immediately when found
 * - Supports graceful shutdown
 * - Includes health checks and metrics
 * - Can run multiple workers for scalability
 * 
 * Usage:
 *   php task_worker.php                    # Run with default settings
 *   php task_worker.php --interval=1       # Poll every 1 second
 *   php task_worker.php --max-tasks=10     # Process max 10 tasks then exit
 *   php task_worker.php --component=mod_classengage  # Only process ClassEngage tasks
 * 
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('TASK_WORKER', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/cronlib.php');

use core\task\manager;
use core\task\adhoc_task;

// Parse command line options
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'interval' => 2,              // Polling interval in seconds
    'max-tasks' => 0,             // Max tasks to process (0 = unlimited)
    'max-runtime' => 0,           // Max runtime in seconds (0 = unlimited)
    'component' => 'mod_classengage', // Component to process (empty = all)
    'verbose' => false,           // Verbose output
    'stats' => false,             // Show statistics on exit
    'pid-file' => '',             // Write PID to file
], [
    'h' => 'help',
    'i' => 'interval',
    'm' => 'max-tasks',
    't' => 'max-runtime',
    'c' => 'component',
    'v' => 'verbose',
    's' => 'stats',
    'p' => 'pid-file',
]);

if ($unrecognized) {
    $unrecognized = implode(PHP_EOL . '  ', $unrecognized);
    cli_error("Unknown options:\n  {$unrecognized}", 2);
}

if ($options['help']) {
    $help = "ClassEngage Task Worker - High-Performance Database Polling Worker

Polls the database for pending adhoc tasks and executes them immediately.

Usage:
  php task_worker.php [options]

Options:
  -h, --help            Show this help message
  -i, --interval=N      Polling interval in seconds (default: 2)
  -m, --max-tasks=N     Max tasks to process then exit (0 = unlimited)
  -t, --max-runtime=N   Max runtime in seconds (0 = unlimited)
  -c, --component=NAME  Component to process (default: mod_classengage)
  -v, --verbose         Verbose output
  -s, --stats           Show statistics on exit
  -p, --pid-file=FILE   Write PID to file

Examples:
  # Run with defaults (poll every 2 seconds, process ClassEngage tasks)
  php task_worker.php

  # Poll every 1 second for faster response
  php task_worker.php --interval=1

  # Process max 50 tasks then exit
  php task_worker.php --max-tasks=50 --stats

  # Process all adhoc tasks (not just ClassEngage)
  php task_worker.php --component=''

  # Run as daemon with PID file
  php task_worker.php --pid-file=/var/run/classengage-worker.pid

Worker Behavior:
  - Polls database every N seconds for pending tasks
  - Processes tasks immediately when found
  - Supports graceful shutdown (SIGTERM, SIGINT)
  - Can run multiple workers simultaneously
  - Logs all activity for monitoring

Performance:
  - Typical response time: 2-5 seconds from task creation to execution
  - With --interval=1: 1-3 seconds response time
  - Multiple workers can process tasks in parallel

";

    echo $help;
    exit(0);
}

// Validate options
$interval = max(1, min(60, intval($options['interval']))); // Clamp between 1-60 seconds
$maxTasks = max(0, intval($options['max-tasks']));
$maxRuntime = max(0, intval($options['max-runtime']));
$component = $options['component'];
$verbose = !empty($options['verbose']);
$showStats = !empty($options['stats']);
$pidFile = $options['pid-file'];

// Statistics
$stats = [
    'started' => time(),
    'tasks_processed' => 0,
    'tasks_failed' => 0,
    'polls' => 0,
    'tasks_found' => 0,
    'last_task_time' => 0,
];

// Write PID file if requested
if ($pidFile) {
    if (file_put_contents($pidFile, getmypid()) === false) {
        cli_error("Failed to write PID file: {$pidFile}");
    }
    if ($verbose) {
        mtrace("PID written to: {$pidFile}");
    }
}

// Setup signal handlers for graceful shutdown
$shutdown = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shutdown) {
        $shutdown = true;
        mtrace("Received SIGTERM, shutting down gracefully...");
    });
    pcntl_signal(SIGINT, function() use (&$shutdown) {
        $shutdown = true;
        mtrace("Received SIGINT, shutting down gracefully...");
    });
}

// Log startup
mtrace("========================================");
mtrace("ClassEngage Task Worker Started");
mtrace("Started at: " . date('Y-m-d H:i:s'));
mtrace("PID: " . getmypid());
mtrace("Configuration:");
mtrace("  - Polling interval: {$interval} seconds");
mtrace("  - Component filter: " . ($component ?: 'all'));
mtrace("  - Max tasks: " . ($maxTasks ?: 'unlimited'));
mtrace("  - Max runtime: " . ($maxRuntime ?: 'unlimited') . " seconds");
mtrace("========================================");

// Main worker loop
$consecutiveEmpty = 0;
$maxConsecutiveEmpty = 30; // After 30 empty polls, log a heartbeat

try {
    while (!$shutdown) {
        // Check max runtime
        if ($maxRuntime > 0 && (time() - $stats['started']) >= $maxRuntime) {
            mtrace("Max runtime reached ({$maxRuntime}s), shutting down...");
            break;
        }
        
        // Check max tasks
        if ($maxTasks > 0 && $stats['tasks_processed'] >= $maxTasks) {
            mtrace("Max tasks reached ({$maxTasks}), shutting down...");
            break;
        }
        
        // Process signals if available
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        $stats['polls']++;
        
        // Look for pending tasks
        $tasks = find_pending_tasks($component, $verbose);
        
        if (!empty($tasks)) {
            $consecutiveEmpty = 0;
            $stats['tasks_found'] += count($tasks);
            
            if ($verbose) {
                mtrace("Found " . count($tasks) . " pending task(s)");
            }
            
            // Process each task
            foreach ($tasks as $task) {
                if ($shutdown) {
                    break;
                }
                
                $result = process_task($task, $verbose);
                
                if ($result) {
                    $stats['tasks_processed']++;
                    $stats['last_task_time'] = time();
                } else {
                    $stats['tasks_failed']++;
                }
                
                // Brief pause between tasks to prevent overwhelming the system
                usleep(100000); // 100ms
            }
        } else {
            $consecutiveEmpty++;
            
            // Log heartbeat every N empty polls
            if ($consecutiveEmpty >= $maxConsecutiveEmpty) {
                $runtime = time() - $stats['started'];
                mtrace("Worker heartbeat: running for {$runtime}s, {$stats['polls']} polls, {$stats['tasks_processed']} tasks processed");
                $consecutiveEmpty = 0;
            }
        }
        
        // Sleep until next poll
        if (!$shutdown) {
            sleep($interval);
        }
    }
} catch (Exception $e) {
    mtrace("ERROR: " . $e->getMessage());
    mtrace($e->getTraceAsString());
    exit(1);
} finally {
    // Cleanup
    if ($pidFile && file_exists($pidFile)) {
        unlink($pidFile);
    }
    
    // Show statistics
    if ($showStats || $verbose) {
        $runtime = time() - $stats['started'];
        mtrace("");
        mtrace("========================================");
        mtrace("Worker Statistics");
        mtrace("========================================");
        mtrace("Runtime: " . format_duration($runtime));
        mtrace("Polls: " . $stats['polls']);
        mtrace("Tasks found: " . $stats['tasks_found']);
        mtrace("Tasks processed: " . $stats['tasks_processed']);
        mtrace("Tasks failed: " . $stats['tasks_failed']);
        if ($stats['tasks_processed'] > 0) {
            $avgTime = $runtime / $stats['tasks_processed'];
            mtrace("Avg time per task: " . round($avgTime, 2) . "s");
        }
        mtrace("========================================");
    }
    
    mtrace("Worker stopped at: " . date('Y-m-d H:i:s'));
}

/**
 * Find pending adhoc tasks in the database
 * 
 * @param string $component Component filter (empty for all)
 * @param bool $verbose Verbose output
 * @return array Array of task records
 */
function find_pending_tasks($component, $verbose) {
    global $DB;
    
    $tasks = [];
    
    try {
        // Build query
        $sql = "SELECT * FROM {task_adhoc} 
                WHERE faildelay = 0 
                AND (nextruntime IS NULL OR nextruntime <= :now)";
        $params = ['now' => time()];
        
        if (!empty($component)) {
            $sql .= " AND component = :component";
            $params['component'] = $component;
        }
        
        $sql .= " ORDER BY id ASC LIMIT 10"; // Process oldest first, max 10 at a time
        
        $records = $DB->get_records_sql($sql, $params);
        
        foreach ($records as $record) {
            $tasks[] = $record;
        }
    } catch (Exception $e) {
        if ($verbose) {
            mtrace("Database error: " . $e->getMessage());
        }
    }
    
    return $tasks;
}

/**
 * Process a single adhoc task
 * 
 * @param object $task Task record from database
 * @param bool $verbose Verbose output
 * @return bool True if successful
 */
function process_task($task, $verbose) {
    global $DB;
    
    $taskId = $task->id;
    $classname = $task->classname;
    
    if ($verbose) {
        mtrace("Processing task {$taskId}: {$classname}");
    }
    
    try {
        // Load the task using Moodle's task manager
        $adhocTask = manager::get_adhoc_task_from_record($task);
        
        if (!$adhocTask) {
            mtrace("Failed to load task {$taskId}");
            return false;
        }
        
        // Execute the task
        $startTime = microtime(true);
        
        // Mark task as running (update nextruntime to prevent other workers picking it up)
        $DB->set_field('task_adhoc', 'nextruntime', time() + 300, ['id' => $taskId]); // 5 min lock
        
        // Execute
        $adhocTask->execute();
        
        // Delete the task after successful execution
        $DB->delete_records('task_adhoc', ['id' => $taskId]);
        
        $duration = round(microtime(true) - $startTime, 3);
        
        if ($verbose) {
            mtrace("Task {$taskId} completed in {$duration}s");
        }
        
        return true;
        
    } catch (Exception $e) {
        mtrace("Task {$taskId} failed: " . $e->getMessage());
        
        // Increment faildelay
        $newFaildelay = min($task->faildelay + 60, 86400); // Max 24 hours
        $DB->set_field('task_adhoc', 'faildelay', $newFaildelay, ['id' => $taskId]);
        
        return false;
    }
}

/**
 * Format duration in human-readable format
 * 
 * @param int $seconds Duration in seconds
 * @return string Formatted duration
 */
function format_duration($seconds) {
    if ($seconds < 60) {
        return $seconds . "s";
    } elseif ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;
        return "{$mins}m {$secs}s";
    } else {
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        return "{$hours}h {$mins}m";
    }
}
