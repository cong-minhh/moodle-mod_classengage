# ClassEngage Clicker API Documentation

## Overview

The ClassEngage plugin provides a REST/JSON Web Services API for integrating classroom clicker hardware. This allows wireless clicker devices (A/B/C/D keypads) to transmit student responses through a classroom hub to Moodle in real-time.

## Architecture

```
┌─────────────┐      Wireless      ┌──────────────┐      HTTP/JSON      ┌────────────┐
│   Student   │ ──────────────────> │ Classroom    │ ──────────────────> │  Moodle    │
│   Clicker   │   (A/B/C/D Press)   │     Hub      │  (Web Services)     │  Server    │
│  (Keypad)   │                     │  (Bridge)    │                     │            │
└─────────────┘                     └──────────────┘                     └────────────┘
                                                                                │
                                                                                ▼
                                                                         ┌──────────────┐
                                                                         │  Gradebook   │
                                                                         │  Analytics   │
                                                                         └──────────────┘
```

## Setup Instructions

### 1. Enable Web Services in Moodle

1. **Enable Web Services**
   - Navigate to: `Site Administration → Advanced features`
   - Check "Enable web services"
   - Save changes

2. **Enable REST Protocol**
   - Navigate to: `Site Administration → Server → Web services → Manage protocols`
   - Enable "REST protocol"

3. **Create a Web Service User** (for the clicker hub)
   - Navigate to: `Site Administration → Users → Accounts → Add a new user`
   - Create user: `clicker_hub` (or any name)
   - Set a strong password

4. **Create a Web Service Role**
   - Navigate to: `Site Administration → Users → Permissions → Define roles`
   - Add a new role: "Clicker Hub Service"
   - Grant capabilities:
     - `mod/classengage:submitclicker` (for bulk submissions)
     - `mod/classengage:takequiz` (for individual submissions)
     - `mod/classengage:view`
     - `webservice/rest:use`

5. **Assign Role to User**
   - Navigate to the course → Participants
   - Enrol the `clicker_hub` user with the "Clicker Hub Service" role

6. **Enable ClassEngage Clicker Service**
   - Navigate to: `Site Administration → Server → Web services → External services`
   - Find "ClassEngage Clicker Service"
   - Enable it
   - Click "Add functions" (they should already be listed)
   - Click "Authorised users" and add the `clicker_hub` user

7. **Generate Web Service Token**
   - Navigate to: `Site Administration → Server → Web services → Manage tokens`
   - Click "Add"
   - Select user: `clicker_hub`
   - Select service: "ClassEngage Clicker Service"
   - Save changes
   - **Copy the generated token** - you'll need this for API calls

---

## API Endpoints

All endpoints use the base URL: `https://your-moodle-site/webservice/rest/server.php`

### Authentication

All requests require:
- `wstoken`: Your Web Service token
- `moodlewsrestformat`: `json`

---

## API Functions

### 1. Get Active Session

Get information about the currently active quiz session.

**Function:** `mod_classengage_get_active_session`

**Parameters:**
- `classengageid` (int, required) - ClassEngage activity instance ID

**Example Request:**
```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_get_active_session" \
  -d "classengageid=5"
```

**Example Response:**
```json
{
  "hassession": true,
  "sessionid": 12,
  "sessionname": "Chapter 3 Quiz",
  "status": "active",
  "currentquestion": 2,
  "totalquestions": 10,
  "timelimit": 30,
  "shuffleanswers": true
}
```

---

### 2. Get Current Question

Get the current question being displayed in an active session.

**Function:** `mod_classengage_get_current_question`

**Parameters:**
- `sessionid` (int, required) - Session ID

**Example Request:**
```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_get_current_question" \
  -d "sessionid=12"
```

**Example Response:**
```json
{
  "hasquestion": true,
  "questionid": 45,
  "questiontext": "What is the capital of France?",
  "questionnumber": 3,
  "totalquestions": 10,
  "optiona": "London",
  "optionb": "Paris",
  "optionc": "Berlin",
  "optiond": "Madrid",
  "timelimit": 30,
  "timeremaining": 25
}
```

---

### 3. Submit Single Clicker Response

Submit a response from one student clicker device.

**Function:** `mod_classengage_submit_clicker_response`

**Parameters:**
- `sessionid` (int, required) - Session ID
- `userid` (int, required) - Moodle user ID of the student
- `clickerid` (string, optional) - Clicker device ID (e.g., serial number)
- `answer` (string, required) - Answer choice: "A", "B", "C", or "D"
- `timestamp` (int, optional) - Unix timestamp when button was pressed

**Example Request:**
```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_submit_clicker_response" \
  -d "sessionid=12" \
  -d "userid=42" \
  -d "clickerid=CLICKER-12345" \
  -d "answer=B" \
  -d "timestamp=1699012345"
```

