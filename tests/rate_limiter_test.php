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
 * Unit tests for mod_classengage rate limiter
 *
 * Tests the enterprise rate limiting functionality including:
 * - Token bucket algorithm behavior
 * - Rate limit enforcement
 * - Cache-based distributed limiting
 * - Header generation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\rate_limiter
 * @covers     \mod_classengage\rate_limit_result
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Rate limiter unit tests
 */
class rate_limiter_test extends \advanced_testcase
{

    /**
     * Test rate limiter allows requests within limit
     */
    public function test_allows_requests_within_limit(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(10, 60); // 10 requests per 60 seconds.
        $userid = 123;

        // First 10 requests should all be allowed.
        for ($i = 0; $i < 10; $i++) {
            $result = $limiter->check($userid, 'test');
            $this->assertTrue($result->allowed, "Request $i should be allowed");
            $this->assertEquals(10, $result->limit);
            $this->assertEquals(9 - $i, $result->remaining);
        }
    }

    /**
     * Test rate limiter blocks requests exceeding limit
     */
    public function test_blocks_requests_exceeding_limit(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(5, 60); // 5 requests per 60 seconds.
        $userid = 456;

        // Use up all 5 requests.
        for ($i = 0; $i < 5; $i++) {
            $result = $limiter->check($userid, 'test');
            $this->assertTrue($result->allowed);
        }

        // 6th request should be blocked.
        $result = $limiter->check($userid, 'test');
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
        $this->assertGreaterThan(0, $result->reset_in);
    }

    /**
     * Test peek does not consume tokens
     */
    public function test_peek_does_not_consume_tokens(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(3, 60);
        $userid = 789;

        // Check initial state.
        $result = $limiter->peek($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(3, $result->remaining);

        // Peek should not consume tokens.
        $result = $limiter->peek($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(3, $result->remaining);

        // Now consume one token.
        $limiter->check($userid, 'test');

        // Peek should show 2 remaining.
        $result = $limiter->peek($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(2, $result->remaining);
    }

    /**
     * Test different actions have separate limits
     */
    public function test_separate_limits_per_action(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(2, 60);
        $userid = 111;

        // Use up limit for 'action1'.
        $limiter->check($userid, 'action1');
        $limiter->check($userid, 'action1');
        $result = $limiter->check($userid, 'action1');
        $this->assertFalse($result->allowed);

        // 'action2' should still have its full quota.
        $result = $limiter->check($userid, 'action2');
        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->remaining);
    }

    /**
     * Test different users have separate limits
     */
    public function test_separate_limits_per_user(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(2, 60);

        // User 1 uses up limit.
        $limiter->check(1, 'test');
        $limiter->check(1, 'test');
        $result = $limiter->check(1, 'test');
        $this->assertFalse($result->allowed);

        // User 2 should have full quota.
        $result = $limiter->check(2, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->remaining);
    }

    /**
     * Test reset clears rate limit for user
     */
    public function test_reset_clears_limit(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(2, 60);
        $userid = 222;

        // Use up limit.
        $limiter->check($userid, 'test');
        $limiter->check($userid, 'test');
        $result = $limiter->check($userid, 'test');
        $this->assertFalse($result->allowed);

        // Reset should restore quota.
        $limiter->reset($userid, 'test');

        $result = $limiter->check($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(1, $result->remaining);
    }

    /**
     * Test rate limit result object construction
     */
    public function test_rate_limit_result_construction(): void
    {
        $result = new rate_limit_result(true, 5, 30, 10);

        $this->assertTrue($result->allowed);
        $this->assertEquals(5, $result->remaining);
        $this->assertEquals(30, $result->reset_in);
        $this->assertEquals(10, $result->limit);
    }

    /**
     * Test rate limiter uses defaults from constants
     */
    public function test_uses_default_constants(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter();
        $result = $limiter->check(333, 'test');

        $this->assertTrue($result->allowed);
        $this->assertEquals(constants::RATE_LIMIT_REQUESTS_PER_MINUTE, $result->limit);
    }

    /**
     * Test get_session_stats returns expected structure
     */
    public function test_get_session_stats(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(100, 120);
        $stats = $limiter->get_session_stats(1);

        $this->assertArrayHasKey('limit', $stats);
        $this->assertArrayHasKey('window', $stats);
        $this->assertEquals(100, $stats['limit']);
        $this->assertEquals(120, $stats['window']);
    }

    /**
     * Test high concurrency scenario
     *
     * Simulates multiple rapid requests to verify rate limiting works under load.
     */
    public function test_high_concurrency_scenario(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(50, 60);
        $userid = 444;

        $allowedcount = 0;
        $blockedcount = 0;

        // Simulate 100 rapid requests.
        for ($i = 0; $i < 100; $i++) {
            $result = $limiter->check($userid, 'submit');
            if ($result->allowed) {
                $allowedcount++;
            } else {
                $blockedcount++;
            }
        }

        // Should allow exactly 50 and block exactly 50.
        $this->assertEquals(50, $allowedcount);
        $this->assertEquals(50, $blockedcount);
    }

    /**
     * Test multiple action types with different rate limits
     *
     * Verifies that different action types can be rate limited independently.
     */
    public function test_multiple_action_types(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(3, 60);
        $userid = 555;

        $actions = ['submitanswer', 'pause', 'resume'];

        foreach ($actions as $action) {
            // Each action should have its own 3-request limit.
            for ($i = 0; $i < 3; $i++) {
                $result = $limiter->check($userid, $action);
                $this->assertTrue($result->allowed, "Request $i for $action should be allowed");
            }

            // 4th request should be blocked.
            $result = $limiter->check($userid, $action);
            $this->assertFalse($result->allowed, "4th request for $action should be blocked");
        }
    }

    /**
     * Test rate limiter handles edge case of zero remaining
     */
    public function test_zero_remaining_edge_case(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(1, 60);
        $userid = 666;

        // First request uses the only token.
        $result = $limiter->check($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals(0, $result->remaining);

        // Subsequent request should be blocked.
        $result = $limiter->check($userid, 'test');
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
    }
}
