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
 * Student Results Renderer for ClassEngage
 *
 * Handles the rendering of student-specific quiz results including:
 * - Question-by-question breakdown
 * - Correct answer reveal
 * - Performance insights
 * - Class comparison (anonymized)
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

class student_results_renderer {

    /** @var int User ID */
    private $userid = 0;

    /** @var int ClassEngage activity ID */
    private $classengageid = 0;

    /** @var int Session ID */
    private $sessionid = 0;

    /** @var object User object */
    private $user = null;

    /** @var object ClassEngage object */
    private $classengage = null;

    /** @var object Session object */
    private $session = null;

    /** @var array Student responses */
    private $responses = [];

    /** @var array Question data with responses */
    private $questions_with_responses = [];

    /** @var array Statistics */
    private $stats = [];

    /**
     * Constructor
     *
     * @param int $classengageid
     * @param int $sessionid
     * @param int $userid
     */
    public function __construct($classengageid, $sessionid, $userid) {
        global $DB;

        $this->classengageid = $classengageid;
        $this->sessionid = $sessionid;
        $this->userid = $userid;

        $this->classengage = $DB->get_record('classengage', ['id' => $classengageid]);
        $this->session = $DB->get_record('classengage_sessions', ['id' => $sessionid]);
        $this->user = $DB->get_record('user', ['id' => $userid]);

        $this->load_responses();
        $this->load_questions_with_responses();
        $this->calculate_statistics();
    }

    /**
     * Load student responses for this session
     */
    private function load_responses() {
        global $DB;

        $sql = "SELECT r.*, q.questiontext, q.optiona, q.optionb, q.optionc, q.optiond,
                       q.correctanswer, q.rationale, q.trustworthiness_score, q.trustworthiness_level,
                       q.trustworthiness_factors, q.difficulty, q.bloomlevel, q.question_image,
                       sq.questionorder
                FROM {classengage_responses} r
                JOIN {classengage_questions} q ON q.id = r.questionid
                JOIN {classengage_session_questions} sq ON sq.questionid = q.id AND sq.sessionid = r.sessionid
                WHERE r.sessionid = :sessionid AND r.userid = :userid
                ORDER BY sq.questionorder";

        $this->responses = $DB->get_records_sql($sql, [
            'sessionid' => $this->sessionid,
            'userid' => $this->userid
        ]);
    }

    /**
     * Load questions with responses data
     */
    private function load_questions_with_responses() {
        global $DB;

        foreach ($this->responses as $response) {
            $question_data = [
                'response' => $response,
                'is_correct' => !empty($response->iscorrect),
                'user_answer' => strtoupper($response->answer),
                'correct_answer' => strtoupper($response->correctanswer),
                'options' => [
                    'A' => $response->optiona,
                    'B' => $response->optionb,
                    'C' => $response->optionc,
                    'D' => $response->optiond
                ],
                'question_order' => $response->questionorder,
                'questiontext' => $response->questiontext,
                'rationale' => $response->rationale,
                'trustworthiness' => [
                    'score' => $response->trustworthiness_score ?? 0,
                    'level' => $response->trustworthiness_level ?? 'uncertain',
                    'factors' => json_decode($response->trustworthiness_factors ?? '{}', true)
                ],
                'difficulty' => $response->difficulty ?? 'medium',
                'bloomlevel' => $response->bloomlevel ?? '',
                'question_image' => $response->question_image ?? '',
                'responsetime' => $response->responsetime ?? 0,
                'score' => $response->score ?? 0
            ];

            $this->questions_with_responses[] = $question_data;
        }
    }

