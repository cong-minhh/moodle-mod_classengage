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
 * Shared helpers for ClassEngage demo CLI scripts.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Validate a demo user prefix.
 *
 * @param string $prefix
 * @return void
 */
function mod_classengage_demo_validate_prefix(string $prefix): void {
    if ($prefix === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
        throw new coding_exception('Prefix must contain only letters, numbers, underscores, or hyphens.');
    }
}

/**
 * Return demo users for a prefix.
 *
 * @param string $prefix
 * @param int $limit
 * @return stdClass[]
 */
function mod_classengage_demo_get_users(string $prefix, int $limit = 0): array {
    global $DB;

    mod_classengage_demo_validate_prefix($prefix);

    $records = $DB->get_records_select(
        'user',
        'username LIKE :prefix',
        ['prefix' => $prefix . '%'],
        '',
        'id, username, firstname, lastname, email, auth, confirmed'
    );

    $users = [];
    foreach ($records as $record) {
        if (strpos($record->username, $prefix . '_') === 0) {
            $users[] = $record;
        }
    }

    usort($users, static function($a, $b): int {
        return strnatcasecmp($a->username, $b->username);
    });

    if ($limit > 0) {
        $users = array_slice($users, 0, $limit);
    }

    return $users;
}

/**
 * Find or create the manual enrolment instance for a course.
 *
 * @param stdClass $course
 * @return stdClass
 */
function mod_classengage_demo_get_manual_enrol_instance(stdClass $course): stdClass {
    global $DB;

    $plugin = enrol_get_plugin('manual');
    if (!$plugin) {
        throw new moodle_exception('Manual enrolment plugin is not available.');
    }

    $instances = enrol_get_instances($course->id, true);
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            return $instance;
        }
    }

    $instanceid = $plugin->add_default_instance($course);
    return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
}

/**
 * Get the current question for a session.
 *
 * @param int $sessionid
 * @return stdClass|null
 */
function mod_classengage_demo_get_current_question(int $sessionid): ?stdClass {
    global $DB;

    $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";

    return $DB->get_record_sql($sql, [
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1,
    ]) ?: null;
}

/**
 * Get answer option keys for a question.
 *
 * @param stdClass $question
 * @return string[]
 */
function mod_classengage_demo_get_option_keys(stdClass $question): array {
    $keys = [];

    if (!empty($question->optiona)) {
        $keys[] = 'A';
    }
    if (!empty($question->optionb)) {
        $keys[] = 'B';
    }
    if (!empty($question->optionc)) {
        $keys[] = 'C';
    }
    if (!empty($question->optiond)) {
        $keys[] = 'D';
    }

    if ($question->questiontype === 'truefalse' && empty($keys)) {
        return ['TRUE', 'FALSE'];
    }

    return $keys;
}

/**
 * Choose an answer for a question.
 *
 * @param stdClass $question
 * @param int $correctrate
 * @return string
 */
function mod_classengage_demo_choose_answer(stdClass $question, int $correctrate): string {
    $correctrate = max(0, min(100, $correctrate));
    $roll = random_int(1, 100);

    if ($roll <= $correctrate) {
        return (string) $question->correctanswer;
    }

    return mod_classengage_demo_choose_wrong_answer($question);
}

/**
 * Choose an intentionally wrong answer for a question.
 *
 * @param stdClass $question
 * @return string
 */
function mod_classengage_demo_choose_wrong_answer(stdClass $question): string {
    $correctanswer = strtoupper(trim((string) $question->correctanswer));

    if ($question->questiontype === 'truefalse') {
        $trueanswers = ['TRUE', 'T', '1'];
        return in_array($correctanswer, $trueanswers, true) ? 'FALSE' : 'TRUE';
    }

    if ($question->questiontype === 'shortanswer') {
        return 'Demo answer ' . random_int(100, 999);
    }

    $choices = mod_classengage_demo_get_option_keys($question);
    $choices = array_values(array_filter($choices, static function(string $choice) use ($correctanswer): bool {
        return strtoupper($choice) !== $correctanswer;
    }));

    if (empty($choices)) {
        $choices = ['A', 'B', 'C', 'D'];
        $choices = array_values(array_filter($choices, static function(string $choice) use ($correctanswer): bool {
            return $choice !== $correctanswer;
        }));
    }

    return $choices[array_rand($choices)];
}

/**
 * Build a stable connection id for a demo user.
 *
 * @param int $sessionid
 * @param int $userid
 * @return string
 */
function mod_classengage_demo_connection_id(int $sessionid, int $userid): string {
    return 'demo_' . $sessionid . '_' . $userid;
}

/**
 * Cap stagger spread so answers land before the timer expires.
 *
 * @param int $spreadseconds
 * @param int $timelimit
 * @return int
 */
function mod_classengage_demo_get_effective_spread(int $spreadseconds, int $timelimit): int {
    if ($spreadseconds <= 0 || $timelimit <= 1) {
        return 0;
    }

    return min($spreadseconds, max(0, $timelimit - 1));
}
