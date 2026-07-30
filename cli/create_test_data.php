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

/**
 * Puts the placeholder deadline dataset into the database, for development only.
 *
 * The block itself now reads real courses, activities and completion records, so there is
 * nothing to look at on a fresh site. This creates a test student, the four placeholder
 * courses and one activity per placeholder deadline, so the block has the same dataset to
 * render that it used to invent in PHP.
 *
 * The dataset is read from {@see \block_deadline_checker\sample_task_source}, so the sample
 * data is still defined in exactly one place.
 *
 * Re-running the script is safe: it reuses the student and the courses, and rebuilds the
 * activities so their due dates are relative to today again.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Which activity type carries each placeholder deadline.
 *
 * Assignments by default, because their due date is the plainest example of a deadline. The
 * one exception is the dataset's sub-hour task, which becomes a quiz so the block is also
 * reading a close date from a different activity type.
 */
const DC_MODULE_TYPES = ['t3' => 'quiz'];

/** Prefix for everything this script creates, so it can find its own records again. */
const DC_PREFIX = 'deadline-';

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'username' => 'deadline.student',
    'password' => 'Student123!',
    'studentonly' => false,
    'bypasscheck' => false,
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', array_keys($unrecognised)) . '. Use --help for help.');
}

if ($options['help']) {
    echo "
Create a test student with placeholder deadlines for the deadline checker block.

Not for use on live sites: it only runs where debugging is set to DEVELOPER level.

Options:
--username     Username for the test student (default deadline.student)
--password     Password for the test student (default Student123!)
--studentonly  Only enrol the test student, leaving the admin account out of the courses
--bypasscheck  Bypass the developer-mode check (be careful)
-h, --help     Print out this help

Example, from the Moodle root directory:
\$ php public/blocks/deadline_checker/cli/create_test_data.php
";
    exit(0);
}

if (empty($options['bypasscheck']) && !debugging('', DEBUG_DEVELOPER)) {
    cli_error('This script creates users and courses, so it only runs with developer debugging on. ' .
              'Pass --bypasscheck if you are sure.');
}

// Creating courses and activities is an administrative act, and the APIs below check capabilities.
\core\session\manager::set_user(get_admin());

if (empty($CFG->enablecompletion)) {
    set_config('enablecompletion', 1);
    cli_writeln('Turned on activity completion site-wide: the block reads completion records.');
}

$generator = \core\test\phpunit\phpunit_util::get_data_generator();
$now = time();

$student = dc_ensure_student($options['username'], $options['password']);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

// The block shows the deadlines of whoever is looking at it, so an admin browsing their own
// Dashboard sees nothing unless they are enrolled too. Both accounts get the dataset.
$learners = [$student];
$admin = get_admin();

if (!$options['studentonly'] && (int) $admin->id !== (int) $student->id) {
    $learners[] = $admin;
    cli_writeln("Also enrolling {$admin->username}, so the data shows on your own Dashboard. " .
                'Pass --studentonly to skip that.');
}

$courses = [];
foreach (\block_deadline_checker\sample_task_source::courses() as $key => $fullname) {
    $course = dc_ensure_course($key, $fullname, $generator);
    $courses[$key] = $course;

    foreach ($learners as $learner) {
        enrol_try_internal_enrol($course->id, $learner->id, $studentrole->id);
    }

    dc_ensure_block_on_course($course);
    cli_writeln("Course: {$course->fullname} ({$course->shortname}), enrolled, block added.");
}

foreach (\block_deadline_checker\sample_task_source::tasks($now) as $task) {
    $modulename = DC_MODULE_TYPES[$task->id] ?? 'assign';
    $cmid = dc_ensure_activity($courses[$task->courseid], $task, $modulename, $generator);

    foreach ($learners as $learner) {
        dc_set_completion($courses[$task->courseid], $modulename, $cmid, (int) $learner->id, $task->complete);
    }

    cli_writeln(sprintf('  %-8s %-40s due %s%s', $modulename, $task->name,
                        userdate($task->due, '%a %d %b, %H:%M'), $task->complete ? ' (complete)' : ''));
}

// Older versions of this script put the block on the site default Dashboard, which handed it to
// every user on the site. Undo that before giving anyone their own copy of the page.
if ($removed = dc_clear_default_dashboard_block()) {
    cli_writeln($removed);
}

