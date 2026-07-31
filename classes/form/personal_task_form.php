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

namespace block_deadline_checker\form;

use block_deadline_checker\personal_task_repository;
use context;
use context_system;
use core_form\dynamic_form;
use core_text;
use HTML_QuickForm_select;
use moodle_url;

/**
 * Add or edit one of the learner's own deadlines, in a modal.
 *
 * A dynamic form so the block can open it without leaving the dashboard: losing your place on a
 * page to write down one date is a poor trade. The form is the browser's guard only — the rules
 * that actually protect the database live in {@see personal_task_repository}, which this calls
 * into rather than writing anything itself.
 *
 * A deadline written here is the learner's own and belongs to nobody else, so there is nothing to
 * choose but what to call it and when it is due. There is deliberately no course to file it under:
 * a course's dates belong to the course, and the block reads those from the calendar instead.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personal_task_form extends dynamic_form {

    /**
     * How many options the date and time lists show at once.
     *
     * A select carrying a size renders as a short scrolling list rather than a dropdown, which is
     * what keeps every minute reachable without sixty of them unrolling down the screen. Five is
     * enough to see where you are in the list and small enough that five of these side by side
     * still fit in the modal.
     *
     * @var int
     */
    private const VISIBLE_OPTIONS = 5;

    /**
     * Build the form.
     */
    protected function definition(): void {
        $mform = $this->_form;

        // Everything this form's stylesheet does is scoped under this class, so the scrolling date
        // lists below stay this form's business and no other date selector on the site changes.
        $mform->updateAttributes([
            'class' => trim((string) $mform->getAttribute('class') . ' block_deadline_checker__form'),
        ]);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', 0);

        $mform->addElement('text', 'name', get_string('deadlinename', 'block_deadline_checker'),
                           ['maxlength' => personal_task_repository::NAME_MAX_LENGTH]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('errornamerequired', 'block_deadline_checker'),
                        'required', null, 'client');
        $mform->addHelpButton('name', 'deadlinename', 'block_deadline_checker');

        // Every minute stays selectable — core's default step of one is left alone — but the lists
        // are turned into short scrollers below rather than dropdowns, so sixty minutes and eighty
        // years no longer unroll down the screen.
        $mform->addElement('date_time_selector', 'due',
                           get_string('deadlinedue', 'block_deadline_checker'));
        $mform->addRule('due', get_string('errorduerequired', 'block_deadline_checker'),
                        'required', null, 'client');

        self::make_lists_scroll($mform->getElement('due'));
    }

    /**
     * Turn a date and time selector's dropdowns into short scrolling lists.
     *
     * A select with a size attribute is a list box: the browser shows that many rows and scrolls
     * the rest, keeping every option selectable and the keyboard behaviour a select already has.
     * Nothing is removed, so this costs the form no functionality at all.
     *
     * Only the size is set here. A class cannot be: core's select template writes its own class
     * attribute before it prints anything an element carries, and the first class attribute is the
     * one a browser keeps. The stylesheet therefore hangs off the class on the form instead, which
     * is also what keeps these rules away from every other date selector on the site.
     *
     * @param object $group The date_time_selector, which is a group of selects.
     */
    private static function make_lists_scroll(object $group): void {
        foreach ($group->getElements() as $element) {
            // Optional selectors add an enabling checkbox to the group, which is not a list.
            if (!$element instanceof HTML_QuickForm_select) {
                continue;
            }

            $element->updateAttributes(['size' => self::VISIBLE_OPTIONS]);
        }
    }

    /**
     * Rules the browser cannot be trusted with, checked again server side.
     *
     * These duplicate the repository's rules on purpose: a failed rule here becomes a message
     * beside the field the learner needs to fix, whereas the repository's exceptions are a
     * last line of defence and read like errors rather than guidance.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $errors['name'] = get_string('errornamerequired', 'block_deadline_checker');
        } else if (core_text::strlen($name) > personal_task_repository::NAME_MAX_LENGTH) {
            $errors['name'] = get_string('errornametoolong', 'block_deadline_checker',
                                         personal_task_repository::NAME_MAX_LENGTH);
        }

        if (empty($data['due'])) {
            $errors['due'] = get_string('errorduerequired', 'block_deadline_checker');
        }

        return $errors;
    }

    /**
     * Where the form's permissions are judged.
     *
     * The system context, matching the capability: a personal deadline list is not a thing that
     * happens inside one course.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Refuse the form outright unless this person may keep deadlines of their own.
     */
    protected function check_access_for_dynamic_submission(): void {
        personal_task_repository::require_can_manage();
    }

    /**
     * Load an existing deadline into the form, when editing one.
     *
     * Reading through the repository means an id belonging to somebody else fails here, before it
     * has a chance to put another learner's deadline on screen.
     */
    public function set_data_for_dynamic_submission(): void {
        $id = (int) ($this->optional_param('id', 0, PARAM_INT));

        if ($id <= 0) {
            // Adding. Default to the end of today, which is what most deadlines turn out to be.
            $this->set_data(['id' => 0, 'due' => usergetmidnight(time()) + DAYSECS - MINSECS]);
            return;
        }

        $existing = personal_task_repository::get_own($id);

        $this->set_data([
            'id' => (int) $existing->id,
            'name' => $existing->name,
            'due' => (int) $existing->due,
        ]);
    }

    /**
     * Save the deadline.
     *
     * @return array The row id, so the caller knows what was written.
     */
    public function process_dynamic_submission(): array {
        personal_task_repository::require_can_manage();

        $data = $this->get_data();
        $id = (int) ($data->id ?? 0);

        if ($id > 0) {
            personal_task_repository::update($id, (string) $data->name, (int) $data->due);
        } else {
            $id = personal_task_repository::create((string) $data->name, (int) $data->due);
        }

        return ['id' => $id];
    }

    /**
     * Fallback URL for browsers that cannot open the modal.
     *
     * The dashboard, which is where the block lives and where the learner started.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/my/');
    }
}
