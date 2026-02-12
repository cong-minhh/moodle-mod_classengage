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
 * NLP question generation class (local PHP implementation).
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

use mod_classengage\nlp\provider_manager;
use mod_classengage\nlp\file_processing_service;
use mod_classengage\nlp\content_filter;
use mod_classengage\nlp\storage\document_storage;
use mod_classengage\nlp\storage\image_asset_storage;

class nlp_generator {
    /**
     * Inspect document content to get structure (pages/images).
     *
     * @param \stored_file $file Moodle stored file
     * @return array Document inspection result with docId and pages
     */
    public function inspect_document($file): array {
        global $DB;

        $contextid = $file->get_contextid();
        $slideid = (int) $file->get_itemid();
        $classengageid = 0;
        if ($slideid) {
            $slide = $DB->get_record('classengage_slides', ['id' => $slideid]);
            if ($slide) {
                $classengageid = (int) $slide->classengageid;
            }
        }

        $docinfo = document_storage::ensure_document($file, $contextid, $classengageid, $slideid);
        $docid = $docinfo['docId'];
        $extraction = document_storage::get_extraction($docid);

        if ($extraction === null) {
            $processor = new file_processing_service();
            $extraction = $processor->process(
                $docinfo['path'],
                $file->get_filename(),
                [
                    'docId' => $docid,
                ]
            );
            document_storage::save_extraction($docid, $extraction);
        }

        return [
            'docId' => $docid,
            'pages' => $extraction['pages'] ?? [],
        ];
    }

    /**
     * Generate questions from a previously inspected document.
     *
     * @param string $docid
     * @param int $classengageid
     * @param int $slideid
     * @param array $options
     * @return array
     */
    public function generate_questions_from_document(string $docid, int $classengageid, int $slideid, array $options = []): array {
        $options = $this->apply_defaults($options);

        $extraction = document_storage::get_extraction($docid);
        if ($extraction === null) {
            $meta = document_storage::get_metadata($docid);
            $processor = new file_processing_service();
            $extraction = $processor->process(
                $meta['path'],
                $meta['originalname'],
                [
                    'docId' => $docid,
                ]
            );
            document_storage::save_extraction($docid, $extraction);
        }

        $options['docId'] = $docid;
        $filtered = content_filter::apply($extraction, $options);

        $payload = $filtered['text'];
        if (!empty($filtered['images'])) {
            $payload = [
                'text' => $filtered['text'] ?? '',
                'images' => $filtered['images'],
            ];
        }

        $distributionplan = $this->build_distribution_plan($options);
        if ($distributionplan) {
            $options['distributionPlan'] = $distributionplan;
        }
        $options['imageMetadata'] = $filtered['imageMetadata'] ?? [];

        $manager = new provider_manager();
        $provider = $manager->select_provider(!empty($filtered['images']));

        $result = $provider->generate_questions($payload, $options);
        if (empty($result['questions'])) {
            throw new \Exception('AI provider returned no questions');
        }

        if (!empty($distributionplan)) {
            if (!isset($result['metadata'])) {
                $result['metadata'] = [];
            }
            $result['metadata']['plan'] = $distributionplan['breakdown'] ?? null;
        }

        $sources = $filtered['sources'] ?? [];
        $questions = $this->normalize_question_images($result['questions'], $docid, $filtered['imageMetadata'] ?? [], $sources);

        $questionids = $this->store_questions($questions, $classengageid, $slideid);

        return [
            'questionids' => $questionids,
            'count' => count($questionids),
            'provider' => $result['provider'] ?? $provider->get_name(),
            'model' => $result['metadata']['model'] ?? $provider->get_current_model(),
            'analysis' => $result['analysis'] ?? null,
            'metadata' => $result['metadata'] ?? null,
        ];
    }

    /**
     * Generate questions with async interface (local implementation).
     *
     * @param string $docid
     * @param int $classengageid
     * @param int $slideid
     * @param array $options
     * @param int $maxwait
     * @param int $pollinterval
     * @return array
     */
    public function generate_questions_async(string $docid, int $classengageid, int $slideid, array $options = [], int $maxwait = 600, int $pollinterval = 2): array {
        $result = $this->generate_questions_from_document($docid, $classengageid, $slideid, $options);
        $result['jobId'] = 'local_' . uniqid();
        return $result;
    }

    /**
     * No-op job status sync (local implementation).
     *
     * @param int $slideid
     * @return void
     */
    public function check_and_update_job_status($slideid): void {
        return;
    }

