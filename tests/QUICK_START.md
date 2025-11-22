# Load Testing Quick Start

## 1. Prerequisites Check

```bash
# Check PHP version (need 7.4+)
php -v

# Check curl extension
php -m | grep curl

# Check web services are enabled
# Go to: Site Administration → Advanced features → Enable web services ✓
```

## 2. Prepare Session

1. Create a ClassEngage activity in a course
2. Upload slides and approve questions
3. Create a quiz session
4. **Start the session** (important!)
5. Note the session ID from URL: `controlpanel.php?id=X&sessionid=Y`
6. Note the course ID from URL: `course/view.php?id=Z`

## 3. Run Your First Test

```bash
cd /path/to/moodle/mod/classengage/tests

# Replace 1 and 2 with your actual session ID and course ID
php load_test_api.php --users=10 --sessionid=1 --courseid=2 --cleanup --verbose
```

## 4. Interpret Results

**Good Results:**
- Success rate: 100%
- Throughput: > 50 req/s
- Avg response time: < 100ms

**Warning Signs:**
- Success rate: < 95%
- Throughput: < 20 req/s
- Avg response time: > 500ms

## 5. Scale Up

Once the small test works, increase the load:

```bash
# 50 users
php load_test_api.php --users=50 --sessionid=1 --courseid=2 --cleanup

# 100 users
php load_test_api.php --users=100 --sessionid=1 --courseid=2 --cleanup

# 200 users (stress test)
php load_test_api.php --users=200 --sessionid=1 --courseid=2 --cleanup
```

## 6. Automated Suite

Run all tests at once:

```bash
# Edit the script first to set your session ID and course ID
nano run_load_tests.sh  # or run_load_tests.bat on Windows

# Run the suite
./run_load_tests.sh
```

## Common Issues

**"Session is not active"**
→ Start the session in the control panel first

**"No active question found"**
→ Make sure the session has questions and is on a valid question

**"Failed to generate token"**
→ Enable web services and REST protocol

**Low success rate**
→ Check error summary, may need to increase PHP memory or database connections

## Need Help?

See full documentation: [LOAD_TESTING.md](LOAD_TESTING.md)
