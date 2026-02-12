# ClassEngage API Documentation

## AJAX Endpoints (ajax.php)

### Control Panel Statistics Endpoint

#### `ajax.php?action=getstats`

Real-time AJAX endpoint for control panel updates. Returns current session statistics including participant count, response distribution, and participation rate.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'getstats'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key for CSRF protection

**Authentication:**
- Requires valid Moodle session
- Requires `mod/classengage:startquiz` capability
- User must be logged into the course

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "data": {
    "participants": 25,
    "responses": 23,
    "participationrate": 92.0,
    "distribution": {
      "A": 5,
      "B": 12,
      "C": 4,
      "D": 2,
      "total": 23,
      "correctanswer": "B"
    },
    "status": "active"
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message"
}
```

**Usage Example (JavaScript):**
```javascript
$.ajax({
    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
    method: 'POST',
    data: {
        action: 'getstats',
        sessionid: 12,
        sesskey: M.cfg.sesskey
    },
    dataType: 'json',
    success: function(response) {
        if (response.success) {
            console.log('Participants:', response.data.participants);
            console.log('Distribution:', response.data.distribution);
        }
    }
});
```

**Implementation Details:**
- Uses `session_manager` to retrieve current question
- Uses `analytics_engine` for cached statistics (2-second cache)
- Returns response distribution for current question only
- Participation rate calculated as percentage of enrolled students
- Only available for active sessions

**Security:**
- CSRF protection via `require_sesskey()`
- Capability check: `mod/classengage:startquiz`
- Session ownership verification

**Performance:**
- Analytics data cached for 2 seconds
- Optimized for 1-second polling interval
- Minimal database queries via caching layer

**Added:** Version 2025110306

**Breaking Changes from Previous Version:**
- Removed `getcurrent` action (moved to student quiz interface)
- Removed `submitanswer` action (moved to student quiz interface)
- Focused exclusively on instructor control panel statistics
- Now requires `startquiz` capability instead of `takequiz`

---

#### `ajax.php?action=getstatus`

Real-time AJAX endpoint for student connection status and participation tracking. Returns comprehensive statistics about connected students, their response status for the current question, and aggregate session metrics.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'getstatus'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key for CSRF protection

**Authentication:**
- Requires valid Moodle session
- Requires `mod/classengage:startquiz` capability (instructor only)
- User must be logged into the course

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "sessionid": 12,
  "total_connections": 25,
  "connected": 25,
  "disconnected": 0,
  "answered": 18,
  "pending": 7,
  "statistics": {
    "avg_latency": 245,
    "error_rate": 0.1,
    "throughput": 12
  },
  "students": [
    {
      "userid": 42,
      "fullname": "John Smith",
      "status": "connected",
      "answered": true
    },
    {
      "userid": 43,
      "fullname": "Jane Doe",
      "status": "connected",
      "answered": false
    }
  ]
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | Whether the request succeeded |
| `sessionid` | int | Session ID |
| `total_connections` | int | Total number of student connections/participants |
| `connected` | int | Number of currently connected students |
| `disconnected` | int | Number of disconnected students |
| `answered` | int | Number of students who answered the current question |
| `pending` | int | Number of connected students who haven't answered yet |
| `statistics` | object | Aggregate session performance metrics |
| `statistics.avg_latency` | int | Average response latency in milliseconds |
| `statistics.error_rate` | float | Percentage of failed submissions |
| `statistics.throughput` | int | Responses processed per minute |
| `students` | array | List of students with their status (instructor only) |

**Student Object Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `userid` | int | Moodle user ID |
| `fullname` | string | Student's full name |
| `status` | string | Connection status: `'connected'` or `'disconnected'` |
| `answered` | bool | Whether student has answered the current question |

**Error Response:**
```json
{
  "success": false,
  "error": "Error message"
}
```

**Usage Example (JavaScript):**
```javascript
$.ajax({
    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
    method: 'POST',
    data: {
        action: 'getstatus',
        sessionid: 12,
        sesskey: M.cfg.sesskey
    },
    dataType: 'json',
    success: function(response) {
        if (response.success) {
            console.log('Connected:', response.connected);
            console.log('Answered:', response.answered);
            console.log('Pending:', response.pending);
            
            // Update student list
            response.students.forEach(function(student) {
                console.log(student.fullname + ': ' + 
                    (student.answered ? 'Answered' : 'Pending'));
            });
        }
    }
});
```

**Implementation Details:**
- Uses hybrid approach combining connection-based and response-based statistics
- Connection stats from `heartbeat_manager` for real-time connection tracking
- Response stats from database for accurate participation counts
- Returns the higher of connection-based or response-based counts for reliability
- Student list shows all students who have submitted any response in the session
- `answered` status reflects whether student answered the *current* question

**Data Source Priority:**
The endpoint uses a hybrid approach to ensure accurate counts even when connection tracking is incomplete:
1. Queries `classengage_responses` for students who have submitted answers
2. Queries `heartbeat_manager` for real-time connection status
3. Returns the maximum of both sources for `connected` and `answered` counts
4. This ensures students are counted even if their connection tracking failed

**Performance:**
- Optimized for 2-second polling interval (per Requirement 1.3)
- Uses indexed queries on `classengage_responses` table
- Student list limited to session participants only

**Security:**
- CSRF protection via `require_sesskey()`
- Capability check: `mod/classengage:startquiz`
- Student names only visible to instructors

**Requirements Implemented:** 1.3, 5.1, 5.4, 5.5

**Added:** Version 2025110306

**Updated:** Version 2025120500
- Enhanced to use response-based statistics alongside connection-based statistics
- Student list now derived from actual response submissions for reliability
- Improved accuracy when connection tracking is incomplete

---

### Session Control Endpoints (Planned)

The following endpoints are being implemented for real-time session control:

#### `ajax.php?action=pause`

Pauses an active quiz session, freezing the timer and blocking new submissions.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'pause'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "paused",
    "timer_remaining": 15
  }
}
```

---

#### `ajax.php?action=resume`

Resumes a paused quiz session, restoring the timer and re-enabling submissions.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'resume'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "active",
    "timer_remaining": 15
  }
}
```

---

#### `ajax.php?action=getstudents`

Retrieves the list of connected students with their status and answer state for the instructor control panel. Used for real-time student monitoring during live quiz sessions.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'getstudents'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key for CSRF protection

**Authentication:**
- Requires valid Moodle session
- Requires `mod/classengage:viewanalytics` capability (instructor only)
- User must be logged into the course

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "students": [
    {
      "userid": 42,
      "fullname": "John Smith",
      "status": "connected",
      "hasanswered": true,
      "lastheartbeat": 1701705600,
      "transport": "sse"
    },
    {
      "userid": 43,
      "fullname": "Jane Doe",
      "status": "connected",
      "hasanswered": false,
      "lastheartbeat": 1701705598,
      "transport": "polling"
    }
  ],
  "stats": {
    "connected": 25,
    "disconnected": 2,
    "answered": 18,
    "pending": 7
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | Whether the request succeeded |
| `students` | array | List of students with their connection and answer status |
| `stats` | object | Aggregate statistics for the session |

**Student Object Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `userid` | int | Moodle user ID |
| `fullname` | string | Student's full name |
| `status` | string | Connection status: `'connected'` or `'disconnected'` |
| `hasanswered` | bool | Whether student has answered the current question |
| `lastheartbeat` | int | Unix timestamp of last heartbeat |
| `transport` | string | Connection transport: `'sse'` or `'polling'` |

**Stats Object Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `connected` | int | Number of currently connected students |
| `disconnected` | int | Number of disconnected students |
| `answered` | int | Number of students who answered the current question |
| `pending` | int | Number of connected students who haven't answered yet |

**Error Response:**
```json
{
  "success": false,
  "error": "Error message"
}
```

**Usage Example (JavaScript):**
```javascript
$.ajax({
    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
    method: 'POST',
    data: {
        action: 'getstudents',
        sessionid: 12,
        sesskey: M.cfg.sesskey
    },
    dataType: 'json',
    success: function(response) {
        if (response.success) {
            // Update student list
            response.students.forEach(function(student) {
                console.log(student.fullname + ': ' + student.status +
                    (student.hasanswered ? ' (answered)' : ' (pending)'));
            });
            
            // Update aggregate stats
            console.log('Connected:', response.stats.connected);
            console.log('Answered:', response.stats.answered);
        }
    }
});
```

**Implementation Details:**
- Uses `session_state_manager` to retrieve connected students
- Enriches student data with full names from user table
- Returns aggregate statistics from session state manager
- Designed for 2-second polling interval (Requirement 1.3)

**Performance:**
- Optimized for frequent polling during live sessions
- Uses cached connection data from heartbeat manager
- Minimal database queries via session state manager

**Security:**
- CSRF protection via `require_sesskey()`
- Capability check: `mod/classengage:viewanalytics`
- Student names only visible to instructors

**Requirements Implemented:** 1.3, 5.1, 5.4, 5.5

**Added:** Version 2025120600

---

#### `ajax.php?action=heartbeat`

Sends a heartbeat to maintain connection status and receive session updates.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'heartbeat'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "connected",
    "session_status": "active",
    "current_question": 3,
    "timer_remaining": 22
  }
}
```

