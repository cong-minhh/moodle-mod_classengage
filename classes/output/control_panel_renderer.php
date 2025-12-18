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
class control_panel_renderer {
    
    /**
     * Render session status cards
     *
     * @param \stdClass $session Session object
     * @param int $participantcount Number of unique participants
     * @return string HTML output
     */
    public function render_status_cards($session, $participantcount) {
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
        
        // Participant count card.
        $participantcontent = html_writer::tag('span', $participantcount, ['id' => 'participant-count']);
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

        // Response rate card.
        $responserate = html_writer::tag('span', '0%', ['id' => 'response-rate']);
        $output .= $this->render_status_card(
            get_string('participationrate', 'mod_classengage'),
            $responserate,
            'warning'
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
    private function render_status_card($title, $content, $bordercolor) {
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
    private function get_status_badge_class($status) {
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
     * Render current question display
     *
     * @param \stdClass $question Question object
     * @return string HTML output
     */
    public function render_question_display($question) {
        $output = html_writer::start_div('card mb-4');
        $output .= html_writer::start_div('card-header bg-primary text-white');
        $output .= html_writer::tag('h5', get_string('currentquestiontext', 'mod_classengage'), ['class' => 'mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::start_div('card-body');
        $output .= html_writer::tag('p', format_text($question->questiontext), ['class' => 'lead mb-0']);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        
        return $output;
    }
    
    /**
     * Render response distribution table and chart
     *
     * @param \stdClass $question Question object
     * @return string HTML output
     */
    public function render_response_distribution($question) {
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
    private function render_distribution_table($question) {
        $output = html_writer::start_div('card h-100 border-0 shadow-sm');
        $output .= html_writer::start_div('card-header bg-white border-bottom-0 pt-4 pb-2');
        $output .= html_writer::tag('h5', get_string('liveresponses', 'mod_classengage'), ['class' => 'mb-0 text-dark font-weight-bold']);
        $output .= html_writer::tag('small', 
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
            $output .= html_writer::tag('td', html_writer::tag('span', '0', ['id' => 'count-' . $option, 'class' => 'font-weight-bold']), 
                ['class' => 'text-center align-middle']);
            $output .= html_writer::tag('td', html_writer::tag('span', '0%', ['id' => 'percent-' . $option, 'class' => 'text-muted']), 
                ['class' => 'text-center align-middle']);
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
    private function render_distribution_chart() {
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
    public function render_response_rate_progress() {
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
    public function render_control_buttons($session, $cmid, $sessionid) {
        $output = html_writer::start_div('card');
        $output .= html_writer::start_div('card-body text-center');

        $baseparams = ['id' => $cmid, 'sessionid' => $sessionid, 'sesskey' => sesskey()];

        // Pause/Resume buttons (Requirement 1.4, 1.5).
        $ispaused = ($session->status === constants::SESSION_STATUS_PAUSED);

        // Pause button - shown when session is active.
        $pausestyle = $ispaused ? 'display: none;' : '';
        $output .= html_writer::tag('button',
            html_writer::tag('i', '', ['class' => 'fa fa-pause mr-2']) .
            get_string('pause', 'mod_classengage'),
            [
                'id' => 'btn-pause-session',
                'class' => 'btn btn-warning btn-lg mr-2',
                'style' => $pausestyle,
                'type' => 'button',
            ]
        );

        // Resume button - shown when session is paused.
        $resumestyle = $ispaused ? '' : 'display: none;';
        $output .= html_writer::tag('button',
            html_writer::tag('i', '', ['class' => 'fa fa-play mr-2']) .
            get_string('resume', 'mod_classengage'),
            [
                'id' => 'btn-resume-session',
                'class' => 'btn btn-success btn-lg mr-2',
                'style' => $resumestyle,
                'type' => 'button',
            ]
        );

        if ($session->currentquestion < $session->numquestions) {
            $nexturl = new moodle_url('/mod/classengage/controlpanel.php',
                array_merge($baseparams, ['action' => constants::ACTION_NEXT]));
            $output .= html_writer::link($nexturl, get_string('nextquestion', 'mod_classengage'),
                ['class' => 'btn btn-primary btn-lg mr-2']);
        }

        $stopurl = new moodle_url('/mod/classengage/controlpanel.php',
            array_merge($baseparams, ['action' => constants::ACTION_STOP]));
        $output .= html_writer::link($stopurl, get_string('stopsession', 'mod_classengage'),
            ['class' => 'btn btn-danger btn-lg']);

        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        return $output;
    }

    /**
     * Render connected students panel
     *
     * Requirement: 5.1 - Display list of connected students with status
     *
     * @return string HTML output
     */
    public function render_connected_students_panel() {
        $output = html_writer::start_div('card mb-4 shadow-sm');

        // Card header with connection status indicator.
        $output .= html_writer::start_div('card-header bg-white d-flex justify-content-between align-items-center');
        $output .= html_writer::tag('h5',
            html_writer::tag('i', '', ['class' => 'fa fa-users mr-2']) .
            get_string('connectedstudents', 'mod_classengage'),
            ['class' => 'mb-0']
        );
        $output .= html_writer::tag('span',
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
     * Render session statistics panel
     *
     * Requirement: 5.5 - Display aggregate statistics (connected, answered, pending)
     *
     * @return string HTML output
     */
    public function render_session_statistics_panel() {
        $output = html_writer::start_div('card mb-4 shadow-sm');

        // Card header.
        $output .= html_writer::start_div('card-header bg-white');
        $output .= html_writer::tag('h5',
            html_writer::tag('i', '', ['class' => 'fa fa-chart-bar mr-2']) .
            get_string('sessionstatistics', 'mod_classengage'),
            ['class' => 'mb-0']
        );
        $output .= html_writer::end_div();

        // Card body with statistics.
        $output .= html_writer::start_div('card-body');

        // Statistics row.
        $output .= html_writer::start_div('row text-center');

        // Connected count.
        $output .= html_writer::start_div('col-4');
        $output .= html_writer::tag('div',
            html_writer::tag('span', '0', ['id' => 'stat-connected', 'class' => 'h3 text-primary']),
            ['class' => 'stat-value']
        );
        $output .= html_writer::tag('div',
            get_string('connected', 'mod_classengage'),
            ['class' => 'stat-label text-muted small']
        );
        $output .= html_writer::end_div();

        // Answered count.
        $output .= html_writer::start_div('col-4');
        $output .= html_writer::tag('div',
            html_writer::tag('span', '0', ['id' => 'stat-answered', 'class' => 'h3 text-success']),
            ['class' => 'stat-value']
        );
        $output .= html_writer::tag('div',
            get_string('answered', 'mod_classengage'),
            ['class' => 'stat-label text-muted small']
        );
        $output .= html_writer::end_div();

        // Pending count.
        $output .= html_writer::start_div('col-4');
        $output .= html_writer::tag('div',
            html_writer::tag('span', '0', ['id' => 'stat-pending', 'class' => 'h3 text-warning']),
            ['class' => 'stat-value']
        );
        $output .= html_writer::tag('div',
            get_string('pending', 'mod_classengage'),
            ['class' => 'stat-label text-muted small']
        );
        $output .= html_writer::end_div();

        $output .= html_writer::end_div(); // row.

        // Progress bar for answered/connected ratio.
        $output .= html_writer::start_div('mt-3');
        $output .= html_writer::start_div('progress', ['style' => 'height: 0.5rem;']);
        $output .= html_writer::div('', 'progress-bar bg-success', [
            'id' => 'answered-progress',
            'role' => 'progressbar',
            'style' => 'width: 0%',
            'aria-valuenow' => '0',
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
        ]);
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        $output .= html_writer::end_div(); // card-body.
        $output .= html_writer::end_div(); // card.

        return $output;
    }
}
