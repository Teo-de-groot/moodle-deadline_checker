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
 * Strings for component 'block_deadline_checker', language 'en'
 *
 * @package   block_deadline_checker
 * @copyright Daniel Neis <danielneis@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['adddeadline'] = 'Add';
$string['adddeadlinearia'] = 'Add a deadline of my own';
$string['adddeadlinetitle'] = 'Add a deadline';
$string['allcourses'] = 'All courses';
$string['alltasks'] = 'All tasks';
$string['blocktitle'] = 'My deadlines';
$string['completed'] = 'Completed';
$string['deadline_checker:addinstance'] = 'Add a deadline checker block';
$string['deadline_checker:manageowndeadlines'] = 'Keep a list of one\'s own deadlines';
$string['deadline_checker:myaddinstance'] = 'Add a deadline checker block to my moodle';
$string['deadlinecourse'] = 'Course';
$string['deadlinecourse_help'] = 'Filing a deadline under one of your courses groups it with that course\'s own dates and lets you find it with the course filter. Leave this as "No course" for anything that does not belong to one.';
$string['deadlinedue'] = 'Due';
$string['deadlinename'] = 'Deadline';
$string['deadlinename_help'] = 'What you want to call this deadline. Only you can see it.';
$string['deadlinepages'] = 'Deadline pages';
$string['deleteconfirm'] = 'Delete "{$a}"? This cannot be undone. Deadlines set by your courses are not affected.';
$string['deleteconfirmtitle'] = 'Delete this deadline?';
$string['deletedeadline'] = 'Delete';
$string['deletedeadlinearia'] = 'Delete deadline: {$a}';
$string['editdeadline'] = 'Edit';
$string['editdeadlinearia'] = 'Edit deadline: {$a}';
$string['editdeadlinetitle'] = 'Edit deadline';
$string['errorcoursenotyours'] = 'You can only file a deadline under a course you are enrolled on.';
$string['errorduerequired'] = 'Choose a due date.';
$string['errornamerequired'] = 'Give the deadline a name.';
$string['errornametoolong'] = 'A deadline name can be at most {$a} characters long.';
$string['errornotloggedin'] = 'You must be logged in as a real user to keep deadlines of your own.';
$string['errortoomany'] = 'You already have {$a} deadlines of your own, which is the most the block will hold. Delete one you no longer need before adding another.';
$string['defaultvisibletasks'] = 'Default tasks per page';
$string['defaultvisibletasks_desc'] = 'How many deadlines a block shows on each page unless it has been configured itself. Anyone who can edit a page can set a different number for their own block. On tablet and mobile widths the number is capped at three, whatever is set here.';
$string['done'] = 'Done';
$string['duenow'] = 'Due now';
$string['emptyall'] = 'No visible tasks with the current filters.';
$string['emptydone'] = 'No completed tasks yet in this view.';
$string['emptytodoall'] = 'Nothing outstanding. Every deadline on your list is complete.';
$string['emptytodocourse'] = 'Nothing outstanding in this course.';
$string['filterbycourse'] = 'Filter by course';
$string['hideactions'] = 'Hide actions for {$a}';
$string['hideactionscomplete'] = 'Hide actions for {$a}: mark as complete';
$string['hideactionsreopen'] = 'Hide actions for {$a}: reopen task';
$string['hoursleft'] = '{$a}h left';
$string['lessthanonehour'] = '<1 hour';
$string['markascomplete'] = 'Mark as complete';
$string['markascompletearia'] = 'Mark as complete: {$a}';
$string['markedcomplete'] = '{$a} marked as complete.';
$string['metadue'] = '{$a->course} · due {$a->date}';
$string['metasubmitted'] = 'Submitted · {$a}';
$string['ndays'] = '{$a} days';
$string['nextpage'] = 'Next page, page {$a->page} of {$a->total}';
$string['nocourse'] = 'No course';
$string['nextpageunavailable'] = 'Next page, unavailable';
$string['oneday'] = '1 day';
$string['overdue'] = 'Overdue';
$string['pageannounce'] = 'Page {$a->page} of {$a->total}';
$string['pagerange'] = '{$a->first}–{$a->last} of {$a->total}';
$string['pagesingle'] = '{$a->index} of {$a->total}';
$string['pluginname'] = 'Deadline checker';
$string['prevpage'] = 'Previous page, page {$a->page} of {$a->total}';
$string['prevpageunavailable'] = 'Previous page, unavailable';
$string['privacy:metadata:task'] = 'Deadlines a learner has added for themselves. Deadlines that come from a course are not stored here: those belong to the calendar, and completion of a course activity belongs to activity completion.';
$string['privacy:metadata:task:courseid'] = 'The course the learner filed the deadline under, if any.';
$string['privacy:metadata:task:due'] = 'The date the learner set for it.';
$string['privacy:metadata:task:name'] = 'The name the learner gave it.';
$string['privacy:metadata:task:timecompleted'] = 'When the learner marked it complete.';
$string['privacy:metadata:task:timecreated'] = 'When the deadline was added.';
$string['privacy:metadata:task:timemodified'] = 'When the deadline was last changed.';
$string['privacy:metadata:task:userid'] = 'The learner the deadline belongs to.';
$string['reopened'] = '{$a} reopened and moved back to your to-do list.';
$string['reopentask'] = 'Reopen task';
$string['reopentaskaria'] = 'Reopen task: {$a}';
$string['showactions'] = 'Show actions for {$a}';
$string['showactionscomplete'] = 'Show actions for {$a}: mark as complete';
$string['showactionsreopen'] = 'Show actions for {$a}: reopen task';
$string['statecompleted'] = 'completed';
$string['stateduein'] = 'due in {$a} days';
$string['statedueinoneday'] = 'due in 1 day';
$string['stateduenow'] = 'due now, today';
$string['statehourleft'] = 'due today, 1 hour left';
$string['statehoursleft'] = 'due today, {$a} hours left';
$string['stateoverduedays'] = 'overdue by {$a} days';
$string['stateoverdueoneday'] = 'overdue by 1 day';
$string['statesubhour'] = 'due today, less than 1 hour left';
$string['strftimedeadline'] = '%a %d %b, %H:%M';
$string['summarydone'] = '{$a->todo} to do · {$a->done} done';
$string['summaryoverdue'] = '{$a->todo} to do · {$a->overdue} overdue';
$string['taskaria'] = '{$a->name}, {$a->course}, {$a->state}, due {$a->date}.';
$string['taskariacomplete'] = '{$a->name}, {$a->course}, {$a->state}.';
$string['taskariawasdue'] = '{$a->name}, {$a->course}, {$a->state}, was due {$a->date}.';
$string['taskstatus'] = 'Task status';
$string['todo'] = 'To do';
$string['viewall'] = 'All';
$string['viewdone'] = 'Done';
$string['visibletasks'] = 'Tasks per page';
$string['visibletasks_help'] = 'How many deadlines to show on each page of the block. On tablet and mobile widths this is capped at three, whatever is set here.';

