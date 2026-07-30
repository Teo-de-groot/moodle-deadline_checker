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

use block_deadline_checker\personal_task_repository;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Remove one of the current user's own deadlines.
 *
 * Only ever one of their own: the id goes to {@see personal_task_repository::delete()}, which scopes
 * every query to the caller, so another learner's id fails as though it did not exist. A course
 * deadline has no id of this kind and cannot be deleted through here at all.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_task extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return task_payload::parameters([
            'id' => new external_value(PARAM_INT, 'Row id of the deadline to remove'),
        ]);
    }

    /**
     * Remove the deadline and return the refreshed block.
     *
     * @param int $id Row id.
     * @param string $blockid DOM id of the calling block card.
     * @param int $pagesize Rows per page the card is using.
     * @return array The block's payload, with the deadline gone.
     */
    public static function execute(int $id, string $blockid, int $pagesize): array {
        ['id' => $id, 'blockid' => $blockid, 'pagesize' => $pagesize] = self::validate_parameters(
            self::execute_parameters(),
            ['id' => $id, 'blockid' => $blockid, 'pagesize' => $pagesize]);

        self::validate_context(context_system::instance());
        personal_task_repository::require_can_manage();

        personal_task_repository::delete($id);

        return task_payload::build($blockid, $pagesize);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return task_payload::structure();
    }
}
