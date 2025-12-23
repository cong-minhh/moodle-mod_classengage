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
 * Cache definitions for mod_classengage
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = array(
    'response_stats' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 2, // Cache for 2 seconds to match polling interval
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
    ),
    // Real-time quiz engine caches (Requirements 3.1, 3.5).
    'session_state' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 60, // Cache session state for 60 seconds
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ),
    'connection_status' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 10, // Short TTL for connection status (10 seconds)
        'staticacceleration' => true,
        'staticaccelerationsize' => 200, // Support 200+ concurrent users
    ),
    'question_broadcast' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // Cache question broadcasts for 5 minutes
        'staticacceleration' => true,
        'staticaccelerationsize' => 20,
    ),
    // Enterprise optimization: Analytics summary cache.
    'analytics_summary' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 30, // 30 seconds for analytics summaries
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ),
    // Enterprise optimization: Question data cache (questions rarely change during session).
    'question_data' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600, // 1 hour - questions rarely change during session
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ),
    // Enterprise optimization: Rate limiting cache for abuse prevention.
    'rate_limiting' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60, // 1 minute sliding window
        'staticacceleration' => true,
        'staticaccelerationsize' => 500, // Support higher concurrent users
    ),
    // Enterprise: User performance cache for real-time leaderboards.
    'user_performance' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 10, // 10 seconds for real-time feel
        'staticacceleration' => true,
        'staticaccelerationsize' => 200, // Support 200+ concurrent users
    ),
    // Enterprise: Session summary cache for instructor dashboard.
    'session_summary' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 5, // 5 seconds for near-real-time dashboard updates
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ),
);
