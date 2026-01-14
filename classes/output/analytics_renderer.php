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
 * Analytics renderer for enhanced analytics page
 *
 * This renderer provides methods for displaying analytics data including:
 * - Filter toolbars with search, score ranges, and pagination controls
 * - Summary cards showing engagement, comprehension, and performance metrics
 * - Student performance tables with sorting and highlighting
 * - Chart containers for visualizations
 * - Insights panels for at-risk students and missing participants
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\output;

use plugin_renderer_base;
use html_writer;
use html_table;
use moodle_url;
use mod_classengage\analytics_filter;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer for analytics page components
 *
 * Provides rendering methods for all analytics UI components following Moodle's
 * renderer pattern. All methods use html_writer for output generation and follow
 * Bootstrap 4 styling conventions.
 */
class analytics_renderer extends plugin_renderer_base
{

    // Bootstrap CSS classes.
    const CLASS_CARD = 'card';
    const CLASS_CARD_HEADER = 'card-header';
    const CLASS_CARD_BODY = 'card-body';
    const CLASS_MARGIN_BOTTOM_2 = 'mb-2';
    const CLASS_MARGIN_BOTTOM_3 = 'mb-3';
    const CLASS_MARGIN_BOTTOM_4 = 'mb-4';
    const CLASS_TEXT_CENTER = 'text-center';
    const CLASS_TEXT_MUTED = 'text-muted';
    const CLASS_TEXT_WHITE = 'text-white';

    // Bootstrap color classes.
    const COLOR_SUCCESS = 'success';
    const COLOR_WARNING = 'warning';
    const COLOR_DANGER = 'danger';
    const COLOR_INFO = 'info';
    const COLOR_PRIMARY = 'primary';
    const COLOR_SECONDARY = 'secondary';

    // Engagement levels.
    const LEVEL_HIGH = 'high';
    const LEVEL_MODERATE = 'moderate';
    const LEVEL_LOW = 'low';

    // Comprehension levels.
    const LEVEL_STRONG = 'strong';
    const LEVEL_PARTIAL = 'partial';
    const LEVEL_WEAK = 'weak';

    // Responsiveness pace.
    const PACE_QUICK = 'quick';
    const PACE_NORMAL = 'normal';
    const PACE_SLOW = 'slow';

    // Level to color mappings.
    const ENGAGEMENT_COLORS = [
        self::LEVEL_HIGH => self::COLOR_SUCCESS,
        self::LEVEL_MODERATE => self::COLOR_WARNING,
        self::LEVEL_LOW => self::COLOR_DANGER,
    ];

    const COMPREHENSION_COLORS = [
        self::LEVEL_STRONG => self::COLOR_SUCCESS,
        self::LEVEL_PARTIAL => self::COLOR_WARNING,
        self::LEVEL_WEAK => self::COLOR_DANGER,
    ];

    // Pace to icon mappings.
    const PACE_ICONS = [
        self::PACE_QUICK => '↑',
        self::PACE_NORMAL => '→',
        self::PACE_SLOW => '↓',
    ];

    const PACE_COLORS = [
        self::PACE_QUICK => 'text-success',
        self::PACE_NORMAL => self::CLASS_TEXT_MUTED,
        self::PACE_SLOW => 'text-warning',
    ];

    /**
     * Get color class for engagement level
     *
     * @param string $level Engagement level (high, moderate, low)
     * @return string Bootstrap color class
     */
    protected function get_engagement_color($level)
    {
        return self::ENGAGEMENT_COLORS[$level] ?? self::COLOR_SECONDARY;
    }

    /**
     * Get color class for comprehension level
     *
     * @param string $level Comprehension level (strong, partial, weak)
     * @return string Bootstrap color class
     */
    protected function get_comprehension_color($level)
    {
        return self::COMPREHENSION_COLORS[$level] ?? self::COLOR_SECONDARY;
    }



    /**
     * Render a standard card with header and body
     *
     * @param string $title Card title
     * @param string $content Card body content (HTML)
     * @param string $bordercolor Bootstrap color class for border
     * @param string $headerclass Additional classes for header
     * @return string HTML output
     */
    protected function render_card($title, $content, $bordercolor, $headerclass = '')
    {
        $parts = [];

        $parts[] = html_writer::start_div(self::CLASS_CARD . ' border-' . $bordercolor . ' ' . self::CLASS_MARGIN_BOTTOM_4 . ' h-100');
        $parts[] = html_writer::start_div(self::CLASS_CARD_HEADER . ' bg-' . $bordercolor . ' ' .
            self::CLASS_TEXT_WHITE . ' ' . $headerclass);
        $parts[] = html_writer::tag('h5', $title, ['class' => 'mb-0']);
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::start_div(self::CLASS_CARD_BODY . ' d-flex flex-column');
        $parts[] = $content;
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::end_div();

        return implode('', $parts);
    }

    /**
     * Render a two-column row with label and value
     *
     * @param string $label Label text
     * @param string|int $value Value to display
     * @param int $labelwidth Column width for label (out of 12)
     * @return string HTML output
     */
    protected function render_label_value_row($label, $value, $labelwidth = 8)
    {
        $valuewidth = 12 - $labelwidth;

        $parts = [];
        $parts[] = html_writer::start_div('row ' . self::CLASS_MARGIN_BOTTOM_2);
        $parts[] = html_writer::start_div('col-' . $labelwidth);
        $parts[] = html_writer::tag('strong', $label);
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::start_div('col-' . $valuewidth . ' text-right');
        $parts[] = html_writer::tag('span', $value, [
            'aria-label' => $label . ': ' . $value
        ]);
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::end_div();

        return implode('', $parts);
    }

