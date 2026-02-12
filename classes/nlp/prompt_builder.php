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
 * Prompt builder for question generation.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

class prompt_builder {
    /** @var bool */
    private $imageonly = false;

    /**
     * Build prompt string.
     *
     * @param string $text
     * @param array $options
     * @return string
     */
    public function build(string $text, array $options = []): string {
        $numquestions = (int) ($options['numQuestions'] ?? 10);
        $bloomlevel = (string) ($options['bloomLevel'] ?? 'apply');
        $difficulty = (string) ($options['difficulty'] ?? 'mixed');
        $distributionplan = $options['distributionPlan'] ?? null;
        $imagemetadata = $options['imageMetadata'] ?? [];

        $sourceText = $text;
        $hasimages = is_array($imagemetadata) && !empty($imagemetadata);
        $textlength = strlen(trim($sourceText));
        $this->imageonly = $hasimages && $textlength < 50;

        $imageinventory = '';
        if ($hasimages) {
            $lines = [];
            $idx = 1;
            foreach ($imagemetadata as $img) {
                $label = $img['label'] ?? ('Page ' . ($img['page'] ?? ''));
                $lines[] = '   - Image ' . $idx . ' (imageId: "' . ($img['imageId'] ?? '') . '") - from ' . $label;
                $idx++;
            }

            if ($this->imageonly) {
                $imageinventory = "\n<available_images>\n" .
                    'IMPORTANT: This is an IMAGE-BASED question generation request.' . "\n" .
                    'The following ' . count($imagemetadata) . ' image(s) are the PRIMARY source material - you MUST analyze them carefully.' . "\n\n" .
                    implode("\n", $lines) . "\n\n" .
                    "You MUST:\n" .
                    "1. Carefully examine EACH attached image to understand its content\n" .
                    "2. Generate questions based on what you SEE in the image(s)\n" .
                    "3. Include the imageId in the \"question_image\" field for EVERY question\n" .
                    "4. Reference the image content naturally in your questions\n" .
                    "</available_images>";
            } else {
                $imageinventory = "\n<available_images>\n" .
                    'The following ' . count($imagemetadata) . ' image(s) are attached and visible to you.' . "\n" .
                    'When you create a question that references one of these images, include its imageId in the "question_image" field.' . "\n\n" .
                    implode("\n", $lines) . "\n\n" .
                    'IMPORTANT: Match figures/tables in the text to these images based on what you see in them.' . "\n" .
                    "</available_images>";
            }
        }

        $distribution = '';
        $bloomdefinitions = '';
        if (is_array($distributionplan) && !empty($distributionplan['breakdown'])) {
            $lines = [];
            foreach ($distributionplan['breakdown'] as $item) {
                $lines[] = '   - ' . (int) $item['count'] . ' questions: Difficulty [' . strtoupper($item['difficulty']) . '], Bloom Level [' . strtoupper($item['bloomLevel']) . ']';
            }
            $distribution = "\n<distribution_requirements>\n" .
                "You must STRICTLY follow this question breakdown:\n" .
                implode("\n", $lines) . "\n" .
                'Total Questions: ' . $numquestions . "\n" .
                "</distribution_requirements>";

            $unique = [];
            foreach ($distributionplan['breakdown'] as $item) {
                $key = strtolower($item['bloomLevel']);
                if (!isset($unique[$key])) {
                    $unique[$key] = true;
                    $bloomdefinitions .= self::get_bloom_definition($key);
                }
            }
        } else {
            $distribution = "\n<distribution_requirements>\n" .
                '- Total Questions: ' . $numquestions . "\n" .
                '- Difficulty: ' . strtoupper($difficulty) . "\n" .
                "- Bloom's Level: " . strtoupper($bloomlevel) . "\n" .
                "</distribution_requirements>";
            $bloomdefinitions = self::get_bloom_definition($bloomlevel);
        }

        $inputcontext = "\n<input_context>\n";
        if ($this->imageonly) {
            $inputcontext .= "There is NO text content provided. You must generate questions by analyzing the attached image(s) below.\n";
        } else {
            $inputcontext .= "The following text is the source material for the exam:\n\"\"\"\n" . $sourceText . "\n\"\"\"\n";
        }
        $inputcontext .= $imageinventory . "\n</input_context>";

        $execution = "\n<execution_step>\n";
        if ($this->imageonly) {
            $execution .= 'Carefully analyze the attached image(s). Identify key concepts, data, diagrams, or information visible in them. Generate questions that test understanding of this visual content.';
        } else {
            $execution .= 'Analyze the text, plan the questions according to the <distribution_requirements>, and generate the JSON response.';
        }
        $execution .= "\n</execution_step>";

        return "\n<system_role>\n" .
            "You are an Expert University Lecturer and Assessment Specialist.\n" .
            "Your goal is to create a high-quality, academically rigorous exam for international students.\n" .
            "</system_role>" .
            $inputcontext .
            "\n\n<task_configuration>\n" .
            $distribution .
            "\n</task_configuration>\n\n" .
            "<pedagogical_guidelines>\n" .
            "<blooms_taxonomy_definitions>\n" .
            $bloomdefinitions .
            "</blooms_taxonomy_definitions>\n\n" .
            "<design_rules>\n" .
            "1. CLARITY: Use professional, standard English. Accessible to non-native speakers (CEFR B2+).\n" .
            "2. RIGOR: Questions must test concepts, not just vocabulary.\n" .
            "3. DISTRACTORS: Must be plausible, roughly same length, and clearly incorrect.\n" .
            "4. RATIONALE: Provide clear explanation for correct answer. Keep concise.\n" .
            "5. IMAGE REFERENCE: If a question is about a specific figure/table/image:\n" .
            "   - In questiontext: refer to it naturally (e.g., 'According to Figure 3...')\n" .
            "   - In question_image: put the imageId from <available_images>\n" .
            "   - NEVER put the imageId in the question text itself\n" .
            "</design_rules>\n" .
            "</pedagogical_guidelines>\n\n" .
            "<output_format>\n" .
            "IMPORTANT: You must output ONLY a valid JSON object.\n" .
            "Required JSON Structure:\n" .
            "{\n" .
            "  \"questions\": [\n" .
            "    {\n" .
            "      \"questiontext\": \"Question text here?\",\n" .
            "      \"optiona\": \"Option A text\",\n" .
            "      \"optionb\": \"Option B text\",\n" .
            "      \"optionc\": \"Option C text\",\n" .
            "      \"optiond\": \"Option D text\",\n" .
            "      \"correctanswer\": \"A\",\n" .
            "      \"difficulty\": \"medium\",\n" .
            "      \"cognitive_level\": \"apply\",\n" .
            "      \"rationale\": \"The correct answer is A because...\",\n" .
            "      \"question_image\": " . ($this->imageonly ? '"img_ID_here"' : 'null') . "\n" .
            "    }\n" .
            "  ]\n" .
            "}\n\n" .
            "CRITICAL RULES:\n" .
            "1. correctanswer MUST be exactly one letter: A, B, C, or D.\n" .
            "2. Do NOT write the full answer text in correctanswer.\n" .
            "3. Ensure all JSON syntax is correct.\n" .
            "</output_format>" .
            $execution;
    }

    /**
     * Returns whether prompt is in image-only mode.
     *
     * @return bool
     */
    public function is_image_only_mode(): bool {
        return $this->imageonly;
    }

    /**
     * Get Bloom taxonomy definition.
     *
     * @param string $level
     * @return string
     */
    public static function get_bloom_definition(string $level): string {
        $definitions = [
            'remember' => 'REMEMBER: Recall facts and basic concepts.',
            'understand' => 'UNDERSTAND: Explain ideas or concepts.',
            'apply' => 'APPLY: Use information in new situations.',
            'analyze' => 'ANALYZE: Draw connections among ideas.',
            'evaluate' => 'EVALUATE: Justify a stand or decision.',
            'create' => 'CREATE: Produce new or original work.',
        ];
        $key = strtolower($level);
        $definition = $definitions[$key] ?? $definitions['apply'];
        return '    - ' . $definition . "\n";
    }
}
