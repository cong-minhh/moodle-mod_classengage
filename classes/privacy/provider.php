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
 * This provider covers ALL user data tables for GDPR compliance:
 * - classengage_responses: Quiz responses with user answers
 * - classengage_connections: Real-time connection tracking
 * - classengage_session_log: Event logging with user data
 * - classengage_clicker_devices: Clicker device registrations
 * - classengage_slides: Slide uploads (tracks uploader)
 * - classengage_sessions: Session creation (tracks creator)
 * - classengage_response_queue: Pending responses in queue
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
use core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for the classengage activity module.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $items a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $items): collection {
        // Table 1: classengage_responses - Primary quiz response data
        $items->add_database_table(
            'classengage_responses',
            [
                'userid' => 'privacy:metadata:responses:userid',
                'questionid' => 'privacy:metadata:responses:questionid',
                'sessionid' => 'privacy:metadata:responses:sessionid',
                'classengageid' => 'privacy:metadata:responses:classengageid',
                'answer' => 'privacy:metadata:responses:answer',
                'iscorrect' => 'privacy:metadata:responses:iscorrect',
                'score' => 'privacy:metadata:responses:score',
                'responsetime' => 'privacy:metadata:responses:responsetime',
                'timecreated' => 'privacy:metadata:responses:timecreated',
            ],
            'privacy:metadata:responses'
        );

        // Table 2: classengage_connections - Real-time connection tracking
        $items->add_database_table(
            'classengage_connections',
            [
                'sessionid' => 'privacy:metadata:connections:sessionid',
                'userid' => 'privacy:metadata:connections:userid',
                'connectionid' => 'privacy:metadata:connections:connectionid',
                'transport' => 'privacy:metadata:connections:transport',
                'status' => 'privacy:metadata:connections:status',
                'current_question_answered' => 'privacy:metadata:connections:current_question_answered',
                'timecreated' => 'privacy:metadata:connections:timecreated',
                'timemodified' => 'privacy:metadata:connections:timemodified',
            ],
            'privacy:metadata:connections'
        );

        // Table 3: classengage_session_log - Event logging
        $items->add_database_table(
            'classengage_session_log',
            [
                'sessionid' => 'privacy:metadata:sessionlog:sessionid',
                'userid' => 'privacy:metadata:sessionlog:userid',
                'event_type' => 'privacy:metadata:sessionlog:event_type',
                'event_data' => 'privacy:metadata:sessionlog:event_data',
                'latency_ms' => 'privacy:metadata:sessionlog:latency_ms',
                'timecreated' => 'privacy:metadata:sessionlog:timecreated',
            ],
            'privacy:metadata:sessionlog'
        );

        // Table 4: classengage_clicker_devices - Clicker device registrations
        $items->add_database_table(
            'classengage_clicker_devices',
            [
                'userid' => 'privacy:metadata:clickerdevices:userid',
                'clickerid' => 'privacy:metadata:clickerdevices:clickerid',
                'contextid' => 'privacy:metadata:clickerdevices:contextid',
                'timecreated' => 'privacy:metadata:clickerdevices:timecreated',
                'lastused' => 'privacy:metadata:clickerdevices:lastused',
            ],
            'privacy:metadata:clickerdevices'
        );

        // Table 5: classengage_slides - Slide uploads (tracks uploader)
        $items->add_database_table(
            'classengage_slides',
            [
                'classengageid' => 'privacy:metadata:slides:classengageid',
                'title' => 'privacy:metadata:slides:title',
                'filename' => 'privacy:metadata:slides:filename',
                'userid' => 'privacy:metadata:slides:userid',
                'timecreated' => 'privacy:metadata:slides:timecreated',
                'timemodified' => 'privacy:metadata:slides:timemodified',
            ],
            'privacy:metadata:slides'
        );

        // Table 6: classengage_sessions - Session creation (tracks creator)
        $items->add_database_table(
            'classengage_sessions',
            [
                'classengageid' => 'privacy:metadata:sessions:classengageid',
                'name' => 'privacy:metadata:sessions:name',
                'createdby' => 'privacy:metadata:sessions:createdby',
                'timecreated' => 'privacy:metadata:sessions:timecreated',
                'timestarted' => 'privacy:metadata:sessions:timestarted',
                'timecompleted' => 'privacy:metadata:sessions:timecompleted',
                'timemodified' => 'privacy:metadata:sessions:timemodified',
            ],
            'privacy:metadata:sessions'
        );

        // Table 7: classengage_response_queue - Pending responses in queue
        $items->add_database_table(
            'classengage_response_queue',
            [
                'sessionid' => 'privacy:metadata:responsequeue:sessionid',
                'questionid' => 'privacy:metadata:responsequeue:questionid',
                'userid' => 'privacy:metadata:responsequeue:userid',
                'answer' => 'privacy:metadata:responsequeue:answer',
                'client_timestamp' => 'privacy:metadata:responsequeue:client_timestamp',
                'server_timestamp' => 'privacy:metadata:responsequeue:server_timestamp',
            ],
            'privacy:metadata:responsequeue'
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
        $contextlist = new contextlist();

        // Query for all tables that contain userid
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

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        // Get users from all tables with userid
        $tables = [
            'classengage_responses' => 'userid',
            'classengage_connections' => 'userid',
            'classengage_session_log' => 'userid',
            'classengage_clicker_devices' => 'userid',
            'classengage_slides' => 'userid',
            'classengage_sessions' => 'createdby',
            'classengage_response_queue' => 'userid',
        ];

        $unionqueries = [];
        $params = [
            'cmid' => $context->instanceid,
            'modname' => 'classengage',
        ];

        foreach ($tables as $table => $userfield) {
            if ($table === 'classengage_sessions') {
                $unionqueries[] = "SELECT {$userfield}
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                    JOIN {classengage} ce ON ce.id = cm.instance
                    JOIN {{$table}} t ON t.classengageid = ce.id
                   WHERE cm.id = :cmid AND {$userfield} IS NOT NULL";
            } else {
                $unionqueries[] = "SELECT {$userfield}
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                    JOIN {classengage} ce ON ce.id = cm.instance
                    JOIN {{$table}} t ON t.classengageid = ce.id
                   WHERE cm.id = :cmid";
            }
        }

        $sql = implode(" UNION ", $unionqueries);
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $contexts = $contextlist->get_contextids();

        foreach ($contexts as $contextid) {
            $context = \context_module::instance($contextid);
            $cm = get_coursemodule_from_id('classengage', $context->instanceid);
            if (!$cm) {
                continue;
            }

            self::export_responses($context, $cm->instance, $user->id);
            self::export_connections($context, $cm->instance, $user->id);
            self::export_session_logs($context, $cm->instance, $user->id);
            self::export_clicker_devices($context, $user->id);
            self::export_slides($context, $cm->instance, $user->id);
            self::export_sessions($context, $cm->instance, $user->id);
        }
    }

    /**
     * Export responses for a user.
     *
     * @param \context $context
     * @param int $classengageid
     * @param int $userid
     */
    protected static function export_responses(\context $context, int $classengageid, int $userid) {
        global $DB;

        $responses = $DB->get_records('classengage_responses', [
            'classengageid' => $classengageid,
            'userid' => $userid
        ]);

        foreach ($responses as $response) {
            $data = (object) [
                'questionid' => $response->questionid,
                'sessionid' => $response->sessionid,
                'answer' => $response->answer,
                'iscorrect' => transform::yesno($response->iscorrect),
                'score' => $response->score,
                'responsetime' => $response->responsetime . ' seconds',
                'timecreated' => transform::datetime($response->timecreated),
            ];
            writer::with_context($context)->export_data(['responses', $response->id], $data);
        }
    }

    /**
     * Export connections for a user.
     *
     * @param \context $context
     * @param int $classengageid
     * @param int $userid
     */
    protected static function export_connections(\context $context, int $classengageid, int $userid) {
        global $DB;

        $sql = "SELECT c.*
                  FROM {classengage_connections} c
                  JOIN {classengage_sessions} s ON s.id = c.sessionid
                 WHERE s.classengageid = :classengageid AND c.userid = :userid";

        $connections = $DB->get_records_sql($sql, [
            'classengageid' => $classengageid,
            'userid' => $userid
        ]);

        foreach ($connections as $connection) {
            $data = (object) [
                'sessionid' => $connection->sessionid,
                'connectionid' => $connection->connectionid,
                'transport' => $connection->transport,
                'status' => $connection->status,
                'current_question_answered' => transform::yesno($connection->current_question_answered),
                'timecreated' => transform::datetime($connection->timecreated),
                'timemodified' => transform::datetime($connection->timemodified),
            ];
            writer::with_context($context)->export_data(['connections', $connection->id], $data);
        }
    }

    /**
     * Export session logs for a user.
     *
     * @param \context $context
     * @param int $classengageid
     * @param int $userid
     */
    protected static function export_session_logs(\context $context, int $classengageid, int $userid) {
        global $DB;

        $sql = "SELECT l.*
                  FROM {classengage_session_log} l
                  JOIN {classengage_sessions} s ON s.id = l.sessionid
                 WHERE s.classengageid = :classengageid AND l.userid = :userid";

        $logs = $DB->get_records_sql($sql, [
            'classengageid' => $classengageid,
            'userid' => $userid
        ]);

        foreach ($logs as $log) {
            $data = (object) [
                'sessionid' => $log->sessionid,
                'event_type' => $log->event_type,
                'event_data' => $log->event_data,
                'latency_ms' => $log->latency_ms,
                'timecreated' => transform::datetime($log->timecreated),
            ];
            writer::with_context($context)->export_data(['session_logs', $log->id], $data);
        }
    }

    /**
     * Export clicker devices for a user.
     *
     * @param \context $context
     * @param int $userid
     */
    protected static function export_clicker_devices(\context $context, int $userid) {
        global $DB;

        $devices = $DB->get_records('classengage_clicker_devices', ['userid' => $userid]);

        foreach ($devices as $device) {
            $data = (object) [
                'clickerid' => $device->clickerid,
                'contextid' => $device->contextid,
                'timecreated' => transform::datetime($device->timecreated),
                'lastused' => transform::datetime($device->lastused),
            ];
            writer::with_context($context)->export_data(['clicker_devices', $device->id], $data);
        }
    }

    /**
     * Export slides for a user (uploader).
     *
     * @param \context $context
     * @param int $classengageid
     * @param int $userid
     */
    protected static function export_slides(\context $context, int $classengageid, int $userid) {
        global $DB;

        $slides = $DB->get_records('classengage_slides', [
            'classengageid' => $classengageid,
            'userid' => $userid
        ]);

        foreach ($slides as $slide) {
            $data = (object) [
                'title' => $slide->title,
                'filename' => $slide->filename,
                'timecreated' => transform::datetime($slide->timecreated),
                'timemodified' => transform::datetime($slide->timemodified),
            ];
            writer::with_context($context)->export_data(['slides', $slide->id], $data);
        }
    }

    /**
     * Export sessions created by a user.
     *
     * @param \context $context
     * @param int $classengageid
     * @param int $userid
     */
    protected static function export_sessions(\context $context, int $classengageid, int $userid) {
        global $DB;

        $sessions = $DB->get_records('classengage_sessions', [
            'classengageid' => $classengageid,
            'createdby' => $userid
        ]);

        foreach ($sessions as $session) {
            $data = (object) [
                'name' => $session->name,
                'status' => $session->status,
                'timecreated' => transform::datetime($session->timecreated),
                'timestarted' => $session->timestarted ? transform::datetime($session->timestarted) : null,
                'timecompleted' => $session->timecompleted ? transform::datetime($session->timecompleted) : null,
            ];
            writer::with_context($context)->export_data(['sessions', $session->id], $data);
        }
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

        $cm = get_coursemodule_from_id('classengage', $context->instanceid);
        if (!$cm) {
            return;
        }

        $classengageid = $cm->instance;

        // Delete from all tables
        $DB->delete_records('classengage_responses', ['classengageid' => $classengageid]);
        $DB->delete_records('classengage_slides', ['classengageid' => $classengageid]);

        // Delete session-related data
        $sessions = $DB->get_fieldset_select('classengage_sessions', 'id', 'classengageid = ?', [$classengageid]);
        if (!empty($sessions)) {
            list($insql, $inparams) = $DB->get_in_or_equal($sessions, SQL_PARAMS_NAMED);
            $DB->delete_records_select('classengage_connections', "sessionid $insql", $inparams);
            $DB->delete_records_select('classengage_session_log', "sessionid $insql", $inparams);
            $DB->delete_records_select('classengage_response_queue', "sessionid $insql", $inparams);
            $DB->delete_records_select('classengage_sessions', "id $insql", $inparams);
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

            $cm = get_coursemodule_from_id('classengage', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $classengageid = $cm->instance;

            // Delete from all user-specific tables
            $DB->delete_records('classengage_responses', [
                'classengageid' => $classengageid,
                'userid' => $userid
            ]);

            $DB->delete_records('classengage_slides', [
                'classengageid' => $classengageid,
                'userid' => $userid
            ]);

            // Delete connections
            $DB->delete_records_select(
                'classengage_connections',
                "sessionid IN (SELECT id FROM {classengage_sessions} WHERE classengageid = ?) AND userid = ?",
                [$classengageid, $userid]
            );

            // Delete session logs
            $DB->delete_records_select(
                'classengage_session_log',
                "sessionid IN (SELECT id FROM {classengage_sessions} WHERE classengageid = ?) AND userid = ?",
                [$classengageid, $userid]
            );

            // Delete response queue
            $DB->delete_records('classengage_response_queue', [
                'userid' => $userid
            ]);
        }

        // Delete clicker devices globally
        $DB->delete_records('classengage_clicker_devices', ['userid' => $userid]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
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

        $classengageid = $cm->instance;
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Delete responses
        $DB->delete_records_select(
            'classengage_responses',
            "classengageid = :classengageid AND userid $usersql",
            ['classengageid' => $classengageid] + $userparams
        );

        // Delete slides uploaded by these users
        $DB->delete_records_select(
            'classengage_slides',
            "classengageid = :classengageid AND userid $usersql",
            ['classengageid' => $classengageid] + $userparams
        );

        // Delete connections
        $DB->delete_records_select(
            'classengage_connections',
            "sessionid IN (SELECT id FROM {classengage_sessions} WHERE classengageid = :cid) AND userid $usersql",
            ['cid' => $classengageid] + $userparams
        );

        // Delete session logs
        $DB->delete_records_select(
            'classengage_session_log',
            "sessionid IN (SELECT id FROM {classengage_sessions} WHERE classengageid = :cid) AND userid $usersql",
            ['cid' => $classengageid] + $userparams
        );
    }
}
