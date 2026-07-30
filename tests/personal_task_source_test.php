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
 * Tests for reading back a learner's own deadlines.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\personal_task_source
 */
final class personal_task_source_test extends \advanced_testcase {

    /**
     * A stored deadline comes back as a task the rest of the block can handle.
     */
    public function test_a_stored_deadline_becomes_a_task(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $now = time();
        $due = $now + 3 * DAYSECS;
        $id = personal_task_repository::create('Reflective log 3', $due);

        $tasks = personal_task_source::tasks($now);

        $this->assertCount(1, $tasks);
        $task = reset($tasks);

        $this->assertSame('Reflective log 3', $task->name);
        $this->assertSame($due, $task->due);
        $this->assertSame(3, $task->daydiff);
        $this->assertFalse($task->complete);
        // Their own, so theirs to change, and theirs to tick off.
        $this->assertSame($id, $task->personalid);
        $this->assertTrue($task->is_personal());
        $this->assertTrue($task->manualcompletion);
        // Nothing to link to, and no course module behind it.
        $this->assertNull($task->url);
        $this->assertNull($task->cmid);
    }

    /**
     * A deadline with no course is filed under a label of its own rather than a course id.
     *
     * The identifier is a word, so it can never collide with a real course id in the filter.
     */
    public function test_a_deadline_with_no_course_gets_its_own_filter_identifier(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $tasks = personal_task_source::tasks(time());
        $task = reset($tasks);

        $this->assertSame(personal_task_source::NO_COURSE, $task->courseid);
        $this->assertSame(get_string('nocourse', 'block_deadline_checker'), $task->coursename);
        $this->assertFalse(is_numeric($task->courseid));
    }

    /**
     * A deadline filed under a course carries that course's id, so it groups with the course's own
     * dates in the filter rather than sitting in a list of its own.
     */
    public function test_a_deadline_in_a_course_uses_the_courses_own_identifier(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Operational leadership']);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $course->id);

        $tasks = personal_task_source::tasks(time());
        $task = reset($tasks);

        // A string, matching what course_task_source produces, because the browser compares these
        // by identity.
        $this->assertSame((string) $course->id, $task->courseid);
        $this->assertSame('Operational leadership', $task->coursename);
    }

    /**
     * A deadline outlives the enrolment it was filed under.
     *
     * The deadline is still the learner's, so it is still shown — but it stops naming a course they
     * can no longer see, which would be telling them about a course they have lost access to.
     */
    public function test_a_deadline_stops_naming_a_course_the_learner_has_left(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Operational leadership']);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $course->id);

        // Off the course, but the deadline stays.
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol',
            ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, $user->id);

        $tasks = personal_task_source::tasks(time());

        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame('Reflective log 3', $task->name);
        $this->assertSame(personal_task_source::NO_COURSE, $task->courseid);
        $this->assertSame(get_string('nocourse', 'block_deadline_checker'), $task->coursename);
        $this->assertStringNotContainsString('Operational leadership', $task->coursename);
    }

    /**
     * Done means the stored completion date, which is what the learner set.
     */
    public function test_completion_comes_from_the_stored_date(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $before = personal_task_source::tasks(time());
        $this->assertFalse(reset($before)->complete);

        personal_task_repository::set_completion($id, true);

        $after = personal_task_source::tasks(time());
        $this->assertTrue(reset($after)->complete);
    }

    /**
     * A learner sees their own deadlines and nobody else's.
     */
    public function test_one_learner_never_sees_anothers_deadlines(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Mine', time() + DAYSECS);

        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $now = time();

        $this->assertSame(['Theirs'], array_map(fn($t) => $t->name, personal_task_source::tasks($now)));
        $this->assertSame(['Mine'],
            array_map(fn($t) => $t->name, personal_task_source::tasks($now, (int) $owner->id)));
    }

    /**
     * Guests have no list of their own, and must not be shown anyone else's.
     */
    public function test_a_guest_has_no_deadlines(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $this->setGuestUser();

        $this->assertSame([], personal_task_source::tasks(time()));
    }

    /**
     * Someone with nothing on their list has an empty list, not an error.
     */
    public function test_no_deadlines_is_an_empty_list(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], personal_task_source::tasks(time()));
    }
}