---

#### `ajax.php?action=reconnect`

Restores client state after a disconnection, including current question and timer.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'reconnect'`
- `sessionid` (int, required) - Session ID
- `sesskey` (string, required) - Moodle session key

**Response:**
```json
{
  "success": true,
  "data": {
    "session_status": "active",
    "current_question": 3,
    "question_data": { ... },
    "timer_remaining": 18,
    "already_answered": false
  }
}
```

---

#### `ajax.php?action=submitbatch`

Submits multiple cached responses in a single request (for offline sync).

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'submitbatch'`
- `sessionid` (int, required) - Session ID
- `responses` (array, required) - Array of response objects
- `sesskey` (string, required) - Moodle session key

**Response:**
```json
{
  "success": true,
  "data": {
    "processed": 3,
    "accepted": 2,
    "late": 1,
    "rejected": 0
  }
}
```

---

## Server-Sent Events Endpoint (sse_handler.php)

### `sse_handler.php`

Real-time streaming endpoint for server-to-client push notifications. Provides continuous updates for session state changes, question broadcasts, and connection status during live quiz sessions. Supports both instructor and student roles with appropriate data filtering.

**HTTP Method:** GET

**Content-Type:** `text/event-stream`

**Parameters:**
- `sessionid` (int, required) - Session ID to subscribe to
- `lastupdate` (int, optional) - Timestamp of last received update (for reconnection)
- `sesskey` (string, required) - Moodle session key for CSRF protection

**Authentication:**
- Requires valid Moodle session
- Requires either `mod/classengage:startquiz` (instructor) or `mod/classengage:takequiz` (student) capability
- User must be logged into the course

**Configuration Constants:**

| Constant | Value | Description |
|----------|-------|-------------|
| `SSE_MAX_DURATION_SECONDS` | 300 | Maximum connection duration (5 minutes) |
| `SSE_HEARTBEAT_UPDATE_INTERVAL` | 10 | Iterations between heartbeat DB updates |
| `SSE_STALE_CHECK_INTERVAL` | 5 | Iterations between stale connection checks |
| `SSE_POLL_INTERVAL_SECONDS` | 1 | Sleep interval between iterations |

**Connection Behavior:**
- Maintains persistent connection for up to 5 minutes (300 iterations at 1-second intervals)
- Sends heartbeat comments every second to keep connection alive
- Updates student heartbeat in database every 10 iterations
- Checks for stale connections every 5 iterations (instructor only)
- Automatically closes when session ends or timeout is reached
- Students are automatically registered as connected when subscribing
- Graceful error handling with error events sent to clients on exceptions

**Event Types:**

| Event | Description | Recipient |
|-------|-------------|-----------|
| `connected` | Initial connection confirmation | Both |
| `state` | Session state update (status, timer, connections) | Both |
| `question` | New question broadcast with options | Students |
| `session_ended` | Session has completed | Both |
| `close` | Connection closing (timeout or session end) | Both |
| `error` | Internal error occurred | Both |

**Event: `connected`**
```json
{
  "sessionid": 123,
  "userid": 456,
  "role": "student",
  "timestamp": 1701705600
}
```

**Event: `state`**

Base fields (sent to all clients):
```json
{
  "sessionid": 123,
  "status": "active",
  "currentquestion": 2,
  "totalquestions": 10,
  "timelimit": 30,
  "timeremaining": 22,
  "elapsed": 8,
  "is_paused": false,
  "timestamp": 1701705600
}
```

Additional fields for instructors:
```json
{
  "sessionid": 123,
  "status": "active",
  "currentquestion": 2,
  "totalquestions": 10,
  "timelimit": 30,
  "timeremaining": 22,
  "elapsed": 8,
  "is_paused": false,
  "timestamp": 1701705600,
  "connections": {
    "total": 25,
    "connected": 23,
    "disconnected": 2,
    "answered": 18,
    "pending": 5
  },
  "students": [...],
  "participants": 25,
  "responses": 18,
  "participationrate": 72.0,
  "distribution": {
    "A": 5,
    "B": 8,
    "C": 3,
    "D": 2,
    "total": 18,
    "correctanswer": "B"
  }
}
```

| Field | Type | Description | Recipient |
|-------|------|-------------|-----------|
| `sessionid` | int | Session ID | Both |
| `status` | string | Session status: 'active', 'paused', 'completed' | Both |
| `currentquestion` | int | Current question index (0-based) | Both |
| `totalquestions` | int | Total number of questions | Both |
| `timelimit` | int | Time limit per question in seconds | Both |
| `timeremaining` | int | Remaining time in seconds | Both |
| `elapsed` | int | Elapsed time in seconds | Both |
| `is_paused` | bool | Whether session is paused | Both |
| `timestamp` | int | Server timestamp | Both |
| `connections` | object | Connection statistics | Instructor |
| `students` | array | List of connected students with status | Instructor |
| `participants` | int | Total unique participants in session | Instructor |
| `responses` | int | Response count for current question | Instructor |
| `participationrate` | float | Percentage of enrolled students who responded | Instructor |
| `distribution` | object | Response distribution for current question | Instructor |

Note: The `distribution` object contains counts for each answer option (A, B, C, D), total responses, and the correct answer.

**Event: `question`**
```json
{
  "questionid": 789,
  "text": "What is the capital of France?",
  "options": [
    {"key": "A", "text": "London"},
    {"key": "B", "text": "Paris"},
    {"key": "C", "text": "Berlin"},
    {"key": "D", "text": "Madrid"}
  ],
  "number": 3,
  "total": 10,
  "timelimit": 30,
  "timeremaining": 30,
  "hasanswered": false,
  "timestamp": 1701705600
}
```

**Event: `session_ended`**
```json
{
  "sessionid": 123,
  "timestamp": 1701705600
}
```

**Event: `close`**
```json
{
  "reason": "timeout",
  "timestamp": 1701705600
}
```

Possible `reason` values:
- `timeout` - Connection reached maximum duration (5 minutes)
- `session_ended` - Quiz session completed

**Event: `error`**
```json
{
  "message": "An internal error occurred",
  "code": 500,
  "timestamp": 1701705600
}
```

Sent when an unexpected exception occurs during SSE processing. The connection will close after this event.

**Usage Example (JavaScript):**
```javascript
const sessionId = 123;
const sesskey = M.cfg.sesskey;
const url = `${M.cfg.wwwroot}/mod/classengage/sse_handler.php?sessionid=${sessionId}&sesskey=${sesskey}`;

const eventSource = new EventSource(url);

eventSource.addEventListener('connected', (e) => {
    const data = JSON.parse(e.data);
    console.log('Connected as:', data.role);
});

eventSource.addEventListener('state', (e) => {
    const state = JSON.parse(e.data);
    console.log('Session status:', state.status);
    console.log('Timer remaining:', state.timeremaining);
    console.log('Elapsed:', state.elapsed);
});

eventSource.addEventListener('question', (e) => {
    const question = JSON.parse(e.data);
    console.log('New question:', question.text);
    displayQuestion(question);
});

eventSource.addEventListener('session_ended', (e) => {
    console.log('Session has ended');
    eventSource.close();
});

eventSource.addEventListener('error', (e) => {
    const error = JSON.parse(e.data);
    console.error('Server error:', error.message, 'Code:', error.code);
    eventSource.close();
});

eventSource.onerror = (e) => {
    console.error('SSE connection error');
    // Implement reconnection logic
};
```

