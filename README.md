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

To enable physical clicker support, you must configure Moodle Web Services.

#### 1. Enable Web Services
1. Go to **Site Administration > Advanced features**.
2. Enable **Enable web services** and save.
3. Go to **Site Administration > Server > Web services > Manage protocols**.
4. Enable **REST protocol**.

#### 2. Create Service User and Role
1. Go to **Site Administration > Users > Add a new user**.
2. Create a user (e.g., `clicker_hub`) with a strong password.
3. Go to **Site Administration > Users > Define roles > Add new role**.
4. Name it "Clicker Hub Service".
5. Allow the following capabilities:
   - `mod/classengage:submitclicker`
   - `mod/classengage:takequiz`
   - `mod/classengage:view`
   - `webservice/rest:use`

#### 3. Generate Token
1. Go to **Site Administration > Server > Web services > External services**.
2. Find **ClassEngage Clicker Service** and click **Enable**.
3. Add the `clicker_hub` user to **Authorised users**.
4. Go to **Site Administration > Server > Web services > Manage tokens**.
5. Add a token for the `clicker_hub` user for the **ClassEngage Clicker Service**.
6. Copy this token for use in your clicker hub software.

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
| `heartbeat` | Test heartbeat endpoint under load |
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
