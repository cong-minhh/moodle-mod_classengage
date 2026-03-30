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
 * Check the current CLI runtime for ClassEngage background processing.
 *
 * This is intended to be run in the same environment as Moodle cron or the
 * optional ClassEngage worker.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    $help = "ClassEngage CLI runtime check

Checks whether the current CLI runtime can execute ClassEngage background tasks.

Usage:
  php mod/classengage/cli/runtime_check.php

Run this inside the host, VM, or container that will execute Moodle cron or
mod/classengage/classes/task/task_worker.php.
";

    echo $help;
    exit(0);
}

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/nlp_generator.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode(PHP_EOL . '  ', $unrecognized);
    cli_error("Unknown options:\n  {$unrecognized}", 2);
}

if (!empty($options['help'])) {
    echo "Use --help before Moodle bootstrap or run the command without arguments to perform the runtime check.\n";
    exit(0);
}

$generator = new \mod_classengage\nlp_generator();
$pdftools = $generator->check_pdf_tools();

$providers = [
    'gemini' => 'geminiapikey',
    'openai' => 'openaiapikey',
    'anthropic' => 'anthropicapikey',
    'deepseek' => 'deepseekapikey',
    'kimi' => 'kimiapikey',
    'kimicn' => 'kimicnapikey',
    'local' => 'localendpoint',
];

$configuredproviders = [];
foreach ($providers as $name => $configkey) {
    $value = (string)get_config('mod_classengage', $configkey);
    if ($value !== '') {
        $configuredproviders[] = $name;
    }
}

$results = [
    'PHP CLI bootstrap' => true,
    'shell_exec()' => !empty($pdftools['shell_exec']),
    'pdftotext' => !empty($pdftools['pdftotext']),
    'pdfinfo' => !empty($pdftools['pdfinfo']),
    'Imagick' => !empty($pdftools['imagick']),
    'ZipArchive' => class_exists('\ZipArchive'),
    'AI provider configured' => !empty($configuredproviders),
];

$required = [
    'PHP CLI bootstrap',
    'shell_exec()',
    'pdftotext',
    'ZipArchive',
    'AI provider configured',
];

$user = 'unknown';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    if (!empty($pw['name'])) {
        $user = $pw['name'];
    }
}

cli_writeln('ClassEngage runtime check');
cli_writeln('=========================');
cli_writeln('Moodle dirroot: ' . $CFG->dirroot);
cli_writeln('PHP binary: ' . PHP_BINARY);
cli_writeln('PHP version: ' . PHP_VERSION);
cli_writeln('User: ' . $user);
cli_writeln('');

foreach ($results as $label => $ok) {
    $status = $ok ? '[OK]' : '[MISSING]';
    cli_writeln(str_pad($status, 10) . ' ' . $label);
}

cli_writeln('');
if (!empty($configuredproviders)) {
    cli_writeln('Configured AI providers: ' . implode(', ', $configuredproviders));
} else {
    cli_writeln('Configured AI providers: none');
}

$missingrequired = [];
foreach ($required as $label) {
    if (empty($results[$label])) {
        $missingrequired[] = $label;
    }
}

cli_writeln('');
if (empty($missingrequired)) {
    cli_writeln('Result: this CLI runtime satisfies the required ClassEngage background-task dependencies.');
    exit(0);
}

cli_writeln('Result: missing required dependencies: ' . implode(', ', $missingrequired));
cli_writeln('Install the missing components in the runtime that executes Moodle cron or task_worker.php.');
exit(1);
