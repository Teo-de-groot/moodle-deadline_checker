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
use context_course;
use context_system;
use core_form\dynamic_form;
use core_text;
use moodle_url;

/**
 * Add or edit one of the learner's own deadlines, in a modal.
 *
 * A dynamic form so the block can open it without leaving the dashboard: losing your place on a
 * page to write down one date is a poor trade. The form is the browser's guard only — the rules
 * that actually protect the database live in {@see personal_task_repository}, which this calls
 * into rather than writing anything itself.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personal_task_form extends dynamic_form {

    /**
     * Build the form.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', 0);

        $mform->addElement('text', 'name', get_string('deadlinename', 'block_deadline_checker'),
                           ['maxlength' => personal_task_repository::NAME_MAX_LENGTH]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('errornamerequired', 'block_deadline_checker'),
                        'required', null, 'client');
        $mform->addHelpButton('name', 'deadlinename', 'block_deadline_checker');

        $mform->addElement('date_time_selector', 'due',
                           get_string('deadlinedue', 'block_deadline_checker'));
        $mform->addRule('due', get_string('errorduerequired', 'block_deadline_checker'),
                        'required', null, 'client');

        $mform->addElement('select', 'courseid', get_string('deadlinecourse', 'block_deadline_checker'),
                           $this->course_options());
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);
        $mform->addHelpButton('courseid', 'deadlinecourse', 'block_deadline_checker');
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
     * Courses the learner may file a deadline under: the ones they are actually on.
     *
     * @return string[] Course id => name, with 0 for no course at all.
     */
    protected function course_options(): array {
        global $USER;

        $options = [0 => get_string('nocourse', 'block_deadline_checker')];

        foreach (enrol_get_all_users_courses((int) $USER->id, true, ['id', 'fullname']) as $course) {
            $options[(int) $course->id] = format_string($course->fullname, true, [
                'context' => context_course::instance($course->id),
            ]);
        }

        return $options;
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
            $this->set_data(['id' => 0, 'courseid' => 0, 'due' => usergetmidnight(time()) + DAYSECS - MINSECS]);
            return;
        }

        $existing = personal_task_repository::get_own($id);

        $this->set_data([
            'id' => (int) $existing->id,
            'name' => $existing->name,
            'due' => (int) $existing->due,
            'courseid' => (int) $existing->courseid,
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
        $courseid = empty($data->courseid) ? null : (int) $data->courseid;

        if ($id > 0) {
            personal_task_repository::update($id, (string) $data->name, (int) $data->due, $courseid);
        } else {
            $id = personal_task_repository::create((string) $data->name, (int) $data->due, $courseid);
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