**Performance:**
- State changes detected and broadcast within 1 second
- Target broadcast latency: <500ms (Requirements 1.1, 1.2)
- Minimal server load with 1-second polling interval
- Heartbeat comments prevent connection timeout

**Server Configuration:**
- Output buffering disabled for streaming
- nginx buffering disabled via `X-Accel-Buffering: no` header
- No time limit set for long-running connections

**Requirements Implemented:** 1.1, 1.2, 6.4

**Added:** Version 2025110400

---

## Response Capture Engine (classes/response_capture_engine.php)

The `response_capture_engine` class handles receiving, validating, and acknowledging student responses with support for batch processing under high-load scenarios. This is the core component for real-time quiz response handling.

**Namespace:** `mod_classengage`

**Requirements Implemented:** 2.1, 2.2, 2.3, 3.1, 3.5, 4.3, 7.2

### Data Classes

#### response_result

Result object returned by `submit_response()`.

| Property | Type | Description |
|----------|------|-------------|
| `success` | bool | Whether the submission was successful |
| `error` | string\|null | Error message if submission failed |
| `iscorrect` | bool\|null | Whether the answer was correct |
| `correctanswer` | string\|null | The correct answer (revealed after submission) |
| `responseid` | int\|null | Response ID if successfully stored |
| `islate` | bool | Whether this was a late submission |
| `latencyms` | int | Processing latency in milliseconds |

#### batch_result

Result object returned by `submit_batch()`.

| Property | Type | Description |
|----------|------|-------------|
| `success` | bool | Whether the batch was processed successfully |
| `processedcount` | int | Number of responses successfully processed |
| `failedcount` | int | Number of responses that failed |
| `results` | array | Individual `response_result` for each response |
| `error` | string\|null | Error message if batch processing failed |

#### validation_result

Result object returned by `validate_response()`.

| Property | Type | Description |
|----------|------|-------------|
| `valid` | bool | Whether the answer format is valid |
| `error` | string\|null | Error message if validation failed |

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `MAX_BATCH_SIZE` | 100 | Maximum responses per batch operation |
| `LATE_SUBMISSION_GRACE_PERIOD` | 5 | Grace period in seconds for late submissions |

---

### submit_response()

Submits a single student response with full validation and acknowledgment.

**Signature:**
```php
public function submit_response(
    int $sessionid,
    int $questionid,
    string $answer,
    int $userid,
    ?int $clienttimestamp = null
): response_result
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$questionid` (int) - Question ID
- `$answer` (string) - The submitted answer
- `$userid` (int) - User ID
- `$clienttimestamp` (int|null) - Optional client-side timestamp for late detection

**Returns:** `response_result` object (see Data Classes above)

**Error Messages:**
- `'Session not found'` - Session ID does not exist
- `'Session not active'` - Session is not in active status
- `'Question not found'` - Question ID does not exist
- `'Answer cannot be empty'` - Empty answer submitted
- `'Invalid answer format: must be A, B, C, or D'` - Invalid multichoice answer
- `'Invalid answer format: must be TRUE, FALSE, T, F, 1, or 0'` - Invalid truefalse answer
- `'Answer exceeds maximum length of 255 characters'` - Short answer too long
- `'Duplicate submission: already answered this question'` - User already answered
- `'Internal error: ...'` - Unexpected server error

**Usage Example:**
```php
$engine = new \mod_classengage\response_capture_engine();
$result = $engine->submit_response(
    sessionid: 123,
    questionid: 456,
    answer: 'B',
    userid: 789,
    clienttimestamp: time()
);

if ($result->success) {
    echo "Response recorded (ID: {$result->responseid})";
    echo $result->iscorrect ? "Correct!" : "Incorrect";
    echo "Latency: {$result->latencyms}ms";
} else {
    echo "Error: {$result->error}";
}
```

**Performance:** Designed to acknowledge responses within 1 second (NFR-01 compliance).

---

### submit_batch()

Submits multiple responses in a single transaction for high-load scenarios.

**Signature:**
```php
public function submit_batch(array $responses): batch_result
```

**Parameters:**
- `$responses` (array) - Array of response data, each containing:
  - `sessionid` (int, required)
  - `questionid` (int, required)
  - `answer` (string, required)
  - `userid` (int, required)
  - `clienttimestamp` (int, optional)

**Returns:** `batch_result` object (see Data Classes above)

**Usage Example:**
```php
$engine = new \mod_classengage\response_capture_engine();
$responses = [
    ['sessionid' => 123, 'questionid' => 456, 'answer' => 'A', 'userid' => 101],
    ['sessionid' => 123, 'questionid' => 456, 'answer' => 'B', 'userid' => 102],
    ['sessionid' => 123, 'questionid' => 456, 'answer' => 'C', 'userid' => 103],
];

$result = $engine->submit_batch($responses);
echo "Processed: {$result->processedcount}, Failed: {$result->failedcount}";
```

**Notes:**
- Uses database transaction for atomicity
- Returns error if batch exceeds MAX_BATCH_SIZE (100) responses
- Ideal for syncing cached offline responses
- Pre-fetches sessions and questions to minimize database queries

---

### validate_response()

Validates answer format based on question type.

**Signature:**
```php
public function validate_response(string $answer, string $questiontype): validation_result
```

**Parameters:**
- `$answer` (string) - The submitted answer
- `$questiontype` (string) - Question type: `multichoice`, `truefalse`, `shortanswer`

**Returns:** `validation_result` object (see Data Classes above)

**Validation Rules:**

| Question Type | Valid Formats |
|---------------|---------------|
| `multichoice` | A, B, C, D (case-insensitive) |
| `truefalse` | TRUE, FALSE, T, F, 1, 0 (case-insensitive) |
| `shortanswer` | Any string up to 255 characters |

**Usage Example:**
```php
$engine = new \mod_classengage\response_capture_engine();
$validation = $engine->validate_response('B', 'multichoice');

if ($validation->valid) {
    // Proceed with submission
} else {
    echo "Invalid: {$validation->error}";
}
```

---

### is_duplicate()

Checks if a user has already submitted a response for a question in a session.

**Signature:**
```php
public function is_duplicate(int $sessionid, int $questionid, int $userid): bool
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$questionid` (int) - Question ID
- `$userid` (int) - User ID

**Returns:** `bool` - True if duplicate exists

**Usage Example:**
```php
$engine = new \mod_classengage\response_capture_engine();
if ($engine->is_duplicate(123, 456, 789)) {
    echo "You have already answered this question";
}
```

---

### queue_response()

Queues a response for later batch processing (high-load scenario).

**Signature:**
```php
public function queue_response(
    int $sessionid,
    int $questionid,
    string $answer,
    int $userid,
    ?int $clienttimestamp = null
): int
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$questionid` (int) - Question ID
- `$answer` (string) - The submitted answer
- `$userid` (int) - User ID
- `$clienttimestamp` (int|null) - Client-side timestamp

**Returns:** `int` - Queue entry ID

**Notes:**
- Responses are stored in `classengage_response_queue` table
- Use `process_queue()` to process queued responses
- Automatically marks late submissions

---

### process_queue()

Processes queued responses (called by scheduled task or cron).

**Signature:**
```php
public function process_queue(int $limit = 100): batch_result
```

**Parameters:**
- `$limit` (int) - Maximum responses to process (default: 100)

**Returns:** `batch_result` object (see Data Classes above)

**Usage Example:**
```php
// In a scheduled task
$engine = new \mod_classengage\response_capture_engine();
$result = $engine->process_queue(50);
mtrace("Processed {$result->processedcount} queued responses");
```

---

## Core Library Functions (lib.php)

### Navigation

#### `classengage_render_tabs($cmid, $activetab = null)`

Renders consistent tab navigation across all ClassEngage pages using Moodle's standard `print_tabs()` function.

**Parameters:**
- `$cmid` (int) - Course module ID
- `$activetab` (string|null) - Active tab identifier or null for no active tab
  - Valid values: `'slides'`, `'questions'`, `'sessions'`, `'analytics'`, `null`

**Returns:** void (outputs HTML directly)

**Usage Examples:**

```php
// In slides.php - highlight the slides tab
classengage_render_tabs($cm->id, 'slides');

// In questions.php - highlight the questions tab
classengage_render_tabs($cm->id, 'questions');

// In sessions.php - highlight the sessions tab
classengage_render_tabs($cm->id, 'sessions');

// In analytics.php - highlight the analytics tab
classengage_render_tabs($cm->id, 'analytics');

// In controlpanel.php - no active tab (dedicated monitoring page)
classengage_render_tabs($cm->id, null);
```

