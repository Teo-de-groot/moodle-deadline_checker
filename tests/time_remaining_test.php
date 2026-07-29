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
 * Tests for the urgency calculation.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_deadline_checker\time_remaining
 */
final class time_remaining_test extends \advanced_testcase {

    /** @var int Fixed "now" for every case: 1 January 2026, 09:00 UTC. */
    private const NOW = 1767258000;

    /**
     * Build a task with a given day difference and due time.
     *
     * @param int $daydiff Calendar days between today and the due date.
     * @param int $offset Seconds between now and the due date.
     * @param bool $complete Completion state.
     * @return task
     */
    private function task(int $daydiff, int $offset, bool $complete = false): task {
        return new task('t1', 'Reflective log 3', 'ol', 'Operational leadership',
                        self::NOW + $offset, $daydiff, $complete);
    }

    /**
     * The pill text, colour variant and row tint for each urgency band.
     *
     * @dataProvider stamp_provider
     * @param int $daydiff Calendar days to the due date.
     * @param int $offset Seconds to the due date.
     * @param bool $complete Completion state.
     * @param string $text Expected pill text.
     * @param string $variant Expected variant.
     * @param bool $tinted Whether the row is tinted.
     */
    public function test_stamp(int $daydiff, int $offset, bool $complete,
                               string $text, string $variant, bool $tinted): void {
        $stamp = time_remaining::stamp($this->task($daydiff, $offset, $complete), self::NOW);

        $this->assertSame($text, $stamp['text']);
        $this->assertSame($variant, $stamp['variant']);
        $this->assertSame($tinted, $stamp['tinted']);
    }

    /**
     * Every row of the handoff's urgency table, plus the boundaries between them.
     *
     * @return array[]
     */
    public static function stamp_provider(): array {
        return [
            'complete' => [-18, -18 * DAYSECS, true, 'Done', 'done', false],
            'complete and overdue is still done' => [-3, -3 * DAYSECS, true, 'Done', 'done', false],
            'past due' => [-3, -3 * DAYSECS, false, 'Overdue', 'overdue', true],
            'due yesterday' => [-1, -DAYSECS, false, 'Overdue', 'overdue', true],
            'due today, moment passed' => [0, -HOURSECS, false, 'Due now', 'overdue', true],
            'due today, exactly now' => [0, 0, false, 'Due now', 'overdue', true],
            'due today, one minute left' => [0, MINSECS, false, '<1 hour', 'urgent', false],
            'due today, forty minutes left' => [0, 40 * MINSECS, false, '<1 hour', 'urgent', false],
            'due today, fifty nine minutes' => [0, 59 * MINSECS, false, '<1 hour', 'urgent', false],
            'due today, exactly one hour' => [0, HOURSECS, false, '1h left', 'urgent', false],
            'due today, floors not rounds' => [0, HOURSECS + 59 * MINSECS, false, '1h left', 'urgent', false],
            'due today, five hours' => [0, 5 * HOURSECS, false, '5h left', 'urgent', false],
            'tomorrow' => [1, 16 * HOURSECS, false, '1 day', 'soon', false],
            'three days' => [3, 3 * DAYSECS, false, '3 days', 'soon', false],
            'four days drops to neutral' => [4, 4 * DAYSECS, false, '4 days', 'far', false],
            'eight days' => [8, 8 * DAYSECS, false, '8 days', 'far', false],
        ];
    }

    /**
     * The accessible name must carry the same urgency the pill shows in colour.
     *
     * @dataProvider state_provider
     * @param int $daydiff Calendar days to the due date.
     * @param int $offset Seconds to the due date.
     * @param bool $complete Completion state.
     * @param string $expected Expected phrasing.
     */
    public function test_accessible_state(int $daydiff, int $offset, bool $complete, string $expected): void {
        $this->assertSame($expected,
            time_remaining::accessible_state($this->task($daydiff, $offset, $complete), self::NOW));
    }

    /**
     * @return array[]
     */
    public static function state_provider(): array {
        return [
            'complete' => [-18, -18 * DAYSECS, true, 'completed'],
            'overdue by one day' => [-1, -DAYSECS, false, 'overdue by 1 day'],
            'overdue by three days' => [-3, -3 * DAYSECS, false, 'overdue by 3 days'],
            'moment passed' => [0, -HOURSECS, false, 'due now, today'],
            'under an hour' => [0, 40 * MINSECS, false, 'due today, less than 1 hour left'],
            'one hour' => [0, HOURSECS, false, 'due today, 1 hour left'],
            'five hours' => [0, 5 * HOURSECS, false, 'due today, 5 hours left'],
            'tomorrow' => [1, 16 * HOURSECS, false, 'due in 1 day'],
            'eight days' => [8, 8 * DAYSECS, false, 'due in 8 days'],
        ];
    }

    /**
     * Every urgency band must produce a non-empty accessible phrase, or a screen reader user
     * loses information that sighted users get from the pill's colour.
     */
    public function test_no_urgency_band_is_silent(): void {
        foreach (self::stamp_provider() as $name => [$daydiff, $offset, $complete]) {
            $task = $this->task($daydiff, $offset, $complete);

            $this->assertNotSame('', trim(time_remaining::accessible_state($task, self::NOW)),
                "Accessible state is empty for: {$name}");
        }
    }
}
