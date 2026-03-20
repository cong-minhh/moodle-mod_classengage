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
 * Question Trustworthiness Analyzer for ClassEngage
 *
 * Analyzes AI-generated questions to predict their trustworthiness/quality
 * using multiple factors including source coverage, question clarity,
 * answer plausibility, historical accuracy, and content consistency.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

class question_trustworthiness_analyzer {

    /** @var array Source text used for generation */
    private $sourcetext = '';

    /** @var array Source images used for generation */
    private $sourceimages = [];

    /** @var object Question being analyzed */
    private $question = null;

    /** @var int ClassEngage activity ID */
    private $classengageid = 0;

    /** @var array Analysis factors and their scores */
    private $factors = [];

    /** @var int Final trustworthiness score (0-100) */
    private $score = 0;

    /** @var string Trustworthiness level */
    private $level = '';

    /** @var float Minimum score difference for ambiguity */
    const AMBIGUITY_THRESHOLD = 0.15;

    /** @var int Minimum word count for source coverage */
    const MIN_SOURCE_WORDS = 50;

    /**
     * Constructor
     *
     * @param int $classengageid
     */
    public function __construct($classengageid = 0) {
        $this->classengageid = $classengageid;
    }

    /**
     * Analyze a question for trustworthiness
     *
     * @param object $question Question object with all properties
     * @param string $sourcetext Source text used to generate the question
     * @param array $sourceimages Source images used
     * @return array Analysis results with score, level, and factors
     */
    public function analyze($question, $sourcetext = '', $sourceimages = []) {
        global $DB;

        $this->question = $question;
        $this->sourcetext = $sourcetext;
        $this->sourceimages = $sourceimages;

        $this->factors = [];

        $this->analyze_source_coverage();
        $this->analyze_question_clarity();
        $this->analyze_answer_plausibility();
        $this->analyze_historical_accuracy();
        $this->analyze_content_consistency();

        $this->calculate_final_score();

        $result = [
            'score' => $this->score,
            'level' => $this->level,
            'factors' => $this->factors,
            'questionid' => $question->id,
            'analyzed_at' => time()
        ];

        $this->save_analysis_to_question($result);

        return $result;
    }

