# In-class Learning Engagement

Transform your lectures into interactive learning experiences with the In-class Learning Engagement Moodle plugin. This module empowers educators to foster real-time participation, assess student understanding instantly, and gain deep insights into learning progress.

## Why Choose ClassEngage?

- **Boost Student Participation**: Break the silence in your classroom with interactive live quizzes that encourage every student to participate.
- **Save Time with AI**: Automatically generate relevant quiz questions directly from your lecture slides using advanced NLP technology.
- **Data-Driven Teaching**: Move beyond simple scores with advanced analytics that reveal how students are learning, not just what they know.
- **Hybrid Ready**: Seamlessly support both remote students (via web) and in-person students (via physical clickers) in the same session.

## Key Features

### AI-Powered Question Generation
Upload your lecture slides (PDF, PPT, PPTX) and let the system do the work. Our NLP engine analyzes your content and automatically generates multiple-choice questions, saving you hours of preparation time. You retain full control to review, edit, and approve every question.

### Real-Time Engagement
Run live, synchronous quiz sessions that keep students focused and engaged.
- **Instant Feedback**: Students see immediate results, reinforcing learning concepts on the spot.
- **Live Leaderboards**: Optional gamification elements to increase motivation.
- **Dynamic Pacing**: Control the flow of questions to match your lecture speed.
- **Pause/Resume Control**: Instructors can pause sessions to freeze timers and block submissions, then resume when ready.
- **Offline Support**: Responses are cached locally when connectivity is lost and automatically synced when restored.
- **Optimistic UI**: Immediate visual confirmation of answer submission before server acknowledgment.
- **Real-Time Updates**: Server-Sent Events (SSE) for instant question broadcasts with automatic polling fallback.

### Instructor Control Panel
Monitor your class in real-time with the enhanced instructor dashboard:
- **Live Student Status**: See which students are connected and their answer status, updated every 2 seconds.
- **Aggregate Statistics**: View connected count, answered count, and pending responses at a glance.
- **Session Control**: Pause and resume sessions with a single click to manage classroom flow.
- **Response Distribution**: Real-time visualization of answer distribution with Chart.js graphs.
- **Connection Indicators**: Monitor your own connection status with automatic reconnection.

### Deep Learning Analytics
Gain actionable insights with our comprehensive analytics dashboard:
- **Concept Difficulty Analysis**: Identify which topics students find most challenging.
- **At-Risk Student Detection**: Early warning system for students who may be falling behind.
- **Response Trends**: Visualize class performance over time to spot engagement dips.
- **Teaching Recommendations**: Receive automated suggestions on areas that may need re-teaching based on class performance.

### Flexible Participation
- **Web Interface**: Students can participate using any device with a browser (laptop, tablet, phone).
- **Mobile-Optimized**: Touch-friendly answer buttons and responsive design for seamless mobile participation.
- **Clicker Integration**: Native support for physical clicker devices via our REST API, perfect for environments with limited connectivity or strict device policies.
- **Connection Resilience**: Automatic reconnection with state restoration when network connectivity is interrupted.

## Installation

### Standard Plugin Installation

1. Download the plugin and extract it to `mod/classengage` in your Moodle installation.
2. Log in to your Moodle site as an administrator.
3. Go to **Site administration > Notifications** to trigger the database update.
4. Configure the plugin settings (NLP endpoint, API keys) in **Site administration > Plugins > Activity modules > In-class Learning Engagement**.

### Clicker Integration Setup

To enable physical clicker support (or to use the Web Services API for testing), you must configure Moodle Web Services. Follow these steps **in order**.

> **Note**: This setup is **only required** if you are using physical clicker devices or testing the API directly. Students using the web interface do not need this configuration.

#### Step 1: Enable Web Services Globally

1. Go to **Site Administration > Advanced features**
2. Find **Enable web services** and check the box ☑️
3. Click **Save changes**

#### Step 2: Enable REST Protocol

1. Go to **Site Administration > Server > Web services > Manage protocols**
2. Click the 👁️ eye icon next to **REST protocol** to enable it (the eye should become open/visible)
3. The REST protocol row should now show as enabled

#### Step 3: Create the Service User

1. Go to **Site Administration > Users > Accounts > Add a new user**
2. Fill in the required fields:
   - **Username**: `clicker_hub` (or any name you prefer)
   - **Password**: Choose a strong password
   - **First name**: `Clicker`
   - **Last name**: `Hub`
   - **Email**: `clicker@example.com` (can be any valid email format)
3. Click **Create user**

#### Step 4: Create a Custom Role (Optional but Recommended)

