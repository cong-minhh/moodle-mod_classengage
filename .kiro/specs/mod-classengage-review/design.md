# Design Document: mod_classengage Improvements

## Overview

This document provides design recommendations for completing and improving the mod_classengage Moodle plugin based on the implementation review. It focuses on addressing identified gaps and suggesting better approaches where applicable.

## Architecture

### Current Architecture Assessment

**Strengths**:
- Clean separation of concerns (session_manager, slide_processor, nlp_generator)
- Proper use of Moodle APIs (DML, File API, Gradebook API)
- Web Service API for clicker integration
- AJAX polling for real-time updates

**Areas for Improvement**:
- Missing abstraction layer for response aggregation
- No caching strategy for frequently accessed data
- Limited error recovery mechanisms

### Recommended Architecture Enhancements

```
┌─────────────────────────────────────────────────────────────┐
│                    Presentation Layer                        │
├──────────────────┬──────────────────┬──────────────────────┤
│  Instructor UI   │   Student UI     │   Admin Settings     │
│  - Control Panel │   - Quiz View    │   - NLP Config       │
│  - Analytics     │   - Results      │   - Performance      │
│  - Question Mgmt │                  │                      │
└──────────────────┴──────────────────┴──────────────────────┘
                            │
┌───────────────────────────┴───────────────────────────────┐
│                    Service Layer                           │
├──────────────────┬──────────────────┬────────────────────┤
│ session_manager  │ analytics_engine │ question_manager   │
│ - State machine  │ - Aggregation    │ - Review workflow  │
│ - Transitions    │ - Caching        │ - Approval         │
└──────────────────┴──────────────────┴────────────────────┘
                            │
┌───────────────────────────┴───────────────────────────────┐
│                    Data Access Layer                       │
├──────────────────┬──────────────────┬────────────────────┤
│ response_handler │ question_repo    │ session_repo       │
│ - CRUD ops       │ - CRUD ops       │ - CRUD ops         │
│ - Aggregation    │ - Filtering      │ - State queries    │
└──────────────────┴──────────────────┴────────────────────┘
```

## Components and Interfaces

### 1. Analytics Engine (NEW)

**Purpose**: Centralize response aggregation and analytics calculations

**Class**: `\mod_classengage\analytics_engine`

**Methods**:
```php
class analytics_engine {
    /**
     * Get real-time response distribution for current question
     * @param int $sessionid
     * @return array ['A' => 10, 'B' => 15, 'C' => 5, 'D' => 2]
     */
    public function get_current_question_stats($sessionid);
    
    /**
     * Get session summary statistics
     * @param int $sessionid
     * @return object {total_participants, avg_score, completion_rate}
     */
    public function get_session_summary($sessionid);
    
    /**
     * Get question-by-question breakdown
     * @param int $sessionid
     * @return array of question stats
     */
    public function get_question_breakdown($sessionid);
    
    /**
     * Get student performance data
     * @param int $sessionid
     * @param int $userid
     * @return object {correct, total, percentage, rank}
     */
    public function get_student_performance($sessionid, $userid);
}
```

**Caching Strategy**:
- Cache aggregated stats for 2 seconds (matches polling interval)
- Invalidate cache on new response submission
- Use Moodle's cache API with 'application' store

### 2. Question Manager (ENHANCED)

**Purpose**: Handle question review, approval, and editing workflow

**Class**: `\mod_classengage\question_manager`

**Methods**:
```php
class question_manager {
    /**
     * Get questions pending review
     * @param int $classengageid
     * @return array of question objects
     */
    public function get_pending_questions($classengageid);
    
    /**
     * Approve a question
     * @param int $questionid
     * @return bool
     */
    public function approve_question($questionid);
    
    /**
     * Bulk approve questions
     * @param array $questionids
     * @return int count of approved
     */
    public function bulk_approve($questionids);
    
    /**
     * Reject a question with reason
     * @param int $questionid
     * @param string $reason
     * @return bool
     */
    public function reject_question($questionid, $reason);
    
    /**
     * Edit question content
     * @param int $questionid
     * @param object $data
     * @return bool
     */
    public function update_question($questionid, $data);
}
```

