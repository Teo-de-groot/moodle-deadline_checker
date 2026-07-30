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
 * Calendar-day arithmetic in the learner's own timezone.
 *
 * Shared by every data source, so a deadline is the same number of days away however the task
 * reached the block.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_days {

    /**
     * Whole calendar days between today and a due date, in the learner's timezone.
     *
     * Calendar days rather than 24 hour blocks, so a deadline at 09:00 tomorrow counts as one
     * day away even though it is only sixteen hours off. Rounded because daylight saving makes
     * some days 23 or 25 hours long.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int $due Due date as a unix timestamp.
     * @return int Negative when the due date has passed.
     */
    public static function between(int $now, int $due): int {
        return (int) round((usergetmidnight($due) - usergetmidnight($now)) / DAYSECS);
    }
}