**Generated Tabs:**

| Tab | Label | URL |
|-----|-------|-----|
| slides | Upload Slides | `/mod/classengage/slides.php?id={cmid}` |
| questions | Manage Questions | `/mod/classengage/questions.php?id={cmid}` |
| sessions | Quiz Sessions | `/mod/classengage/sessions.php?id={cmid}` |
| analytics | Analytics | `/mod/classengage/analytics.php?id={cmid}` |

**Implementation Details:**

- Uses Moodle's `tabobject` class for tab definition
- Uses Moodle's `print_tabs()` function for rendering
- Consistent with Moodle's standard tab navigation pattern
- Language strings retrieved via `get_string()`
- URLs constructed with `moodle_url` for proper parameter handling

**Added:** Version 2025110305

---

## Activity Module Functions

### Instance Management

#### `classengage_add_instance($classengage, $mform = null)`

Creates a new ClassEngage activity instance.

**Parameters:**
- `$classengage` (stdClass) - Activity data from mod_form.php
- `$mform` (mod_classengage_mod_form|null) - Form instance (optional)

**Returns:** int - ID of newly created instance

**Side Effects:**
- Creates database record in `classengage` table
- Creates gradebook item via `classengage_grade_item_update()`

---

#### `classengage_update_instance($classengage, $mform = null)`

Updates an existing ClassEngage activity instance.

**Parameters:**
- `$classengage` (stdClass) - Updated activity data
- `$mform` (mod_classengage_mod_form|null) - Form instance (optional)

**Returns:** bool - Success status

**Side Effects:**
- Updates database record
- Updates gradebook item

---

#### `classengage_delete_instance($id)`

Deletes a ClassEngage activity instance and all related data.

**Parameters:**
- `$id` (int) - Activity instance ID

**Returns:** bool - Success status

**Side Effects:**
- Deletes all slides, questions, sessions, and responses
- Deletes gradebook item
- Cascading deletion of dependent records

---

### Grading Functions

#### `classengage_grade_item_update($classengage, $grades = null)`

Creates or updates the gradebook item for a ClassEngage activity.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$grades` (mixed|null) - Grade data or 'reset' to reset grades

**Returns:** int - 0 if successful, error code otherwise

---

#### `classengage_grade_item_delete($classengage)`

Deletes the gradebook item for a ClassEngage activity.

**Parameters:**
- `$classengage` (stdClass) - Activity instance

**Returns:** int - 0 if successful, error code otherwise

---

#### `classengage_update_grades($classengage, $userid = 0, $nullifnone = true)`

Updates grades in the gradebook for one or all users.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$userid` (int) - Specific user ID, or 0 for all users
- `$nullifnone` (bool) - Return null if no grade exists

**Returns:** void

---

#### `classengage_get_user_grades($classengage, $userid = 0)`

Retrieves grades for one or all users.

**Parameters:**
- `$classengage` (stdClass) - Activity instance
- `$userid` (int) - Specific user ID, or 0 for all users

**Returns:** array - Array of grade objects with userid and rawgrade

**Grade Calculation:**
- Uses maximum score across all responses
- Score = (correct responses / total responses) × 100

---

### Feature Support

#### `classengage_supports($feature)`

Declares which Moodle features this module supports.

**Parameters:**
- `$feature` (string) - FEATURE_xx constant

**Returns:** mixed - true/false/null

**Supported Features:**
- `FEATURE_MOD_INTRO` - Activity description
- `FEATURE_BACKUP_MOODLE2` - Backup/restore
- `FEATURE_SHOW_DESCRIPTION` - Show description on course page
- `FEATURE_GRADE_HAS_GRADE` - Gradebook integration
- `FEATURE_GROUPS` - Group support

---

---

## Session State Manager (classes/session_state_manager.php)

The `session_state_manager` class handles session lifecycle management, connection tracking, and state synchronization for real-time quiz sessions. This is the core component for managing live quiz session state.

**Namespace:** `mod_classengage`

**Requirements Implemented:** 1.1, 1.2, 1.4, 1.5, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, 7.1

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `CONNECTION_TIMEOUT` | 10 | Seconds before a connection is considered stale |
| `BROADCAST_LATENCY_TARGET` | 500 | Target broadcast latency in milliseconds |
| `CONNECTION_STATUS_CONNECTED` | 'connected' | Client is actively connected |
| `CONNECTION_STATUS_DISCONNECTED` | 'disconnected' | Client has disconnected |
| `CONNECTION_STATUS_ANSWERED` | 'answered' | Client has answered the current question |
| `CLEANUP_MAX_AGE_SECONDS` | 86400 | Default max age for connection cleanup (24 hours) |

---

### start_session()

Starts a quiz session and broadcasts to all connected clients.

**Signature:**
```php
public function start_session(int $sessionid): session_state
```

**Parameters:**
- `$sessionid` (int) - Session ID to start

**Returns:** `session_state` object with:
- `success` (bool) - Whether session started successfully
- `sessionid` (int) - Session ID
- `status` (string) - New session status ('active')
- `currentquestion` (int) - Current question index (0)
- `questionstarttime` (int) - Timestamp when question started
- `timelimit` (int) - Time limit in seconds
- `timer_remaining` (int) - Remaining time in seconds
- `broadcast_latency` (int) - Broadcast latency in milliseconds
- `error` (string) - Error code (on failure)
- `message` (string) - Error message (on failure)

**Behavior:**
- Transitions session to 'active' status
- Stops any other active sessions for the same activity
- Resets timer and pause state
- Logs session start event
- Target: Notify all clients within 500ms (Requirement 1.1)

**Usage Example:**
```php
$manager = new \mod_classengage\session_state_manager();
$result = $manager->start_session(123);

if ($result->success) {
    echo "Session started, latency: {$result->broadcast_latency}ms";
}
```

---

### pause_session()

Pauses an active session, freezing the timer and blocking submissions.

**Signature:**
```php
public function pause_session(int $sessionid): session_state
```

**Parameters:**
- `$sessionid` (int) - Session ID to pause

**Returns:** `session_state` object with:
- `success` (bool) - Whether pause succeeded
- `sessionid` (int) - Session ID
- `status` (string) - New session status ('paused')
- `timer_remaining` (int) - Frozen timer value in seconds
- `paused_at` (int) - Timestamp when paused
- `error` (string) - Error code (on failure): `session_not_active`

**Behavior:**
- Only active sessions can be paused
- Calculates and stores remaining timer value
- Logs pause event with timer state

**Usage Example:**
```php
$manager = new \mod_classengage\session_state_manager();
$result = $manager->pause_session(123);

if ($result->success) {
    echo "Paused with {$result->timer_remaining}s remaining";
}
```

---

### resume_session()

Resumes a paused session, restoring the timer and re-enabling submissions.

**Signature:**
```php
public function resume_session(int $sessionid): session_state
```

**Parameters:**
- `$sessionid` (int) - Session ID to resume

**Returns:** `session_state` object with:
- `success` (bool) - Whether resume succeeded
- `sessionid` (int) - Session ID
- `status` (string) - New session status ('active')
- `timer_remaining` (int) - Restored timer value
- `pause_duration` (int) - Total pause duration in seconds
- `error` (string) - Error code (on failure): `session_not_paused`

**Behavior:**
- Only paused sessions can be resumed
- Accumulates total pause duration for accurate timing
- Logs resume event

**Usage Example:**
```php
$manager = new \mod_classengage\session_state_manager();
$result = $manager->resume_session(123);

if ($result->success) {
    echo "Resumed, total pause time: {$result->pause_duration}s";
}
```

---

### next_question()

Advances to the next question and broadcasts to all clients.

**Signature:**
```php
public function next_question(int $sessionid): question_broadcast
```

**Parameters:**
- `$sessionid` (int) - Session ID

**Returns:** `question_broadcast` object with:
- `success` (bool) - Whether advance succeeded
- `sessionid` (int) - Session ID
- `session_completed` (bool) - True if this was the last question
- `status` (string) - Session status
- `currentquestion` (int) - New question index
- `questionstarttime` (int) - Timestamp when question started
- `timelimit` (int) - Time limit in seconds
- `question` (stdClass|null) - Question data object
- `broadcast_latency` (int) - Broadcast latency in milliseconds
- `error` (string) - Error code (on failure): `session_not_active`