### 3. Session Manager (ENHANCED)

**Current Implementation**: Good foundation
**Recommended Additions**:

```php
class session_manager {
    // EXISTING METHODS (keep as-is)
    
    /**
     * Pause an active session
     * @param int $sessionid
     * @return bool
     */
    public function pause_session($sessionid);
    
    /**
     * Resume a paused session
     * @param int $sessionid
     * @return bool
     */
    public function resume_session($sessionid);
    
    /**
     * Get session state with current question details
     * @param int $sessionid
     * @return object Complete session state
     */
    public function get_session_state($sessionid);
    
    /**
     * Go back to previous question
     * @param int $sessionid
     * @return bool
     */
    public function previous_question($sessionid);
}
```

## Data Models

### Enhanced Database Schema

#### 1. Add 'paused' Status

**Table**: `classengage_sessions`
**Change**: Update status field to support 'ready', 'active', 'paused', 'completed'

**Migration**:
```php
// In db/upgrade.php
if ($oldversion < 2025110303) {
    // No schema change needed, just documentation
    // Status field already supports any varchar(20) value
    upgrade_mod_savepoint(true, 2025110303, 'classengage');
}
```

#### 2. Add Question Rejection Tracking

**New Table**: `classengage_question_reviews`

```xml
<TABLE NAME="classengage_question_reviews">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="questionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="reviewerid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="action" TYPE="char" LENGTH="20" NOTNULL="true" COMMENT="approved, rejected, edited"/>
    <FIELD NAME="comment" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="questionid" TYPE="foreign" FIELDS="questionid" REFTABLE="classengage_questions" REFFIELDS="id"/>
    <KEY NAME="reviewerid" TYPE="foreign" FIELDS="reviewerid" REFTABLE="user" REFFIELDS="id"/>
  </KEYS>
</TABLE>
```

#### 3. Add Response Aggregation Cache

**New Table**: `classengage_response_cache`

```xml
<TABLE NAME="classengage_response_cache">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="sessionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="questionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="stats_json" TYPE="text" NOTNULL="true" COMMENT="Cached aggregation results"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="sessionid" TYPE="foreign" FIELDS="sessionid" REFTABLE="classengage_sessions" REFFIELDS="id"/>
    <KEY NAME="questionid" TYPE="foreign" FIELDS="questionid" REFTABLE="classengage_questions" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="session_question" UNIQUE="true" FIELDS="sessionid, questionid"/>
  </INDEXES>
</TABLE>
```

### Recommended Index Additions

```sql
-- Optimize response aggregation queries
CREATE INDEX idx_responses_session_question 
ON mdl_classengage_responses(sessionid, questionid, answer);

-- Optimize student performance queries
CREATE INDEX idx_responses_user_correct 
ON mdl_classengage_responses(userid, classengageid, iscorrect);
```

## Error Handling

### Current Approach
- Basic try-catch blocks
- Generic error messages
- Debugging output

### Recommended Approach

**Error Code System**:
```php
class classengage_error {
    const ERR_NLP_CONNECTION = 'nlp_connection_failed';
    const ERR_NLP_INVALID_RESPONSE = 'nlp_invalid_response';
    const ERR_SESSION_NOT_ACTIVE = 'session_not_active';
    const ERR_DUPLICATE_RESPONSE = 'duplicate_response';
    const ERR_INVALID_ANSWER = 'invalid_answer_format';
    const ERR_NO_QUESTIONS = 'no_approved_questions';
    
    public static function get_message($code, $a = null) {
        return get_string('error:' . $code, 'mod_classengage', $a);
    }
}
```

**Usage**:
```php
if ($session->status !== 'active') {
    throw new moodle_exception(
        classengage_error::ERR_SESSION_NOT_ACTIVE,
        'mod_classengage',
        '',
        null,
        classengage_error::get_message(classengage_error::ERR_SESSION_NOT_ACTIVE)
    );
}
```

## Testing Strategy

### Unit Tests (PHPUnit)

**Priority Test Classes**:

