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
 * The single source of truth for how much time is left before a deadline.
 *
 * Every part of the block that talks about remaining time comes through here — the urgency
 * pill, the row's accessible name and the header summary — so the three can never disagree.
 * Nothing else in the plugin may calculate a duration.
 *
 * Calendar-day arithmetic happens in the caller, which knows the learner's timezone; this
 * class only turns an already-resolved day difference into words.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class time_remaining {

    /** @var string Neutral pill: complete. */
    public const VARIANT_DONE = 'done';

    /** @var string Red pill: past due, or due today and the moment has passed. */
    public const VARIANT_OVERDUE = 'overdue';

    /** @var string Coral pill: due today, still time left. */
    public const VARIANT_URGENT = 'urgent';

    /** @var string Indigo pill: due within three days. */
    public const VARIANT_SOON = 'soon';

    /** @var string Grey pill: four or more days away. */
    public const VARIANT_FAR = 'far';

    /**
     * The urgency pill for a task.
     *
     * @param task $task The task.
     * @param int $now Current time as a unix timestamp.
     * @return array{text: string, variant: string, tinted: bool} Pill text, one of the
     *         VARIANT_* constants, and whether the row takes the overdue background tint.
     */
    public static function stamp(task $task, int $now): array {
        if ($task->complete) {
            return self::pill(get_string('done', 'block_deadline_checker'), self::VARIANT_DONE, false);
        }

        if ($task->daydiff < 0) {
            return self::pill(get_string('overdue', 'block_deadline_checker'), self::VARIANT_OVERDUE, true);
        }

        if ($task->daydiff === 0) {
            $seconds = $task->due - $now;

            if ($seconds <= 0) {
                return self::pill(get_string('duenow', 'block_deadline_checker'), self::VARIANT_OVERDUE, true);
            }

            $hours = self::hours_left($seconds);

            if ($hours < 1) {
                return self::pill(get_string('lessthanonehour', 'block_deadline_checker'), self::VARIANT_URGENT, false);
            }

            return self::pill(get_string('hoursleft', 'block_deadline_checker', $hours), self::VARIANT_URGENT, false);
        }

        $variant = $task->daydiff <= 3 ? self::VARIANT_SOON : self::VARIANT_FAR;
        return self::pill(self::days($task->daydiff), $variant, false);
    }

    /**
     * How the task's urgency is spoken, for the row's accessible name.
     *
     * Colour is never the only carrier of urgency, so this must say everything the pill says.
     *
     * @param task $task The task.
     * @param int $now Current time as a unix timestamp.
     * @return string For example "overdue by 3 days" or "due today, 2 hours left".
     */
    public static function accessible_state(task $task, int $now): string {
        if ($task->complete) {
            return get_string('statecompleted', 'block_deadline_checker');
        }

        if ($task->daydiff < 0) {
            $late = abs($task->daydiff);
            return $late === 1
                ? get_string('stateoverdueoneday', 'block_deadline_checker')
                : get_string('stateoverduedays', 'block_deadline_checker', $late);
        }

        if ($task->daydiff === 0) {
            $seconds = $task->due - $now;

            if ($seconds <= 0) {
                return get_string('stateduenow', 'block_deadline_checker');
            }

            $hours = self::hours_left($seconds);

            if ($hours < 1) {
                return get_string('statesubhour', 'block_deadline_checker');
            }

            return $hours === 1
                ? get_string('statehourleft', 'block_deadline_checker')
                : get_string('statehoursleft', 'block_deadline_checker', $hours);
        }

        return $task->daydiff === 1
            ? get_string('statedueinoneday', 'block_deadline_checker')
            : get_string('stateduein', 'block_deadline_checker', $task->daydiff);
    }

    /**
     * Whole hours left, to the nearest hour, with exactly half an hour rounding up.
     *
     * The pill and the spoken state both come through here, so the two can never disagree about
     * the same deadline.
     *
     * Rounded rather than floored, because floored reads as wrong to the person waiting: at 15:19
     * a 17:00 deadline has 1h41m left, and "1h left" undersells it by most of an hour. Zero means
     * under half an hour is left, which the callers word for themselves rather than say "0h".
     *
     * Capped at 23. This only ever describes a deadline due today, and a pill saying "24h left"
     * next to a row that says due today contradicts itself; that needs a deadline late tonight
     * being looked at just after midnight, which is rare but not impossible.
     *
     * @param int $seconds Seconds until the deadline. Positive; callers handle the passed moment.
     * @return int Whole hours, 0 when under half an hour is left.
     */
    private static function hours_left(int $seconds): int {
        return min((int) round($seconds / HOURSECS), 23);
    }

    /**
     * A whole number of days, singular or plural.
     *
     * @param int $days Number of days.
     * @return string For example "1 day" or "8 days".
     */
    public static function days(int $days): string {
        return $days === 1
            ? get_string('oneday', 'block_deadline_checker')
            : get_string('ndays', 'block_deadline_checker', $days);
    }

    /**
     * Assemble a pill.
     *
     * @param string $text Pill text.
     * @param string $variant One of the VARIANT_* constants.
     * @param bool $tinted Whether the row is tinted.
     * @return array{text: string, variant: string, tinted: bool}
     */
    private static function pill(string $text, string $variant, bool $tinted): array {
        return ['text' => $text, 'variant' => $variant, 'tinted' => $tinted];
    }
}