**Example Response:**
```json
{
  "success": true,
  "message": "Response recorded successfully",
  "iscorrect": true,
  "correctanswer": "B",
  "responseid": 567
}
```

---

### 4. Submit Bulk Responses

Submit multiple student responses at once (recommended for efficiency).

**Function:** `mod_classengage_submit_bulk_responses`

**Parameters:**
- `sessionid` (int, required) - Session ID
- `responses` (array, required) - Array of response objects:
  - `userid` (int, optional) - User ID (can be resolved from clickerid)
  - `clickerid` (string, required) - Clicker device ID
  - `answer` (string, required) - Answer choice: "A", "B", "C", or "D"
  - `timestamp` (int, optional) - Unix timestamp

**Example Request:**
```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_submit_bulk_responses" \
  -d "sessionid=12" \
  -d "responses[0][userid]=42" \
  -d "responses[0][clickerid]=CLICKER-001" \
  -d "responses[0][answer]=A" \
  -d "responses[0][timestamp]=1699012340" \
  -d "responses[1][userid]=43" \
  -d "responses[1][clickerid]=CLICKER-002" \
  -d "responses[1][answer]=B" \
  -d "responses[1][timestamp]=1699012342"
```

**Example Response:**
```json
{
  "success": true,
  "message": "Processed 2 responses, 0 failed",
  "processed": 2,
  "failed": 0,
  "results": [
    {
      "clickerid": "CLICKER-001",
      "success": true,
      "message": "Response recorded",
      "responseid": 568
    },
    {
      "clickerid": "CLICKER-002",
      "success": true,
      "message": "Response recorded",
      "responseid": 569
    }
  ]
}
```

---

### 5. Register Clicker Device

Register a clicker device to a Moodle user.

**Function:** `mod_classengage_register_clicker`

**Parameters:**
- `userid` (int, required) - Moodle user ID
- `clickerid` (string, required) - Clicker device ID

**Example Request:**
```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_register_clicker" \
  -d "userid=42" \
  -d "clickerid=CLICKER-12345"
```

**Example Response:**
```json
{
  "success": true,
  "message": "Clicker device registered successfully"
}
```

---

## Integration Workflow

### Typical Classroom Session Flow

1. **Before Class:**
   - Teacher creates ClassEngage activity in Moodle
   - Students register their clicker devices (map device ID to Moodle user)

2. **During Class:**
   ```
   Step 1: Teacher starts quiz session in Moodle
   
   Step 2: Hub polls for active session
           GET active session (classengageid)
   
   Step 3: Hub displays current question
           GET current question (sessionid)
   
   Step 4: Students press buttons (A/B/C/D)
           Hub receives wireless signals
   
   Step 5: Hub submits responses
           POST bulk responses (sessionid, array of answers)
   
   Step 6: Teacher advances to next question
           Repeat steps 3-5
   ```

3. **After Class:**
   - Grades automatically sync to Moodle gradebook
   - Teacher views analytics dashboard
   - Students see their results

---

## Error Handling

### Common Error Responses

**Session Not Active:**
```json
{
  "success": false,
  "message": "Session is not active",
  "iscorrect": false,
  "correctanswer": ""
}
```

**Already Answered:**
```json
{
  "success": false,
  "message": "Already answered this question"
}
```

**Invalid Answer Format:**
```json
{
  "success": false,
  "message": "Invalid answer format. Must be A, B, C, or D"
}
```

**Clicker Not Registered:**
```json
{
  "clickerid": "CLICKER-999",
  "success": false,
  "message": "Clicker device not registered"
}
```

---

## Hub Implementation Example

### Python Example

```python
import requests
import time

class MoodleClickerHub:
    def __init__(self, base_url, token):
        self.base_url = base_url
        self.token = token
        self.endpoint = f"{base_url}/webservice/rest/server.php"
    
    def _call_api(self, function, params):
        """Make Web Service API call"""
        data = {
            'wstoken': self.token,
            'moodlewsrestformat': 'json',
            'wsfunction': function,
            **params
        }
        response = requests.post(self.endpoint, data=data)
        return response.json()
    
    def get_active_session(self, classengage_id):
        """Get active session for a ClassEngage activity"""
        return self._call_api('mod_classengage_get_active_session', {
            'classengageid': classengage_id
        })
    
    def get_current_question(self, session_id):
        """Get current question"""
        return self._call_api('mod_classengage_get_current_question', {
            'sessionid': session_id
        })
    
    def submit_responses(self, session_id, responses):
        """Submit bulk responses"""
        params = {'sessionid': session_id}
        for i, resp in enumerate(responses):
            params[f'responses[{i}][userid]'] = resp['userid']
            params[f'responses[{i}][clickerid]'] = resp['clickerid']
            params[f'responses[{i}][answer]'] = resp['answer']
            params[f'responses[{i}][timestamp]'] = int(time.time())
        
        return self._call_api('mod_classengage_submit_bulk_responses', params)

# Usage
hub = MoodleClickerHub('https://your-moodle.edu', 'YOUR_TOKEN')

# Get active session
session = hub.get_active_session(classengage_id=5)
if session['hassession']:
    session_id = session['sessionid']
    
    # Get current question
    question = hub.get_current_question(session_id)
    print(f"Question: {question['questiontext']}")
    
    # Submit responses (example: 2 students answered)
    responses = [
        {'userid': 42, 'clickerid': 'CLICKER-001', 'answer': 'A'},
        {'userid': 43, 'clickerid': 'CLICKER-002', 'answer': 'B'}
    ]
    result = hub.submit_responses(session_id, responses)
    print(f"Processed: {result['processed']}, Failed: {result['failed']}")
```

