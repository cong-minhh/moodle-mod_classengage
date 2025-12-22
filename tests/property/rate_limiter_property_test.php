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
 * Property-based tests
 *
 * @group mod_classengage
 * @group mod_classengage_property for mod_classengage rate limiter
 *
 * Tests rate limiter invariants and properties:
 * - Token bucket invariants
 * - Monotonicity of remaining tokens
 * - Idempotency of reset
 * - Consistency across different configurations
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Rate limiter property-based tests
 */
class rate_limiter_property_test extends \advanced_testcase {

    /**
     * Property: Remaining tokens never exceed limit
     *
     * @dataProvider random_configurations_provider
     */
    public function test_remaining_never_exceeds_limit(int $limit, int $window): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, $window);
        $userid = random_int(1, 100000);

        // Check initial state.
        $result = $limiter->peek($userid, 'test');
        $this->assertLessThanOrEqual($limit, $result->remaining);

        // After any number of checks, remaining should never exceed limit.
        $numchecks = random_int(1, $limit + 10);
        for ($i = 0; $i < $numchecks; $i++) {
            $result = $limiter->check($userid, 'test');
            $this->assertLessThanOrEqual($limit, $result->remaining);
        }
    }

    /**
     * Property: Remaining tokens are monotonically decreasing within a window
     *
     * @dataProvider random_configurations_provider
     */
    public function test_remaining_monotonically_decreasing(int $limit, int $window): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, $window);
        $userid = random_int(1, 100000);

        $lastremaining = $limit;

        for ($i = 0; $i < $limit; $i++) {
            $result = $limiter->check($userid, 'test');
            $this->assertLessThanOrEqual($lastremaining, $result->remaining);
            $lastremaining = $result->remaining;
        }
    }

    /**
     * Property: Blocked requests always have 0 remaining tokens
     *
     * @dataProvider small_limit_provider
     */
    public function test_blocked_always_zero_remaining(int $limit): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, 60);
        $userid = random_int(1, 100000);

        // Exhaust the limit.
        for ($i = 0; $i < $limit; $i++) {
            $limiter->check($userid, 'test');
        }

        // All subsequent blocked requests should have 0 remaining.
        for ($i = 0; $i < 10; $i++) {
            $result = $limiter->check($userid, 'test');
            $this->assertFalse($result->allowed);
            $this->assertEquals(0, $result->remaining);
        }
    }

    /**
     * Property: Reset is idempotent
     *
     * @dataProvider random_configurations_provider
     */
    public function test_reset_is_idempotent(int $limit, int $window): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, $window);
        $userid = random_int(1, 100000);

        // Use some tokens.
        for ($i = 0; $i < min($limit, 5); $i++) {
            $limiter->check($userid, 'test');
        }

        // Reset multiple times.
        $limiter->reset($userid, 'test');
        $limiter->reset($userid, 'test');
        $limiter->reset($userid, 'test');

        // State should be fully reset.
        $result = $limiter->peek($userid, 'test');
        $this->assertTrue($result->allowed);
        $this->assertEquals($limit, $result->remaining);
    }

    /**
     * Property: Allowed count exactly equals limit
     *
     * @dataProvider small_limit_provider
     */
    public function test_allowed_count_equals_limit(int $limit): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, 60);
        $userid = random_int(1, 100000);

        $allowedcount = 0;
        $totalrequests = $limit * 2;

        for ($i = 0; $i < $totalrequests; $i++) {
            $result = $limiter->check($userid, 'test');
            if ($result->allowed) {
                $allowedcount++;
            }
        }

        $this->assertEquals($limit, $allowedcount);
    }

    /**
     * Property: Different users are independent
     *
     * @dataProvider small_limit_provider
     */
    public function test_users_are_independent(int $limit): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, 60);

        $user1 = random_int(1, 50000);
        $user2 = random_int(50001, 100000);

        // Exhaust user1's limit.
        for ($i = 0; $i < $limit; $i++) {
            $limiter->check($user1, 'test');
        }
        $result1 = $limiter->check($user1, 'test');
        $this->assertFalse($result1->allowed);

        // User2 should still have full quota.
        $result2 = $limiter->peek($user2, 'test');
        $this->assertTrue($result2->allowed);
        $this->assertEquals($limit, $result2->remaining);
    }

    /**
     * Property: Different actions are independent
     *
     * @dataProvider small_limit_provider
     */
    public function test_actions_are_independent(int $limit): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, 60);
        $userid = random_int(1, 100000);

        $action1 = 'action_' . random_int(1, 1000);
        $action2 = 'action_' . random_int(1001, 2000);

        // Exhaust action1's limit.
        for ($i = 0; $i < $limit; $i++) {
            $limiter->check($userid, $action1);
        }
        $result1 = $limiter->check($userid, $action1);
        $this->assertFalse($result1->allowed);

        // Action2 should still have full quota.
        $result2 = $limiter->peek($userid, $action2);
        $this->assertTrue($result2->allowed);
        $this->assertEquals($limit, $result2->remaining);
    }

    /**
     * Property: Peek doesn't change state
     *
     * @dataProvider random_configurations_provider
     */
    public function test_peek_doesnt_change_state(int $limit, int $window): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, $window);
        $userid = random_int(1, 100000);

        // Use some tokens (handle edge case when limit is 1).
        $maxtokens = max(1, $limit - 1);
        $tokensused = ($maxtokens > 1) ? random_int(1, $maxtokens) : 1;
        for ($i = 0; $i < $tokensused; $i++) {
            $limiter->check($userid, 'test');
        }

        // Peek multiple times.
        $peekresults = [];
        for ($i = 0; $i < 10; $i++) {
            $peekresults[] = $limiter->peek($userid, 'test');
        }

        // All peek results should be identical.
        for ($i = 1; $i < count($peekresults); $i++) {
            $this->assertEquals(
                $peekresults[0]->remaining,
                $peekresults[$i]->remaining
            );
            $this->assertEquals(
                $peekresults[0]->allowed,
                $peekresults[$i]->allowed
            );
        }
    }

    /**
     * Property: Rate limit result has valid structure
     *
     * @dataProvider random_configurations_provider
     */
    public function test_result_has_valid_structure(int $limit, int $window): void {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter($limit, $window);
        $userid = random_int(1, 100000);

        for ($i = 0; $i < $limit + 5; $i++) {
            $result = $limiter->check($userid, 'test');

            // Validate structure.
            $this->assertIsBool($result->allowed);
            $this->assertIsInt($result->remaining);
            $this->assertIsInt($result->reset_in);
            $this->assertIsInt($result->limit);

            // Validate bounds.
            $this->assertGreaterThanOrEqual(0, $result->remaining);
            $this->assertGreaterThanOrEqual(0, $result->reset_in);
            $this->assertLessThanOrEqual($window, $result->reset_in);
            $this->assertEquals($limit, $result->limit);
        }
    }

    /**
     * Data provider for random configurations
     *
     * @return array
     */
    public static function random_configurations_provider(): array {
        return [
            'small limit short window' => [5, 30],
            'medium limit medium window' => [50, 60],
            'large limit long window' => [100, 120],
            'single request' => [1, 60],
            'high throughput' => [200, 60],
        ];
    }

    /**
     * Data provider for small limits (for exhaustive tests)
     *
     * @return array
     */
    public static function small_limit_provider(): array {
        return [
            'limit 1' => [1],
            'limit 2' => [2],
            'limit 3' => [3],
            'limit 5' => [5],
            'limit 10' => [10],
        ];
    }
}