    /**
     * Analyze source coverage factor
     * Checks if the source material contains enough information to answer the question
     */
    private function analyze_source_coverage() {
        $score = 50;
        $details = [];

        $questiontext = strtolower($this->question->questiontext ?? '');
        $correctanswer = $this->get_correct_answer_text();
        $options = $this->get_all_options();

        if (empty($this->sourcetext)) {
            $this->factors['source_coverage'] = [
                'score' => 30,
                'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['source_coverage'],
                'status' => 'warning',
                'details' => 'No source text provided for analysis'
            ];
            return;
        }

        $source_lower = strtolower($this->sourcetext);
        $source_words = str_word_count($source_lower);

        $details[] = "Source text contains {$source_words} words";

        if ($source_words < self::MIN_SOURCE_WORDS) {
            $details[] = 'Source text is too short for reliable question generation';
            $score -= 30;
        }

        $keywords = $this->extract_keywords($questiontext);
        $found_keywords = 0;
        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 3 && strpos($source_lower, $keyword) !== false) {
                $found_keywords++;
            }
        }

        $keyword_coverage = count($keywords) > 0 ? ($found_keywords / count($keywords)) : 0;
        $details[] = "Question keywords found in source: " . round($keyword_coverage * 100) . "%";

        $score = $score * 0.3 + $keyword_coverage * 70;

        if ($correctanswer && strlen($correctanswer) > 2) {
            $answer_in_source = strpos($source_lower, strtolower($correctanswer)) !== false;
            if ($answer_in_source) {
                $score += 10;
                $details[] = 'Correct answer found directly in source';
            } else {
                $score -= 10;
                $details[] = 'Correct answer not found in source (may require inference)';
            }
        }

        $score = max(0, min(100, $score));

        $status = 'success';
        if ($score < 40) {
            $status = 'danger';
        } else if ($score < 60) {
            $status = 'warning';
        }

        $this->factors['source_coverage'] = [
            'score' => round($score),
            'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['source_coverage'],
            'status' => $status,
            'details' => implode('; ', $details),
            'keyword_coverage' => round($keyword_coverage * 100)
        ];
    }

    /**
     * Analyze question clarity factor
     * Checks for ambiguity, clarity, and proper question structure
     */
    private function analyze_question_clarity() {
        $score = 70;
        $details = [];

        $questiontext = $this->question->questiontext ?? '';
        $question_lower = strtolower($questiontext);

        if (empty($questiontext)) {
            $this->factors['question_clarity'] = [
                'score' => 0,
                'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['question_clarity'],
                'status' => 'danger',
                'details' => 'Empty question text'
            ];
            return;
        }

        $ambiguous_patterns = [
            '/\b(all|always|none|never|every)\b/i' => 'Absolute language detected',
            '/\b(might|could|perhaps|maybe)\b/i' => 'Uncertain language detected',
            '/\s{2,}/' => 'Multiple consecutive spaces found',
            '/\b(includes|contains|involves)\b.*\b(all|only)\b/i' => 'Potentially ambiguous scope'
        ];

        $issues = [];
        foreach ($ambiguous_patterns as $pattern => $description) {
            if (preg_match($pattern, $question_lower)) {
                $issues[] = $description;
                $score -= 10;
            }
        }

        $question_starters = ['what', 'which', 'who', 'where', 'when', 'why', 'how', 'did', 'does', 'is', 'are', 'can', 'should'];
        $starts_with_question = false;
        foreach ($question_starters as $starter) {
            if (strpos($question_lower, $starter . ' ') === 0) {
                $starts_with_question = true;
                break;
            }
        }

        if (!$starts_with_question) {
            $issues[] = 'Question does not start with typical question word';
            $score -= 5;
        }

        $length = strlen($questiontext);
        if ($length < 15) {
            $issues[] = 'Question is very short';
            $score -= 15;
        } else if ($length > 500) {
            $issues[] = 'Question is very long';
            $score -= 10;
        }

        if (empty($issues)) {
            $details[] = 'Question structure looks good';
        } else {
            $details = $issues;
        }

        $score = max(0, min(100, $score));

        $status = 'success';
        if ($score < 40) {
            $status = 'danger';
        } else if ($score < 60) {
            $status = 'warning';
        }

        $this->factors['question_clarity'] = [
            'score' => round($score),
            'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['question_clarity'],
            'status' => $status,
            'details' => implode('; ', $details),
            'issues_count' => count($issues)
        ];
    }

    /**
     * Analyze answer plausibility factor
     * Checks if all options are plausible and if distractors are reasonable
     */
    private function analyze_answer_plausibility() {
        $score = 60;
        $details = [];

        $options = [
            'A' => $this->question->optiona ?? '',
            'B' => $this->question->optionb ?? '',
            'C' => $this->question->optionc ?? '',
            'D' => $this->question->optiond ?? ''
        ];

        $correctanswer = strtoupper($this->question->correctanswer ?? '');

        $empty_options = array_filter($options, function($opt) {
            return empty(trim($opt));
        });

        if (count($empty_options) > 0) {
            $details[] = count($empty_options) . ' empty options found';
            $score -= 20 * count($empty_options);
        }

        $options = array_filter($options);
        $option_lengths = array_map('strlen', $options);
        $avg_length = array_sum($option_lengths) / count($option_lengths);

        if ($avg_length < 3) {
            $details[] = 'Options are too short';
            $score -= 15;
        }

        $unique_options = array_unique(array_map('trim', $options));
        if (count($unique_options) < count($options)) {
            $details[] = 'Duplicate options detected';
            $score -= 25;
        }

        if ($correctanswer && isset($options[$correctanswer])) {
            $correct_len = strlen($options[$correctanswer]);
            foreach ($options as $key => $opt) {
                if ($key !== $correctanswer) {
                    $len_diff = abs(strlen($opt) - $correct_len);
                    if ($len_diff > $correct_len * 0.5) {
                        $details[] = "Option {$key} length differs significantly from correct answer";
                        $score -= 5;
                    }
                }
            }
        }

        $similarity_issues = $this->check_option_similarity($options);
        if (!empty($similarity_issues)) {
            $details = array_merge($details, $similarity_issues);
            $score -= count($similarity_issues) * 5;
        }

        $score = max(0, min(100, $score));

        $status = 'success';
        if ($score < 40) {
            $status = 'danger';
        } else if ($score < 60) {
            $status = 'warning';
        }

        if (empty($details)) {
            $details[] = 'Options appear well-constructed';
        }

        $this->factors['answer_plausibility'] = [
            'score' => round($score),
            'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['answer_plausibility'],
            'status' => $status,
            'details' => implode('; ', $details),
            'empty_options' => count($empty_options)
        ];
    }

    /**
     * Analyze historical accuracy factor
     * Uses actual student performance data on similar questions
     */
    private function analyze_historical_accuracy() {
        global $DB;

        $score = 50;
        $details = [];

        if ($this->classengageid == 0 || empty($this->question->id)) {
            $this->factors['historical_accuracy'] = [
                'score' => 50,
                'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['historical_accuracy'],
                'status' => 'warning',
                'details' => 'No historical data available for this activity'
            ];
            return;
        }

        $sql = "SELECT 
                    COUNT(*) as total_attempts,
                    SUM(r.iscorrect) as correct_count,
                    AVG(r.iscorrect) as accuracy
                FROM {classengage_responses} r
                JOIN {classengage_questions} q ON q.id = r.questionid
                WHERE r.classengageid = :classengageid
                AND r.questionid != :current_question
                AND q.questiontext LIKE :similar_text";

        $similar_text = '%' . substr($this->question->questiontext ?? '', 0, 50) . '%';
        
        $stats = $DB->get_record_sql($sql, [
            'classengageid' => $this->classengageid,
            'current_question' => $this->question->id,
            'similar_text' => $similar_text
        ]);

        if ($stats && $stats->total_attempts >= 10) {
            $accuracy = $stats->accuracy * 100;
            $details[] = "Based on {$stats->total_attempts} similar question attempts";
            $details[] = "Historical accuracy: " . round($accuracy, 1) . "%";

            $score = $accuracy;
        } else {
            $total_questions_sql = "SELECT COUNT(DISTINCT questionid) as count 
                                    FROM {classengage_responses} 
                                    WHERE classengageid = :classengageid";
            $question_count = $DB->get_record_sql($total_questions_sql, [
                'classengageid' => $this->classengageid
            ]);

            if ($question_count && $question_count->count > 0) {
                $details[] = "{$question_count->count} questions with response history";
                $score = 55;
            } else {
                $details[] = 'No historical data available';
                $score = 50;
            }
        }

        $score = max(0, min(100, $score));

        $status = 'success';
        if ($score < 40) {
            $status = 'danger';
        } else if ($score < 60) {
            $status = 'warning';
        }

        $this->factors['historical_accuracy'] = [
            'score' => round($score),
            'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['historical_accuracy'],
            'status' => $status,
            'details' => implode('; ', $details)
        ];
    }

    /**
     * Analyze content consistency factor
     * Checks for internal consistency between question and answer
     */
    private function analyze_content_consistency() {
        $score = 70;
        $details = [];

        $questiontext = $this->question->questiontext ?? '';
        $rationale = $this->question->rationale ?? '';

        $options = [
            'A' => $this->question->optiona ?? '',
            'B' => $this->question->optionb ?? '',
            'C' => $this->question->optionc ?? '',
            'D' => $this->question->optiond ?? ''
        ];

        $correctanswer = strtoupper($this->question->correctanswer ?? '');

        if (empty($correctanswer) || !isset($options[$correctanswer])) {
            $details[] = 'Correct answer not properly specified';
            $score -= 30;
        }

        $question_keywords = $this->extract_keywords($questiontext);
        $correct_option = $options[$correctanswer] ?? '';
        $option_keywords = $this->extract_keywords($correct_option);

        $overlap = array_intersect($question_keywords, $option_keywords);
        if (count($question_keywords) > 0) {
            $overlap_ratio = count($overlap) / count($question_keywords);
            if ($overlap_ratio < 0.1) {
                $details[] = 'Minimal keyword overlap between question and correct answer';
                $score -= 15;
            } else if ($overlap_ratio > 0.5) {
                $details[] = 'Good keyword consistency between question and answer';
                $score += 10;
            }
        }

        $all_options_text = implode(' ', $options);
        $all_keywords = $this->extract_keywords($all_options_text);
        $unique_keywords = array_unique($all_keywords);

        if (count($unique_keywords) < count($all_keywords) * 0.3) {
            $details[] = 'Options share very similar vocabulary';
            $score -= 15;
        }

        if (!empty($rationale)) {
            $rationale_lower = strtolower($rationale);
            $rationale_length = strlen($rationale);

            if ($rationale_length < 20) {
                $details[] = 'Rationale is too short';
                $score -= 10;
            }

            $rationale_has_context = strpos($rationale_lower, $question_lower ?? '') !== false 
                || strpos($rationale_lower, 'because') !== false
                || strpos($rationale_lower, 'since') !== false;

            if ($rationale_has_context) {
                $details[] = 'Rationale provides context';
                $score += 10;
            }
        } else {
            $details[] = 'No rationale provided';
            $score -= 10;
        }

        $score = max(0, min(100, $score));

        $status = 'success';
        if ($score < 40) {
            $status = 'danger';
        } else if ($score < 60) {
            $status = 'warning';
        }

        if (empty($details)) {
            $details[] = 'Question and answer are internally consistent';
        }

        $this->factors['content_consistency'] = [
            'score' => round($score),
            'weight' => constants::TRUSTWORTHINESS_FACTOR_WEIGHTS['content_consistency'],
            'status' => $status,
            'details' => implode('; ', $details)
        ];
    }

    /**
     * Calculate the final trustworthiness score
     */
    private function calculate_final_score() {
        $weighted_sum = 0;
        $total_weight = 0;

        foreach ($this->factors as $factor_key => $factor) {
            $weight = $factor['weight'] ?? 0.2;
            $weighted_sum += $factor['score'] * $weight;
            $total_weight += $weight;
        }

        $this->score = $total_weight > 0 ? round($weighted_sum / $total_weight) : 50;

        if ($this->score >= constants::TRUSTWORTHINESS_SCORE_TRUSTWORTHY) {
            $this->level = constants::TRUSTWORTHINESS_TRUSTWORTHY;
        } else if ($this->score >= constants::TRUSTWORTHINESS_SCORE_UNCERTAIN) {
            $this->level = constants::TRUSTWORTHINESS_UNCERTAIN;
        } else {
            $this->level = constants::TRUSTWORTHINESS_UNLIKELY;
        }
    }

    /**
     * Save analysis results to the question record
     *
     * @param array $result Analysis result
     */
    private function save_analysis_to_question($result) {
        global $DB;

        if (empty($this->question->id)) {
            return;
        }

        $update = new \stdClass();
        $update->id = $this->question->id;
        $update->trustworthiness_score = $result['score'];
        $update->trustworthiness_level = $result['level'];
        $update->trustworthiness_factors = json_encode($result['factors']);
        $update->trustworthiness_analyzed = 1;
        $update->timemodified = time();

        $DB->update_record('classengage_questions', $update);
    }

    /**
     * Extract keywords from text
     *
     * @param string $text
     * @return array Keywords
     */
    private function extract_keywords($text) {
        if (empty($text)) {
            return [];
        }

        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        $stopwords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'must', 'can', 'to', 'of', 'in', 'for',
            'on', 'with', 'at', 'by', 'from', 'as', 'into', 'through', 'during',
            'before', 'after', 'above', 'below', 'between', 'under', 'again',
            'further', 'then', 'once', 'here', 'there', 'when', 'where', 'why',
            'how', 'all', 'each', 'few', 'more', 'most', 'other', 'some', 'such',
            'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too',
            'very', 'just', 'and', 'but', 'if', 'or', 'because', 'until',
            'while', 'this', 'that', 'these', 'those', 'what', 'which', 'who',
            'whom', 'their', 'they', 'them', 'its', 'it'
        ];

        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });

        return array_values($words);
    }

    /**
     * Get the correct answer text
     *
     * @return string Correct answer option text
     */
    private function get_correct_answer_text() {
        $correctanswer = strtoupper($this->question->correctanswer ?? '');
        $options = [
            'A' => $this->question->optiona ?? '',
            'B' => $this->question->optionb ?? '',
            'C' => $this->question->optionc ?? '',
            'D' => $this->question->optiond ?? ''
        ];

        return $options[$correctanswer] ?? '';
    }

    /**
     * Get all options as array
     *
     * @return array All options
     */
    private function get_all_options() {
        return [
            'A' => $this->question->optiona ?? '',
            'B' => $this->question->optionb ?? '',
            'C' => $this->question->optionc ?? '',
            'D' => $this->question->optiond ?? ''
        ];
    }

    /**
     * Check if options are too similar
     *
     * @param array $options Options array
     * @return array List of similarity issues
     */
    private function check_option_similarity($options) {
        $issues = [];
        $options = array_filter($options);

        foreach ($options as $key1 => $opt1) {
            foreach ($options as $key2 => $opt2) {
                if ($key1 >= $key2) continue;

                $opt1_lower = strtolower(trim($opt1));
                $opt2_lower = strtolower(trim($opt2));

                if ($opt1_lower === $opt2_lower) {
                    $issues[] = "Options {$key1} and {$key2} are identical";
                    continue;
                }

                similar_text($opt1_lower, $opt2_lower, $percent);
                if ($percent > 85) {
                    $issues[] = "Options {$key1} and {$key2} are very similar ({$percent}% match)";
                }
            }
        }

        return $issues;
    }

    /**
     * Get trustworthiness color info for UI
     *
     * @param string $level Trustworthiness level
     * @return array Color information
     */
    public static function get_color_info($level) {
        $colors = constants::TRUSTWORTHINESS_COLORS;
        return $colors[$level] ?? $colors[constants::TRUSTWORTHINESS_UNCERTAIN];
    }

    /**
     * Get trustworthiness badge HTML
     *
     * @param object $question Question object
     * @param bool $show_tooltip Whether to show tooltip with details
     * @return string HTML for badge
     */
    public static function get_badge_html($question, $show_tooltip = true) {
        $level = $question->trustworthiness_level ?? constants::TRUSTWORTHINESS_UNCERTAIN;
        $score = $question->trustworthiness_score ?? 0;
        $color_info = self::get_color_info($level);

        $analyzed = !empty($question->trustworthiness_analyzed);

        if (!$analyzed) {
            return html_writer::span(
                html_writer::tag('i', '', ['class' => 'fa fa-question-circle mr-1']) .
                get_string('notanalyzed', 'mod_classengage'),
                'badge badge-secondary'
            );
        }

        $icon_class = 'fa-' . $color_info['icon'];
        $badge_content = html_writer::tag('i', '', ['class' => 'fa ' . $icon_class . ' mr-1']) .
                        ucfirst($level) . ' (' . $score . '%)';

        $tooltip = '';
        if ($show_tooltip && !empty($question->trustworthiness_factors)) {
            $factors = json_decode($question->trustworthiness_factors, true);
            $tooltip_parts = [];

            foreach ($factors as $factor_key => $factor) {
                $factor_name = constants::TRUSTWORTHINESS_FACTORS[$factor_key] ?? $factor_key;
                $factor_score = $factor['score'] ?? 0;
                $tooltip_parts[] = "{$factor_name}: {$factor_score}%";
            }

            $tooltip = implode("\n", $tooltip_parts);
        }

        $attrs = [
            'class' => 'badge trustworthiness-badge',
            'style' => 'background-color: ' . $color_info['bg'] . '; ' .
                      'color: ' . $color_info['text'] . '; ' .
                      'border: 1px solid ' . $color_info['border'] . '; ' .
                      'padding: 4px 10px; border-radius: 12px;'
        ];

        if ($show_tooltip && $tooltip) {
            $attrs['data-toggle'] = 'tooltip';
            $attrs['data-placement'] = 'top';
            $attrs['title'] = $tooltip;
            $attrs['data-html'] = 'true';
        }

        return html_writer::span($badge_content, '', $attrs);
    }

    /**
     * Batch analyze multiple questions
     *
     * @param array $questions Array of question objects
     * @param string $sourcetext Source text
     * @param array $sourceimages Source images
     * @return array Array of analysis results
     */
    public function analyze_batch($questions, $sourcetext = '', $sourceimages = []) {
        $results = [];

        foreach ($questions as $question) {
            try {
                $results[$question->id] = $this->analyze($question, $sourcetext, $sourceimages);
            } catch (\Exception $e) {
                $results[$question->id] = [
                    'error' => $e->getMessage(),
                    'score' => 0,
                    'level' => constants::TRUSTWORTHINESS_UNCERTAIN
                ];
            }
        }

        return $results;
    }

    /**
     * Get trustworthiness statistics for an activity
     *
     * @param int $classengageid Activity ID
     * @return array Statistics array
     */
    public static function get_activity_statistics($classengageid) {
        global $DB;

        $sql = "SELECT 
                    trustworthiness_level,
                    COUNT(*) as count,
                    AVG(trustworthiness_score) as avg_score
                FROM {classengage_questions}
                WHERE classengageid = :classengageid
                AND trustworthiness_analyzed = 1
                GROUP BY trustworthiness_level";

        $stats = $DB->get_records_sql($sql, ['classengageid' => $classengageid]);

        $result = [
            'total_analyzed' => 0,
            'trustworthy' => 0,
            'uncertain' => 0,
            'unlikely' => 0,
            'avg_score' => 0,
            'not_analyzed' => 0
        ];

        $total_score = 0;
        foreach ($stats as $stat) {
            $result['total_analyzed'] += $stat->count;
            $total_score += $stat->avg_score * $stat->count;

            switch ($stat->trustworthiness_level) {
                case constants::TRUSTWORTHINESS_TRUSTWORTHY:
                    $result['trustworthy'] = $stat->count;
                    break;
                case constants::TRUSTWORTHINESS_UNCERTAIN:
                    $result['uncertain'] = $stat->count;
                    break;
                case constants::TRUSTWORTHINESS_UNLIKELY:
                    $result['unlikely'] = $stat->count;
                    break;
            }
        }

        if ($result['total_analyzed'] > 0) {
            $result['avg_score'] = round($total_score / $result['total_analyzed'], 1);
        }

        $total_questions = $DB->count_records('classengage_questions', ['classengageid' => $classengageid]);
        $result['not_analyzed'] = $total_questions - $result['total_analyzed'];

        return $result;
    }
}
