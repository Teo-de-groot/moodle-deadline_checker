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

use block_deadline_checker\external\delete_task;
use block_deadline_checker\external\get_tasks;
use block_deadline_checker\external\set_completion;
use core\exception\moodle_exception;
use core_external\external_api;

/**
 * Tests for the block's web services.
 *
 * Every assertion runs the result through external_api::clean_returnvalue(), which is what the
 * server does before answering a browser. That is deliberate: it means these tests fail if the
 * declared return structure and the payload the block actually builds ever drift apart, which is
 * otherwise the kind of mismatch that only shows up as a broken block in production.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\external\get_tasks
 * @covers     \block_deadline_checker\external\delete_task
 * @covers     \block_deadline_checker\external\set_completion
 * @covers     \block_deadline_checker\external\task_payload
 */
final class external_test extends \advanced_testcase {

    /**
     * Reading back a learner's deadlines gives the same payload the block embeds on first paint.
     */
    public function test_get_tasks_returns_a_payload_matching_its_declared_structure(): void {
        $this->resetAfterTest();

        $this->setUser($this->getDataGenerator()->create_user());

        personal_task_repository::create('Ring the assessor', time() + DAYSECS);

        $result = external_api::clean_returnvalue(
            get_tasks::execute_returns(),
            get_tasks::execute('dlblock1', 5)
        );

        $this->assertSame('dlblock1', $result['blockid']);
        $this->assertSame(5, $result['pagesize']);
        $this->assertCount(1, $result['tasks']);

        $task = $result['tasks'][0];
        $this->assertSame('Ring the assessor', $task['name']);
        $this->assertTrue($task['canedit']);
        $this->assertTrue($task['cantoggle']);
        $this->assertGreaterThan(0, $task['personalid']);
        // Both states' wording is present, which is what lets the browser toggle without asking.
        $this->assertNotEmpty($task['open']['stamptext']);
        $this->assertNotEmpty($task['done']['stamptext']);
    }

    /**
     * A page size a browser makes up is clamped, not trusted.
     */
    public function test_get_tasks_clamps_the_page_size(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $huge = external_api::clean_returnvalue(
            get_tasks::execute_returns(), get_tasks::execute('dlblock1', 9999));
        $tiny = external_api::clean_returnvalue(
            get_tasks::execute_returns(), get_tasks::execute('dlblock1', -4));

        $this->assertSame(output\deadlines::MAX_PAGE_SIZE, $huge['pagesize']);
        $this->assertSame(output\deadlines::MIN_PAGE_SIZE, $tiny['pagesize']);
    }

    /**
     * Removing a deadline removes it, and the answer is the block without it.
     */
    public function test_delete_task_removes_it_and_returns_the_refreshed_block(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $keep = personal_task_repository::create('Keep this', time() + DAYSECS);
        $drop = personal_task_repository::create('Drop this', time() + 2 * DAYSECS);

        $result = external_api::clean_returnvalue(
            delete_task::execute_returns(),
            delete_task::execute($drop, 'dlblock1', 5)
        );

        $this->assertFalse($DB->record_exists(personal_task_repository::TABLE, ['id' => $drop]));
        $this->assertTrue($DB->record_exists(personal_task_repository::TABLE, ['id' => $keep]));

        // The browser is told what is left rather than having to work it out.
        $this->assertSame(['Keep this'], array_map(fn($t) => $t['name'], $result['tasks']));
    }

    /**
     * The service will not remove somebody else's deadline.
     */
    public function test_delete_task_refuses_another_learners_deadline(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $this->setUser($other);

        try {
            delete_task::execute($id, 'dlblock1', 5);
            $this->fail('the service removed another learner\'s deadline');
        } catch (moodle_exception $e) {
            $this->assertInstanceOf(moodle_exception::class, $e);
        }

        $this->assertTrue($DB->record_exists(personal_task_repository::TABLE,
            ['id' => $id, 'userid' => $owner->id]));
    }

    /**
     * Ticking a deadline off records it, and the answer says so.
     */
    public function test_set_completion_records_the_state(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $done = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($id, true, 'dlblock1', 5)
        );

        $this->assertTrue($done['tasks'][0]['complete']);
        $this->assertGreaterThan(0, (int) personal_task_repository::get_own($id)->timecompleted);

        $reopened = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($id, false, 'dlblock1', 5)
        );

        $this->assertFalse($reopened['tasks'][0]['complete']);
        $this->assertSame(0, (int) personal_task_repository::get_own($id)->timecompleted);
    }

    /**
     * The service will not tick off somebody else's deadline.
     */
    public function test_set_completion_refuses_another_learners_deadline(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $owner = $generator->create_user();
        $other = $generator->create_user();

        $this->setUser($owner);
        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $this->setUser($other);

        $this->expectException(moodle_exception::class);
        set_completion::execute($id, true, 'dlblock1', 5);
    }

    /**
     * A course deadline cannot be reached through these services at all.
     *
     * A course module id is not a row id here, so passing one in is simply an id that is not the
     * caller's. Completion of an activity goes to core's own service, which is the only place that
     * knows what completing an activity means.
     */
    public function test_a_course_module_id_is_not_a_handle_on_a_course_deadline(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => time() + DAYSECS,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $this->expectException(moodle_exception::class);
        set_completion::execute((int) $assign->cmid, true, 'dlblock1', 5);
    }

    /**
     * A learner whose role lacks the capability cannot write through these services.
     */
    public function test_the_write_services_require_the_capability(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $id = personal_task_repository::create('Reflective log 3', time() + DAYSECS);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/deadline_checker:manageowndeadlines', CAP_PROHIBIT, $roleid,
                          \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\core\exception\required_capability_exception::class);
        delete_task::execute($id, 'dlblock1', 5);
    }
}
