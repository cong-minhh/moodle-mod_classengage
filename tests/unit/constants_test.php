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
 * Unit tests for mod_classengage constants
 *
 * Tests the enterprise constants class including:
 * - Rate limiting constants
 * - Connection management constants
 * - Cache TTL constants
 * - Error codes
 * - Performance thresholds
 * - Validation rules
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\constants
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Constants unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class constants_test extends \advanced_testcase
{

    // =========================================================================
    // Session Status Constants Tests
    // =========================================================================

    /**
     * Test session status constants are defined
     */
    public function test_session_status_constants(): void
    {
        $this->assertEquals('ready', constants::SESSION_STATUS_READY);
        $this->assertEquals('active', constants::SESSION_STATUS_ACTIVE);
        $this->assertEquals('paused', constants::SESSION_STATUS_PAUSED);
        $this->assertEquals('completed', constants::SESSION_STATUS_COMPLETED);
    }

    /**
     * Test question status constants are defined
     */
    public function test_question_status_constants(): void
    {
        $this->assertEquals('pending', constants::QUESTION_STATUS_PENDING);
        $this->assertEquals('approved', constants::QUESTION_STATUS_APPROVED);
        $this->assertEquals('rejected', constants::QUESTION_STATUS_REJECTED);
    }

    /**
     * Test question source constants are defined
     */
    public function test_question_source_constants(): void
    {
        $this->assertEquals('nlp', constants::QUESTION_SOURCE_NLP);
        $this->assertEquals('manual', constants::QUESTION_SOURCE_MANUAL);
    }

    /**
     * Test question type constants are defined
     */
    public function test_question_type_constants(): void
    {
        $this->assertEquals('multichoice', constants::QUESTION_TYPE_MULTICHOICE);
        $this->assertEquals('truefalse', constants::QUESTION_TYPE_TRUEFALSE);
        $this->assertEquals('shortanswer', constants::QUESTION_TYPE_SHORTANSWER);
    }

    /**
     * Test valid answers constant
     */
    public function test_valid_answers_constant(): void
    {
        $this->assertIsArray(constants::VALID_ANSWERS);
        $this->assertContains('A', constants::VALID_ANSWERS);
        $this->assertContains('B', constants::VALID_ANSWERS);
        $this->assertContains('C', constants::VALID_ANSWERS);
        $this->assertContains('D', constants::VALID_ANSWERS);
        $this->assertCount(4, constants::VALID_ANSWERS);
    }

    /**
     * Test valid actions constant
     */
    public function test_valid_actions_constant(): void
    {
        $this->assertIsArray(constants::VALID_ACTIONS);
        $this->assertContains(constants::ACTION_NEXT, constants::VALID_ACTIONS);
        $this->assertContains(constants::ACTION_STOP, constants::VALID_ACTIONS);
        $this->assertContains(constants::ACTION_PAUSE, constants::VALID_ACTIONS);
        $this->assertContains(constants::ACTION_RESUME, constants::VALID_ACTIONS);
    }

    // =========================================================================
    // Rate Limiting Constants Tests
    // =========================================================================

    /**
     * Test rate limiting constants are positive integers
     */
    public function test_rate_limiting_constants(): void
    {
        $this->assertIsInt(constants::RATE_LIMIT_REQUESTS_PER_MINUTE);
        $this->assertGreaterThan(0, constants::RATE_LIMIT_REQUESTS_PER_MINUTE);

        $this->assertIsInt(constants::MAX_BATCH_SIZE);
        $this->assertGreaterThan(0, constants::MAX_BATCH_SIZE);

        $this->assertIsInt(constants::MAX_CONNECTIONS_PER_SESSION);
        $this->assertGreaterThan(0, constants::MAX_CONNECTIONS_PER_SESSION);

        $this->assertIsInt(constants::RATE_LIMIT_WINDOW);
        $this->assertGreaterThan(0, constants::RATE_LIMIT_WINDOW);
    }

    /**
     * Test rate limit is reasonable for enterprise use
     */
    public function test_rate_limit_is_reasonable(): void
    {
        // Should allow at least 60 requests per minute (1 per second).
        $this->assertGreaterThanOrEqual(60, constants::RATE_LIMIT_REQUESTS_PER_MINUTE);

        // Should not exceed unreasonable thresholds.
        $this->assertLessThanOrEqual(1000, constants::RATE_LIMIT_REQUESTS_PER_MINUTE);
    }

    /**
     * Test max connections supports enterprise scale
     */
    public function test_max_connections_enterprise_scale(): void
    {
        // Should support at least 200 concurrent users (requirement).
        $this->assertGreaterThanOrEqual(200, constants::MAX_CONNECTIONS_PER_SESSION);
    }

    // =========================================================================
    // Connection Management Constants Tests
    // =========================================================================

    /**
     * Test connection management constants
     */
    public function test_connection_management_constants(): void
    {
        $this->assertIsInt(constants::CONNECTION_STALE_TIMEOUT);
        $this->assertGreaterThan(0, constants::CONNECTION_STALE_TIMEOUT);

        $this->assertIsInt(constants::MAX_SSE_RUNTIME);
        $this->assertGreaterThan(0, constants::MAX_SSE_RUNTIME);

        $this->assertIsInt(constants::RECONNECT_DELAY_INITIAL);
        $this->assertGreaterThan(0, constants::RECONNECT_DELAY_INITIAL);

        $this->assertIsInt(constants::RECONNECT_DELAY_MAX);
        $this->assertGreaterThan(constants::RECONNECT_DELAY_INITIAL, constants::RECONNECT_DELAY_MAX);

        $this->assertIsInt(constants::CONNECTION_TIMEOUT);
        $this->assertGreaterThan(0, constants::CONNECTION_TIMEOUT);
    }

    /**
     * Test connection stale timeout is reasonable
     */
    public function test_connection_stale_timeout_is_reasonable(): void
    {
        // Should be at least 30 seconds to avoid false positives.
        $this->assertGreaterThanOrEqual(30, constants::CONNECTION_STALE_TIMEOUT);

        // Should not be more than 5 minutes (too lenient).
        $this->assertLessThanOrEqual(300, constants::CONNECTION_STALE_TIMEOUT);
    }

    // =========================================================================
    // Cache TTL Constants Tests
    // =========================================================================

    /**
     * Test cache TTL constants are positive
     */
    public function test_cache_ttl_constants(): void
    {
        $this->assertIsInt(constants::CACHE_TTL_RESPONSE_STATS);
        $this->assertGreaterThan(0, constants::CACHE_TTL_RESPONSE_STATS);

        $this->assertIsInt(constants::CACHE_TTL_SESSION_STATE);
        $this->assertGreaterThan(0, constants::CACHE_TTL_SESSION_STATE);

        $this->assertIsInt(constants::CACHE_TTL_CONNECTION_STATUS);
        $this->assertGreaterThan(0, constants::CACHE_TTL_CONNECTION_STATUS);

        $this->assertIsInt(constants::CACHE_TTL_ANALYTICS_SUMMARY);
        $this->assertGreaterThan(0, constants::CACHE_TTL_ANALYTICS_SUMMARY);

        $this->assertIsInt(constants::CACHE_TTL_QUESTION_DATA);
        $this->assertGreaterThan(0, constants::CACHE_TTL_QUESTION_DATA);
    }

    /**
     * Test cache TTLs are appropriately ordered
     */
    public function test_cache_ttl_ordering(): void
    {
        // Response stats should be very short (real-time).
        $this->assertLessThanOrEqual(5, constants::CACHE_TTL_RESPONSE_STATS);

        // Question data can be cached longer than session state.
        $this->assertGreaterThan(
            constants::CACHE_TTL_SESSION_STATE,
            constants::CACHE_TTL_QUESTION_DATA
        );
    }

    // =========================================================================
    // Error Code Constants Tests
    // =========================================================================

    /**
     * Test error codes are unique
     */
    public function test_error_codes_are_unique(): void
    {
        $errorcodes = [
            constants::ERROR_SESSION_NOT_FOUND,
            constants::ERROR_SESSION_NOT_ACTIVE,
            constants::ERROR_ALREADY_ANSWERED,
            constants::ERROR_RATE_LIMIT_EXCEEDED,
            constants::ERROR_INVALID_ANSWER,
            constants::ERROR_CONNECTION_TIMEOUT,
            constants::ERROR_DATABASE,
            constants::ERROR_PERMISSION_DENIED,
        ];

        // All error codes should be unique.
        $this->assertEquals(count($errorcodes), count(array_unique($errorcodes)));
    }

    /**
     * Test error codes are in expected range
     */
    public function test_error_codes_are_in_range(): void
    {
        $errorcodes = [
            constants::ERROR_SESSION_NOT_FOUND,
            constants::ERROR_SESSION_NOT_ACTIVE,
            constants::ERROR_ALREADY_ANSWERED,
            constants::ERROR_RATE_LIMIT_EXCEEDED,
            constants::ERROR_INVALID_ANSWER,
            constants::ERROR_CONNECTION_TIMEOUT,
            constants::ERROR_DATABASE,
            constants::ERROR_PERMISSION_DENIED,
        ];

        foreach ($errorcodes as $code) {
            $this->assertIsInt($code);
            $this->assertGreaterThan(1000, $code);
            $this->assertLessThan(2000, $code);
        }
    }

    // =========================================================================
    // Performance Threshold Constants Tests
    // =========================================================================

    /**
     * Test performance thresholds are defined
     */
    public function test_performance_thresholds(): void
    {
        // Response latency should be positive.
        $this->assertGreaterThan(0, constants::TARGET_RESPONSE_LATENCY);

        // Success rate should be between 0 and 100.
        $this->assertGreaterThan(0, constants::TARGET_SUCCESS_RATE);
        $this->assertLessThanOrEqual(100, constants::TARGET_SUCCESS_RATE);

        // Concurrent users should be at least 200 (requirement).
        $this->assertGreaterThanOrEqual(200, constants::TARGET_CONCURRENT_USERS);

        // Broadcast latency should be positive.
        $this->assertGreaterThan(0, constants::TARGET_BROADCAST_LATENCY);
    }

    /**
     * Test performance targets meet enterprise requirements
     */
    public function test_performance_targets_meet_requirements(): void
    {
        // NFR-01: Response latency should be <= 1 second.
        $this->assertLessThanOrEqual(1.0, constants::TARGET_RESPONSE_LATENCY);

        // NFR-03: Success rate should be >= 95%.
        $this->assertGreaterThanOrEqual(95.0, constants::TARGET_SUCCESS_RATE);

        // Question broadcast should be <= 500ms.
        $this->assertLessThanOrEqual(0.5, constants::TARGET_BROADCAST_LATENCY);
    }

    // =========================================================================
    // Event Type Constants Tests
    // =========================================================================

    /**
     * Test event type constants are strings
     */
    public function test_event_type_constants(): void
    {
        $this->assertIsString(constants::EVENT_SESSION_START);
        $this->assertIsString(constants::EVENT_SESSION_END);
        $this->assertIsString(constants::EVENT_RESPONSE);
        $this->assertIsString(constants::EVENT_CONNECTION);
        $this->assertIsString(constants::EVENT_DISCONNECTION);
        $this->assertIsString(constants::EVENT_ERROR);
        $this->assertIsString(constants::EVENT_WARNING);
        $this->assertIsString(constants::EVENT_RECONNECT);
    }

    /**
     * Test event types are not empty
     */
    public function test_event_types_not_empty(): void
    {
        $eventtypes = [
            constants::EVENT_SESSION_START,
            constants::EVENT_SESSION_END,
            constants::EVENT_RESPONSE,
            constants::EVENT_CONNECTION,
            constants::EVENT_DISCONNECTION,
            constants::EVENT_ERROR,
            constants::EVENT_WARNING,
            constants::EVENT_RECONNECT,
        ];

        foreach ($eventtypes as $eventtype) {
            $this->assertNotEmpty($eventtype);
        }
    }

    // =========================================================================
    // Validation Rule Constants Tests
    // =========================================================================

    /**
     * Test validation rule constants
     */
    public function test_validation_rule_constants(): void
    {
        $this->assertIsInt(constants::MAX_SESSION_NAME_LENGTH);
        $this->assertGreaterThan(0, constants::MAX_SESSION_NAME_LENGTH);

        $this->assertIsInt(constants::MAX_QUESTION_TEXT_LENGTH);
        $this->assertGreaterThan(0, constants::MAX_QUESTION_TEXT_LENGTH);

        $this->assertIsInt(constants::MAX_ANSWER_LENGTH);
        $this->assertGreaterThan(0, constants::MAX_ANSWER_LENGTH);
    }

    /**
     * Test time limit constraints are valid
     */
    public function test_time_limit_constraints(): void
    {
        $this->assertIsInt(constants::MIN_TIME_LIMIT);
        $this->assertIsInt(constants::MAX_TIME_LIMIT);

        // Min should be less than max.
        $this->assertLessThan(constants::MAX_TIME_LIMIT, constants::MIN_TIME_LIMIT);

        // Min should be at least 5 seconds.
        $this->assertGreaterThanOrEqual(5, constants::MIN_TIME_LIMIT);

        // Max should be reasonable (10 minutes max).
        $this->assertLessThanOrEqual(600, constants::MAX_TIME_LIMIT);
    }

    /**
     * Test questions per session constraints
     */
    public function test_questions_per_session_constraints(): void
    {
        $this->assertIsInt(constants::MIN_QUESTIONS_PER_SESSION);
        $this->assertIsInt(constants::MAX_QUESTIONS_PER_SESSION);

        // Min should be less than max.
        $this->assertLessThan(
            constants::MAX_QUESTIONS_PER_SESSION,
            constants::MIN_QUESTIONS_PER_SESSION
        );

        // Min should be at least 1.
        $this->assertGreaterThanOrEqual(1, constants::MIN_QUESTIONS_PER_SESSION);
    }

    // =========================================================================
    // Default Configuration Constants Tests
    // =========================================================================

    /**
     * Test default configuration constants
     */
    public function test_default_configuration(): void
    {
        $this->assertIsInt(constants::DEFAULT_POLLING_INTERVAL);
        $this->assertGreaterThan(0, constants::DEFAULT_POLLING_INTERVAL);

        $this->assertIsInt(constants::DEFAULT_NUM_QUESTIONS);
        $this->assertGreaterThan(0, constants::DEFAULT_NUM_QUESTIONS);

        $this->assertIsInt(constants::DEFAULT_TIME_LIMIT);
        $this->assertGreaterThan(0, constants::DEFAULT_TIME_LIMIT);
    }

    /**
     * Test default values are within validation bounds
     */
    public function test_defaults_within_bounds(): void
    {
        // Default time limit should be within min/max.
        $this->assertGreaterThanOrEqual(
            constants::MIN_TIME_LIMIT,
            constants::DEFAULT_TIME_LIMIT
        );
        $this->assertLessThanOrEqual(
            constants::MAX_TIME_LIMIT,
            constants::DEFAULT_TIME_LIMIT
        );

        // Default questions should be within min/max.
        $this->assertGreaterThanOrEqual(
            constants::MIN_QUESTIONS_PER_SESSION,
            constants::DEFAULT_NUM_QUESTIONS
        );
        $this->assertLessThanOrEqual(
            constants::MAX_QUESTIONS_PER_SESSION,
            constants::DEFAULT_NUM_QUESTIONS
        );
    }
}
