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

declare(strict_types=1);

namespace block_deadline_checker\privacy;

use block_deadline_checker\personal_task_repository;
use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy for the deadlines a learner keeps for themselves.
 *
 * The block reads course deadlines and activity completion, but stores neither: those belong to the
 * calendar and to core completion, which report on them themselves. What this plugin holds is one
 * table of deadlines learners have written down, so everything below is about that table.
 *
 * The data sits in the user context, because a personal deadline belongs to the learner rather than
 * to the course it happens to be filed under. Deleting a user's data therefore removes their whole
 * list, whichever courses the rows named.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe what is stored.
     *
     * @param collection $collection Collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(personal_task_repository::TABLE, [
            'userid' => 'privacy:metadata:task:userid',
            'courseid' => 'privacy:metadata:task:courseid',
            'name' => 'privacy:metadata:task:name',
            'due' => 'privacy:metadata:task:due',
            'timecompleted' => 'privacy:metadata:task:timecompleted',
            'timecreated' => 'privacy:metadata:task:timecreated',
            'timemodified' => 'privacy:metadata:task:timemodified',
        ], 'privacy:metadata:task');

        return $collection;
    }

    /**
     * Contexts holding data for a user: their own user context, when they have any deadlines.
     *
     * @param int $userid User to look for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        if ($DB->record_exists(personal_task_repository::TABLE, ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist Userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_user) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . personal_task_repository::TABLE . '}
                                            WHERE userid = :userid', ['userid' => $context->instanceid]);
    }

    /**
     * Export a user's deadlines.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            // Only ever this user's own context: a personal deadline is not exported to anyone else.
            if (!$context instanceof context_user || (int) $context->instanceid !== $userid) {
                continue;
            }

            $records = $DB->get_records(personal_task_repository::TABLE, ['userid' => $userid], 'due ASC');
            $data = [];

            foreach ($records as $record) {
                $data[] = (object) [
                    'name' => $record->name,
                    'course' => empty($record->courseid)
                        ? get_string('nocourse', 'block_deadline_checker')
                        : $DB->get_field('course', 'fullname', ['id' => $record->courseid], IGNORE_MISSING),
                    'due' => transform::datetime((int) $record->due),
                    'completed' => empty($record->timecompleted)
                        ? get_string('no')
                        : transform::datetime((int) $record->timecompleted),
                    'timecreated' => transform::datetime((int) $record->timecreated),
                    'timemodified' => transform::datetime((int) $record->timemodified),
                ];
            }

            if (empty($data)) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_deadline_checker')],
                (object) ['deadlines' => $data]
            );
        }
    }

    /**
     * Delete every deadline in a context.
     *
     * @param context $context The context.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_user) {
            return;
        }

        $DB->delete_records(personal_task_repository::TABLE, ['userid' => $context->instanceid]);
    }

    /**
     * Delete a user's deadlines.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && (int) $context->instanceid === $userid) {
                $DB->delete_records(personal_task_repository::TABLE, ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete the deadlines of several users in a context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof context_user) {
            return;
        }

        $userids = $userlist->get_userids();

        // The context names the only user whose rows live here, so anyone else on the list has
        // nothing in it to delete.
        if (in_array((int) $context->instanceid, array_map('intval', $userids), true)) {
            $DB->delete_records(personal_task_repository::TABLE, ['userid' => $context->instanceid]);
        }
    }
}
