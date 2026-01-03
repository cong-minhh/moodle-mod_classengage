# ClassEngage Performance Tests

Quick reference for running performance tests. **All commands run inside Docker.**

## Container

```bash
docker exec -it bin-webserver-1 bash
```

---

## 1. Benchmark Performance ✅

**Best for:** Measuring raw function performance (direct PHP, no HTTP).

```bash
# Basic run
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/benchmark_performance.php

# With more iterations
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/benchmark_performance.php --iterations=500

# Verbose + cleanup
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/benchmark_performance.php --verbose --cleanup
```

> **Requires:** An active ClassEngage session with at least one question.

---

## 2. Load Test API ✅

**Best for:** Simulating concurrent users via HTTP API.

### Setup:

```bash
# Create test users
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=create --users=200 --prefix=loadtest

# Enroll in course
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=enroll --courseid=2 --prefix=loadtest
```

### Load Tests:

```bash
# Batch submission (10 responses per batch)
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=batch --sessionid=4 --prefix=loadtest --batchsize=10

# Answer (legacy single submissions)
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=answer --sessionid=3 --prefix=loadtest

# Concurrent simulation (200 users)
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=concurrent --sessionid=3 --prefix=loadtest --users=200

# Cleanup
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=cleanup --prefix=loadtest
```

| Action       | Description                    |
| ------------ | ------------------------------ |
| `create`     | Create test users              |
| `enroll`     | Enroll users in a course       |
| `answer`     | Single answer submissions      |
| `batch`      | Batch response submission      |
| `concurrent` | Simulate 200+ concurrent users |
| `cleanup`    | Delete test users              |

---

## 3. Property Tests (PHPUnit) ✅

**Best for:** Validating performance requirements (latency, scalability).

```bash
# Run all performance property tests
docker exec -it bin-webserver-1 php /var/www/html/vendor/bin/phpunit /var/www/html/mod/classengage/tests/performance/performance_property_test.php

# Run specific test
docker exec -it bin-webserver-1 php /var/www/html/vendor/bin/phpunit --filter test_property_concurrent_submission_scalability /var/www/html/mod/classengage/tests/performance/performance_property_test.php
```

### Key Tests

| Test Method                                       | What it validates               |
| ------------------------------------------------- | ------------------------------- |
| `test_property_concurrent_submission_scalability` | Batch latency under 1s          |
| `test_property_200_concurrent_submissions`        | 200 user concurrent submissions |
| `test_property_resource_utilization_under_load`   | Memory/CPU efficiency           |
| `test_property_graceful_degradation`              | System stability under overload |

---

## Quick Commands

```bash
# Benchmark
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/benchmark_performance.php --cleanup

# Batch load test (200 users)
docker exec -it bin-webserver-1 php /var/www/html/mod/classengage/tests/performance/load_test_api.php --action=batch --sessionid=3 --prefix=loadtest --batchsize=10 --verbose

# PHPUnit property tests
docker exec -it bin-webserver-1 php /var/www/html/vendor/bin/phpunit /var/www/html/mod/classengage/tests/performance/performance_property_test.php
```