    /**
     * Generate questions from raw text.
     *
     * @param string $text
     * @param int $classengageid
     * @param int $numquestions
     * @param string $difficulty
     * @return array
     */
    public function generate_questions_from_text(string $text, int $classengageid, int $numquestions = 10, string $difficulty = 'medium'): array {
        $manager = new provider_manager();
        $provider = $manager->select_provider(false);

        $result = $provider->generate_questions($text, [
            'numQuestions' => $numquestions,
            'difficulty' => $difficulty,
        ]);

        if (empty($result['questions'])) {
            throw new \Exception('AI provider returned no questions');
        }

        return $this->store_questions($result['questions'], $classengageid, 0);
    }

    /**
     * Analyze class session data using AI.
     *
     * @param array $sessiondata
     * @param array $options
     * @return array
     */
    public function analyze_session(array $sessiondata, array $options = []): array {
        $manager = new provider_manager();
        $provider = $manager->select_provider(false);
        $prompt = $this->build_analysis_prompt($sessiondata, $options);
        $raw = $provider->generate_response($prompt);

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [
                'summary' => 'Analysis generated but format was invalid.',
                'strengths' => ['Could not parse details.'],
                'areas_for_improvement' => [],
                'actionable_advice' => ['Please try generating analysis again.'],
            ];
        }

