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
 * Provider manager to handle multiple AI providers and routing.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/providers/gemini_provider.php');
require_once(__DIR__ . '/providers/openai_provider.php');
require_once(__DIR__ . '/providers/anthropic_provider.php');
require_once(__DIR__ . '/providers/deepseek_provider.php');
require_once(__DIR__ . '/providers/kimi_provider.php');
require_once(__DIR__ . '/providers/kimicn_provider.php');

class provider_manager {
    /** @var provider_base[] */
    private $providers = [];

    /** @var string[] */
    private $priority = [];

    /** @var string|null */
    private $defaultprovider = null;

    public function __construct() {
        $this->load_config();
        $this->register_providers();
    }

    private function load_config(): void {
        $priority = \get_config('mod_classengage', 'nlp_provider_priority');
        if (empty($priority)) {
            $priority = 'gemini,openai,anthropic,deepseek,kimi,kimicn';
        }
        $this->priority = array_values(array_filter(array_map('trim', explode(',', $priority))));

        $this->defaultprovider = \get_config('mod_classengage', 'nlp_default_provider') ?: null;
    }

    private function register_providers(): void {
        $this->providers = [
            'gemini' => new providers\gemini_provider($this->get_provider_config('gemini')),
            'openai' => new providers\openai_provider($this->get_provider_config('openai')),
            'anthropic' => new providers\anthropic_provider($this->get_provider_config('anthropic')),
            'deepseek' => new providers\deepseek_provider($this->get_provider_config('deepseek')),
            'kimi' => new providers\kimi_provider($this->get_provider_config('kimi')),
            'kimicn' => new providers\kimicn_provider($this->get_provider_config('kimicn')),
        ];
    }

    /**
     * Get provider instance by name.
     *
     * @param string $name
     * @return provider_base|null
     */
    public function get_provider(string $name): ?provider_base {
        return $this->providers[$name] ?? null;
    }

    /**
     * Pick provider based on configuration and needs.
     *
     * @param bool $needsimages
     * @return provider_base
     */
    public function select_provider(bool $needsimages = false): provider_base {
        $candidates = $this->priority;
        if (!empty($this->defaultprovider)) {
            array_unshift($candidates, $this->defaultprovider);
            $candidates = array_values(array_unique($candidates));
        }

        foreach ($candidates as $name) {
            $provider = $this->providers[$name] ?? null;
            if (!$provider) {
                continue;
            }
            if (!$provider->is_configured()) {
                continue;
            }
            if ($needsimages && !$provider->supports_images()) {
                continue;
            }
            return $provider;
        }

        if ($needsimages) {
            throw new \Exception('No configured AI provider supports image input');
        }

        throw new \Exception('No AI provider configured');
    }

    /**
     * Provider configuration from plugin settings.
     *
     * @param string $name
     * @return array
     */
    private function get_provider_config(string $name): array {
        $config = [];
        switch ($name) {
            case 'gemini':
                $config['apiKey'] = \get_config('mod_classengage', 'gemini_api_key');
                $config['model'] = \get_config('mod_classengage', 'gemini_model');
                break;
            case 'openai':
                $config['apiKey'] = \get_config('mod_classengage', 'openai_api_key');
                $config['model'] = \get_config('mod_classengage', 'openai_model');
                $config['baseURL'] = \get_config('mod_classengage', 'openai_base_url');
                break;
            case 'anthropic':
                $config['apiKey'] = \get_config('mod_classengage', 'anthropic_api_key');
                $config['model'] = \get_config('mod_classengage', 'anthropic_model');
                break;
            case 'deepseek':
                $config['apiKey'] = \get_config('mod_classengage', 'deepseek_api_key');
                $config['model'] = \get_config('mod_classengage', 'deepseek_model');
                $config['baseURL'] = \get_config('mod_classengage', 'deepseek_base_url');
                break;
            case 'kimi':
                $config['apiKey'] = \get_config('mod_classengage', 'kimi_api_key');
                $config['model'] = \get_config('mod_classengage', 'kimi_model');
                $config['baseURL'] = \get_config('mod_classengage', 'kimi_base_url');
                break;
            case 'kimicn':
                $config['apiKey'] = \get_config('mod_classengage', 'kimicn_api_key');
                $config['model'] = \get_config('mod_classengage', 'kimicn_model');
                $config['baseURL'] = \get_config('mod_classengage', 'kimicn_base_url');
                break;
        }

        return $config;
    }
}