**Behavior:**
- Only active sessions can advance questions
- Resets answered status for all connections
- Completes session if no more questions
- Target: Broadcast within 500ms (Requirement 1.2)

**Usage Example:**
```php
$manager = new \mod_classengage\session_state_manager();
$result = $manager->next_question(123);

if ($result->success && !$result->session_completed) {
    echo "Question {$result->currentquestion}: {$result->question->questiontext}";
}
```

---

### register_connection()

Registers a client connection for tracking.

**Signature:**
```php
public function register_connection(
    int $sessionid,
    int $userid,
    string $connectionid,
    string $transport = 'polling'
): void
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$userid` (int) - User ID
- `$connectionid` (string) - Unique connection identifier
- `$transport` (string) - Transport type: 'websocket', 'polling', 'sse'

**Behavior:**
- Creates or updates connection record
- Sets status to 'connected'
- Updates heartbeat timestamp
- Logs connection event

---

### handle_disconnect()

Handles client disconnection.

**Signature:**
```php
public function handle_disconnect(string $connectionid): void
```

**Parameters:**
- `$connectionid` (string) - Connection identifier

**Behavior:**
- Updates connection status to 'disconnected'
- Logs disconnection event
- Target: Update within 5 seconds (Requirement 5.2)

---

### get_connected_students()

Gets list of connected students with status and aggregate statistics.

**Signature:**
```php
public function get_connected_students(int $sessionid): array
```

**Parameters:**
- `$sessionid` (int) - Session ID

**Returns:** Array with:
- `students` (array) - Array of student data:
  - `userid`, `firstname`, `lastname`, `fullname`, `email`
  - `connectionid`, `transport`, `status`
  - `answered` (bool), `last_heartbeat`, `time_since_heartbeat`
- `stats` (array) - Aggregate statistics:
  - `total`, `connected`, `disconnected`, `answered`, `pending`

**Behavior:**
- Marks stale connections as disconnected before returning
- Joins with user table for display names

**Usage Example:**
```php
$manager = new \mod_classengage\session_state_manager();
$data = $manager->get_connected_students(123);

echo "Connected: {$data['stats']['connected']}/{$data['stats']['total']}";
echo "Answered: {$data['stats']['answered']}";
```

---

### get_client_state()

Gets session state for a reconnecting client.

**Signature:**
```php
public function get_client_state(int $sessionid, int $userid): client_session_state
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$userid` (int) - User ID

**Returns:** `client_session_state` object with:
- `success` (bool) - Whether state retrieval succeeded
- `sessionid` (int) - Session ID
- `status` (string) - Session status
- `currentquestion` (int) - Current question index
- `totalquestions` (int) - Total questions in session
- `questionstarttime` (int) - When current question started
- `timelimit` (int) - Time limit in seconds
- `timer_remaining` (int) - Remaining time in seconds
- `is_paused` (bool) - Whether session is paused
- `question` (stdClass|null) - Current question data
- `has_answered` (bool) - Whether user already answered
- `connection_status` (string|null) - User's connection status
- `error` (string) - Error code (on failure): `session_not_found`, `get_state_failed`

**Behavior:**
- Uses cache for session data
- Calculates accurate timer remaining
- Checks if user has already answered current question
- Supports session state restoration (Requirement 4.4)

---

### mark_question_answered()

Updates connection status when user answers a question.

**Signature:**
```php
public function mark_question_answered(int $sessionid, int $userid): void
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$userid` (int) - User ID

**Behavior:**
- Sets `current_question_answered` flag on connection
- Invalidates connection cache

---

### get_session_statistics()

Gets comprehensive session statistics for instructor panel.

**Signature:**
```php
public function get_session_statistics(int $sessionid): array
```

**Parameters:**
- `$sessionid` (int) - Session ID

**Returns:** Array with:
- `sessionid` (int) - Session ID
- `status` (string) - Session status
- `currentquestion` (int) - Current question index
- `totalquestions` (int) - Total questions
- `timelimit` (int) - Time limit in seconds
- `timer_remaining` (int) - Remaining time
- `is_paused` (bool) - Whether session is paused
- `connections` (array) - Connection statistics
- `response_distribution` (array) - Answer distribution for current question

---

### update_heartbeat()

Updates heartbeat timestamp for a connection.

**Signature:**
```php
public function update_heartbeat(int $sessionid, int $userid): bool
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$userid` (int) - User ID

**Returns:** `bool` - True if connection was updated

**Behavior:**
- Updates `last_heartbeat` timestamp
- Restores 'connected' status if previously disconnected

---

### cleanup_old_connections()

Cleans up old connections for completed sessions.

**Signature:**
```php
public function cleanup_old_connections(int $maxage = 86400): int
```

**Parameters:**
- `$maxage` (int) - Maximum age in seconds (default: 24 hours)

**Returns:** `int` - Number of connections deleted

**Usage:** Called by scheduled task to clean up stale data.

---

## Heartbeat Manager (classes/heartbeat_manager.php)

The `heartbeat_manager` class handles heartbeat processing, stale connection detection, and connection statistics for the real-time quiz engine. This component is responsible for maintaining connection health and providing monitoring metrics.

**Namespace:** `mod_classengage`

**Requirements Implemented:** 5.2, 5.3, 7.5

### Constructor

```php
public function __construct()
```

Initializes the heartbeat manager with the `connection_status` cache.

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();
```

---

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `HEARTBEAT_TIMEOUT` | 10 | Seconds without heartbeat before connection is stale |
| `RECONNECT_WINDOW` | 2 | Reconnection detection window in seconds |

---

### process_heartbeat()

Processes a heartbeat from a client, updating connection status and timestamp.

**Signature:**
```php
public function process_heartbeat(
    int $sessionid,
    int $userid,
    string $connectionid
): heartbeat_response
```

**Parameters:**
- `$sessionid` (int) - Session ID
- `$userid` (int) - User ID
- `$connectionid` (string) - Unique connection identifier

**Returns:** `heartbeat_response` object with:
- `success` (bool) - Whether heartbeat was processed successfully
- `error` (string|null) - Error message if processing failed
- `servertimestamp` (int) - Current server timestamp
- `status` (string) - Connection status ('connected' or 'disconnected')

**Behavior:**
- Validates connection exists and matches session/user
- Updates `last_heartbeat` timestamp for existing connections
- Restores 'connected' status if previously disconnected or stale
- Logs reconnection events when recovering from disconnected/stale state
- Updates connection cache for fast lookups
- Target: Status updates within 2 seconds for reconnects (Requirement 5.3)

**Error Messages:**
- `'Connection not found'` - Connection ID does not exist
- `'Connection mismatch'` - Session or user ID doesn't match connection record

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();
$result = $manager->process_heartbeat(123, 456, 'conn_abc123');

if ($result->success) {
    echo "Status: {$result->status}";
    echo "Server time: {$result->servertimestamp}";
} else {
    echo "Error: {$result->error}";
}
```

---

### check_stale_connections()

Identifies and marks stale connections as disconnected.

**Signature:**
```php
public function check_stale_connections(int $sessionid): array
```

**Parameters:**
- `$sessionid` (int) - Session ID

**Returns:** `array` - List of user IDs that were marked as disconnected

**Behavior:**
- Finds connections with `last_heartbeat` older than `HEARTBEAT_TIMEOUT` (10 seconds)
- Only processes connections currently marked as 'connected'
- Updates status to 'disconnected'
- Removes stale connections from cache
- Logs disconnection events with timeout details
- Target: Update status within 5 seconds (Requirement 5.2)

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();
$disconnected = $manager->check_stale_connections(123);

if (!empty($disconnected)) {
    echo "Disconnected users: " . implode(', ', $disconnected);
    // Notify instructor of disconnections
}
```

---

### get_connection_stats()

Gets comprehensive connection and performance statistics for a session.

**Signature:**
```php
public function get_connection_stats(int $sessionid): connection_stats
```

**Parameters:**
- `$sessionid` (int) - Session ID

**Returns:** `connection_stats` object with:
- `totalconnections` (int) - Total connection records for session
- `activeconnections` (int) - Currently connected and not stale
- `disconnectedconnections` (int) - Explicitly disconnected connections
- `staleconnections` (int) - Connected but heartbeat timed out
- `averagelatency` (float) - Average response latency in milliseconds (from last 5 minutes)
- `timestamp` (int) - Timestamp of statistics calculation

**Behavior:**
- Counts total connections for the session
- Identifies active connections (connected status AND recent heartbeat)
- Counts explicitly disconnected connections
- Counts stale connections (connected status but heartbeat timeout exceeded)
- Calculates average latency from recent session logs (last 5 minutes)
- Supports Requirement 7.5 (session statistics API)

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();
$stats = $manager->get_connection_stats(123);

echo "Total: {$stats->totalconnections}";
echo "Active: {$stats->activeconnections}";
echo "Disconnected: {$stats->disconnectedconnections}";
echo "Stale: {$stats->staleconnections}";
echo "Avg latency: {$stats->averagelatency}ms";
```

