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
 * NLP Environment Diagnostics
 *
 * This page helps administrators check if the required tools
 * for PDF/PPTX/DOCX processing are available in their environment.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Check admin access.
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/mod/classengage/nlp_diagnostics.php');
$PAGE->set_title(get_string('nlpdiagnostics', 'mod_classengage'));
$PAGE->set_heading(get_string('nlpdiagnostics', 'mod_classengage'));
$PAGE->set_pagelayout('admin');

require_once(__DIR__ . '/classes/nlp_generator.php');
$generator = new \mod_classengage\nlp_generator();
$pdftools = $generator->check_pdf_tools();
$pdfmode = $generator->get_pdf_text_extraction_mode();
$activepdfbackend = $generator->get_active_pdf_text_backend();
$executionmode = (string)(get_config('mod_classengage', 'nlpexecutionmode') ?: 'auto');

$pdfbackendready = false;
if ($pdfmode === 'external') {
    $pdfbackendready = !empty($pdftools['shell_exec']) && !empty($pdftools['pdftotext']);
} else if ($pdfmode === 'bundled') {
    $pdfbackendready = !empty($pdftools['pdfparser']);
} else {
    $pdfbackendready = (
        (!empty($pdftools['shell_exec']) && !empty($pdftools['pdftotext'])) ||
        !empty($pdftools['pdfparser'])
    );
}

// Run diagnostics.
$diagnostics = [];

// Check PDF backend.
$diagnostics['execution_mode'] = [
    'name' => 'User-Triggered Execution Mode',
    'required' => true,
    'available' => true,
    'message' => 'Mode: ' . $executionmode,
];

$diagnostics['pdf_backend'] = [
    'name' => 'PDF Text Backend',
    'required' => true,
    'available' => $pdfbackendready,
    'message' => 'Mode: ' . $pdfmode . ' | Active backend: ' . $activepdfbackend,
];

// Check PHP functions.
$diagnostics['php_shell_exec'] = [
    'name' => 'PHP shell_exec()',
    'required' => ($pdfmode === 'external'),
    'available' => !empty($pdftools['shell_exec']),
    'message' => !empty($pdftools['shell_exec'])
        ? 'Available'
        : 'Disabled in this runtime',
];

// Check external tools.
$diagnostics['tool_pdftotext'] = [
    'name' => 'pdftotext (Poppler)',
    'required' => ($pdfmode === 'external'),
    'available' => !empty($pdftools['pdftotext']),
    'message' => !empty($pdftools['pdftotext']) ? 'Installed' : 'Not installed in this runtime',
    'docker_fix' => 'RUN apt-get update && apt-get install -y poppler-utils',
];

$diagnostics['tool_pdfinfo'] = [
    'name' => 'pdfinfo (Poppler)',
    'required' => false,
    'available' => !empty($pdftools['pdfinfo']),
    'message' => !empty($pdftools['pdfinfo']) ? 'Installed' : 'Not installed (optional)',
    'docker_fix' => 'RUN apt-get update && apt-get install -y poppler-utils',
];

$diagnostics['tool_pdfparser'] = [
    'name' => 'Bundled PDF Parser',
    'required' => ($pdfmode === 'bundled'),
    'available' => !empty($pdftools['pdfparser']),
    'message' => !empty($pdftools['pdfparser'])
        ? 'Available inside plugin package'
        : 'Not available in this plugin package',
];

// Check PHP extensions.
$diagnostics['ext_imagick'] = [
    'name' => 'Imagick PHP Extension',
    'required' => false,
    'available' => !empty($pdftools['imagick']),
    'message' => !empty($pdftools['imagick']) ? 'Installed' : 'Not installed (optional for previews)',
    'docker_fix' => 'RUN apt-get update && apt-get install -y libmagickwand-dev && pecl install imagick && docker-php-ext-enable imagick',
];

$diagnostics['ext_zip'] = [
    'name' => 'ZIP PHP Extension',
    'required' => true,
    'available' => class_exists('\ZipArchive'),
    'message' => class_exists('\ZipArchive') ? 'Installed' : 'NOT INSTALLED',
];

// Check generation providers.
$diagnostics['builtin_generator'] = [
    'name' => 'Built-in Question Generator',
    'required' => true,
    'available' => true,
    'message' => 'Available inside the plugin',
];

