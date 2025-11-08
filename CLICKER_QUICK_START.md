# ClassEngage Clicker Integration - Quick Start Guide

## 🚀 5-Minute Setup

### Step 1: Enable Web Services (Moodle Admin)

```
Site Administration → Advanced features
  ✓ Enable web services → Save

Site Administration → Server → Web services → Manage protocols
  ✓ REST protocol → Enable
```

### Step 2: Create Hub User & Role

```
Site Administration → Users → Add a new user
  Username: clicker_hub
  Password: [Strong Password]
  → Create user

Site Administration → Users → Define roles → Add new role
  Name: Clicker Hub Service
  Capabilities:
    ✓ mod/classengage:submitclicker
    ✓ mod/classengage:takequiz
    ✓ mod/classengage:view
    ✓ webservice/rest:use
  → Save
```

### Step 3: Generate Token

```
Site Administration → Server → Web services → External services
  Find: ClassEngage Clicker Service
  → Enable
  → Authorised users → Add: clicker_hub

Site Administration → Server → Web services → Manage tokens
  → Add
  User: clicker_hub
  Service: ClassEngage Clicker Service
  → Save
  → COPY THE TOKEN (you'll need this!)
```

### Step 4: Test API

```bash
curl -X POST "https://your-moodle.edu/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN_HERE" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=core_webservice_get_site_info"
```

If you see site info JSON, it's working! ✅

---

## 📡 API Cheat Sheet

### Base URL
```
POST https://your-moodle.edu/webservice/rest/server.php
```

### Required Parameters (All Calls)
```
wstoken=YOUR_TOKEN
moodlewsrestformat=json
wsfunction=FUNCTION_NAME
```

### Functions

#### 1. Get Active Session
```bash
wsfunction=mod_classengage_get_active_session
classengageid=5
```

#### 2. Get Current Question
```bash
wsfunction=mod_classengage_get_current_question
sessionid=12
```

#### 3. Submit Single Response
```bash
wsfunction=mod_classengage_submit_clicker_response
sessionid=12
userid=42
clickerid=CLICKER-001
answer=B
timestamp=1699012345  # Optional
```

#### 4. Submit Bulk Responses (Recommended)
```bash
wsfunction=mod_classengage_submit_bulk_responses
sessionid=12
responses[0][clickerid]=CLICKER-001
responses[0][answer]=A
responses[1][clickerid]=CLICKER-002
responses[1][answer]=B
responses[2][clickerid]=CLICKER-003
responses[2][answer]=C
```

#### 5. Register Clicker
```bash
wsfunction=mod_classengage_register_clicker
userid=42
clickerid=CLICKER-001
```

---

## 🎯 Typical Classroom Flow

```
1. Teacher creates ClassEngage activity
2. Teacher creates quiz session
3. Teacher starts session in Moodle

4. Hub polls:
   GET active session (every 2 seconds)
   
5. When session active:
   GET current question
   Display question on projector
   
6. Students press buttons (A/B/C/D)
   Hub receives wireless signals
   
7. Hub submits:
   POST bulk responses
   
8. Teacher clicks "Next Question"
   Repeat steps 5-7
   
9. Teacher ends session
   Grades auto-sync to Moodle gradebook
```

---

## 🐍 Python Example

```python
import requests

BASE_URL = "https://your-moodle.edu/webservice/rest/server.php"
TOKEN = "your_token_here"

def call_api(function, **params):
    data = {
        'wstoken': TOKEN,
        'moodlewsrestformat': 'json',
        'wsfunction': function,
        **params
    }
    return requests.post(BASE_URL, data=data).json()

# Get active session
session = call_api('mod_classengage_get_active_session', 
                   classengageid=5)

if session['hassession']:
    # Get current question
    question = call_api('mod_classengage_get_current_question',
                       sessionid=session['sessionid'])
    
    print(f"Q: {question['questiontext']}")
    
    # Submit responses
    result = call_api('mod_classengage_submit_bulk_responses',
        sessionid=session['sessionid'],
        **{
            'responses[0][clickerid]': 'CLICKER-001',
            'responses[0][answer]': 'A',
            'responses[1][clickerid]': 'CLICKER-002',
            'responses[1][answer]': 'B',
        }
    )
    
    print(f"Processed: {result['processed']}")
```

