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
 * Form for editing questions
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit question form class
 */
class edit_question_form extends \moodleform
{

    /**
     * Define the form
     */
    public function definition()
    {
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $question = $customdata['question'];

        // Question text
        $mform->addElement(
            'textarea',
            'questiontext',
            get_string('questiontext', 'mod_classengage'),
            array('rows' => 4, 'cols' => 60)
        );
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addRule('questiontext', null, 'required', null, 'client');

        // Question type
        $types = array(
            'multichoice' => get_string('multichoice', 'mod_classengage'),
        );
        $mform->addElement('select', 'questiontype', get_string('questiontype', 'mod_classengage'), $types);
        $mform->setDefault('questiontype', 'multichoice');

        // Options
        $mform->addElement(
            'textarea',
            'optiona',
            get_string('optiona', 'mod_classengage'),
            array('rows' => 2, 'cols' => 60)
        );
        $mform->setType('optiona', PARAM_TEXT);
        $mform->addRule('optiona', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'optionb',
            get_string('optionb', 'mod_classengage'),
            array('rows' => 2, 'cols' => 60)
        );
        $mform->setType('optionb', PARAM_TEXT);
        $mform->addRule('optionb', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'optionc',
            get_string('optionc', 'mod_classengage'),
            array('rows' => 2, 'cols' => 60)
        );
        $mform->setType('optionc', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'optiond',
            get_string('optiond', 'mod_classengage'),
            array('rows' => 2, 'cols' => 60)
        );
        $mform->setType('optiond', PARAM_TEXT);

        // Correct answer
        $answers = array('A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D');
        $mform->addElement('select', 'correctanswer', get_string('correctanswer', 'mod_classengage'), $answers);
        $mform->addRule('correctanswer', null, 'required', null, 'client');

        // Difficulty
        $difficulties = array(
            'easy' => get_string('easy', 'mod_classengage'),
            'medium' => get_string('medium', 'mod_classengage'),
            'hard' => get_string('hard', 'mod_classengage'),
        );
        $mform->addElement('select', 'difficulty', get_string('difficulty', 'mod_classengage'), $difficulties);
        $mform->setDefault('difficulty', 'medium');

        // Bloom's Taxonomy Level (Cognitive Level)
        $bloomlevels = array(
            '' => get_string('selectanswer', 'mod_classengage'),
            'remember' => get_string('bloom_remember', 'mod_classengage'),
            'understand' => get_string('bloom_understand', 'mod_classengage'),
            'apply' => get_string('bloom_apply', 'mod_classengage'),
            'analyze' => get_string('bloom_analyze', 'mod_classengage'),
            'evaluate' => get_string('bloom_evaluate', 'mod_classengage'),
            'create' => get_string('bloom_create', 'mod_classengage'),
        );
        $mform->addElement('select', 'bloomlevel', get_string('cognitivelevel', 'mod_classengage'), $bloomlevels);
        $mform->addHelpButton('bloomlevel', 'cognitivelevel', 'mod_classengage');

        // Rationale (AI explanation)
        $mform->addElement(
            'textarea',
            'rationale',
            get_string('rationale', 'mod_classengage'),
            array('rows' => 3, 'cols' => 60)
        );
        $mform->setType('rationale', PARAM_TEXT);
        $mform->addHelpButton('rationale', 'rationale', 'mod_classengage');

        // Source Attribution (read-only for NLP-generated questions)
        if ($question && !empty($question->sources)) {
            $sources = json_decode($question->sources, true);
            if ($sources) {
                $sourceshtml = '<div class="question-sources">';

                // Slides - compact range format (e.g., "1-5, 8-10")
                if (!empty($sources['slides'])) {
                    $ranges = $this->format_page_ranges($sources['slides']);
                    $sourceshtml .= '<div class="d-flex align-items-center mb-2">' .
                        '<span class="mr-2">' . get_string('sourceslides', 'mod_classengage') . ':</span>' .
                        '<span class="badge badge-secondary">' . s($ranges) . '</span></div>';
                }

                // Images - collapsible section
                if (!empty($sources['images'])) {
                    $imagecount = count($sources['images']);
                    $collapseid = 'source-images-' . $question->id;

                    $sourceshtml .= '<div class="mb-2">' .
                        '<a class="btn btn-sm btn-outline-secondary" data-toggle="collapse" href="#' . $collapseid . '" role="button" aria-expanded="false">' .
                        '<i class="fa fa-images mr-1"></i>' . get_string('sourceimages', 'mod_classengage') .
                        ' <span class="badge badge-pill badge-secondary ml-1">' . $imagecount . '</span>' .
                        '</a></div>';

                    $sourceshtml .= '<div class="collapse" id="' . $collapseid . '">';
                    $sourceshtml .= '<div class="d-flex flex-wrap" style="gap: 8px;">';

                    foreach ($sources['images'] as $img) {
                        $label = s($img['label'] ?? 'Image');
                        $url = $img['url'] ?? '';

                        // Build public URL for images
                        $publicurl = get_config('mod_classengage', 'nlppublicurl');
                        $baseurl = !empty($publicurl) ? $publicurl : get_config('mod_classengage', 'nlpendpoint');
                        if ($url && strpos($url, 'http') !== 0) {
                            $url = rtrim($baseurl, '/') . $url;
                        }

                        if ($url) {
                            $sourceshtml .= '<div class="card" style="width: 100px;">' .
                                '<img src="' . s($url) . '" class="card-img-top" alt="' . $label . '" style="height: 70px; object-fit: cover;">' .
                                '<div class="card-body p-1 text-center"><small class="text-muted" style="font-size: 0.7rem;">' . $label . '</small></div></div>';
                        } else {
                            $sourceshtml .= '<span class="badge badge-info">' . $label . '</span>';
                        }
                    }
                    $sourceshtml .= '</div></div>';
                }

                $sourceshtml .= '</div>';

                $mform->addElement(
                    'static',
                    'sources_display',
                    get_string('questionsources', 'mod_classengage'),
                    $sourceshtml
                );
            }
        }

        // Buttons
        $this->add_action_buttons(true, get_string('savequestion', 'mod_classengage'));

        // Set defaults if editing
        if ($question) {
            $this->set_data($question);
        }
    }

    /**
     * Format an array of page numbers into compact ranges
     * 
     * e.g., [1,2,3,5,6,8] => "1-3, 5-6, 8"
     * 
     * @param array $pages Array of page numbers
     * @return string Formatted range string
     */
    protected function format_page_ranges(array $pages): string
    {
        if (empty($pages)) {
            return '';
        }

        // Ensure numeric and sort
        $pages = array_map('intval', $pages);
        sort($pages);
        $pages = array_unique($pages);

        $ranges = [];
        $start = $pages[0];
        $end = $pages[0];

        for ($i = 1; $i < count($pages); $i++) {
            if ($pages[$i] == $end + 1) {
                // Continue the range
                $end = $pages[$i];
            } else {
                // End current range, start new one
                $ranges[] = ($start == $end) ? (string) $start : "{$start}-{$end}";
                $start = $pages[$i];
                $end = $pages[$i];
            }
        }

        // Add the last range
        $ranges[] = ($start == $end) ? (string) $start : "{$start}-{$end}";

        return implode(', ', $ranges);
    }
}

