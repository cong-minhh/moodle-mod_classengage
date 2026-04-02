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
 * Submit staggered demo answers for an active ClassEngage session.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    $help = <<<HELP
Submit staggered demo answers for a live ClassEngage session.

Usage:
  php mod/classengage/cli/demo_responder.php --sessionid=SESSION_ID [options]

Options:
  --sessionid=ID        Session to watch and answer
  --prefix=STRING       Username prefix created by demo_setup.php
  --users=N             Limit to the first N matching users (default: all)
  --correct-rate=N      Percentage of answers that should be correct (default: 65)
  --spread-seconds=N    Spread answers over this many seconds per question (default: 12)
  --poll-seconds=N      Poll interval while waiting (default: 1)
  --once                Answer the current question only, then exit
  --verbose             Print every submitted answer
  -h, --help            Show this help

Examples:
  php mod/classengage/cli/demo_responder.php --sessionid=4 --prefix=democe
  php mod/classengage/cli/demo_responder.php --sessionid=4 --prefix=democe --users=15 --spread-seconds=8 --correct-rate=75
HELP;

    echo $help . PHP_EOL;
    exit(0);
}

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/demo_lib.php');

use mod_classengage\analytics_engine;
use mod_classengage\response_capture_engine;
use mod_classengage\session_state_manager;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'sessionid' => 0,
        'prefix' => 'classengage_demo',
        'users' => 0,
        'correct-rate' => 65,
        'spread-seconds' => 12,
        'poll-seconds' => 1,
        'once' => false,
        'verbose' => false,
    ],
    [
        'h' => 'help',
        's' => 'sessionid',
        'p' => 'prefix',
        'u' => 'users',
        'v' => 'verbose',
    ]
);

if ($unrecognized) {
    $unknown = implode(PHP_EOL . '  ', $unrecognized);
    cli_error("Unknown options:\n  {$unknown}");
}

if (!empty($options['help'])) {
    echo "Use --help before Moodle bootstrap or run the command without arguments to start the responder." . PHP_EOL;
    exit(0);
}

$sessionid = (int) $options['sessionid'];
$prefix = trim((string) $options['prefix']);
$limit = (int) $options['users'];
$correctrate = (int) $options['correct-rate'];
$spreadseconds = (int) $options['spread-seconds'];
$pollseconds = (int) $options['poll-seconds'];
$once = !empty($options['once']);
$verbose = !empty($options['verbose']);

if ($sessionid <= 0) {
    cli_error('Session id is required.');
}

if ($limit < 0) {
    cli_error('Users must be 0 or greater.');
}

if ($correctrate < 0 || $correctrate > 100) {
    cli_error('Correct rate must be between 0 and 100.');
}

if ($spreadseconds < 0) {
    cli_error('Spread seconds must be 0 or greater.');
}

if ($pollseconds < 1) {
    cli_error('Poll seconds must be at least 1.');
}

try {
    mod_classengage_demo_validate_prefix($prefix);
} catch (coding_exception $e) {
    cli_error($e->getMessage());
}

$session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', ['id' => $session->classengageid], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $classengage->course], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$coursecontext = context_course::instance($course->id);

$users = mod_classengage_demo_get_users($prefix, $limit);
if (empty($users)) {
    cli_error('No users found for prefix "' . $prefix . '". Run demo_setup.php first.');
}

$eligibleusers = [];
$skippedenrolment = 0;
foreach ($users as $user) {
    if (!is_enrolled($coursecontext, $user, '', true)) {
        $skippedenrolment++;
        continue;
    }
    $eligibleusers[] = $user;
}

if (empty($eligibleusers)) {
    cli_error('No active enrolled users matched the prefix for this course.');
}

$engine = new response_capture_engine();
$statemanager = new session_state_manager();
$analytics = new analytics_engine($classengage->id, $context);
$connectionids = [];

register_shutdown_function(static function() use ($statemanager, &$connectionids): void {
    foreach ($connectionids as $connectionid) {
        try {
            $statemanager->handle_disconnect($connectionid);
        } catch (Throwable $e) {
            // Ignore shutdown cleanup failures.
        }
    }
});

$fetchansweredusers = static function(int $questionid) use ($DB, $sessionid, $eligibleusers): array {
    $answered = $DB->get_fieldset_select(
        'classengage_responses',
        'userid',
        'sessionid = :sessionid AND questionid = :questionid',
        [
            'sessionid' => $sessionid,
            'questionid' => $questionid,
        ]
    );

    $targetids = [];
    foreach ($eligibleusers as $user) {
        $targetids[$user->id] = true;
    }

    $lookup = [];
    foreach ($answered as $userid) {
        if (isset($targetids[(int) $userid])) {
            $lookup[(int) $userid] = true;
        }
    }

    return $lookup;
};

$sessionstillcurrent = static function(int $questionid) use ($DB, $sessionid): bool {
    $sessionrecord = $DB->get_record('classengage_sessions', ['id' => $sessionid], 'id,status,currentquestion');
    if (!$sessionrecord || $sessionrecord->status !== 'active') {
        return false;
    }

    $currentquestion = mod_classengage_demo_get_current_question($sessionid);
    return !empty($currentquestion) && (int) $currentquestion->id === $questionid;
};

