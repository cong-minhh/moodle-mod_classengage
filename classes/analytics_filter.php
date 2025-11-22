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
 * Analytics filter class for parameter validation and encapsulation
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics filter class
 *
 * Validates, sanitizes, and encapsulates filter parameters for analytics queries.
 */
class analytics_filter {

    /** @var string|null Student name search term */
    protected $namesearch;

    /** @var float|null Minimum score percentage */
    protected $minscore;

    /** @var float|null Maximum score percentage */
    protected $maxscore;

    /** @var float|null Minimum response time in seconds */
    protected $minresponsetime;

    /** @var float|null Maximum response time in seconds */
    protected $maxresponsetime;

    /** @var bool Show only top performers */
    protected $topperformersonly;

    /** @var int|null Filter by specific question ID */
    protected $questionfilter;

    /** @var string Sort column name */
    protected $sortcolumn;

    /** @var string Sort direction (ASC or DESC) */
    protected $sortdirection;

    /** @var int Current page number (1-based) */
    protected $page;

    /** @var int Records per page */
    protected $perpage;

    /** @var array Allowed sort columns */
    const ALLOWED_SORT_COLUMNS = [
        'fullname',
        'totalresponses',
        'correctresponses',
        'percentage',
        'avgresponsetime'
    ];

    /** @var array Allowed per page values */
    const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    /**
     * Constructor
     *
     * @param array $params Filter parameters from request
     */
    public function __construct(array $params) {
        $this->validate_and_set_params($params);
    }

    /**
     * Validate and set filter parameters
     *
     * @param array $params Raw parameters
     */
    protected function validate_and_set_params(array $params) {
        // Name search: trimmed string, max 255 chars.
        $this->namesearch = isset($params['namesearch']) ? 
            substr(trim($params['namesearch']), 0, 255) : null;
        if ($this->namesearch === '') {
            $this->namesearch = null;
        }

        // Score range: 0-100, min <= max.
        $this->minscore = isset($params['minscore']) ? 
            max(0, min(100, (float)$params['minscore'])) : null;
        $this->maxscore = isset($params['maxscore']) ? 
            max(0, min(100, (float)$params['maxscore'])) : null;

        // Ensure min <= max.
        if ($this->minscore !== null && $this->maxscore !== null && $this->minscore > $this->maxscore) {
            $temp = $this->minscore;
            $this->minscore = $this->maxscore;
            $this->maxscore = $temp;
        }

        // Response time: >= 0, min <= max.
        $this->minresponsetime = isset($params['mintime']) ? 
            max(0, (float)$params['mintime']) : null;
        $this->maxresponsetime = isset($params['maxtime']) ? 
            max(0, (float)$params['maxtime']) : null;

        // Ensure min <= max.
        if ($this->minresponsetime !== null && $this->maxresponsetime !== null && 
            $this->minresponsetime > $this->maxresponsetime) {
            $temp = $this->minresponsetime;
            $this->minresponsetime = $this->maxresponsetime;
            $this->maxresponsetime = $temp;
        }

        // Top performers: boolean.
        $this->topperformersonly = !empty($params['toponly']);

        // Question filter: valid question ID or null.
        $this->questionfilter = isset($params['questionid']) && $params['questionid'] > 0 ? 
            (int)$params['questionid'] : null;

        // Sort column: whitelist validation.
        $sortcolumn = isset($params['sort']) ? $params['sort'] : 'percentage';
        $this->sortcolumn = in_array($sortcolumn, self::ALLOWED_SORT_COLUMNS) ? 
            $sortcolumn : 'percentage';

        // Sort direction: ASC or DESC only.
        $sortdir = isset($params['dir']) ? strtoupper($params['dir']) : 'DESC';
        $this->sortdirection = in_array($sortdir, ['ASC', 'DESC']) ? $sortdir : 'DESC';

        // Page: >= 1.
        $this->page = isset($params['page']) ? max(1, (int)$params['page']) : 1;

        // Per page: one of allowed values.
        $perpage = isset($params['perpage']) ? (int)$params['perpage'] : 25;
        $this->perpage = in_array($perpage, self::ALLOWED_PER_PAGE) ? $perpage : 25;
    }

    /**
     * Get name search term
     *
     * @return string|null
     */
    public function get_name_search() {
        return $this->namesearch;
    }

    /**
     * Get minimum score
     *
     * @return float|null
     */
    public function get_min_score() {
        return $this->minscore;
    }

    /**
     * Get maximum score
     *
     * @return float|null
     */
    public function get_max_score() {
        return $this->maxscore;
    }

    /**
     * Get minimum response time
     *
     * @return float|null
     */
    public function get_min_response_time() {
        return $this->minresponsetime;
    }

    /**
     * Get maximum response time
     *
     * @return float|null
     */
    public function get_max_response_time() {
        return $this->maxresponsetime;
    }

    /**
     * Get top performers only flag
     *
     * @return bool
     */
    public function get_top_performers_only() {
        return $this->topperformersonly;
    }

    /**
     * Get question filter
     *
     * @return int|null
     */
    public function get_question_filter() {
        return $this->questionfilter;
    }

    /**
     * Get sort column
     *
     * @return string
     */
    public function get_sort_column() {
        return $this->sortcolumn;
    }

    /**
     * Get sort direction
     *
     * @return string
     */
    public function get_sort_direction() {
        return $this->sortdirection;
    }

    /**
     * Get current page number
     *
     * @return int
     */
    public function get_page() {
        return $this->page;
    }

    /**
     * Get records per page
     *
     * @return int
     */
    public function get_per_page() {
        return $this->perpage;
    }

    /**
     * Convert filter to URL parameters
     *
     * @return array
     */
    public function to_url_params() {
        $params = [];

        if ($this->namesearch !== null) {
            $params['namesearch'] = $this->namesearch;
        }
        if ($this->minscore !== null) {
            $params['minscore'] = $this->minscore;
        }
        if ($this->maxscore !== null) {
            $params['maxscore'] = $this->maxscore;
        }
        if ($this->minresponsetime !== null) {
            $params['mintime'] = $this->minresponsetime;
        }
        if ($this->maxresponsetime !== null) {
            $params['maxtime'] = $this->maxresponsetime;
        }
        if ($this->topperformersonly) {
            $params['toponly'] = 1;
        }
        if ($this->questionfilter !== null) {
            $params['questionid'] = $this->questionfilter;
        }
        if ($this->sortcolumn !== 'percentage') {
            $params['sort'] = $this->sortcolumn;
        }
        if ($this->sortdirection !== 'DESC') {
            $params['dir'] = $this->sortdirection;
        }
        if ($this->page !== 1) {
            $params['page'] = $this->page;
        }
        if ($this->perpage !== 25) {
            $params['perpage'] = $this->perpage;
        }

        return $params;
    }

    /**
     * Check if any filters are active
     *
     * @return bool
     */
    public function is_filtered() {
        return $this->namesearch !== null ||
               $this->minscore !== null ||
               $this->maxscore !== null ||
               $this->minresponsetime !== null ||
               $this->maxresponsetime !== null ||
               $this->topperformersonly ||
               $this->questionfilter !== null;
    }
}
