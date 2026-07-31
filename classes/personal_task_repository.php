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

use context_system;
use core\exception\moodle_exception;
use core_text;
use stdClass;

/**
 * Creating, changing and removing the deadlines a learner keeps for themselves.
 *
 * Every method here is scoped to one learner's own rows. That is deliberate and it is the whole
 * security model: a learner may only ever reach a deadline of theirs, so there is no separate
 * "can I touch this one" question to get wrong. Asking for someone else's row is indistinguishable
 * from asking for one that does not exist, which also means an id cannot be used to find out
 * whose deadlines exist.
 *
 * Validation lives here rather than in the form, so the web services get the same rules. A form
 * only guards the browser; this guards the database.
 *
 * Course deadlines are not writable through this class and never will be: they belong to the
 * course, and Moodle already gives teachers the activity settings to change them.
 *
 * A deadline written here belongs to one learner and to nothing else. It cannot be filed under a
 * course: the courseid column is retained only for rows written before that was removed, is never
 * set by this class, and is ignored on the way back out by {@see personal_task_source}.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personal_task_repository {

    /** @var string The table holding learner-owned deadlines. */
    public const TABLE = 'block_deadline_checker_task';

    /** @var int Longest name we can store, matching the column. */
    public const NAME_MAX_LENGTH = 255;

    /** @var int Most deadlines one learner may keep, so the block cannot be used as free storage. */
    public const MAX_PER_USER = 200;

    /**
     * Add a deadline for a learner.
     *
     * @param string $name What to call it.
     * @param int $due Due date as a unix timestamp.
     * @param int|null $userid Owner; defaults to the current user.
     * @return int The new row's id.
     */
    public static function create(string $name, int $due, ?int $userid = null): int {
        global $DB;

        $userid = self::owner($userid);
        $now = time();

        if ($DB->count_records(self::TABLE, ['userid' => $userid]) >= self::MAX_PER_USER) {
            throw new moodle_exception('errortoomany', 'block_deadline_checker', '', self::MAX_PER_USER);
        }

        return (int) $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'courseid' => null,
            'name' => self::validate_name($name),
            'due' => self::validate_due($due),
            'timecompleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Change one of a learner's own deadlines.
     *
     * Completion is not settable here: it moves through {@see set_completion}, so ticking a
     * deadline off never has to send the name and date back with it.
     *
     * @param int $id Row id.
     * @param string $name What to call it.
     * @param int $due Due date as a unix timestamp.
     * @param int|null $userid Owner; defaults to the current user.
     */
    public static function update(int $id, string $name, int $due, ?int $userid = null): void {
        global $DB;

        $userid = self::owner($userid);
        // Throws before anything is written if the row is not theirs.
        $existing = self::get_own($id, $userid);

        // courseid is deliberately absent, so editing an old row neither sets a course nor discards
        // what a learner recorded before the field went away. Either way nothing reads it.
        $DB->update_record(self::TABLE, (object) [
            'id' => $existing->id,
            'name' => self::validate_name($name),
            'due' => self::validate_due($due),
            'timemodified' => time(),
        ]);
    }

    /**
     * Remove one of a learner's own deadlines.
     *
     * @param int $id Row id.
     * @param int|null $userid Owner; defaults to the current user.
     */
    public static function delete(int $id, ?int $userid = null): void {
        global $DB;

        $userid = self::owner($userid);
        $existing = self::get_own($id, $userid);

        // Keyed on the owner as well as the id: the guard above and the delete itself then agree
        // about whose row this is, whatever happens between them.
        $DB->delete_records(self::TABLE, ['id' => $existing->id, 'userid' => $userid]);
    }

    /**
     * Tick one of a learner's own deadlines off, or put it back on the list.
     *
     * @param int $id Row id.
     * @param bool $complete New state.
     * @param int|null $userid Owner; defaults to the current user.
     */
    public static function set_completion(int $id, bool $complete, ?int $userid = null): void {
        global $DB;

        $userid = self::owner($userid);
        $existing = self::get_own($id, $userid);
        $now = time();

        // Re-ticking something already complete must not rewrite the date it was completed on.
        if ($complete && $existing->timecompleted > 0) {
            return;
        }

        $DB->update_record(self::TABLE, (object) [
            'id' => $existing->id,
            'timecompleted' => $complete ? $now : 0,
            'timemodified' => $now,
        ]);
    }

    /**
     * One of a learner's own deadlines.
     *
     * @param int $id Row id.
     * @param int|null $userid Owner; defaults to the current user.
     * @return stdClass The record.
     */
    public static function get_own(int $id, ?int $userid = null): stdClass {
        global $DB;

        // MUST_EXIST with the owner in the conditions: someone else's row and a deleted row give
        // the same error, so an id reveals nothing.
        return $DB->get_record(self::TABLE, ['id' => $id, 'userid' => self::owner($userid)], '*', MUST_EXIST);
    }

    /**
     * All of a learner's own deadlines, oldest due date first.
     *
     * @param int|null $userid Owner; defaults to the current user.
     * @return stdClass[] Keyed by row id.
     */
    public static function all_for_user(?int $userid = null): array {
        global $DB;

        return $DB->get_records(self::TABLE, ['userid' => self::owner($userid)], 'due ASC, id ASC');
    }

    /**
     * Check that the current user is allowed to keep deadlines of their own at all.
     *
     * Separate from the ownership checks above, which answer a different question: this is whether
     * the site lets this person use the feature, that is whether a given row is theirs.
     */
    public static function require_can_manage(): void {
        require_capability('block/deadline_checker:manageowndeadlines', context_system::instance());
    }

    /**
     * Whether the current user is allowed to keep deadlines of their own.
     *
     * @return bool
     */
    public static function can_manage(): bool {
        global $USER;

        return !empty($USER->id) && !isguestuser()
            && has_capability('block/deadline_checker:manageowndeadlines', context_system::instance());
    }

    /**
     * Resolve and sanity-check the owner a call is about.
     *
     * @param int|null $userid Owner, or null for the current user.
     * @return int
     */
    protected static function owner(?int $userid): int {
        global $USER;

        $userid = $userid ?? (int) $USER->id;

        // Guests share one account, so a guest's "own" deadline would be everybody's.
        if (empty($userid) || isguestuser($userid)) {
            throw new moodle_exception('errornotloggedin', 'block_deadline_checker');
        }

        return $userid;
    }

    /**
     * Check a name and tidy it.
     *
     * @param string $name Raw name.
     * @return string Trimmed name, guaranteed to fit the column.
     */
    protected static function validate_name(string $name): string {
        // Trims non-breaking space and the rest of Unicode's whitespace too, so a name made only
        // of invisible characters is caught as empty rather than stored.
        $name = trim($name);
        $name = preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $name) ?? $name;

        if ($name === '') {
            throw new moodle_exception('errornamerequired', 'block_deadline_checker');
        }

        if (core_text::strlen($name) > self::NAME_MAX_LENGTH) {
            throw new moodle_exception('errornametoolong', 'block_deadline_checker', '', self::NAME_MAX_LENGTH);
        }

        return $name;
    }

    /**
     * Check a due date.
     *
     * Dates in the past are allowed: a learner may well be recording something they have already
     * missed, and the block has an overdue state for exactly that.
     *
     * @param int $due Due date as a unix timestamp.
     * @return int
     */
    protected static function validate_due(int $due): int {
        if ($due <= 0) {
            throw new moodle_exception('errorduerequired', 'block_deadline_checker');
        }

        return $due;
    }
}
