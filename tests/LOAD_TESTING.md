# Load Testing Guide for ClassEngage

This guide explains how to use the load testing script to verify the performance and reliability of the ClassEngage clicker API under concurrent user load.

## Quick Start

```bash
# 1. Start a quiz session in ClassEngage
# 2. Note the session ID and course ID
# 3. Run the load test
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --cleanup
```

## Prerequisites

### System Requirements
- PHP 7.4+ with curl extension
- Moodle 4.0+ with ClassEngage plugin installed
- Web services enabled
- Active quiz session

### Setup Steps

1. **Enable Web Services**
   ```
   Site Administration → Advanced features → Enable web services ✓
   Site Administration → Server → Web services → Manage protocols → Enable REST ✓
   ```

2. **Configure Email (Optional)**
   
   To avoid email warnings during enrollment, configure a valid noreply email:
   ```
   Site Administration → Server → Email → Outgoing mail configuration
   Set "No-reply address" to a valid email (e.g., noreply@yourdomain.com)
   ```
   
   Or the script will automatically disable welcome emails during enrollment.

3. **Create Active Session**
   - Navigate to a ClassEngage activity
   - Go to "Quiz Sessions" tab
   - Create and start a session
   - Note the session ID from the URL

3. **Get Course ID**
   - The course ID is in the URL when viewing the course
   - Or check the database: `SELECT id, fullname FROM mdl_course;`

## Usage Examples

### Basic Load Test
Test with 50 users (default):
```bash
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2
```

### High Load Test
Test with 200 concurrent users:
```bash
php mod/classengage/tests/load_test_api.php --users=200 --sessionid=1 --courseid=2
```

### Test with Cleanup
Automatically remove test users after completion:
```bash
php mod/classengage/tests/load_test_api.php --users=100 --sessionid=1 --courseid=2 --cleanup
```

### Gradual Load Test
Add 100ms delay between requests to simulate gradual load:
```bash
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --delay=100
```

### Debug Mode
Show detailed output for each request:
```bash
php mod/classengage/tests/load_test_api.php --users=10 --sessionid=1 --courseid=2 --verbose
```

### Short Options
Use abbreviated flags:
```bash
php mod/classengage/tests/load_test_api.php -u 50 -s 1 -c 2 -v
```

## Understanding the Output

### Step-by-Step Process

1. **Creating test users** - Generates Moodle user accounts
   - Username format: `loadtest_<timestamp>_<number>`
   - Password: `LoadTest123!`
   - Email: `<username>@example.com`

2. **Enrolling users** - Enrolls users in course as students
   - Uses manual enrollment plugin
   - Assigns student role

3. **Generating token** - Creates web service authentication token
   - Uses admin user credentials
   - Token valid for API calls

4. **Getting current question** - Retrieves active question
   - Shows question text preview
   - Displays correct answer

5. **Simulating responses** - Sends concurrent API requests
   - Uses curl_multi for true concurrency
   - Only 50% of users submit responses (realistic participation rate)
   - 60% correct answers, 40% random (realistic simulation)
   - Each user gets unique clicker ID

6. **Verifying integrity** - Checks database consistency
   - Compares API success count with database records
   - Warns if mismatch detected

7. **Cleanup** - Removes test data (if --cleanup flag used)
   - Deletes test users
   - Removes enrollments
   - Responses remain in database for analysis

### Performance Metrics

The script outputs two key numbers:
- **Total users created**: Number of test user accounts generated
- **Actual requests sent**: Number of API calls made (typically 50% of users due to realistic participation simulation)

**Success Rate**
- Target: 100% for stable API
- < 95%: Investigate errors
- < 90%: Critical issues

**Throughput**
- Good: > 50 requests/second
- Acceptable: 20-50 requests/second
- Poor: < 20 requests/second

**Average Response Time**
- Excellent: < 50ms
- Good: 50-200ms
- Acceptable: 200-500ms
- Poor: > 500ms

## Troubleshooting

### Common Issues

**Error: "Session is not active"**
```
Solution: Start the quiz session before running the test
```

**Error: "No active question found"**
```
Solution: Ensure the session has questions and is on a valid question
```

**Error: "Failed to generate web service token"**
```
Solution: 
1. Enable web services in Site Administration
2. Enable REST protocol
3. Ensure you have admin privileges
4. Check that external services are not disabled
```

**Warning: "Invalid noreply-email"**
```
This is a non-critical warning during enrollment.
The script automatically disables welcome emails to avoid this.

To fix permanently:
Site Administration → Server → Email → Outgoing mail configuration
Set "No-reply address" to a valid email address
```

