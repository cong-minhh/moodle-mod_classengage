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
 * Base provider class for AI integrations.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

abstract class provider_base {
    /** @var array */
    protected $config = [];

    /** @var string */
    protected $name = 'base';

    /** @var string */
    protected $description = 'Base AI Provider';

    /** @var array */
    protected $supportedmodels = [];

    /** @var bool */
    protected $supportsimages = false;

    /** @var int */
    protected $maxretries = 3;

    /** @var int */
    protected $basedelayms = 2000;

    /** @var string|null */
    protected $currentmodel = null;

    public function __construct(array $config = []) {
        $this->config = $config;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_description(): string {
        return $this->description;
    }

    public function get_supported_models(): array {
        return $this->supportedmodels;
    }

    public function supports_images(): bool {
        return $this->supportsimages;
    }

    public function get_current_model(): ?string {
        return $this->currentmodel;
    }

    public function configure(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if provider is configured.
     *
     * @return bool
     */
    abstract public function is_configured(): bool;

    /**
     * Generate questions from text or multimodal input.
     *
     * @param string|array $input
     * @param array $options
     * @return array
     */
    abstract public function generate_questions($input, array $options = []): array;

    /**
     * Generate a raw response from a prompt.
     *
     * @param string $prompt
     * @param array $images
     * @return string
     */
    abstract public function generate_response(string $prompt, array $images = []): string;

    /**
     * Build prompt for the provider.
     *
     * @param string $text
     * @param array $options
     * @return array [prompt, image_only]
     */
    protected function build_prompt(string $text, array $options = []): array {
        $builder = new prompt_builder();
        $prompt = $builder->build($text, $options);
        return [$prompt, $builder->is_image_only_mode()];
    }

    /**
     * Parse JSON safely from LLM output.
     *
     * @param string $raw
     * @param string $mode strict|repair
     * @return array
     */
    protected function safe_json_parse(string $raw, string $mode = 'strict'): array {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $cleaned);
        $cleaned = preg_replace('/<think>[\s\S]*/i', '', $cleaned);
        $cleaned = str_replace(['```json', '```javascript', '```'], '', $cleaned);

        $first = strpos($cleaned, '{');
        $last = strrpos($cleaned, '}');
        if ($first === false || $last === false || $first >= $last) {
            throw new \Exception('Invalid JSON structure from provider');
        }

        $jsonstring = substr($cleaned, $first, $last - $first + 1);

        if ($mode === 'repair') {
            if (strpos($jsonstring, '"questions"') !== false && !preg_match('/\]\s*\}/', $jsonstring)) {
                $jsonstring .= "\n  ]\n}";
            }
            $jsonstring = preg_replace('/}\s*{/', '},\n{', $jsonstring);
            $jsonstring = preg_replace('/"\s*\n\s*"/', "\",\n\"", $jsonstring);
            $jsonstring = preg_replace('/,(\s*[\}\]])/', '$1', $jsonstring);
        }

        $result = '';
        $instring = false;
        $escape = false;
        $len = strlen($jsonstring);
        for ($i = 0; $i < $len; $i++) {
            $ch = $jsonstring[$i];
            $code = ord($ch);
            if ($escape) {
                $result .= $ch;
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $result .= $ch;
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $instring = !$instring;
                $result .= $ch;
                continue;
            }
            if ($instring) {
                if ($code < 0x20) {
                    $map = [
                        "\n" => "\\n",
                        "\r" => "\\r",
                        "\t" => "\\t",
                        "\b" => "\\b",
                        "\f" => "\\f",
                    ];
                    $result .= $map[$ch] ?? '';
                } else {
                    $result .= $ch;
                }
            } else {
                if ($code >= 0x20 || $ch === "\n" || $ch === "\r" || $ch === "\t" || $ch === ' ') {
                    $result .= $ch;
                }
            }
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON returned by provider');
        }
        return $decoded;
    }

    /**
     * Parse response with strict mode, fallback to repair.
     *
     * @param string $raw
     * @return array
     */
    protected function parse_response_text(string $raw): array {
        try {
            return $this->safe_json_parse($raw, 'strict');
        } catch (\Exception $e) {
            return $this->safe_json_parse($raw, 'repair');
        }
    }

    /**
     * Normalize and validate questions.
     *
     * @param array $parsed
     * @param int $expected
     * @param bool $imageonly
     * @return array
     */
    protected function standardize_response(array $parsed, int $expected, bool $imageonly): array {
        if (isset($parsed[0]) && !isset($parsed['questions'])) {
            $parsed = ['questions' => $parsed];
        }
        if (!isset($parsed['questions']) && isset($parsed['questiontext'])) {
            $parsed = ['questions' => [$parsed]];
        }
        $questions = $parsed['questions'] ?? [];
        $questions = question_validator::normalize_and_validate($questions, $imageonly);

        return [
            'questions' => $questions,
            'provider' => $this->name,
            'analysis' => $parsed['analysis'] ?? null,
            'metadata' => [
                'generated_at' => gmdate('c'),
                'numQuestions' => count($questions),
                'expected_questions' => $expected,
                'source' => $this->name,
                'model' => $this->currentmodel,
            ],
        ];
    }

    /**
     * Make HTTP POST request with JSON.
     *
     * @param string $url
     * @param array $payload
     * @param array $headers
     * @param int $timeout
     * @return array
     */
    protected function post_json(string $url, array $payload, array $headers = [], int $timeout = 60): array {
        $curl = new \curl();
        $opts = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_HTTPHEADER' => $headers,
        ];
        $response = $curl->post($url, json_encode($payload), $opts);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($curl->get_errno()) {
            throw new \Exception('Provider connection failed: ' . $curl->error);
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            $snippet = '';
            if (is_string($response) && $response !== '') {
                $snippet = substr($response, 0, 500);
            }
            $message = 'Provider error (HTTP ' . $httpcode . ')';
            if ($snippet !== '') {
                $message .= ': ' . $snippet;
            }
            throw new \Exception($message);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \Exception('Provider returned invalid JSON');
        }
        return $decoded;
    }

    /**
     * Sleep with exponential backoff.
     *
     * @param int $attempt
     * @return void
     */
    protected function backoff_sleep(int $attempt): void {
        $delay = $this->basedelayms * (2 ** max(0, $attempt - 1));
        usleep((int) ($delay * 1000));
    }
}
