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
 * NLP Providers Integration Test Script
 *
 * Run standalone to test configured AI providers.
 * Usage: php tests/integration/test_nlp_providers.php
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../../classes/nlp/provider_manager.php');

echo "=== ClassEngage NLP Provider Test ===\n\n";

$providers = ['gemini', 'openai', 'anthropic', 'deepseek', 'kimi', 'kimicn'];
$manager = new \mod_classengage\nlp\provider_manager();

$testtext = 'Machine learning is a subset of artificial intelligence that focuses on patterns in data.';
$configured = 0;

foreach ($providers as $name) {
    $provider = $manager->get_provider($name);
    if (!$provider) {
        echo "- {$name}: not available\n";
        continue;
    }

    if (!$provider->is_configured()) {
        echo "- {$name}: not configured (missing API key)\n";
        continue;
    }

    $configured++;
    echo "- {$name}: testing...\n";

    try {
        $result = $provider->generate_questions($testtext, [
            'numQuestions' => 1,
            'difficulty' => 'medium',
            'bloomLevel' => 'apply',
        ]);

        $model = $provider->get_current_model();
        $count = count($result['questions'] ?? []);
        echo "  ✓ Success" . (!empty($model) ? " (model: {$model})" : '') . " - questions: {$count}\n";
    } catch (Exception $e) {
        echo "  ✗ Failed: {$e->getMessage()}\n";
    }
}

if ($configured === 0) {
    echo "\nNo providers are configured. Add API keys in plugin settings.\n";
}
