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
 * @copyright  2025 Your Name
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
     * @return \stdClass Chart.js compatible data object
     */
    public static function transform_timeline_data($timeline) {
        $data = new \stdClass();
        $data->labels = array_map(function($t) { 
            return $t['label']; 
        }, $timeline);
        $data->data = array_map(function($t) { 
            return $t['count']; 
        }, $timeline);
        $data->peaks = array_map(function($t) { 
            return isset($t['is_peak']) && $t['is_peak']; 
        }, $timeline);
        $data->dips = array_map(function($t) { 
            return isset($t['is_dip']) && $t['is_dip']; 
        }, $timeline);
        
        return $data;
    }
    
    /**
     * Transform concept difficulty data for Chart.js
     *
     * @param array $conceptdifficulty Concept difficulty data from comprehension analyzer
     * @return \stdClass Chart.js compatible data object
     */
    public static function transform_difficulty_data($conceptdifficulty) {
        $data = new \stdClass();
        $data->labels = array_map(function($c) { 
            return 'Q' . $c->question_order; 
        }, $conceptdifficulty);
        $data->data = array_map(function($c) { 
            return $c->correctness_rate; 
        }, $conceptdifficulty);
        $data->colors = array_map(function($c) {
            return chart_colors::get_difficulty_color($c->difficulty_level);
        }, $conceptdifficulty);
        
        return $data;
    }
    
    /**
     * Transform participation distribution data for Chart.js
     *
     * @param array $distribution Participation distribution data
     * @return \stdClass Chart.js compatible data object
     */
    public static function transform_distribution_data($distribution) {
        $data = new \stdClass();
        $data->labels = [
            get_string('participationhigh', 'mod_classengage'),
            get_string('participationmoderate', 'mod_classengage'),
            get_string('participationlow', 'mod_classengage'),
            get_string('participationnone', 'mod_classengage')
        ];
        $data->data = [
            $distribution->high ?? 0,
            $distribution->moderate ?? 0,
            $distribution->low ?? 0,
            $distribution->none ?? 0
        ];
        $data->colors = chart_colors::get_participation_colors();
        
        return $data;
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
