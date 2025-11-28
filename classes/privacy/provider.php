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
 * Privacy Subsystem implementation for mod_classengage.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for the classengage activity module.
 */
class provider implements
        // This plugin stores personal data.
        \core_privacy\local\metadata\provider,

        // This plugin is a core_user_data_provider.
        \core_privacy\local\request\plugin\provider,

        // This plugin is capable of determining which users have data within it.
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $items a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table(
            'classengage_responses',
            [
                'userid' => 'privacy:metadata:classengage_responses:userid',
                'questionid' => 'privacy:metadata:classengage_responses:questionid',
                'sessionid' => 'privacy:metadata:classengage_responses:sessionid',
                'answer' => 'privacy:metadata:classengage_responses:answer',
                'score' => 'privacy:metadata:classengage_responses:score',
                'timecreated' => 'privacy:metadata:classengage_responses:timecreated',
            ],
            'privacy:metadata:classengage_responses'
        );

        return $items;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {classengage} ce ON ce.id = cm.instance
            INNER JOIN {classengage_responses} r ON r.classengageid = ce.id
                 WHERE r.userid = :userid";

        $params = [
            'modname' => 'classengage',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT r.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {classengage} ce ON ce.id = cm.instance
                  JOIN {classengage_responses} r ON r.classengageid = ce.id
                 WHERE cm.id = :cmid";

        $params = [
            'cmid' => $context->instanceid,
            'modname' => 'classengage',
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export personal data for the given approved_contextlist. User and context information is contained within the contextlist.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT cm.id AS cmid,
                       r.id, r.questionid, r.sessionid, r.answer, r.iscorrect, r.score, r.timecreated
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {classengage} ce ON ce.id = cm.instance
            INNER JOIN {classengage_responses} r ON r.classengageid = ce.id
                 WHERE c.id {$contextsql}
                       AND r.userid = :userid
              ORDER BY cm.id";

        $params = ['modname' => 'classengage', 'contextlevel' => CONTEXT_MODULE, 'userid' => $user->id] + $contextparams;

        $responses = $DB->get_recordset_sql($sql, $params);
        foreach ($responses as $response) {
            $context = \context_module::instance($response->cmid);
            $data = (object) [
                'questionid' => $response->questionid,
                'sessionid' => $response->sessionid,
                'answer' => $response->answer,
                'iscorrect' => transform::yesno($response->iscorrect),
                'score' => $response->score,
                'timecreated' => transform::datetime($response->timecreated),
            ];
            writer::with_context($context)->export_data(['responses', $response->id], $data);
        }
        $responses->close();
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        if ($cm = get_coursemodule_from_id('classengage', $context->instanceid)) {
            $DB->delete_records('classengage_responses', ['classengageid' => $cm->instance]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $instanceid = $DB->get_field('course_modules', 'instance', ['id' => $context->instanceid], MUST_EXIST);
            $DB->delete_records('classengage_responses', ['classengageid' => $instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('classengage', $context->instanceid);

        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $select = "classengageid = :classengageid AND userid $usersql";
        $params = ['classengageid' => $cm->instance] + $userparams;
        $DB->delete_records_select('classengage_responses', $select, $params);
    }
}

