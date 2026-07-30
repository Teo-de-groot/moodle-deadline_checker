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

use block_deadline_checker\output\deadlines;

/**
 * Tests for the presenter.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\output\deadlines
 */
final class deadlines_test extends \advanced_testcase {

    /**
     * The course filter lists the courses, and identifies them exactly as the tasks do.
     *
     * Real course ids are numeric strings, and a numeric string used as a PHP array key becomes
     * an integer. When it did, the filter compared 2 against "2", found nothing in either the
     * markup or the browser's payload, and offered nothing but All courses.
     */
    public function test_course_filter_lists_courses_by_the_same_id_the_tasks_use(): void {
        global $PAGE;

        $this->resetAfterTest();

        $now = time();
        $due = $now + 2 * DAYSECS;

        $tasks = [
            new task('cm1', 'Reflective log 3', '2', 'Operational leadership', $due, 2, false),
            new task('cm2', 'Project brief', '13', 'Improvement projects', $due, 2, false),
            // Second task in a course already listed: the filter offers each course once.
            new task('cm3', 'Peer review', '2', 'Operational leadership', $due, 2, false),
            // Complete, and the only task in its course, so that course is not in the To do view.
            new task('cm4', 'Stakeholder map', '7', 'Finance for managers', $due, 2, true),
        ];

        $renderable = new deadlines($tasks, $now, 5, 'dlblock-1');
        $context = $renderable->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame([
            ['value' => '2', 'label' => 'Operational leadership', 'selected' => false],
            ['value' => '13', 'label' => 'Improvement projects', 'selected' => false],
        ], $context['courseoptions']);

        // The browser filters by comparing these against each task's courseid, so they have to be
        // the same strings, not numbers that merely look like them.
        $this->assertSame([
            ['id' => '2', 'name' => 'Operational leadership'],
            ['id' => '13', 'name' => 'Improvement projects'],
            ['id' => '7', 'name' => 'Finance for managers'],
        ], $renderable->js_payload()['courses']);
    }

    /**
     * The block only offers a completion button for tasks the learner is allowed to mark.
     *
     * The button writes to core's manual completion service, so offering it for an activity that
     * completes itself would promise something the server would refuse.
     */
    public function test_only_manually_tracked_tasks_offer_the_button(): void {
        global $PAGE;

        $this->resetAfterTest();

        $now = time();
        $due = $now + 2 * DAYSECS;

        $manual = new task('cm1', 'Reflective log 3', '2', 'Operational leadership',
                           $due, 2, false, null, 1, true);
        $automatic = new task('cm2', 'Unit 3 quiz', '2', 'Operational leadership',
                              $due, 2, false, null, 2, false);

        $renderable = new deadlines([$manual, $automatic], $now, 5, 'dlblock-1');
        $context = $renderable->export_for_template($PAGE->get_renderer('core'));

        $rows = [];
        foreach ($context['list']['rows'] as $row) {
            $rows[$row['id']] = $row;
        }

        $this->assertTrue($rows['cm1']['cantoggle']);
        $this->assertFalse($rows['cm2']['cantoggle']);

        // The browser gets the same answer, plus the id it needs to act on.
        $payload = [];
        foreach ($renderable->js_payload()['tasks'] as $task) {
            $payload[$task['id']] = $task;
        }

        $this->assertTrue($payload['cm1']['cantoggle']);
        $this->assertSame(1, $payload['cm1']['cmid']);
        $this->assertFalse($payload['cm2']['cantoggle']);
    }
}
