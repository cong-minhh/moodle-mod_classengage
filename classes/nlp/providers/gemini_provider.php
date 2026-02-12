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
 * Gemini provider.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\providers;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../provider_base.php');

use mod_classengage\nlp\provider_base;

class gemini_provider extends provider_base {
    protected $name = 'gemini';
    protected $description = 'Google Gemini AI Provider';
    protected $supportsimages = true;
    protected $supportedmodels = [
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
        'gemini-pro',
    ];

    public function is_configured(): bool {
        return !empty($this->config['apiKey']);
    }

    public function generate_questions($input, array $options = []): array {
        $numquestions = (int) ($options['numQuestions'] ?? 10);
        $text = is_array($input) ? (string) ($input['text'] ?? '') : (string) $input;
        $images = is_array($input) ? ($input['images'] ?? []) : [];

        $promptoptions = [
            'numQuestions' => $numquestions,
            'bloomLevel' => $options['bloomLevel'] ?? 'apply',
            'difficulty' => $options['difficulty'] ?? 'mixed',
            'imageMetadata' => $options['imageMetadata'] ?? [],
            'distributionPlan' => $options['distributionPlan'] ?? null,
        ];

        [$prompt, $imageonly] = $this->build_prompt($text, $promptoptions);

        $models = $this->get_model_candidates();
        $lasterror = null;

        foreach ($models as $model) {
            $this->currentmodel = $model;
            try {
                $parts = [
                    ['text' => $prompt],
                ];

                if ($this->supportsimages && !empty($images)) {
                    foreach ($images as $img) {
                        $parts[] = [
                            'inline_data' => [
                                'mime_type' => $img['mediaType'] ?? 'image/png',
                                'data' => $img['data'] ?? '',
                            ],
                        ];
                    }
                }

                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 2048,
                    ],
                ];

                $modelpath = $this->format_model_path($model);
                $url = 'https://generativelanguage.googleapis.com/v1beta/' . $modelpath .
                    ':generateContent?key=' . rawurlencode($this->config['apiKey']);

                $response = $this->post_json($url, $payload, ['Content-Type: application/json']);
                $textresponse = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (!$textresponse) {
                    throw new \Exception('Gemini response missing content');
                }

                $parsed = $this->parse_response_text($textresponse);
                $standard = $this->standardize_response($parsed, $numquestions, $imageonly);
                $standard['metadata']['model'] = $model;
                return $standard;
            } catch (\Exception $e) {
                $lasterror = $e;
                continue;
            }
        }

        throw new \Exception('Gemini generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
    }

    public function generate_response(string $prompt, array $images = []): string {
        $models = $this->get_model_candidates();
        $lasterror = null;

        foreach ($models as $model) {
            $this->currentmodel = $model;
            try {
                $parts = [
                    ['text' => $prompt],
                ];

                if ($this->supportsimages && !empty($images)) {
                    foreach ($images as $img) {
                        $parts[] = [
                            'inline_data' => [
                                'mime_type' => $img['mediaType'] ?? 'image/png',
                                'data' => $img['data'] ?? '',
                            ],
                        ];
                    }
                }

                $payload = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 2048,
                    ],
                ];

                $modelpath = $this->format_model_path($model);
                $url = 'https://generativelanguage.googleapis.com/v1beta/' . $modelpath .
                    ':generateContent?key=' . rawurlencode($this->config['apiKey']);

                $response = $this->post_json($url, $payload, ['Content-Type: application/json']);
                $textresponse = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (!$textresponse) {
                    throw new \Exception('Gemini response missing content');
                }
                return (string) $textresponse;
            } catch (\Exception $e) {
                $lasterror = $e;
                continue;
            }
        }

        throw new \Exception('Gemini generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
    }

    private function get_model_candidates(): array {
        $model = $this->config['model'] ?? null;
        if ($model && in_array($model, $this->supportedmodels, true)) {
            $models = [$model];
            foreach ($this->supportedmodels as $candidate) {
                if ($candidate !== $model) {
                    $models[] = $candidate;
                }
            }
            return $models;
        }

        return $this->supportedmodels;
    }

    private function format_model_path(string $model): string {
        $model = trim($model);
        $model = preg_replace('#^models/#', '', $model);
        return 'models/' . $model;
    }
}
