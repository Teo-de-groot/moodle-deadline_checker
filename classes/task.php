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
 * A single deadline shown in the block.
 *
 * Deliberately free of $DB, $CFG and $USER: the data source decides where these come from,
 * this only describes what a deadline is.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task {

    /**
     * @param string $id Stable identifier, used as the key for client-side state.
     * @param string $name Activity name.
     * @param string $courseid Course identifier, used by the course filter.
     * @param string $coursename Course name as displayed.
     * @param int $due Due date as a unix timestamp.
     * @param int $daydiff Whole calendar days between today and the due date in the learner's
     *                     timezone. Negative is in the past. Calendar days, not 24 hour blocks,
     *                     so a deadline at 09:00 tomorrow is 1 even when it is 16 hours away.
     * @param bool $complete Whether the learner has completed the activity.
     * @param string|null $url Link to the activity, or null when there is nothing to link to.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $courseid,
        public readonly string $coursename,
        public readonly int $due,
        public readonly int $daydiff,
        public readonly bool $complete,
        public readonly ?string $url = null,
    ) {
    }

    /**
     * Copy of this task with the completion state flipped.
     *
     * @param bool $complete New completion state.
     * @return self
     */
    public function with_complete(bool $complete): self {
        return new self($this->id, $this->name, $this->courseid, $this->coursename,
                        $this->due, $this->daydiff, $complete, $this->url);
    }
}
