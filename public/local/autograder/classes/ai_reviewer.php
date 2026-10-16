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
 * AI Code Reviewer using Gemini API with intelligent local static fallback.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_reviewer {

    /**
     * Reviews the student code.
     *
     * @param string $code The student's submitted code
     * @param string $prompt Context or assignment prompt
     * @return array [score, summary, positives, improvements, is_ai]
     */
    public static function review(string $code, string $prompt = ''): array {
        $apikey = get_config('local_autograder', 'gemini_key');
        $model = get_config('local_autograder', 'ai_model') ?: 'gemini-2.5-flash';

        if (!empty($apikey)) {
            $aires = self::call_gemini($code, $prompt, $apikey, $model);
            if ($aires !== null) {
                return $aires;
            }
        }

        // Fallback to local heuristic code review
        return self::local_heuristic_review($code, $prompt);
    }

    /**
     * Calls Google Gemini API.
     */
    private static function call_gemini(string $code, string $prompt, string $key, string $model): ?array {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

        $system_instruction = "You are an expert Python programming teacher and automated grader. "
            . "Evaluate the student's Python code for correctness, clarity, style, edge cases, and algorithmic efficiency. "
            . "Always return your response in strictly valid JSON format with this exact structure: "
            . '{"score": <number 0-100>, "summary": "<1-2 sentence overview>", "positives": ["<strength 1>", "<strength 2>"], "improvements": ["<suggestion 1>", "<suggestion 2>"]}';

        $user_prompt = "Assignment Prompt:\n{$prompt}\n\nStudent Submission:\n```python\n{$code}\n```";

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $system_instruction . "\n\n" . $user_prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2
            ]
        ];

        require_once($GLOBALS['CFG']->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post($url, json_encode($body), ['CURLOPT_TIMEOUT' => 15]);

        if ($curl->get_errno() === 0 && !empty($response)) {
            $data = json_decode($response, true);
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $raw_json = $data['candidates'][0]['content']['parts'][0]['text'];
                $parsed = json_decode($raw_json, true);
                if (isset($parsed['score'])) {
                    return [
                        'score' => (int)$parsed['score'],
                        'summary' => $parsed['summary'] ?? 'Code evaluated successfully.',
                        'positives' => $parsed['positives'] ?? [],
                        'improvements' => $parsed['improvements'] ?? [],
                        'is_ai' => true,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Intelligent local heuristic review when Gemini API key is not supplied.
     */
    private static function local_heuristic_review(string $code, string $prompt): array {
        $score = 100;
        $positives = [];
        $improvements = [];

        // Check 1: Docstrings / Comments
        if (preg_match('/("""|\'\'\'|#)/', $code)) {
            $positives[] = "Code includes helpful comments / docstrings explaining logic.";
        } else {
            $score -= 10;
            $improvements[] = "Add docstrings or inline comments explaining function behavior and parameters.";
        }

        // Check 2: Function naming convention (snake_case in Python)
        if (preg_match('/def [a-z0-9_]+\s*\(/', $code)) {
            $positives[] = "Adheres to standard Python PEP 8 snake_case function naming conventions.";
        } else {
            $score -= 10;
            $improvements[] = "Ensure function names adhere to PEP 8 snake_case standard.";
        }

        // Check 3: Check for factorial recursion or iteration
        if (stripos($code, 'factorial') !== false) {
            if (preg_match('/factorial\s*\([^)]*\-.*1\)/', $code)) {
                $positives[] = "Employs clean recursive design with appropriate decrementing step.";
            } elseif (preg_match('/(for|while)/', $code)) {
                $positives[] = "Implements efficient iterative approach avoiding recursion stack overhead.";
            }
        }

        // Check 4: Check for palindrome normalization
        if (stripos($code, 'is_palindrome') !== false) {
            if (stripos($code, 'lower()') !== false || stripos($code, 'casefold()') !== false) {
                $positives[] = "Properly normalizes string casing before testing symmetry.";
            } else {
                $score -= 15;
                $improvements[] = "Handle case-insensitivity by converting input to lowercase before comparison.";
            }

            if (stripos($code, 'isalnum') !== false || stripos($code, 'replace') !== false) {
                $positives[] = "Handles spaces and special characters for phrase-level palindromes.";
            } else {
                $score -= 15;
                $improvements[] = "Filter out non-alphanumeric characters or spaces (e.g. 'race car').";
            }
        }

        $score = max(20, min(100, $score));

        $summary = $score >= 85
            ? "High-quality, idiomatic Python code with clear structure and good style."
            : ($score >= 60
                ? "Functional code structure, with a few edge cases and style enhancements recommended."
                : "Basic attempt, but requires attention to edge cases, case-insensitivity, and comments.");

        return [
            'score' => $score,
            'summary' => $summary,
            'positives' => $positives,
            'improvements' => $improvements,
            'is_ai' => false,
        ];
    }
}
