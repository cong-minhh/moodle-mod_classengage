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
 * Filter interactions for analytics page
 *
 * @module     mod_classengage/analytics_filters
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    var cmid = 0;
    var sessionid = 0;
    var debounceTimer = null;
    var currentSort = {
        column: '',
        direction: 'ASC'
    };

    /**
     * Initialize filter interactions
     *
     * @param {number} courseModuleId - Course module ID
     * @param {number} selectedSessionId - Selected session ID
     */
    var init = function(courseModuleId, selectedSessionId) {
        cmid = courseModuleId;
        sessionid = selectedSessionId;

        attachFilterHandlers();
        attachSortHandlers();
        attachPaginationHandlers();
    };

    /**
     * Attach event handlers to filter controls
     */
    var attachFilterHandlers = function() {
        // Name search with debouncing
        var nameSearch = document.getElementById('filter-namesearch');
        if (nameSearch) {
            nameSearch.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    applyFilters();
                }, 300);
            });
        }

        // Score range inputs
        var minScore = document.getElementById('filter-minscore');
        if (minScore) {
            minScore.addEventListener('change', function() {
                applyFilters();
            });
        }

        var maxScore = document.getElementById('filter-maxscore');
        if (maxScore) {
            maxScore.addEventListener('change', function() {
                applyFilters();
            });
        }

        // Response time range inputs
        var minTime = document.getElementById('filter-mintime');
        if (minTime) {
            minTime.addEventListener('change', function() {
                applyFilters();
            });
        }

        var maxTime = document.getElementById('filter-maxtime');
        if (maxTime) {
            maxTime.addEventListener('change', function() {
                applyFilters();
            });
        }

        // Top performers checkbox
        var topOnly = document.getElementById('filter-toponly');
        if (topOnly) {
            topOnly.addEventListener('change', function() {
                applyFilters();
            });
        }

        // Question filter dropdown
        var questionFilter = document.getElementById('filter-questionid');
        if (questionFilter) {
            questionFilter.addEventListener('change', function() {
                applyFilters();

            });
        }

        // Per page selector
        var perPage = document.getElementById('filter-perpage');
        if (perPage) {
            perPage.addEventListener('change', function() {
                applyFilters();
            });
        }

        // Clear filters button
        var clearBtn = document.getElementById('clear-filters-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                clearFilters();
            });
        }
    };

    /**
     * Attach event handlers to sortable column headers
     */
    var attachSortHandlers = function() {
        var sortableHeaders = document.querySelectorAll('[data-sortable]');

        sortableHeaders.forEach(function(header) {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                var column = this.getAttribute('data-sortable');

                // Toggle direction if same column, otherwise default to ASC
                if (currentSort.column === column) {
                    currentSort.direction = currentSort.direction === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    currentSort.column = column;
                    currentSort.direction = 'ASC';
                }

                applyFilters();
            });
        });
    };

    /**
     * Attach event handlers to pagination controls
     */
    var attachPaginationHandlers = function() {
        var paginationLinks = document.querySelectorAll('[data-page]');

        paginationLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var page = parseInt(this.getAttribute('data-page'), 10);
                if (page > 0) {
                    navigateToPage(page);
                }
            });
        });
    };

    /**
     * Apply current filters and reload page
     */
    var applyFilters = function() {
        var params = buildUrlParams();
        var url = buildUrl(params);
        window.location.href = url;
    };

    /**
     * Clear all filters and reload page
     */
    var clearFilters = function() {
        var url = buildUrl({
            id: cmid,
            sessionid: sessionid
        });
        window.location.href = url;
    };

    /**
     * Navigate to specific page
     *
     * @param {number} page - Page number (1-based)
     */
    var navigateToPage = function(page) {
        var params = buildUrlParams();
        params.page = page;
        var url = buildUrl(params);
        window.location.href = url;
    };

    /**
     * Build URL parameters object from current filter values
     *
     * @return {Object} URL parameters
     */
    var buildUrlParams = function() {
        var params = {
            id: cmid,
            sessionid: sessionid
        };

        // Name search
        var nameSearch = document.getElementById('filter-namesearch');
        if (nameSearch && nameSearch.value.trim() !== '') {
            params.namesearch = nameSearch.value.trim();
        }

        // Score range
        var minScore = document.getElementById('filter-minscore');
        if (minScore && minScore.value !== '') {
            params.minscore = minScore.value;
        }

        var maxScore = document.getElementById('filter-maxscore');
        if (maxScore && maxScore.value !== '') {
            params.maxscore = maxScore.value;
        }

        // Response time range
        var minTime = document.getElementById('filter-mintime');
        if (minTime && minTime.value !== '') {
            params.mintime = minTime.value;
        }

        var maxTime = document.getElementById('filter-maxtime');
        if (maxTime && maxTime.value !== '') {
            params.maxtime = maxTime.value;
        }

        // Top performers only
        var topOnly = document.getElementById('filter-toponly');
        if (topOnly && topOnly.checked) {
            params.toponly = 1;
        }

        // Question filter
        var questionFilter = document.getElementById('filter-questionid');
        if (questionFilter && questionFilter.value !== '' && questionFilter.value !== '0') {
            params.questionid = questionFilter.value;
        }

        // Sorting
        if (currentSort.column !== '') {
            params.sort = currentSort.column;
            params.dir = currentSort.direction;
        }

        // Pagination
        var perPage = document.getElementById('filter-perpage');
        if (perPage && perPage.value !== '') {
            params.perpage = perPage.value;
        }

        // Current page (preserve if not explicitly changed)
        var currentPage = getCurrentPage();
        if (currentPage > 1) {
            params.page = currentPage;
        }

        return params;
    };

    /**
     * Get current page number from URL or default to 1
     *
     * @return {number} Current page number
     */
    var getCurrentPage = function() {
        var urlParams = new URLSearchParams(window.location.search);
        var page = parseInt(urlParams.get('page'), 10);
        return isNaN(page) || page < 1 ? 1 : page;
    };

    /**
     * Build URL from parameters object
     *
     * @param {Object} params - URL parameters
     * @return {string} Complete URL
     */
    var buildUrl = function(params) {
        var baseUrl = window.location.pathname;
        var queryString = Object.keys(params).map(function(key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');

        return baseUrl + '?' + queryString;
    };

    /**
     * Initialize sort indicators from URL parameters
     */
    var initializeSortState = function() {
        var urlParams = new URLSearchParams(window.location.search);
        var sortColumn = urlParams.get('sort');
        var sortDirection = urlParams.get('dir');

        if (sortColumn) {
            currentSort.column = sortColumn;
            currentSort.direction = sortDirection === 'DESC' ? 'DESC' : 'ASC';

            // Update visual indicators
            updateSortIndicators();
        }
    };

    /**
     * Update visual sort indicators on column headers
     */
    var updateSortIndicators = function() {
        var sortableHeaders = document.querySelectorAll('[data-sortable]');

        sortableHeaders.forEach(function(header) {
            var column = header.getAttribute('data-sortable');

            // Remove existing indicators
            var existingIndicator = header.querySelector('.sort-indicator');
            if (existingIndicator) {
                existingIndicator.remove();
            }

            // Add indicator if this is the sorted column
            if (column === currentSort.column) {
                var indicator = document.createElement('span');
                indicator.className = 'sort-indicator';
                indicator.innerHTML = currentSort.direction === 'ASC' ? ' ▲' : ' ▼';
                header.appendChild(indicator);
            }
        });
    };

    // Initialize sort state when module loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSortState);
    } else {
        initializeSortState();
    }

    return {
        init: init
    };
});
