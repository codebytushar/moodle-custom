<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Language strings for local_autograder.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Hybrid Assignment Auto-Grader';
$string['autograder:manage'] = 'Manage hybrid auto-grader settings';

// Admin Settings
$string['setting_enable'] = 'Enable Auto-Grading';
$string['setting_enable_desc'] = 'When enabled, student submissions will be automatically graded using the hybrid engine.';
$string['setting_test_weight'] = 'Unit Tests Weight (%)';
$string['setting_test_weight_desc'] = 'Percentage of total grade determined by functional unit tests (Default: 70).';
$string['setting_ai_weight'] = 'AI Review Weight (%)';
$string['setting_ai_weight_desc'] = 'Percentage of total grade determined by AI code analysis (Default: 30).';
$string['setting_gemini_key'] = 'Gemini API Key';
$string['setting_gemini_key_desc'] = 'Optional: Enter your Gemini API key for live AI code evaluations. If left empty, intelligent local static analysis is used.';
$string['setting_ai_model'] = 'Gemini Model';
$string['setting_ai_model_desc'] = 'Model identifier to use for AI grading (e.g. gemini-2.5-flash).';

// Grading Report Strings
$string['report_title'] = 'Automated Hybrid Evaluation Report';
$string['test_results_header'] = 'Functional Unit Tests';
$string['ai_review_header'] = 'AI Code Quality & Rubric Review';
$string['total_score'] = 'Overall Score';
$string['status_passed'] = 'PASSED';
$string['status_failed'] = 'FAILED';
$string['test_name'] = 'Test Case';
$string['expected'] = 'Expected';
$string['actual'] = 'Actual / Output';
$string['duration'] = 'Time';
