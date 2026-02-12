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
 * Question validation and normalization for AI responses.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

class question_validator {
    /**
     * Normalize correct answer from common LLM formats.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function normalize_correct_answer($value): ?string {
        if ($value === null) {
            return null;
        }

        $str = strtoupper(trim((string) $value));
        if ($str === '') {
            return null;
        }

        if (in_array($str, ['A', 'B', 'C', 'D'], true)) {
            return $str;
        }

        if (preg_match('/^[\(\[]?([ABCD])[\)\]\.]?$/', $str, $matches)) {
            return $matches[1];
        }

        $nummap = [
            '1' => 'A',
            '2' => 'B',
            '3' => 'C',
            '4' => 'D',
        ];
        if (isset($nummap[$str])) {
            return $nummap[$str];
        }

        $clean = preg_replace('/[\"\']/', '', $str);
        if (preg_match('/\b(?:OPTION|ANSWER|IS)\s*[:\-]?\s*[\(\[]?([ABCD])[\)\]]?/', $clean, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^([ABCD])(?:\W|$)/', $clean, $matches)) {
            return $matches[1];
        }

        return $str;
    }

    /**
     * Normalize and validate questions array.
     *
     * @param array $questions
     * @param bool $imageonly
     * @return array Normalized questions
     */
    public static function normalize_and_validate(array $questions, bool $imageonly = false): array {
        if (empty($questions)) {
            throw new \Exception('No questions returned by AI provider');
        }

        $normalized = [];
        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                throw new \Exception('Invalid question format at index ' . $index);
            }

            $questiontext = trim((string) ($question['questiontext'] ?? ''));
            $optiona = trim((string) ($question['optiona'] ?? ''));
            $optionb = trim((string) ($question['optionb'] ?? ''));
            $optionc = trim((string) ($question['optionc'] ?? ''));
            $optiond = trim((string) ($question['optiond'] ?? ''));

            if ($questiontext === '' || $optiona === '' || $optionb === '' || $optionc === '' || $optiond === '') {
                throw new \Exception('Missing question fields at index ' . $index);
            }

            $correct = self::normalize_correct_answer($question['correctanswer'] ?? null);
            if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                // Attempt exact option match if correctanswer contains full text.
                $match = answer_matcher::match_exact_only((string) ($question['correctanswer'] ?? ''), [
                    'A' => $optiona,
                    'B' => $optionb,
                    'C' => $optionc,
                    'D' => $optiond,
                ]);
                if ($match['key'] && answer_matcher::is_safe_to_correct($match['confidence'])) {
                    $correct = $match['key'];
                }
            }

            if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                throw new \Exception('Invalid correct answer at index ' . $index);
            }

            $difficulty = strtolower(trim((string) ($question['difficulty'] ?? 'medium')));
            if (!in_array($difficulty, ['easy', 'medium', 'hard', 'mixed'], true)) {
                $difficulty = 'medium';
            }

            $questionimage = $question['question_image'] ?? null;
            if ($imageonly && (empty($questionimage) || !is_string($questionimage))) {
                throw new \Exception('question_image is required in image-only mode (index ' . $index . ')');
            }

            $normalized[] = [
                'questiontext' => $questiontext,
                'optiona' => $optiona,
                'optionb' => $optionb,
                'optionc' => $optionc,
                'optiond' => $optiond,
                'correctanswer' => $correct,
                'difficulty' => $difficulty,
                'cognitive_level' => $question['cognitive_level'] ?? null,
                'bloomLevel' => $question['bloomLevel'] ?? ($question['bloomlevel'] ?? ($question['bloom_level'] ?? null)),
                'rationale' => $question['rationale'] ?? null,
                'question_image' => $questionimage,
            ];
        }

        return $normalized;
    }
}
