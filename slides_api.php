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
require_once(__DIR__ . '/classes/nlp_generator.php');

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

/**
 * Maximum file size for automatic inline execution.
 */
const MOD_CLASSENGAGE_INLINE_MAX_FILESIZE = 26214400; // 25 MB.

/**
 * Maximum question count for automatic inline execution.
 */
const MOD_CLASSENGAGE_INLINE_MAX_QUESTIONS = 20;

/**
 * Get the configured execution mode for user-triggered NLP actions.
 *
 * @return string
 */
function mod_classengage_get_execution_mode(): string {
    $mode = (string)(get_config('mod_classengage', 'nlpexecutionmode') ?: 'auto');
    if (!in_array($mode, ['auto', 'background', 'inline'], true)) {
        return 'auto';
    }
    return $mode;
}

/**
 * Update slide NLP job state.
 *
 * @param int $slideid
 * @param array $fields
 * @return void
 */
function mod_classengage_update_slide_job(int $slideid, array $fields): void {
    global $DB;

    $record = (object)array_merge([
        'id' => $slideid,
        'timemodified' => time(),
    ], $fields);

    $DB->update_record('classengage_slides', $record);
}

/**
 * Get the stored file for a slide.
 *
 * @param int $contextid
 * @param int $slideid
 * @return \stored_file
 */
function mod_classengage_get_slide_file(int $contextid, int $slideid): \stored_file {
    $fs = get_file_storage();
    $files = $fs->get_area_files($contextid, 'mod_classengage', 'slides', $slideid, 'id', false);

    if (empty($files)) {
        throw new Exception('Slide file not found in storage');
    }

    return reset($files);
}

/**
 * Determine whether the current request should execute inline.
 *
 * @param int $contextid
 * @param int $slideid
 * @param array $options
 * @return bool
 */
function mod_classengage_should_run_inline(int $contextid, int $slideid, array $options = []): bool {
    $mode = mod_classengage_get_execution_mode();
    if ($mode === 'background') {
        return false;
    }

    if ($mode === 'inline') {
        return true;
    }

    $file = mod_classengage_get_slide_file($contextid, $slideid);
    if ((int)$file->get_filesize() > MOD_CLASSENGAGE_INLINE_MAX_FILESIZE) {
        return false;
    }

    $numquestions = (int)($options['numQuestions'] ?? (get_config('mod_classengage', 'defaultquestions') ?: 5));
    if ($numquestions > MOD_CLASSENGAGE_INLINE_MAX_QUESTIONS) {
        return false;
    }

    return true;
}

/**
 * Build default generation options for quick-generate flows.
 *
 * @return array
 */
function mod_classengage_build_default_generation_options(): array {
    return [
        'numQuestions' => max(1, (int)(get_config('mod_classengage', 'defaultquestions') ?: 5)),
        'difficulty' => 'mixed',
        'bloomLevel' => 'remember',
    ];
}

/**
 * Run inspection inline inside the current request.
 *
 * @param int $slideid
 * @param int $contextid
 * @return array
 */
function mod_classengage_perform_inline_inspection(int $slideid, int $contextid): array {
    $file = mod_classengage_get_slide_file($contextid, $slideid);

    mod_classengage_update_slide_job($slideid, [
        'nlp_job_status' => 'inspecting',
        'nlp_job_progress' => 10,
        'nlp_job_error' => null,
    ]);

    try {
        $generator = new \mod_classengage\nlp_generator();
        $inspection = $generator->inspect_document($file);
    } catch (Exception $e) {
        mod_classengage_update_slide_job($slideid, [
            'nlp_job_status' => 'inspect_failed',
            'nlp_job_error' => $e->getMessage(),
        ]);
        throw $e;
    }

    $inspectiondata = [
        'docId' => $inspection['docId'],
        'pages' => $inspection['pages'],
        'inspected_at' => time(),
        'page_count' => count($inspection['pages'] ?? []),
    ];

    mod_classengage_update_slide_job($slideid, [
        'nlp_generation_metadata' => json_encode($inspectiondata),
        'nlp_job_status' => 'inspected',
        'nlp_job_progress' => 100,
        'nlp_job_error' => null,
    ]);

    return $inspectiondata;
}

/**
 * Run question generation inline inside the current request.
 *
 * @param int $slideid
 * @param int $classengageid
 * @param int $contextid
 * @param array $options
 * @param array|null $inspectiondata
 * @return array
 */