cli_heading('ClassEngage Demo Responder');
cli_writeln('Session: #' . $sessionid . ' (' . format_string($classengage->name) . ')');
cli_writeln('Course: ' . format_string($course->fullname) . ' (#' . $course->id . ')');
cli_writeln('Prefix: ' . $prefix);
cli_writeln('Target users: ' . count($eligibleusers));
if ($skippedenrolment > 0) {
    cli_writeln('Skipped not-enrolled users: ' . $skippedenrolment);
}
cli_writeln('Correct rate: ' . $correctrate . '%');
cli_writeln('Spread: ' . $spreadseconds . 's');
cli_writeln('Mode: ' . ($once ? 'current question only' : 'watch until session completes'));
cli_writeln('');

$lastannouncedquestionkey = '';
$lastwaitmessage = '';
$totalresponses = 0;

while (true) {
    $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

    if ($session->status === 'completed') {
        cli_writeln('Session completed. Exiting.');
        break;
    }

    if ($session->status !== 'active') {
        $message = 'Waiting for active session. Current status: ' . $session->status;
        if ($message !== $lastwaitmessage) {
            cli_writeln($message);
            $lastwaitmessage = $message;
        }
        sleep($pollseconds);
        continue;
    }

    $question = mod_classengage_demo_get_current_question($sessionid);
    if (!$question) {
        $message = 'Waiting for a current question.';
        if ($message !== $lastwaitmessage) {
            cli_writeln($message);
            $lastwaitmessage = $message;
        }
        sleep($pollseconds);
        continue;
    }

    $lastwaitmessage = '';

    $questionnumber = (int) $session->currentquestion + 1;
    $questionkey = $questionnumber . ':' . $question->id;
    $existinganswered = $fetchansweredusers((int) $question->id);

    if ($questionkey !== $lastannouncedquestionkey) {
        $lastannouncedquestionkey = $questionkey;
        $preview = trim(preg_replace('/\s+/', ' ', strip_tags((string) $question->questiontext)));
        if (core_text::strlen($preview) > 80) {
            $preview = core_text::substr($preview, 0, 77) . '...';
        }

        cli_writeln('Question ' . $questionnumber . ': ' . $preview);

        foreach ($eligibleusers as $user) {
            $connectionid = mod_classengage_demo_connection_id($sessionid, $user->id);
            $statemanager->register_connection($sessionid, $user->id, $connectionid, 'demo');
            $connectionids[$user->id] = $connectionid;
        }

        foreach (array_keys($existinganswered) as $userid) {
            $statemanager->mark_question_answered($sessionid, (int) $userid);
        }
    }

    $pendingusers = [];
    foreach ($eligibleusers as $user) {
        if (!isset($existinganswered[$user->id])) {
            $pendingusers[] = $user;
        }
    }

    if (empty($pendingusers)) {
        if ($once) {
            cli_writeln('All targeted users have already answered the current question.');
            break;
        }
        sleep($pollseconds);
        continue;
    }

    $effectivespread = mod_classengage_demo_get_effective_spread($spreadseconds, (int) $session->timelimit);
    $schedule = [];
    foreach ($pendingusers as $user) {
        $offsetms = $effectivespread > 0 ? random_int(0, $effectivespread * 1000) : 0;
        $schedule[] = [
            'offset' => $offsetms / 1000,
            'user' => $user,
            'answer' => mod_classengage_demo_choose_answer($question, $correctrate),
        ];
    }

    usort($schedule, static function(array $left, array $right): int {
        return $left['offset'] <=> $right['offset'];
    });

    cli_writeln(
        'Submitting ' . count($schedule) . ' demo answers over ' . $effectivespread . ' second(s).'
    );

    $windowstart = microtime(true);
    $questionresponses = 0;

    foreach ($schedule as $slot) {
        while ((microtime(true) - $windowstart) < $slot['offset']) {
            usleep(200000);
            if (!$sessionstillcurrent((int) $question->id)) {
                break 2;
            }
        }

        if (!$sessionstillcurrent((int) $question->id)) {
            break;
        }

        $result = $engine->submit_response(
            $sessionid,
            (int) $question->id,
            (string) $slot['answer'],
            (int) $slot['user']->id,
            time()
        );

        if ($result->success) {
            $statemanager->mark_question_answered($sessionid, (int) $slot['user']->id);
            $analytics->invalidate_cache($sessionid);
            $totalresponses++;
            $questionresponses++;

            if ($verbose) {
                $correct = $result->iscorrect ? 'correct' : 'wrong';
                cli_writeln(
                    '  ' . $slot['user']->username . ' -> ' . $slot['answer'] . ' (' . $correct . ')'
                );
            }
            continue;
        }

        if (strpos((string) $result->error, 'Duplicate submission') !== false) {
            $statemanager->mark_question_answered($sessionid, (int) $slot['user']->id);
            if ($verbose) {
                cli_writeln('  ' . $slot['user']->username . ' already answered, keeping status synced.');
            }
            continue;
        }

        if ($verbose) {
            cli_writeln(
                '  Failed for ' . $slot['user']->username . ': ' . ($result->error ?? 'unknown error')
            );
        }
    }

    cli_writeln('Recorded ' . $questionresponses . ' demo answers for question ' . $questionnumber . '.');

    if ($once) {
        break;
    }

    sleep($pollseconds);
}

cli_writeln('');
cli_writeln('Total demo responses recorded: ' . $totalresponses);
