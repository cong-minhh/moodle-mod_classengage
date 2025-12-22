# mod_classengage Test Suite

Enterprise-grade test suite for the ClassEngage Moodle activity module.

## Directory Structure

```
tests/
├── unit/           # Pure logic tests (no I/O, no DB)
├── property/       # Property-based/invariant tests with randomized inputs
├── integration/    # Tests requiring DB, Moodle APIs, scheduled tasks
├── performance/    # Benchmarks, load tests, SLA validation
├── fixtures/       # Test utilities, data helpers, scripts
├── behat/          # Behavior-driven feature tests
└── generator/      # Moodle data generator (lib.php)
```

## Test Categories

### Unit Tests (`tests/unit/`)

- **Purpose**: Test pure business logic in isolation
- **Characteristics**: Fast, deterministic, no external dependencies
- **Run**: `vendor/bin/phpunit --group mod_classengage_unit`

**Files**:

- `constants_test.php` - Configuration constants validation
- `lib_test.php` - Module library functions
- `rate_limiter_test.php` - Token bucket algorithm
- `health_checker_test.php` - Health monitoring logic

### Property Tests (`tests/property/`)

- **Purpose**: Verify invariants hold across randomized inputs
- **Characteristics**: Uses data providers for input generation
- **Run**: `vendor/bin/phpunit --group mod_classengage_property`

**Files**:

- `*_property_test.php` - Tests with 100+ iterations per property

### Integration Tests (`tests/integration/`)

- **Purpose**: Test DB operations, Moodle APIs, scheduled tasks
- **Characteristics**: Requires database reset, may be slower
- **Run**: `vendor/bin/phpunit --group mod_classengage_integration`

**Files**:

- `db_schema_test.php` - Schema structure validation
- `session_state_manager_test.php` - Full session lifecycle
- `scheduled_tasks_test.php` - Scheduled task execution
- `enterprise_integration_test.php` - End-to-end workflows

### Performance Tests (`tests/performance/`)

- **Purpose**: Measure execution time, memory, and throughput
- **Characteristics**: Should NOT fail CI by default
- **Run**: `vendor/bin/phpunit --group mod_classengage_performance`

> **Note**: Performance tests are marked with `@group slow` to exclude from regular CI runs.

### Behat Tests (`tests/behat/`)

- **Purpose**: Behavior-driven end-to-end testing
- **Run**: `vendor/bin/behat --config behat.yml --tags @mod_classengage`

## CI Pipeline Configuration

### Fast CI (Every Push)

```bash
# Unit + Property tests only (< 30 seconds)
vendor/bin/phpunit --group mod_classengage_unit
vendor/bin/phpunit --group mod_classengage_property
```

### Full CI (Pre-Merge)

```bash
# All non-slow tests
vendor/bin/phpunit --testsuite mod_classengage --exclude-group slow
```

### Nightly/Weekly (Performance Validation)

```bash
# Include slow/performance tests
vendor/bin/phpunit --group mod_classengage_performance
```

## Running Tests

### Prerequisites

```bash
# Initialize PHPUnit for Moodle
php admin/tool/phpunit/cli/init.php
```

### Run All Tests

```bash
cd /path/to/moodle
vendor/bin/phpunit mod/classengage/tests/
```

### Run Specific Category

```bash
# Unit tests only
vendor/bin/phpunit mod/classengage/tests/unit/

# Integration tests only
vendor/bin/phpunit mod/classengage/tests/integration/
```

### Run by Group

```bash
# All mod_classengage tests
vendor/bin/phpunit --group mod_classengage

# Unit tests only
vendor/bin/phpunit --group mod_classengage_unit

# Exclude slow tests
vendor/bin/phpunit --group mod_classengage --exclude-group slow
```

## Test Conventions

### Naming

- Test files: `{component}_test.php`
- Property tests: `{component}_property_test.php`
- Test classes: Match filename (e.g., `constants_test`)

### Structure

All tests follow **Arrange → Act → Assert** pattern:

```php
public function test_example_behavior(): void {
    // Arrange: Set up test fixtures
    $this->resetAfterTest(true);
    $limiter = new rate_limiter(10, 60);

    // Act: Execute the behavior under test
    $result = $limiter->check(123, 'test');

    // Assert: Verify expected outcomes
    $this->assertTrue($result->allowed);
}
```

### Group Annotations

All test classes include group annotations:

```php
/**
 * @group mod_classengage
 * @group mod_classengage_{category}
 */
class example_test extends \advanced_testcase { }
```

## SLA Thresholds (Performance Tests)

| Metric            | Target  | Requirement |
| ----------------- | ------- | ----------- |
| Response Latency  | ≤ 1.0s  | NFR-01      |
| Broadcast Latency | ≤ 500ms | NFR-02      |
| Concurrent Users  | ≥ 200   | NFR-03      |
| Success Rate      | ≥ 95%   | NFR-04      |
