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

use core\exception\moodle_exception;

/**
 * Tests for creating, changing and removing a learner's own deadlines.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\personal_task_repository
 */
final class personal_task_repository_test extends \advanced_testcase {

    /**
     * A deadline is stored against its owner, with the values it was given.
     */
    public function test_create_stores_the_deadline(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $due = time() + 3 * DAYSECS;
        $id = personal_task_repository::create('Reflective log 3', $due);

        $record = $DB->get_record(personal_task_repository::TABLE, ['id' => $id]);

        $this->assertSame('Reflective log 3', $record->name);
        $this->assertSame($due, (int) $record->due);
        $this->assertSame((int) $user->id, (int) $record->userid);
        $this->assertNull($record->courseid);
        // Not complete, and both timestamps set.
        $this->assertSame(0, (int) $record->timecompleted);
        $this->assertGreaterThan(0, (int) $record->timecreated);
        $this->assertGreaterThan(0, (int) $record->timemodified);
    }

    /**
     * A name has to say something. Whitespace of any kind is not a name.
     *
     * @dataProvider empty_name_provider
     * @param string $name The name to try.
     */
    public function test_create_rejects_an_empty_name(string $name): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(moodle_exception::class);
        personal_task_repository::create($name, time() + DAYSECS);
    }

    /**
     * Names that are not names.
     *
     * @return array[]
     */
    public static function empty_name_provider(): array {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'tab and newline' => ["\t\n"],
            // A name made only of non-breaking spaces looks filled in and is not.
            'non-breaking space' => ["\u{00a0}\u{00a0}"],
        ];
    }

    /**
     * A name longer than the column would be truncated by the database, so it is refused instead.
     */
    public function test_create_rejects_a_name_that_would_not_fit(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $toolong = str_repeat('a', personal_task_repository::NAME_MAX_LENGTH + 1);

        $this->expectException(moodle_exception::class);
        personal_task_repository::create($toolong, time() + DAYSECS);
    }

    /**
     * A name that exactly fills the column is fine, and is stored whole.
     */
    public function test_create_accepts_a_name_that_exactly_fits(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $name = str_repeat('a', personal_task_repository::NAME_MAX_LENGTH);
        $id = personal_task_repository::create($name, time() + DAYSECS);

        $this->assertSame($name, $DB->get_field(personal_task_repository::TABLE, 'name', ['id' => $id]));
    }

    /**
     * A deadline needs a date.
     */
    public function test_create_rejects_a_missing_due_date(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(moodle_exception::class);
        personal_task_repository::create('Reflective log 3', 0);
    }

    /**
     * A date in the past is allowed: a learner may be recording something they have already missed,
     * and the block has an overdue state for exactly that.
     */
    public function test_create_allows_a_date_in_the_past(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $due = time() - 5 * DAYSECS;
        $id = personal_task_repository::create('Reflective log 3', $due);

        $this->assertSame($due, (int) personal_task_repository::get_own($id)->due);
    }

    /**
     * A deadline can be filed under a course the learner is on.
     */
    public function test_create_accepts_a_course_the_learner_is_enrolled_on(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $course->id);

        $this->assertSame((int) $course->id, (int) personal_task_repository::get_own($id)->courseid);
    }

    /**
     * A deadline cannot be filed under a course the learner is not on.
     *
     * Both because it would end up somewhere they never look, and because being able to attach a
     * row to any id would say whether that course exists.
     */
    public function test_create_refuses_a_course_the_learner_is_not_on(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $elsewhere = $generator->create_course();
        $this->setUser($generator->create_user());

        $this->expectException(moodle_exception::class);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $elsewhere->id);
    }

    /**
     * Editing changes the values and leaves the owner alone.
     */
    public function test_update_changes_the_deadline(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Operational leadership']);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $id = personal_task_repository::create('Draft', time() + DAYSECS);
        $newdue = time() + 9 * DAYSECS;

        personal_task_repository::update($id, 'Reflective log 3', $newdue, (int) $course->id);

        $record = personal_task_repository::get_own($id);
        $this->assertSame('Reflective log 3', $record->name);
        $this->assertSame($newdue, (int) $record->due);
        $this->assertSame((int) $course->id, (int) $record->courseid);
        $this->assertSame((int) $user->id, (int) $record->userid);
    }

    /**
     * Editing can put a deadline back to belonging to no course.
     */
    public function test_update_can_clear_the_course(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $course->id);
        personal_task_repository::update($id, 'Reflective log 3', time() + DAYSECS, null);

        $this->assertNull(personal_task_repository::get_own($id)->courseid);
    }

    /**
     * Ticking a deadline off records when, and reopening it clears that.
     */
    public function test_set_completion_records_and_clears(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        personal_task_repository::set_completion($id, true);
        $completed = (int) personal_task_repository::get_own($id)->timecompleted;
        $this->assertGreaterThan(0, $completed);

        personal_task_repository::set_completion($id, false);
        $this->assertSame(0, (int) personal_task_repository::get_own($id)->timecompleted);
    }

    /**
     * Ticking something already complete must not move the date it was completed on.
     */
    public function test_set_completion_does_not_rewrite_an_existing_completion_date(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        personal_task_repository::set_completion($id, true);

        // Backdate it, as though it had been ticked off last week.
        $backdated = time() - 7 * DAYSECS;
        $DB->set_field(personal_task_repository::TABLE, 'timecompleted', $backdated, ['id' => $id]);

        personal_task_repository::set_completion($id, true);

        $this->assertSame($backdated, (int) personal_task_repository::get_own($id)->timecompleted);
    }

    /**
     * Removing a deadline removes it.
     */
    public function test_delete_removes_the_deadline(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        personal_task_repository::delete($id);

        $this->assertFalse($DB->record_exists(personal_task_repository::TABLE, ['id' => $id]));
    }

    /**
     * One learner cannot read, change, tick off or remove another's deadline.
     *
     * The four write paths and the read are checked together because they share one guard: if it
     * ever stopped scoping to the caller, every one of these would start succeeding at once.
     *
     * @dataProvider other_users_row_provider
     * @param string $method Repository method to call.
     * @param array $extra Arguments between the id and the owner.
     */
    public function test_another_learners_deadline_is_out_of_reach(string $method, array $extra): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $this->setUser($other);

        try {
            personal_task_repository::$method($id, ...$extra);
            $this->fail("{$method}() reached another learner's deadline");
        } catch (moodle_exception $e) {
            // Expected: scoped to the caller, so this is indistinguishable from a missing row.
            $this->assertInstanceOf(moodle_exception::class, $e);
        }

        // Still there, and still the owner's.
        $record = $DB->get_record(personal_task_repository::TABLE, ['id' => $id]);
        $this->assertNotFalse($record);
        $this->assertSame((int) $owner->id, (int) $record->userid);
        $this->assertSame('Reflective log 3', $record->name);
    }

    /**
     * Every way of reaching a row, for the ownership check.
     *
     * @return array[]
     */
    public static function other_users_row_provider(): array {
        return [
            'read' => ['get_own', []],
            'edit' => ['update', ['Hijacked', 1893456000]],
            'delete' => ['delete', []],
            'tick off' => ['set_completion', [true]],
        ];
    }

    /**
     * One learner's list is only their own.
     */
    public function test_all_for_user_returns_only_that_learners_deadlines(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Mine', time() + DAYSECS);

        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $this->assertSame(['Theirs'], array_values(array_map(
            fn($r) => $r->name, personal_task_repository::all_for_user())));
        $this->assertSame(['Mine'], array_values(array_map(
            fn($r) => $r->name, personal_task_repository::all_for_user((int) $owner->id))));
    }

    /**
     * The list is capped, so the block cannot be used as free storage.
     */
    public function test_create_stops_at_the_cap(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Written straight to the table: going through create() this many times is slow and proves
        // nothing extra. The cap is what is being tested, not the insert.
        $now = time();
        $rows = [];
        for ($i = 0; $i < personal_task_repository::MAX_PER_USER; $i++) {
            $rows[] = (object) [
                'userid' => $user->id,
                'name' => 'Deadline ' . $i,
                'due' => $now + DAYSECS,
                'timecompleted' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records(personal_task_repository::TABLE, $rows);

        $this->expectException(moodle_exception::class);
        personal_task_repository::create('One too many', $now + DAYSECS);
    }

    /**
     * Guests share one account, so a guest's "own" deadline would be everybody's.
     */
    public function test_a_guest_cannot_keep_deadlines(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->assertFalse(personal_task_repository::can_manage());

        $this->expectException(moodle_exception::class);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);
    }

    /**
     * A learner whose role has the capability taken away may not keep deadlines.
     */
    public function test_the_capability_governs_who_may_keep_deadlines(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(personal_task_repository::can_manage());

        // Prohibit for authenticated users, which is how a site would turn the feature off.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/deadline_checker:manageowndeadlines', CAP_PROHIBIT, $roleid,
                          \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(personal_task_repository::can_manage());

        $this->expectException(\core\exception\required_capability_exception::class);
        personal_task_repository::require_can_manage();
    }
}
