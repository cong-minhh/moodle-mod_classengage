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
 * Questions table interactions for ClassEngage
 *
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    /**
     * Initialize the questions table functionality.
     *
     * @returns {object} Public methods
     */
    return {
        init: function() {
            $(document).ready(function() {
                initQuestionsTable();
            });
        }
    };

    /**
     * Initialize all questions tables on the page.
     */
    function initQuestionsTable() {
        $('.questions-table-container').each(function() {
            initSingleTable($(this));
        });
    }

    /**
     * Initialize a single table container with all event handlers.
     *
     * @param {jQuery} container The table container element
     */
    function initSingleTable(container) {
        var table = container.find('table.questions-table');
        var tbody = table.find('tbody');
        var searchInput = container.find('.question-search-input');
        var selectAll = container.find('.select-all-questions');
        var filterBtns = container.find('.filter-btn');
        var sortableHeaders = table.find('thead .sortable-col');

        // Select all functionality.
        selectAll.off('change').on('change', function() {
            tbody.find('.question-checkbox').prop('checked', $(this).prop('checked'));
        });

        // Individual checkbox change.
        tbody.off('change', '.question-checkbox').on('change', '.question-checkbox', function() {
            var total = tbody.find('.question-checkbox').length;
            var checked = tbody.find('.question-checkbox:checked').length;
            selectAll.prop('checked', total === checked);
            selectAll.prop('indeterminate', checked > 0 && checked < total);
        });

        // Filter buttons.
        filterBtns.off('click').on('click', function() {
            var filter = $(this).data('filter');
            filterBtns.removeClass('active');
            $(this).addClass('active');
            applyFilter(container, filter, searchInput.val());
        });

        // Search input.
        searchInput.off('input').on('input', function() {
            var activeFilter = 'all';
            filterBtns.each(function() {
                if ($(this).hasClass('active')) {
                    activeFilter = $(this).data('filter');
                }
            });
            applyFilter(container, activeFilter, $(this).val());
        });

        // Sortable columns.
        sortableHeaders.off('click').on('click', function() {
            var col = $(this);
            var sortType = col.data('sort');
            var currentOrder = col.hasClass('sort-asc') ? 'asc' : (col.hasClass('sort-desc') ? 'desc' : 'none');
            var newOrder = currentOrder === 'asc' ? 'desc' : 'asc';

            // Update header classes.
            sortableHeaders.removeClass('sort-asc sort-desc');
            col.addClass('sort-' + newOrder);

            // Get visible rows.
            var visibleRows = tbody.find('tr:visible').get();

            // Sort.
            visibleRows.sort(function(a, b) {
                return sortTableRows(a, b, sortType, newOrder);
            });

            // Re-append.
            $.each(visibleRows, function(idx, row) {
                tbody.append(row);
            });
        });

        // Initialize tooltips.
        container.find('[data-toggle="tooltip"]').tooltip();
    }

    /**
     * Apply filter and search to the table rows.
     *
     * @param {jQuery} container The table container
     * @param {string} filter The filter to apply
     * @param {string} searchTerm The search term
     */
    function applyFilter(container, filter, searchTerm) {
        var tbody = container.find('tbody');
        var table = container.find('table');
        var noResults = container.find('.no-results-message');
        var count = 0;

        searchTerm = (searchTerm || '').toLowerCase().trim();

        tbody.find('tr').each(function() {
            var row = $(this);
            var trustTd = row.find('td[data-trustworthiness]');
            var trustLevel = trustTd.data('trustworthiness') || '';
            var questionText = row.find('.question-text-cell').text().toLowerCase();

            var showByFilter = (filter === 'all' || trustLevel === filter);
            var showBySearch = !searchTerm || questionText.includes(searchTerm);

            if (showByFilter && showBySearch) {
                row.show();
                count++;
            } else {
                row.hide();
            }
        });

        if (count === 0) {
            table.hide();
            noResults.show();
        } else {
            table.show();
            noResults.hide();
        }
    }

    /**
     * Sort two table rows based on column type.
     *
     * @param {HTMLElement} a First row element
     * @param {HTMLElement} b Second row element
     * @param {string} sortType Type of sort
     * @param {string} order Sort order (asc/desc)
     * @returns {number} Sort comparison result
     */
    function sortTableRows(a, b, sortType, order) {
        var aCell = $(a).children('td').eq(getColumnIndex(a, sortType));
        var bCell = $(b).children('td').eq(getColumnIndex(b, sortType));
        var aVal;
        var bVal;

        switch (sortType) {
            case 'date':
                aVal = parseInt(aCell.data('timestamp')) || 0;
                bVal = parseInt(bCell.data('timestamp')) || 0;
                break;
            case 'trustworthiness':
                aVal = parseInt(aCell.find('.trust-score').text()) || 0;
                bVal = parseInt(bCell.find('.trust-score').text()) || 0;
                break;
            case 'difficulty':
                var diffOrder = {easy: 1, medium: 2, hard: 3};
                aVal = diffOrder[aCell.text().trim().toLowerCase()] || 0;
                bVal = diffOrder[bCell.text().trim().toLowerCase()] || 0;
                break;
            case 'status':
                aVal = aCell.text().trim().toLowerCase();
                bVal = bCell.text().trim().toLowerCase();
                break;
            case 'bloom':
                var bloomOrder = {remember: 1, understand: 2, apply: 3, analyze: 4, evaluate: 5, create: 6};
                aVal = bloomOrder[aCell.text().trim().toLowerCase()] || 0;
                bVal = bloomOrder[bCell.text().trim().toLowerCase()] || 0;
                break;
            default:
                aVal = aCell.text().trim().toLowerCase();
                bVal = bCell.text().trim().toLowerCase();
        }

        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return order === 'asc' ? aVal - bVal : bVal - aVal;
        } else {
            if (aVal < bVal) {
                return order === 'asc' ? -1 : 1;
            }
            if (aVal > bVal) {
                return order === 'asc' ? 1 : -1;
            }
            return 0;
        }
    }

    /**
     * Get the column index for a given sort type.
     *
     * @param {HTMLElement} row Table row element
     * @param {string} sortType The sort type to find
     * @returns {number} Column index
     */
    function getColumnIndex(row, sortType) {
        var header = $(row).closest('table').find('thead th');
        for (var i = 0; i < header.length; i++) {
            if ($(header[i]).data('sort') === sortType) {
                return i;
            }
        }
        return 0;
    }
});
