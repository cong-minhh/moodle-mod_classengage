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
 * Adhoc task for asynchronous document inspection
 * 
 * This task handles PDF document inspection (text extraction + image rendering)
 * in the background, allowing the webserver to remain lightweight.
 * 
 * Flow:
 * 1. User uploads PDF
 * 2. Webserver queues this inspection task
 * 3. Worker executes: Extract text, render page images
 * 4. Results stored in slide record
 * 5. UI polls for completion and shows preview
 * 
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Class inspect_document_task
 */
class inspect_document_task extends \core\task\adhoc_task {

    /**
     * Get task name
     * @return string
     */
    public function get_name() {
        return get_string('task_inspect_document', 'mod_classengage');
    }

    /**
     * Execute the task
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        
        // Extract parameters
        $slideid = $data->slideid ?? 0;
        $classengageid = $data->classengageid ?? 0;
        $contextid = $data->contextid ?? 0;

        if ($slideid <= 0) {
            \mtrace("ClassEngage Inspection: Invalid slide ID: {$slideid}");
            return;
        }

        \mtrace("ClassEngage Inspection: Starting inspection for slide {$slideid}");

        // Get slide record
        $slide = $DB->get_record('classengage_slides', ['id' => $slideid]);
        if (!$slide) {
            \mtrace("ClassEngage Inspection: Slide {$slideid} not found");
            return;
        }

        // Mark as running
        $this->update_slide_status($slideid, 'inspecting', 10);

        try {
            // Get the file from storage
            $fs = get_file_storage();
            $files = $fs->get_area_files($contextid, 'mod_classengage', 'slides', $slideid, 'id', false);
            
            if (empty($files)) {
                throw new \Exception('Slide file not found in storage');
            }

            $file = reset($files);

            // Update progress
            $this->update_slide_status($slideid, 'inspecting', 30);

            // Perform inspection
            require_once(__DIR__ . '/../nlp_generator.php');
            $generator = new \mod_classengage\nlp_generator();
            $inspection = $generator->inspect_document($file);

            // Update progress
            $this->update_slide_status($slideid, 'inspecting', 80);

            // Store inspection results in slide metadata
            $inspection_data = [
                'docId' => $inspection['docId'],
                'pages' => $inspection['pages'],
                'inspected_at' => time(),
                'page_count' => count($inspection['pages'])
            ];

            // Update slide record with inspection data
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_generation_metadata' => json_encode($inspection_data),
                'nlp_job_status' => 'inspected',  // Mark as inspected and ready
                'nlp_job_progress' => 100,
                'timemodified' => time()
            ]);

            \mtrace("ClassEngage Inspection: Completed for slide {$slideid}. Found " . count($inspection['pages']) . " pages");

        } catch (\Exception $e) {
            \mtrace("ClassEngage Inspection: Failed for slide {$slideid}: " . $e->getMessage());
            
            // Mark as failed
            $DB->update_record('classengage_slides', (object) [
                'id' => $slideid,
                'nlp_job_status' => 'inspect_failed',
                'nlp_job_error' => $e->getMessage(),
                'timemodified' => time()
            ]);
        }
    }

    /**
     * Update slide inspection status
     * 
     * @param int $slideid
     * @param string $status
     * @param int $progress
     */
    private function update_slide_status($slideid, $status, $progress) {
        global $DB;
        
        $DB->update_record('classengage_slides', (object) [
            'id' => $slideid,
            'nlp_job_status' => $status,
            'nlp_job_progress' => $progress,
            'timemodified' => time()
        ]);
    }
}
