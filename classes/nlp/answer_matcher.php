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
 * Answer matcher for normalizing LLM correct answers.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

class answer_matcher {
    const CONFIDENCE_EXACT = 'exact';
    const CONFIDENCE_NONE = 'none';

    /**
     * Match answer text against options using exact normalized comparison.
     *
     * @param string $answertext
     * @param array $options ['A' => '...', 'B' => '...']
     * @return array ['key' => 'A', 'confidence' => 'exact|none']
     */
    public static function match_exact_only(string $answertext, array $options): array {
        $normalizedanswer = self::normalize_text($answertext);
        if ($normalizedanswer === '') {
            return ['key' => null, 'confidence' => self::CONFIDENCE_NONE];
        }

        foreach ($options as $key => $value) {
            $normalizedoption = self::normalize_text((string) $value);
            if ($normalizedoption !== '' && $normalizedoption === $normalizedanswer) {
                return ['key' => $key, 'confidence' => self::CONFIDENCE_EXACT];
            }
        }

        return ['key' => null, 'confidence' => self::CONFIDENCE_NONE];
    }

    /**
     * Whether the confidence level is safe for auto-correction.
     *
     * @param string $confidence
     * @return bool
     */
    public static function is_safe_to_correct(string $confidence): bool {
        return $confidence === self::CONFIDENCE_EXACT;
    }

    /**
     * Normalize text for comparison.
     *
     * @param string $text
     * @return string
     */
    private static function normalize_text(string $text): string {
        if (function_exists('mb_strtolower')) {
            $text = trim(mb_strtolower($text, 'UTF-8'));
        } else {
            $text = trim(strtolower($text));
        }
        $text = preg_replace('/[\s\p{P}\p{S}]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
