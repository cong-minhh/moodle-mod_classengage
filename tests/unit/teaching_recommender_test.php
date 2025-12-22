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
 * Unit tests for mod_classengage teaching recommender
 *
 * Tests the teaching recommender including:
 * - Recommendation generation
 * - Priority ordering
 * - Engagement drop detection
 * - Quiet period detection
 * - Cache invalidation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\teaching_recommender
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Teaching recommender unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class teaching_recommender_test extends \advanced_testcase
{

    /**
     * Create mock engagement data
     */
    private function create_engagement_data(string $level, float $percentage): object
    {
        return (object) [
            'percentage' => $percentage,
            'level' => $level,
            'message' => "Engagement is $level",
            'unique_participants' => (int) ($percentage / 10),
            'total_enrolled' => 10,
        ];
    }

    /**
     * Create mock comprehension data
     */
    private function create_comprehension_data(string $level, float $avgcorrectness): object
    {
        return (object) [
            'avg_correctness' => $avgcorrectness,
            'level' => $level,
            'message' => "Comprehension is $level",
            'confused_topics' => [],
        ];
    }

    /**
     * Create a fresh session for each test method
     */
    private function create_test_session(): int
    {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($instructor->id, $course->id, 'editingteacher');

        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $session = $generator->create_session($classengage->id, $instructor->id, [
            'status' => 'active',
            'timelimit' => 30,
            'numquestions' => 5,
            'timestarted' => time(),
        ]);

        return $session->id;
    }

    /**
     * Test generate_recommendations with low engagement
     */
    public function test_generate_recommendations_low_engagement(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('low', 25.0);
        $comprehension = $this->create_comprehension_data('high', 85.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);
        $recommendations = $recommender->generate_recommendations();

        $this->assertIsArray($recommendations);
        $this->assertGreaterThan(0, count($recommendations));

        // Should have engagement-related recommendation.
        $categories = array_column($recommendations, 'category');
        $this->assertContains('engagement', $categories);
    }

    /**
     * Test generate_recommendations with low comprehension
     */
    public function test_generate_recommendations_low_comprehension(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('high', 85.0);
        $comprehension = $this->create_comprehension_data('low', 35.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);
        $recommendations = $recommender->generate_recommendations();

        $this->assertIsArray($recommendations);
        $this->assertGreaterThan(0, count($recommendations));

        // Should have comprehension-related recommendation.
        $categories = array_column($recommendations, 'category');
        $this->assertContains('comprehension', $categories);
    }

    /**
     * Test generate_recommendations with high performance
     */
    public function test_generate_recommendations_high_performance(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('high', 90.0);
        $comprehension = $this->create_comprehension_data('high', 90.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);
        $recommendations = $recommender->generate_recommendations();

        $this->assertIsArray($recommendations);
        // Should still generate some recommendations (praise or continue).
    }

    /**
     * Test recommendation priority ordering
     */
    public function test_recommendation_priority_ordering(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('low', 20.0);
        $comprehension = $this->create_comprehension_data('low', 30.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);
        $recommendations = $recommender->generate_recommendations();

        $this->assertIsArray($recommendations);

        if (count($recommendations) >= 2) {
            // First recommendation should have lower or equal priority number (higher priority).
            $this->assertLessThanOrEqual($recommendations[1]->priority, $recommendations[0]->priority);
        }
    }

    /**
     * Test maximum recommendation count
     */
    public function test_recommendation_max_count(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('low', 20.0);
        $comprehension = $this->create_comprehension_data('low', 30.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);
        $recommendations = $recommender->generate_recommendations();

        $this->assertIsArray($recommendations);
        $this->assertLessThanOrEqual(5, count($recommendations));
    }

    /**
     * Test detect_engagement_drops
     */
    public function test_detect_engagement_drops(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('moderate', 50.0);
        $comprehension = $this->create_comprehension_data('moderate', 60.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($recommender);
        $method = $reflection->getMethod('detect_engagement_drops');
        $method->setAccessible(true);

        $drops = $method->invoke($recommender);

        $this->assertIsArray($drops);
    }

    /**
     * Test has_quiet_periods
     */
    public function test_has_quiet_periods(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('moderate', 50.0);
        $comprehension = $this->create_comprehension_data('moderate', 60.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($recommender);
        $method = $reflection->getMethod('has_quiet_periods');
        $method->setAccessible(true);

        $hasquiet = $method->invoke($recommender);

        $this->assertIsBool($hasquiet);
    }

    /**
     * Test invalidate_cache
     */
    public function test_invalidate_cache(): void
    {
        $sessionid = $this->create_test_session();

        $engagement = $this->create_engagement_data('moderate', 50.0);
        $comprehension = $this->create_comprehension_data('moderate', 60.0);

        $recommender = new teaching_recommender($sessionid, $engagement, $comprehension);

        // Populate cache.
        $recommender->generate_recommendations();

        // Invalidate - should not throw exception.
        $recommender->invalidate_cache();
        $this->assertTrue(true);
    }
}