foreach ($learners as $learner) {
    cli_writeln('Dashboard: ' . dc_ensure_block_on_dashboard($learner));
}

cli_writeln('');
cli_writeln('Done. Log in at ' . $CFG->wwwroot . ' as ' . $options['username'] . ' / ' . $options['password'] . ',');
cli_writeln('or stay as ' . $admin->username . ': the block is on the Dashboard and on each course above.');

/**
 * The test student, created if this is the first run.
 *
 * @param string $username Username.
 * @param string $password Plain text password, set on creation only.
 * @return stdClass User record.
 */
function dc_ensure_student(string $username, string $password): stdClass {
    global $CFG, $DB;

    $existing = $DB->get_record('user', [
        'username' => $username,
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0,
    ]);

    if ($existing) {
        cli_writeln("Student: {$username} already exists (id {$existing->id}), password left alone.");
        return $existing;
    }

    // Invented details on a reserved domain: this is a fixture, not a person.
    $userid = user_create_user((object) [
        'username' => $username,
        'password' => $password,
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => 'Deadline',
        'lastname' => 'Testlearner',
        'email' => $username . '@example.com',
        'city' => 'Manchester',
        'country' => 'GB',
        'timezone' => '99',
    ], true, false);

    cli_writeln("Student: created {$username} (id {$userid}).");

    return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
}

/**
 * One of the placeholder courses, created if this is the first run.
 *
 * @param string $key Course key from the sample dataset, used to build the shortname.
 * @param string $fullname Course full name.
 * @param testing_data_generator $generator Data generator.
 * @return stdClass Course record.
 */
function dc_ensure_course(string $key, string $fullname, testing_data_generator $generator): stdClass {
    global $DB;

    $shortname = DC_PREFIX . $key;
    $existing = $DB->get_record('course', ['shortname' => $shortname]);

    if ($existing) {
        if (empty($existing->enablecompletion)) {
            // Without completion there is no Done view, so put it back.
            update_course((object) ['id' => $existing->id, 'enablecompletion' => 1]);
            $existing = get_course($existing->id);
        }
        return $existing;
    }

    return $generator->create_course([
        'fullname' => $fullname,
        'shortname' => $shortname,
        'category' => \core_course_category::get_default()->id,
        'summary' => 'Placeholder course for the deadline checker block.',
        'summaryformat' => FORMAT_HTML,
        'enablecompletion' => 1,
        // Well before the oldest placeholder deadline, which is 25 days in the past.
        'startdate' => usergetmidnight(time()) - 90 * DAYSECS,
    ], ['createsections' => true]);
}

/**
 * Put the block on the course page, so there is somewhere to look at the data.
 *
 * The course page rather than the Dashboard: the block lists every course either way, and this
 * leaves the site's default Dashboard for everybody else alone.
 *
 * @param stdClass $course Course to add the block to.
 */
function dc_ensure_block_on_course(stdClass $course): void {
    global $DB;

    $context = context_course::instance($course->id);

    if ($DB->record_exists('block_instances',
            ['blockname' => 'deadline_checker', 'parentcontextid' => $context->id])) {
        return;
    }

    $page = new moodle_page();
    $page->set_course($course);
    $page->blocks->add_blocks(['side-pre' => ['deadline_checker']], 'course-view-*', null, false);
}

/**
 * Put the block on one user's own Dashboard, and nobody else's.
 *
 * The user gets their personal copy of the Dashboard first — a copy of the site default, blocks
 * and all, which is what Moodle creates the moment anyone customises theirs — and the block goes
 * on that copy. The site default is left exactly as the site had it, so no other account picks
 * this up.
 *
 * @param stdClass $user The user whose Dashboard should show the block.
 * @return string What happened, for the caller to report.
 */