    /**
     * Calculate statistics for this student's results
     */
    private function calculate_statistics() {
        $total_questions = count($this->responses);
        $correct_answers = 0;
        $total_score = 0;
        $total_response_time = 0;
        $trustworthy_correct = 0;
        $trustworthy_total = 0;
        $uncertain_correct = 0;
        $uncertain_total = 0;
        $unlikely_correct = 0;
        $unlikely_total = 0;

        foreach ($this->responses as $response) {
            if (!empty($response->iscorrect)) {
                $correct_answers++;
            }
            $total_score += $response->score ?? 0;
            $total_response_time += $response->responsetime ?? 0;

            $trust_level = $response->trustworthiness_level ?? 'uncertain';
            if (!empty($response->trustworthiness_analyzed)) {
                switch ($trust_level) {
                    case 'trustworthy':
                        $trustworthy_total++;
                        if (!empty($response->iscorrect)) $trustworthy_correct++;
                        break;
                    case 'uncertain':
                        $uncertain_total++;
                        if (!empty($response->iscorrect)) $uncertain_correct++;
                        break;
                    case 'unlikely':
                        $unlikely_total++;
                        if (!empty($response->iscorrect)) $unlikely_correct++;
                        break;
                }
            }
        }

        $this->stats = [
            'total_questions' => $total_questions,
            'correct_answers' => $correct_answers,
            'incorrect_answers' => $total_questions - $correct_answers,
            'total_score' => $total_score,
            'percentage' => $total_questions > 0 ? round(($correct_answers / $total_questions) * 100, 1) : 0,
            'avg_response_time' => $total_questions > 0 ? round($total_response_time / $total_questions, 1) : 0,
            'total_response_time' => $total_response_time,
            'grade' => $this->classengage ? round(($correct_answers / max(1, $total_questions)) * $this->classengage->grade, 1) : 0,
            'trustworthiness_breakdown' => [
                'trustworthy' => [
                    'total' => $trustworthy_total,
                    'correct' => $trustworthy_correct,
                    'percentage' => $trustworthy_total > 0 ? round(($trustworthy_correct / $trustworthy_total) * 100, 1) : 0
                ],
                'uncertain' => [
                    'total' => $uncertain_total,
                    'correct' => $uncertain_correct,
                    'percentage' => $uncertain_total > 0 ? round(($uncertain_correct / $uncertain_total) * 100, 1) : 0
                ],
                'unlikely' => [
                    'total' => $unlikely_total,
                    'correct' => $unlikely_correct,
                    'percentage' => $unlikely_total > 0 ? round(($unlikely_correct / $unlikely_total) * 100, 1) : 0
                ]
            ]
        ];
    }

    /**
     * Get overall statistics
     *
     * @return array Statistics array
     */
    public function get_statistics() {
        return $this->stats;
    }

    /**
     * Get questions with responses
     *
     * @return array Questions with responses
     */
    public function get_questions_with_responses() {
        return $this->questions_with_responses;
    }

    /**
     * Get class comparison data (anonymized)
     *
     * @return array Comparison data
     */
    public function get_class_comparison() {
        global $DB;

        $sql = "SELECT 
                    AVG(r.score) as avg_score,
                    AVG(r.iscorrect) as avg_correct,
                    COUNT(DISTINCT r.userid) as participant_count
                FROM {classengage_responses} r
                WHERE r.sessionid = :sessionid";

        $class_data = $DB->get_record_sql($sql, ['sessionid' => $this->sessionid]);

        $user_percentage = $this->stats['percentage'];
        $class_avg_percentage = $class_data ? round(($class_data->avg_correct ?? 0) * 100, 1) : 0;

        $comparison = [];
        $comparison['your_score'] = $user_percentage;
        $comparison['class_average'] = $class_avg_percentage;
        $comparison['participants'] = $class_data->participant_count ?? 0;

        if ($user_percentage > $class_avg_percentage + 10) {
            $comparison['status'] = 'above';
            $comparison['status_text'] = 'Above Average';
            $comparison['status_icon'] = 'fa-arrow-up';
            $comparison['status_class'] = 'text-success';
        } else if ($user_percentage < $class_avg_percentage - 10) {
            $comparison['status'] = 'below';
            $comparison['status_text'] = 'Below Average';
            $comparison['status_icon'] = 'fa-arrow-down';
            $comparison['status_class'] = 'text-warning';
        } else {
            $comparison['status'] = 'average';
            $comparison['status_text'] = 'At Average';
            $comparison['status_icon'] = 'fa-minus';
            $comparison['status_class'] = 'text-info';
        }

        return $comparison;
    }

