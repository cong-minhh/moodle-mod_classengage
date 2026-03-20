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
        initializeQuickNav();
        initializeKeyboardNav();
        initializePrintStyles();
    };

    /**
     * Initialize Bootstrap tooltips
     */
    var initializeTooltips = function() {
        $('[data-toggle="tooltip"]').tooltip({
            html: true,
            container: 'body',
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
                'transform': 'translateY(20px)',
            });

            setTimeout(function() {
                $item.animate({
                    'opacity': 1,
                    'transform': 'translateY(0)',
                }, 300, 'swing');
            }, index * 100);
        });
    };

    /**
     * Initialize quick navigation
     */
    var initializeQuickNav = function() {
        var $navBar = $('.student-results-page .sticky-top');
        var $questions = $('.question-item');

        if ($questions.length === 0) {
            return;
        }

        // Create quick nav pills if not exists
        if ($('#quick-nav-pills').length === 0) {
            var pillsHtml = '<div id="quick-nav-pills" class="d-flex flex-wrap gap-1 justify-content-center mt-2">';
            $questions.each(function(index) {
                var $q = $(this);
                var num = index + 1;
                var isCorrect = $q.hasClass('border-left-success');
                var pillClass = isCorrect ? 'badge-success' : 'badge-danger';
                var btnStyle = 'cursor: pointer; border: none; padding: 4px 8px;';
                pillsHtml += '<button class="badge ' + pillClass + ' quick-nav-pill" ';
                pillsHtml += 'data-question="' + num + '" title="Question ' + num + '" ';
                pillsHtml += 'style="' + btnStyle + '">' + num + '</button>';
            });
            pillsHtml += '</div>';
            $navBar.after(pillsHtml);

            // Click handler for pills
            $('.quick-nav-pill').on('click', function() {
                var qNum = $(this).data('question');
                var $target = $questions.eq(qNum - 1);
                if ($target.length) {
                    // Collapse all first
                    $('.question-item .collapse').collapse('hide');
                    // Open target
                    var $collapse = $target.find('.collapse');
                    $collapse.collapse('show');
                    // Scroll to it
                    $('html, body').animate({
                        scrollTop: $target.offset().top - 100,
                    }, 500);
                    // Update active state
                    $('.quick-nav-pill').removeClass('active');
                    $(this).addClass('active');
                }
            });
        }
    };

    /**
     * Initialize keyboard navigation
     */
    var initializeKeyboardNav = function() {
        $(document).on('keydown', function(e) {
            // Only if not in input/select
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            var $questions = $('.question-item');
            var $openQuestion = $('.question-item .collapse.show');
            var currentIndex = -1;

            if ($openQuestion.length) {
                currentIndex = $questions.index($openQuestion.closest('.question-item'));
            }

            switch (e.key) {
            case 'j': // Next question
            case 'ArrowDown':
                e.preventDefault();
                if (currentIndex < $questions.length - 1) {
                    var $next = $questions.eq(currentIndex + 1);
                    var $collapse = $next.find('.collapse');
                    $questions.find('.collapse').collapse('hide');
                    $collapse.collapse('show');
                    $('html, body').animate({
                        scrollTop: $next.offset().top - 100,
                    }, 300);
                }
                break;
            case 'k': // Previous question
            case 'ArrowUp':
                e.preventDefault();
                if (currentIndex > 0) {
                    var $prev = $questions.eq(currentIndex - 1);
                    var $collapsePrev = $prev.find('.collapse');
                    $questions.find('.collapse').collapse('hide');
                    $collapsePrev.collapse('show');
                    $('html, body').animate({
                        scrollTop: $prev.offset().top - 100,
                    }, 300);
                }
                break;
            case 'Escape':
                $questions.find('.collapse').collapse('hide');
                break;
            }
        });
    };

    /**
     * Initialize print-specific styles
     */
    var initializePrintStyles = function() {
        // Add print button functionality
        $('#print-results-btn').on('click', function() {
            window.print();
        });
    };

    /**
     * Print results functionality
     */
    var printResults = function() {
        window.print();
    };

    /**
     * Scroll to specific question
     *
     * @param {number} questionNumber The question number to scroll to (1-indexed)
     */
    var scrollToQuestion = function(questionNumber) {
        var $questions = $('.question-item');
        var $target = $questions.eq(questionNumber - 1);
        if ($target.length) {
            $questions.find('.collapse').collapse('hide');
            var $collapse = $target.find('.collapse');
            $collapse.collapse('show');
            $('html, body').animate({
                scrollTop: $target.offset().top - 100,
            }, 500);
        }
    };

    return {
        init: init,
        printResults: printResults,
        scrollToQuestion: scrollToQuestion,
    };
});