function dc_ensure_block_on_dashboard(stdClass $user): string {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/my/lib.php');

    $mypage = my_copy_page((int) $user->id, MY_PAGE_PRIVATE, 'my-index');

    if (!$mypage) {
        return "no Dashboard page to copy for {$user->username}, skipped.";
    }

    $context = context_user::instance((int) $user->id);

    if ($DB->record_exists('block_instances', [
        'blockname' => 'deadline_checker',
        'parentcontextid' => $context->id,
        'pagetypepattern' => 'my-index',
        'subpagepattern' => $mypage->id,
    ])) {
        return "already on {$user->username}'s own Dashboard.";
    }

    $page = new moodle_page();
    $page->set_context($context);
    $page->set_pagetype('my-index');
    $page->set_subpage((string) $mypage->id);
    $page->blocks->add_blocks(['side-pre' => ['deadline_checker']], 'my-index', (string) $mypage->id, false);

    return "added to {$user->username}'s own Dashboard.";
}

/**
 * Take the block off the site default Dashboard, where an earlier version of this script put it.
 *
 * @return string What happened, or an empty string if there was nothing there.
 */
function dc_clear_default_dashboard_block(): string {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/my/lib.php');

    $default = $DB->get_record('my_pages',
        ['userid' => null, 'private' => MY_PAGE_PRIVATE, 'name' => MY_PAGE_DEFAULT]);

    if (!$default) {
        return '';
    }

    $instances = $DB->get_records('block_instances', [
        'blockname' => 'deadline_checker',
        'parentcontextid' => context_system::instance()->id,
        'pagetypepattern' => 'my-index',
        'subpagepattern' => $default->id,
    ]);

    foreach ($instances as $instance) {
        blocks_delete_instance($instance);
    }

    return $instances
        ? 'Removed the block from the site default Dashboard, so only the accounts below have it.'
        : '';
}

/**
 * The activity for a placeholder task, its deadline moved to today's dataset.
 *
 * An earlier run's activity is reused rather than replaced, so any submissions or grades a
 * developer made against it survive.
 *
 * @param stdClass $course Course to put the activity in.
 * @param \block_deadline_checker\task $task The placeholder task.
 * @param string $modulename Activity type, assign or quiz.
 * @param testing_data_generator $generator Data generator.
 * @return int Course module id.
 */
function dc_ensure_activity(stdClass $course, \block_deadline_checker\task $task, string $modulename,
                            testing_data_generator $generator): int {
    global $CFG, $DB;

    // Each activity type names its deadline differently; the block reads whichever one the
    // activity publishes as a calendar event.
    $deadlinefield = $modulename === 'quiz' ? 'timeclose' : 'duedate';
    $idnumber = DC_PREFIX . $task->id;
    $existing = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $idnumber]);

    if ($existing) {
        $DB->set_field($modulename, $deadlinefield, $task->due, ['id' => $existing->instance]);
        $DB->set_field($modulename, 'name', $task->name, ['id' => $existing->instance]);

        // The activity rewrites its own calendar events from the dates it now holds.
        require_once($CFG->dirroot . '/mod/' . $modulename . '/lib.php');
        call_user_func($modulename . '_refresh_events', $course->id, (int) $existing->instance);
        rebuild_course_cache($course->id, true);

        return (int) $existing->id;
    }

    $record = [
        'course' => $course->id,
        'name' => $task->name,
        'idnumber' => $idnumber,
        'intro' => 'Placeholder activity for the deadline checker block.',
        'introformat' => FORMAT_HTML,
        'section' => 0,
        // Manual completion, which is the completion type the block's own toggle describes.
        'completion' => COMPLETION_TRACKING_MANUAL,
    ];

    $record[$deadlinefield] = $task->due;

    if ($modulename === 'quiz') {
        $record['timeopen'] = 0;
    } else {
        $record['allowsubmissionsfromdate'] = 0;
        $record['cutoffdate'] = 0;
    }

    return (int) $generator->create_module($modulename, $record)->cmid;
}

/**
 * Record whether the test student has completed an activity.
 *
 * Set either way, so re-running after editing the dataset moves a task back out of the Done view
 * as well as into it.
 *
 * @param stdClass $course Course the activity is in.
 * @param string $modulename Activity type.
 * @param int $cmid Course module id.
 * @param int $userid Test student's id.
 * @param bool $complete Whether the activity should be complete.
 */
function dc_set_completion(stdClass $course, string $modulename, int $cmid, int $userid, bool $complete): void {
    $cm = get_coursemodule_from_id($modulename, $cmid, $course->id, true, MUST_EXIST);

    $completion = new completion_info(get_course($course->id));
    $completion->update_state(cm_info::create($cm, $userid),
                              $complete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);
}
