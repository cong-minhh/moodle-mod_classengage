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
 * Kimi CN provider.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\providers;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../provider_base.php');

use mod_classengage\nlp\provider_base;

class kimicn_provider extends provider_base {
    protected $name = 'kimicn';
    protected $description = 'Kimi AI China (Moonshot CN) Provider';
    protected $supportsimages = false;
    protected $supportedmodels = [
        'moonshot-v1-8k',
        'moonshot-v1-32k',
        'moonshot-v1-128k',
    ];

    public function is_configured(): bool {
        return !empty($this->config['apiKey']);
    }

    public function generate_questions($input, array $options = []): array {
        $numquestions = (int) ($options['numQuestions'] ?? 10);
        $text = is_array($input) ? (string) ($input['text'] ?? '') : (string) $input;

        $promptoptions = [
            'numQuestions' => $numquestions,
            'bloomLevel' => $options['bloomLevel'] ?? 'apply',
            'difficulty' => $options['difficulty'] ?? 'mixed',
            'distributionPlan' => $options['distributionPlan'] ?? null,
        ];

        [$prompt, $imageonly] = $this->build_prompt($text, $promptoptions);
        $models = $this->get_model_candidates();
        $lasterror = null;

        foreach ($models as $model) {
            $this->currentmodel = $model;
            try {
                $payload = [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an expert educator creating multiple choice quiz questions. Respond with only valid JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.7,
                ];

                $url = rtrim($this->config['baseURL'] ?? 'https://api.moonshot.cn/v1', '/');
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
                    throw new \Exception('Kimi CN response missing content');
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

        throw new \Exception('Kimi CN generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
    }

    public function generate_response(string $prompt, array $images = []): string {
        $models = $this->get_model_candidates();
        $lasterror = null;

        foreach ($models as $model) {
            $this->currentmodel = $model;
            try {
                $payload = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                ];

                $url = rtrim($this->config['baseURL'] ?? 'https://api.moonshot.cn/v1', '/');
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
                    throw new \Exception('Kimi CN response missing content');
                }
                return (string) $textresponse;
            } catch (\Exception $e) {
                $lasterror = $e;
                continue;
            }
        }

        throw new \Exception('Kimi CN generation failed: ' . ($lasterror ? $lasterror->getMessage() : 'unknown error'));
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
