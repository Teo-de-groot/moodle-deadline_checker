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

/**
 * Everything with a date on it, wherever it came from.
 *
 * A learner does not think of "course deadlines" and "my deadlines" as two lists, so the block
 * does not show two. The only place the difference surfaces is in what may be done to a row: a
 * course's dates are the course's to change, a personal one is the learner's.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class merged_task_source {

    /**
     * Every deadline a learner has, from their courses and from their own list.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int|null $userid Learner to report on; defaults to the current user.
     * @return task[] Unsorted; the presenter decides the order.
     */
    public static function tasks(int $now, ?int $userid = null): array {
        return array_merge(
            course_task_source::tasks($now, $userid),
            personal_task_source::tasks($now, $userid),
        );
    }
}
