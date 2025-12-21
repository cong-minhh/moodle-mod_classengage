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
 * Scheduled task to process response queue
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

use mod_classengage\response_capture_engine;

defined('MOODLE_INTERNAL') || die();

/**
 * Process response queue task
 *
 * Processes pending responses in the queue for batch efficiency.
 */
class process_response_queue extends \core\task\scheduled_task {

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:processresponsequeue', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute(): void {
        $engine = new response_capture_engine();
        $result = $engine->process_queue(100);

        if ($result->processedcount > 0 || $result->failedcount > 0) {
            mtrace("ClassEngage: Processed {$result->processedcount} queued responses, {$result->failedcount} failed");
        }
    }
}