function mod_classengage_perform_inline_generation(
    int $slideid,
    int $classengageid,
    int $contextid,
    array $options = [],
    ?array $inspectiondata = null
): array {
    if (empty($inspectiondata['docId'])) {
        $inspectiondata = mod_classengage_perform_inline_inspection($slideid, $contextid);
    }

    $started = time();
    mod_classengage_update_slide_job($slideid, [
        'nlp_job_status' => 'running',
        'nlp_job_progress' => 10,
        'nlp_job_error' => null,
        'nlp_job_started' => $started,
        'nlp_job_completed' => null,
    ]);

    $generator = new \mod_classengage\nlp_generator();
    try {
        mod_classengage_update_slide_job($slideid, [
            'nlp_job_status' => 'running',
            'nlp_job_progress' => 60,
        ]);

        $result = $generator->generate_questions_from_document(
            $inspectiondata['docId'],
            $classengageid,
            $slideid,
            $options
        );
    } catch (Exception $e) {
        mod_classengage_update_slide_job($slideid, [
            'nlp_job_status' => 'failed',
            'nlp_job_progress' => 60,
            'nlp_job_error' => $e->getMessage(),
            'nlp_job_completed' => time(),
        ]);
        throw $e;
    }

    $completed = time();
    $count = count($result['questionids'] ?? []);

    mod_classengage_update_slide_job($slideid, [
        'nlp_job_status' => 'completed',
        'nlp_job_progress' => 100,
        'nlp_job_error' => null,
        'nlp_questions_count' => $count,
        'nlp_job_completed' => $completed,
        'nlp_provider' => $result['provider'] ?? null,
        'nlp_model' => $result['model'] ?? null,
        'nlp_generation_metadata' => !empty($result['metadata']) ? json_encode($result['metadata']) : null,
    ]);

    purge_caches();

    $event = \mod_classengage\event\questions_generated::create([
        'objectid' => $slideid,
        'context' => \context::instance_by_id($contextid),
        'other' => [
            'classengageid' => $classengageid,
            'count' => $count,
        ],
    ]);
    $event->trigger();

    $response = [
        'success' => true,
        'status' => 'completed',
        'progress' => 100,
        'count' => $count,
        'provider' => $result['provider'] ?? 'unknown',
        'model' => $result['model'] ?? null,
        'duration' => $completed - $started,
        'inline' => true,
    ];

    if (!empty($result['metadata'])) {
        $response['metadata'] = [
            'generated' => $result['metadata']['generated'] ?? null,
            'expected' => $result['metadata']['requested'] ?? null,
        ];
    }

    return $response;
}

try {
    switch ($action) {
        case 'inspect':
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

            // Check if inspection is already in progress.
            if ($slide->nlp_job_status === 'inspecting') {
                $response = [
                    'success' => true,
                    'status' => 'inspecting',
                    'progress' => (int) ($slide->nlp_job_progress ?? 0),
                    'message' => 'Inspection already in progress'
                ];
                break;
            }

            if (mod_classengage_should_run_inline($context->id, $slideid)) {
                $inspectiondata = mod_classengage_perform_inline_inspection($slideid, $context->id);
                $response = [
                    'success' => true,
                    'status' => 'inspected',
                    'progress' => 100,
                    'docid' => $inspectiondata['docId'],
                    'pages' => $inspectiondata['pages'],
                    'page_count' => $inspectiondata['page_count'],
                    'cached' => true,
                    'inline' => true,
                ];
                break;
            }

            mod_classengage_update_slide_job($slideid, [
                'nlp_job_status' => 'inspecting',
                'nlp_job_progress' => 5,
                'nlp_job_error' => null,
            ]);

            $task = new \mod_classengage\task\inspect_document_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'contextid' => $context->id,
            ]);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'inspecting',
                'progress' => 5,
                'message' => 'Document inspection queued. Poll inspectionstatus for results.',
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

            if (mod_classengage_should_run_inline($context->id, $slideid, $options)) {
                $response = mod_classengage_perform_inline_generation(
                    $slideid,
                    $classengage->id,
                    $context->id,
                    $options,
                    $metadata
                );
                break;
            }

            mod_classengage_update_slide_job($slideid, [
                'nlp_job_status' => 'pending',
                'nlp_job_progress' => 5,
                'nlp_job_error' => null,
                'nlp_job_started' => null,
                'nlp_job_completed' => null,
            ]);

            $task = new \mod_classengage\task\generate_nlp_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'docid' => $docid,
                'options' => $options,
                'contextid' => $context->id,
                'inspection_data' => $metadata,
            ]);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'pending',
                'progress' => 5,
                'message' => 'Question generation queued successfully. Check status with nlpstatus action.',
            ];
            break;

        case 'generatenlp':
            require_capability('mod/classengage:uploadslides', $context);

            if (in_array(($slide->nlp_job_status ?? 'idle'), ['pending', 'running', 'inspecting'], true)) {
                $response = [
                    'success' => true,
                    'status' => $slide->nlp_job_status,
                    'progress' => (int)($slide->nlp_job_progress ?? 0),
                    'message' => 'Generation already in progress for this slide',
                ];
                break;
            }

            $options = mod_classengage_build_default_generation_options();

            if (($slide->nlp_job_status ?? 'idle') === 'completed') {
                $DB->delete_records('classengage_questions', ['slideid' => $slideid]);
            }

            if (mod_classengage_should_run_inline($context->id, $slideid, $options)) {
                $response = mod_classengage_perform_inline_generation(
                    $slideid,
                    $classengage->id,
                    $context->id,
                    $options
                );
                break;
            }

            $metadata = json_decode($slide->nlp_generation_metadata ?? '', true);

            mod_classengage_update_slide_job($slideid, [
                'nlp_job_status' => 'pending',
                'nlp_job_progress' => 5,
                'nlp_job_error' => null,
                'nlp_job_started' => null,
                'nlp_job_completed' => null,
            ]);

            $task = new \mod_classengage\task\generate_nlp_task();
            $customdata = [
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'contextid' => $context->id,
                'options' => $options,
            ];

            if (!empty($metadata['docId'])) {
                $customdata['docid'] = $metadata['docId'];
                $customdata['inspection_data'] = $metadata;
            }

            $task->set_custom_data($customdata);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'pending',
                'progress' => 5,
                'message' => 'Question generation queued successfully. Check status with nlpstatus action.',
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
