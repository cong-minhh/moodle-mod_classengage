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
 * Create demo users and enrol them into a course.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    $help = <<<HELP
Create demo users and enrol them into a Moodle course for ClassEngage.

Usage:
  php mod/classengage/cli/demo_setup.php --courseid=COURSE_ID [options]

Options:
  --courseid=ID         Course to enrol the demo users into
  --count=N             Number of users to create or reuse (default: 20)
  --prefix=STRING       Username prefix (default: classengage_demo)
  --password=STRING     Password to assign to new users (default: DemoClass123!)
  --reset-password      Reset the password for matching existing users too
  --verbose             Print each user as it is processed
  -h, --help            Show this help

Example:
  php mod/classengage/cli/demo_setup.php --courseid=2 --count=30 --prefix=democe
HELP;

    echo $help . PHP_EOL;
    exit(0);
}

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once(__DIR__ . '/demo_lib.php');

// Demo users do not need outbound email and suppressing it keeps CLI output clean.
$CFG->noemailever = true;
set_debugging(DEBUG_NONE, false);

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'courseid' => 0,
        'count' => 20,
        'prefix' => 'classengage_demo',
        'password' => 'DemoClass123!',
        'reset-password' => false,
        'verbose' => false,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
        'n' => 'count',
        'p' => 'prefix',
        'v' => 'verbose',
    ]
);

if ($unrecognized) {
    $unknown = implode(PHP_EOL . '  ', $unrecognized);
    cli_error("Unknown options:\n  {$unknown}");
}

if (!empty($options['help'])) {
    echo "Use --help before Moodle bootstrap or run the command without arguments to execute the setup." . PHP_EOL;
    exit(0);
}

$courseid = (int) $options['courseid'];
$count = (int) $options['count'];
$prefix = trim((string) $options['prefix']);
$password = (string) $options['password'];
$resetpassword = !empty($options['reset-password']);
$verbose = !empty($options['verbose']);

if ($courseid <= 1) {
    cli_error('A real course id is required. Course id 1 is the site course and cannot be used.');
}

if ($count < 1 || $count > 10000) {
    cli_error('Count must be between 1 and 10000.');
}

if ($password === '') {
    cli_error('Password cannot be empty.');
}

try {
    mod_classengage_demo_validate_prefix($prefix);
} catch (coding_exception $e) {
    cli_error($e->getMessage());
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$manualinstance = mod_classengage_demo_get_manual_enrol_instance($course);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

cli_heading('ClassEngage Demo Setup');
cli_writeln('Course: ' . format_string($course->fullname) . ' (#' . $course->id . ')');
cli_writeln('Prefix: ' . $prefix);
cli_writeln('Users: ' . $count);
cli_writeln('');

$created = 0;
$reused = 0;
$passwordsreset = 0;
$enrolled = 0;
$alreadyenrolled = 0;

$coursecontext = context_course::instance($courseid);
$enrolplugin = enrol_get_plugin('manual');
if (!$enrolplugin) {
    cli_error('Manual enrolment plugin is not available.');
}

for ($index = 1; $index <= $count; $index++) {
    $username = $prefix . '_' . $index;
    $user = core_user::get_user_by_username($username, 'id,username,firstname,lastname,email,auth,password');

    if (!$user) {
        $record = new stdClass();
        $record->username = $username;
        $record->password = $password;
        $record->firstname = 'Demo';
        $record->lastname = 'Student ' . $index;
        $record->email = $username . '@example.com';
        $record->auth = 'manual';
        $record->confirmed = 1;
        $record->mnethostid = $CFG->mnet_localhost_id;

        $userid = user_create_user($record, false, false);
        $user = $DB->get_record('user', ['id' => $userid], 'id,username,firstname,lastname,email,auth,password', MUST_EXIST);
        $created++;

        if ($verbose) {
            cli_writeln('Created user ' . $user->username);
        }
    } else {
        $reused++;

        if ($resetpassword) {
            update_internal_user_password($user, $password);
            $passwordsreset++;
        }

        if ($verbose) {
            $status = $resetpassword ? 'Reused user and reset password ' : 'Reused user ';
            cli_writeln($status . $user->username);
        }
    }

    if (is_enrolled($coursecontext, $user, '', true)) {
        $alreadyenrolled++;
        continue;
    }

    $enrolplugin->enrol_user($manualinstance, $user->id, $studentrole->id);
    $enrolled++;

    if ($verbose) {
        cli_writeln('Enrolled ' . $user->username . ' into course #' . $courseid);
    }
}

cli_writeln('Summary');
cli_writeln('  Created: ' . $created);
cli_writeln('  Reused: ' . $reused);
cli_writeln('  Passwords reset: ' . $passwordsreset);
cli_writeln('  Newly enrolled: ' . $enrolled);
cli_writeln('  Already enrolled: ' . $alreadyenrolled);
cli_writeln('');
cli_writeln('Demo credentials');
cli_writeln('  Username pattern: ' . $prefix . '_1 .. ' . $prefix . '_' . $count);
cli_writeln('  Password: ' . $password);
cli_writeln('');
cli_writeln('Next step');
cli_writeln(
    '  php mod/classengage/cli/demo_responder.php --sessionid=SESSION_ID --prefix=' . $prefix
);
