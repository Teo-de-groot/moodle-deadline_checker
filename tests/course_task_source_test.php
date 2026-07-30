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
 * Tests for the course-backed data source.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\course_task_source
 */
final class course_task_source_test extends \advanced_testcase {

    /**
     * A deadline is an activity in one of the learner's own courses, with a date of its own.
     */
    public function test_only_deadlines_from_the_learners_own_courses(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $now = time();
        $due = $now + 3 * DAYSECS;

        $course = $generator->create_course(['fullname' => 'Operational leadership', 'enablecompletion' => 1]);
        $elsewhere = $generator->create_course(['enablecompletion' => 1]);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reflective log 3',
            'duedate' => $due,
        ]);
        // A course the learner is not on, and an activity with no date: neither is a deadline
        // of theirs.
        $generator->create_module('assign', ['course' => $elsewhere->id, 'duedate' => $due]);
        $generator->create_module('assign', ['course' => $course->id, 'duedate' => 0]);

        $tasks = course_task_source::tasks($now, (int) $student->id);

        $this->assertCount(1, $tasks);

        $task = reset($tasks);
        $this->assertSame('Reflective log 3', $task->name);
        $this->assertSame((string) $course->id, $task->courseid);
        $this->assertSame('Operational leadership', $task->coursename);
        $this->assertSame($due, $task->due);
        $this->assertSame(3, $task->daydiff);
        $this->assertFalse($task->complete);
        $this->assertStringContainsString('/mod/assign/view.php?id=' . $assign->cmid, (string) $task->url);

        // Someone enrolled on nothing has no deadlines rather than everyone else's.
        $this->assertSame([], course_task_source::tasks($now, (int) $generator->create_user()->id));
    }

    /**
     * Done means what activity completion says it means.
     */
    public function test_completion_comes_from_activity_completion(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $now + DAYSECS,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $tasks = course_task_source::tasks($now, (int) $student->id);
        $this->assertFalse(reset($tasks)->complete);

        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $completion = new \completion_info(get_course($course->id));
        $completion->update_state(\cm_info::create($cm, $student->id), COMPLETION_COMPLETE, $student->id);

        $tasks = course_task_source::tasks($now, (int) $student->id);
        $this->assertTrue(reset($tasks)->complete);
    }

    /**
     * Only manually tracked activities can be marked complete from the block.
     *
     * An activity that completes itself from a view, a submission or a grade is the course's
     * business: the block reports its state and offers no button.
     */
    public function test_only_manual_completion_can_be_toggled(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $now = time();
        $due = $now + DAYSECS;

        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $manual = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $due,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $automatic = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $due,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
        ]);
        $untracked = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $due,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $tasks = [];
        foreach (course_task_source::tasks($now, (int) $student->id) as $task) {
            $tasks[$task->cmid] = $task;
        }

        $this->assertCount(3, $tasks);
        $this->assertTrue($tasks[$manual->cmid]->manualcompletion);
        $this->assertFalse($tasks[$automatic->cmid]->manualcompletion);
        $this->assertFalse($tasks[$untracked->cmid]->manualcompletion);

        // The cmid is what the browser records completion against, so it has to be the real one.
        $this->assertSame((int) $manual->cmid, $tasks[$manual->cmid]->cmid);
        $this->assertSame('cm' . $manual->cmid, $tasks[$manual->cmid]->id);
    }

    /**
     * A hidden activity is not a deadline the learner can act on, so it is not listed.
     */
    public function test_activities_the_learner_cannot_see_are_left_out(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $now + DAYSECS,
            'visible' => 0,
        ]);

        $this->assertSame([], course_task_source::tasks($now, (int) $student->id));
    }

    /**
     * An activity the learner is restricted out of is not listed either.
     *
     * Access restrictions are a form of not being able to see it, the same as hiding, so the
     * block has to respect them rather than announcing a deadline for something out of reach.
     */
    public function test_activities_restricted_from_the_learner_are_left_out(): void {
        $this->resetAfterTest();
        set_config('enableavailability', 1);

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        // Not available until next week, and hidden entirely until then.
        $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => $now + 2 * WEEKSECS,
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'date', 'd' => '>=', 't' => $now + WEEKSECS]],
                'showc' => [false],
            ]),
        ]);

        $this->assertSame([], course_task_source::tasks($now, (int) $student->id));
    }

    /**
     * A suspended enrolment is not a current course, so its deadlines are not the learner's.
     */
    public function test_a_suspended_enrolment_shows_no_deadlines(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, null, 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $generator->create_module('assign', ['course' => $course->id, 'duedate' => $now + DAYSECS]);

        $this->assertSame([], course_task_source::tasks($now, (int) $student->id));
    }

    /**
     * A hidden course is listed only for the people allowed to see hidden courses.
     */
    public function test_a_hidden_course_is_listed_only_for_those_who_may_see_it(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $now = time();

        $course = $generator->create_course(['visible' => 0]);
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $generator->create_module('assign', ['course' => $course->id, 'duedate' => $now + DAYSECS]);

        $this->assertSame([], course_task_source::tasks($now, (int) $student->id));
        $this->assertCount(1, course_task_source::tasks($now, (int) $teacher->id));
    }

    /**
     * An override granted to this learner is their deadline; other learners keep the course date.
     */
    public function test_a_date_override_for_the_learner_wins(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $now = time();
        $due = $now + 2 * DAYSECS;
        $extended = $now + 9 * DAYSECS;

        $course = $generator->create_course();
        $student = $generator->create_user();
        $classmate = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);
        $generator->enrol_user($classmate->id, $course->id);

        $assign = $generator->create_module('assign', ['course' => $course->id, 'duedate' => $due]);

        $generator->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'userid' => $student->id,
            'duedate' => $extended,
        ]);

        $tasks = course_task_source::tasks($now, (int) $student->id);
        $this->assertCount(1, $tasks);
        $this->assertSame($extended, reset($tasks)->due);

        $tasks = course_task_source::tasks($now, (int) $classmate->id);
        $this->assertCount(1, $tasks);
        $this->assertSame($due, reset($tasks)->due);
    }
}
