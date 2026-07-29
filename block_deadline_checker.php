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
 * Deadline checker block.
 *
 * @package    block_deadline_checker
 * @copyright  Daniel Neis <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_deadline_checker\output\deadlines;
use block_deadline_checker\sample_task_source;

defined('MOODLE_INTERNAL') || die();

class block_deadline_checker extends block_base {

    /** @var int Page size used when the instance has not been configured. */
    protected const DEFAULT_PAGE_SIZE = 5;

    function init() {
        $this->title = get_string('blocktitle', 'block_deadline_checker');
    }

    /**
     * The card carries its own heading and summary line, so the theme's block header would be
     * a second copy of the same title.
     *
     * @return bool
     */
    public function hide_header() {
        return true;
    }

    function get_content() {
        global $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (empty($this->instance)) {
            return $this->content;
        }

        $now = time();

        $blockid = 'dlblock-' . $this->instance->id;

        $renderable = new deadlines(
            sample_task_source::tasks($now),
            $now,
            $this->page_size(),
            $blockid,
        );


        $this->page->requires->js_call_amd('block_deadline_checker/deadlines', 'init', [$blockid]);

        $this->content->text = $OUTPUT->render_from_template('block_deadline_checker/block',
                                                             $renderable->export_for_template($OUTPUT));

        return $this->content;
    }


    protected function page_size(): int {
        $configured = (int) ($this->config->visibletasks ?? self::DEFAULT_PAGE_SIZE);

        return min(deadlines::MAX_PAGE_SIZE, max(deadlines::MIN_PAGE_SIZE, $configured));
    }

    public function applicable_formats() {
        return array('all' => true,
                     'site' => true,
                     'site-index' => true,
                     'my' => true,
                     'course-view' => true,
                     'course-view-social' => false,
                     'mod' => true,
                     'mod-quiz' => false);
    }

    public function instance_allow_multiple() {
          return true;
    }

    function has_config() {return true;}

    public function cron() {
            mtrace( "Hey, my cron script is running" );

                 // do something

                      return true;
    }
}
