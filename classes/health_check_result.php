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
 * Health check result class for ClassEngage plugin
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Health check result class
 *
 * Represents the result of a single health check component.
 */
class health_check_result {
    /** @var string Component name */
    public string $component;

    /** @var bool Health status */
    public bool $healthy;

    /** @var string Status message */
    public string $message;

    /** @var float Response time in milliseconds */
    public float $response_time_ms;

    /** @var array Additional details */
    public array $details;

    /**
     * Constructor
     *
     * @param string $component Component name
     * @param bool $healthy Health status
     * @param string $message Status message
     * @param float $responsetimems Response time in ms
     * @param array $details Additional details
     */
    public function __construct(
        string $component,
        bool $healthy,
        string $message,
        float $responsetimems = 0.0,
        array $details = []
    ) {
        $this->component = $component;
        $this->healthy = $healthy;
        $this->message = $message;
        $this->response_time_ms = $responsetimems;
        $this->details = $details;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'component' => $this->component,
            'healthy' => $this->healthy,
            'message' => $this->message,
            'response_time_ms' => $this->response_time_ms,
            'details' => $this->details,
        ];
    }
}
