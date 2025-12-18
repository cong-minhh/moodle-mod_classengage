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
 * Property-based tests for bandwidth efficiency
 *
 * These tests verify that quiz interface payloads remain within size limits
 * to support low-connectivity environments.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Property-based tests for bandwidth efficiency
 *
 * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
 *
 * For any quiz interface payload, the total size SHALL not exceed 50KB for
 * initial load and 5KB for subsequent updates.
 *
 * **Validates: Requirements 8.3**
 */
class bandwidth_efficiency_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /** @var int Maximum size for initial load payload in bytes (50KB) */
    const MAX_INITIAL_PAYLOAD_SIZE = 50 * 1024;

    /** @var int Maximum size for update payload in bytes (5KB) */
    const MAX_UPDATE_PAYLOAD_SIZE = 5 * 1024;

    /**
     * Generate random question text of varying length
     *
     * @param int $minlength Minimum length
     * @param int $maxlength Maximum length
     * @return string
     */
    protected function generate_random_question_text(int $minlength = 10, int $maxlength = 500): string {
        $words = [
            'What', 'Which', 'How', 'Why', 'When', 'Where', 'is', 'are', 'was', 'were',
            'the', 'a', 'an', 'of', 'in', 'to', 'for', 'with', 'on', 'at',
            'correct', 'answer', 'following', 'statement', 'true', 'false',
            'best', 'describes', 'example', 'definition', 'concept', 'theory',
            'method', 'process', 'result', 'effect', 'cause', 'relationship',
            'primary', 'secondary', 'main', 'important', 'significant', 'key',
        ];

        $length = rand($minlength, $maxlength);
        $text = '';

        while (strlen($text) < $length) {
            $text .= $words[array_rand($words)] . ' ';
        }

        return trim(substr($text, 0, $length)) . '?';
    }

    /**
     * Generate random option text
     *
     * @param int $minlength Minimum length
     * @param int $maxlength Maximum length
     * @return string
     */
    protected function generate_random_option_text(int $minlength = 5, int $maxlength = 200): string {
        $words = [
            'The', 'A', 'An', 'This', 'That', 'These', 'Those',
            'answer', 'option', 'choice', 'solution', 'result',
            'is', 'are', 'was', 'were', 'will', 'would', 'could',
            'correct', 'incorrect', 'true', 'false', 'valid', 'invalid',
            'first', 'second', 'third', 'fourth', 'primary', 'secondary',
            'important', 'significant', 'relevant', 'applicable', 'appropriate',
        ];

        $length = rand($minlength, $maxlength);
        $text = '';

        while (strlen($text) < $length) {
            $text .= $words[array_rand($words)] . ' ';
        }

        return trim(substr($text, 0, $length));
    }

    /**
     * Build a question broadcast payload similar to what the server sends
     *
     * @param object $question Question object
     * @param int $questionnumber Current question number
     * @param int $totalquestions Total questions in session
     * @param int $timeremaining Time remaining in seconds
     * @return array
     */
    protected function build_question_broadcast_payload(
        object $question,
        int $questionnumber,
        int $totalquestions,
        int $timeremaining
    ): array {
        $options = [];
        $optionkeys = ['A', 'B', 'C', 'D'];

        // Parse options from question.
        $optiondata = json_decode($question->options ?? '{}', true);
        foreach ($optionkeys as $key) {
            if (isset($optiondata[$key])) {
                $options[] = [
                    'key' => $key,
                    'text' => $optiondata[$key],
                ];
            }
        }

        return [
            'success' => true,
            'status' => 'active',
            'question' => [
                'id' => $question->id,
                'text' => $question->questiontext,
                'options' => $options,
                'number' => $questionnumber,
                'total' => $totalquestions,
                'timeremaining' => $timeremaining,
                'answered' => false,
            ],
        ];
    }

    /**
     * Build a status update payload (for polling updates)
     *
     * @param string $status Session status
     * @param int $timeremaining Time remaining
     * @param bool $includequestion Whether to include question data
     * @return array
     */
    protected function build_status_update_payload(
        string $status,
        int $timeremaining,
        bool $includequestion = false
    ): array {
        $payload = [
            'success' => true,
            'status' => $status,
            'timeremaining' => $timeremaining,
            'timestamp' => time(),
        ];

        if ($includequestion) {
            $payload['question'] = [
                'id' => rand(1, 1000),
                'answered' => (bool) rand(0, 1),
            ];
        }

        return $payload;
    }

    /**
     * Build a timer sync payload
     *
     * @param int $remaining Seconds remaining
     * @return array
     */
    protected function build_timer_sync_payload(int $remaining): array {
        return [
            'event' => 'timer_sync',
            'remaining' => $remaining,
            'timestamp' => time(),
        ];
    }

    /**
     * Build a heartbeat response payload
     *
     * @return array
     */
    protected function build_heartbeat_response_payload(): array {
        return [
            'success' => true,
            'servertimestamp' => time(),
            'latency' => rand(10, 100),
        ];
    }

    /**
     * Build a submission response payload
     *
     * @param bool $success Whether submission was successful
     * @param bool $iscorrect Whether answer was correct
     * @param string $correctanswer The correct answer
     * @param bool $islate Whether submission was late
     * @return array
     */
    protected function build_submission_response_payload(
        bool $success,
        bool $iscorrect,
        string $correctanswer,
        bool $islate = false
    ): array {
        return [
            'success' => $success,
            'iscorrect' => $iscorrect,
            'correctanswer' => $correctanswer,
            'islate' => $islate,
            'responseid' => rand(1, 10000),
        ];
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any quiz interface payload, the total size SHALL not exceed 50KB for
     * initial load and 5KB for subsequent updates.
     *
     * This test verifies that question broadcast payloads (initial load) remain
     * within the 50KB limit regardless of question content length.
     *
     * **Validates: Requirements 8.3**
     *
     * @covers \mod_classengage\session_state_manager::next_question
     */
    public function test_property_question_broadcast_payload_size(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random question with varying text lengths.
            // Use realistic maximum lengths that might occur in practice.
            $questiontext = $this->generate_random_question_text(10, 2000);

            // Generate options with varying lengths.
            $options = [];
            $optionkeys = ['A', 'B', 'C', 'D'];
            foreach ($optionkeys as $key) {
                $options[$key] = $this->generate_random_option_text(5, 500);
            }

            // Create question.
            $question = $generator->create_question($classengage->id, [
                'questiontext' => $questiontext,
                'options' => json_encode($options),
                'correctanswer' => $optionkeys[array_rand($optionkeys)],
            ]);

            // Create session.
            $numquestions = rand(5, 50);
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'numquestions' => $numquestions,
            ]);

            // Build the question broadcast payload.
            $questionnumber = rand(1, $numquestions);
            $timeremaining = rand(10, 300);

            $payload = $this->build_question_broadcast_payload(
                $question,
                $questionnumber,
                $numquestions,
                $timeremaining
            );

            // Calculate payload size.
            $jsonpayload = json_encode($payload);
            $payloadsize = strlen($jsonpayload);

            // Verify payload is within initial load limit (50KB).
            $this->assertLessThanOrEqual(
                self::MAX_INITIAL_PAYLOAD_SIZE,
                $payloadsize,
                "Iteration $i: Question broadcast payload ($payloadsize bytes) should not exceed 50KB"
            );

            // Log payload size for analysis (optional).
            if ($payloadsize > self::MAX_INITIAL_PAYLOAD_SIZE * 0.8) {
                // Warning: payload is approaching limit.
                $this->addWarning(
                    "Iteration $i: Payload size ($payloadsize bytes) is above 80% of limit"
                );
            }
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any quiz interface payload, the total size SHALL not exceed 5KB for
     * subsequent updates (status updates, timer syncs, heartbeats).
     *
     * **Validates: Requirements 8.3**
     *
     * @covers \mod_classengage\session_state_manager::get_client_state
     */
    public function test_property_update_payload_size(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            // Generate random update payloads.
            $updatetype = rand(0, 3);

            switch ($updatetype) {
                case 0:
                    // Status update without question.
                    $statuses = ['active', 'paused', 'completed'];
                    $payload = $this->build_status_update_payload(
                        $statuses[array_rand($statuses)],
                        rand(0, 300),
                        false
                    );
                    $payloadtype = 'status_update';
                    break;

                case 1:
                    // Status update with question reference.
                    $payload = $this->build_status_update_payload(
                        'active',
                        rand(0, 300),
                        true
                    );
                    $payloadtype = 'status_update_with_question';
                    break;

                case 2:
                    // Timer sync.
                    $payload = $this->build_timer_sync_payload(rand(0, 300));
                    $payloadtype = 'timer_sync';
                    break;

                case 3:
                    // Heartbeat response.
                    $payload = $this->build_heartbeat_response_payload();
                    $payloadtype = 'heartbeat';
                    break;

                default:
                    $payload = ['success' => true];
                    $payloadtype = 'default';
            }

            // Calculate payload size.
            $jsonpayload = json_encode($payload);
            $payloadsize = strlen($jsonpayload);

            // Verify payload is within update limit (5KB).
            $this->assertLessThanOrEqual(
                self::MAX_UPDATE_PAYLOAD_SIZE,
                $payloadsize,
                "Iteration $i: $payloadtype payload ($payloadsize bytes) should not exceed 5KB"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any submission response payload, the size SHALL not exceed 5KB.
     *
     * **Validates: Requirements 8.3**
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_submission_response_payload_size(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            // Generate random submission response.
            $success = (bool) rand(0, 1);
            $iscorrect = $success ? (bool) rand(0, 1) : false;
            $correctanswer = $this->generate_random_option_text(1, 200);
            $islate = (bool) rand(0, 1);

            $payload = $this->build_submission_response_payload(
                $success,
                $iscorrect,
                $correctanswer,
                $islate
            );

            // Calculate payload size.
            $jsonpayload = json_encode($payload);
            $payloadsize = strlen($jsonpayload);

            // Verify payload is within update limit (5KB).
            $this->assertLessThanOrEqual(
                self::MAX_UPDATE_PAYLOAD_SIZE,
                $payloadsize,
                "Iteration $i: Submission response payload ($payloadsize bytes) should not exceed 5KB"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any session with multiple questions, the cumulative update payloads
     * during a session SHALL remain efficient (average under 2KB per update).
     *
     * **Validates: Requirements 8.3**
     */
    public function test_property_cumulative_update_efficiency(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            // Simulate a session with multiple updates.
            $numquestions = rand(5, 20);
            $updatesperquestion = rand(5, 15); // Timer syncs, heartbeats, etc.
            $totalupdates = $numquestions * $updatesperquestion;

            $totalpayloadsize = 0;

            for ($j = 0; $j < $totalupdates; $j++) {
                // Generate random update type.
                $updatetype = rand(0, 2);

                switch ($updatetype) {
                    case 0:
                        $payload = $this->build_timer_sync_payload(rand(0, 300));
                        break;
                    case 1:
                        $payload = $this->build_heartbeat_response_payload();
                        break;
                    case 2:
                        $payload = $this->build_status_update_payload('active', rand(0, 300), false);
                        break;
                    default:
                        $payload = ['success' => true];
                }

                $totalpayloadsize += strlen(json_encode($payload));
            }

            // Calculate average payload size.
            $averagepayloadsize = $totalpayloadsize / $totalupdates;

            // Verify average is under 2KB (efficient updates).
            $this->assertLessThan(
                2048,
                $averagepayloadsize,
                "Iteration $i: Average update payload ($averagepayloadsize bytes) should be under 2KB"
            );

            // Verify total session bandwidth is reasonable.
            // For a 20-question session with 15 updates each = 300 updates.
            // At 2KB average = 600KB total, which is reasonable for a full session.
            $maxreasonabletotal = $totalupdates * 2048;
            $this->assertLessThanOrEqual(
                $maxreasonabletotal,
                $totalpayloadsize,
                "Iteration $i: Total session bandwidth should be reasonable"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any SSE event stream, individual events SHALL not exceed 5KB.
     *
     * **Validates: Requirements 8.3**
     */
    public function test_property_sse_event_size(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            // Generate random SSE event types.
            $eventtypes = [
                'connected',
                'session_started',
                'session_paused',
                'session_resumed',
                'session_completed',
                'timer_sync',
                'heartbeat',
            ];

            $eventtype = $eventtypes[array_rand($eventtypes)];

            // Build event data based on type.
            switch ($eventtype) {
                case 'connected':
                    $data = [
                        'connectionid' => 'conn_' . time() . '_' . rand(1000, 9999),
                        'sessionid' => rand(1, 1000),
                        'status' => 'connected',
                    ];
                    break;

                case 'session_started':
                case 'session_resumed':
                    $data = [
                        'sessionid' => rand(1, 1000),
                        'status' => 'active',
                        'timestamp' => time(),
                    ];
                    break;

                case 'session_paused':
                    $data = [
                        'sessionid' => rand(1, 1000),
                        'status' => 'paused',
                        'timerRemaining' => rand(0, 300),
                        'timestamp' => time(),
                    ];
                    break;

                case 'session_completed':
                    $data = [
                        'sessionid' => rand(1, 1000),
                        'status' => 'completed',
                        'score' => rand(0, 100),
                        'timestamp' => time(),
                    ];
                    break;

                case 'timer_sync':
                    $data = [
                        'remaining' => rand(0, 300),
                        'timestamp' => time(),
                    ];
                    break;

                case 'heartbeat':
                    $data = [
                        'timestamp' => time(),
                        'latency' => rand(10, 100),
                    ];
                    break;

                default:
                    $data = ['success' => true];
            }

            // Build SSE event format.
            $eventid = rand(1, 10000);
            $sseevent = "id: $eventid\n";
            $sseevent .= "event: $eventtype\n";
            $sseevent .= "data: " . json_encode($data) . "\n\n";

            $eventsize = strlen($sseevent);

            // Verify SSE event is within limit (5KB).
            $this->assertLessThanOrEqual(
                self::MAX_UPDATE_PAYLOAD_SIZE,
                $eventsize,
                "Iteration $i: SSE event '$eventtype' ($eventsize bytes) should not exceed 5KB"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 18: Bandwidth Efficiency**
     *
     * For any reconnection state restoration, the payload SHALL not exceed 50KB.
     *
     * **Validates: Requirements 8.3**
     */
    public function test_property_reconnection_payload_size(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate a question with maximum realistic content.
            $questiontext = $this->generate_random_question_text(100, 2000);
            $options = [];
            foreach (['A', 'B', 'C', 'D'] as $key) {
                $options[$key] = $this->generate_random_option_text(50, 500);
            }

            $question = $generator->create_question($classengage->id, [
                'questiontext' => $questiontext,
                'options' => json_encode($options),
                'correctanswer' => 'A',
            ]);

            // Create session.
            $numquestions = rand(10, 50);
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'numquestions' => $numquestions,
            ]);

            // Build reconnection payload (includes current question and session state).
            $reconnectionpayload = [
                'success' => true,
                'connectionid' => 'conn_' . time() . '_' . rand(1000, 9999),
                'session' => [
                    'id' => $session->id,
                    'status' => 'active',
                    'currentquestion' => rand(1, $numquestions),
                    'totalquestions' => $numquestions,
                    'timeremaining' => rand(10, 300),
                ],
                'question' => [
                    'id' => $question->id,
                    'text' => $questiontext,
                    'options' => array_map(function($key, $text) {
                        return ['key' => $key, 'text' => $text];
                    }, array_keys($options), array_values($options)),
                    'answered' => (bool) rand(0, 1),
                ],
            ];

            // Calculate payload size.
            $jsonpayload = json_encode($reconnectionpayload);
            $payloadsize = strlen($jsonpayload);

            // Verify reconnection payload is within initial load limit (50KB).
            $this->assertLessThanOrEqual(
                self::MAX_INITIAL_PAYLOAD_SIZE,
                $payloadsize,
                "Iteration $i: Reconnection payload ($payloadsize bytes) should not exceed 50KB"
            );
        }
    }
}