    /**
     * Render filter toolbar with all filter controls
     *
     * @param analytics_filter $filter Current filter state
     * @param array $sessions Available sessions (id => name)
     * @param int $sessionid Current session ID
     * @param int $cmid Course module ID
     * @param array $questions Available questions for filtering (id => order)
     * @return string HTML output
     */
    public function render_filter_toolbar($filter, $sessions, $sessionid, $cmid, $questions = [])
    {
        $output = '';

        // Start sticky toolbar container.
        $output .= html_writer::start_div('analytics-filter-toolbar sticky-top bg-white border-bottom p-3 mb-4', [
            'role' => 'region',
            'aria-label' => get_string('filterform', 'mod_classengage')
        ]);

        $output .= html_writer::tag('h5', get_string('filtertoolbar', 'mod_classengage'), ['class' => 'mb-3']);

        // Start form.
        $formurl = new moodle_url('/mod/classengage/analytics.php');
        $output .= html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $formurl->out_omit_querystring(),
            'class' => 'form-inline',
            'id' => 'analytics-filter-form'
        ]);

        // Hidden fields.
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'id',
            'value' => $cmid
        ]);
        $output .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sessionid',
            'value' => $sessionid
        ]);

        $output .= html_writer::start_div('row w-100');

        // Name search.
        $output .= html_writer::start_div('col-md-3 mb-2');
        $output .= html_writer::label(get_string('namesearch', 'mod_classengage'), 'namesearch', false, ['class' => 'sr-only']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'namesearch',
            'id' => 'namesearch',
            'class' => 'form-control w-100',
            'placeholder' => get_string('namesearch', 'mod_classengage'),
            'value' => $filter->get_name_search() ?? '',
            'aria-label' => get_string('namesearch', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        // Score range.
        $output .= html_writer::start_div('col-md-2 mb-2');
        $output .= html_writer::label(get_string('minscore', 'mod_classengage'), 'minscore', false, ['class' => 'sr-only']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'minscore',
            'id' => 'minscore',
            'class' => 'form-control w-100',
            'placeholder' => get_string('minscore', 'mod_classengage'),
            'min' => '0',
            'max' => '100',
            'step' => '1',
            'value' => $filter->get_min_score() !== null ? $filter->get_min_score() : '',
            'aria-label' => get_string('minscore', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::start_div('col-md-2 mb-2');
        $output .= html_writer::label(get_string('maxscore', 'mod_classengage'), 'maxscore', false, ['class' => 'sr-only']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'maxscore',
            'id' => 'maxscore',
            'class' => 'form-control w-100',
            'placeholder' => get_string('maxscore', 'mod_classengage'),
            'min' => '0',
            'max' => '100',
            'step' => '1',
            'value' => $filter->get_max_score() !== null ? $filter->get_max_score() : '',
            'aria-label' => get_string('maxscore', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        // Response time range.
        $output .= html_writer::start_div('col-md-2 mb-2');
        $output .= html_writer::label(get_string('minresponsetime', 'mod_classengage'), 'mintime', false, ['class' => 'sr-only']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'mintime',
            'id' => 'mintime',
            'class' => 'form-control w-100',
            'placeholder' => get_string('minresponsetime', 'mod_classengage'),
            'min' => '0',
            'step' => '0.1',
            'value' => $filter->get_min_response_time() !== null ? $filter->get_min_response_time() : '',
            'aria-label' => get_string('minresponsetime', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::start_div('col-md-2 mb-2');
        $output .= html_writer::label(get_string('maxresponsetime', 'mod_classengage'), 'maxtime', false, ['class' => 'sr-only']);
        $output .= html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'maxtime',
            'id' => 'maxtime',
            'class' => 'form-control w-100',
            'placeholder' => get_string('maxresponsetime', 'mod_classengage'),
            'min' => '0',
            'step' => '0.1',
            'value' => $filter->get_max_response_time() !== null ? $filter->get_max_response_time() : '',
            'aria-label' => get_string('maxresponsetime', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::end_div(); // End row.

        $output .= html_writer::start_div('row w-100 mt-2');

        // Top performers checkbox.
        $output .= html_writer::start_div('col-md-3 mb-2');
        $output .= html_writer::start_div('form-check');
        $output .= html_writer::checkbox('toponly', '1', $filter->get_top_performers_only(), '', [
            'id' => 'toponly',
            'class' => 'form-check-input'
        ]);
        $output .= html_writer::label(get_string('topperformersonly', 'mod_classengage'), 'toponly', false, [
            'class' => 'form-check-label'
        ]);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        // Question filter.
        $output .= html_writer::start_div('col-md-3 mb-2');
        $output .= html_writer::label(get_string('filterbyquestion', 'mod_classengage'), 'questionid', false, ['class' => 'sr-only']);

        $output .= html_writer::select(
            $this->build_question_options($questions),
            'questionid',
            $filter->get_question_filter() ?? '',
            false,
            [
                'id' => 'questionid',
                'class' => 'custom-select w-100',
                'aria-label' => get_string('filterbyquestion', 'mod_classengage')
            ]
        );
        $output .= html_writer::end_div();

        // Per page selector.
        $output .= html_writer::start_div('col-md-2 mb-2');
        $output .= html_writer::label(get_string('perpage', 'mod_classengage'), 'perpage', false, ['class' => 'sr-only']);
        $perpageoptions = [10 => '10', 25 => '25', 50 => '50', 100 => '100'];
        $output .= html_writer::select(
            $perpageoptions,
            'perpage',
            $filter->get_per_page(),
            false,
            [
                'id' => 'perpage',
                'class' => 'custom-select w-100',
                'aria-label' => get_string('perpage', 'mod_classengage')
            ]
        );
        $output .= html_writer::end_div();

        // Buttons.
        $output .= html_writer::start_div('col-md-4 mb-2');
        $output .= html_writer::tag('button', get_string('applyfilters', 'mod_classengage'), [
            'type' => 'submit',
            'class' => 'btn btn-primary mr-2'
        ]);

        $clearurl = new moodle_url('/mod/classengage/analytics.php', [
            'id' => $cmid,
            'sessionid' => $sessionid
        ]);
        $output .= html_writer::link($clearurl, get_string('clearfilters', 'mod_classengage'), [
            'class' => 'btn btn-secondary'
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::end_div(); // End row.

        $output .= html_writer::end_tag('form');
        $output .= html_writer::end_div(); // End toolbar.

        return $output;
    }

    /**
     * Build question options array for dropdown
     *
     * @param array $questions Questions array with id and questionorder properties
     * @return array Options array for html_writer::select()
     */
    protected function build_question_options($questions)
    {
        $options = ['' => get_string('filterbyquestion', 'mod_classengage')];

        foreach ($questions as $q) {
            $options[$q->id] = get_string('question', 'mod_classengage') . ' ' . $q->questionorder;
        }

        return $options;
    }

    /**
     * Render summary cards with trend indicators
     *
     * @param object $summary Summary statistics object with properties:
     *                        - participationrate (float)
     *                        - averagescore (float)
     *                        - avgresponsetime (float)
     *                        - stddev (float, optional)
     *                        - higheststreak (int)
     * @param object $trends Trend data object with properties:
     *                       - accuracytrend (float|null)
     * @return string HTML output
     */
    public function render_summary_cards($summary, $trends)
    {
        $output = html_writer::start_div('row mb-4');

        // Participation rate card.
        $output .= $this->render_summary_card(
            get_string('participationrate', 'mod_classengage'),
            round($summary->participationrate, 1) . '%',
            'primary',
            null
        );

        // Accuracy trend card.
        $trendicon = '';
        $trendclass = '';
        if ($trends->accuracytrend !== null) {
            if ($trends->accuracytrend > 0) {
                $trendicon = '↑ +' . round($trends->accuracytrend, 1) . '%';
                $trendclass = 'text-success';
            } else if ($trends->accuracytrend < 0) {
                $trendicon = '↓ ' . round($trends->accuracytrend, 1) . '%';
                $trendclass = 'text-danger';
            } else {
                $trendicon = '→ 0%';
                $trendclass = 'text-muted';
            }
        }

        $output .= $this->render_summary_card(
            get_string('accuracytrend', 'mod_classengage'),
            round($summary->averagescore, 1) . '%',
            'success',
            $trendicon ? html_writer::tag('small', $trendicon, ['class' => $trendclass]) : null
        );

        // Response speed card.
        $speedtext = round($summary->avgresponsetime, 1) . 's';
        if (isset($summary->stddev)) {
            $speedtext .= html_writer::empty_tag('br') .
                html_writer::tag('small', get_string('stddev', 'mod_classengage') . ': ' .
                    round($summary->stddev, 1) . 's', ['class' => 'text-muted']);
        }

        $output .= $this->render_summary_card(
            get_string('responsespeed', 'mod_classengage'),
            $speedtext,
            'info',
            null
        );

        // Highest streak card.
        $output .= $this->render_summary_card(
            get_string('higheststreak', 'mod_classengage'),
            $summary->higheststreak ?? 0,
            'warning',
            null
        );

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render a single summary card
     *
     * @param string $title Card title
     * @param string $content Card content (HTML allowed)
     * @param string $bordercolor Bootstrap color class
     * @param string|null $footer Optional footer content (HTML)
     * @return string HTML output
     */
    protected function render_summary_card($title, $content, $bordercolor, $footer = null)
    {
        $parts = [];

        $parts[] = html_writer::start_div('col-md-3');
        $parts[] = html_writer::start_div(self::CLASS_CARD . ' border-' . $bordercolor);
        $parts[] = html_writer::start_div(self::CLASS_CARD_BODY . ' ' . self::CLASS_TEXT_CENTER);
        $parts[] = html_writer::tag('h6', $title, ['class' => 'card-title ' . self::CLASS_TEXT_MUTED]);
        $parts[] = html_writer::tag('h2', $content, ['class' => 'mb-0']);

        if ($footer) {
            $parts[] = html_writer::div($footer, 'mt-2');
        }

        $parts[] = html_writer::end_div();
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::end_div();

        return implode('', $parts);
    }

    /**
     * Render insights panel for at-risk and missing students
     *
     * @param object $insights Question insights object (currently unused, reserved for future)
     * @param array $atrisk At-risk students array with properties:
     *                      - fullname (string)
     *                      - percentage (float)
     *                      - avgresponsetime (float)
     *                      - isatrisk (bool)
     * @param array $missing Missing participants array with properties:
     *                       - fullname (string)
     * @return string HTML output
     */
    public function render_insights_panel($insights, $atrisk, $missing)
    {
        $output = html_writer::start_div('row mb-4');

        // At-risk students panel.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::start_div('card border-danger');
        $output .= html_writer::start_div('card-header bg-danger text-white');
        $output .= html_writer::tag('h5', get_string('atriskstudents', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');

        if (empty($atrisk)) {
            $output .= html_writer::tag('p', get_string('noatriskstudents', 'mod_classengage'), ['class' => 'text-muted']);
        } else {
            $output .= html_writer::start_tag('ul', ['class' => 'list-unstyled mb-0']);
            foreach ($atrisk as $student) {
                $reason = '';
                if ($student->percentage < 50) {
                    $reason = get_string('lowscore', 'mod_classengage') . ': ' . round($student->percentage, 1) . '%';
                }
                if ($student->isatrisk && $student->percentage >= 50) {
                    $reason = get_string('slowresponse', 'mod_classengage') . ': ' . round($student->avgresponsetime, 1) . 's';
                }

                $output .= html_writer::tag(
                    'li',
                    html_writer::tag('strong', $student->fullname) .
                    html_writer::empty_tag('br') .
                    html_writer::tag('small', $reason, ['class' => 'text-muted']),
                    ['class' => 'mb-2']
                );
            }
            $output .= html_writer::end_tag('ul');
        }

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        // Missing participants panel.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::start_div('card border-warning');
        $output .= html_writer::start_div('card-header bg-warning text-dark');
        $output .= html_writer::tag('h5', get_string('missingparticipants', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');

        if (empty($missing)) {
            $output .= html_writer::tag('p', get_string('nomissingparticipants', 'mod_classengage'), ['class' => 'text-muted']);
        } else {
            $output .= html_writer::start_tag('ul', ['class' => 'list-unstyled mb-0']);
            foreach ($missing as $student) {
                $output .= html_writer::tag('li', $student->fullname, ['class' => 'mb-1']);
            }
            $output .= html_writer::end_tag('ul');
        }

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render student performance table with highlighting
     *
     * @param array $students Student performance data with properties:
     *                        - rank (int)
     *                        - fullname (string)
     *                        - totalresponses (int)
     *                        - correctresponses (int)
     *                        - percentage (float)
     *                        - avgresponsetime (float)
     *                        - istopperformer (bool, optional)
     *                        - isatrisk (bool, optional)
     * @param analytics_filter $filter Current filter state
     * @param int $totalcount Total number of filtered records
     * @param int $cmid Course module ID
     * @param int $sessionid Session ID
     * @return string HTML output
     */
    public function render_student_performance_table($students, $filter, $totalcount, $cmid, $sessionid)
    {
        if (empty($students)) {
            return html_writer::div(
                get_string('nostudentdata', 'mod_classengage'),
                'alert alert-info'
            );
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-striped';
        $table->attributes['id'] = 'student-performance-table';

        // Build sortable headers.
        $sortcol = $filter->get_sort_column();
        $sortdir = $filter->get_sort_direction();

        $table->head = [
            $this->render_sortable_header('rank', get_string('rank', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
            $this->render_sortable_header('fullname', get_string('studentname', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
            $this->render_sortable_header('totalresponses', get_string('totalresponses', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
            $this->render_sortable_header('correctresponses', get_string('correctresponses', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
            $this->render_sortable_header('percentage', get_string('score', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
            $this->render_sortable_header('avgresponsetime', get_string('responsetime', 'mod_classengage'), $sortcol, $sortdir, $cmid, $sessionid, $filter),
        ];

        $table->data = [];

        foreach ($students as $student) {
            $rowclass = '';
            $rowattributes = [];

            // Apply highlighting classes.
            if (isset($student->istopperformer) && $student->istopperformer) {
                $rowclass = 'table-success';
                $rowattributes['title'] = get_string('topperformer', 'mod_classengage');
            } else if (isset($student->isatrisk) && $student->isatrisk) {
                $rowclass = 'table-danger';
                $rowattributes['title'] = get_string('atriskstudent', 'mod_classengage');
            }

            $row = new \html_table_row([
                $student->rank ?? '-',
                $student->fullname,
                $student->totalresponses,
                $student->correctresponses,
                round($student->percentage, 1) . '%',
                round($student->avgresponsetime, 1) . 's',
            ]);

            if ($rowclass) {
                $row->attributes['class'] = $rowclass;
            }
            if (!empty($rowattributes)) {
                $row->attributes = array_merge($row->attributes, $rowattributes);
            }

            $table->data[] = $row;
        }

        return html_writer::table($table);
    }

    /**
     * Render sortable column header
     *
     * @param string $column Column name
     * @param string $label Display label
     * @param string $currentsort Current sort column
     * @param string $currentdir Current sort direction (ASC or DESC)
     * @param int $cmid Course module ID
     * @param int $sessionid Session ID
     * @param analytics_filter $filter Current filter state
     * @return string HTML output
     */
    protected function render_sortable_header($column, $label, $currentsort, $currentdir, $cmid, $sessionid, $filter)
    {
        $params = $filter->to_url_params();
        $params['id'] = $cmid;
        $params['sessionid'] = $sessionid;
        $params['sort'] = $column;

        // Toggle direction if clicking current sort column.
        if ($column === $currentsort) {
            $params['dir'] = ($currentdir === 'ASC') ? 'DESC' : 'ASC';
            $indicator = ($currentdir === 'ASC') ? ' ↑' : ' ↓';
        } else {
            $params['dir'] = 'ASC';
            $indicator = '';
        }

        $url = new moodle_url('/mod/classengage/analytics.php', $params);

        return html_writer::link(
            $url,
            $label . $indicator,
            [
                'aria-label' => get_string('sortby', 'mod_classengage', $label),
                'class' => 'sortable-header'
            ]
        );
    }

    /**
     * Render pagination controls
     *
     * @param int $page Current page number (1-based)
     * @param int $perpage Records per page
     * @param int $totalcount Total number of records
     * @param moodle_url $baseurl Base URL for pagination links
     * @return string HTML output
     */
    public function render_pagination($page, $perpage, $totalcount, $baseurl)
    {
        if ($totalcount <= $perpage) {
            return '';
        }

        $totalpages = ceil($totalcount / $perpage);
        $start = (($page - 1) * $perpage) + 1;
        $end = min($page * $perpage, $totalcount);

        $output = html_writer::start_div('row mt-3');

        // Showing text.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::tag(
            'p',
            get_string('showing', 'mod_classengage', [
                'start' => $start,
                'end' => $end,
                'total' => $totalcount
            ]),
            ['class' => 'text-muted']
        );
        $output .= html_writer::end_div();

        // Pagination controls.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::start_tag('nav', ['aria-label' => get_string('pagination', 'mod_classengage')]);
        $output .= html_writer::start_tag('ul', ['class' => 'pagination justify-content-end mb-0']);

        // Previous button.
        $prevclass = ($page <= 1) ? 'page-item disabled' : 'page-item';
        $output .= html_writer::start_tag('li', ['class' => $prevclass]);
        if ($page > 1) {
            $prevurl = new moodle_url($baseurl, ['page' => $page - 1]);
            $output .= html_writer::link($prevurl, get_string('previous', 'mod_classengage'), [
                'class' => 'page-link',
                'aria-label' => get_string('previous', 'mod_classengage')
            ]);
        } else {
            $output .= html_writer::tag('span', get_string('previous', 'mod_classengage'), ['class' => 'page-link']);
        }
        $output .= html_writer::end_tag('li');

        // Page numbers (show max 5 pages).
        $startpage = max(1, $page - 2);
        $endpage = min($totalpages, $page + 2);

        for ($i = $startpage; $i <= $endpage; $i++) {
            $pageclass = ($i === $page) ? 'page-item active' : 'page-item';
            $output .= html_writer::start_tag('li', ['class' => $pageclass]);

            if ($i === $page) {
                $output .= html_writer::tag('span', $i, [
                    'class' => 'page-link',
                    'aria-current' => 'page'
                ]);
            } else {
                $pageurl = new moodle_url($baseurl, ['page' => $i]);
                $output .= html_writer::link($pageurl, $i, [
                    'class' => 'page-link',
                    'aria-label' => get_string('page', 'mod_classengage') . ' ' . $i
                ]);
            }

            $output .= html_writer::end_tag('li');
        }

        // Next button.
        $nextclass = ($page >= $totalpages) ? 'page-item disabled' : 'page-item';
        $output .= html_writer::start_tag('li', ['class' => $nextclass]);
        if ($page < $totalpages) {
            $nexturl = new moodle_url($baseurl, ['page' => $page + 1]);
            $output .= html_writer::link($nexturl, get_string('next', 'mod_classengage'), [
                'class' => 'page-link',
                'aria-label' => get_string('next', 'mod_classengage')
            ]);
        } else {
            $output .= html_writer::tag('span', get_string('next', 'mod_classengage'), ['class' => 'page-link']);
        }
        $output .= html_writer::end_tag('li');

        $output .= html_writer::end_tag('ul');
        $output .= html_writer::end_tag('nav');
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render chart container for Chart.js
     *
     * @param string $chartid Unique chart ID
     * @param string $title Chart title
     * @param int $height Chart height in pixels
     * @return string HTML output
     */
    public function render_chart_container($chartid, $title, $height = 400)
    {
        $output = html_writer::start_div('card mb-4');
        $output .= html_writer::start_div('card-header');
        $output .= html_writer::tag('h5', $title, ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');
        $output .= html_writer::start_div('', [
            'style' => 'height: ' . $height . 'px; position: relative;'
        ]);
        $output .= html_writer::tag('canvas', '', [
            'id' => $chartid,
            'role' => 'img',
            'aria-label' => get_string('chartalternative', 'mod_classengage', $title)
        ]);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render tab navigation for Simple and Advanced analysis
     *
     * @param string $activetab Currently active tab ('simple' or 'advanced')
     * @return string HTML output
     */
    public function render_tab_navigation($activetab = 'simple')
    {
        $output = html_writer::start_tag('ul', [
            'class' => 'nav nav-tabs mb-4',
            'role' => 'tablist',
            'aria-label' => get_string('tabpanel', 'mod_classengage')
        ]);

        // Simple Analysis tab.
        $simpleclass = ($activetab === 'simple') ? 'nav-link active' : 'nav-link';
        $simplearia = ($activetab === 'simple') ? ['aria-selected' => 'true'] : ['aria-selected' => 'false'];
        $output .= html_writer::start_tag('li', ['class' => 'nav-item', 'role' => 'presentation']);
        $output .= html_writer::link(
            '#simple-analysis',
            get_string('simpleanalysis', 'mod_classengage'),
            array_merge([
                'class' => $simpleclass,
                'id' => 'simple-tab',
                'data-toggle' => 'tab',
                'role' => 'tab',
                'aria-controls' => 'simple-analysis'
            ], $simplearia)
        );
        $output .= html_writer::end_tag('li');

        // Advanced Analysis tab.
        $advancedclass = ($activetab === 'advanced') ? 'nav-link active' : 'nav-link';
        $advancedaria = ($activetab === 'advanced') ? ['aria-selected' => 'true'] : ['aria-selected' => 'false'];
        $output .= html_writer::start_tag('li', ['class' => 'nav-item', 'role' => 'presentation']);
        $output .= html_writer::link(
            '#advanced-analysis',
            get_string('advancedanalysis', 'mod_classengage'),
            array_merge([
                'class' => $advancedclass,
                'id' => 'advanced-tab',
                'data-toggle' => 'tab',
                'role' => 'tab',
                'aria-controls' => 'advanced-analysis'
            ], $advancedaria)
        );
        $output .= html_writer::end_tag('li');

        $output .= html_writer::end_tag('ul');

        return $output;
    }

    /**
     * Render Simple Analysis tab content
     *
     * @param object $data Data object containing engagement, comprehension, activity counts, and responsiveness
     * @return string HTML output
     */
    public function render_simple_analysis($data)
    {
        $output = html_writer::start_div('tab-pane fade show active', [
            'id' => 'simple-analysis',
            'role' => 'tabpanel',
            'aria-labelledby' => 'simple-tab'
        ]);

        $output .= html_writer::start_div('row');

        // AI Insights Section
        $output .= $this->render_ai_section('simple');

        // 1. Engagement Card.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_engagement_card($data->engagement);
        $output .= html_writer::end_div();

        // 2. Comprehension Card.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_comprehension_card($data->comprehension);
        $output .= html_writer::end_div();

        // 3. Activity Counts.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_activity_counts($data->activity_counts);
        $output .= html_writer::end_div();

        // 4. Responsiveness.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_responsiveness($data->responsiveness);
        $output .= html_writer::end_div();

        $output .= html_writer::end_div(); // End row.

        $output .= html_writer::end_div(); // End tab-pane.

        return $output;
    }

    /**
     * Render engagement level card
     *
     * @param object $engagement Engagement data with properties:
     *                           - percentage (float)
     *                           - level (string: high, moderate, low)
     *                           - message (string)
     *                           - unique_participants (int)
     *                           - total_enrolled (int)
     * @return string HTML output
     */
    public function render_engagement_card($engagement)
    {
        $bordercolor = $this->get_engagement_color($engagement->level);

        $content = '';

        // Engagement percentage display.
        $content .= html_writer::tag('h2', round($engagement->percentage, 1) . '%', [
            'class' => self::CLASS_TEXT_CENTER . ' ' . self::CLASS_MARGIN_BOTTOM_3,
            'aria-label' => get_string('engagementlevel', 'mod_classengage') . ': ' .
                round($engagement->percentage, 1) . '%'
        ]);

        // Engagement message.
        $content .= html_writer::tag('p', s($engagement->message), [
            'class' => self::CLASS_TEXT_CENTER . ' ' . self::CLASS_MARGIN_BOTTOM_2
        ]);

        // Participation details.
        $participationtext = get_string('participationdetails', 'mod_classengage', [
            'participants' => $engagement->unique_participants,
            'total' => $engagement->total_enrolled
        ]);
        $content .= html_writer::tag('p', $participationtext, [
            'class' => self::CLASS_TEXT_CENTER . ' ' . self::CLASS_TEXT_MUTED . ' small'
        ]);

        return $this->render_card(
            get_string('engagementlevel', 'mod_classengage'),
            $content,
            $bordercolor
        );
    }

    /**
     * Render comprehension summary card
     *
     * @param object $comprehension Comprehension data with properties:
     *                              - avg_correctness (float)
     *                              - level (string: strong, partial, weak)
     *                              - message (string)
     *                              - confused_topics (array of strings)
     * @return string HTML output
     */
    public function render_comprehension_card($comprehension)
    {
        $bordercolor = $this->get_comprehension_color($comprehension->level);

        $content = '';

        // Comprehension message.
        $content .= html_writer::tag('p', s($comprehension->message), [
            'class' => self::CLASS_MARGIN_BOTTOM_3
        ]);

        // Confused topics if any.
        if (!empty($comprehension->confused_topics)) {
            $topicslist = implode(', ', array_map('s', $comprehension->confused_topics));
            $confusedtext = get_string('confusedtopics', 'mod_classengage', $topicslist);
            $content .= html_writer::tag('p', $confusedtext, [
                'class' => 'text-danger small mb-0'
            ]);
        }

        return $this->render_card(
            get_string('comprehensionsummary', 'mod_classengage'),
            $content,
            $bordercolor
        );
    }

    /**
     * Render activity counts card
     *
     * @param object $counts Activity counts with properties:
     *                       - questions_answered (int)
     *                       - poll_submissions (int)
     *                       - reactions (int)
     * @return string HTML output
     */
    public function render_activity_counts($counts)
    {
        // Cache language strings.
        $strings = [
            'questionsanswered' => get_string('questionsanswered', 'mod_classengage'),
            'pollsubmissions' => get_string('pollsubmissions', 'mod_classengage'),
            'reactions' => get_string('reactions', 'mod_classengage'),
        ];

        $content = '';
        $content .= $this->render_label_value_row($strings['questionsanswered'], $counts->questions_answered);
        $content .= $this->render_label_value_row($strings['pollsubmissions'], $counts->poll_submissions);

        // Last row without bottom margin.
        $parts = [];
        $parts[] = html_writer::start_div('row mb-0');
        $parts[] = html_writer::start_div('col-8');
        $parts[] = html_writer::tag('strong', $strings['reactions']);
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::start_div('col-4 text-right');
        $parts[] = html_writer::tag('span', $counts->reactions, [
            'aria-label' => $strings['reactions'] . ': ' . $counts->reactions
        ]);
        $parts[] = html_writer::end_div();
        $parts[] = html_writer::end_div();
        $content .= implode('', $parts);

        return $this->render_card(
            get_string('activitycounts', 'mod_classengage'),
            $content,
            self::COLOR_INFO
        );
    }

    /**
     * Render responsiveness indicator card
     *
     * @param object $responsiveness Responsiveness data with properties:
     *                               - avg_time (float)
     *                               - median_time (float)
     *                               - pace (string: quick, normal, slow)
     *                               - message (string)
     *                               - variance (float)
     * @return string HTML output
     */
    public function render_responsiveness($responsiveness)
    {
        $paceicon = self::PACE_ICONS[$responsiveness->pace] ?? self::PACE_ICONS[self::PACE_NORMAL];
        $pacecolor = self::PACE_COLORS[$responsiveness->pace] ?? self::CLASS_TEXT_MUTED;

        $content = '';

        // Pace indicator with icon.
        $content .= html_writer::tag(
            'h3',
            html_writer::tag('span', $paceicon, ['class' => $pacecolor]) . ' ' . s($responsiveness->message),
            ['class' => self::CLASS_TEXT_CENTER . ' ' . self::CLASS_MARGIN_BOTTOM_3]
        );

        // Average and median times.
        $timedetails = get_string('responsivenessdetails', 'mod_classengage', [
            'avg' => round($responsiveness->avg_time, 1),
            'median' => round($responsiveness->median_time, 1)
        ]);
        $content .= html_writer::tag('p', $timedetails, [
            'class' => self::CLASS_TEXT_CENTER . ' ' . self::CLASS_TEXT_MUTED . ' small'
        ]);

        return $this->render_card(
            get_string('responsiveness', 'mod_classengage'),
            $content,
            self::COLOR_PRIMARY
        );
    }

    /**
     * Render Advanced Analysis tab content
     *
     * @param object $data Data object containing concept difficulty, timeline, trends, recommendations, and distribution
     * @return string HTML output
     */
    public function render_advanced_analysis($data)
    {
        $output = html_writer::start_div('tab-pane fade', [
            'id' => 'advanced-analysis',
            'role' => 'tabpanel',
            'aria-labelledby' => 'advanced-tab'
        ]);

        // AI Insights Section (for Advanced tab too)
        $output .= html_writer::start_div('row');
        $output .= $this->render_ai_section('advanced');
        $output .= html_writer::end_div();

        // Concept difficulty section with chart.
        if (!empty($data->concept_difficulty)) {
            $output .= $this->render_concept_difficulty_chart_container();
            $output .= $this->render_concept_difficulty_table($data->concept_difficulty);
        }

        // Engagement timeline section.
        $output .= $this->render_engagement_timeline_container();

        // Response trends section.
        if (!empty($data->response_trends)) {
            $output .= $this->render_response_trends($data->response_trends);
        }

        // Teaching recommendations section.
        if (!empty($data->recommendations)) {
            $output .= $this->render_recommendations($data->recommendations);
        }

        // Participation distribution section.
        if (!empty($data->participation_distribution)) {
            $output .= $this->render_participation_distribution($data->participation_distribution);
        }

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render concept difficulty table with ordered topics
     *
     * @param array $concepts Array of concept objects with properties:
     *                        - question_order (int)
     *                        - question_text (string)
     *                        - correctness_rate (float)
     *                        - difficulty_level (string: easy, moderate, difficult)
     *                        - total_responses (int)
     * @return string HTML output
     */
    public function render_concept_difficulty_table($concepts)
    {
        if (empty($concepts)) {
            return html_writer::div(
                get_string('noconceptdata', 'mod_classengage'),
                'alert alert-info ' . self::CLASS_MARGIN_BOTTOM_4
            );
        }

        $output = html_writer::start_div(self::CLASS_CARD . ' ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER);
        $output .= html_writer::tag('h5', get_string('conceptdifficulty', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        // Create table.
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-striped';
        $table->attributes['id'] = 'concept-difficulty-table';

        // Table headers.
        $table->head = [
            get_string('question', 'mod_classengage'),
            get_string('correctnessrate', 'mod_classengage'),
            get_string('difficultylevel', 'mod_classengage'),
            get_string('totalresponses', 'mod_classengage'),
        ];

        $table->data = [];

        foreach ($concepts as $concept) {
            // Determine difficulty color.
            $difficultycolor = '';
            $difficultylabel = '';

            switch ($concept->difficulty_level) {
                case 'easy':
                    $difficultycolor = 'text-success';
                    $difficultylabel = get_string('easy', 'mod_classengage');
                    break;
                case 'moderate':
                    $difficultycolor = 'text-warning';
                    $difficultylabel = get_string('moderate', 'mod_classengage');
                    break;
                case 'difficult':
                    $difficultycolor = 'text-danger';
                    $difficultylabel = get_string('difficult', 'mod_classengage');
                    break;
                default:
                    $difficultycolor = self::CLASS_TEXT_MUTED;
                    $difficultylabel = $concept->difficulty_level;
            }

            $row = new \html_table_row([
                html_writer::tag('strong', 'Q' . $concept->question_order) . ': ' . s($concept->question_text),
                round($concept->correctness_rate, 1) . '%',
                html_writer::tag('span', $difficultylabel, ['class' => $difficultycolor]),
                $concept->total_responses,
            ]);

            $table->data[] = $row;
        }

        $output .= html_writer::table($table);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render concept difficulty chart container for Chart.js
     *
     * @return string HTML output
     */
    public function render_concept_difficulty_chart_container()
    {
        $output = html_writer::start_div(self::CLASS_CARD . ' ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER);
        $output .= html_writer::tag('h5', get_string('conceptdifficultychart', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        $output .= html_writer::start_div('', [
            'style' => 'height: 400px; position: relative;'
        ]);
        $output .= html_writer::tag('canvas', '', [
            'id' => 'concept-difficulty-chart',
            'role' => 'img',
            'aria-label' => get_string('conceptdifficulty', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render engagement timeline container for Chart.js
     *
     * @return string HTML output
     */
    public function render_engagement_timeline_container()
    {
        $output = html_writer::start_div(self::CLASS_CARD . ' ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER);
        $output .= html_writer::tag('h5', get_string('engagementtimeline', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        // Chart container.
        $output .= html_writer::start_div('', [
            'style' => 'height: 400px; position: relative;'
        ]);
        $output .= html_writer::tag('canvas', '', [
            'id' => 'engagement-timeline-chart',
            'role' => 'img',
            'aria-label' => get_string('engagementtimeline', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render response trends with common wrong answers
     *
     * @param array $trends Array of trend objects with properties:
     *                      - question_order (int)
     *                      - question_text (string)
     *                      - common_wrong_answer (string)
     *                      - percentage (float)
     *                      - misconception_description (string)
     * @return string HTML output
     */
    public function render_response_trends($trends)
    {
        if (empty($trends)) {
            return html_writer::div(
                get_string('notrendsdata', 'mod_classengage'),
                'alert alert-info ' . self::CLASS_MARGIN_BOTTOM_4
            );
        }

        $output = html_writer::start_div(self::CLASS_CARD . ' ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER);
        $output .= html_writer::tag('h5', get_string('responsetrends', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        // Render each trend as a card.
        foreach ($trends as $trend) {
            $output .= html_writer::start_div('border-left border-warning pl-3 mb-3');

            // Question header.
            $output .= html_writer::tag(
                'h6',
                html_writer::tag('strong', 'Q' . $trend->question_order) . ': ' . s($trend->question_text),
                ['class' => 'mb-2']
            );

            // Common wrong answer.
            $output .= html_writer::tag(
                'p',
                html_writer::tag('strong', get_string('commonwronganswer', 'mod_classengage') . ': ') .
                s($trend->common_wrong_answer) . ' (' . round($trend->percentage, 1) . '%)',
                ['class' => 'mb-1']
            );

            // Misconception description.
            if (!empty($trend->misconception_description)) {
                $output .= html_writer::tag(
                    'p',
                    html_writer::tag('em', s($trend->misconception_description)),
                    ['class' => self::CLASS_TEXT_MUTED . ' mb-0 small']
                );
            }

            $output .= html_writer::end_div();
        }

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render teaching recommendations with prioritized suggestions
     *
     * @param array $recommendations Array of recommendation objects with properties:
     *                               - priority (int: 1-6, 1 is highest)
     *                               - category (string)
     *                               - message (string)
     *                               - evidence (string)
     * @return string HTML output
     */
    public function render_recommendations($recommendations)
    {
        if (empty($recommendations)) {
            return html_writer::div(
                get_string('norecommendations', 'mod_classengage'),
                'alert alert-info ' . self::CLASS_MARGIN_BOTTOM_4
            );
        }

        $output = html_writer::start_div(self::CLASS_CARD . ' border-primary ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER . ' bg-primary ' . self::CLASS_TEXT_WHITE);
        $output .= html_writer::tag('h5', get_string('teachingrecommendations', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        // Render each recommendation as a numbered item.
        $output .= html_writer::start_tag('ol', ['class' => 'mb-0']);

        foreach ($recommendations as $recommendation) {
            $output .= html_writer::start_tag('li', ['class' => 'mb-3']);

            // Recommendation message.
            $output .= html_writer::tag(
                'p',
                html_writer::tag('strong', s($recommendation->message)),
                ['class' => 'mb-1']
            );

            // Evidence.
            if (!empty($recommendation->evidence)) {
                $output .= html_writer::tag(
                    'p',
                    s($recommendation->evidence),
                    ['class' => self::CLASS_TEXT_MUTED . ' mb-0 small']
                );
            }

            $output .= html_writer::end_tag('li');
        }

        $output .= html_writer::end_tag('ol');

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render participation distribution for doughnut chart
     *
     * @param object $distribution Distribution data with keys:
     *                            - high (int): Count of students with 5+ responses
     *                            - moderate (int): Count with 2-4 responses
     *                            - low (int): Count with 1 response
     *                            - none (int): Count with 0 responses
     * @return string HTML output
     */
    public function render_participation_distribution($distribution)
    {
        if (empty($distribution)) {
            return html_writer::div(
                get_string('noparticipationdata', 'mod_classengage'),
                'alert alert-info ' . self::CLASS_MARGIN_BOTTOM_4
            );
        }

        $output = html_writer::start_div(self::CLASS_CARD . ' ' . self::CLASS_MARGIN_BOTTOM_4);
        $output .= html_writer::start_div(self::CLASS_CARD_HEADER);
        $output .= html_writer::tag('h5', get_string('participationdistribution', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div(self::CLASS_CARD_BODY);

        $output .= html_writer::start_div('row align-items-center');

        // Left column: Chart.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::start_div('', [
            'style' => 'height: 300px; position: relative;'
        ]);
        $output .= html_writer::tag('canvas', '', [
            'id' => 'participation-distribution-chart',
            'role' => 'img',
            'aria-label' => get_string('participationdistribution', 'mod_classengage')
        ]);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        // Right column: Legend with counts.
        $output .= html_writer::start_div('col-md-6');
        $output .= html_writer::start_tag('ul', ['class' => 'list-unstyled']);

        // High participation.
        $output .= html_writer::tag(
            'li',
            html_writer::tag('span', '●', ['class' => 'text-success', 'style' => 'font-size: 1.5em;']) . ' ' .
            html_writer::tag('strong', get_string('highparticipation', 'mod_classengage')) . ': ' .
            ($distribution->high ?? 0),
            ['class' => 'mb-2']
        );

        // Moderate participation.
        $output .= html_writer::tag(
            'li',
            html_writer::tag('span', '●', ['class' => 'text-primary', 'style' => 'font-size: 1.5em;']) . ' ' .
            html_writer::tag('strong', get_string('moderateparticipation', 'mod_classengage')) . ': ' .
            ($distribution->moderate ?? 0),
            ['class' => 'mb-2']
        );

        // Low participation.
        $output .= html_writer::tag(
            'li',
            html_writer::tag('span', '●', ['class' => 'text-warning', 'style' => 'font-size: 1.5em;']) . ' ' .
            html_writer::tag('strong', get_string('lowparticipation', 'mod_classengage')) . ': ' .
            ($distribution->low ?? 0),
            ['class' => 'mb-2']
        );

        // No participation.
        $output .= html_writer::tag(
            'li',
            html_writer::tag('span', '●', ['class' => self::CLASS_TEXT_MUTED, 'style' => 'font-size: 1.5em;']) . ' ' .
            html_writer::tag('strong', get_string('noparticipation', 'mod_classengage')) . ': ' .
            ($distribution->none ?? 0),
            ['class' => 'mb-0']
        );

        $output .= html_writer::end_tag('ul');
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render AI Insights Section
     * 
     * @param string $context Context identifier (simple/advanced)
     * @return string HTML output
     */
    public function render_ai_section($context = 'simple')
    {
        $containerid = 'ai-insights-container-' . $context;
        $btnid = 'generate-ai-insights-' . $context;

        $output = html_writer::start_div('col-12 mb-4');

        // Container for results
        $output .= html_writer::div('', '', ['id' => $containerid]);

        // Generate Button
        $output .= html_writer::tag(
            'button',
            html_writer::tag('i', '', ['class' => 'fa fa-magic mr-2']) . get_string('generateaiinsights', 'mod_classengage'),
            [
                'id' => $btnid,
                'class' => 'btn btn-outline-primary btn-lg btn-block',
                'data-context' => $context,
                'data-container' => $containerid
            ]
        );

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render the Javascript for the AI button
     *
     * @param int $sessionid The session ID to analyze
     * @return string HTML script tag
     */
    public function render_ai_script($sessionid)
    {
        // We use a simple script tag here for immediate functionality. 
        // In a strictly standards-compliant Moodle plugin, likely use AMD modules.

        $js = "
        document.addEventListener('DOMContentLoaded', function() {
            var buttons = document.querySelectorAll('[id^=\"generate-ai-insights-\"]');
            
            buttons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var containerId = this.getAttribute('data-container');
                    var container = document.getElementById(containerId);
                    
                    // Show loading state
                    var originalText = this.innerHTML;
                    this.disabled = true;
                    this.innerHTML = '<i class=\"fa fa-spinner fa-spin mr-2\"></i> " . get_string('generating', 'mod_classengage') . "...';
                    
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', 'ajax_ai_analytics.php?sessionid=" . $sessionid . "', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    container.innerHTML = response.html;
                                    btn.style.display = 'none'; // Hide button on success
                                } else {
                                    alert('Error: ' + (response.error || 'Unknown error'));
                                    btn.innerHTML = originalText;
                                    btn.disabled = false;
                                }
                            } catch (e) {
                                console.error('Invalid JSON', xhr.responseText);
                                alert('Error parsing server response');
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                            }
                        } else {
                            alert('Request failed.  Returned status of ' + xhr.status);
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }
                    };
                    
                    xhr.send();
                });
            });
        });
        ";

        return html_writer::tag('script', $js);
    }
}
