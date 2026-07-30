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

namespace block_deadline_checker\external;

use block_deadline_checker\merged_task_source;
use block_deadline_checker\output\deadlines;
use block_deadline_checker\personal_task_repository;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * The block's whole state, returned by every write so the card can redraw itself.
 *
 * Each of these web services hands back the same payload the block embeds on first paint. That is
 * one round trip rather than two, but more importantly it keeps the rule the rest of the plugin
 * follows: every string a learner reads is composed in PHP. After adding a deadline the browser
 * does not have to work out where the new row sorts, what its urgency pill says or how the count
 * line now reads — it is told, by the same code that would have told it on a page load.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_payload {

    /**
     * Parameters every one of these services shares: which block is asking, and how it pages.
     *
     * @param array $own Parameters specific to the service.
     * @return external_function_parameters
     */
    public static function parameters(array $own = []): external_function_parameters {
        return new external_function_parameters($own + [
            'blockid' => new external_value(PARAM_ALPHANUMEXT, 'DOM id of the block card asking'),
            'pagesize' => new external_value(PARAM_INT, 'Rows per page the card is using', VALUE_DEFAULT,
                                             deadlines::DEFAULT_PAGE_SIZE),
        ]);
    }

    /**
     * Rebuild the payload for the current user.
     *
     * @param string $blockid DOM id of the calling block card.
     * @param int $pagesize Rows per page.
     * @return array Same shape as \block_deadline_checker\output\deadlines::js_payload().
     */
    public static function build(string $blockid, int $pagesize): array {
        $now = time();

        // Clamped, not trusted: the caller is a browser, and the value only affects its own paging
        // so there is nothing to gain by rejecting an odd one outright.
        $pagesize = min(deadlines::MAX_PAGE_SIZE, max(deadlines::MIN_PAGE_SIZE, $pagesize));

        $renderable = new deadlines(
            merged_task_source::tasks($now),
            $now,
            $pagesize,
            $blockid,
            personal_task_repository::can_manage(),
        );

        return $renderable->js_payload();
    }

    /**
     * The shape of that payload.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'blockid' => new external_value(PARAM_ALPHANUMEXT, 'DOM id of the block card'),
            'pagesize' => new external_value(PARAM_INT, 'Rows per page'),
            'compactpagesize' => new external_value(PARAM_INT, 'Rows per page on narrow viewports'),
            'tasks' => new external_multiple_structure(self::task_structure()),
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_RAW, 'Course filter identifier'),
                'name' => new external_value(PARAM_RAW, 'Course name as displayed'),
            ])),
            'strings' => new external_single_structure([
                'allcourses' => new external_value(PARAM_RAW, 'Label for the unfiltered option'),
                'pageslabel' => new external_value(PARAM_RAW, 'Accessible name for the pager'),
                'emptytodoall' => new external_value(PARAM_RAW, 'Empty To do, all courses'),
                'emptytodocourse' => new external_value(PARAM_RAW, 'Empty To do, one course'),
                'emptyall' => new external_value(PARAM_RAW, 'Empty All view'),
                'emptydone' => new external_value(PARAM_RAW, 'Empty Done view'),
                'deleteconfirmtitle' => new external_value(PARAM_RAW, 'Title of the delete confirmation'),
                'deleteconfirmyes' => new external_value(PARAM_RAW, 'Confirm button on the delete dialogue'),
                'addtitle' => new external_value(PARAM_RAW, 'Title of the add modal'),
                'edittitle' => new external_value(PARAM_RAW, 'Title of the edit modal'),
            ]),
        ]);
    }

    /**
     * The shape of one task in the payload.
     *
     * @return external_single_structure
     */
    protected static function task_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_ALPHANUMEXT, 'Client-side identifier'),
            'name' => new external_value(PARAM_RAW, 'Deadline name as displayed'),
            'courseid' => new external_value(PARAM_RAW, 'Course filter identifier'),
            'due' => new external_value(PARAM_INT, 'Due date as a unix timestamp'),
            'complete' => new external_value(PARAM_BOOL, 'Whether it is done'),
            'cmid' => new external_value(PARAM_INT, 'Course module, for a course deadline',
                                         VALUE_OPTIONAL, null, NULL_ALLOWED),
            'personalid' => new external_value(PARAM_INT, 'Row id, for a deadline the learner added',
                                               VALUE_OPTIONAL, null, NULL_ALLOWED),
            'cantoggle' => new external_value(PARAM_BOOL, 'Whether completion can be set from here'),
            'canedit' => new external_value(PARAM_BOOL, 'Whether it can be edited or removed from here'),
            'overdue' => new external_value(PARAM_BOOL, 'Whether the due date has passed'),
            'url' => new external_value(PARAM_URL, 'Link to the activity, if any',
                                        VALUE_OPTIONAL, null, NULL_ALLOWED),
            'haslink' => new external_value(PARAM_BOOL, 'Whether there is anything to link to'),
            'editcta' => new external_value(PARAM_RAW, 'Edit button label'),
            'editaria' => new external_value(PARAM_RAW, 'Edit button accessible name'),
            'deletecta' => new external_value(PARAM_RAW, 'Delete button label'),
            'deletearia' => new external_value(PARAM_RAW, 'Delete button accessible name'),
            'deleteconfirm' => new external_value(PARAM_RAW, 'Body of the delete confirmation, naming this deadline'),
            'open' => self::wording_structure('while outstanding'),
            'done' => self::wording_structure('once complete'),
        ]);
    }

    /**
     * The shape of one task's wording in one of its two states.
     *
     * @param string $when Which state, for the parameter descriptions.
     * @return external_single_structure
     */
    protected static function wording_structure(string $when): external_single_structure {
        return new external_single_structure([
            'meta' => new external_value(PARAM_RAW, "Line under the name, {$when}"),
            'aria' => new external_value(PARAM_RAW, "Row accessible name, {$when}"),
            'stamptext' => new external_value(PARAM_RAW, "Urgency pill text, {$when}"),
            'stampvariant' => new external_value(PARAM_ALPHA, "Urgency pill variant, {$when}"),
            'tinted' => new external_value(PARAM_BOOL, "Whether the row is tinted, {$when}"),
            'chevronshow' => new external_value(PARAM_RAW, "Chevron name when collapsed, {$when}"),
            'chevronhide' => new external_value(PARAM_RAW, "Chevron name when expanded, {$when}"),
            'actioncta' => new external_value(PARAM_RAW, "Completion button label, {$when}"),
            'actionaria' => new external_value(PARAM_RAW, "Completion button accessible name, {$when}"),
            'announce' => new external_value(PARAM_RAW, "Live-region message on toggling, {$when}"),
        ]);
    }
}
