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
 * Tests for the sample data source.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\sample_task_source
 */
final class sample_task_source_test extends \advanced_testcase {

    /**
     * Day differences are calendar days in the learner's timezone, not 24 hour blocks.
     *
     * A deadline at 09:00 tomorrow is one day away even when only ten hours separate them,
     * which is what stops it being described as "13h left".
     */
    public function test_day_difference_counts_calendar_days(): void {
        $this->resetAfterTest();
        // Both arguments, so the user default is UTC too: usergetmidnight reads the user's zone.
        $this->setTimezone('UTC', 'UTC');

        $now = make_timestamp(2026, 1, 1, 23, 0, 0, 'UTC');

        $this->assertSame(0, sample_task_source::day_difference($now,
            make_timestamp(2026, 1, 1, 23, 59, 0, 'UTC')));
        $this->assertSame(1, sample_task_source::day_difference($now,
            make_timestamp(2026, 1, 2, 9, 0, 0, 'UTC')));
        $this->assertSame(-1, sample_task_source::day_difference($now,
            make_timestamp(2025, 12, 31, 23, 59, 0, 'UTC')));
        $this->assertSame(-3, sample_task_source::day_difference($now,
            make_timestamp(2025, 12, 29, 17, 0, 0, 'UTC')));
    }

    /**
     * The dataset must keep reproducing the handoff's test cases whenever the block is opened.
     */
    public function test_sample_dataset_covers_the_design_test_cases(): void {
        $this->resetAfterTest();
        // Both arguments, so the user default is UTC too: usergetmidnight reads the user's zone.
        $this->setTimezone('UTC', 'UTC');

        // Mid-morning, so the sub-hour task cannot spill over midnight into tomorrow.
        $now = make_timestamp(2026, 1, 15, 10, 0, 0, 'UTC');
        $tasks = sample_task_source::tasks($now);

        $this->assertCount(13, $tasks);

        $bands = [];
        foreach ($tasks as $task) {
            $bands[] = time_remaining::stamp($task, $now)['variant'];
        }

        // Every urgency band the design defines is represented.
        foreach (['done', 'overdue', 'urgent', 'soon', 'far'] as $variant) {
            $this->assertContains($variant, $bands, "No sample task lands in the {$variant} band");
        }

        // The sub-hour case: about forty minutes out, so the pill reads "<1 hour".
        $quiz = array_values(array_filter($tasks, fn(task $t) => $t->id === 't3'))[0];
        $this->assertSame('<1 hour', time_remaining::stamp($quiz, $now)['text']);

        // All four courses start with something open, so the To do filter lists all four. The
        // handoff's "course absent from the filter" case is reached by completing the one open
        // task in Apprenticeship admin, not from the seed data on its own.
        $open = array_map(fn(task $t) => $t->courseid, array_filter($tasks, fn(task $t) => !$t->complete));

        foreach (array_keys(sample_task_source::courses()) as $courseid) {
            $this->assertContains($courseid, $open, "Course {$courseid} has nothing open to show");
        }

        $this->assertCount(1, array_filter($tasks, fn(task $t) => $t->courseid === 'ad' && !$t->complete));
    }
}
