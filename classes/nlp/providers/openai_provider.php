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
 * OpenAI provider.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\providers;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../provider_base.php');

use mod_classengage\nlp\provider_base;

class openai_provider extends provider_base {
    protected $name = 'openai';
    protected $description = 'OpenAI GPT Provider';
    protected $supportsimages = true;
    protected $supportedmodels = [
        'gpt-4o',
        'gpt-4-turbo',
        'gpt-4',
        'gpt-3.5-turbo',
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
                $messages = [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert educator creating multiple choice quiz questions. Respond with only valid JSON.',
                    ],
                ];

                $usercontent = $prompt;
                if ($this->supportsimages && !empty($images)) {
                    $parts = [
                        ['type' => 'text', 'text' => $prompt],
                    ];
                    foreach ($images as $img) {
                        $mime = $img['mediaType'] ?? 'image/png';
                        $parts[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mime . ';base64,' . ($img['data'] ?? ''),
                            ],
                        ];
                    }
                    $usercontent = $parts;
                }

                $messages[] = [
                    'role' => 'user',
                    'content' => $usercontent,
                ];

                $payload = [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

                $url = rtrim($this->config['baseURL'] ?? 'https://api.openai.com/v1', '/');
                $response = $this->post_json(
                    $url . '/chat/completions',
                    $payload,
                    [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $this->config['apiKey'],
                    ]
                );

                $textresponse = $response['choices'][0]['message']['content'] ?? null;
                if (!$textresponse) {
                    throw new \Exception('OpenAI response missing content');
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

        throw new \Exception('OpenAI generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
    }

    public function generate_response(string $prompt, array $images = []): string {
        $models = $this->get_model_candidates();
        $lasterror = null;

        foreach ($models as $model) {
            $this->currentmodel = $model;
            try {
                $usercontent = $prompt;
                if ($this->supportsimages && !empty($images)) {
                    $parts = [
                        ['type' => 'text', 'text' => $prompt],
                    ];
                    foreach ($images as $img) {
                        $mime = $img['mediaType'] ?? 'image/png';
                        $parts[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mime . ';base64,' . ($img['data'] ?? ''),
                            ],
                        ];
                    }
                    $usercontent = $parts;
                }

                $payload = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $usercontent],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 1500,
                ];

                $url = rtrim($this->config['baseURL'] ?? 'https://api.openai.com/v1', '/');
                $response = $this->post_json(
                    $url . '/chat/completions',
                    $payload,
                    [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $this->config['apiKey'],
                    ]
                );

                $textresponse = $response['choices'][0]['message']['content'] ?? null;
                if (!$textresponse) {
                    throw new \Exception('OpenAI response missing content');
                }
                return (string) $textresponse;
            } catch (\Exception $e) {
                $lasterror = $e;
                continue;
            }
        }

        throw new \Exception('OpenAI generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
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
}