---

### is_connection_stale()

Checks if a specific connection is stale (heartbeat timeout exceeded).

**Signature:**
```php
public function is_connection_stale(string $connectionid): bool
```

**Parameters:**
- `$connectionid` (string) - Connection identifier

**Returns:** `bool` - True if connection is stale or doesn't exist

**Behavior:**
- Returns true if connection record doesn't exist
- Returns true if `last_heartbeat` is older than `HEARTBEAT_TIMEOUT`
- Returns false if connection is healthy

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();

if ($manager->is_connection_stale('conn_abc123')) {
    echo "Connection needs to reconnect";
}
```

---

### get_heartbeat_timeout()

Gets the heartbeat timeout value.

**Signature:**
```php
public function get_heartbeat_timeout(): int
```

**Returns:** `int` - Timeout in seconds (10)

**Usage Example:**
```php
$manager = new \mod_classengage\heartbeat_manager();
$timeout = $manager->get_heartbeat_timeout();
echo "Connections timeout after {$timeout} seconds";
```

---

### Data Classes

#### heartbeat_response

Response object returned by `process_heartbeat()`.

| Property | Type | Description |
|----------|------|-------------|
| `success` | bool | Whether the heartbeat was processed successfully |
| `error` | string\|null | Error message if processing failed |
| `servertimestamp` | int | Server timestamp |
| `status` | string | Connection status: 'connected' or 'disconnected' |

#### connection_stats

Response object returned by `get_connection_stats()`.

| Property | Type | Description |
|----------|------|-------------|
| `totalconnections` | int | Total number of connections for the session |
| `activeconnections` | int | Number of active (connected and not stale) connections |
| `disconnectedconnections` | int | Number of explicitly disconnected connections |
| `staleconnections` | int | Number of stale connections (connected but heartbeat timed out) |
| `averagelatency` | float | Average latency in milliseconds (from last 5 minutes of logs) |
| `timestamp` | int | Timestamp when statistics were calculated |

---

## NLP Slide API Endpoints (slides_api.php)

### Overview

The `slides_api.php` endpoint handles slide inspection and NLP question generation. Question generation uses Moodle's **adhoc task system** for asynchronous processing.

### `slides_api.php?action=inspect`

Inspects a slide document and returns page content inventory.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'inspect'`
- `slideid` (int, required) - Slide ID to inspect
- `sesskey` (string, required) - Moodle session key

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "docId": "doc_abc123",
  "pages": [
    {
      "page": 1,
      "text": "Introduction to...",
      "images": [
        {
          "imageId": "img_1_1_abc123",
          "label": "Page 1 preview",
          "source": "PDF page 1"
        }
      ]
    }
  ]
}
```

**Usage Example:**
```javascript
$.ajax({
    url: M.cfg.wwwroot + '/mod/classengage/slides_api.php',
    method: 'POST',
    data: {
        action: 'inspect',
        slideid: 1,
        sesskey: M.cfg.sesskey
    },
    dataType: 'json',
    success: function(response) {
        if (response.success) {
            console.log('Doc ID:', response.docId);
            console.log('Pages:', response.pages.length);
        }
    }
});
```

---

### `slides_api.php?action=generate_from_options`

Generates questions from selected slides and images using configured AI provider.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'generate_from_options'`
- `slideid` (int, required) - Slide ID
- `docid` (string, required) - Document ID from inspect action
- `options` (JSON string, required) - Generation options:
  ```json
  {
    "numQuestions": 10,
    "difficulty": "medium",
    "bloomLevel": "apply",
    "includeSlides": [1, 2, 3],
    "includeImages": ["img_1_1_abc123"]
  }
  ```
- `sesskey` (string, required) - Moodle session key

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "status": "pending",
  "progress": 5,
  "message": "Question generation queued successfully."
}
```

**Error Response (already running):**
```json
{
  "success": false,
  "error": "Generation already in progress for this slide"
}
```

**How It Works:**
1. Request queues an adhoc task
2. Task executes via cron (every minute)
3. Questions stored in `classengage_questions` table
4. Poll `nlpstatus` to check progress

---

### `slides_api.php?action=nlpstatus`

Polls the status of an NLP generation job.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'nlpstatus'`
- `slideid` (int, required) - Slide ID to check
- `sesskey` (string, required) - Moodle session key

**Response Format:** JSON

**Pending Response:**
```json
{
  "success": true,
  "status": "running",
  "progress": 60
}
```

**Completed Response:**
```json
{
  "success": true,
  "status": "completed",
  "progress": 100,
  "count": 10,
  "provider": "gemini",
  "model": "gemini-1.5-flash",
  "duration": 45,
  "metadata": {
    "generated": 10,
    "expected": 10,
    "selectedSlides": [1, 2],
    "selectedImages": ["img_1_1_abc123"]
  }
}
```

**Failed Response:**
```json
{
  "success": true,
  "status": "failed",
  "progress": 60,
  "error": "API rate limit exceeded"
}
```

---

### `slides_api.php?action=resetjob`

Resets a failed job to allow retry.

**HTTP Method:** POST

**Parameters:**
- `action` (string, required) - Must be `'resetjob'`
- `slideid` (int, required) - Slide ID to reset
- `sesskey` (string, required) - Moodle session key

**Response Format:** JSON

**Success Response:**
```json
{
  "success": true,
  "status": "idle",
  "message": "Job reset successfully"
}
```

---

## NLP Question Generation Flow

```
1. Upload Slide
   ↓
2. Call inspect → Get docId and page inventory
   ↓
3. User selects slides/images and options
   ↓
4. Call generate_from_options
   → Queues adhoc task
   → Returns "pending" status
   ↓
5. Cron runs (every minute)
   → Executes adhoc task
   → Calls AI API (Gemini/OpenAI/etc)
   → Stores questions in DB
   → Updates slide status to "completed"
   ↓
6. Poll nlpstatus
   → When "completed", questions are ready
   ↓
7. Visit Manage Questions tab
   → Questions appear immediately
```

## NLP Diagnostics (nlp_diagnostics.php)

Visit `http://your-moodle/mod/classengage/nlp_diagnostics.php` to verify NLP prerequisites:

```
✓ PHP shell_exec() - Available
✓ pdftotext (Poppler) - Installed
✓ pdfinfo (Poppler) - Installed  
✓ Imagick PHP Extension - Installed
✓ ZIP PHP Extension - Installed
```

If any tool is missing, install via Docker (see README.md).

## Database Tables Reference

### Real-Time Session Tables

#### `classengage_connections`

Tracks active client connections for real-time status updates.

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Primary key |
| `sessionid` | int | FK to classengage_sessions |
| `userid` | int | FK to user table |
| `connectionid` | char(64) | Unique connection identifier |
| `transport` | char(20) | Transport type: websocket, polling, sse |
| `status` | char(20) | Connection status: connected, disconnected, answered |
| `last_heartbeat` | int | Timestamp of last heartbeat |
| `current_question_answered` | int(1) | Whether user answered current question |

---

#### `classengage_response_queue`

Temporary queue for batch processing responses under high load.

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Primary key |
| `sessionid` | int | FK to classengage_sessions |
| `questionid` | int | FK to classengage_questions |
| `userid` | int | FK to user table |
| `answer` | char(255) | The submitted answer |
| `client_timestamp` | int | Client-side submission timestamp |
| `server_timestamp` | int | Server-side receipt timestamp |
| `processed` | int(1) | Whether response has been processed |
| `is_late` | int(1) | Whether response was submitted after timer expired |

---

#### `classengage_session_log`

