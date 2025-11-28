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
 * Chart data transformation utilities
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Chart data transformer class
 *
 * Transforms analytics data into Chart.js-compatible format.
 */
class chart_data_transformer {
    
    /**
     * Transform engagement timeline data for Chart.js
     *
     * @param array $timeline Timeline data from analytics engine
     * @return array Timeline data (passed through as-is for JavaScript processing)
     */
    public static function transform_timeline_data($timeline) {
        // JavaScript expects array of objects with label, count, is_peak, is_dip
        // Just return as-is since analytics_engine already provides correct structure
        return $timeline;
    }
    
    /**
     * Transform concept difficulty data for Chart.js
     *
     * @param array $conceptdifficulty Concept difficulty data from comprehension analyzer
     * @return array Difficulty data (passed through as-is for JavaScript processing)
     */
    public static function transform_difficulty_data($conceptdifficulty) {
        // JavaScript expects array of objects with question_text and correctness_rate
        // Just return as-is since comprehension_analyzer already provides correct structure
        return $conceptdifficulty;
    }
    
    /**
     * Transform participation distribution data for Chart.js
     *
     * @param object $distribution Participation distribution data
     * @return object Distribution data (passed through as-is for JavaScript processing)
     */
    public static function transform_distribution_data($distribution) {
        // JavaScript expects object with high, moderate, low, none properties
        // Just return as-is since analytics_engine already provides correct structure
        return $distribution;
    }
    
    /**
     * Transform all analytics data to chart format
     *
     * @param array $engagementtimeline Timeline data
     * @param array $conceptdifficulty Concept difficulty data
     * @param array $participationdistribution Participation distribution data
     * @return \stdClass Complete chart data object
     */
    public static function transform_all_chart_data($engagementtimeline, $conceptdifficulty, $participationdistribution) {
        $chartdata = new \stdClass();
        $chartdata->timeline = self::transform_timeline_data($engagementtimeline);
        $chartdata->difficulty = self::transform_difficulty_data($conceptdifficulty);
        $chartdata->distribution = self::transform_distribution_data($participationdistribution);
        
        return $chartdata;
    }
}
