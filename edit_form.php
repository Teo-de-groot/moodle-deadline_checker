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
 * Per-instance settings for the deadline checker block.
 *
 * @package    block_deadline_checker
 * @copyright  Daniel Neis <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_deadline_checker\output\deadlines;

defined('MOODLE_INTERNAL') || die();

class block_deadline_checker_edit_form extends block_edit_form {

    protected function specific_definition($mform) {

        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $sizes = range(deadlines::MIN_PAGE_SIZE, deadlines::MAX_PAGE_SIZE);

        // Starts at whatever the site default is, so this form agrees with the block the editor
        // is looking at rather than with a number hard coded here.
        $default = (int) get_config('block_deadline_checker', 'visibletasks');

        $mform->addElement('select', 'config_visibletasks',
                           get_string('visibletasks', 'block_deadline_checker'),
                           array_combine($sizes, $sizes));
        $mform->addHelpButton('config_visibletasks', 'visibletasks', 'block_deadline_checker');
        $mform->setDefault('config_visibletasks', $default > 0 ? $default : deadlines::DEFAULT_PAGE_SIZE);
        $mform->setType('config_visibletasks', PARAM_INT);
    }
}