1. **session_manager_test.php**
```php
class session_manager_test extends advanced_testcase {
    public function test_create_session_selects_approved_questions_only();
    public function test_start_session_stops_other_active_sessions();
    public function test_next_question_increments_counter();
    public function test_next_question_completes_session_at_end();
    public function test_pause_resume_session();
    public function test_stop_session_triggers_gradebook_update();
}
```

2. **analytics_engine_test.php**
```php
class analytics_engine_test extends advanced_testcase {
    public function test_get_current_question_stats_aggregates_correctly();
    public function test_get_session_summary_calculates_averages();
    public function test_caching_reduces_database_queries();
}
```

3. **question_manager_test.php**
```php
class question_manager_test extends advanced_testcase {
    public function test_approve_question_changes_status();
    public function test_bulk_approve_handles_multiple_questions();
    public function test_reject_question_records_reason();
}
```

### Integration Tests

**Test Scenarios**:
1. Complete workflow: Upload → Generate → Review → Approve → Create Session → Run → Grade
2. Concurrent student submissions (simulate 50 students)
3. NLP service failure handling
4. Gradebook synchronization accuracy

### Behat Tests

**Feature Files**:

1. **instructor_workflow.feature**
```gherkin
Feature: Instructor can manage quiz sessions
  As an instructor
  I want to create and control quiz sessions
  So that I can engage students in real-time

  Scenario: Create and start a quiz session
    Given I am logged in as "teacher1"
    And I am on the "Test Course" course homepage
    And I follow "Class Engagement Activity"
    When I navigate to "Manage Sessions" in current page administration
    And I fill in "Session name" with "Midterm Review"
    And I fill in "Number of questions" with "10"
    And I press "Create Session"
    Then I should see "Session created successfully"
    When I click "Start Session" for "Midterm Review"
    Then I should see "Session started"
    And the session status should be "active"
```

2. **student_participation.feature**
```gherkin
Feature: Student can participate in quiz
  As a student
  I want to answer quiz questions
  So that I can demonstrate my understanding

  Scenario: Submit answer during active session
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
    And there is an active quiz session
    When I log in as "student1"
    And I follow "Class Engagement Activity"
    And I click "Join Quiz"
    Then I should see the current question
    When I select answer "B"
    And I press "Submit Answer"
    Then I should see "Response recorded"
```

## UI/UX Improvements

### 1. Instructor Control Panel

**Current State**: Exists but needs enhancement
**Recommended Design**:

```
┌─────────────────────────────────────────────────────────┐
│  Session: Midterm Review          [Pause] [Next] [End]  │
├─────────────────────────────────────────────────────────┤
│  Question 3 of 10                    Time: 0:25 / 0:30  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  What is the capital of France?                         │
│                                                          │
│  ┌────────────────────────────────────────────────┐    │
│  │ A. London          ████████░░░░░░░░  15 (25%)  │    │
│  │ B. Paris           ████████████████  35 (58%)  │    │
│  │ C. Berlin          ████░░░░░░░░░░░░   8 (13%)  │    │
│  │ D. Madrid          ██░░░░░░░░░░░░░░   2 (3%)   │    │
│  └────────────────────────────────────────────────┘    │
│                                                          │
│  Total Responses: 60 / 75 students (80%)                │
│  Correct: 35 (58%)                                      │
│                                                          │
│  [Show Correct Answer] [Export Results]                 │
└─────────────────────────────────────────────────────────┘
```

**Implementation**:
- Real-time bar charts using Chart.js or similar
- Auto-refresh every 1 second via AJAX
- Visual indicators for response rate
- One-click answer reveal

### 2. Question Review Interface

**Recommended Design**:

```
┌─────────────────────────────────────────────────────────┐
│  Pending Questions (12)        [Approve All] [Settings]  │
├─────────────────────────────────────────────────────────┤
│  ☐ Question 1                    Difficulty: Medium     │
│     What is photosynthesis?                             │
│     A. Process of... ✓ CORRECT                          │
│     B. Process of...                                    │
│     C. Process of...                                    │
│     D. Process of...                                    │
│     Source: NLP (Slide 3)                               │
│     [✓ Approve] [✎ Edit] [✗ Reject]                    │
├─────────────────────────────────────────────────────────┤
│  ☐ Question 2                    Difficulty: Hard       │
│     Which of the following...                           │
│     ...                                                 │
└─────────────────────────────────────────────────────────┘
```

