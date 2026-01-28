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
 * Background task for NLP question generation
 *
 * Runs outside the request lifecycle to ensure scalability and controlled concurrency.
 * Updates progress incrementally in DB so UI can reflect real status.
 *
 * ARCHITECTURE:
 * - Requests enqueue work (api.php handles enqueueing)
 * - Workers do work (this task runs via cron)
 * - UI observes state (polling via api.php nlpstatus action)
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task for NLP question generation
 *
 * @package    mod_classengage
 */
class generate_nlp_task extends \core\task\adhoc_task
{

    /**
     * Get task name for display
     *
     * @return string
     */
    public function get_name(): string
    {
        return get_string('task:generatenlp', 'mod_classengage');
    }

    /**
     * Execute the NLP question generation task
     *
     * @return void
     * @throws \Exception if generation fails
     */
    public function execute(): void
    {
        global $DB;

        $data = $this->get_custom_data();
        $slideid = $data->slideid;
        $contextid = $data->contextid;
        $classengageid = $data->classengageid;

        $starttime = microtime(true);
        mtrace("ClassEngage NLP: Starting generation for slide {$slideid}");

        // IDEMPOTENCY: Exit early if slide no longer exists or already completed.
        $slide = $DB->get_record('classengage_slides', ['id' => $slideid]);
        if (!$slide) {
            mtrace("ClassEngage NLP: Slide {$slideid} not found, aborting");
            return;
        }

        if ($slide->nlp_job_status === 'completed') {
            mtrace("ClassEngage NLP: Slide {$slideid} already completed, skipping");
            return;
        }

        // Mark as running with timestamp.
        $this->update_slide_status($slideid, 'running', 10, null, [
            'nlp_job_started' => time(),
            'nlp_job_error' => null
        ]);

        try {
            // Progress: 10% - Starting.
            $this->log_progress($slideid, 10, 'Initializing NLP engine...');

            // Get the stored file.
            $fs = get_file_storage();
            $context = \context::instance_by_id($contextid);
            $files = $fs->get_area_files($contextid, 'mod_classengage', 'slides', $slideid, 'id', false);

            if (empty($files)) {
                throw new \Exception('Slide file not found');
            }

            $file = reset($files);

            // Progress: 20% - File loaded.
            $this->update_slide_status($slideid, 'running', 20);
            $this->log_progress($slideid, 20, 'Analyzing slide content...');

            // Progress: 40% - Pre-processing.
            $this->update_slide_status($slideid, 'running', 40);
            $this->log_progress($slideid, 40, 'Inspecting document...');

            // Generate questions via NLP service using async flow.
            require_once(__DIR__ . '/../nlp_generator.php');
            $generator = new \mod_classengage\nlp_generator();

            // First inspect the document to get docId.
            $inspection = $generator->inspect_document($file);
            $docid = $inspection['docId'] ?? null;

            if (empty($docid)) {
                throw new \Exception('Document inspection failed - no docId returned');
            }

            $this->log_progress($slideid, 50, 'Document inspected, starting generation...');

            // Progress: 60% - NLP processing with async polling.
            $this->update_slide_status($slideid, 'running', 60);
            $this->log_progress($slideid, 60, 'Processing with NLP engine (async)...');

            // Use async generation with internal polling
            // This is more robust - uses job queue on NLP service side
            $result = $generator->generate_questions_async(
                $docid,
                $classengageid,
                $slideid,
                [
                    'numQuestions' => $data->numquestions ?? 10,
                    'difficulty' => $data->difficulty ?? 'mixed'
                ],
                600,  // 10 minute max wait
                3     // poll every 3 seconds
            );

            $questions = $result['questionids'] ?? [];

            // Progress: 90% - Storing results.
            $this->update_slide_status($slideid, 'running', 90);
            $this->log_progress($slideid, 90, 'Finalizing questions...');

            // Update slide with success.
            $duration = microtime(true) - $starttime;
            $this->update_slide_status($slideid, 'completed', 100, null, [
                'status' => 'completed',
                'nlp_questions_count' => count($questions),
                'nlp_job_completed' => time()
            ]);

            // Log success for monitoring and capacity planning.
            mtrace(sprintf(
                "ClassEngage NLP: Completed slide %d - %d questions generated in %.2fs",
                $slideid,
                count($questions),
                $duration
            ));

            // Trigger event (once only per successful generation).
            $event = \mod_classengage\event\questions_generated::create([
                'objectid' => $slideid,
                'context' => $context,
                'other' => ['classengageid' => $classengageid, 'count' => count($questions)]
            ]);
            $event->trigger();

        } catch (\Exception $e) {
            $duration = microtime(true) - $starttime;

            // Update slide with failure.
            $this->update_slide_status($slideid, 'failed', $slide->nlp_job_progress ?? 0, $e->getMessage(), [
                'nlp_job_completed' => time()
            ]);

            mtrace(sprintf(
                "ClassEngage NLP: FAILED slide %d after %.2fs - %s",
                $slideid,
                $duration,
                $e->getMessage()
            ));

            // Re-throw to mark task as failed in Moodle's task system.
            throw $e;
        }
    }

    /**
     * Update slide status and progress in database
     *
     * @param int $slideid Slide ID
     * @param string $status Job status (running, completed, failed)
     * @param int $progress Progress percentage 0-100
     * @param string|null $error Error message if failed
     * @param array $extrafields Additional fields to update
     */
    private function update_slide_status(int $slideid, string $status, int $progress, ?string $error = null, array $extrafields = []): void
    {
        global $DB;

        $update = (object) array_merge([
            'id' => $slideid,
            'nlp_job_status' => $status,
            'nlp_job_progress' => $progress,
            'nlp_job_error' => $error,
            'timemodified' => time()
        ], $extrafields);

        $DB->update_record('classengage_slides', $update);
    }

    /**
     * Log progress for monitoring
     *
     * @param int $slideid Slide ID
     * @param int $progress Progress percentage
     * @param string $message Status message
     */
    private function log_progress(int $slideid, int $progress, string $message): void
    {
        mtrace("ClassEngage NLP: Slide {$slideid} - {$progress}% - {$message}");
    }
}