This step creates a minimal-permission role for security. You can skip this if the user has admin rights.

1. Go to **Site Administration > Users > Permissions > Define roles**
2. Click **Add a new role**
3. Choose **Use role or archetype**: Select "No role" and click **Continue**
4. Fill in:
   - **Short name**: `clickerhub`
   - **Custom full name**: `Clicker Hub Service`
   - **Role archetype**: Leave as "None"
   - **Context types**: Check ☑️ **System**
5. In the **Capabilities** section, search for and allow (set to ✅ Allow):
   - `webservice/rest:use` — Use REST protocol
   - `mod/classengage:view` — View ClassEngage activity
   - `mod/classengage:takequiz` — Take quiz (submit responses)
   - `mod/classengage:submitclicker` — Submit clicker responses
6. Click **Create this role**

#### Step 5: Assign the Role to the Service User

1. Go to **Site Administration > Users > Permissions > Assign system roles**
2. Click on **Clicker Hub Service** (the role you just created)
3. Find `clicker_hub` in the "Potential users" list and add them to "Existing users"
4. Click **Add** to assign the role

#### Step 6: Enable the ClassEngage External Service

1. Go to **Site Administration > Server > Web services > External services**
2. Find **ClassEngage Clicker Service** in the list
3. If the "Enabled" column shows ❌ or is unchecked:
   - Click on the service name to edit it
   - Check ☑️ **Enabled**
   - Click **Save changes**
4. Click on the service name **ClassEngage Clicker Service**
5. Scroll down to **Authorised users**
6. Click **Add** and select the `clicker_hub` user
7. Click **Add** to authorize this user

#### Step 7: Generate an API Token

1. Go to **Site Administration > Server > Web services > Manage tokens**
2. Click **Create token** (or **Add**)
3. Fill in:
   - **User**: Search for and select `clicker_hub`
   - **Service**: Select **ClassEngage Clicker Service**
   - **Valid until**: Leave empty for no expiration, or set a date
   - **IP restriction**: Leave empty (or restrict to specific IPs for security)
4. Click **Save changes**
5. **Copy the generated token** — you will need this for your clicker hub software or API testing

   > ⚠️ **Important**: The token is only shown once! Copy it immediately and store it securely.

#### Step 8: Verify the Setup

Test that your token works by running this command (replace `YOUR_TOKEN` with your actual token):

```bash
curl -X POST "http://localhost:8000/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=core_webservice_get_site_info" \
  -d "moodlewsrestformat=json"
```

**Expected response** (success):
```json
{"sitename":"Your Moodle Site","username":"clicker_hub",...}
```

**If you get an error**, see the troubleshooting section below.

#### Troubleshooting

| Error | Cause | Solution |
|-------|-------|----------|
| `HTTP 403 Forbidden` | Token invalid or web services not enabled | Re-check Steps 1, 2, 6, and 7 |
| `HTTP 303 Redirect` | URL mismatch with `$CFG->wwwroot` | Use the exact URL from your Moodle config |
| `accessexception` | User not authorized or service not enabled | Re-check Steps 5 and 6 (role assignment and authorized users) |
| `invalidtoken` | Token doesn't exist or is expired | Generate a new token in Step 7 |
| `servicenotavailable` | External service not enabled | Enable the service in Step 6 |
| `webabortnoresult` | Function not found in service | Reinstall the plugin or check service functions |

#### Quick Checklist

Before testing, verify all of these are complete:

- [ ] **Advanced features**: "Enable web services" is ON
- [ ] **Manage protocols**: REST protocol is enabled
- [ ] **Service user**: `clicker_hub` user exists
- [ ] **Role assigned**: User has the `clickerhub` role at system level
- [ ] **External service**: "ClassEngage Clicker Service" is enabled
- [ ] **Authorized user**: `clicker_hub` is added to the service's authorized users
- [ ] **Token created**: A valid token exists for the user + service combination

## Usage Workflow

### Standard Workflow
1. **Upload**: Instructor uploads lecture slides to the activity.
2. **Generate**: System generates questions; Instructor reviews and approves them.
3. **Engage**: Instructor starts a live session; Students join and answer questions via web or clickers.
4. **Analyze**: Instructor reviews the analytics dashboard to adjust future teaching strategies.

### Clicker Workflow
1. **Setup**: Teacher starts the session in Moodle.
2. **Poll**: The classroom hub polls the API for the active session and current question.
3. **Submit**: Students press buttons on their clickers; the hub collects responses and submits them in bulk to Moodle.
4. **Result**: Grades are automatically synced to the Moodle gradebook.

## Development and Testing

### Running Tests