**Features**:
- Inline editing
- Bulk selection and approval
- Difficulty level adjustment
- Source tracking (which slide)
- Rejection with comments

### 3. Analytics Dashboard

**Recommended Visualizations**:

1. **Session Overview**
   - Participation rate over time (line chart)
   - Average score distribution (histogram)
   - Question difficulty vs. success rate (scatter plot)

2. **Question Analysis**
   - Most missed questions
   - Average response time per question
   - Answer distribution patterns

3. **Student Performance**
   - Individual student scores
   - Comparative rankings
   - Progress over multiple sessions

## Performance Optimization

### 1. Database Query Optimization

**Current Issue**: Aggregation queries run on every poll

**Solution**: Implement caching layer

```php
class analytics_engine {
    private function get_cached_stats($sessionid, $questionid) {
        $cache = cache::make('mod_classengage', 'response_stats');
        $key = "{$sessionid}_{$questionid}";
        
        $stats = $cache->get($key);
        if ($stats === false) {
            $stats = $this->calculate_stats($sessionid, $questionid);
            $cache->set($key, $stats, 2); // Cache for 2 seconds
        }
        return $stats;
    }
}
```

### 2. Reduce Polling Load

**Current**: All students poll every 1-2 seconds

**Optimization**: Implement exponential backoff

```javascript
var pollInterval = 1000; // Start at 1 second
var maxInterval = 5000;  // Max 5 seconds

function poll() {
    getCurrentQuestion(sessionid).then(function(response) {
        if (response.changed) {
            pollInterval = 1000; // Reset to fast polling
        } else {
            pollInterval = Math.min(pollInterval * 1.2, maxInterval);
        }
        setTimeout(poll, pollInterval);
    });
}
```

### 3. Database Connection Pooling

**Recommendation**: For high-concurrency scenarios, ensure Moodle's database connection pooling is properly configured

```php
// In config.php
$CFG->dboptions = array(
    'dbpersist' => true,
    'dbsocket' => false,
    'dbport' => '',
    'dbhandlesoptions' => false,
    'dbcollation' => 'utf8mb4_unicode_ci',
);
```

## Security Enhancements

### 1. Rate Limiting

**Issue**: No protection against rapid-fire submissions

**Solution**: Implement rate limiting

```php
class response_handler {
    private function check_rate_limit($userid, $sessionid) {
        $cache = cache::make('mod_classengage', 'rate_limit');
        $key = "submit_{$userid}_{$sessionid}";
        
        $lastsubmit = $cache->get($key);
        if ($lastsubmit && (time() - $lastsubmit) < 1) {
            throw new moodle_exception('rate_limit_exceeded', 'mod_classengage');
        }
        
        $cache->set($key, time(), 5);
    }
}
```

### 2. CSRF Protection Enhancement

**Current**: Uses sesskey (good)
**Additional**: Add request origin validation

```php
function validate_ajax_request() {
    global $CFG;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    if (!empty($origin) && strpos($origin, $CFG->wwwroot) !== 0) {
        throw new moodle_exception('invalid_origin', 'mod_classengage');
    }
}
```

### 3. Input Sanitization

**Current**: Good use of PARAM types
**Enhancement**: Add additional validation

```php
function validate_answer($answer, $question) {
    $answer = strtoupper(trim($answer));
    
    // Validate against question type
    if ($question->questiontype === 'multichoice') {
        if (!in_array($answer, ['A', 'B', 'C', 'D'])) {
            throw new invalid_parameter_exception('Invalid answer option');
        }
    }
    
    return $answer;
}
```

## Deployment Considerations

### 1. Configuration Management

**Recommended Settings** (settings.php):

