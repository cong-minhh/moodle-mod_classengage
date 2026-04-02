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
 * Print screenshot-friendly benchmark evidence for classroom beta testing.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    $help = <<<HELP
Generate a screenshot-friendly benchmark summary for a ClassEngage beta session.

Usage:
  php mod/classengage/cli/benchmark_beta_session.php --sessionid=SESSION_ID [options]

Options:
  --sessionid=ID             Session to analyse
  --slideid=ID               Optional slide deck override
  --student-feedback=TEXT    Optional student feedback sentence content
  --instructor-feedback=TEXT Optional instructor feedback sentence content
  --output=PATH              Optional file path to save the text report
  --json                     Print JSON instead of plain text
  -h, --help                 Show this help

Examples:
  php mod/classengage/cli/benchmark_beta_session.php --sessionid=18
  php mod/classengage/cli/benchmark_beta_session.php --sessionid=18 --output=/tmp/classengage-benchmark.txt
  php mod/classengage/cli/benchmark_beta_session.php --sessionid=18 --json
HELP;

    echo $help . PHP_EOL;
    exit(0);
}

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/evidence_lib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'sessionid' => 0,
        'slideid' => 0,
        'student-feedback' => '',
        'instructor-feedback' => '',
        'output' => '',
        'json' => false,
    ],
    [
        'h' => 'help',
        's' => 'sessionid',
    ]
);

if ($unrecognized) {
    $unknown = implode(PHP_EOL . '  ', $unrecognized);
    cli_error("Unknown options:\n  {$unknown}");
}

$sessionid = (int)$options['sessionid'];
$slideid = (int)$options['slideid'];
$studentfeedback = trim((string)$options['student-feedback']);
$instructorfeedback = trim((string)$options['instructor-feedback']);
$output = trim((string)$options['output']);
$asjson = !empty($options['json']);

if ($sessionid <= 0) {
    cli_error('Session id is required.');
}

$metrics = mod_classengage_evidence_collect_metrics($sessionid, $slideid);

if ($asjson) {
    $content = json_encode(mod_classengage_evidence_build_json_payload($metrics), JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    $content = mod_classengage_evidence_build_benchmark_text($metrics, $studentfeedback, $instructorfeedback);
}

if ($output !== '') {
    mod_classengage_evidence_write_file($output, $content);
}

echo $content;
