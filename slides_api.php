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
 * - NLP question generation (async, queued via adhoc task)
 * - NLP job status polling
 *
 * ARCHITECTURE PRINCIPLE:
 * - Requests enqueue work (generatenlp action)
 * - Workers do work (adhoc task via cron)
 * - UI observes state (nlpstatus action)
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
            require_capability('mod/classengage:uploadslides', $context);

            // Get the stored file.
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slideid, 'id', false);
            if (empty($files)) {
                throw new Exception('Slide file not found');
            }
            $file = reset($files);

            require_once(__DIR__ . '/classes/nlp_generator.php');
            $generator = new \mod_classengage\nlp_generator();
            $inspection = $generator->inspect_document($file);

            // Image URLs are now served via Moodle pluginfile (no external URL conversion needed)

            $response = [
                'success' => true,
                'docid' => $inspection['docId'],
                'pages' => $inspection['pages']
            ];
            break;

        case 'generate_from_options':
            require_capability('mod/classengage:uploadslides', $context);

            $docid = required_param('docid', PARAM_RAW);
            $options_json = required_param('options', PARAM_RAW);
            $options = json_decode($options_json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON options');
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
                'contextid' => $context->id
            ]);
            $task->set_component('mod_classengage');

            debugging("ClassEngage: Queuing adhoc task for slide {$slideid} with docid {$docid}", DEBUG_DEVELOPER);

            \core\task\manager::queue_adhoc_task($task);

            debugging("ClassEngage: Adhoc task queued successfully for slide {$slideid}", DEBUG_DEVELOPER);

            $response = [
                'success' => true,
                'status' => 'pending',
                'progress' => 5,
                'message' => 'Question generation queued successfully. Check status with nlpstatus action.'
            ];
            break;

        case 'generatenlp':
            // ASYNC NLP generation - queues adhoc task and returns immediately.
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
                // Clear existing questions to allow regeneration
                $DB->delete_records('classengage_questions', ['slideid' => $slideid]);
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

            // Create and queue the adhoc task - it will do inspection internally.
            $task = new \mod_classengage\task\generate_nlp_task();
            $task->set_custom_data([
                'slideid' => $slideid,
                'classengageid' => $classengage->id,
                'docid' => null, // Will be determined by task
                'options' => [], // Default options for simple generation
                'contextid' => $context->id
            ]);
            $task->set_component('mod_classengage');
            \core\task\manager::queue_adhoc_task($task);

            $response = [
                'success' => true,
                'status' => 'pending',
                'message' => 'Question generation started. This may take a few minutes depending on the AI provider.'
            ];
            break;

        case 'nlpstatus':
            // Poll endpoint for job status.
            require_capability('mod/classengage:uploadslides', $context);

            $slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);

            $status = $slide->nlp_job_status ?? 'idle';
            $progress = (int) ($slide->nlp_job_progress ?? 0);

            // Debug logging to help diagnose stuck jobs.
            debugging("ClassEngage nlpstatus: slide={$slideid} status={$status} progress={$progress}", DEBUG_DEVELOPER);

            $response = [
                'success' => true,
                'status' => $status,
                'progress' => $progress
            ];

            if (($slide->nlp_job_status ?? 'idle') === 'completed') {
                $response['count'] = (int) ($slide->nlp_questions_count ?? 0);
                $response['provider'] = $slide->nlp_provider ?? 'unknown';
                $response['model'] = $slide->nlp_model ?? null;

                // Calculate generation time
                if (!empty($slide->nlp_job_started) && !empty($slide->nlp_job_completed)) {
                    $response['duration'] = (int) $slide->nlp_job_completed - (int) $slide->nlp_job_started;
                }

                // Parse metadata for additional info
                if (!empty($slide->nlp_generation_metadata)) {
                    $meta = json_decode($slide->nlp_generation_metadata, true);
                    if ($meta) {
                        $response['metadata'] = [
                            'generated' => $meta['generated'] ?? null,
                            'expected' => $meta['requested'] ?? null,
                            'selectedSlides' => $meta['selectedSlides'] ?? [],
                            'selectedImages' => $meta['selectedImages'] ?? [],
                            'plan' => $meta['plan'] ?? null,
                        ];
                    }
                }
            }

            if (($slide->nlp_job_status ?? 'idle') === 'failed') {
                $response['error'] = $slide->nlp_job_error ?? 'Unknown error occurred';
            }
            break;

        case 'resetjob':
            // Reset a failed job to allow retry.
            require_capability('mod/classengage:uploadslides', $context);

            if (($slide->nlp_job_status ?? 'idle') !== 'failed') {
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
