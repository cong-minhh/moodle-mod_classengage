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
 * Export a thesis-ready ClassEngage classroom beta testing section.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    $help = <<<HELP
Generate a Markdown subsection for report evidence from a recorded ClassEngage session.

Usage:
  php mod/classengage/cli/evaluation_report.php --sessionid=SESSION_ID [options]

Options:
  --sessionid=ID             Session to analyse
  --slideid=ID               Optional slide deck override
  --title=TEXT               Section heading (default: 9.3.1 Classroom Beta Testing)
  --section-level=N          Markdown heading depth 1-6 (default: 4)
  --student-feedback=TEXT    Optional student feedback sentence content
  --instructor-feedback=TEXT Optional instructor feedback sentence content
  --output=PATH              Optional file path to save the Markdown
  -h, --help                 Show this help

Examples:
  php mod/classengage/cli/evaluation_report.php --sessionid=18
  php mod/classengage/cli/evaluation_report.php --sessionid=18 --output=/tmp/9.3.1-classroom-beta-testing.md
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
        'title' => '9.3.1 Classroom Beta Testing',
        'section-level' => 4,
        'student-feedback' => '',
        'instructor-feedback' => '',
        'output' => '',
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
$title = trim((string)$options['title']);
$sectionlevel = max(1, min(6, (int)$options['section-level']));
$studentfeedback = trim((string)$options['student-feedback']);
$instructorfeedback = trim((string)$options['instructor-feedback']);
$output = trim((string)$options['output']);

if ($sessionid <= 0) {
    cli_error('Session id is required.');
}

if ($title === '') {
    cli_error('Title cannot be empty.');
}

$metrics = mod_classengage_evidence_collect_metrics($sessionid, $slideid);
$bundle = $metrics['bundle'];

$heading = str_repeat('#', $sectionlevel) . ' ' . $title;
$quantitative = mod_classengage_evidence_build_quantitative_sentence($metrics);
$qualitative = mod_classengage_evidence_build_feedback_paragraph($studentfeedback, $instructorfeedback);

$lines = [];
$lines[] = $heading;
$lines[] = '';
$lines[] = $quantitative;
$lines[] = '';
$lines[] = $qualitative;
$lines[] = '';
$lines[] = 'Supporting metrics:';
$lines[] = '';
$lines[] = '- Session ID: ' . $bundle['session']->id;
$lines[] = '- Activity: ' . format_string($bundle['classengage']->name);
$lines[] = '- Presentation: ' . (!empty($metrics['slide']) ? $metrics['slide']->filename : 'n/a');
$lines[] = '- Slide count: ' . ($metrics['slide_page_count'] ?? 'n/a');
$lines[] = '- Questions generated: ' . $metrics['generated_question_count'];
$lines[] = '- Participants observed: ' . $metrics['participants'];
$lines[] = '- Total recorded responses: ' . (int)$metrics['summary']->total_responses;
$lines[] = '- Average response time: ' . number_format((float)$metrics['summary']->avg_response_time, 2) . ' seconds';
$lines[] = '- Maximum broadcast latency: ' .
    ($metrics['broadcast_latency']['max_ms'] === null ? 'n/a' : (int)$metrics['broadcast_latency']['max_ms'] . ' ms');
$lines[] = '';

$markdown = implode(PHP_EOL, $lines) . PHP_EOL;

if ($output !== '') {
    mod_classengage_evidence_write_file($output, $markdown);
}

echo $markdown;
