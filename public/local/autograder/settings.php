<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Settings for local_autograder.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_autograder', get_string('pluginname', 'local_autograder'));

    // Enable / Disable
    $settings->add(new admin_setting_configcheckbox(
        'local_autograder/enabled',
        get_string('setting_enable', 'local_autograder'),
        get_string('setting_enable_desc', 'local_autograder'),
        1
    ));

    // Unit test weight
    $settings->add(new admin_setting_configtext(
        'local_autograder/test_weight',
        get_string('setting_test_weight', 'local_autograder'),
        get_string('setting_test_weight_desc', 'local_autograder'),
        70,
        PARAM_INT
    ));

    // AI weight
    $settings->add(new admin_setting_configtext(
        'local_autograder/ai_weight',
        get_string('setting_ai_weight', 'local_autograder'),
        get_string('setting_ai_weight_desc', 'local_autograder'),
        30,
        PARAM_INT
    ));

    // Gemini API Key
    $settings->add(new admin_setting_configpasswordunmask(
        'local_autograder/gemini_key',
        get_string('setting_gemini_key', 'local_autograder'),
        get_string('setting_gemini_key_desc', 'local_autograder'),
        ''
    ));

    // Gemini Model
    $settings->add(new admin_setting_configtext(
        'local_autograder/ai_model',
        get_string('setting_ai_model', 'local_autograder'),
        get_string('setting_ai_model_desc', 'local_autograder'),
        'gemini-2.5-flash',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