// Sample data. These stand in for real course and activity names until the block is wired up
// to course modules, and mirror the dataset in the design prototype.
$string['coursead'] = 'Apprenticeship admin';
$string['coursefm'] = 'Finance for managers';
$string['courseip'] = 'Improvement projects';
$string['courseol'] = 'Operational leadership';
$string['sampletaskassessment3'] = 'Assessment 3: leading teams';
$string['sampletaskassessment4'] = 'Assessment 4: managing budgets';
$string['sampletaskbudgetvariance'] = 'Budget variance exercise';
$string['sampletaskepareadiness'] = 'EPA readiness self-review';
$string['sampletaskobservation'] = 'Team briefing observation';
$string['sampletaskotjjuly'] = 'Off-the-job hours log — July';
$string['sampletaskotjjune'] = 'Off-the-job hours log — June';
$string['sampletaskpeerreview'] = 'Peer review: cohort 12';
$string['sampletaskprojectbrief'] = 'Project brief: process improvement';
$string['sampletaskreflectivelog2'] = 'Reflective log 2';
$string['sampletaskreflectivelog3'] = 'Reflective log 3';
$string['sampletaskstakeholdermap'] = 'Stakeholder map';
$string['sampletaskunit3quiz'] = 'Unit 3 quiz: managing risk';
