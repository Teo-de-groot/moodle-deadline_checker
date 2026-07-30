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

use block_deadline_checker\privacy\provider;
use context_user;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the block's privacy provider.
 *
 * The block stores one thing about a person: the deadlines they have written down. These tests
 * check that it is findable, exportable and deletable, and that one learner's request never touches
 * another's list.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {

    /**
     * The stored table is described, field by field.
     */
    public function test_the_stored_table_is_described(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('block_deadline_checker'));
        $items = $collection->get_collection();

        $this->assertCount(1, $items);
        $this->assertSame(personal_task_repository::TABLE, $items[0]->get_name());

        // Every column that holds something about the learner is accounted for.
        $described = array_keys($items[0]->get_privacy_fields());
        $this->assertEqualsCanonicalizing(
            ['userid', 'courseid', 'name', 'due', 'timecompleted', 'timecreated', 'timemodified'],
            $described
        );
    }

    /**
     * A learner with deadlines is found in their own user context, and one without is not found.
     */
    public function test_a_learner_with_deadlines_is_found_in_their_own_context(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $withdata = $generator->create_user();
        $without = $generator->create_user();

        $this->setUser($withdata);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $contexts = provider::get_contexts_for_userid((int) $withdata->id)->get_contextids();
        $this->assertSame([context_user::instance($withdata->id)->id], array_map('intval', $contexts));

        $this->assertEmpty(provider::get_contexts_for_userid((int) $without->id)->get_contextids());
    }

    /**
     * Only the owner is listed as having data in their own context.
     */
    public function test_only_the_owner_is_listed_in_their_context(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $userlist = new userlist(context_user::instance($owner->id), 'block_deadline_checker');
        provider::get_users_in_context($userlist);

        $this->assertSame([(int) $owner->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * A learner's export contains their deadlines and nobody else's.
     */
    public function test_export_returns_the_learners_own_deadlines(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Operational leadership']);
        $owner = $generator->create_user();
        $other = $generator->create_user();
        $generator->enrol_user($owner->id, $course->id);

        $this->setUser($owner);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS, (int) $course->id);
        personal_task_repository::create('Ring the assessor', time() + 2 * DAYSECS);

        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $context = context_user::instance($owner->id);
        $this->export_context_data_for_user((int) $owner->id, $context, 'block_deadline_checker');

        $data = writer::with_context($context)
            ->get_data([get_string('pluginname', 'block_deadline_checker')]);

        $names = array_map(fn($d) => $d->name, $data->deadlines);

        $this->assertEqualsCanonicalizing(['Reflective log 3', 'Ring the assessor'], $names);
        $this->assertNotContains('Theirs', $names);

        // The course is named, and the one filed under no course says so rather than being blank.
        $courses = array_map(fn($d) => $d->course, $data->deadlines);
        $this->assertContains('Operational leadership', $courses);
        $this->assertContains(get_string('nocourse', 'block_deadline_checker'), $courses);
    }

    /**
     * Deleting a learner's data removes their whole list and leaves everyone else's alone.
     */
    public function test_deleting_one_learners_data_leaves_others_alone(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $contextlist = new approved_contextlist($owner, 'block_deadline_checker',
                                                [context_user::instance($owner->id)->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists(personal_task_repository::TABLE, ['userid' => $owner->id]));
        $this->assertTrue($DB->record_exists(personal_task_repository::TABLE, ['userid' => $other->id]));
    }

    /**
     * Deleting everything in a user context clears that learner's list only.
     */
    public function test_deleting_a_context_clears_that_learners_list(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        provider::delete_data_for_all_users_in_context(context_user::instance($owner->id));

        $this->assertFalse($DB->record_exists(personal_task_repository::TABLE, ['userid' => $owner->id]));
        $this->assertTrue($DB->record_exists(personal_task_repository::TABLE, ['userid' => $other->id]));
    }

    /**
     * A bulk deletion in one learner's context cannot reach into another's list.
     *
     * The other user is named on the approved list on purpose: the context says whose rows live
     * there, so being named must not be enough.
     */
    public function test_bulk_deletion_stays_inside_the_context_it_was_given(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        personal_task_repository::create('Reflective log 3', time() + DAYSECS);
        $this->setUser($other);
        personal_task_repository::create('Theirs', time() + DAYSECS);

        $userlist = new approved_userlist(context_user::instance($owner->id), 'block_deadline_checker',
                                          [(int) $owner->id, (int) $other->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists(personal_task_repository::TABLE, ['userid' => $owner->id]));
        $this->assertTrue($DB->record_exists(personal_task_repository::TABLE, ['userid' => $other->id]));
    }
}
