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
 * Tests for showing course deadlines and the learner's own as one list.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\merged_task_source
 */
final class merged_task_source_test extends \advanced_testcase {

    /**
     * Both kinds of deadline appear, and only the learner's own is editable.
     *
     * This is the distinction the whole feature rests on: a course's date is the course's to change,
     * so the block must never offer to change it.
     */
    public function test_both_kinds_appear_and_only_the_learners_own_is_editable(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $now = time();
        $due = $now + 3 * DAYSECS;

        $course = $generator->create_course([
            'fullname' => 'Operational leadership',
            'enablecompletion' => 1,
        ]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reflective log 3',
            'duedate' => $due,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        personal_task_repository::create('Ring the assessor', $due + DAYSECS, (int) $course->id);

        $tasks = merged_task_source::tasks($now);

        $byname = [];
        foreach ($tasks as $task) {
            $byname[$task->name] = $task;
        }

        $this->assertCount(2, $tasks);
        $this->assertArrayHasKey('Reflective log 3', $byname);
        $this->assertArrayHasKey('Ring the assessor', $byname);

        // The course's own date: readable, tickable, but not the learner's to edit or remove.
        $this->assertFalse($byname['Reflective log 3']->is_personal());
        $this->assertNull($byname['Reflective log 3']->personalid);
        $this->assertNotNull($byname['Reflective log 3']->cmid);

        // Theirs: editable, and with no course module behind it.
        $this->assertTrue($byname['Ring the assessor']->is_personal());
        $this->assertNotNull($byname['Ring the assessor']->personalid);
        $this->assertNull($byname['Ring the assessor']->cmid);

        // Filed under the same course, so the filter groups them rather than splitting them.
        $this->assertSame($byname['Reflective log 3']->courseid, $byname['Ring the assessor']->courseid);
    }

    /**
     * A learner with courses but no list of their own still sees their course deadlines.
     */
    public function test_a_learner_with_no_list_of_their_own_still_sees_their_courses(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reflective log 3',
            'duedate' => $now + DAYSECS,
        ]);

        $this->assertSame(['Reflective log 3'],
            array_map(fn(task $t) => $t->name, merged_task_source::tasks($now)));
    }

    /**
     * A learner on no courses still sees their own list.
     */
    public function test_a_learner_on_no_courses_still_sees_their_own_list(): void {
        $this->resetAfterTest();

        $this->setUser($this->getDataGenerator()->create_user());
        personal_task_repository::create('Ring the assessor', time() + DAYSECS);

        $this->assertSame(['Ring the assessor'],
            array_map(fn(task $t) => $t->name, merged_task_source::tasks(time())));
    }
}