---

## 🟢 Node.js Example

```javascript
const axios = require('axios');

const BASE_URL = 'https://your-moodle.edu/webservice/rest/server.php';
const TOKEN = 'your_token_here';

async function callApi(wsfunction, params = {}) {
  const data = new URLSearchParams({
    wstoken: TOKEN,
    moodlewsrestformat: 'json',
    wsfunction,
    ...params
  });
  
  const response = await axios.post(BASE_URL, data.toString());
  return response.data;
}

// Get active session
const session = await callApi('mod_classengage_get_active_session', {
  classengageid: 5
});

if (session.hassession) {
  // Get current question
  const question = await callApi('mod_classengage_get_current_question', {
    sessionid: session.sessionid
  });
  
  console.log(`Q: ${question.questiontext}`);
  
  // Submit responses
  const result = await callApi('mod_classengage_submit_bulk_responses', {
    sessionid: session.sessionid,
    'responses[0][clickerid]': 'CLICKER-001',
    'responses[0][answer]': 'A',
    'responses[1][clickerid]': 'CLICKER-002',
    'responses[1][answer]': 'B',
  });
  
  console.log(`Processed: ${result.processed}`);
}
```

---

## ⚠️ Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| Invalid token | Wrong token or not enabled | Regenerate token, check service enabled |
| Access denied | Missing capabilities | Add required capabilities to role |
| Function not found | Function not in service | Add functions to External service |
| Session not active | Quiz not started | Start quiz session in Moodle |
| Already answered | Duplicate submission | Expected - student already answered |
| Invalid answer | Not A/B/C/D | Check clicker is sending valid option |

---

## 🔐 Security Checklist

- [ ] Use HTTPS only (never HTTP)
- [ ] Store token securely (not in code)
- [ ] Use dedicated service account
- [ ] Limit role to minimum capabilities
- [ ] Restrict IP if possible
- [ ] Rotate tokens regularly
- [ ] Monitor API usage
- [ ] Log all submissions

---

## 📊 Testing Checklist

- [ ] Web Services enabled
- [ ] REST protocol enabled
- [ ] Service created and enabled
- [ ] Token generated
- [ ] Test API call succeeds
- [ ] Can get active session
- [ ] Can get current question
- [ ] Can submit single response
- [ ] Can submit bulk responses
- [ ] Grades appear in gradebook
- [ ] Can register clicker
- [ ] Duplicate prevention works

---

## 🆘 Quick Debug

**Test your setup:**
```bash
# Visit this URL as admin
https://your-moodle.edu/test_clicker_api.php
```

**Check logs:**
```
Site Administration → Development → Debugging
  → Set to DEVELOPER
  
Then check:
- PHP error log
- Moodle error log
- Web server access log
```

---

## 📚 Full Documentation

- **Complete API Reference:** [CLICKER_API_DOCUMENTATION.md](CLICKER_API_DOCUMENTATION.md)
- **Implementation Details:** [IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)
- **General Plugin Info:** [README.md](README.md)

---

## 💡 Pro Tips

1. **Use bulk endpoint** - Much faster than individual submissions
2. **Cache session info** - Don't poll too frequently (2-3 seconds is good)
3. **Handle network errors** - Implement retry logic
4. **Log everything** - Track all submissions for debugging
5. **Pre-register clickers** - Do this before class starts
6. **Test thoroughly** - Run practice quiz before real class

---

## ✅ That's It!

You now have classroom clickers integrated with Moodle! 🎉

Students press buttons → Hub sends to Moodle → Instant gradebook updates

For help: Check [CLICKER_API_DOCUMENTATION.md](CLICKER_API_DOCUMENTATION.md)

