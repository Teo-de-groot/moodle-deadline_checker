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

namespace block_deadline_checker;

use context_course;

/**
 * The deadlines a learner has added for themselves, read back for display.
 *
 * The reading counterpart of {@see personal_task_repository}: this turns stored rows into the same
 * {@see task} objects the course source produces, so everything downstream — sorting, urgency
 * pills, accessible names, paging — treats both kinds of deadline identically. The one difference
 * a task carries is its personal row id, which is what lets the block offer Edit and Delete on
 * these and not on a course's own dates.
 *
 * A deadline a learner wrote for themselves belongs to no course, so every one of these reports the
 * same filter identifier and groups together. Rows written before the course field was removed may
 * still carry a courseid; it is not read, so an old deadline stops naming a course rather than
 * being the one row that behaves differently from the rest.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personal_task_source {

    /**
     * Course filter identifier used by deadlines that belong to no course.
     *
     * A word rather than a number, so it can never collide with a real course id.
     *
     * @var string
     */
    public const NO_COURSE = 'personal';

    /**
     * Every deadline the learner has added for themselves.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int|null $userid Learner to report on; defaults to the current user.
     * @return task[] Unsorted; the presenter decides the order.
     */
    public static function tasks(int $now, ?int $userid = null): array {
        global $USER;

        $userid = $userid ?? (int) $USER->id;

        // Guests share an account, so they have no deadlines of their own to read.
        if (empty($userid) || isguestuser($userid)) {
            return [];
        }

        $records = personal_task_repository::all_for_user($userid);

        if (empty($records)) {
            return [];
        }

        $nocourse = get_string('nocourse', 'block_deadline_checker');
        $tasks = [];

        foreach ($records as $record) {
            $tasks[] = new task(
                'p' . (int) $record->id,
                format_string($record->name, true, ['context' => context_course::instance(SITEID)]),
                self::NO_COURSE,
                $nocourse,
                (int) $record->due,
                calendar_days::between($now, (int) $record->due),
                (int) $record->timecompleted > 0,
                // Nothing to link to: the learner wrote this down, there is no activity behind it.
                null,
                null,
                // Their own deadline, so their own call whether it is done.
                true,
                (int) $record->id,
            );
        }

        return $tasks;
    }
}