---

## Node.js Example

```javascript
const axios = require('axios');

class MoodleClickerHub {
  constructor(baseUrl, token) {
    this.baseUrl = baseUrl;
    this.token = token;
    this.endpoint = `${baseUrl}/webservice/rest/server.php`;
  }

  async callApi(wsfunction, params) {
    const data = new URLSearchParams({
      wstoken: this.token,
      moodlewsrestformat: 'json',
      wsfunction,
      ...params
    });

    const response = await axios.post(this.endpoint, data.toString(), {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });

    return response.data;
  }

  async getActiveSession(classengageId) {
    return this.callApi('mod_classengage_get_active_session', {
      classengageid: classengageId
    });
  }

  async getCurrentQuestion(sessionId) {
    return this.callApi('mod_classengage_get_current_question', {
      sessionid: sessionId
    });
  }

  async submitResponses(sessionId, responses) {
    const params = { sessionid: sessionId };
    
    responses.forEach((resp, i) => {
      params[`responses[${i}][userid]`] = resp.userid;
      params[`responses[${i}][clickerid]`] = resp.clickerid;
      params[`responses[${i}][answer]`] = resp.answer;
      params[`responses[${i}][timestamp]`] = Math.floor(Date.now() / 1000);
    });

    return this.callApi('mod_classengage_submit_bulk_responses', params);
  }
}

// Usage
const hub = new MoodleClickerHub('https://your-moodle.edu', 'YOUR_TOKEN');

(async () => {
  const session = await hub.getActiveSession(5);
  
  if (session.hassession) {
    const question = await hub.getCurrentQuestion(session.sessionid);
    console.log(`Question: ${question.questiontext}`);
    
    const responses = [
      { userid: 42, clickerid: 'CLICKER-001', answer: 'A' },
      { userid: 43, clickerid: 'CLICKER-002', answer: 'B' }
    ];
    
    const result = await hub.submitResponses(session.sessionid, responses);
    console.log(`Processed: ${result.processed}, Failed: ${result.failed}`);
  }
})();
```

---

## Security Considerations

1. **Token Security:**
   - Store tokens securely
   - Use HTTPS only
   - Regenerate tokens periodically
   - Limit token to specific service

2. **Rate Limiting:**
   - Implement rate limiting on hub
   - Batch responses when possible
   - Use bulk submission endpoint

3. **User Privacy:**
   - Clicker IDs should not reveal student identity
   - Secure wireless communication between clickers and hub
   - Log access for auditing

4. **Permissions:**
   - Use dedicated service account
   - Grant minimal required capabilities
   - Restrict to specific courses/contexts

---

## Troubleshooting

### Test Web Service Token

```bash
curl -X POST "https://your-moodle-site/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=core_webservice_get_site_info"
```

### Enable Debugging

In Moodle:
- `Site Administration → Development → Debugging`
- Set "Debug messages" to "DEVELOPER"
- Check web server error logs

### Common Issues

1. **"Invalid token"** - Check token is correct and service is enabled
2. **"Access control exception"** - Check user has required capabilities
3. **"Function not available"** - Check function is added to service
4. **"Session not found"** - Verify session ID and status

---

## Database Schema

### Clicker Devices Table

```sql
CREATE TABLE mdl_classengage_clicker_devices (
  id BIGINT PRIMARY KEY,
  userid BIGINT NOT NULL,           -- Moodle user ID
  clickerid VARCHAR(100) NOT NULL,  -- Device serial/ID
  contextid BIGINT NOT NULL,
  timecreated BIGINT NOT NULL,
  lastused BIGINT NOT NULL,
  UNIQUE(clickerid),                -- One clicker = one user
  UNIQUE(userid, clickerid)
);
```

---

## Support

For issues or questions:
- GitHub: https://github.com/your-username/moodle-mod_classengage
- Moodle Forums: https://moodle.org/plugins/mod_classengage

---

## License

GPL v3 or later