Comprehensive logging for diagnostics and analytics.

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Primary key |
| `sessionid` | int | FK to classengage_sessions |
| `userid` | int | FK to user table (null for system events) |
| `event_type` | char(50) | Event type: session_start, session_end, response, error, warning |
| `event_data` | text | JSON-encoded event data |
| `latency_ms` | int | Response latency in milliseconds |

---

## JavaScript AMD Modules

### Connection Manager (amd/src/connection_manager.js)

The `connection_manager` module provides a unified interface for real-time communication between the client and server during live quiz sessions. It implements automatic transport fallback (WebSocket → SSE → HTTP Polling) and handles connection resilience including automatic reconnection and offline detection.

**Module:** `mod_classengage/connection_manager`

**Dependencies:** None (uses native browser APIs)

**Implementation Notes:**
- Uses native `fetch` API for HTTP requests (no Moodle core/ajax dependency)
- Uses native `WebSocket` and `EventSource` APIs for real-time transports
- Self-contained module with no external AMD dependencies

**Requirements Implemented:** 6.1, 6.2, 6.3, 6.4, 6.5

---

### Constants

#### Transport Types

```javascript
var TRANSPORT = {
    WEBSOCKET: 'websocket',  // Full-duplex WebSocket connection
    SSE: 'sse',              // Server-Sent Events for server push
    POLLING: 'polling',      // HTTP polling fallback
    OFFLINE: 'offline'       // No network connectivity
};
```

#### Connection States

```javascript
var STATE = {
    DISCONNECTED: 'disconnected',  // Not connected
    CONNECTING: 'connecting',      // Connection in progress
    CONNECTED: 'connected',        // Actively connected
    RECONNECTING: 'reconnecting'   // Attempting to reconnect
};
```

---

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `wsEndpoint` | string | null | WebSocket endpoint URL |
| `sseEndpoint` | string | auto | SSE endpoint (defaults to sse_handler.php) |
| `pollEndpoint` | string | auto | Polling endpoint (defaults to ajax.php) |
| `pollInterval` | int | 2000 | Polling interval in ms (max 2s per Req 6.2) |
| `wsRetryAttempts` | int | 3 | WebSocket retry attempts before fallback (Req 6.3) |
| `heartbeatInterval` | int | 30000 | Heartbeat interval in ms |
| `reconnectDelay` | int | 1000 | Initial reconnect delay in ms |
| `maxReconnectDelay` | int | 30000 | Maximum reconnect delay in ms |
| `heartbeatTimeout` | int | 10000 | Heartbeat request timeout in ms |

---

### Module API

#### init(sessionId, options)

Initializes the connection manager and establishes a connection.

**Parameters:**
- `sessionId` (number) - Quiz session ID
- `options` (Object) - Configuration options (see above)

**Returns:** `Promise` - Resolves when connection is established

**Usage Example:**
```javascript
require(['mod_classengage/connection_manager'], function(ConnectionManager) {
    ConnectionManager.init(123, {
        pollInterval: 1500,
        heartbeatInterval: 25000
    }).then(function() {
        console.log('Connected!');
    }).catch(function(error) {
        console.error('Connection failed:', error);
    });
});
```

---

#### on(event, callback)

Registers an event handler.

**Parameters:**
- `event` (string) - Event name
- `callback` (Function) - Handler function

**Returns:** `Object` - Module instance for chaining

**Events:**

| Event | Data | Description |
|-------|------|-------------|
| `connected` | `{transport}` | Connection established |
| `disconnected` | `{transport, reason}` | Connection lost |
| `reconnecting` | `{attempt, delay}` | Reconnection attempt starting |
| `statechange` | Status object | Connection state changed |
| `online` | - | Network came online |
| `offline` | - | Network went offline |
| `state` | Session state | Session state update from server |
| `question` | Question data | New question broadcast |
| `session_ended` | `{sessionid}` | Quiz session completed |
| `heartbeat` | `{latency}` | Heartbeat acknowledged |
| `heartbeat_failed` | - | Heartbeat request failed |
| `state_restored` | Session state | State restored after reconnect |
| `server_error` | Error data | Server-side error occurred |

**Usage Example:**
```javascript
ConnectionManager
    .on('connected', function(data) {
        console.log('Connected via:', data.transport);
    })
    .on('question', function(question) {
        displayQuestion(question);
    })
    .on('offline', function() {
        showOfflineIndicator();
    });
```

---

#### submitResponse(questionId, answer)

Submits a single response to the server.

**Parameters:**
- `questionId` (number) - Question ID
- `answer` (string) - Answer value

**Returns:** `Promise` - Resolves with submission result

**Usage Example:**
```javascript
ConnectionManager.submitResponse(456, 'B')
    .then(function(result) {
        if (result.success) {
            showConfirmation(result.iscorrect);
        }
    });
```

---

#### submitBatch(responses)

Submits multiple cached responses in a single request.

**Parameters:**
- `responses` (Array) - Array of response objects with `questionid`, `answer`, `clienttimestamp`

**Returns:** `Promise` - Resolves with batch result

**Usage Example:**
```javascript
var cachedResponses = [
    {questionid: 1, answer: 'A', clienttimestamp: 1701705600},
    {questionid: 2, answer: 'C', clienttimestamp: 1701705630}
];

ConnectionManager.submitBatch(cachedResponses)
    .then(function(result) {
        console.log('Processed:', result.processed);
        console.log('Late:', result.late);
    });
```

---

#### getStatus()

Gets current connection status.

**Returns:** `Object` with:
- `connected` (boolean) - Whether currently connected
- `state` (string) - Current state (STATE constant)
- `transport` (string) - Current transport (TRANSPORT constant)
- `latency` (number) - Last measured latency in ms
- `lastHeartbeat` (number) - Timestamp of last heartbeat
- `connectionId` (string) - Unique connection identifier
- `isOnline` (boolean) - Network online status

**Usage Example:**
```javascript
var status = ConnectionManager.getStatus();
console.log('Connected:', status.connected);
console.log('Transport:', status.transport);
console.log('Latency:', status.latency + 'ms');
```

---

#### reconnect()

Forces an immediate reconnection attempt.

**Returns:** `Promise` - Resolves when reconnected

**Usage Example:**
```javascript
ConnectionManager.reconnect()
    .then(function() {
        console.log('Reconnected successfully');
    });
```

---

#### disconnect()

Gracefully disconnects from the server.

**Usage Example:**
```javascript
// On page unload or session end
ConnectionManager.disconnect();
```

---

### Transport Fallback Behavior

The connection manager implements automatic transport fallback per Requirements 6.1, 6.3:

1. **WebSocket** (if endpoint configured)
   - Attempts connection up to 3 times
   - Falls back to SSE after 3 failures

2. **Server-Sent Events (SSE)**
   - Used for server-to-client push
   - Falls back to polling if SSE fails

3. **HTTP Polling**
   - Final fallback, always available
   - Polls at maximum 2-second intervals (Requirement 6.2)

All transports provide identical functionality (Requirement 6.4) and maintain session state without data loss during transport changes (Requirement 6.5).

---

### Reconnection Strategy

When a connection is lost:

1. Automatic reconnection is scheduled with exponential backoff
2. Initial delay: 1 second
3. Maximum delay: 30 seconds
4. On successful reconnect, session state is restored from server (Requirement 4.4)

---

### Network Detection

The module monitors browser online/offline events:

- When offline: Sets transport to `OFFLINE`, emits `offline` event
- When online: Triggers reconnection, emits `online` event

---

### Integration Example

Complete integration with quiz interface:

```javascript
require(['mod_classengage/connection_manager'], function(CM) {
    // Initialize connection
    CM.init(sessionId)
        .then(function() {
            updateStatusIndicator('connected');
        });

    // Handle connection events
    CM.on('connected', function(data) {
        updateStatusIndicator('connected');
        hideOfflineWarning();
    });

    CM.on('disconnected', function() {
        updateStatusIndicator('disconnected');
    });

    CM.on('offline', function() {
        showOfflineWarning();
    });

    CM.on('reconnecting', function(data) {
        updateStatusIndicator('reconnecting');
        showReconnectMessage('Attempt ' + data.attempt);
    });

    // Handle quiz events
    CM.on('question', function(question) {
        displayQuestion(question);
        startTimer(question.timelimit);
    });

    CM.on('state', function(state) {
        updateTimer(state.timer_remaining);
        if (state.is_paused) {
            showPausedOverlay();
        }
    });

    CM.on('session_ended', function() {
        showSessionComplete();
        CM.disconnect();
    });

    // Submit answer
    function submitAnswer(answer) {
        CM.submitResponse(currentQuestionId, answer)
            .then(function(result) {
                if (result.success) {
                    showAnswerConfirmation();
                }
            })
            .catch(function(error) {
                // Cache for later if offline
                cacheResponse(currentQuestionId, answer);
            });
    }
});
```