```php
$settings->add(new admin_setting_configtext(
    'mod_classengage/nlpendpoint',
    get_string('nlpendpoint', 'mod_classengage'),
    get_string('nlpendpoint_desc', 'mod_classengage'),
    'http://localhost:3000/api',
    PARAM_URL
));

$settings->add(new admin_setting_configpasswordunmask(
    'mod_classengage/nlpapikey',
    get_string('nlpapikey', 'mod_classengage'),
    get_string('nlpapikey_desc', 'mod_classengage'),
    ''
));

$settings->add(new admin_setting_configcheckbox(
    'mod_classengage/autogeneratequestions',
    get_string('autogeneratequestions', 'mod_classengage'),
    get_string('autogeneratequestions_desc', 'mod_classengage'),
    1
));

$settings->add(new admin_setting_configtext(
    'mod_classengage/defaultquestions',
    get_string('defaultquestions', 'mod_classengage'),
    get_string('defaultquestions_desc', 'mod_classengage'),
    10,
    PARAM_INT
));

$settings->add(new admin_setting_configtext(
    'mod_classengage/pollinginterval',
    get_string('pollinginterval', 'mod_classengage'),
    get_string('pollinginterval_desc', 'mod_classengage'),
    1000,
    PARAM_INT
));

$settings->add(new admin_setting_configtext(
    'mod_classengage/maxconcurrentsessions',
    get_string('maxconcurrentsessions', 'mod_classengage'),
    get_string('maxconcurrentsessions_desc', 'mod_classengage'),
    5,
    PARAM_INT
));
```

### 2. Monitoring and Logging

**Implement Comprehensive Event Logging**:

```php
// classes/event/document_uploaded.php
class document_uploaded extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'classengage_slides';
    }
}

// classes/event/questions_generated.php
class questions_generated extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'classengage_questions';
    }
}

// classes/event/response_submitted.php
class response_submitted extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'classengage_responses';
    }
}
```

### 3. Backup and Restore

**Ensure Complete Backup Coverage**:

```php
// backup/moodle2/backup_classengage_stepslib.php
protected function define_structure() {
    // Add all tables to backup
    $classengage = new backup_nested_element('classengage', ['id'], [
        'name', 'intro', 'introformat', 'grade', 'timecreated', 'timemodified'
    ]);
    
    $slides = new backup_nested_element('slides');
    $slide = new backup_nested_element('slide', ['id'], [
        'title', 'filename', 'status', 'extractedtext', 'timecreated'
    ]);
    
    $questions = new backup_nested_element('questions');
    $question = new backup_nested_element('question', ['id'], [
        'questiontext', 'questiontype', 'optiona', 'optionb', 'optionc', 'optiond',
        'correctanswer', 'difficulty', 'status', 'source', 'timecreated'
    ]);
    
    // Include sessions and responses for historical data
    // ...
}
```

## Summary

### Priority Implementation Order

**Phase 1: Critical Gaps** (2-3 weeks)
1. Implement analytics_engine class with caching
2. Complete instructor control panel with real-time updates
3. Build question review/approval UI
4. Add pause/resume session functionality

**Phase 2: Quality Improvements** (2-3 weeks)
5. Expand test coverage (PHPUnit + Behat)
6. Implement all event logging
7. Add comprehensive error handling
8. Optimize database queries with indices

**Phase 3: Polish** (1-2 weeks)
9. Enhance UI/UX with better visualizations
10. Add admin configuration options
11. Implement rate limiting and security enhancements
12. Complete backup/restore functionality

### Key Design Principles

1. **Separation of Concerns**: Keep business logic in service classes, not in page scripts
2. **Caching Strategy**: Cache aggregated data for 2 seconds to reduce database load
3. **Error Handling**: Use specific error codes with user-friendly messages
4. **Testing**: Aim for 80%+ code coverage with unit tests
5. **Performance**: Optimize for 100+ concurrent users per session
6. **Security**: Follow Moodle security best practices throughout

### Better Approaches Identified

1. **Analytics Caching**: Implement application-level caching for response aggregation
2. **Question Review Workflow**: Add review history tracking table
3. **Session State Machine**: Add 'paused' state for better control
4. **Error Handling**: Use error code constants instead of generic messages
5. **Polling Optimization**: Implement exponential backoff to reduce server load
6. **Database Indices**: Add composite indices for common query patterns
