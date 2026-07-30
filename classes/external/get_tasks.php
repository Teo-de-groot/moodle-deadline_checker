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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;

/**
 * Re-read the current user's deadlines.
 *
 * Called after the add or edit modal saves, because the form knows it wrote a row but not where
 * that row now sorts, what its urgency pill says or how the count line reads.
 *
 * Takes no user id and never will: it reports on whoever is logged in. There is no parameter here
 * that could be pointed at somebody else.
 *
 * @package    block_deadline_checker
 * @copyright  2026 Accipio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_tasks extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return task_payload::parameters();
    }

    /**
     * Return every deadline the current user can see.
     *
     * @param string $blockid DOM id of the calling block card.
     * @param int $pagesize Rows per page the card is using.
     * @return array The block's payload.
     */
    public static function execute(string $blockid, int $pagesize): array {
        ['blockid' => $blockid, 'pagesize' => $pagesize] = self::validate_parameters(
            self::execute_parameters(), ['blockid' => $blockid, 'pagesize' => $pagesize]);

        // The learner's own dashboard, so the system context. Reading requires nothing beyond
        // being logged in: these are the deadlines they can already see.
        self::validate_context(\context_system::instance());

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