**PHPUnit Tests:**
```bash
vendor/bin/phpunit mod/classengage/tests/
```

**Behat Acceptance Tests:**

First, ensure Behat is configured in your `config.php`:
```php
$CFG->behat_prefix = 'behat_';
$CFG->behat_dataroot = '/path/to/behatdata';
$CFG->behat_wwwroot = 'http://localhost:8000';
$CFG->behat_profiles = [
    'default' => [
        'browser' => 'chrome',
        'wd_host' => 'http://localhost:4444/wd/hub',
    ],
];
```

Then initialize and run:
```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags @mod_classengage
```

### Load Testing
The plugin includes a comprehensive load testing script to verify performance under load. It supports testing both legacy clicker API endpoints and the new real-time quiz engine endpoints.

**Basic Usage:**
```bash
# Create test users
php mod/classengage/tests/load_test_api.php --action=create --users=200 --prefix=loadtest

# Enroll users in a course
php mod/classengage/tests/load_test_api.php --action=enroll --courseid=2 --prefix=loadtest

# Simulate concurrent users (scalability test)
php mod/classengage/tests/load_test_api.php --action=concurrent --sessionid=1 --prefix=loadtest --users=200

# Clean up test users
php mod/classengage/tests/load_test_api.php --action=cleanup --prefix=loadtest
```

**Available Actions:**

| Action | Description |
|--------|-------------|
| `create` | Create test users with specified prefix |
| `enroll` | Enroll test users in a course |
| `answer` | Simulate single answer submissions (legacy API) |
| `batch` | Test batch response submission endpoint |
| `sse` | Test SSE connection handling |
| `concurrent` | Simulate 200+ concurrent users for scalability testing |
| `cleanup` | Delete test users |
| `all` | Run create, enroll, and answer actions sequentially |

**Parameters:**

| Parameter | Short | Description | Default |
|-----------|-------|-------------|---------|
| `--action` | `-a` | Action to perform | `all` |
| `--users` | `-u` | Number of users to create/simulate | `50` |
| `--sessionid` | `-s` | Session ID (required for most actions) | - |
| `--courseid` | `-c` | Course ID (required for enroll) | - |
| `--prefix` | `-p` | Username prefix for test users | `loadtest` |
| `--percent` | - | Percentage of users to simulate answering | `100` |
| `--delay` | `-d` | Delay in milliseconds between requests | `0` |
| `--batchsize` | `-b` | Responses per batch (batch action) | `5` |
| `--duration` | `-t` | Duration in seconds (sse/heartbeat) | `30` |
| `--verbose` | `-v` | Show detailed output | `false` |
| `--report` | `-r` | Generate detailed JSON performance report | `false` |

**Example: Full Scalability Test:**
```bash
# 1. Create 200 test users
php mod/classengage/tests/load_test_api.php -a create -u 200 -p scaletest

# 2. Enroll in course
php mod/classengage/tests/load_test_api.php -a enroll -c 2 -p scaletest

# 3. Run concurrent simulation with report
php mod/classengage/tests/load_test_api.php -a concurrent -s 1 -p scaletest -u 200 -r

# 4. Test SSE connections
php mod/classengage/tests/load_test_api.php -a sse -s 1 -p scaletest -t 60

# 5. Cleanup
php mod/classengage/tests/load_test_api.php -a cleanup -p scaletest
```

**NFR Compliance Checks:**
The `concurrent` action automatically validates:
- **NFR-01**: Sub-1-second average response latency
- **NFR-03**: 95%+ success rate with 200+ concurrent users

**Performance Metrics:**
The script reports latency statistics (min, avg, P50, P95, P99, max), throughput (responses/second), and error summaries.

## Technical Architecture

### Client-Side Modules

The quiz interface uses a modular JavaScript architecture:

- **quiz.js**: Main quiz participation module with offline support and optimistic UI updates
- **controlpanel.js**: Instructor control panel with real-time student monitoring and session control
- **connection_manager.js**: Handles SSE connections with automatic polling fallback
- **client_cache.js**: IndexedDB-based offline response caching with automatic retry

### Connection Handling

The plugin uses a tiered approach for real-time communication:

1. **Primary**: Server-Sent Events (SSE) for low-latency server-to-client push
2. **Fallback**: HTTP polling at configurable intervals when SSE is unavailable
3. **Offline**: IndexedDB caching with automatic sync on reconnection

### Performance Targets

- Response acknowledgment: <1 second latency
- Question broadcast: <500ms to all connected clients
- Concurrent users: 200+ per session
- Offline cache: Unlimited pending responses with automatic retry

## License

This project is licensed under the GNU General Public License v3 or later.
