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
 * Student Results JavaScript Module
 *
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification', 'core/templates'], function($, Notification, Templates) {

    'use strict';

    /**
     * Initialize the student results page
     */
    var init = function() {
        initializeTooltips();
        initializeAccordion();
        animateResults();
    };

    /**
     * Initialize Bootstrap tooltips
     */
    var initializeTooltips = function() {
        $('[data-toggle="tooltip"]').tooltip({
            html: true,
            container: 'body'
        });
    };

    /**
     * Initialize accordion behavior
     */
    var initializeAccordion = function() {
        $('.question-item').on('click', function() {
            var $this = $(this);
            var $header = $this.find('[data-toggle="collapse"]');
            var $collapse = $($header.data('target'));

            $collapse.on('shown.bs.collapse', function() {
                $header.find('.fa-chevron-down').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            });

            $collapse.on('hidden.bs.collapse', function() {
                $header.find('.fa-chevron-up').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            });
        });
    };

    /**
     * Animate the results on page load
     */
    var animateResults = function() {
        $('.question-item').each(function(index) {
            var $item = $(this);
            $item.css({
                'opacity': 0,
                'transform': 'translateY(20px)'
            });

            setTimeout(function() {
                $item.animate({
                    'opacity': 1,
                    'transform': 'translateY(0)'
                }, 300, 'swing');
            }, index * 100);
        });
    };

    /**
     * Print results functionality
     */
    var printResults = function() {
        window.print();
    };

    return {
        init: init,
        printResults: printResults
    };
});
