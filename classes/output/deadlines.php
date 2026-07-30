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

namespace block_deadline_checker\output;

use block_deadline_checker\task;
use block_deadline_checker\time_remaining;
use renderer_base;
use templatable;
use renderable;

/**
 * Turns a list of deadlines into everything the templates and the JavaScript need.
 *
 * Every string a learner can see is built here, in PHP, including the ones the JavaScript
 * swaps in later. The browser never composes copy of its own and never recalculates a
 * duration — it only chooses between values this class has already worked out.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deadlines implements renderable, templatable {

    /** @var int Smallest page size the instance config allows. */
    public const MIN_PAGE_SIZE = 3;

    /** @var int Largest page size the instance config allows. */
    public const MAX_PAGE_SIZE = 12;

    /** @var int Page size used when neither the instance nor the site has said otherwise. */
    public const DEFAULT_PAGE_SIZE = 5;

    /** @var int Page size used on tablet and mobile widths, regardless of config. */
    public const COMPACT_PAGE_SIZE = 3;

    /**
     * @param task[] $tasks Every task available to the learner, in any order.
     * @param int $now Current time as a unix timestamp.
     * @param int $pagesize Tasks per page.
     * @param string $blockid Unique id for this block instance, used to scope the markup.
     * @param bool $canadd Whether this learner may keep deadlines of their own, which decides
     *                     whether the card offers the Add control at all. Passed in rather than
     *                     checked here, so this class stays a presenter and the capability is
     *                     asked about in one place.
     */
    public function __construct(
        protected array $tasks,
        protected int $now,
        protected int $pagesize,
        protected string $blockid,
        protected bool $canadd = false,
    ) {
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output Renderer.
     * @return array Context for block_deadline_checker/block.
     */
    public function export_for_template(renderer_base $output): array {
        $todo = $this->sort(array_filter($this->tasks, fn(task $t) => !$t->complete), false);
        $initial = array_slice($todo, 0, $this->pagesize);

        return [
            'blockid' => $this->blockid,
            'title' => get_string('blocktitle', 'block_deadline_checker'),
            'summary' => $this->summary($this->tasks),
            'canadd' => $this->canadd,
            'addlabel' => get_string('adddeadline', 'block_deadline_checker'),
            'addaria' => get_string('adddeadlinearia', 'block_deadline_checker'),
            'filterlabel' => get_string('filterbycourse', 'block_deadline_checker'),
            'allcourses' => get_string('allcourses', 'block_deadline_checker'),
            'statuslabel' => get_string('taskstatus', 'block_deadline_checker'),
            'views' => [
                ['key' => 'todo', 'label' => get_string('todo', 'block_deadline_checker'),
                 'aria' => null, 'selected' => true],
                ['key' => 'all', 'label' => get_string('viewall', 'block_deadline_checker'),
                 'aria' => get_string('alltasks', 'block_deadline_checker'), 'selected' => false],
                ['key' => 'done', 'label' => get_string('viewdone', 'block_deadline_checker'),
                 'aria' => get_string('completed', 'block_deadline_checker'), 'selected' => false],
            ],
            'courseoptions' => $this->course_options($todo),
            // The first page of the To do view, so the block is complete and readable before
            // any JavaScript runs.
            'list' => $this->list_context($initial, count($todo), 0, 'todo', 'all'),
            // Embedded in the page as JSON rather than passed through js_call_amd, which warns
            // above 1024 characters and is not a transport for a payload this size.
            'taskdata' => $this->task_data_json(),
        ];
    }

    /**
     * The browser's copy of the data, ready to sit inside a script element.
     *
     * Angle brackets, ampersands and quotes are escaped to \u sequences, so the payload cannot
     * close the script element early whatever ends up in an activity name.
     *
     * @return string JSON.
     */
    public function task_data_json(): string {
        return json_encode($this->js_payload(),
                           JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * The context for the part of the block the JavaScript re-renders.
     *
     * @param task[] $page Tasks on the current page.
     * @param int $total Tasks in the filtered list, across all pages.
     * @param int $page0 Zero-based page number.
     * @param string $view Current status view: todo, all or done.
     * @param string $course Current course filter, or "all".
     * @return array Context for block_deadline_checker/tasks.
     */
    public function list_context(array $page, int $total, int $page0, string $view, string $course): array {
        $pagecount = max(1, (int) ceil($total / $this->pagesize));
        $first = $page0 * $this->pagesize + 1;
        $last = min($total, $first + $this->pagesize - 1);

        return [
            'blockid' => $this->blockid,
            'pageslabel' => get_string('deadlinepages', 'block_deadline_checker'),
            'rows' => array_map(fn(task $t) => $this->row($t), $page),
            'hasrows' => !empty($page),
            'emptytext' => $this->empty_text($view, $course),
            'haspages' => $pagecount > 1,
            'pageinfo' => $first === $last
                ? get_string('pagesingle', 'block_deadline_checker',
                             (object) ['index' => $first, 'total' => $total])
                : get_string('pagerange', 'block_deadline_checker',
                             (object) ['first' => $first, 'last' => $last, 'total' => $total]),
            'prevdisabled' => $page0 === 0,
            'nextdisabled' => $page0 >= $pagecount - 1,
            'prevlabel' => $page0 === 0
                ? get_string('prevpageunavailable', 'block_deadline_checker')
                : get_string('prevpage', 'block_deadline_checker',
                             (object) ['page' => $page0, 'total' => $pagecount]),
            'nextlabel' => $page0 >= $pagecount - 1
                ? get_string('nextpageunavailable', 'block_deadline_checker')
                : get_string('nextpage', 'block_deadline_checker',
                             (object) ['page' => $page0 + 2, 'total' => $pagecount]),
        ];
    }

    /**
     * One task row.
     *
     * @param task $task The task.
     * @return array Row context.
     */
    protected function row(task $task): array {
        $stamp = time_remaining::stamp($task, $this->now);

        return [
            'id' => $task->id,
            'name' => $task->name,
            'url' => $task->url,
            'haslink' => $task->url !== null,
            'meta' => $this->meta($task),
            'aria' => $this->aria($task),
            'stamptext' => $stamp['text'],
            'stampvariant' => $stamp['variant'],
            'tinted' => $stamp['tinted'],
            'complete' => $task->complete,
            'expanded' => false,
            // No button unless the learner is the one who decides: see task::$manualcompletion.
            'cantoggle' => $this->can_toggle($task),
            // Only their own deadlines. A course's dates stay the course's to change.
            'canedit' => $task->is_personal(),
            'hasactions' => $this->can_toggle($task) || $task->is_personal(),
            'chevronlabel' => $this->chevron_label($task, false),
            'actioncta' => $task->complete
                ? get_string('reopentask', 'block_deadline_checker')
                : get_string('markascomplete', 'block_deadline_checker'),
            'actionaria' => $task->complete
                ? get_string('reopentaskaria', 'block_deadline_checker', $task->name)
                : get_string('markascompletearia', 'block_deadline_checker', $task->name),
            'editcta' => get_string('editdeadline', 'block_deadline_checker'),
            'editaria' => get_string('editdeadlinearia', 'block_deadline_checker', $task->name),
            'deletecta' => get_string('deletedeadline', 'block_deadline_checker'),
            'deletearia' => get_string('deletedeadlinearia', 'block_deadline_checker', $task->name),
        ];
    }

    /**
     * Everything the browser needs to re-render without asking the server again.
     *
     * Each task carries both its open and its complete wording, so toggling completion is a
     * choice between two strings PHP has already produced rather than a string the browser
     * builds for itself.
     *
     * @return array Passed straight to the AMD module.
     */
    public function js_payload(): array {
        $tasks = [];

        foreach ($this->sort($this->tasks, false) as $task) {
            $open = $task->with_complete(false);
            $done = $task->with_complete(true);
            $stamp = time_remaining::stamp($open, $this->now);

            $tasks[] = [
                'id' => $task->id,
                'name' => $task->name,
                'courseid' => $task->courseid,
                'due' => $task->due,
                'complete' => $task->complete,
                // What the browser records completion against, and whether it may. A deadline has
                // one or the other: a cmid means core's completion web service, a personalid means
                // this plugin's. Never both, and the browser must not have to guess which.
                'cmid' => $task->cmid,
                'personalid' => $task->personalid,
                'cantoggle' => $this->can_toggle($task),
                'canedit' => $task->is_personal(),
                // Kept alongside the wording so the browser can recount the summary line
                // without having to work out what "overdue" means.
                'overdue' => $task->daydiff < 0,
                'url' => $task->url,
                'haslink' => $task->url !== null,
                // Editing and removing do not depend on whether the deadline is done, so unlike
                // the wording below these sit once on the task rather than in both states.
                'editcta' => get_string('editdeadline', 'block_deadline_checker'),
                'editaria' => get_string('editdeadlinearia', 'block_deadline_checker', $task->name),
                'deletecta' => get_string('deletedeadline', 'block_deadline_checker'),
                'deletearia' => get_string('deletedeadlinearia', 'block_deadline_checker', $task->name),
                // Named here rather than assembled in the confirmation dialogue, so the learner is
                // asked about a deadline by name and the browser still composes nothing.
                'deleteconfirm' => get_string('deleteconfirm', 'block_deadline_checker', $task->name),
                'open' => [
                    'meta' => $this->meta($open),
                    'aria' => $this->aria($open),
                    'stamptext' => $stamp['text'],
                    'stampvariant' => $stamp['variant'],
                    'tinted' => $stamp['tinted'],
                    'chevronshow' => $this->chevron_label($open, false),
                    'chevronhide' => $this->chevron_label($open, true),
                    'actioncta' => get_string('markascomplete', 'block_deadline_checker'),
                    'actionaria' => get_string('markascompletearia', 'block_deadline_checker', $task->name),
                    'announce' => get_string('markedcomplete', 'block_deadline_checker', $task->name),
                ],
                'done' => [
                    'meta' => $this->meta($done),
                    'aria' => $this->aria($done),
                    'stamptext' => get_string('done', 'block_deadline_checker'),
                    'stampvariant' => time_remaining::VARIANT_DONE,
                    'tinted' => false,
                    'chevronshow' => $this->chevron_label($done, false),
                    'chevronhide' => $this->chevron_label($done, true),
                    'actioncta' => get_string('reopentask', 'block_deadline_checker'),
                    'actionaria' => get_string('reopentaskaria', 'block_deadline_checker', $task->name),
                    'announce' => get_string('reopened', 'block_deadline_checker', $task->name),
                ],
            ];
        }

        return [
            'blockid' => $this->blockid,
            'pagesize' => $this->pagesize,
            'compactpagesize' => self::COMPACT_PAGE_SIZE,
            'tasks' => $tasks,
            'courses' => $this->courses(),
            'strings' => [
                'allcourses' => get_string('allcourses', 'block_deadline_checker'),
                'pageslabel' => get_string('deadlinepages', 'block_deadline_checker'),
                'emptytodoall' => get_string('emptytodoall', 'block_deadline_checker'),
                'emptytodocourse' => get_string('emptytodocourse', 'block_deadline_checker'),
                'emptyall' => get_string('emptyall', 'block_deadline_checker'),
                'emptydone' => get_string('emptydone', 'block_deadline_checker'),
                'deleteconfirmtitle' => get_string('deleteconfirmtitle', 'block_deadline_checker'),
                'deleteconfirmyes' => get_string('deletedeadline', 'block_deadline_checker'),
                'addtitle' => get_string('adddeadlinetitle', 'block_deadline_checker'),
                'edittitle' => get_string('editdeadlinetitle', 'block_deadline_checker'),
            ],
        ];
    }

    /**
     * The header summary line, scoped to the selected course but not to the status view.
     *
     * @param task[] $tasks Tasks in scope.
     * @return string
     */
    protected function summary(array $tasks): string {
        $todo = count(array_filter($tasks, fn(task $t) => !$t->complete));
        $overdue = count(array_filter($tasks, fn(task $t) => !$t->complete && $t->daydiff < 0));
        $done = count($tasks) - $todo;

        if ($overdue > 0) {
            return get_string('summaryoverdue', 'block_deadline_checker',
                              (object) ['todo' => $todo, 'overdue' => $overdue]);
        }

        return get_string('summarydone', 'block_deadline_checker',
                          (object) ['todo' => $todo, 'done' => $done]);
    }

    /**
     * Courses offered by the filter: only those with at least one task in the current view.
     *
     * @param task[] $inview Tasks visible in the current status view.
     * @return array[] Option contexts.
     */
    protected function course_options(array $inview): array {
        $present = array_map(fn(task $t) => $t->courseid, $inview);
        $options = [];

        foreach ($this->courses() as $course) {
            if (in_array($course['id'], $present, true)) {
                $options[] = ['value' => $course['id'], 'label' => $course['name'], 'selected' => false];
            }
        }

        return $options;
    }

    /**
     * The courses the tasks belong to, in the order they were first seen.
     *
     * A list of pairs rather than a map keyed by course id, because PHP turns a numeric string
     * array key into an integer: keying by a real course id such as "12" would hand the filter
     * and the browser the number 12 while every task still says "12", and identity comparisons
     * against them would all fail.
     *
     * @return array[] Each ['id' => string, 'name' => string].
     */
    protected function courses(): array {
        $courses = [];
        $seen = [];

        foreach ($this->tasks as $task) {
            // Only ever a set membership test, so the key's own type does not matter here.
            if (isset($seen[$task->courseid])) {
                continue;
            }

            $seen[$task->courseid] = true;
            $courses[] = ['id' => $task->courseid, 'name' => $task->coursename];
        }

        return $courses;
    }

    /**
     * The line under the task name.
     *
     * @param task $task The task.
     * @return string
     */
    protected function meta(task $task): string {
        if ($task->complete) {
            return get_string('metasubmitted', 'block_deadline_checker', $task->coursename);
        }

        return get_string('metadue', 'block_deadline_checker', (object) [
            'course' => $task->coursename,
            'date' => $this->due_date($task),
        ]);
    }

    /**
     * The row's accessible name. Carries the urgency the pill shows in colour.
     *
     * @param task $task The task.
     * @return string
     */
    protected function aria(task $task): string {
        $state = time_remaining::accessible_state($task, $this->now);

        if ($task->complete) {
            return get_string('taskariacomplete', 'block_deadline_checker', (object) [
                'name' => $task->name,
                'course' => $task->coursename,
                'state' => $state,
            ]);
        }

        $key = $task->daydiff < 0 ? 'taskariawasdue' : 'taskaria';

        return get_string($key, 'block_deadline_checker', (object) [
            'name' => $task->name,
            'course' => $task->coursename,
            'state' => $state,
            'date' => $this->due_date($task),
        ]);
    }

    /**
     * Whether the learner can mark this task complete from the block.
     *
     * A deadline the learner wrote down themselves is always theirs to tick off. For an activity
     * both halves matter: it has to track completion manually, and there has to be a course module
     * to record it against.
     *
     * @param task $task The task.
     * @return bool
     */
    protected function can_toggle(task $task): bool {
        if ($task->is_personal()) {
            return true;
        }

        return $task->manualcompletion && $task->cmid !== null;
    }

    /**
     * The chevron's accessible name, which says what expanding will offer.
     *
     * A personal deadline's strip holds more than one thing, so naming a single action would be
     * wrong. Course rows keep the wording they had, which names the only action there is.
     *
     * @param task $task The task.
     * @param bool $expanded Whether the action strip is currently open.
     * @return string
     */
    protected function chevron_label(task $task, bool $expanded): string {
        if ($task->is_personal()) {
            return get_string($expanded ? 'hideactions' : 'showactions',
                              'block_deadline_checker', $task->name);
        }

        $key = match (true) {
            $expanded && $task->complete => 'hideactionsreopen',
            $expanded => 'hideactionscomplete',
            $task->complete => 'showactionsreopen',
            default => 'showactionscomplete',
        };

        return get_string($key, 'block_deadline_checker', $task->name);
    }

    /**
     * The due date as the learner sees it, in their own timezone.
     *
     * @param task $task The task.
     * @return string For example "Wed 5 Aug, 17:00".
     */
    protected function due_date(task $task): string {
        return userdate($task->due, get_string('strftimedeadline', 'block_deadline_checker'));
    }

    /**
     * Empty state copy, which depends on both the view and whether a course is selected.
     *
     * @param string $view Current status view.
     * @param string $course Current course filter, or "all".
     * @return string
     */
    protected function empty_text(string $view, string $course): string {
        $key = match ($view) {
            'done' => 'emptydone',
            'all' => 'emptyall',
            default => $course === 'all' ? 'emptytodoall' : 'emptytodocourse',
        };

        return get_string($key, 'block_deadline_checker');
    }

    /**
     * Incomplete before complete, then by due date.
     *
     * @param task[] $tasks Tasks to sort.
     * @param bool $newestfirst Descending due date, used by the Done view.
     * @return task[] Re-indexed.
     */
    protected function sort(array $tasks, bool $newestfirst): array {
        $tasks = array_values($tasks);

        usort($tasks, function(task $a, task $b) use ($newestfirst) {
            if ($a->complete !== $b->complete) {
                return $a->complete ? 1 : -1;
            }
            return $newestfirst ? $b->due <=> $a->due : $a->due <=> $b->due;
        });

        return $tasks;
    }
}