        return $parsed;
    }

    /**
     * Legacy method for backward compatibility.
     *
     * @param \stored_file $file
     * @param int $classengageid
     * @param int $slideid
     * @param int|null $numquestions
     * @return array
     */
    public function generate_questions_from_file($file, $classengageid, $slideid, $numquestions = null): array {
        $inspection = $this->inspect_document($file);
        $docid = $inspection['docId'] ?? null;
        if (empty($docid)) {
            throw new \Exception('Document inspection failed');
        }
        $options = [
            'numQuestions' => $numquestions ?? (int) (\get_config('mod_classengage', 'defaultquestions') ?: 10),
        ];
        $result = $this->generate_questions_from_document($docid, (int) $classengageid, (int) $slideid, $options);
        return $result['questionids'];
    }

    /**
     * Store generated questions in database.
     *
     * @param array $questions
     * @param int $classengageid
     * @param int $slideid
     * @return array
     */
    protected function store_questions(array $questions, int $classengageid, int $slideid): array {
        global $DB;

        $questionids = [];
        $now = time();

        foreach ($questions as $q) {
            $question = new \stdClass();
            $question->classengageid = $classengageid;
            $question->slideid = $slideid;
            $question->questiontext = $q['questiontext'];
            $question->questiontype = 'multichoice';
            $question->optiona = $q['optiona'];
            $question->optionb = $q['optionb'];
            $question->optionc = $q['optionc'];
            $question->optiond = $q['optiond'];
            $question->correctanswer = $q['correctanswer'];
            $question->difficulty = $q['difficulty'] ?? 'medium';
            $question->bloomlevel = $q['bloomLevel'] ?? $q['bloomlevel'] ?? $q['bloom_level'] ?? $q['cognitive_level'] ?? null;
            $question->rationale = $q['rationale'] ?? null;
            $question->sources = !empty($q['sources']) ? json_encode($q['sources']) : null;
            $question->question_image = $q['question_image'] ?? null;
            $question->status = 'pending';
            $question->source = 'nlp';
            $question->timecreated = $now;
            $question->timemodified = $now;

            $questionids[] = $DB->insert_record('classengage_questions', $question);
        }

        return $questionids;
    }

    private function apply_defaults(array $options): array {
        if (!isset($options['numQuestions'])) {
            $options['numQuestions'] = (int) (\get_config('mod_classengage', 'defaultquestions') ?: 10);
        }
        if (empty($options['difficulty'])) {
            $options['difficulty'] = 'mixed';
        }
        if (empty($options['bloomLevel'])) {
            $options['bloomLevel'] = 'apply';
        }
        return $options;
    }

    private function build_distribution_plan(array $options): ?array {
        $numquestions = (int) ($options['numQuestions'] ?? 10);
        $diffdist = $options['difficultyDistribution'] ?? null;
        $bloomdist = $options['bloomDistribution'] ?? null;

        if (empty($diffdist) && empty($bloomdist)) {
            return null;
        }

        $slots = array_fill(0, $numquestions, []);
        if (!empty($diffdist) && is_array($diffdist)) {
            $current = 0;
            foreach ($diffdist as $key => $count) {
                for ($i = 0; $i < (int) $count; $i++) {
                    if (isset($slots[$current])) {
                        $slots[$current]['difficulty'] = $key;
                    }
                    $current++;
                }
            }
        }

        if (!empty($bloomdist) && is_array($bloomdist)) {
            $bloomlist = [];
            foreach ($bloomdist as $key => $count) {
                for ($i = 0; $i < (int) $count; $i++) {
                    $bloomlist[] = $key;
                }
            }
            shuffle($bloomlist);
            foreach ($bloomlist as $index => $bloom) {
                if (isset($slots[$index])) {
                    $slots[$index]['bloomLevel'] = $bloom;
                }
            }
        }

        $counts = [];
        foreach ($slots as $slot) {
            $diff = $slot['difficulty'] ?? ($options['difficulty'] ?? 'medium');
            $bloom = $slot['bloomLevel'] ?? ($options['bloomLevel'] ?? 'apply');
            $key = $diff . '|' . $bloom;
            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'difficulty' => $diff,
                    'bloomLevel' => $bloom,
                    'count' => 0,
                ];
            }
            $counts[$key]['count']++;
        }

        return [
            'total' => $numquestions,
            'breakdown' => array_values($counts),
        ];
    }

    private function normalize_question_images(array $questions, string $docid, array $imagemetadata, array $sources): array {
        $imageids = [];
        foreach ($imagemetadata as $img) {
            if (!empty($img['imageId'])) {
                $imageids[$img['imageId']] = true;
            }
        }

        foreach ($questions as &$question) {
            if (!empty($question['question_image'])) {
                $imgid = $question['question_image'];
                if (isset($imageids[$imgid])) {
                    $question['question_image'] = image_asset_storage::build_url($docid, $imgid);
                }
            }
            if (!empty($sources)) {
                $question['sources'] = $sources;
            }
        }
        unset($question);

        return $questions;
    }

    private function build_analysis_prompt(array $data, array $options = []): string {
        $engagement = "- Engagement Level: " . ($data['engagement']['percentage'] ?? 0) . "% (" . ($data['engagement']['level'] ?? 'Unknown') . ")\n" .
            "- Participation: " . ($data['engagement']['unique_participants'] ?? 0) . "/" . ($data['engagement']['total_enrolled'] ?? 0) . " students\n";

        $comprehension = "- Overall Comprehension: " . ($data['comprehension']['level'] ?? 'Unknown') . " (Avg Correctness: " . ($data['comprehension']['avg_correctness'] ?? 0) . "%)\n" .
            "- Confused Topics: " . (!empty($data['comprehension']['confused_topics']) ? implode(', ', $data['comprehension']['confused_topics']) : 'None') . "\n";

        $activities = "- Questions Answered: " . ($data['activity_counts']['questions_answered'] ?? 0) . "\n" .
            "- Polls: " . ($data['activity_counts']['poll_submissions'] ?? 0) . "\n";

        $concepts = '';
        if (!empty($data['concept_difficulty']) && is_array($data['concept_difficulty'])) {
            $concepts .= "\n**Question Analysis (Concept Difficulty):**\n";
            foreach ($data['concept_difficulty'] as $concept) {
                $concepts .= "- Q" . ($concept['question_order'] ?? 0) . " [" . ($concept['difficulty_level'] ?? 'medium') . "]: " . ($concept['correctness_rate'] ?? 0) . "% correct. Text: \"" . ($concept['question_text'] ?? '') . "\"\n";
            }
        }

        return "You are an Expert Pedagogy Consultant and Data Scientist specializing in educational technology.\n" .
            "You are analyzing data from a specific session of \"ClassEngage\", a Moodle plugin that facilitates real-time classroom engagement.\n\n" .
            "**SESSION DATA:**\n" .
            $engagement . "\n" .
            $comprehension . "\n" .
            $activities . "\n" .
            $concepts . "\n" .
            "**OUTPUT FORMAT:**\n" .
            "Return ONLY a valid JSON object with the following structure:\n" .
            "{\n" .
            "  \"summary\": \"1-2 sentence executive summary of the session performance. Use simple, direct language.\",\n" .
            "  \"strengths\": [\"1-3 specific positives\"],\n" .
            "  \"areas_for_improvement\": [\"1-3 specific issues\"],\n" .
            "  \"actionable_advice\": [\"1-3 concrete teaching strategies\"]\n" .
            "}\n" .
            "Do not include markdown formatting or code blocks in the response.";
    }
}
