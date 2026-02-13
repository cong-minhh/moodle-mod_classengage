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
 * API endpoint for slide management operations
 *
 * This endpoint handles slide-related write operations:
 * - Async document inspection (queues adhoc task)
 * - NLP question generation (async, queued via adhoc task)
 * - NLP job status polling
 *
 * ARCHITECTURE (Option C - Worker-based):
 * - Webserver: Just queues tasks, no PDF processing
 * - Worker: Handles all inspection and generation
 * - UI: Shows loading states, polls for completion
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_classengage\rate_limiter;
use mod_classengage\constants;

$action = required_param('action', PARAM_ALPHANUMEXT);
$slideid = required_param('slideid', PARAM_INT);

// Get slide and validate ownership.
$slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', ['id' => $slide->classengageid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login(0, false, null, false, true);
require_sesskey();

$response = ['success' => false];

try {
    switch ($action) {
        case 'inspect':
            // ASYNC INSPECTION - Queues task, returns immediately
            require_capability('mod/classengage:uploadslides', $context);

            // Check if already inspected
            $metadata = json_decode($slide->nlp_generation_metadata ?? '', true);
            if (!empty($metadata['docId']) && $slide->nlp_job_status === 'inspected') {
                // Already inspected, return cached results
                $response = [
                    'success' => true,
                    'docid' => $metadata['docId'],
                    'pages' => $metadata['pages'],
                    'cached' => true
                ];
                break;
            }

            // Check if inspection is already in progress
            if ($slide->nlp_job_status === 'inspecting') {
                $response = [
                    'success' => true,
                    'status' => 'inspecting',
                    'progress' => (int) ($slide->nlp_job_progress ?? 0),
                    'message' => 'Inspection already in progress'
                ];
                break;
            }

            // Mark as inspecting and queue task
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'inspecting',
                'nlp_job_progress' => 5,
                'nlp_job_error' => null,
                'timemodified' => time()
            ]);

            // Queue inspection task
            $task = new \mod_classengage\task\inspect_document_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'contextid' => $context->id
            ]);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'inspecting',
                'progress' => 5,
                'message' => 'Document inspection queued. Poll inspectionstatus for results.'
            ];
            break;

        case 'inspectionstatus':
            // Poll endpoint for inspection completion
            require_capability('mod/classengage:uploadslides', $context);

            $slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);
            $status = $slide->nlp_job_status ?? 'idle';
            $progress = (int) ($slide->nlp_job_progress ?? 0);

            $response = [
                'success' => true,
                'status' => $status,
                'progress' => $progress
            ];

            // If inspection complete, return the data
            if ($status === 'inspected') {
                $metadata = json_decode($slide->nlp_generation_metadata ?? '', true);
                if (!empty($metadata['docId'])) {
                    $response['docid'] = $metadata['docId'];
                    $response['pages'] = $metadata['pages'];
                    $response['page_count'] = $metadata['page_count'] ?? count($metadata['pages'] ?? []);
                }
            }

            if ($status === 'inspect_failed') {
                $response['error'] = $slide->nlp_job_error ?? 'Inspection failed';
            }
            break;

        case 'generate_from_options':
            // ASYNC GENERATION - Uses pre-inspected document
            require_capability('mod/classengage:uploadslides', $context);

            $docid = required_param('docid', PARAM_RAW);
            $options_json = required_param('options', PARAM_RAW);
            $options = json_decode($options_json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON options');
            }

            // Verify document has been inspected
            $metadata = json_decode($slide->nlp_generation_metadata ?? '', true);
            if (empty($metadata['docId']) || $slide->nlp_job_status !== 'inspected') {
                $response = [
                    'success' => false,
                    'error' => 'Document must be inspected before generating questions'
                ];
                break;
            }

            // Prevent duplicate generation if already running.
            if (($slide->nlp_job_status ?? 'idle') === 'running') {
                $response = [
                    'success' => false,
                    'error' => 'Generation already in progress for this slide'
                ];
                break;
            }

            // Mark as pending and enqueue adhoc task.
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'pending',
                'nlp_job_progress' => 0,
                'nlp_job_error' => null,
                'nlp_job_started' => null,
                'nlp_job_completed' => null,
                'timemodified' => time()
            ]);

            // Create and queue the adhoc task.
            $task = new \mod_classengage\task\generate_nlp_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'docid' => $docid,
                'options' => $options,
                'contextid' => $context->id,
                'inspection_data' => $metadata  // Pass inspection data to avoid re-inspection
            ]);
            $task->set_component('mod_classengage');

            debugging("ClassEngage: Queuing generation task for slide {$slideid}", DEBUG_DEVELOPER);

            \core\task\manager::queue_adhoc_task($task);

            debugging("ClassEngage: Generation task queued successfully for slide {$slideid}", DEBUG_DEVELOPER);

            $response = [
                'success' => true,
                'status' => 'pending',
                'progress' => 5,
                'message' => 'Question generation queued successfully. Check status with nlpstatus action.'
            ];
            break;

        case 'generatenlp':
            // SIMPLIFIED: Just inspect + generate in one flow
            require_capability('mod/classengage:uploadslides', $context);

            // Prevent duplicate generation if already running.
            if (($slide->nlp_job_status ?? 'idle') === 'running') {
                $response = [
                    'success' => false,
                    'error' => 'Generation already in progress for this slide'
                ];
                break;
            }

            // Check if already completed - allow regeneration by resetting first
            if (($slide->nlp_job_status ?? 'idle') === 'completed') {
                $DB->delete_records('classengage_questions', ['slideid' => $slideid]);
            }

            // Queue inspection task first (it will queue generation after)
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'inspecting',
                'nlp_job_progress' => 5,
                'nlp_job_error' => null,
                'timemodified' => time()
            ]);

            // Queue inspection task with auto-generate flag
            $task = new \mod_classengage\task\inspect_document_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'contextid' => $context->id,
                'auto_generate' => true,  // Auto-queue generation after inspection
                'options' => []
            ]);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'inspecting',
                'message' => 'Document inspection started. Generation will begin automatically after inspection.'
            ];
            break;

        case 'nlpstatus':
            // Poll endpoint for generation job status.
            require_capability('mod/classengage:uploadslides', $context);

            $slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);

            $status = $slide->nlp_job_status ?? 'idle';
            $progress = (int) ($slide->nlp_job_progress ?? 0);

            debugging("ClassEngage nlpstatus: slide={$slideid} status={$status} progress={$progress}", DEBUG_DEVELOPER);

            $response = [
                'success' => true,
                'status' => $status,
                'progress' => $progress
            ];

            if ($status === 'completed') {
                $response['count'] = (int) ($slide->nlp_questions_count ?? 0);
                $response['provider'] = $slide->nlp_provider ?? 'unknown';
                $response['model'] = $slide->nlp_model ?? null;

                if (!empty($slide->nlp_job_started) && !empty($slide->nlp_job_completed)) {
                    $response['duration'] = (int) $slide->nlp_job_completed - (int) $slide->nlp_job_started;
                }

                if (!empty($slide->nlp_generation_metadata)) {
                    $meta = json_decode($slide->nlp_generation_metadata, true);
                    if ($meta && isset($meta['generated'])) {
                        $response['metadata'] = [
                            'generated' => $meta['generated'] ?? null,
                            'expected' => $meta['requested'] ?? null,
                        ];
                    }
                }
            }

            if ($status === 'failed') {
                $response['error'] = $slide->nlp_job_error ?? 'Unknown error occurred';
            }
            break;

        case 'resetjob':
            // Reset a failed job to allow retry.
            require_capability('mod/classengage:uploadslides', $context);

            if (!in_array($slide->nlp_job_status ?? 'idle', ['failed', 'inspect_failed'])) {
                $response = [
                    'success' => false,
                    'error' => 'Can only reset failed jobs'
                ];
                break;
            }

            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'idle',
                'nlp_job_progress' => 0,
                'nlp_job_error' => null,
                'nlp_job_id' => null,
                'nlp_job_started' => null,
                'nlp_job_completed' => null,
                'timemodified' => time()
            ]);

            $response = [
                'success' => true,
                'status' => 'idle',
                'message' => 'Job reset successfully'
            ];
            break;

        default:
            $response['error'] = 'Invalid action: ' . $action;
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    debugging('Slides API error: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

header('Content-Type: application/json');
echo json_encode($response);