**Error: "Call to undefined function external_generate_token"**
```
Solution:
The script should include lib/externallib.php automatically.
If you see this error, ensure you're running the script from the correct directory.
```

**Low Success Rate (< 95%)**
```
Possible causes:
1. Database connection limits
2. PHP memory limits
3. Web server timeout settings
4. Network issues

Check the "Error Summary" section for specific error messages
```

**Database Integrity Mismatch**
```
Possible causes:
1. Transaction rollbacks
2. Database constraints
3. Duplicate submission prevention

Check error messages and database logs
```

### Performance Optimization

**If throughput is low:**

1. **Increase PHP memory limit**
   ```php
   // In config.php
   ini_set('memory_limit', '512M');
   ```

2. **Optimize database**
   ```sql
   -- Add missing indexes
   CREATE INDEX idx_responses_session ON mdl_classengage_responses(sessionid);
   ```

3. **Enable caching**
   ```
   Site Administration → Plugins → Caching → Enable caching
   ```

4. **Use database connection pooling**
   - Configure in database server settings

**If response time is high:**

1. **Check database query performance**
   ```sql
   -- Enable slow query log
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 0.1;
   ```

2. **Review analytics cache settings**
   - Increase cache TTL
   - Use faster cache backend (Redis/Memcached)

3. **Optimize web server**
   - Enable opcache
   - Increase PHP-FPM workers
   - Use HTTP/2

## Advanced Usage

### HTTP Method

The load test script uses **POST requests** to submit responses to the web service API. This approach:
- Avoids URL length limitations
- Handles special characters properly without encoding issues
- Matches production usage patterns
- Provides better security (parameters not visible in logs)

All parameters are sent in the POST body using `application/x-www-form-urlencoded` format.

### Testing Different Scenarios

**Test with 100% participation:**
Modify the script to have all users submit responses:
```php
// Line ~291 - Comment out or remove these lines:
// if ($idx % 2 !== 0) {
//     continue; // Skip this user
// }
```

**Test with all correct answers:**
Modify the script to always send correct answers:
```php
// Line ~295
$answer = $currentquestion->correctanswer;
```

**Test with all incorrect answers:**
```php
// Line ~295
$wronganswers = array_diff($answers, array($currentquestion->correctanswer));
$answer = $wronganswers[array_rand($wronganswers)];
```

**Test duplicate submission prevention:**
Run the script twice without cleanup to test duplicate detection.

### Stress Testing

**Gradual ramp-up:**
```bash
for users in 10 20 50 100 200; do
    echo "Testing with $users users..."
    php load_test_api.php -u $users -s 1 -c 2
    sleep 5
done
```

**Sustained load:**
```bash
# Run 10 tests with 50 users each
for i in {1..10}; do
    echo "Test iteration $i..."
    php load_test_api.php -u 50 -s 1 -c 2 --cleanup
    sleep 2
done
```

### Monitoring

**Watch database connections:**
```sql
-- MySQL
SHOW PROCESSLIST;

-- PostgreSQL
SELECT * FROM pg_stat_activity;
```

**Monitor server resources:**
```bash
# CPU and memory
htop

# Network
iftop

# Disk I/O
iotop
```

## Best Practices

1. **Start small** - Begin with 10-20 users, then scale up
2. **Use cleanup** - Always use --cleanup flag to avoid cluttering database
3. **Test in staging** - Never run load tests on production
4. **Monitor resources** - Watch CPU, memory, and database during tests
5. **Document results** - Keep records of performance metrics
6. **Test regularly** - Run load tests after major changes
7. **Realistic scenarios** - Use delays and mixed answers for realistic simulation

## Interpreting Results

### Healthy System
```
Success rate: 100.00%
Throughput: 75.32 requests/second
Average response time: 13.28 ms
✓ Database integrity verified
```

### Warning Signs
```
Success rate: 92.00%
Throughput: 18.45 requests/second
Average response time: 542.12 ms
WARNING: Response count mismatch!
```

### Critical Issues
```
Success rate: 45.00%
Throughput: 5.23 requests/second
Average response time: 1847.56 ms
Error Summary:
(15x) Database connection timeout
(10x) Maximum execution time exceeded
```

## Support

If you encounter issues:

1. Check the error summary in the output
2. Run with --verbose flag for detailed debugging
3. Check Moodle error logs
4. Review database logs
5. Check web server error logs

## License

This script is part of the ClassEngage plugin and is licensed under GPL v3.
