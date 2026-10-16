<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_autograder;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Core hybrid grading orchestrator.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grader {

    /**
     * Evaluates a submission triggered by assessable_submitted event.
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public function evaluate_submission(\mod_assign\event\assessable_submitted $event) {
        $cmid = $event->contextinstanceid;
        $userid = $event->userid;

        $this->grade_user($cmid, $userid);
    }

    /**
     * Grades a user submission by course module ID and user ID.
     *
     * @param int $cmid Course module ID
     * @param int $userid Student user ID
     * @return array Evaluation details
     */
    public function grade_user(int $cmid, int $userid): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);

        $submission = $assign->get_user_submission($userid, false);
        if (!$submission) {
            return ['status' => 'error', 'message' => 'No submission record found.'];
        }

        // 1. Extract student code
        $code = $this->extract_code($assign, $submission);
        if (empty(trim($code))) {
            return ['status' => 'error', 'message' => 'Submission contained no code or readable file.'];
        }

        // 2. Extract or determine test cases
        $tests = $this->get_test_cases($assign->get_instance()->intro);

        // 3. Run Unit Tests (Functional Correctness)
        $test_results = \local_autograder\test_runner::run($code, $tests);

        // 4. Run AI Code Review (Quality, Complexity, Style)
        $ai_results = \local_autograder\ai_reviewer::review($code, $assign->get_instance()->intro);

        // 5. Compute Hybrid Score
        $test_weight = (int)(get_config('local_autograder', 'test_weight') ?: 70);
        $ai_weight = (int)(get_config('local_autograder', 'ai_weight') ?: 30);

        // Normalize weights if they do not sum to 100
        $total_weight = $test_weight + $ai_weight;
        if ($total_weight > 0) {
            $test_pct = $test_weight / $total_weight;
            $ai_pct = $ai_weight / $total_weight;
        } else {
            $test_pct = 0.7;
            $ai_pct = 0.3;
        }

        $final_score = round(($test_results['score'] * $test_pct) + ($ai_results['score'] * $ai_pct));
        $final_score = max(0, min(100, $final_score));

        // Scale to assignment max grade if not 100
        $max_grade = $assign->get_instance()->grade;
        $scaled_grade = ($max_grade > 0 && $max_grade != 100)
            ? round(($final_score / 100) * $max_grade, 2)
            : $final_score;

        // 6. Generate Feedback Report HTML
        $feedback_html = $this->generate_report_html(
            $final_score,
            $test_results,
            $ai_results,
            $test_weight,
            $ai_weight
        );

        // 7. Save Grade and Feedback to Moodle Assignment & Gradebook
        $grade_data = new \stdClass();
        $grade_data->attemptnumber = $submission->attemptnumber ?? 0;
        $grade_data->grade = $scaled_grade;
        $grade_data->assignfeedbackcomments_editor = [
            'text'   => $feedback_html,
            'format' => FORMAT_HTML,
        ];
        $grade_data->feedbackcomments_editor = [
            'text'   => $feedback_html,
            'format' => FORMAT_HTML,
        ];

        // Save grade as admin/grader
        global $USER;
        $prevuser = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $assign->save_grade($userid, $grade_data);
        } finally {
            \core\session\manager::set_user($prevuser);
        }

        return [
            'status' => 'success',
            'final_score' => $final_score,
            'test_results' => $test_results,
            'ai_results' => $ai_results,
        ];
    }

    /**
     * Extracts student code from online text or uploaded file.
     */
    private function extract_code(\assign $assign, \stdClass $submission): string {
        global $DB;

        // Check online text submission
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
        if ($onlinetext && !empty($onlinetext->onlinetext)) {
            $raw = $onlinetext->onlinetext;
            // Decode HTML entities (e.g. &lt; to <)
            $decoded = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!empty(trim($decoded))) {
                return $decoded;
            }
        }

        // Check file submission (.py files)
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $assign->get_context()->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'id',
            false
        );

        foreach ($files as $file) {
            $filename = strtolower($file->get_filename());
            if (str_ends_with($filename, '.py') || str_ends_with($filename, '.txt')) {
                return $file->get_content();
            }
        }

        return '';
    }

    /**
     * Extracts test cases from assignment description or supplies defaults.
     */
    private function get_test_cases(string $intro): array {
        // Check if intro has <tests>[...]</tests> block
        if (preg_match('/<tests>\s*(\[.*?\])\s*<\/tests>/s', $intro, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // Default test suite for our Assignment 1
        return [
            [
                'name' => 'Factorial of 5 (Standard)',
                'expression' => 'factorial(5)',
                'expected' => '120',
                'points' => 25,
            ],
            [
                'name' => 'Factorial of 0 (Edge Case)',
                'expression' => 'factorial(0)',
                'expected' => '1',
                'points' => 25,
            ],
            [
                'name' => 'Simple Palindrome (racecar)',
                'expression' => 'is_palindrome("racecar")',
                'expected' => 'True',
                'points' => 25,
            ],
            [
                'name' => 'Phrase Palindrome with Spaces/Punctuation',
                'expression' => 'is_palindrome("A man, a plan, a canal: Panama")',
                'expected' => 'True',
                'points' => 25,
            ],
        ];
    }

    /**
     * Generates a beautifully styled evaluation report.
     */
    private function generate_report_html(
        int $final_score,
        array $tests,
        array $ai,
        int $w_test,
        int $w_ai
    ): string {
        $badge_color = ($final_score >= 80) ? '#28a745' : (($final_score >= 50) ? '#fd7e14' : '#dc3545');
        $ai_badge = !empty($ai['is_ai']) ? 'Google Gemini AI' : 'Heuristic Static Analysis';

        $html = '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; background-color: #fdfdfd; margin-top: 10px;">';

        // Header with overall score
        $html .= '<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; margin-bottom: 15px;">';
        $html .= '<div>';
        $html .= '<h3 style="margin: 0; color: #212529;">🤖 Hybrid Automated Evaluation Report</h3>';
        $html .= '<small style="color: #6c757d;">Functional Tests (' . $w_test . '%) + AI Code Review (' . $w_ai . '%)</small>';
        $html .= '</div>';
        $html .= '<div style="background-color: ' . $badge_color . '; color: #fff; font-size: 22px; font-weight: bold; padding: 8px 18px; border-radius: 20px;">' . $final_score . ' / 100</div>';
        $html .= '</div>';

        // Section 1: Unit Tests Breakdown
        $html .= '<h4 style="margin: 15px 0 10px 0; color: #343a40;">🧪 Functional Unit Tests (' . $tests['passed_count'] . '/' . $tests['total_count'] . ' Passed — ' . $tests['score'] . '%)</h4>';
        $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px;">';
        $html .= '<thead><tr style="background-color: #f8f9fa; text-align: left; border-bottom: 1px solid #dee2e6;">';
        $html .= '<th style="padding: 8px;">Status</th><th style="padding: 8px;">Test Case</th><th style="padding: 8px;">Expected</th><th style="padding: 8px;">Actual</th><th style="padding: 8px;">Time</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($tests['tests'] as $t) {
            $row_bg = $t['passed'] ? '#f0fff4' : '#fff5f5';
            $icon = $t['passed'] ? '<span style="color:#28a745; font-weight:bold;">✅ PASS</span>' : '<span style="color:#dc3545; font-weight:bold;">❌ FAIL</span>';
            $html .= '<tr style="background-color: ' . $row_bg . '; border-bottom: 1px solid #eee;">';
            $html .= '<td style="padding: 8px;">' . $icon . '</td>';
            $html .= '<td style="padding: 8px;"><code>' . htmlspecialchars($t['name']) . '</code></td>';
            $html .= '<td style="padding: 8px;"><code>' . htmlspecialchars($t['expected']) . '</code></td>';
            $html .= '<td style="padding: 8px;"><code>' . htmlspecialchars($t['actual']) . '</code></td>';
            $html .= '<td style="padding: 8px; color: #6c757d;">' . $t['elapsed_ms'] . ' ms</td>';
            $html .= '</tr>';
            if (!empty($t['error'])) {
                $html .= '<tr style="background-color: ' . $row_bg . ';"><td colspan="5" style="padding: 0 8px 8px 8px; color: #dc3545; font-size: 12px;">Error: ' . htmlspecialchars($t['error']) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';

        // Section 2: AI Code Review
        $html .= '<h4 style="margin: 15px 0 10px 0; color: #343a40;">💡 AI Code Review (' . $ai['score'] . '% Score) <span style="font-size: 11px; font-weight: normal; background-color: #e2e3e5; color: #383d41; padding: 2px 6px; border-radius: 4px; margin-left: 5px;">' . $ai_badge . '</span></h4>';
        $html .= '<p style="font-style: italic; color: #495057; margin-bottom: 10px;">"' . htmlspecialchars($ai['summary']) . '"</p>';

        if (!empty($ai['positives'])) {
            $html .= '<div style="margin-bottom: 8px;"><strong>Strengths:</strong><ul style="margin: 5px 0; padding-left: 20px; color: #28a745;">';
            foreach ($ai['positives'] as $pos) {
                $html .= '<li><span style="color: #212529;">' . htmlspecialchars($pos) . '</span></li>';
            }
            $html .= '</ul></div>';
        }

        if (!empty($ai['improvements'])) {
            $html .= '<div><strong>Recommendations for Improvement:</strong><ul style="margin: 5px 0; padding-left: 20px; color: #fd7e14;">';
            foreach ($ai['improvements'] as $imp) {
                $html .= '<li><span style="color: #212529;">' . htmlspecialchars($imp) . '</span></li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '</div>';
        return $html;
    }
}
