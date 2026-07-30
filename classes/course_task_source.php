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

use cm_info;
use completion_info;
use context_course;
use stdClass;

/**
 * The learner's real deadlines, read from the courses they are enrolled on.
 *
 * Deadlines come from the calendar rather than from each activity's own table: any activity
 * that publishes a "due" or "close" event has a deadline, whatever type it is, and core keeps
 * those events in step with the activity's settings. Reading duedate, timeclose, deadline and
 * so on directly would mean a special case per activity type and a new one for every plugin.
 *
 * Completion comes from activity completion, so "Done" here means what it means everywhere else
 * in Moodle.
 *
 * This is the only class in the plugin that reads the database; {@see task} and
 * {@see time_remaining} stay free of it.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_task_source {

    /**
     * Calendar event types that are a deadline for a learner.
     *
     * "due" covers assignments, forums and databases; "close" covers quizzes, choices and
     * feedback. Deliberately absent: "open", which is a start date rather than a deadline, and
     * teacher-facing events such as "gradingdue".
     *
     * @var string[]
     */
    protected const DEADLINE_EVENT_TYPES = ['due', 'close'];

    /**
     * Every deadline the learner can see, in no particular order.
     *
     * @param int $now Current time as a unix timestamp.
     * @param int|null $userid Learner to report on; defaults to the current user.
     * @return task[] Unsorted; the presenter decides the order.
     */
    public static function tasks(int $now, ?int $userid = null): array {
        global $CFG, $DB, $USER;

        // For CALENDAR_EVENT_USER_OVERRIDE_PRIORITY, which tells an override apart from a date
        // that applies to the whole course.
        require_once($CFG->dirroot . '/calendar/lib.php');

        $userid = $userid ?? (int) $USER->id;

        // Guests are enrolled on nothing, and have no completion records to read.
        if (empty($userid) || isguestuser($userid)) {
            return [];
        }

        $courses = self::courses($userid);

        if (empty($courses)) {
            return [];
        }

        // Every value below is a bound parameter: the only SQL these fragments carry is the
        // placeholder list get_in_or_equal builds for us.
        [$coursesql, $courseparams] = $DB->get_in_or_equal(
            array_map('intval', array_keys($courses)), SQL_PARAMS_NAMED, 'course');
        [$typesql, $typeparams] = $DB->get_in_or_equal(self::DEADLINE_EVENT_TYPES, SQL_PARAMS_NAMED, 'type');

        // A course-wide date has no priority; an override for one learner carries the user
        // override priority and that learner's id. Other people's overrides are excluded here,
        // and group overrides — priority set with a group rather than a user — are not read at
        // all, so a learner in an extended group sees the course-wide date.
        //
        // Override events are stored with no courseid, because the calendar hides personal
        // events that name a course, so the course filter can only be applied to the
        // course-wide half of this query. The other half is resolved course by course below.
        $select = "eventtype {$typesql}
                   AND modulename <> ''
                   AND instance > 0
                   AND visible = 1
                   AND ((courseid {$coursesql} AND priority IS NULL)
                        OR (priority = :override AND userid = :userid))";

        $events = $DB->get_records_select('event', $select,
            $courseparams + $typeparams
                + ['override' => CALENDAR_EVENT_USER_OVERRIDE_PRIORITY, 'userid' => $userid],
            'timestart ASC', 'id, courseid, modulename, instance, timestart, priority');

        $dates = [];
        $modules = [];

        foreach ($events as $event) {
            $courseid = (int) $event->courseid ?: self::course_of($event);

            // An override for an activity in a course they have since left.
            if (!isset($courses[$courseid])) {
                continue;
            }

            $cm = self::module($courses[$courseid], $event->modulename, (int) $event->instance, $userid);

            if ($cm === null) {
                continue;
            }

            $modules[$cm->id] = $cm;
            // An activity can publish more than one deadline event, and the learner's own
            // override wins over the course-wide date however the two compare. Events arrive
            // in date order, so the first of each kind is the earliest.
            $bucket = $event->priority === null ? 'course' : 'user';
            $dates[$cm->id][$bucket] ??= (int) $event->timestart;
        }

        $tasks = [];

        foreach ($dates as $cmid => $deadline) {
            $cm = $modules[$cmid];
            $course = $courses[$cm->course];
            $due = $deadline['user'] ?? $deadline['course'];
            [$complete, $manual] = self::completion_state($course, $cm, $userid);

            $tasks[] = new task(
                'cm' . $cmid,
                $cm->get_formatted_name(),
                (string) $course->id,
                self::course_name($course),
                $due,
                calendar_days::between($now, $due),
                $complete,
                $cm->url ? $cm->url->out(false) : null,
                (int) $cmid,
                $manual,
            );
        }

        return $tasks;
    }

    /**
     * Courses the learner is actively enrolled on and allowed to see.
     *
     * @param int $userid Learner's id.
     * @return stdClass[] Full course records, keyed by course id.
     */
    protected static function courses(int $userid): array {
        $courses = [];

        foreach (enrol_get_all_users_courses($userid, true, ['id']) as $enrolled) {
            // The full record, which both get_fast_modinfo and completion_info want.
            $course = get_course($enrolled->id);

            if (!$course->visible &&
                    !has_capability('moodle/course:viewhiddencourses',
                                    context_course::instance($course->id), $userid)) {
                continue;
            }

            $courses[$course->id] = $course;
        }

        return $courses;
    }

    /**
     * The course an event's activity belongs to, for the override events that do not say.
     *
     * Resolved through course_modules rather than by reading the activity's own table, so the
     * event's modulename stays a bound value and never becomes part of the SQL.
     *
     * @param stdClass $event Event record with modulename and instance.
     * @return int Course id, or 0 if the activity has since been deleted.
     */
    protected static function course_of(stdClass $event): int {
        global $DB;

        $sql = "SELECT cm.course
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = :modulename
                   AND cm.instance = :instance";

        return (int) $DB->get_field_sql($sql, [
            'modulename' => $event->modulename,
            'instance' => (int) $event->instance,
        ], IGNORE_MISSING);
    }

    /**
     * The course module an event belongs to, if the learner can see it.
     *
     * @param stdClass $course Course record.
     * @param string $modulename Activity type, for example "assign".
     * @param int $instance Activity instance id.
     * @param int $userid Learner's id.
     * @return cm_info|null Null when the activity is gone or hidden from this learner.
     */
    protected static function module(stdClass $course, string $modulename, int $instance, int $userid): ?cm_info {
        // Cached per course and user, so calling this once per event is cheap.
        $instances = get_fast_modinfo($course, $userid)->get_instances_of($modulename);
        $cm = $instances[$instance] ?? null;

        return $cm !== null && $cm->uservisible ? $cm : null;
    }

    /**
     * Whether the learner has completed an activity, and whether they may say so themselves.
     *
     * Activities without completion tracking are never complete: there is nothing recorded to
     * say otherwise, and guessing from a grade or a submission would disagree with the rest of
     * the course. Only manually tracked activities can be marked complete from the block —
     * anything else is the course's own business, so the block offers no button for it.
     *
     * @param stdClass $course Course record.
     * @param cm_info $cm The activity.
     * @param int $userid Learner's id.
     * @return array{0: bool, 1: bool} Complete, and manually trackable.
     */
    protected static function completion_state(stdClass $course, cm_info $cm, int $userid): array {
        $completion = new completion_info($course);
        $tracking = (int) $completion->is_enabled($cm);

        if ($tracking === COMPLETION_TRACKING_NONE) {
            return [false, false];
        }

        $state = (int) $completion->get_data($cm, false, $userid)->completionstate;

        return [
            in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true),
            $tracking === COMPLETION_TRACKING_MANUAL,
        ];
    }

    /**
     * The course name as the learner sees it.
     *
     * @param stdClass $course Course record.
     * @return string
     */
    protected static function course_name(stdClass $course): string {
        return format_string($course->fullname, true, ['context' => context_course::instance($course->id)]);
    }
}
