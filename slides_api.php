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
 * - NLP question generation (async, queued)
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

            // Mark as running.
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'running',
                'nlp_job_progress' => 10,
                'nlp_job_error' => null,
                'nlp_job_started' => time(),
                'timemodified' => time()
            ]);

            require_once(__DIR__ . '/classes/nlp_generator.php');
            $generator = new \mod_classengage\nlp_generator();

            // Sanitize and prepare options
            $safe_options = [
                'numQuestions' => (int) ($options['numQuestions'] ?? 10),
                'difficulty' => $options['difficulty'] ?? 'medium',
                'bloomLevel' => $options['bloomLevel'] ?? 'apply',
                'difficultyDistribution' => $options['difficultyDistribution'] ?? null,
                'bloomDistribution' => $options['bloomDistribution'] ?? null,
                'includeSlides' => $options['includeSlides'] ?? [],
                'includeImages' => $options['includeImages'] ?? []
            ];

            // Ensure includes are arrays of strings (API requirement)
            if (!empty($safe_options['includeSlides'])) {
                $safe_options['includeSlides'] = array_map('strval', $safe_options['includeSlides']);
            }
            // For images, they are already strings (source IDs)

            $questions = $generator->generate_questions_from_document($docid, $classengage->id, $slideid, $safe_options);

            // Update slide with success.
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'status' => 'completed',
                'nlp_job_status' => 'completed',
                'nlp_job_progress' => 100,
                'nlp_questions_count' => count($questions),
                'nlp_job_completed' => time(),
                'timemodified' => time()
            ]);

            // Trigger event.
            $event = \mod_classengage\event\questions_generated::create([
                'objectid' => $slideid,
                'context' => $context,
                'other' => ['classengageid' => $classengage->id, 'count' => count($questions)]
            ]);
            $event->trigger();

            $response = [
                'success' => true,
                'status' => 'completed',
                'count' => count($questions),
                'message' => count($questions) . ' questions generated successfully'
            ];
            break;

        case 'generatenlp':
            // SYNCHRONOUS NLP generation - directly calls the NLP service and waits for response.
            require_capability('mod/classengage:uploadslides', $context);

            // Prevent duplicate generation if already running.
            if (($slide->nlp_job_status ?? 'idle') === 'running') {
                $response = [
                    'success' => false,
                    'error' => 'Generation already in progress for this slide'
                ];
                break;
            }

            try {
                // Mark as running.
                $DB->update_record('classengage_slides', (object) [
                    'id' => $slideid,
                    'nlp_job_status' => 'running',
                    'nlp_job_progress' => 10,
                    'nlp_job_error' => null,
                    'nlp_job_started' => time(),
                    'timemodified' => time()
                ]);

                // Get the stored file.
                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slideid, 'id', false);

                if (empty($files)) {
                    throw new Exception('Slide file not found');
                }

                $file = reset($files);

                // Generate questions via NLP service (synchronous call).
                require_once(__DIR__ . '/classes/nlp_generator.php');
                $generator = new \mod_classengage\nlp_generator();
                $questions = $generator->generate_questions_from_file($file, $classengage->id, $slideid);

                // Update slide with success.
                $DB->update_record('classengage_slides', (object) [
                    'id' => $slideid,
                    'status' => 'completed',
                    'nlp_job_status' => 'completed',
                    'nlp_job_progress' => 100,
                    'nlp_questions_count' => count($questions),
                    'nlp_job_completed' => time(),
                    'timemodified' => time()
                ]);

                // Trigger event.
                $event = \mod_classengage\event\questions_generated::create([
                    'objectid' => $slideid,
                    'context' => $context,
                    'other' => ['classengageid' => $classengage->id, 'count' => count($questions)]
                ]);
                $event->trigger();

                $response = [
                    'success' => true,
                    'status' => 'completed',
                    'progress' => 100,
                    'count' => count($questions),
                    'message' => count($questions) . ' questions generated successfully'
                ];

            } catch (Exception $e) {
                // Update slide with failure.
                $DB->update_record('classengage_slides', (object) [
                    'id' => $slideid,
                    'nlp_job_status' => 'failed',
                    'nlp_job_error' => $e->getMessage(),
                    'nlp_job_completed' => time(),
                    'timemodified' => time()
                ]);

                $response = [
                    'success' => false,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
            break;

        case 'nlpstatus':
            // Lightweight status endpoint for polling.
            // Must be fast, read-only, and safe to poll frequently.

            require_capability('mod/classengage:uploadslides', $context);

            // Response contract:
            // { status: "idle|pending|running|completed|failed", progress: 0-100 }
            // { status: "completed", count: 12 }
            // { status: "failed", error: "..." }

            $response = [
                'success' => true,
                'status' => $slide->nlp_job_status ?? 'idle',
                'progress' => (int) ($slide->nlp_job_progress ?? 0)
            ];

            if (($slide->nlp_job_status ?? 'idle') === 'completed') {
                $response['count'] = (int) ($slide->nlp_questions_count ?? 0);
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