**Added:** Version 2025110400

---

## See Also

- [Clicker API Documentation](../CLICKER_API_DOCUMENTATION.md)
- [Load Testing Guide](../tests/LOAD_TESTING.md)
- [Implementation Summary](../IMPLEMENTATION_SUMMARY.md)
- [README](../README.md)


---

## Control Panel Module (amd/src/controlpanel.js)

The `controlpanel` AMD module provides real-time instructor monitoring and session control for live quiz sessions. It integrates with the `connection_manager` module for SSE-based updates with automatic polling fallback.

**Module:** `mod_classengage/controlpanel`

**Requirements Implemented:** 1.3, 1.4, 1.5, 5.1, 5.4, 5.5

**Dependencies:**
- `jquery` - DOM manipulation and AJAX
- `core/notification` - Moodle notification system
- `mod_classengage/connection_manager` - Real-time connection handling

### Initialization

#### init(sessionId, interval)

Initializes the control panel with real-time updates.

**Parameters:**
- `sessionId` (number) - The quiz session ID to monitor
- `interval` (number, optional) - Polling interval in milliseconds (default: 1000)

**Usage Example:**
```javascript
require(['mod_classengage/controlpanel'], function(ControlPanel) {
    ControlPanel.init(123, 1000);
});
```

**Initialization Sequence:**
1. Establishes SSE connection via `connection_manager`
2. Starts statistics polling at specified interval
3. Starts student status polling at 2-second intervals (Requirement 1.3)
4. Initializes Chart.js visualization
5. Sets up pause/resume button handlers
6. Registers page unload cleanup handler

---

### Session Control

#### pauseSession()

Pauses the active quiz session, freezing the timer and blocking new submissions.

**Requirement:** 1.4 - Instructor can pause session to freeze timer

**Behavior:**
- Sends `pause` action to `ajax.php`
- Updates UI to show paused state
- Hides pause button, shows resume button
- Displays "Paused" status badge with warning styling
- Shows frozen timer value

**Events Handled:**
- `session_paused` from SSE - Updates UI when pause is broadcast

---

#### resumeSession()

Resumes a paused quiz session, restoring the timer and re-enabling submissions.

**Requirement:** 1.5 - Instructor can resume session to restore timer

**Behavior:**
- Sends `resume` action to `ajax.php`
- Updates UI to show active state
- Hides resume button, shows pause button
- Displays "Active" status badge with success styling
- Resumes timer countdown

**Events Handled:**
- `session_resumed` from SSE - Updates UI when resume is broadcast

---

### Student Monitoring

#### updateStudentStatus()

Fetches and displays connected students with their answer status.

**Requirement:** 5.1 - Display list of connected students with status indicators

**Polling Interval:** 2 seconds (Requirement 1.3)

**Behavior:**
- Calls `getstudents` action on `ajax.php`
- Updates student list with connection status icons
- Shows "Answered" badge for students who have responded
- Updates aggregate statistics (connected, answered, pending)

**Student Status Icons:**

| Status | Icon | Color |
|--------|------|-------|
| Connected | `fa-circle` | Green (text-success) |
| Disconnected | `fa-times-circle` | Red (text-danger) |
| Answering | `fa-spinner fa-spin` | Blue (text-info) |
| Answered | `fa-check-circle` | Green (text-success) |

---

#### updateAggregateStats(stats)

Updates the aggregate statistics display.

**Requirement:** 5.5 - Display aggregate statistics

**Parameters:**
- `stats` (Object) - Statistics object with:
  - `connected` (number) - Connected student count
  - `answered` (number) - Students who answered current question
  - `pending` (number) - Students who haven't answered yet

**UI Elements Updated:**
- `#stat-connected` - Connected count
- `#stat-answered` - Answered count
- `#stat-pending` - Pending count
- `#answered-progress` - Progress bar showing answer completion

---

### Real-Time Updates

The control panel subscribes to the following SSE events via `connection_manager`:

| Event | Handler | Description |
|-------|---------|-------------|
| `session_paused` | `handleSessionPaused()` | Updates UI when session is paused |
| `session_resumed` | `handleSessionResumed()` | Updates UI when session is resumed |
| `question_broadcast` | `handleQuestionBroadcast()` | Resets answer status for new question |
| `state_update` | `handleStateUpdate()` | Updates session state from polling |
| `statuschange` | `handleConnectionStatusChange()` | Updates connection indicator |

---

### Statistics Display

#### updateStats()

Fetches and displays session statistics via AJAX polling.

**Polling Interval:** Configurable (default: 1 second)

**Data Displayed:**
- Participant count
- Question progress (current / total)
- Response count and participation rate
- Answer distribution (A, B, C, D counts)
- Session status
- Timer (remaining or elapsed)

---

#### updateDistribution(distribution)

Updates the answer distribution table and chart.

**Parameters:**
- `distribution` (Object) - Distribution data with:
  - `A`, `B`, `C`, `D` (number) - Count for each option
  - `total` (number) - Total responses
  - `correctanswer` (string) - The correct answer option

**Behavior:**
- Updates count and percentage for each option
- Highlights correct answer row with success styling
- Updates Chart.js bar chart if available

---

### Chart Visualization

#### initChart()

Initializes Chart.js bar chart for response visualization.

**Requirements:**
- Chart.js must be loaded via script tag in PHP
- Canvas element with id `responseChart` must exist

**Chart Configuration:**
- Type: Bar chart
- Labels: A, B, C, D
- Colors: Blue for incorrect, green for correct answer
- Responsive with maintained aspect ratio

---

#### updateChart(distribution)

Updates chart with new distribution data.

**Parameters:**
- `distribution` (Object) - Same as `updateDistribution()`

**Behavior:**
- Updates bar heights for each option
- Changes correct answer bar to green
- Uses `update('none')` for smooth real-time updates without animation

---

### Error Handling

The module implements consecutive failure tracking:

- Tracks consecutive AJAX failures
- After 5 consecutive failures, displays warning notification
- Continues polling despite errors (graceful degradation)
- Resets failure counter on successful response

---

### Cleanup

#### stopPolling()

Stops the statistics polling timer.

#### stopStudentStatusPolling()

Stops the student status polling timer.

**Page Unload:**
Both polling timers are automatically stopped on page unload, and the SSE connection is disconnected via `ConnectionManager.disconnect()`.

---

### UI Element IDs

The control panel expects the following DOM elements:

**Session Controls:**
- `#btn-pause-session` - Pause button
- `#btn-resume-session` - Resume button
- `#session-status` - Status text
- `#session-status-badge` - Status badge

**Statistics:**
- `#participant-count` - Total participants
- `#question-progress` - Question progress (e.g., "3 / 10")
- `#response-count` - Response count
- `#response-rate` - Participation rate percentage
- `#response-progress` - Participation progress bar
- `#time-display` - Timer display

**Student Status:**
- `#connected-students-list` - Container for student list
- `#stat-connected` - Connected count
- `#stat-answered` - Answered count
- `#stat-pending` - Pending count
- `#answered-progress` - Answer completion progress bar
- `#connected-count` - Alternative connected count display

**Distribution:**
- `#count-A`, `#count-B`, `#count-C`, `#count-D` - Option counts
- `#percent-A`, `#percent-B`, `#percent-C`, `#percent-D` - Option percentages
- `#bar-A`, `#bar-B`, `#bar-C`, `#bar-D` - Option progress bars
- `#row-A`, `#row-B`, `#row-C`, `#row-D` - Option table rows

**Chart:**
- `#responseChart` - Canvas element for Chart.js

**Connection:**
- `#connection-status-indicator` - Connection status icon

---

### Version History

**Version 2025120600:**
- Integrated `connection_manager` for SSE support with polling fallback
- Added pause/resume session controls (Requirements 1.4, 1.5)
- Added real-time student status monitoring (Requirements 1.3, 5.1, 5.4, 5.5)
- Added aggregate statistics display (Requirement 5.5)
- Added connection status indicator
- Improved error handling with consecutive failure tracking
