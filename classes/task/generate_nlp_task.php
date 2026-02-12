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
        return \get_string('task:generatenlp', 'mod_classengage');
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

        $starttime = microtime(true);
        $slideid = 0;
        $contextid = 0;
        $classengageid = 0;
        $docid = null;
        $options = [];

        try {
            $data = $this->get_custom_data();

            // Validate custom data exists.
            if (empty($data)) {
                \mtrace("ClassEngage NLP: ERROR - No custom data provided to task");
                throw new \Exception('Task custom data is empty');
            }

            // Extract and validate required fields.
            $slideid = (int)($data->slideid ?? 0);
            $contextid = (int)($data->contextid ?? 0);
            $classengageid = (int)($data->classengageid ?? 0);
            $docid = $data->docid ?? null;
            $options = (array)($data->options ?? []);

            if ($slideid <= 0) {
                \mtrace("ClassEngage NLP: ERROR - Invalid slide ID: {$slideid}");
                throw new \Exception('Invalid slide ID in task data');
            }

            // IDEMPOTENCY: Exit early if slide no longer exists.
            $slide = $DB->get_record('classengage_slides', ['id' => $slideid]);
            if (!$slide) {
                \mtrace("ClassEngage NLP: Slide {$slideid} not found, aborting");
                return;
            }

            \mtrace("ClassEngage NLP: Slide {$slideid} found, current status: " . ($slide->nlp_job_status ?? 'null'));

            // Exit early if already completed.
            if ($slide->nlp_job_status === 'completed') {
                \mtrace("ClassEngage NLP: Slide {$slideid} already completed, skipping");
                return;
            }

            // Mark as running with timestamp - this is critical for UI feedback.
            \mtrace("ClassEngage NLP: Updating slide {$slideid} status to 'running' at 10%");
            $this->update_slide_status($slideid, 'running', 10, null, [
                'nlp_job_started' => time(),
                'nlp_job_error' => null
            ]);

            // Verify the update worked.
            $verify = $DB->get_record('classengage_slides', ['id' => $slideid], 'nlp_job_status, nlp_job_progress');
            if ($verify) {
                \mtrace("ClassEngage NLP: Status updated to {$verify->nlp_job_status} at {$verify->nlp_job_progress}%");
            }

            // Progress: 10% - Starting.
            $this->log_progress($slideid, 10, 'Initializing NLP engine...');

            // Get the stored file for inspection if docid not provided.
            if (empty($docid)) {
                $this->log_progress($slideid, 15, 'Inspecting document...');
                $fs = get_file_storage();
                $context = \context::instance_by_id($contextid);
                $files = $fs->get_area_files($contextid, 'mod_classengage', 'slides', $slideid, 'id', false);

                if (empty($files)) {
                    throw new \Exception('Slide file not found');
                }

                $file = reset($files);

                require_once(__DIR__ . '/../nlp_generator.php');
                $generator = new \mod_classengage\nlp_generator();
                $inspection = $generator->inspect_document($file);
                $docid = $inspection['docId'];
                \mtrace("ClassEngage NLP: Document inspected, docid: {$docid}");
            }

            // Progress: 40% - Pre-processing.
            $this->update_slide_status($slideid, 'running', 40);
            $this->log_progress($slideid, 40, 'Processing document content...');

            // Generate questions via internal PHP NLP engine.
            require_once(__DIR__ . '/../nlp_generator.php');
            $generator = new \mod_classengage\nlp_generator();

            // Progress: 60% - NLP processing.
            $this->update_slide_status($slideid, 'running', 60);
            $this->log_progress($slideid, 60, 'Generating questions with AI...');

            // Use internal generation (no external HTTP dependency).
            $result = $generator->generate_questions_from_document(
                $docid,
                $classengageid,
                $slideid,
                $options
            );

            $questionids = $result['questionids'] ?? [];

            // Progress: 90% - Storing results.
            $this->update_slide_status($slideid, 'running', 90);
            $this->log_progress($slideid, 90, 'Finalizing questions...');

            // Update slide with success.
            $duration = microtime(true) - $starttime;
            $this->update_slide_status($slideid, 'completed', 100, null, [
                'nlp_questions_count' => count($questionids),
                'nlp_job_completed' => time(),
                'nlp_provider' => $result['provider'] ?? null,
                'nlp_model' => $result['model'] ?? null,
                'nlp_generation_metadata' => !empty($result['metadata']) ? json_encode($result['metadata']) : null
            ]);

            // Log success for monitoring and capacity planning.
            \mtrace(sprintf(
                "ClassEngage NLP: Completed slide %d - %d questions generated in %.2fs using %s",
                $slideid,
                count($questionids),
                $duration,
                $result['provider'] ?? 'unknown'
            ));

            // Purge Moodle caches to ensure questions appear immediately
            \purge_caches();

            // Trigger event (once only per successful generation).
            $context = \context::instance_by_id($contextid);
            $event = \mod_classengage\event\questions_generated::create([
                'objectid' => $slideid,
                'context' => $context,
                'other' => ['classengageid' => $classengageid, 'count' => count($questionids)]
            ]);
            $event->trigger();

        } catch (\Exception $e) {
            $duration = microtime(true) - $starttime;

            // Update slide with failure - use current progress if available.
            $currentprogress = 10; // Default to 10% if we fail early
            if ($slideid > 0) {
                $slide = $DB->get_record('classengage_slides', ['id' => $slideid], 'nlp_job_progress, nlp_job_status');
                if ($slide && $slide->nlp_job_status === 'running') {
                    $currentprogress = (int)$slide->nlp_job_progress;
                }
            }

            if ($slideid > 0) {
                $this->update_slide_status($slideid, 'failed', $currentprogress, $e->getMessage(), [
                    'nlp_job_completed' => time()
                ]);
            }

            \mtrace(sprintf(
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
        \mtrace("ClassEngage NLP: Slide {$slideid} - {$progress}% - {$message}");
    }
}
