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
 * Site-wide settings for the deadline checker block.
 *
 * @package    block_deadline_checker
 * @copyright  Daniel Neis <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$sizes = range(\block_deadline_checker\output\deadlines::MIN_PAGE_SIZE,
               \block_deadline_checker\output\deadlines::MAX_PAGE_SIZE);

// The default for every block that has not been configured itself. The same range the instance
// form offers, so a site default can never be a number an editor is unable to set.
$settings->add(new admin_setting_configselect(
    'block_deadline_checker/visibletasks',
    get_string('defaultvisibletasks', 'block_deadline_checker'),
    get_string('defaultvisibletasks_desc', 'block_deadline_checker'),
    \block_deadline_checker\output\deadlines::DEFAULT_PAGE_SIZE,
    array_combine($sizes, $sizes)
));
