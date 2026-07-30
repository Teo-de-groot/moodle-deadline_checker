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
 * Web services for the deadline checker block.
 *
 * Adding and editing are not here: the modal uses core's dynamic form service, so the form class
 * is both the validation and the write. What remains are the two actions with no form behind them
 * and the read that follows a save.
 *
 * All three are ajax => true and none is in a service, so they are reachable from a logged-in
 * session and not from a token: these act on the caller's own deadlines, which is not something a
 * site should be able to hand an integration.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_deadline_checker_get_tasks' => [
        'classname' => 'block_deadline_checker\external\get_tasks',
        'description' => 'Read the deadlines the current user can see.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'block_deadline_checker_delete_task' => [
        'classname' => 'block_deadline_checker\external\delete_task',
        'description' => 'Remove one of the current user\'s own deadlines.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/deadline_checker:manageowndeadlines',
    ],
    'block_deadline_checker_set_completion' => [
        'classname' => 'block_deadline_checker\external\set_completion',
        'description' => 'Set whether one of the current user\'s own deadlines is complete.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/deadline_checker:manageowndeadlines',
    ],
];
