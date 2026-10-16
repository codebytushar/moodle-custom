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
 * Sandboxed execution engine for running Python test suites.
 *
 * @package    local_autograder
 * @copyright  2026 Tushar Gohil
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_runner {

    /**
     * Executes test cases against student's code.
     *
     * @param string $code Python source code submitted by the student
     * @param array $test_specs Array of test case definitions
     * @return array [score, passed_count, total_count, tests]
     */
    public static function run(string $code, array $test_specs): array {
        if (empty($test_specs)) {
            return [
                'score' => 100,
                'passed_count' => 0,
                'total_count' => 0,
                'tests' => [],
            ];
        }

        $tmpfile = tempnam(sys_get_temp_dir(), 'autograde_') . '.py';

        // Prepare Python harness script
        $harness = "import sys, json, time\n\n";
        $harness .= "# --- STUDENT SUBMISSION ---\n";
        $harness .= $code . "\n";
        $harness .= "# --- END STUDENT SUBMISSION ---\n\n";
        $harness .= "test_specs = " . json_encode($test_specs) . "\n";
        $harness .= <<<'PYCODE'
results = []
for test in test_specs:
    t_start = time.time()
    try:
        actual = eval(test['expression'])
        actual_str = str(actual)
        expected_str = str(test['expected'])
        passed = (actual_str.strip().lower() == expected_str.strip().lower())
        elapsed = round((time.time() - t_start) * 1000, 2)
        results.append({
            'name': test['name'],
            'expression': test['expression'],
            'expected': expected_str,
            'actual': actual_str,
            'passed': passed,
            'points': test['points'] if passed else 0,
            'max_points': test['points'],
            'elapsed_ms': elapsed,
            'error': None
        })
    except Exception as e:
        elapsed = round((time.time() - t_start) * 1000, 2)
        results.append({
            'name': test['name'],
            'expression': test['expression'],
            'expected': str(test['expected']),
            'actual': 'ERROR',
            'passed': False,
            'points': 0,
            'max_points': test['points'],
            'elapsed_ms': elapsed,
            'error': str(e)
        })

print("__GRADER_OUTPUT_START__")
print(json.dumps(results))
print("__GRADER_OUTPUT_END__")
PYCODE;

        file_put_contents($tmpfile, $harness);

        // Execute via python3 with timeout protection
        $cmd = "python3 " . escapeshellarg($tmpfile) . " 2>&1";
        $descriptor = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptor, $pipes);
        $output = '';
        $timeout = 5; // seconds
        $start = time();

        if (is_resource($process)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $timed_out = false;
            while (true) {
                $status = proc_get_status($process);
                $output .= stream_get_contents($pipes[1]);
                $output .= stream_get_contents($pipes[2]);

                if (!$status['running']) {
                    break;
                }

                if ((time() - $start) > $timeout) {
                    $timed_out = true;
                    proc_terminate($process, 9);
                    break;
                }
                usleep(50000); // 50ms
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        @unlink($tmpfile);

        if ($timed_out) {
            // All tests failed due to timeout / infinite loop
            $tests = [];
            foreach ($test_specs as $spec) {
                $tests[] = [
                    'name' => $spec['name'],
                    'expression' => $spec['expression'],
                    'expected' => (string)$spec['expected'],
                    'actual' => 'Time Limit Exceeded (Timeout > 5s)',
                    'passed' => false,
                    'points' => 0,
                    'max_points' => $spec['points'],
                    'elapsed_ms' => 5000,
                    'error' => 'Execution exceeded 5 seconds limit (possible infinite loop or recursion).'
                ];
            }
            return [
                'score' => 0,
                'passed_count' => 0,
                'total_count' => count($test_specs),
                'tests' => $tests,
            ];
        }

        // Parse result
        if (preg_match('/__GRADER_OUTPUT_START__\s*(.*?)\s*__GRADER_OUTPUT_END__/s', $output, $matches)) {
            $parsed_tests = json_decode($matches[1], true);
            if (is_array($parsed_tests)) {
                $total_points = 0;
                $max_points = 0;
                $passed_count = 0;

                foreach ($parsed_tests as $t) {
                    $total_points += $t['points'];
                    $max_points += $t['max_points'];
                    if ($t['passed']) {
                        $passed_count++;
                    }
                }

                $score = ($max_points > 0) ? round(($total_points / $max_points) * 100) : 0;

                return [
                    'score' => $score,
                    'passed_count' => $passed_count,
                    'total_count' => count($parsed_tests),
                    'tests' => $parsed_tests,
                ];
            }
        }

        // Syntax error or runtime crash before harness loop
        $tests = [];
        $clean_error = trim(strip_tags($output));
        foreach ($test_specs as $spec) {
            $tests[] = [
                'name' => $spec['name'],
                'expression' => $spec['expression'],
                'expected' => (string)$spec['expected'],
                'actual' => 'Syntax / Runtime Crash',
                'passed' => false,
                'points' => 0,
                'max_points' => $spec['points'],
                'elapsed_ms' => 0,
                'error' => $clean_error ?: 'Python interpreter failed to execute code.'
            ];
        }

        return [
            'score' => 0,
            'passed_count' => 0,
            'total_count' => count($test_specs),
            'tests' => $tests,
        ];
    }
}
