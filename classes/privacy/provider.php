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
 * Privacy Subsystem implementation for quizaccess_sanad_lockdown.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@sanad.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_sanad_lockdown\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy Subsystem for quizaccess_sanad_lockdown implementing necessary interfaces.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about this plugin.
     *
     * @param  collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'quizaccess_sanad_sessions',
            [
                'userid'      => 'privacy:metadata:quizaccess_sanad_sessions:userid',
                'quizid'      => 'privacy:metadata:quizaccess_sanad_sessions:quizid',
                'token'       => 'privacy:metadata:quizaccess_sanad_sessions:token',
                'deviceid'    => 'privacy:metadata:quizaccess_sanad_sessions:deviceid',
                'timecreated' => 'privacy:metadata:quizaccess_sanad_sessions:timecreated',
                'timeexpires' => 'privacy:metadata:quizaccess_sanad_sessions:timeexpires',
            ],
            'privacy:metadata:quizaccess_sanad_sessions'
        );

        $collection->add_database_table(
            'quizaccess_sanad_violations',
            [
                'userid'        => 'privacy:metadata:quizaccess_sanad_violations:userid',
                'quizid'        => 'privacy:metadata:quizaccess_sanad_violations:quizid',
                'violationtype' => 'privacy:metadata:quizaccess_sanad_violations:violationtype',
                'deviceid'      => 'privacy:metadata:quizaccess_sanad_violations:deviceid',
                'details'       => 'privacy:metadata:quizaccess_sanad_violations:details',
                'timecreated'   => 'privacy:metadata:quizaccess_sanad_violations:timecreated',
            ],
            'privacy:metadata:quizaccess_sanad_violations'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param  int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quizaccess_sanad_sessions} ses ON ses.quizid = cm.instance
                 WHERE ses.userid = :userid";
        $params = [
            'modname'      => 'quiz',
            'contextlevel' => CONTEXT_MODULE,
            'userid'       => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quizaccess_sanad_violations} vio ON vio.quizid = cm.instance
                 WHERE vio.userid = :userid";
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param  userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $params = ['instanceid' => $context->instanceid];

        $sql = "SELECT ses.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quizaccess_sanad_sessions} ses ON ses.quizid = cm.instance
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT vio.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quizaccess_sanad_violations} vio ON vio.quizid = cm.instance
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param  approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT c.id AS contextid, ses.*
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quizaccess_sanad_sessions} ses ON ses.quizid = cm.instance
                 WHERE c.id {$contextsql}
                   AND ses.userid = :userid";

        $params = ['modname' => 'quiz', 'contextlevel' => CONTEXT_MODULE, 'userid' => $userid] + $contextparams;

        $sessions = $DB->get_recordset_sql($sql, $params);
        foreach ($sessions as $session) {
            $context = \context::instance_by_id($session->contextid);
            $data = (object)[
                'token' => $session->token,
                'deviceid' => $session->deviceid,
                'timecreated' => \core_privacy\local\request\transform::datetime($session->timecreated),
                'timeexpires' => \core_privacy\local\request\transform::datetime($session->timeexpires),
            ];
            \core_privacy\local\request\writer::with_context($context)
                ->export_data([get_string('pluginname', 'quizaccess_sanad_lockdown'), 'sessions', $session->id], $data);
        }
        $sessions->close();

        $sql = "SELECT c.id AS contextid, vio.*
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quizaccess_sanad_violations} vio ON vio.quizid = cm.instance
                 WHERE c.id {$contextsql}
                   AND vio.userid = :userid";

        $violations = $DB->get_recordset_sql($sql, $params);
        foreach ($violations as $violation) {
            $context = \context::instance_by_id($violation->contextid);
            $data = (object)[
                'violationtype' => $violation->violationtype,
                'deviceid' => $violation->deviceid,
                'details' => $violation->details,
                'timecreated' => \core_privacy\local\request\transform::datetime($violation->timecreated),
            ];
            \core_privacy\local\request\writer::with_context($context)
                ->export_data([get_string('pluginname', 'quizaccess_sanad_lockdown'), 'violations', $violation->id], $data);
        }
        $violations->close();
    }

    /**
     * Delete all use data which matches the specified context.
     *
     * @param  \context $context A user context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        if ($cm = get_coursemodule_from_id('quiz', $context->instanceid)) {
            $DB->delete_records('quizaccess_sanad_sessions', ['quizid' => $cm->instance]);
            $DB->delete_records('quizaccess_sanad_violations', ['quizid' => $cm->instance]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param  approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT cm.instance
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE c.id {$contextsql}";

        $params = ['modname' => 'quiz', 'contextlevel' => CONTEXT_MODULE] + $contextparams;
        $quizids = $DB->get_fieldset_sql($sql, $params);

        if (empty($quizids)) {
            return;
        }

        [$quizsql, $quizparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED);
        $delparams = ['userid' => $userid] + $quizparams;

        $DB->delete_records_select('quizaccess_sanad_sessions', "userid = :userid AND quizid {$quizsql}", $delparams);
        $DB->delete_records_select('quizaccess_sanad_violations', "userid = :userid AND quizid {$quizsql}", $delparams);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param  approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['quizid' => $cm->instance] + $inparams;

        $DB->delete_records_select('quizaccess_sanad_sessions', "quizid = :quizid AND userid {$insql}", $params);
        $DB->delete_records_select('quizaccess_sanad_violations', "quizid = :quizid AND userid {$insql}", $params);
    }
}
