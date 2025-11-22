#!/bin/bash
# Load testing suite for ClassEngage
# Runs multiple load tests with increasing user counts

# Configuration
SESSION_ID=1
COURSE_ID=2
CLEANUP=true

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "ClassEngage Load Testing Suite"
echo "========================================="
echo ""
echo "Session ID: $SESSION_ID"
echo "Course ID: $COURSE_ID"
echo "Cleanup: $CLEANUP"
echo ""

# Array of user counts to test
USER_COUNTS=(10 25 50 75 100)

# Results file
RESULTS_FILE="load_test_results_$(date +%Y%m%d_%H%M%S).txt"
echo "Results will be saved to: $RESULTS_FILE"
echo ""

# Header for results file
echo "ClassEngage Load Test Results - $(date)" > $RESULTS_FILE
echo "=========================================" >> $RESULTS_FILE
echo "" >> $RESULTS_FILE

# Run tests
for users in "${USER_COUNTS[@]}"; do
    echo -e "${YELLOW}Testing with $users concurrent users...${NC}"
    echo "----------------------------------------" >> $RESULTS_FILE
    echo "Test: $users users" >> $RESULTS_FILE
    echo "----------------------------------------" >> $RESULTS_FILE
    
    # Build command
    CMD="php load_test_api.php --users=$users --sessionid=$SESSION_ID --courseid=$COURSE_ID"
    if [ "$CLEANUP" = true ]; then
        CMD="$CMD --cleanup"
    fi
    
    # Run test and capture output
    OUTPUT=$($CMD 2>&1)
    EXIT_CODE=$?
    
    # Save to results file
    echo "$OUTPUT" >> $RESULTS_FILE
    echo "" >> $RESULTS_FILE
    
    # Extract key metrics
    SUCCESS_RATE=$(echo "$OUTPUT" | grep "Success rate:" | awk '{print $3}')
    THROUGHPUT=$(echo "$OUTPUT" | grep "Throughput:" | awk '{print $2}')
    AVG_TIME=$(echo "$OUTPUT" | grep "Average response time:" | awk '{print $4}')
    
    # Display summary
    if [ $EXIT_CODE -eq 0 ]; then
        echo -e "${GREEN}✓ Test completed${NC}"
        echo "  Success rate: $SUCCESS_RATE"
        echo "  Throughput: $THROUGHPUT req/s"
        echo "  Avg response time: $AVG_TIME"
    else
        echo -e "${RED}✗ Test failed${NC}"
    fi
    echo ""
    
    # Wait between tests
    if [ $users -ne ${USER_COUNTS[-1]} ]; then
        echo "Waiting 5 seconds before next test..."
        sleep 5
        echo ""
    fi
done

echo "========================================="
echo -e "${GREEN}All tests completed!${NC}"
echo "Results saved to: $RESULTS_FILE"
echo "========================================="
