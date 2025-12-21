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
 * Rate Limiter for ClassEngage plugin
 *
 * Implements token bucket rate limiting to prevent abuse and ensure fair resource usage.
 * Designed for enterprise-scale deployments with 200+ concurrent users.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Rate limiter result class
 */
class rate_limit_result {
    /** @var bool Whether the request is allowed */
    public bool $allowed;

    /** @var int Remaining requests in current window */
    public int $remaining;

    /** @var int Seconds until rate limit resets */
    public int $reset_in;

    /** @var int Total limit for the window */
    public int $limit;

    /**
     * Constructor
     *
     * @param bool $allowed Whether request is allowed
     * @param int $remaining Remaining requests
     * @param int $resetin Seconds until reset
     * @param int $limit Total limit
     */
    public function __construct(bool $allowed, int $remaining, int $resetin, int $limit) {
        $this->allowed = $allowed;
        $this->remaining = $remaining;
        $this->reset_in = $resetin;
        $this->limit = $limit;
    }
}

/**
 * Rate limiter class
 *
 * Uses Moodle cache for distributed rate limiting across multiple app servers.
 */
class rate_limiter {
    /** @var \cache Rate limiting cache instance */
    private $cache;

    /** @var int Requests per window */
    private int $limit;

    /** @var int Window duration in seconds */
    private int $window;

    /**
     * Constructor
     *
     * @param int|null $limit Requests per window (default from constants)
     * @param int|null $window Window duration in seconds (default from constants)
     */
    public function __construct(?int $limit = null, ?int $window = null) {
        $this->cache = \cache::make('mod_classengage', 'rate_limiting');
        $this->limit = $limit ?? constants::RATE_LIMIT_REQUESTS_PER_MINUTE;
        $this->window = $window ?? constants::RATE_LIMIT_WINDOW;
    }

    /**
     * Check if request is allowed and consume a token
     *
     * @param int $userid User ID
     * @param string $action Action being performed (for granular limiting)
     * @return rate_limit_result
     */
    public function check(int $userid, string $action = 'default'): rate_limit_result {
        $key = $this->get_key($userid, $action);
        $now = time();

        $data = $this->cache->get($key);

        if ($data === false) {
            // First request in window.
            $data = [
                'count' => 1,
                'window_start' => $now,
            ];
            $this->cache->set($key, $data);

            return new rate_limit_result(true, $this->limit - 1, $this->window, $this->limit);
        }

        $windowstart = $data['window_start'];
        $count = $data['count'];
        $elapsed = $now - $windowstart;

        // Check if window has expired.
        if ($elapsed >= $this->window) {
            // Reset window.
            $data = [
                'count' => 1,
                'window_start' => $now,
            ];
            $this->cache->set($key, $data);

            return new rate_limit_result(true, $this->limit - 1, $this->window, $this->limit);
        }

        // Check if limit exceeded.
        if ($count >= $this->limit) {
            $resetin = $this->window - $elapsed;
            return new rate_limit_result(false, 0, $resetin, $this->limit);
        }

        // Increment counter.
        $data['count'] = $count + 1;
        $this->cache->set($key, $data);

        $remaining = $this->limit - $data['count'];
        $resetin = $this->window - $elapsed;

        return new rate_limit_result(true, $remaining, $resetin, $this->limit);
    }

    /**
     * Check without consuming a token (for informational purposes)
     *
     * @param int $userid User ID
     * @param string $action Action type
     * @return rate_limit_result
     */
    public function peek(int $userid, string $action = 'default'): rate_limit_result {
        $key = $this->get_key($userid, $action);
        $now = time();

        $data = $this->cache->get($key);

        if ($data === false) {
            return new rate_limit_result(true, $this->limit, $this->window, $this->limit);
        }

        $windowstart = $data['window_start'];
        $count = $data['count'];
        $elapsed = $now - $windowstart;

        if ($elapsed >= $this->window) {
            return new rate_limit_result(true, $this->limit, $this->window, $this->limit);
        }

        $remaining = max(0, $this->limit - $count);
        $resetin = $this->window - $elapsed;
        $allowed = $count < $this->limit;

        return new rate_limit_result($allowed, $remaining, $resetin, $this->limit);
    }

    /**
     * Reset rate limit for a user/action
     *
     * @param int $userid User ID
     * @param string $action Action type
     * @return bool Success
     */
    public function reset(int $userid, string $action = 'default'): bool {
        $key = $this->get_key($userid, $action);
        return $this->cache->delete($key);
    }

    /**
     * Get rate limit statistics for a session
     *
     * @param int $sessionid Session ID
     * @return array Statistics array
     */
    public function get_session_stats(int $sessionid): array {
        // This would require iterating through cache keys which isn't efficient.
        // In production, consider a separate aggregation mechanism.
        return [
            'limit' => $this->limit,
            'window' => $this->window,
        ];
    }

    /**
     * Generate cache key
     *
     * @param int $userid User ID
     * @param string $action Action type
     * @return string Cache key
     */
    private function get_key(int $userid, string $action): string {
        return "ratelimit_{$userid}_{$action}";
    }

    /**
     * Apply rate limiting headers to response
     *
     * @param rate_limit_result $result Rate limit result
     * @return void
     */
    public static function apply_headers(rate_limit_result $result): void {
        if (!headers_sent()) {
            header("X-RateLimit-Limit: {$result->limit}");
            header("X-RateLimit-Remaining: {$result->remaining}");
            header("X-RateLimit-Reset: {$result->reset_in}");

            if (!$result->allowed) {
                header('HTTP/1.1 429 Too Many Requests');
                header("Retry-After: {$result->reset_in}");
            }
        }
    }
}