    /**
     * Get performance insights
     *
     * @return array Insights
     */
    public function get_performance_insights() {
        $insights = [];
        $stats = $this->stats;
        $trust = $stats['trustworthiness_breakdown'];

        if ($trust['trustworthy']['total'] > 0) {
            $trustworthy_acc = $trust['trustworthy']['percentage'];
            if ($trustworthy_acc < 60) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => 'fa-exclamation-triangle',
                    'message' => 'You struggled with reliable questions. Review the source material for these topics.'
                ];
            } else if ($trustworthy_acc >= 80) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => 'fa-check-circle',
                    'message' => 'Great job on reliable questions! You understood the core concepts well.'
                ];
            }
        }

        if ($trust['uncertain']['total'] > 0) {
            $uncertain_acc = $trust['uncertain']['percentage'];
            if ($uncertain_acc > 70) {
                $insights[] = [
                    'type' => 'info',
                    'icon' => 'fa-lightbulb',
                    'message' => 'You performed well even on uncertain questions. Your understanding may be deeper than average.'
                ];
            }
        }

        if ($trust['unlikely']['total'] > 0) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'fa-info-circle',
                'message' => 'Questions marked as "likely wrong" may have issues. Don\'t worry if you missed these - they may need teacher review.'
            ];
        }

        if ($stats['avg_response_time'] > 20) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-clock',
                'message' => 'Consider improving your response time. Practice with the material to recall answers faster.'
            ];
        } else if ($stats['avg_response_time'] <= 10) {
            $insights[] = [
                'type' => 'success',
                'icon' => 'fa-bolt',
                'message' => 'Excellent response time! You knew the material well.'
            ];
        }

        if ($stats['percentage'] >= 80) {
            $insights[] = [
                'type' => 'success',
                'icon' => 'fa-trophy',
                'message' => 'Excellent overall performance! Keep up the great work.'
            ];
        } else if ($stats['percentage'] < 50) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-book',
                'message' => 'Consider reviewing the lecture slides and material before the next session.'
            ];
        }

        return $insights;
    }

    /**
     * Render the summary card HTML
     *
     * @return string HTML
     */
    public function render_summary_card() {
        $stats = $this->stats;
        $comparison = $this->get_class_comparison();

        $html = '<div class="card shadow-sm mb-4">';
        $html .= '<div class="card-body">';
        $html .= '<h4 class="card-title mb-4"><i class="fa fa-chart-bar mr-2 text-primary"></i>Your Results Summary</h4>';

        $html .= '<div class="row">';

        $html .= '<div class="col-md-3 mb-3">';
        $html .= '<div class="text-center p-3 rounded result-stat-card" style="background: #4a90a4; color: white;">';
        $html .= '<h2 class="mb-0">' . round($stats['grade'], 1) . '</h2>';
        $html .= '<small>Points Earned</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3 mb-3">';
        $html .= '<div class="text-center p-3 rounded result-stat-card" style="background: #5cb85c; color: white;">';
        $html .= '<h2 class="mb-0">' . round($stats['percentage'], 0) . '%</h2>';
        $html .= '<small>Correct Answers</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3 mb-3">';
        $html .= '<div class="text-center p-3 rounded result-stat-card" style="background: #5bc0de; color: white;">';
        $html .= '<h2 class="mb-0">' . $stats['correct_answers'] . '/' . $stats['total_questions'] . '</h2>';
        $html .= '<small>Questions Right</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3 mb-3">';
        $html .= '<div class="text-center p-3 rounded result-stat-card ' . $comparison['status_class'] . '" style="background: #f8f9fa;">';
        $html .= '<h2 class="mb-0"><i class="fa ' . $comparison['status_icon'] . '"></i></h2>';
        $html .= '<small>' . $comparison['status_text'] . ' vs Class</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the question breakdown HTML
     *
     * @return string HTML
     */
    public function render_question_breakdown() {
        $questions = $this->questions_with_responses;

        if (empty($questions)) {
            return '<div class="alert alert-info">No questions were answered in this session.</div>';
        }

        $html = '<div class="card shadow-sm">';
        $html .= '<div class="card-header bg-white">';
        $html .= '<h4 class="mb-0"><i class="fa fa-list-ul mr-2 text-primary"></i>Question Breakdown</h4>';
        $html .= '</div>';
        $html .= '<div class="card-body p-0">';

        $html .= '<div class="accordion" id="questionAccordion">';

        foreach ($questions as $index => $q) {
            $q_num = $index + 1;
            $is_correct = $q['is_correct'];
            $collapse_id = 'collapse-q' . $q_num;

            $result_class = $is_correct ? 'border-left-success' : 'border-left-danger';
            $result_icon = $is_correct ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
            $result_text = $is_correct ? 'Correct' : 'Incorrect';

            $html .= '<div class="question-item ' . $result_class . '" style="border-left: 4px solid; margin-bottom: 0;">';

            $html .= '<div class="d-flex align-items-center p-3 cursor-pointer" data-toggle="collapse" data-target="#' . $collapse_id . '" aria-expanded="false" aria-controls="' . $collapse_id . '">';

            $html .= '<div class="mr-3">';
            $html .= '<span class="badge badge-pill ' . ($is_correct ? 'badge-success' : 'badge-danger') . ' p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">';
            $html .= '<i class="fa ' . $result_icon . '"></i>';
            $html .= '</span>';
            $html .= '</div>';

            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="d-flex justify-content-between align-items-center">';
            $html .= '<h5 class="mb-0">Question ' . $q_num . '</h5>';

            $trust_level = $q['trustworthiness']['level'];
            $trust_score = $q['trustworthiness']['score'];
            $trust_class = 'trustworthiness-' . $trust_level;
            $trust_icon = '';
            if ($trust_level === 'trustworthy') {
                $trust_icon = 'fa-check-circle';
            } else if ($trust_level === 'uncertain') {
                $trust_icon = 'fa-exclamation-triangle';
            } else {
                $trust_icon = 'fa-times-circle';
            }

            $html .= '<div>';
            $html .= '<span class="trustworthiness-badge ' . $trust_class . ' mr-2">';
            $html .= '<i class="fa ' . $trust_icon . '"></i>';
            $html .= 'Reliability: ' . $trust_score . '%';
            $html .= '</span>';

            $html .= '<span class="badge badge-' . ($is_correct ? 'success' : 'danger') . '">' . $result_text . '</span>';
            $html .= '</div>';
            $html .= '</div>';

            $display_text = $q['questiontext'];
            if (strlen($display_text) > 100) {
                $display_text = substr($display_text, 0, 100) . '...';
            }
            $html .= '<p class="mb-0 text-muted">' . format_string($display_text) . '</p>';
            $html .= '</div>';

            $html .= '<div class="ml-2 text-muted">';
            $html .= '<i class="fa fa-chevron-down"></i>';
            $html .= '</div>';

            $html .= '</div>';

            $html .= '<div id="' . $collapse_id . '" class="collapse" aria-labelledby="heading' . $q_num . '" data-parent="#questionAccordion">';
            $html .= '<div class="p-3 bg-light">';

            $html .= '<div class="mb-3">';
            $html .= '<h6><strong>Question:</strong></h6>';
            $html .= '<p class="mb-0">' . format_string($q['questiontext']) . '</p>';
            $html .= '</div>';

            $html .= '<div class="mb-3">';
            $html .= '<h6><strong>Your Answer:</strong></h6>';
            $user_ans = $q['user_answer'];
            $correct_ans = $q['correct_answer'];
            $user_ans_class = $is_correct ? 'text-success font-weight-bold' : 'text-danger';

            foreach (['A', 'B', 'C', 'D'] as $opt) {
                if (empty($q['options'][$opt])) continue;

                $opt_class = '';
                if ($opt === $correct_ans) {
                    $opt_class = 'list-group-item-success';
                } else if ($opt === $user_ans && !$is_correct) {
                    $opt_class = 'list-group-item-danger';
                }

                $html .= '<div class="list-group-item ' . $opt_class . ' d-flex align-items-center">';
                $html .= '<span class="badge badge-dark mr-2" style="width: 28px;">' . $opt . '</span>';
                $html .= '<span class="flex-grow-1">' . format_string($q['options'][$opt]) . '</span>';

                if ($opt === $correct_ans) {
                    $html .= '<i class="fa fa-check-circle text-success ml-2"></i>';
                }
                if ($opt === $user_ans && !$is_correct) {
                    $html .= '<i class="fa fa-times-circle text-danger ml-2"></i>';
                }

                $html .= '</div>';
            }
            $html .= '</div>';

            if (!empty($q['rationale'])) {
                $html .= '<div class="mb-3">';
                $html .= '<h6><strong>Explanation:</strong></h6>';
                $html .= '<div class="alert alert-info mb-0">';
                $html .= '<i class="fa fa-lightbulb-o mr-2"></i>' . format_string($q['rationale']);
                $html .= '</div>';
                $html .= '</div>';
            }

            $html .= '<div class="row small text-muted">';
            $html .= '<div class="col-md-4">';
            $html .= '<i class="fa fa-clock mr-1"></i> Response time: ' . round($q['responsetime']) . 's';
            $html .= '</div>';
            $html .= '<div class="col-md-4">';
            $html .= '<i class="fa fa-signal mr-1"></i> Difficulty: ' . ucfirst($q['difficulty']);
            $html .= '</div>';
            if (!empty($q['bloomlevel'])) {
                $html .= '<div class="col-md-4">';
                $html .= '<i class="fa fa-brain mr-1"></i> Bloom: ' . ucfirst($q['bloomlevel']);
                $html .= '</div>';
            }
            $html .= '</div>';

            $html .= '</div>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render performance insights HTML
     *
     * @return string HTML
     */
    public function render_insights() {
        $insights = $this->get_performance_insights();

        if (empty($insights)) {
            return '';
        }

        $html = '<div class="card shadow-sm mb-4">';
        $html .= '<div class="card-header bg-white">';
        $html .= '<h4 class="mb-0"><i class="fa fa-lightbulb mr-2 text-warning"></i>Performance Insights</h4>';
        $html .= '</div>';
        $html .= '<div class="card-body">';

        foreach ($insights as $insight) {
            $icon_class = '';
            $bg_class = '';
            switch ($insight['type']) {
                case 'success':
                    $icon_class = 'fa-check-circle text-success';
                    $bg_class = 'alert-success';
                    break;
                case 'warning':
                    $icon_class = 'fa-exclamation-triangle text-warning';
                    $bg_class = 'alert-warning';
                    break;
                case 'info':
                default:
                    $icon_class = 'fa-info-circle text-info';
                    $bg_class = 'alert-info';
            }

            $html .= '<div class="alert ' . $bg_class . ' mb-2">';
            $html .= '<i class="fa ' . $icon_class . ' mr-2"></i>';
            $html .= $insight['message'];
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render trustworthiness analysis HTML
     *
     * @return string HTML
     */
    public function render_trustworthiness_summary() {
        $stats = $this->stats;
        $trust = $stats['trustworthiness_breakdown'];

        $total_analyzed = $trust['trustworthy']['total'] + $trust['uncertain']['total'] + $trust['unlikely']['total'];

        if ($total_analyzed === 0) {
            return '';
        }

        $html = '<div class="card shadow-sm mb-4">';
        $html .= '<div class="card-header bg-white">';
        $html .= '<h4 class="mb-0"><i class="fa fa-shield-alt mr-2 text-info"></i>Question Reliability Analysis</h4>';
        $html .= '</div>';
        $html .= '<div class="card-body">';

        $html .= '<p class="text-muted small mb-3">';
        $html .= 'This shows how you performed on questions with different reliability ratings. ';
        $html .= 'Questions rated "Uncertain" or "Likely Wrong" may have issues and shouldn\'t count heavily against you.';
        $html .= '</p>';

        $html .= '<div class="row">';

        if ($trust['trustworthy']['total'] > 0) {
            $html .= '<div class="col-md-4 mb-3">';
            $html .= '<div class="p-3 rounded trustworthiness-trustworthy">';
            $html .= '<div class="d-flex justify-content-between align-items-center mb-2">';
            $html .= '<span><i class="fa fa-check-circle mr-1"></i> Reliable</span>';
            $html .= '<strong>' . $trust['trustworthy']['percentage'] . '%</strong>';
            $html .= '</div>';
            $html .= '<div class="progress" style="height: 8px;">';
            $html .= '<div class="progress-bar bg-success" style="width: ' . $trust['trustworthy']['percentage'] . '%"></div>';
            $html .= '</div>';
            $html .= '<small class="text-muted">' . $trust['trustworthy']['correct'] . '/' . $trust['trustworthy']['total'] . ' correct</small>';
            $html .= '</div>';
            $html .= '</div>';
        }

        if ($trust['uncertain']['total'] > 0) {
            $html .= '<div class="col-md-4 mb-3">';
            $html .= '<div class="p-3 rounded trustworthiness-uncertain">';
            $html .= '<div class="d-flex justify-content-between align-items-center mb-2">';
            $html .= '<span><i class="fa fa-exclamation-triangle mr-1"></i> Uncertain</span>';
            $html .= '<strong>' . $trust['uncertain']['percentage'] . '%</strong>';
            $html .= '</div>';
            $html .= '<div class="progress" style="height: 8px;">';
            $html .= '<div class="progress-bar bg-warning" style="width: ' . $trust['uncertain']['percentage'] . '%"></div>';
            $html .= '</div>';
            $html .= '<small class="text-muted">' . $trust['uncertain']['correct'] . '/' . $trust['uncertain']['total'] . ' correct</small>';
            $html .= '</div>';
            $html .= '</div>';
        }

        if ($trust['unlikely']['total'] > 0) {
            $html .= '<div class="col-md-4 mb-3">';
            $html .= '<div class="p-3 rounded trustworthiness-unlikely">';
            $html .= '<div class="d-flex justify-content-between align-items-center mb-2">';
            $html .= '<span><i class="fa fa-times-circle mr-1"></i> Likely Wrong</span>';
            $html .= '<strong>' . $trust['unlikely']['percentage'] . '%</strong>';
            $html .= '</div>';
            $html .= '<div class="progress" style="height: 8px;">';
            $html .= '<div class="progress-bar bg-danger" style="width: ' . $trust['unlikely']['percentage'] . '%"></div>';
            $html .= '</div>';
            $html .= '<small class="text-muted">' . $trust['unlikely']['correct'] . '/' . $trust['unlikely']['total'] . ' correct</small>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get all session history for this user and activity
     *
     * @return array Session history
     */
    public function get_session_history() {
        global $DB;

        $sql = "SELECT s.id, s.name, s.status, s.timecreated, s.timestarted, s.timecompleted,
                       (SELECT COUNT(*) FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid1) as total_answered,
                       (SELECT SUM(r.iscorrect) FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid2) as total_correct
                FROM {classengage_sessions} s
                WHERE s.classengageid = :classengageid
                AND s.status = 'completed'
                AND EXISTS (SELECT 1 FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid3)
                ORDER BY s.timecompleted DESC";

        return $DB->get_records_sql($sql, [
            'classengageid' => $this->classengageid,
            'userid1' => $this->userid,
            'userid2' => $this->userid,
            'userid3' => $this->userid
        ]);
    }
}
