<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_autograder;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_assign assessable_submitted events.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Triggered when an assignment submission is submitted.
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event) {
        $enabled = get_config('local_autograder', 'enabled');
        if ($enabled === false || $enabled === '0' || $enabled === 0) {
            return;
        }

        $grader = new \local_autograder\grader();
        $grader->evaluate_submission($event);
    }
}
