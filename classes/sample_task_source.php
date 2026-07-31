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
 * Sample deadlines, standing in for real course data.
 *
 * This is the only place that invents data. It mirrors the dataset in the design prototype so
 * the handoff's test cases are reproducible: a task about forty minutes out, one three days
 * overdue, a course whose tasks are all complete, and enough rows to page through.
 *
 * The block no longer renders these: it reads the learner's own courses through
 * {@see course_task_source}. The dataset stays because it is the definition of the placeholder
 * courses and activities that cli/create_test_data.php writes into the database, and because
 * the design's test cases are pinned to it.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sample_task_source {

    /**
     * Sample courses, keyed by the identifier used in the course filter.
     *
     * @return string[] Course identifier => course name.
     */
    public static function courses(): array {
        return [
            'ol' => get_string('courseol', 'block_deadline_checker'),
            'ip' => get_string('courseip', 'block_deadline_checker'),
            'fm' => get_string('coursefm', 'block_deadline_checker'),
            'ad' => get_string('coursead', 'block_deadline_checker'),
        ];
    }

    /**
     * The sample deadlines.
     *
     * Due dates are offsets from today so the block demonstrates every urgency state whenever
     * it is opened, rather than decaying into a list of overdue tasks.
     *
     * @param int $now Current time as a unix timestamp.
     * @return task[] Unsorted; the presenter decides the order.
     */
    public static function tasks(int $now): array {
        $courses = self::courses();

        // Identifier, name string, course, days from today, time of day, complete.
        $rows = [
            ['t1', 'sampletaskreflectivelog3', 'ol', -3, '17:00', false],
            ['t2', 'sampletaskprojectbrief', 'ip', 0, '17:00', false],
            ['t3', 'sampletaskunit3quiz', 'fm', 0, null, false],
            ['t4', 'sampletaskpeerreview', 'ol', 1, '09:00', false],
            ['t5', 'sampletaskotjjuly', 'ad', 4, '23:59', false],
            ['t6', 'sampletaskassessment4', 'fm', 8, '12:00', false],
            ['t7', 'sampletaskepareadiness', 'ol', 20, '17:00', false],
            ['t8', 'sampletaskobservation', 'ol', 26, '12:00', false],
            ['t9', 'sampletaskbudgetvariance', 'fm', 31, '23:59', false],
            ['t10', 'sampletaskreflectivelog2', 'ol', -18, '17:00', true],
            ['t11', 'sampletaskstakeholdermap', 'ip', -12, '17:00', true],
            ['t12', 'sampletaskotjjune', 'ad', -9, '23:59', true],
            ['t13', 'sampletaskassessment3', 'ol', -25, '12:00', true],
        ];

        $tasks = [];
        foreach ($rows as [$id, $namekey, $courseid, $days, $time, $complete]) {
            // The quiz has no fixed time: it is always twenty minutes out, which is the handoff's
            // sub-hour test case. Under half an hour rather than the forty minutes it used to be,
            // because the pill rounds to the nearest hour and forty minutes now reads "1h left".
            $due = $time === null
                ? $now + 20 * MINSECS
                : self::at($now, $days, $time);

            $tasks[] = new task(
                $id,
                get_string($namekey, 'block_deadline_checker'),
                $courseid,
                $courses[$courseid],
                $due,
                self::day_difference($now, $due),
                $complete,
            );
        }

        return $tasks;
    }

    /**
     * Timestamp for a time of day, a given number of days from today, in the learner's timezone.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int $days Days from today; negative is in the past.
     * @param string $time Time of day as "HH:MM".
     * @return int Unix timestamp.
     */
    private static function at(int $now, int $days, string $time): int {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return usergetmidnight($now) + $days * DAYSECS + $hours * HOURSECS + $minutes * MINSECS;
    }

    /**
     * Whole calendar days between today and a due date, in the learner's timezone.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int $due Due date as a unix timestamp.
     * @return int Negative when the due date has passed.
     */
    public static function day_difference(int $now, int $due): int {
        return calendar_days::between($now, $due);
    }
}