// Check if any external provider is configured.
$providers = [
    'gemini' => 'geminiapikey',
    'openai' => 'openaiapikey',
    'anthropic' => 'anthropicapikey',
    'deepseek' => 'deepseekapikey',
    'kimi' => 'kimiapikey',
    'kimicn' => 'kimicnapikey',
    'local' => 'localendpoint',
];

$configuredProviders = [];
foreach ($providers as $name => $configkey) {
    $value = get_config('mod_classengage', $configkey);
    if (!empty($value)) {
        $configuredProviders[] = $name;
    }
}

$diagnostics['external_ai_providers'] = [
    'name' => 'External AI Providers',
    'required' => false,
    'available' => !empty($configuredProviders),
    'message' => !empty($configuredProviders)
    ? 'Configured: ' . implode(', ', $configuredProviders)
    : 'None configured - built-in generator will be used',
];

// Overall status.
$allRequired = true;
$missingRequired = [];
foreach ($diagnostics as $key => $check) {
    if ($check['required'] && !$check['available']) {
        $allRequired = false;
        $missingRequired[] = $check['name'];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('nlpdiagnostics', 'mod_classengage'));

echo $OUTPUT->box_start();
echo html_writer::tag('h3', 'Runtime scope');
echo html_writer::tag(
    'p',
    'This page checks the PHP runtime serving this request. If Moodle cron or the optional ClassEngage worker runs in a different CLI process, host, or container, validate that runtime separately too.'
);
echo html_writer::tag(
    'pre',
    'php /path/to/moodle/mod/classengage/cli/runtime_check.php'
);
echo html_writer::tag(
    'p',
    'Configured PDF mode: <strong>' . s($pdfmode) . '</strong>. ' .
    'Preferred backend in this runtime: <strong>' . s($activepdfbackend) . '</strong>.'
);
echo html_writer::tag(
    'p',
    'Configured user-triggered execution mode: <strong>' . s($executionmode) . '</strong>.'
);
echo $OUTPUT->box_end();

// Status banner.
if ($allRequired) {
    echo $OUTPUT->notification(get_string('diagnostics_allgood', 'mod_classengage'), 'success');
} else {
    echo $OUTPUT->notification(
        get_string('diagnostics_missing', 'mod_classengage', implode(', ', $missingRequired)) .
        ' Install the missing components in the runtime that executes background tasks.',
        'error'
    );
}

// Runtime guidance.
echo $OUTPUT->box_start();
echo html_writer::tag('h3', 'Installation guidance');
echo html_writer::tag(
    'p',
    'ClassEngage works on both standard Moodle and Dockerized Moodle. The required tools must exist in the runtime that executes Moodle cron or task_worker.php.'
);

$standardLines = [];
if ($pdfmode === 'external' && empty($diagnostics['tool_pdftotext']['available'])) {
    $standardLines[] = 'Debian/Ubuntu example: sudo apt-get install -y poppler-utils';
}
if ($pdfmode === 'auto' && empty($diagnostics['tool_pdftotext']['available']) && empty($diagnostics['tool_pdfparser']['available'])) {
    $standardLines[] = 'Install poppler-utils or package the plugin with its bundled PDF parser files.';
}
if ($pdfmode === 'bundled' && empty($diagnostics['tool_pdfparser']['available'])) {
    $standardLines[] = 'Bundled mode requires the plugin package to include its vendor PDF parser files.';
}
if (empty($diagnostics['ext_zip']['available'])) {
    $standardLines[] = 'Install the PHP ZIP extension for the PHP runtime used by Moodle.';
}
if (empty($diagnostics['ext_imagick']['available'])) {
    $standardLines[] = 'Optional preview support: install ImageMagick and the PHP Imagick extension in the task runner.';
}
if ($pdfmode === 'external' && empty($diagnostics['php_shell_exec']['available'])) {
    $standardLines[] = 'Enable shell_exec() for the runtime that executes background tasks.';
}

if (!empty($standardLines)) {
    echo html_writer::tag('p', 'Standard server examples:');
    echo html_writer::tag('pre', implode("\n", array_unique($standardLines)));
}

$dockerLines = [];
foreach ($diagnostics as $key => $check) {
    if (!$check['available'] && !empty($check['docker_fix'])) {
        $dockerLines[] = $check['docker_fix'];
    }
}

if (!empty($dockerLines)) {
    echo html_writer::tag('p', 'Docker image examples:');
    echo html_writer::tag('pre', implode("\n", array_unique($dockerLines)));
}

echo $OUTPUT->box_end();

// Diagnostics table.
echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::start_tag('thead');
echo html_writer::tag('tr',
    html_writer::tag('th', 'Component') .
    html_writer::tag('th', 'Status') .
    html_writer::tag('th', 'Required') .
    html_writer::tag('th', 'Notes')
);
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($diagnostics as $key => $check) {
    $statusClass = $check['available'] ? 'text-success' : ($check['required'] ? 'text-danger' : 'text-warning');
    $statusIcon = $check['available'] ? '✓' : ($check['required'] ? '✗' : '⚠');
    $notes = '';

    if ($key === 'execution_mode') {
        $notes = 'Controls whether instructor-triggered requests run inline, in the background, or choose automatically.';
    } else if ($key === 'pdf_backend') {
        $notes = 'This is the effective PDF extraction requirement for the current mode.';
    } else if ($key === 'php_shell_exec') {
        $notes = $pdfmode === 'external'
            ? 'Required because PDF mode is set to external.'
            : 'Only needed when using external Poppler tools.';
    } else if ($key === 'tool_pdftotext') {
        $notes = $pdfmode === 'external'
            ? 'Required because PDF mode is set to external.'
            : 'Optional. Auto mode prefers this when available.';
    } else if ($key === 'tool_pdfinfo') {
        $notes = 'Recommended for better PDF page counting.';
    } else if ($key === 'tool_pdfparser') {
        $notes = $pdfmode === 'bundled'
            ? 'Required because PDF mode is set to bundled.'
            : 'Optional unless bundled mode is selected.';
    } else if ($key === 'ext_imagick') {
        $notes = 'Optional, but recommended for PDF page previews.';
    } else if ($key === 'ext_zip') {
        $notes = 'Required for PPTX and DOCX inspection.';
    } else if ($key === 'builtin_generator') {
        $notes = 'Provides offline, text-based question generation and analytics fallback.';
    } else if ($key === 'external_ai_providers') {
        $notes = 'Optional. External providers can improve quality and multimodal coverage.';
    }

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $check['name']);
    echo html_writer::tag('td', html_writer::tag('span', $statusIcon . ' ' . $check['message'], ['class' => $statusClass]));
    echo html_writer::tag('td', $check['required'] ? 'Yes' : 'No');
    echo html_writer::tag('td', $notes);
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// Test PDF upload form.
echo $OUTPUT->heading('Test PDF Extraction', 3);
echo $OUTPUT->box_start();

if (!$pdfbackendready) {
    echo $OUTPUT->notification('Please make a working PDF backend available in this runtime first.', 'warning');
} else {
    echo html_writer::tag('p', 'Upload a test PDF to verify extraction is working:');
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'testpdf', 'accept' => '.pdf']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Test Extraction']);
    echo html_writer::end_tag('form');

    // Handle test upload.
    if (!empty($_FILES['testpdf']) && $_FILES['testpdf']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['testpdf']['tmp_name'];
        echo html_writer::start_tag('div', ['class' => 'mt-3']);
        echo html_writer::tag('h4', 'Test Results:');

        try {
            // Test extraction.
            $pages = $generator->test_pdf_extraction($tmpFile);

            echo $OUTPUT->notification(
                'SUCCESS: Extracted ' . count($pages) . ' pages from PDF',
                'success'
            );

            // Show sample.
            $firstpage = reset($pages);
            if (!empty($firstpage)) {
                echo html_writer::tag('h5', 'Page 1 Sample:');
                $preview = substr((string)$firstpage['text'], 0, 500);
                echo html_writer::tag('pre', s($preview) . (strlen((string)$firstpage['text']) > 500 ? '...' : ''));
            }
        } catch (Exception $e) {
            echo $OUTPUT->notification('ERROR: ' . $e->getMessage(), 'error');
        }

        echo html_writer::end_tag('div');
    }
}

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
