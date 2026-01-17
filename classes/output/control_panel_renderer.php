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
 * Control panel renderer
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\output;

use mod_classengage\constants;
use html_writer;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer for control panel UI components
 */
class control_panel_renderer
{

    /**
     * Render session status cards
     *
     * @param \stdClass $session Session object
     * @param int $participantcount Number of unique participants
     * @return string HTML output
     */
    public function render_status_cards($session, $participantcount)
    {
        $output = html_writer::start_div('row mb-4');

        // Question progress card.
        $output .= $this->render_status_card(
            get_string('currentquestiontext', 'mod_classengage'), // "Current Question"
            html_writer::tag('span', ($session->currentquestion + 1) . ' / ' . $session->numquestions, ['id' => 'question-progress']),
            'primary'
        );

        // Status card.
        $statusbadge = html_writer::tag('span', ucfirst($session->status), [
            'id' => 'session-status',
            'class' => 'badge badge-' . $this->get_status_badge_class($session->status)
        ]);
        $output .= $this->render_status_card(
            get_string('status', 'mod_classengage'),
            $statusbadge,
            'info'
        );

        // Participant count card (shows current responses / total participants).
        $participantcontent = html_writer::tag('span', '0', ['id' => 'response-count-current']) .
            '/' .
            html_writer::tag('span', $participantcount, ['id' => 'participant-count']);
        $output .= $this->render_status_card(
            get_string('participants', 'mod_classengage'),
            $participantcontent,
            'success'
        );

        // Time card.
        $timecontent = html_writer::tag('span', '--:--', ['id' => 'time-display']);
        $output .= $this->render_status_card(
            get_string('time', 'mod_classengage'),
            $timecontent,
            'danger'
        );

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render a single status card
     *
     * @param string $title Card title
     * @param string $content Card content (HTML allowed)
     * @param string $bordercolor Bootstrap color class
     * @return string HTML output
     */
    private function render_status_card($title, $content, $bordercolor)
    {
        $output = html_writer::start_div('col-md mb-3');
        $output .= html_writer::start_div('card border-' . $bordercolor . ' h-100');
        $output .= html_writer::start_div('card-body text-center d-flex flex-column justify-content-center px-2');
        $output .= html_writer::tag('div', $title, ['class' => 'status-card-title mb-2 text-truncate']);
        $output .= html_writer::tag('div', $content, ['class' => 'status-card-value']);
        $output .= html_writer::end_div(); // card-body.
        $output .= html_writer::end_div(); // card.
        $output .= html_writer::end_div(); // col-md.

        return $output;
    }

    /**
     * Get Bootstrap badge class for session status
     *
     * @param string $status Session status
     * @return string Bootstrap class name
     */
    private function get_status_badge_class($status)
    {
        switch ($status) {
            case constants::SESSION_STATUS_ACTIVE:
                return 'success';
            case constants::SESSION_STATUS_PAUSED:
                return 'warning';
            case constants::SESSION_STATUS_COMPLETED:
                return 'secondary';
            default:
                return 'info';
        }
    }

    /**
     * Render current question display with answer options
     *
     * @param \stdClass $question Question object
     * @return string HTML output
     */
    public function render_question_display($question)
    {
        $output = html_writer::start_div('card mb-4 shadow-sm');

        // Card header
        $output .= html_writer::start_div('card-header bg-primary text-white d-flex justify-content-between align-items-center');
        $output .= html_writer::tag('h5', get_string('currentquestiontext', 'mod_classengage'), ['class' => 'mb-0']);
        // Show difficulty and bloom level badges if available
        $badges = '';
        if (!empty($question->difficulty)) {
            $badges .= html_writer::tag('span', ucfirst($question->difficulty), ['class' => 'badge badge-light mr-1']);
        }
        if (!empty($question->bloomlevel)) {
            $badges .= html_writer::tag('span', ucfirst($question->bloomlevel), ['class' => 'badge badge-info']);
        }
        if (!empty($badges)) {
            $output .= html_writer::tag('div', $badges);
        }
        $output .= html_writer::end_div();

        // Card body with question and answers
        $output .= html_writer::start_div('card-body');

        // Display referenced image if present
        if (!empty($question->question_image)) {
            // Construct full URL from stored path
            $imagesrc = $question->question_image;
            if (strpos($imagesrc, '/') === 0) {
                $nlppublicurl = rtrim(get_config('mod_classengage', 'nlppublicurl'), '/');
                $imagesrc = $nlppublicurl . $imagesrc;
            }
            $output .= html_writer::start_div('question-image text-center mb-3');
            $output .= html_writer::empty_tag('img', [
                'src' => $imagesrc,
                'alt' => get_string('referenceimage', 'mod_classengage'),
                'class' => 'img-fluid rounded shadow-sm',
                'style' => 'max-height: 250px; cursor: zoom-in;',
                'loading' => 'lazy',
                'onclick' => 'window.open(this.src, "_blank")'
            ]);
            $output .= html_writer::end_div();
        }

        // Question text
        $output .= html_writer::tag('p', format_text($question->questiontext), ['class' => 'lead mb-4']);

        // Answer options
        $output .= html_writer::start_div('question-options');

        $options = [
            'A' => $question->optiona ?? '',
            'B' => $question->optionb ?? '',
            'C' => $question->optionc ?? '',
            'D' => $question->optiond ?? '',
        ];

        $correctanswer = strtoupper($question->correctanswer ?? '');

        foreach ($options as $letter => $optiontext) {
            if (empty($optiontext)) {
                continue;
            }

            $iscorrect = ($letter === $correctanswer);
            $optionclass = 'question-option d-flex align-items-start p-3 mb-2 rounded';
            $optionclass .= $iscorrect ? ' bg-success-light border-success' : ' bg-light';

            $output .= html_writer::start_div($optionclass, ['id' => 'option-' . $letter]);

            // Letter badge
            $badgeclass = $iscorrect ? 'badge badge-success mr-3' : 'badge badge-secondary mr-3';
            $letterContent = $iscorrect ? '✓ ' . $letter : $letter;
            $output .= html_writer::tag('span', $letterContent, [
                'class' => $badgeclass,
                'style' => 'font-size: 1rem; min-width: 35px; text-align: center;'
            ]);

            // Option text
            $output .= html_writer::tag('span', format_text($optiontext), ['class' => 'option-text']);

            $output .= html_writer::end_div();
        }

        $output .= html_writer::end_div(); // question-options

        $output .= html_writer::end_div(); // card-body
        $output .= html_writer::end_div(); // card

        return $output;
    }

    /**
     * Render response distribution table and chart
     *
     * @param \stdClass $question Question object
     * @return string HTML output
     */
    public function render_response_distribution($question)
    {
        $output = html_writer::start_div('row mb-4');

        // Left column: Response distribution table.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_distribution_table($question);
        $output .= html_writer::end_div();

        // Right column: Chart visualization.
        $output .= html_writer::start_div('col-md-6');
        $output .= $this->render_distribution_chart();
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render distribution table
     *
     * @param \stdClass $question Question object
     * @return string HTML output
     */
    private function render_distribution_table($question)
    {
        $output = html_writer::start_div('card h-100 border-0 shadow-sm');
        $output .= html_writer::start_div('card-header bg-white border-bottom-0 pt-4 pb-2');
        $output .= html_writer::tag('h5', get_string('liveresponses', 'mod_classengage'), ['class' => 'mb-0 text-dark font-weight-bold']);
        $output .= html_writer::tag(
            'small',
            html_writer::tag('span', '0 / 0', ['id' => 'response-count', 'class' => 'badge badge-pill badge-light border']),
            ['class' => 'text-muted ml-2']
        );
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');

        // Response distribution table with progress bars.
        $output .= html_writer::start_tag('table', ['class' => 'table table-hover mb-0']);
        $output .= html_writer::start_tag('thead');
        $output .= html_writer::start_tag('tr');
        $output .= html_writer::tag('th', 'Answer', ['style' => 'width: 15%', 'class' => 'border-top-0']);
        $output .= html_writer::tag('th', 'Distribution', ['style' => 'width: 55%', 'class' => 'border-top-0']);
        $output .= html_writer::tag('th', 'Count', ['class' => 'text-center border-top-0', 'style' => 'width: 15%']);
        $output .= html_writer::tag('th', '%', ['class' => 'text-center border-top-0', 'style' => 'width: 15%']);
        $output .= html_writer::end_tag('tr');
        $output .= html_writer::end_tag('thead');
        $output .= html_writer::start_tag('tbody');

        foreach (constants::VALID_ANSWERS as $option) {
            $iscorrect = (strtoupper($question->correctanswer) === $option);

            $output .= html_writer::start_tag('tr', ['id' => 'row-' . $option]);
            $output .= html_writer::start_tag('td', ['class' => 'align-middle']);
            $output .= html_writer::tag('strong', ($iscorrect ? '✓ ' : '') . $option, ['class' => 'text-dark']);
            $output .= html_writer::end_tag('td');
            $output .= html_writer::start_tag('td', ['class' => 'align-middle']);
            $output .= html_writer::start_div('progress', ['style' => 'height: 1.25rem;']);
            $output .= html_writer::div('', 'progress-bar bg-info', [
                'id' => 'bar-' . $option,
                'role' => 'progressbar',
                'style' => 'width: 0%',
                'aria-valuenow' => '0',
                'aria-valuemin' => '0',
                'aria-valuemax' => '100'
            ]);
            $output .= html_writer::end_div();
            $output .= html_writer::end_tag('td');
            $output .= html_writer::tag(
                'td',
                html_writer::tag('span', '0', ['id' => 'count-' . $option, 'class' => 'font-weight-bold']),
                ['class' => 'text-center align-middle']
            );
            $output .= html_writer::tag(
                'td',
                html_writer::tag('span', '0%', ['id' => 'percent-' . $option, 'class' => 'text-muted']),
                ['class' => 'text-center align-middle']
            );
            $output .= html_writer::end_tag('tr');
        }

        $output .= html_writer::end_tag('tbody');
        $output .= html_writer::end_tag('table');

        $output .= html_writer::end_div(); // card-body.
        $output .= html_writer::end_div(); // card.

        return $output;
    }

    /**
     * Render distribution chart
     *
     * @return string HTML output
     */
    private function render_distribution_chart()
    {
        $output = html_writer::start_div('card');
        $output .= html_writer::start_div('card-header');
        $output .= html_writer::tag('h5', 'Response Distribution Chart', ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');
        $output .= html_writer::start_div('', ['style' => 'height: 300px; position: relative;']);
        $output .= html_writer::tag('canvas', '', ['id' => 'responseChart']);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div(); // card-body.
        $output .= html_writer::end_div(); // card.

        return $output;
    }

    /**
     * Render response rate progress bar
     *
     * @return string HTML output
     */
    public function render_response_rate_progress()
    {
        $output = html_writer::start_div('card mb-4 shadow-sm');
        $output .= html_writer::start_div('card-body');
        $output .= html_writer::tag('h6', 'Overall Response Rate', ['class' => 'card-title text-muted mb-3']);
        $output .= html_writer::start_div('progress', ['style' => 'height: 1.5rem;']);
        $output .= html_writer::div('0%', 'progress-bar progress-bar-striped progress-bar-animated bg-success', [
            'id' => 'response-progress',
            'role' => 'progressbar',
            'style' => 'width: 0%',
            'aria-valuenow' => '0',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100'
        ]);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render control buttons including pause/resume
     *
     * Requirements: 1.4, 1.5
     *
     * @param \stdClass $session Session object
     * @param int $cmid Course module ID
     * @param int $sessionid Session ID
     * @return string HTML output
     */
    public function render_control_buttons($session, $cmid, $sessionid)
    {
        $output = html_writer::start_div('card mt-4 shadow-sm');
        $output .= html_writer::start_div('card-body py-4');
        $output .= html_writer::start_div('d-flex justify-content-center gap-3 flex-wrap');

        $baseparams = ['id' => $cmid, 'sessionid' => $sessionid, 'sesskey' => sesskey()];

        // Pause/Resume buttons (Requirement 1.4, 1.5).
        $ispaused = ($session->status === constants::SESSION_STATUS_PAUSED);

        // Pause button - shown when session is active.
        $pausestyle = $ispaused ? 'display: none;' : '';
        $output .= html_writer::tag(
            'button',
            html_writer::tag('i', '', ['class' => 'fa fa-pause mr-2']) .
            get_string('pause', 'mod_classengage'),
            [
                'id' => 'btn-pause-session',
                'class' => 'btn btn-warning btn-lg px-5',
                'style' => $pausestyle,
                'type' => 'button',
            ]
        );

        // Resume button - shown when session is paused.
        $resumestyle = $ispaused ? '' : 'display: none;';
        $output .= html_writer::tag(
            'button',
            html_writer::tag('i', '', ['class' => 'fa fa-play mr-2']) .
            get_string('resume', 'mod_classengage'),
            [
                'id' => 'btn-resume-session',
                'class' => 'btn btn-success btn-lg px-5',
                'style' => $resumestyle,
                'type' => 'button',
            ]
        );

        if ($session->currentquestion < $session->numquestions) {
            $nexturl = new moodle_url(
                '/mod/classengage/controlpanel.php',
                array_merge($baseparams, ['action' => constants::ACTION_NEXT])
            );
            $output .= html_writer::link(
                $nexturl,
                html_writer::tag('i', '', ['class' => 'fa fa-forward mr-2']) .
                get_string('nextquestion', 'mod_classengage'),
                ['class' => 'btn btn-primary btn-lg px-5']
            );
        }

        $stopurl = new moodle_url(
            '/mod/classengage/controlpanel.php',
            array_merge($baseparams, ['action' => constants::ACTION_STOP])
        );
        $output .= html_writer::link(
            $stopurl,
            html_writer::tag('i', '', ['class' => 'fa fa-stop mr-2']) .
            get_string('stopsession', 'mod_classengage'),
            ['class' => 'btn btn-danger btn-lg px-5']
        );

        $output .= html_writer::end_div(); // d-flex
        $output .= html_writer::end_div(); // card-body
        $output .= html_writer::end_div(); // card

        return $output;
    }

    /**
     * Render connected students panel
     *
     * Requirement: 5.1 - Display list of connected students with status
     *
     * @return string HTML output
     */
    public function render_connected_students_panel()
    {
        $output = html_writer::start_div('card mb-4 shadow-sm');

        // Card header with connection status indicator.
        $output .= html_writer::start_div('card-header bg-white d-flex justify-content-between align-items-center');
        $output .= html_writer::tag(
            'h5',
            html_writer::tag('i', '', ['class' => 'fa fa-users mr-2']) .
            get_string('connectedstudents', 'mod_classengage'),
            ['class' => 'mb-0']
        );
        $output .= html_writer::tag(
            'span',
            html_writer::tag('i', '', [
                'id' => 'connection-status-indicator',
                'class' => 'fa fa-circle text-success',
                'title' => 'Connected',
            ]),
            ['class' => 'connection-indicator']
        );
        $output .= html_writer::end_div();

        // Card body with student list container.
        $output .= html_writer::start_div('card-body p-0');
        $output .= html_writer::div(
            html_writer::div(
                get_string('loadingstudents', 'mod_classengage'),
                'text-muted p-3 text-center'
            ),
            '',
            ['id' => 'connected-students-list', 'style' => 'max-height: 300px; overflow-y: auto;']
        );
        $output .= html_writer::end_div();

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render student panel
     *
     * Shows a simple list of students with their answered status.
     * Includes a search input for filtering students by name (client-side).
     * Replaces the separate statistics and connected students panels.
     *
     * @return string HTML output
     */
    public function render_student_panel()
    {
        $output = html_writer::start_div('card shadow-sm');

        // Card header.
        $output .= html_writer::start_div('card-header bg-white py-2');
        $output .= html_writer::tag(
            'h6',
            html_writer::tag('i', '', ['class' => 'fa fa-users mr-2']) .
            get_string('students', 'mod_classengage'),
            ['class' => 'mb-0']
        );
        $output .= html_writer::end_div();

        // Search input - compact padding.
        $output .= html_writer::start_div('px-3 py-2 border-bottom');
        $output .= html_writer::tag('input', '', [
            'type' => 'text',
            'id' => 'student-search',
            'class' => 'form-control form-control-sm',
            'placeholder' => get_string('searchstudents', 'mod_classengage'),
            'autocomplete' => 'off',
        ]);
        $output .= html_writer::end_div();

        // Student list container - no padding, fixed height to match other cards.
        $output .= html_writer::div(
            html_writer::div(
                get_string('loadingstudents', 'mod_classengage'),
                'text-muted p-3 text-center'
            ),
            '',
            ['id' => 'student-list', 'style' => 'height: 778px; overflow-y: auto;']
        );

        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render session statistics panel (deprecated - use render_student_panel)
     *
     * @return string HTML output
     * @deprecated Use render_student_panel instead
     */
    public function render_session_statistics_panel()
    {
        // Return empty - functionality moved to render_student_panel.
        return '';
    }
}
