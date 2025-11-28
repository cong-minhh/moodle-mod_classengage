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
 * Chart color constants for analytics visualizations
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Chart color constants
 *
 * Centralizes color definitions for charts to enable easy theme customization.
 */
class chart_colors {
    /** @var string Color for difficult concepts (red) */
    const DIFFICULTY_HARD = '#dc3545';
    
    /** @var string Color for moderate difficulty concepts (yellow) */
    const DIFFICULTY_MODERATE = '#ffc107';
    
    /** @var string Color for easy concepts (green) */
    const DIFFICULTY_EASY = '#28a745';
    
    /** @var string Color for high participation (green) */
    const PARTICIPATION_HIGH = '#28a745';
    
    /** @var string Color for moderate participation (blue) */
    const PARTICIPATION_MODERATE = '#007bff';
    
    /** @var string Color for low participation (yellow) */
    const PARTICIPATION_LOW = '#ffc107';
    
    /** @var string Color for no participation (gray) */
    const PARTICIPATION_NONE = '#6c757d';
    
    /**
     * Get color for difficulty level
     *
     * @param string $level Difficulty level: 'difficult', 'moderate', or 'easy'
     * @return string Hex color code
     */
    public static function get_difficulty_color($level) {
        switch ($level) {
            case 'difficult':
                return self::DIFFICULTY_HARD;
            case 'moderate':
                return self::DIFFICULTY_MODERATE;
            case 'easy':
            default:
                return self::DIFFICULTY_EASY;
        }
    }
    
    /**
     * Get participation distribution colors
     *
     * @return array Array of hex color codes [high, moderate, low, none]
     */
    public static function get_participation_colors() {
        return [
            self::PARTICIPATION_HIGH,
            self::PARTICIPATION_MODERATE,
            self::PARTICIPATION_LOW,
            self::PARTICIPATION_NONE
        ];
    }
}
