# ClassEngage - AI-Powered In-Class Learning Engagement for Moodle

[![Moodle](https://img.shields.io/badge/Moodle-4.0%2B-orange)](https://moodle.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v3-green)](https://www.gnu.org/licenses/gpl-3.0)

## Features

- **Slide Upload** - Upload PDF, PPT, PPTX, DOC, DOCX lecture slides
- **AI Question Generation** - Automatically generate quiz questions using Google Gemini AI
- **Question Management** - Review, edit, and approve AI-generated questions
- **Live Quiz Sessions** - Conduct real-time interactive quizzes with instant feedback
- **Tabbed Control Panel** - Unified interface for managing all aspects of your quiz sessions
- **Clicker Integration** - Full Web Services API for classroom clicker devices (A/B/C/D keypads)
- **Analytics Dashboard** - Two-tab interface: Simple Analysis for quick engagement/comprehension snapshots, Advanced Analysis for teaching insights (concept difficulty, timeline, recommendations)
- **Real-time Updates** - AJAX polling for seamless live experience
- **Responsive Design** - Works on desktop, tablet, and mobile devices
- **Privacy Compliant** - Full GDPR support with Privacy API implementation
- **Backup & Restore** - Complete Moodle backup/restore integration
- **Gradebook Integration** - Automatic grade synchronization

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [Clicker Integration](#clicker-integration)
- [Database Schema](#database-schema)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Requirements

- **Moodle:** 4.0 or later (fully compatible with Moodle 4.4+)
- **PHP:** 7.4 or later (8.1+ recommended)
- **Database:** MySQL 5.7+ / PostgreSQL 10+ / MariaDB 10.2+
- **Web Server:** Apache 2.4+ / Nginx 1.18+
- **Browser:** Modern browser with JavaScript enabled
- **NLP Service:** (Optional) External Node.js service for AI question generation
  - See: https://github.com/cong-minhh/classengage-nlp-service

## Installation

### Step 1: Install Moodle Plugin

```bash
# Navigate to Moodle directory
cd /path/to/moodle

# Clone or copy plugin to mod directory
# Ensure structure is: moodle/mod/classengage/
git clone https://github.com/cong-minhh/moodle-mod_classengage.git mod/classengage

# Set permissions
sudo chown -R www-data:www-data mod/classengage
sudo chmod -R 755 mod/classengage
```

### Step 2: Complete Installation via Moodle

1. Log in to Moodle as administrator
2. Navigate to **Site Administration → Notifications**
3. Click **Upgrade Moodle database now**
4. Follow on-screen instructions

### Step 3: Verify Installation

```bash
# Check database tables were created
mysql -u moodle_user -p -e "SHOW TABLES LIKE 'mdl_classengage%';" moodle_db
```

You should see 6 tables:
- `mdl_classengage`
- `mdl_classengage_slides`
- `mdl_classengage_questions`
- `mdl_classengage_sessions`
- `mdl_classengage_session_questions`
- `mdl_classengage_responses`

## Configuration

### Plugin Settings

Navigate to: **Site Administration → Plugins → Activity modules → In-class Learning Engagement**

#### NLP Service Settings

To enable AI-powered question generation, set up the external NLP service first:
**See:** https://github.com/cong-minhh/classengage-nlp-service

```
NLP Service Endpoint: http://localhost:3000 (or your service URL)
NLP API Key: (leave empty if not using authentication)
Auto-generate Questions on Upload: Enabled
Default Number of Questions: 10
```

**Note:** Without the NLP service, you can still manually create questions.

#### File Upload Settings

```
Maximum Slide File Size: 50 (MB)
```

Ensure PHP settings allow large uploads:

```bash
# Check current settings
php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit'

# Edit php.ini if needed
sudo nano /etc/php/7.4/apache2/php.ini

# Set these values:
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M

# Restart web server
sudo systemctl restart apache2
```

#### Quiz Defaults

```
Default Number of Questions: 10
Default Time Limit: 30 (seconds per question)
```

#### Real-time Settings

```
Enable Real-time Updates: Enabled
Polling Interval: 1000 (milliseconds)
```

## Usage Guide

### For Instructors

#### 1. Create Activity

```
1. Turn editing on in your course
2. Add an activity → In-class Learning Engagement
3. Enter activity name: "Lecture 5 Quiz"
4. Set maximum grade: 100
5. Save and display
```

#### 2. Upload Slides

```
1. Click "Upload Slides" tab
2. Title: "Lecture 5 - Machine Learning Basics"
3. Choose file: lecture5.pdf
4. Click "Upload"
5. Questions will auto-generate if enabled
```

#### 3. Manage Questions

```
1. Click "Manage Questions" tab
2. Review AI-generated questions
3. Edit any questions that need improvement
4. Click "Approve" for questions you want to use
5. Optionally add manual questions
```

#### 4. Create Quiz Session

```
1. Click "Quiz Sessions" tab
2. Click "Create New Session"
3. Fill in:
   - Title: "Lecture 5 Live Quiz"
   - Number of Questions: 10
   - Time Limit: 30 seconds
   - Shuffle Questions: Enabled
   - Shuffle Answers: Enabled
4. Click "Create Session"
```

#### 5. Run Live Quiz

```
1. Click "Start" next to your session
2. Control Panel opens showing live session monitoring
3. Students can now join and submit responses
4. Monitor live responses in real-time with:
   - 4 status cards: Question progress, Status, Participants, Response rate
   - Response distribution table with progress bars
   - Bar chart visualization (Chart.js)
   - Overall participation rate progress bar
5. Click "Next Question" to advance
6. Click "Stop Session" when done
```

**Control Panel Features:**
- **Real-time Updates**: AJAX polling every 1 second (configurable)
- **Tab Navigation**: Quick access to Upload Slides, Manage Questions, Quiz Sessions, and Analytics
- **Performance Optimized**: Chart.js loads only for active sessions
- **Cached Analytics**: Uses analytics engine with 2-second cache for performance
- **Visual Feedback**: Color-coded status badges, animated progress bars, correct answer highlighting
- **Error Handling**: Automatic retry on connection failures with user notification after 5 consecutive failures
- **Memory Management**: Automatic cleanup on page unload to prevent memory leaks

The control panel focuses on real-time session monitoring and control, with navigation tabs providing quick access to other plugin sections.

#### 6. View Analytics

```
1. Click "Analytics" tab
2. Select completed session from dropdown
3. View Simple Analysis tab (default):
   - Overall Engagement Level: Percentage of students who participated
   - Lesson Comprehension: Summary of class understanding with confused topics
   - Activity Participation: Counts of questions answered, polls, and reactions
   - Class Responsiveness: Pace indicator (quick/normal/slow) with consistency
4. Switch to Advanced Analysis tab for deeper insights:
   - Concept Difficulty: Topics ranked by correctness rate (easy/moderate/difficult)
   - Engagement Timeline: Line chart showing response activity over time with peaks/dips
   - Common Response Trends: Class-level answer patterns and misconceptions
   - Teaching Recommendations: Supportive suggestions for improving lesson delivery
   - Participation Distribution: Anonymous breakdown (high/moderate/low/none)
5. View interactive Chart.js visualizations:
   - Engagement timeline with peak/dip highlighting
   - Concept difficulty horizontal bar chart with color coding
   - Participation distribution doughnut chart
6. Export analytics to CSV if needed
```

### For Students

#### Taking a Quiz

```
1. Navigate to ClassEngage activity
2. When instructor starts: "Join Active Quiz" appears
3. Click to join
4. Answer each question as it appears
5. Click "Submit Answer"
6. See immediate feedback
7. Wait for next question
8. View final score when quiz ends
```

## Clicker Integration

ClassEngage supports classroom clicker hardware integration via REST/JSON Web Services API. This allows wireless clicker devices (A/B/C/D keypads) to submit student responses in real-time.

### Architecture

```
Student Clicker --(A/B/C/D Press)--> Classroom Hub --(HTTP/JSON)--> Moodle Server
```

### Quick Start

1. **Enable Web Services** in Moodle
   - Site Administration → Advanced features → Enable web services
   - Site Administration → Server → Web services → Manage protocols → Enable REST

2. **Create Service Account**
   - Create user: `clicker_hub`
   - Create role: "Clicker Hub Service" with capability `mod/classengage:submitclicker`
   - Assign role to user in course

3. **Generate Token**
   - Site Administration → Server → Web services → Manage tokens
   - Add token for `clicker_hub` user
   - Select service: "ClassEngage Clicker Service"

4. **Configure Hub**
   - Install hub software on classroom computer
   - Configure with Moodle URL and token
   - Map clicker device IDs to student accounts

### API Endpoints

- **Get Active Session** - Check for running quiz
- **Get Current Question** - Retrieve question being shown
- **Submit Response** - Send student answer (A/B/C/D)
- **Submit Bulk Responses** - Send multiple answers at once
- **Register Clicker** - Map device ID to student

### Example API Call

All API calls use POST requests with form-encoded data:

```bash
curl -X POST "https://your-moodle.edu/webservice/rest/server.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "wstoken=YOUR_TOKEN" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_submit_clicker_response" \
  -d "sessionid=12" \
  -d "userid=42" \
  -d "clickerid=CLICKER-001" \
  -d "answer=B"
```

### Complete Documentation

See **[CLICKER_API_DOCUMENTATION.md](CLICKER_API_DOCUMENTATION.md)** for:
- Complete setup instructions
- Full API reference
- Python and Node.js examples
- Error handling
- Security best practices

## Database Schema

### Tables

#### classengage
Main activity instances.

```sql
- id (bigint, primary key)
- course (bigint)
- name (varchar 255)
- intro (text)
- introformat (smallint)
- grade (bigint)
- timecreated (bigint)
- timemodified (bigint)
```

#### classengage_slides
Uploaded slide files.

```sql
- id (bigint, primary key)
- classengageid (bigint, foreign key)
- title (varchar 255)
- filename (varchar 255)
- filepath (varchar 255)
- filesize (bigint)
- mimetype (varchar 100)
- status (varchar 50)
- userid (bigint)
- timecreated (bigint)
- timemodified (bigint)
```

#### classengage_questions
Quiz questions.

```sql
- id (bigint, primary key)
- classengageid (bigint, foreign key)
- slideid (bigint, foreign key, nullable)
- questiontext (text)
- questiontype (varchar 50)
- optiona (text)
- optionb (text)
- optionc (text)
- optiond (text)
- correctanswer (varchar 10)
- difficulty (varchar 20)
- status (varchar 50)
- source (varchar 50)
- timecreated (bigint)
- timemodified (bigint)
```

#### classengage_sessions
Quiz sessions.

```sql
- id (bigint, primary key)
- classengageid (bigint, foreign key)
- title (varchar 255)
- status (varchar 50)
- numquestions (int)
- timelimit (int)
- shufflequestions (tinyint)
- shuffleanswers (tinyint)
- currentquestion (int)
- timestarted (bigint, nullable)
- timecompleted (bigint, nullable)
- timecreated (bigint)
- timemodified (bigint)
```

#### classengage_session_questions
Questions assigned to sessions.

```sql
- id (bigint, primary key)
- sessionid (bigint, foreign key)
- questionid (bigint, foreign key)
- questionorder (int)
- timecreated (bigint)
```

#### classengage_responses
Student responses.

```sql
- id (bigint, primary key)
- sessionid (bigint, foreign key)
- questionid (bigint, foreign key)
- userid (bigint)
- answer (varchar 10)
- iscorrect (tinyint)
- score (decimal 10,5)
- responsetime (bigint, nullable)
- timecreated (bigint)
```

#### classengage_clicker_devices
Clicker device registrations (for hardware integration).

```sql
- id (bigint, primary key)
- userid (bigint, foreign key)
- clickerid (varchar 100, unique)
- contextid (bigint)
- timecreated (bigint)
- lastused (bigint)
```

## Development

### Project Structure

```
classengage/
├── classes/
│   ├── event/                 # Moodle events
│   ├── form/                  # Moodle forms
│   ├── privacy/               # Privacy API
│   ├── output/                # Renderer classes
│   ├── external/              # Web service API
│   ├── analytics_engine.php   # Analytics and statistics
│   ├── analytics_filter.php   # Analytics filter validation
│   ├── constants.php          # Plugin constants
│   ├── control_panel_actions.php  # Control panel action handler
│   ├── nlp_generator.php      # AI integration
│   ├── session_manager.php    # Session handling
│   └── slide_processor.php    # File processing
├── backup/
│   └── moodle2/              # Backup/restore
├── db/
│   ├── access.php            # Capabilities
│   ├── install.xml           # Database schema
│   └── upgrade.php           # Upgrade scripts
├── lang/
│   └── en/
│       └── classengage.php   # Language strings
├── amd/src/                  # JavaScript (AMD)
├── tests/                    # Unit tests
├── analytics.php             # Analytics page
├── controlpanel.php          # Live control panel
├── questions.php             # Question management
├── quiz.php                  # Student quiz view
├── sessions.php              # Session management
├── slides.php                # Slide upload
├── view.php                  # Main view
├── lib.php                   # Core functions
├── mod_form.php              # Activity form
├── version.php               # Version info
└── README.md                 # This file
```

### API Functions

#### Navigation

**`classengage_render_tabs($cmid, $activetab = null)`**

Renders consistent tab navigation across all ClassEngage pages.

```php
// Example: Render tabs with 'slides' tab active
classengage_render_tabs($cm->id, 'slides');

// Example: Render tabs with no active tab (for control panel)
classengage_render_tabs($cm->id, null);
```

Parameters:
- `$cmid` (int) - Course module ID
- `$activetab` (string|null) - Active tab identifier: 'slides', 'questions', 'sessions', 'analytics', or null

Tabs generated:
- Upload Slides → `/mod/classengage/slides.php`
- Manage Questions → `/mod/classengage/questions.php`
- Quiz Sessions → `/mod/classengage/sessions.php`
- Analytics → `/mod/classengage/analytics.php`

#### Control Panel Renderer

**`\mod_classengage\output\control_panel_renderer`**

Provides rendering methods for control panel UI components.

```php
use mod_classengage\output\control_panel_renderer;

$renderer = new control_panel_renderer();

// Render session status cards
echo $renderer->render_status_cards($session, $participantcount);

// Render current question display
echo $renderer->render_question_display($question);

// Render response distribution (table + chart)
echo $renderer->render_response_distribution($question);

// Render response rate progress bar
echo $renderer->render_response_rate_progress();

// Render control buttons
echo $renderer->render_control_buttons($session, $cmid, $sessionid);
```

Methods:
- `render_status_cards($session, $participantcount)` - Renders 4 status cards showing question progress, status, participants, and response rate
- `render_question_display($question)` - Renders current question text in a card
- `render_response_distribution($question)` - Renders distribution table and chart
- `render_response_rate_progress()` - Renders overall response rate progress bar
- `render_control_buttons($session, $cmid, $sessionid)` - Renders Next Question and Stop Session buttons

#### Analytics Query Builder

**`\mod_classengage\analytics_query_builder`**

Constructs dynamic SQL queries with filters, sorting, and pagination for analytics.

```php
use mod_classengage\analytics_query_builder;
use mod_classengage\analytics_filter;

// Create filter from request parameters
$filter = new analytics_filter($params);

// Build query with filters
$builder = new analytics_query_builder($sessionid, $filter);

// Build student performance query with pagination
list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();
$students = $DB->get_records_sql($sql, $params, $offset, $perpage);
$totalcount = $DB->count_records_sql($countsql, $params);

// Build question breakdown query
list($sql, $params) = $builder->build_question_breakdown_query();
$questions = $DB->get_records_sql($sql, $params);

// Build insights query (for at-risk and top performers)
list($sql, $params) = $builder->build_insights_query();
$insights = $DB->get_records_sql($sql, $params);

// Build engagement timeline query
list($sql, $params) = $builder->build_engagement_timeline_query($intervals = 10);
$timeline = $DB->get_records_sql($sql, $params);

// Build score distribution query
list($sql, $params) = $builder->build_score_distribution_query();
$distribution = $DB->get_records_sql($sql, $params);
```

**Methods:**
- `build_student_performance_query()` - Builds paginated query with filters, sorting, and WHERE clauses. Returns array: `[sql, params, countsql, perpage, offset]`
- `build_question_breakdown_query()` - Builds query for question statistics. Returns array: `[sql, params]`
- `build_insights_query()` - Builds query for student insights (at-risk, top performers). Returns array: `[sql, params]`
- `build_engagement_timeline_query($intervals)` - Builds query for response timeline. Returns array: `[sql, params]`
- `build_score_distribution_query()` - Builds query for score histogram. Returns array: `[sql, params]`

**Features:**
- Dynamic WHERE clause construction based on active filters
- Parameterized queries for SQL injection prevention
- Support for name search, score range, response time range, question filter
- Validated sort column and direction
- LIMIT/OFFSET pagination with separate count query
- Top performers filter (top 10 students by percentage, then applies user's sort preference)

**Query Building Logic:**

The `build_student_performance_query()` method constructs queries in multiple stages:

1. **WHERE Clause Construction**: Filters are applied at the row level before aggregation
   - Name search: Uses `LIKE` with SQL injection protection
   - Question filter: Restricts to students who answered a specific question

2. **HAVING Clause Construction**: Filters are applied after aggregation
   - Score range: Applied to calculated percentage
   - Response time range: Applied to average response time

3. **Top Performers Handling**: When enabled, applies a two-stage sort
   - First: Selects top 10 students by percentage DESC
   - Second: Applies user's requested sort on those top 10
   - Count query reflects the limited result set (max 10 records)

4. **Sort Column Mapping**: Maps filter column names to query column names
   - `fullname` → `lastname, firstname` (sorts by last name first)
   - `totalresponses` → `totalresponses`
   - `correctresponses` → `correctresponses`
   - `percentage` → `percentage`
   - `avgresponsetime` → `avg_response_time`

5. **Pagination**: Applies LIMIT and OFFSET for page-based navigation
   - Calculates offset: `($page - 1) * $perpage`
   - Returns both the paginated SQL and the count SQL for total records

#### Analytics Filter

**`\mod_classengage\analytics_filter`**

Validates, sanitizes, and encapsulates filter parameters for analytics queries.

```php
use mod_classengage\analytics_filter;

// Create filter from request parameters
$params = [
    'namesearch' => 'John',
    'minscore' => 50.0,
    'maxscore' => 100.0,
    'mintime' => 5.0,
    'maxtime' => 30.0,
    'toponly' => true,
    'questionid' => 42,
    'sort' => 'percentage',
    'dir' => 'DESC',
    'page' => 1,
    'perpage' => 25
];

$filter = new analytics_filter($params);

// Get validated parameters
$namesearch = $filter->get_name_search();           // string|null
$minscore = $filter->get_min_score();               // float|null (0-100)
$maxscore = $filter->get_max_score();               // float|null (0-100)
$mintime = $filter->get_min_response_time();        // float|null (>= 0)
$maxtime = $filter->get_max_response_time();        // float|null (>= 0)
$toponly = $filter->get_top_performers_only();      // bool
$questionid = $filter->get_question_filter();       // int|null
$sortcol = $filter->get_sort_column();              // string (validated)
$sortdir = $filter->get_sort_direction();           // 'ASC' or 'DESC'
$page = $filter->get_page();                        // int (>= 1)
$perpage = $filter->get_per_page();                 // int (10/25/50/100)

// Check if any filters are active
if ($filter->is_filtered()) {
    // Apply filters to query
}

// Convert to URL parameters for pagination links
$urlparams = $filter->to_url_params();
$url = new moodle_url('/mod/classengage/analytics.php', $urlparams);
```

**Validation Rules:**
- **Name search**: Trimmed, max 255 characters
- **Score range**: 0-100, automatically swaps if min > max
- **Response time**: >= 0, automatically swaps if min > max
- **Top performers**: Boolean flag
- **Question filter**: Valid question ID or null
- **Sort column**: Whitelisted columns only (fullname, totalresponses, correctresponses, percentage, avgresponsetime)
- **Sort direction**: 'ASC' or 'DESC' only
- **Page**: >= 1
- **Per page**: One of [10, 25, 50, 100]

**Security Features:**
- All inputs are validated and sanitized
- SQL injection prevention through parameterized queries
- Whitelist validation for sort columns
- Automatic boundary enforcement for numeric ranges

#### Analytics Engine

**`\mod_classengage\analytics_engine`**

Provides analytics and statistics aggregation with caching support.

```php
use mod_classengage\analytics_engine;

$analytics = new analytics_engine($classengageid, $context);

// Get real-time stats for current question
$stats = $analytics->get_current_question_stats($sessionid);
// Returns: ['A' => 10, 'B' => 15, 'C' => 5, 'D' => 2, 'total' => 32, 
//           'question' => $question, 'correctanswer' => 'B', 'questiontext' => '...']

// Get session summary
$summary = $analytics->get_session_summary($sessionid);
// Returns: {total_participants, avg_score, completion_rate, total_questions, 
//           avg_response_time, total_responses, session_status, current_question}

// Get question-by-question breakdown
$breakdown = $analytics->get_question_breakdown($sessionid);
// Returns: Array of question statistics with answer distributions

// Get student performance
$performance = $analytics->get_student_performance($sessionid, $userid);
// Returns: {userid, correct, total, percentage, rank, avg_response_time}

// Get at-risk students (correctness < 50% OR response time > mean + 2*stddev)
$atrisk = $analytics->get_at_risk_students($sessionid, $threshold = 50.0);
// Returns: Array of student objects with reason flags and isatrisk property set to true

// Get top performing students
$topperformers = $analytics->get_top_performers($sessionid, $limit = 10);
// Returns: Array of top N students ordered by percentage DESC, avg_response_time ASC

// Get enrolled students who haven't participated
$missing = $analytics->get_missing_participants($sessionid, $courseid);
// Returns: Array of enrolled users with zero responses

// Get performance badges (most improved, fastest, most consistent)
$badges = $analytics->get_performance_badges($sessionid);
// Returns: {mostimproved, fastestresponder, mostconsistent}

// Get anomalies (suspicious patterns)
$anomalies = $analytics->get_anomalies($sessionid);
// Returns: Array of anomaly objects with type and severity

// Get question insights (highest/lowest performing)
$insights = $analytics->get_question_insights($sessionid);
// Returns: {highest_performing, lowest_performing, difficult_questions, easy_questions}

// Get engagement timeline (response count over time)
$timeline = $analytics->get_engagement_timeline($sessionid, $intervals = 10);
// Returns: Array of time intervals with response counts

// Get score distribution (histogram buckets)
$distribution = $analytics->get_score_distribution($sessionid, $buckets = 10);
// Returns: Array with bucket labels and student counts

// Get participation rate
$rate = $analytics->get_participation_rate($sessionid, $courseid);
// Returns: Float percentage (0-100)

// Get accuracy trend (compared to previous session)
$trend = $analytics->get_accuracy_trend($sessionid, $classengageid);
// Returns: Float percentage point change (can be negative)

// Get response speed statistics
$speedstats = $analytics->get_response_speed_stats($sessionid);
// Returns: {mean, median, stddev}

// Get highest consecutive correct answer streak
$streak = $analytics->get_highest_streak($sessionid);
// Returns: {userid, fullname, streak_length}

// Invalidate cache when responses change
$analytics->invalidate_cache($sessionid);
```

**Core Methods:**
- `get_current_question_stats($sessionid)` - Returns real-time response distribution for the active question with 2-second cache
- `get_session_summary($sessionid)` - Returns overall session statistics including participant count, average score, and completion rate
- `get_question_breakdown($sessionid)` - Returns detailed statistics for each question in the session
- `get_student_performance($sessionid, $userid = null)` - Returns individual or all student performance data
- `invalidate_cache($sessionid)` - Clears cached statistics for a session

**Enhanced Analytics Methods (v1.1+):**
- `get_at_risk_students($sessionid, $threshold)` - Identifies students needing intervention based on low correctness or slow response time. Returns student objects with `isatrisk` property set to `true` and `reason` array indicating why they're at risk
- `get_top_performers($sessionid, $limit)` - Returns top N students by performance
- `get_missing_participants($sessionid, $courseid)` - Finds enrolled students who haven't participated
- `get_performance_badges($sessionid)` - Awards badges for most improved, fastest responder, and most consistent performer
- `get_anomalies($sessionid)` - Detects suspicious patterns (e.g., extremely fast responses, perfect scores with minimal time)
- `get_question_insights($sessionid)` - Analyzes question difficulty and identifies highest/lowest performing questions
- `get_engagement_timeline($sessionid, $intervals)` - Tracks response activity over time
- `get_score_distribution($sessionid, $buckets)` - Generates histogram of student scores
- `get_participation_rate($sessionid, $courseid)` - Calculates percentage of enrolled students who participated
- `get_accuracy_trend($sessionid, $classengageid)` - Compares current session accuracy to previous session
- `get_response_speed_stats($sessionid)` - Calculates mean, median, and standard deviation of response times
- `get_highest_streak($sessionid)` - Finds the longest consecutive correct answer streak

**Important Notes:**
- The `currentquestion` field in `classengage_sessions` is 0-indexed (0 = first question)
- The `questionorder` field in `classengage_session_questions` is 1-indexed (1 = first question)
- When querying for the current question, the engine automatically adds 1 to convert between these indexing schemes
- All statistics are cached for 2 seconds to optimize performance during real-time polling
- Enhanced analytics methods support advanced features like at-risk student identification, performance badges, and anomaly detection

#### Analytics Charts (JavaScript)

**`mod_classengage/analytics_charts`** (AMD Module)

Provides Chart.js initialization and configuration for analytics visualizations with accessibility support and WCAG AA compliant colors.

```javascript
require(['mod_classengage/analytics_charts'], function(AnalyticsCharts) {
    // Initialize all charts with data
    var chartData = {
        timeline: [
            {label: '10:00', count: 5, is_peak: false, is_dip: false},
            {label: '10:05', count: 18, is_peak: true, is_dip: false},
            {label: '10:10', count: 12, is_peak: false, is_dip: false},
            {label: '10:15', count: 3, is_peak: false, is_dip: true}
        ],
        difficulty: [
            {question_text: 'What is 2+2?', correctness_rate: 95.5},
            {question_text: 'Explain quantum mechanics', correctness_rate: 42.3}
        ],
        distribution: {
            high: 15,      // 5+ responses
            moderate: 20,  // 2-4 responses
            low: 8,        // 1 response
            none: 3        // 0 responses
        }
    };
    
    AnalyticsCharts.init(chartData);
});
```

**Chart Types:**

1. **Engagement Timeline** (Line Chart)
   - Tracks response count over time intervals
   - Blue line with area fill and smooth tension (0.3)
   - Highlights peaks (green) and dips (orange) with larger point radius
   - Tooltips show time and response count with peak/dip labels
   - Canvas ID: `engagement-timeline-chart`
   - X-axis: Time intervals, Y-axis: Response count (integer steps)

2. **Concept Difficulty** (Horizontal Bar Chart)
   - Shows correctness rate per question/concept
   - Color-coded by difficulty:
     - Green (>70%): Easy/well-understood
     - Yellow (50-70%): Moderate difficulty
     - Red (<50%): Difficult/needs attention
   - Tooltips show full question text and exact correctness percentage
   - Canvas ID: `concept-difficulty-chart`
   - Long question texts truncated to 40 characters with ellipsis

3. **Participation Distribution** (Doughnut Chart)
   - Shows student participation spread across categories
   - Color-coded segments:
     - Green: High participation (5+ responses)
     - Blue: Moderate participation (2-4 responses)
     - Yellow: Low participation (1 response)
     - Gray: No participation (0 responses)
   - Tooltips show count and percentage of total students
   - Canvas ID: `participation-distribution-chart`
   - Legend displayed at bottom

**Configuration Constants:**

```javascript
CHART_CONFIG = {
    LABEL_MAX_LENGTH: 40,              // Max chars for question text
    POINT_RADIUS_HIGHLIGHT: 6,         // Radius for peak/dip points
    POINT_RADIUS_NORMAL: 3,            // Radius for normal points
    POINT_RADIUS_HOVER: 8,             // Radius on hover
    LINE_TENSION: 0.3,                 // Line smoothness (0-1)
    TITLE_FONT_SIZE: 16,               // Chart title font size
    DIFFICULTY_EASY_THRESHOLD: 70,     // Easy threshold (%)
    DIFFICULTY_MODERATE_THRESHOLD: 50  // Moderate threshold (%)
}
```

**Color Scheme (WCAG AA Compliant):**

```javascript
COLORS = {
    // Timeline colors
    TIMELINE_LINE: '#0f6cbf',              // Primary blue
    TIMELINE_PEAK: '#28a745',              // Success green
    TIMELINE_DIP: '#fd7e14',               // Warning orange
    TIMELINE_BACKGROUND: 'rgba(15, 108, 191, 0.1)',
    
    // Difficulty colors
    DIFFICULTY_EASY: '#28a745',            // Green (>70%)
    DIFFICULTY_MODERATE: '#ffc107',        // Yellow (50-70%)
    DIFFICULTY_HARD: '#dc3545',            // Red (<50%)
    
    // Participation colors
    PARTICIPATION_HIGH: '#28a745',         // Green (5+)
    PARTICIPATION_MODERATE: '#17a2b8',     // Blue (2-4)
    PARTICIPATION_LOW: '#ffc107',          // Yellow (1)
    PARTICIPATION_NONE: '#6c757d'          // Gray (0)
}
```

**Public API:**

```javascript
// Initialize all charts (reads data from #analytics-chart-data element)
AnalyticsCharts.init();

// Initialize individual charts with explicit data
AnalyticsCharts.initEngagementTimeline(timelineData);
AnalyticsCharts.initConceptDifficulty(difficultyData);
AnalyticsCharts.initParticipationDistribution(distributionData);

// Cleanup
AnalyticsCharts.destroyCharts();
```

**Features:**

- **Responsive Design**: All charts use `maintainAspectRatio: false` for flexible sizing
- **Accessibility**: 
  - ARIA labels on canvas elements
  - Text alternatives generated for screen readers
  - Keyboard-accessible tooltips
  - WCAG AA color contrast compliance
- **Internationalization**: All labels loaded via Moodle's string API
- **Error Handling**: Graceful degradation if canvas elements or data not found
- **Performance**: 
  - Chart instances cached and reusable
  - Data passed via data attribute for better handling of large datasets
  - Avoids AMD parameter size limitations
- **Tooltips**: Contextual information with custom callbacks
- **Automatic Cleanup**: Destroys existing charts before recreating

**Data Structure Requirements:**

**Timeline Data:**
```javascript
[
    {
        label: string,        // Time label (e.g., "10:00")
        count: number,        // Response count
        is_peak: boolean,     // Highlight as peak
        is_dip: boolean       // Highlight as dip
    }
]
```

**Difficulty Data:**
```javascript
[
    {
        question_text: string,      // Full question text
        correctness_rate: number    // Percentage (0-100)
    }
]
```

**Distribution Data:**
```javascript
{
    high: number,      // Count with 5+ responses
    moderate: number,  // Count with 2-4 responses
    low: number,       // Count with 1 response
    none: number       // Count with 0 responses
}
```

**PHP Integration:**

```php
// In analytics.php
use mod_classengage\chart_data_transformer;

$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'), true);

// Get analytics data from engines
$engagementtimeline = $analyticsengine->get_engagement_timeline($sessionid, 10);
$conceptdifficulty = $comprehensionanalyzer->get_concept_difficulty();
$participationdistribution = $analyticsengine->get_participation_distribution($sessionid, $courseid);

// Transform data for Chart.js (pass-through transformation)
$chartdata = chart_data_transformer::transform_all_chart_data(
    $engagementtimeline,
    $conceptdifficulty,
    $participationdistribution
);

// Pass chart data via data attribute (better for large datasets)
echo html_writer::div('', '', [
    'id' => 'analytics-chart-data',
    'data-chartdata' => json_encode($chartdata),
    'style' => 'display:none;'
]);

// Initialize charts (reads data from data attribute)
$PAGE->requires->js_call_amd('mod_classengage/analytics_charts', 'init', []);
```

**Chart Data Transformer:**

The `chart_data_transformer` class provides a centralized interface for preparing analytics data for Chart.js visualization. Currently, it acts as a pass-through since the data sources (analytics_engine, comprehension_analyzer) already provide data in the correct structure.

```php
use mod_classengage\chart_data_transformer;

// Transform all chart data at once
$chartdata = chart_data_transformer::transform_all_chart_data(
    $engagementtimeline,      // From analytics_engine->get_engagement_timeline()
    $conceptdifficulty,       // From comprehension_analyzer->get_concept_difficulty()
    $participationdistribution // From analytics_engine->get_participation_distribution()
);

// Or transform individual datasets
$timelinedata = chart_data_transformer::transform_timeline_data($engagementtimeline);
$difficultydata = chart_data_transformer::transform_difficulty_data($conceptdifficulty);
$distributiondata = chart_data_transformer::transform_distribution_data($participationdistribution);
```

**Methods:**

- `transform_all_chart_data($timeline, $difficulty, $distribution)` - Combines all chart data into a single object
- `transform_timeline_data($timeline)` - Passes through timeline data (array of objects with label, count, is_peak, is_dip)
- `transform_difficulty_data($difficulty)` - Passes through difficulty data (array of objects with question_text, correctness_rate)
- `transform_distribution_data($distribution)` - Passes through distribution data (object with high, moderate, low, none properties)

**Note:** The transformer currently acts as a pass-through layer, returning data as-is. This design allows for future transformation logic without changing the calling code in analytics.php.

**Requirements:**
- Chart.js 3.9.1+ must be loaded before module initialization
- Canvas elements must exist in DOM with correct IDs
- Hidden div with ID `analytics-chart-data` containing chart data in `data-chartdata` attribute
- Moodle's core/chartjs AMD module wrapper
- Moodle's core/str AMD module for language strings

**Browser Compatibility:**
- Modern browsers with ES5+ support
- Canvas API support required
- No IE11 support (uses arrow functions and const/let)

#### Analytics Renderer

**`\mod_classengage\output\analytics_renderer`**

Provides rendering methods for analytics page UI components with Bootstrap 4 styling and accessibility support. This renderer follows Moodle's renderer pattern and uses html_writer for all output generation.

**Features:**
- Filter toolbars with search, score ranges, and pagination controls
- Summary cards showing engagement, comprehension, and performance metrics
- Student performance tables with sorting and highlighting
- Chart containers for visualizations
- Insights panels for at-risk students and missing participants

```php
use mod_classengage\output\analytics_renderer;
use mod_classengage\analytics_filter;

$renderer = $PAGE->get_renderer('mod_classengage', 'analytics');

// Render filter toolbar
echo $renderer->render_filter_toolbar($filter, $sessions, $sessionid, $cmid);

// Render summary cards with trend indicators
echo $renderer->render_summary_cards($summary, $trends);

// Render insights panel (at-risk students and missing participants)
echo $renderer->render_insights_panel($insights, $atriskstudents, $missingparticipants);

// Render student performance table with sorting and highlighting
echo $renderer->render_student_performance_table($students, $filter, $totalcount, $cmid, $sessionid);

// Render pagination controls
$baseurl = new moodle_url('/mod/classengage/analytics.php', ['id' => $cmid, 'sessionid' => $sessionid]);
echo $renderer->render_pagination($page, $perpage, $totalcount, $baseurl);

// Render chart container for Chart.js
echo $renderer->render_chart_container('leaderboardChart', get_string('leaderboardchart', 'mod_classengage'));

// Render tab navigation for Simple/Advanced analysis
echo $renderer->render_tab_navigation('simple');

// Render Simple Analysis tab content
echo $renderer->render_simple_analysis($data);

// Render individual cards
echo $renderer->render_engagement_card($engagement);
echo $renderer->render_comprehension_card($comprehension);
echo $renderer->render_activity_counts($counts);
echo $renderer->render_responsiveness($responsiveness);
```

**Class Constants:**

The renderer defines constants for consistent styling and behavior:

**Bootstrap CSS Classes:**
```php
analytics_renderer::CLASS_CARD = 'card'
analytics_renderer::CLASS_CARD_HEADER = 'card-header'
analytics_renderer::CLASS_CARD_BODY = 'card-body'
analytics_renderer::CLASS_MARGIN_BOTTOM_2 = 'mb-2'
analytics_renderer::CLASS_MARGIN_BOTTOM_3 = 'mb-3'
analytics_renderer::CLASS_MARGIN_BOTTOM_4 = 'mb-4'
analytics_renderer::CLASS_TEXT_CENTER = 'text-center'
analytics_renderer::CLASS_TEXT_MUTED = 'text-muted'
analytics_renderer::CLASS_TEXT_WHITE = 'text-white'
```

**Bootstrap Color Classes:**
```php
analytics_renderer::COLOR_SUCCESS = 'success'
analytics_renderer::COLOR_WARNING = 'warning'
analytics_renderer::COLOR_DANGER = 'danger'
analytics_renderer::COLOR_INFO = 'info'
analytics_renderer::COLOR_PRIMARY = 'primary'
analytics_renderer::COLOR_SECONDARY = 'secondary'
```

**Engagement Levels:**
```php
analytics_renderer::LEVEL_HIGH = 'high'
analytics_renderer::LEVEL_MODERATE = 'moderate'
analytics_renderer::LEVEL_LOW = 'low'
```

**Comprehension Levels:**
```php
analytics_renderer::LEVEL_STRONG = 'strong'
analytics_renderer::LEVEL_PARTIAL = 'partial'
analytics_renderer::LEVEL_WEAK = 'weak'
```

**Responsiveness Pace:**
```php
analytics_renderer::PACE_QUICK = 'quick'
analytics_renderer::PACE_NORMAL = 'normal'
analytics_renderer::PACE_SLOW = 'slow'
```

**Level to Color Mappings:**
```php
analytics_renderer::ENGAGEMENT_COLORS = [
    'high' => 'success',
    'moderate' => 'warning',
    'low' => 'danger'
]

analytics_renderer::COMPREHENSION_COLORS = [
    'strong' => 'success',
    'partial' => 'warning',
    'weak' => 'danger'
]
```

**Pace to Icon/Color Mappings:**
```php
analytics_renderer::PACE_ICONS = [
    'quick' => '↑',
    'normal' => '→',
    'slow' => '↓'
]

analytics_renderer::PACE_COLORS = [
    'quick' => 'text-success',
    'normal' => 'text-muted',
    'slow' => 'text-warning'
]
```

**Public Methods:**

**Filter and Table Rendering:**
- `render_filter_toolbar($filter, $sessions, $sessionid, $cmid)` - Renders sticky filter toolbar with all filter controls (name search, score range, response time range, top performers checkbox, question filter, per page selector)
- `render_student_performance_table($students, $filter, $totalcount, $cmid, $sessionid)` - Renders sortable student performance table with highlighting for top performers and at-risk students
- `render_pagination($page, $perpage, $totalcount, $baseurl)` - Renders Bootstrap pagination controls with page numbers and previous/next buttons

**Summary and Insights:**
- `render_summary_cards($summary, $trends)` - Renders 4 summary cards showing participation rate, accuracy trend, response speed, and highest streak
- `render_insights_panel($insights, $atrisk, $missing)` - Renders two-column panel showing at-risk students and missing participants

**Chart Containers:**
- `render_chart_container($chartid, $title, $height = 400)` - Renders card container with canvas element for Chart.js visualization

**Two-Tab Interface:**
- `render_tab_navigation($activetab = 'simple')` - Renders Bootstrap tab navigation for Simple and Advanced analysis
- `render_simple_analysis($data)` - Renders Simple Analysis tab content with engagement, comprehension, activity counts, and responsiveness cards

**Simple Analysis Cards:**
- `render_engagement_card($engagement)` - Renders engagement level card with percentage, level indicator (high/moderate/low), and participation details
- `render_comprehension_card($comprehension)` - Renders comprehension summary card with level indicator and confused topics list
- `render_activity_counts($counts)` - Renders activity counts card showing questions answered, poll submissions, and reactions
- `render_responsiveness($responsiveness)` - Renders responsiveness indicator card with pace icon (↑/→/↓), average/median times, and variance message. Uses class constants for consistent styling and icon mapping.

**Data Structure Requirements:**

**Filter Toolbar:**
- `$filter` - analytics_filter object with validated parameters
- `$sessions` - Array of available sessions (id => name)
- `$sessionid` - Current session ID
- `$cmid` - Course module ID

**Summary Cards:**
- `$summary` - Object with: `participationrate`, `averagescore`, `avgresponsetime`, `stddev`, `higheststreak`
- `$trends` - Object with: `accuracytrend` (percentage point change from previous session)

**Insights Panel:**
- `$insights` - Question insights object (currently unused, reserved for future)
- `$atrisk` - Array of student objects with: `fullname`, `percentage`, `avgresponsetime`, `isatrisk` (bool)
- `$missing` - Array of student objects with: `fullname`

**Student Performance Table:**
- `$students` - Array of student objects with: `rank`, `fullname`, `totalresponses`, `correctresponses`, `percentage`, `avgresponsetime`, `istopperformer` (bool), `isatrisk` (bool)
- `$filter` - analytics_filter object for sort state
- `$totalcount` - Total number of filtered records
- `$cmid` - Course module ID
- `$sessionid` - Session ID

**Simple Analysis Cards:**
- `$engagement` - Object with: `percentage`, `level` ('high'|'moderate'|'low'), `message`, `unique_participants`, `total_enrolled`
- `$comprehension` - Object with: `avg_correctness`, `level` ('strong'|'partial'|'weak'), `message`, `confused_topics` (array)
- `$counts` - Object with: `questions_answered`, `poll_submissions`, `reactions`
- `$responsiveness` - Object with: `avg_time` (float), `median_time` (float), `pace` ('quick'|'normal'|'slow'), `message` (string), `variance` (float)

**Styling Features:**
- Bootstrap 4 card components with color-coded borders
- Sticky filter toolbar with `position: sticky`
- Sortable table headers with arrow indicators (↑/↓)
- Row highlighting: `.table-success` for top performers, `.table-danger` for at-risk students
- Responsive layout with Bootstrap grid system
- ARIA labels for accessibility
- Screen reader support with semantic HTML

**Accessibility:**
- All form inputs have associated labels (visible or sr-only)
- ARIA labels on interactive elements
- Semantic HTML structure (nav, table, form)
- Keyboard-accessible sortable headers
- Color contrast meets WCAG 2.1 AA standards
- Alternative text for chart containers

**Security:**
- All output escaped via html_writer methods
- User input sanitized through analytics_filter
- Parameterized database queries
- CSRF protection via sesskey

#### Analytics Filters (JavaScript)

**`mod_classengage/analytics_filters`** (AMD Module)

Provides interactive filter controls for the analytics page with debounced search, range inputs, and pagination.

```javascript
require(['mod_classengage/analytics_filters'], function(AnalyticsFilters) {
    // Initialize filter interactions
    AnalyticsFilters.init(cmid, sessionid);
});
```

**Features:**

1. **Name Search with Debouncing**
   - Input ID: `filter-namesearch`
   - 300ms debounce delay to reduce server requests
   - Automatically applies filters on input change

2. **Score Range Filters**
   - Minimum score input ID: `filter-minscore`
   - Maximum score input ID: `filter-maxscore`
   - Validates 0-100 range
   - Applies filters on change event

3. **Response Time Range Filters**
   - Minimum time input ID: `filter-mintime`
   - Maximum time input ID: `filter-maxtime`
   - Validates >= 0 seconds
   - Applies filters on change event

4. **Top Performers Checkbox**
   - Checkbox ID: `filter-toponly`
   - Shows only top 10 students when checked
   - Applies filters on change event

5. **Question Filter Dropdown**
   - Select ID: `filter-questionid`
   - Filters by specific question
   - Applies filters on change event

6. **Per Page Selector**
   - Select ID: `filter-perpage`
   - Options: 10, 25, 50, 100 records per page
   - Applies filters on change event

7. **Clear Filters Button**
   - Button ID: `clear-filters-btn`
   - Resets all filters to default values
   - Reloads page with only cmid and sessionid parameters

8. **Sortable Column Headers**
   - Add `data-sortable="columnname"` attribute to table headers
   - Click to toggle sort direction (ASC/DESC)
   - Visual indicators (▲/▼) show current sort state
   - Supported columns: fullname, totalresponses, correctresponses, percentage, avgresponsetime

9. **Pagination Controls**
   - Add `data-page="N"` attribute to pagination links
   - Click to navigate to specific page
   - Preserves all active filters during navigation

**URL Parameter Management:**

The module automatically builds URL parameters from filter values:
- `namesearch` - Student name search term
- `minscore` - Minimum score percentage (0-100)
- `maxscore` - Maximum score percentage (0-100)
- `mintime` - Minimum response time (seconds)
- `maxtime` - Maximum response time (seconds)
- `toponly` - Top performers flag (1 or omitted)
- `questionid` - Question filter ID
- `sort` - Sort column name
- `dir` - Sort direction (ASC/DESC)
- `page` - Current page number (1-based)
- `perpage` - Records per page (10/25/50/100)

**PHP Integration:**

```php
// In analytics.php
$PAGE->requires->js_call_amd('mod_classengage/analytics_filters', 'init', [$cm->id, $sessionid]);
```

**HTML Requirements:**

```html
<!-- Filter toolbar -->
<input type="text" id="filter-namesearch" placeholder="Search by name">
<input type="number" id="filter-minscore" min="0" max="100" placeholder="Min score">
<input type="number" id="filter-maxscore" min="0" max="100" placeholder="Max score">
<input type="number" id="filter-mintime" min="0" step="0.1" placeholder="Min time">
<input type="number" id="filter-maxtime" min="0" step="0.1" placeholder="Max time">
<input type="checkbox" id="filter-toponly">
<select id="filter-questionid">
    <option value="0">All questions</option>
    <option value="1">Question 1</option>
</select>
<select id="filter-perpage">
    <option value="10">10</option>
    <option value="25">25</option>
    <option value="50">50</option>
    <option value="100">100</option>
</select>
<button id="clear-filters-btn">Clear Filters</button>

<!-- Sortable table headers -->
<th data-sortable="fullname">Student Name</th>
<th data-sortable="percentage">Score</th>
<th data-sortable="avgresponsetime">Response Time</th>

<!-- Pagination links -->
<a href="#" data-page="1">1</a>
<a href="#" data-page="2">2</a>
<a href="#" data-page="3">3</a>
```

**Behavior:**

- All filter changes trigger page reload with updated URL parameters
- Current filter state is preserved in URL for bookmarking and sharing
- Sort indicators automatically update based on URL parameters
- Pagination preserves all active filters
- Debounced name search reduces server load
- Clear filters button resets to default view

**Browser Compatibility:**

- Modern browsers with ES5+ support
- URLSearchParams API for parameter handling
- addEventListener for event handling
- No external dependencies (pure JavaScript)

#### Engagement Calculator

**`\mod_classengage\engagement_calculator`**

Calculates overall engagement level based on participation metrics for the analytics enhancement. Results are cached for 5 minutes to improve performance.

```php
use mod_classengage\engagement_calculator;

$calculator = new engagement_calculator($sessionid, $courseid);

// Calculate engagement level
$engagement = $calculator->calculate_engagement_level();
// Returns: {percentage, level, message, unique_participants, total_enrolled}
// - percentage: 0-100 (unique responding students / enrolled students * 100)
// - level: 'high' (>75%), 'moderate' (40-75%), or 'low' (<40%)
// - message: Localized message with percentage
// - unique_participants: Count of students who responded
// - total_enrolled: Count of enrolled students with takequiz capability

// Get activity counts
$counts = $calculator->get_activity_counts();
// Returns: {questions_answered, poll_submissions, reactions}
// - questions_answered: Total response count for session
// - poll_submissions: Poll response count (0 for now, future feature)
// - reactions: Reaction count (0 for now, future feature)

// Get responsiveness indicator
$responsiveness = $calculator->get_responsiveness_indicator();
// Returns: {avg_time, median_time, pace, message, variance}
// - avg_time: Average response time in seconds
// - median_time: Median response time in seconds
// - pace: 'quick' (avg < median), 'slow' (avg > median), or 'normal' (avg = median)
// - message: Localized message with consistency indicator
// - variance: Standard deviation of response times
```

**Class Constants:**

```php
engagement_calculator::HIGH_ENGAGEMENT_THRESHOLD = 75.0;      // High engagement threshold (%)
engagement_calculator::MODERATE_ENGAGEMENT_THRESHOLD = 40.0;  // Moderate engagement threshold (%)
engagement_calculator::VARIANCE_THRESHOLD_MULTIPLIER = 0.5;   // Variance threshold multiplier
engagement_calculator::CACHE_DURATION = 300;                  // Cache duration (5 minutes)
```

**Engagement Level Calculation:**

Formula: `(Unique responding students / Enrolled students) * 100`

Thresholds:
- **High engagement** (>75%): Green styling, positive message
- **Moderate engagement** (40-75%): Yellow styling, neutral message
- **Low engagement** (<40%): Red styling, attention-drawing message

**Responsiveness Calculation:**

Compares average response time to session median:
- **Quick pace**: Average < Median (students responding faster than typical)
- **Normal pace**: Average = Median (typical response speed)
- **Slow pace**: Average > Median (students responding slower than typical)

Variance indicator:
- **Consistent engagement**: Variance < (Mean × 0.5) - steady participation
- **Fluctuating engagement**: Variance >= (Mean × 0.5) - variable attention levels

**Performance & Caching:**

- Results are cached for 5 minutes using Moodle's cache API (`response_stats` cache)
- Cache keys: `engagement_level_{sessionid}`, `activity_counts_{sessionid}`
- Cache gracefully degrades if not configured (continues without caching)
- Uses optimized `count_enrolled_users()` instead of `get_enrolled_users()` for better performance

**Use Cases:**

1. **Simple Analysis Tab** - Display overall engagement snapshot
2. **Teaching Recommendations** - Generate suggestions based on engagement patterns
3. **Session Comparison** - Compare engagement across multiple sessions
4. **Real-time Monitoring** - Track engagement during active sessions

**Requirements:**
- Session must have at least one response for meaningful calculations
- Course context must be valid for enrollment queries
- Students must have `mod/classengage:takequiz` capability to be counted as enrolled
- Cache definition `response_stats` should be configured in `db/caches.php`

#### Constants

**`\mod_classengage\constants`**

Provides plugin-wide constants for consistency.

```php
use mod_classengage\constants;

// Session status
constants::SESSION_STATUS_READY
constants::SESSION_STATUS_ACTIVE
constants::SESSION_STATUS_PAUSED
constants::SESSION_STATUS_COMPLETED

// Question status
constants::QUESTION_STATUS_PENDING
constants::QUESTION_STATUS_APPROVED
constants::QUESTION_STATUS_REJECTED

// Actions
constants::ACTION_NEXT
constants::ACTION_STOP
constants::ACTION_PAUSE
constants::ACTION_RESUME

// Defaults
constants::DEFAULT_POLLING_INTERVAL  // 1000ms
constants::DEFAULT_NUM_QUESTIONS     // 10
constants::DEFAULT_TIME_LIMIT        // 30 seconds
```

#### Chart Colors

**`\mod_classengage\chart_colors`**

Centralizes color definitions for analytics charts to enable easy theme customization and ensure WCAG AA compliance.

```php
use mod_classengage\chart_colors;

// Difficulty level colors
$hardColor = chart_colors::DIFFICULTY_HARD;         // '#dc3545' (red)
$moderateColor = chart_colors::DIFFICULTY_MODERATE; // '#ffc107' (yellow)
$easyColor = chart_colors::DIFFICULTY_EASY;         // '#28a745' (green)

// Get color for difficulty level
$color = chart_colors::get_difficulty_color('difficult');  // Returns '#dc3545'
$color = chart_colors::get_difficulty_color('moderate');   // Returns '#ffc107'
$color = chart_colors::get_difficulty_color('easy');       // Returns '#28a745'

// Participation distribution colors
$highColor = chart_colors::PARTICIPATION_HIGH;       // '#28a745' (green)
$moderateColor = chart_colors::PARTICIPATION_MODERATE; // '#007bff' (blue)
$lowColor = chart_colors::PARTICIPATION_LOW;         // '#ffc107' (yellow)
$noneColor = chart_colors::PARTICIPATION_NONE;       // '#6c757d' (gray)

// Get all participation colors as array
$colors = chart_colors::get_participation_colors();
// Returns: ['#28a745', '#007bff', '#ffc107', '#6c757d']
```

**Class Constants:**

**Difficulty Colors:**
```php
chart_colors::DIFFICULTY_HARD = '#dc3545'      // Red for difficult concepts (<50% correctness)
chart_colors::DIFFICULTY_MODERATE = '#ffc107'  // Yellow for moderate difficulty (50-70%)
chart_colors::DIFFICULTY_EASY = '#28a745'      // Green for easy concepts (>70%)
```

**Participation Colors:**
```php
chart_colors::PARTICIPATION_HIGH = '#28a745'      // Green for high participation (5+ responses)
chart_colors::PARTICIPATION_MODERATE = '#007bff'  // Blue for moderate participation (2-4 responses)
chart_colors::PARTICIPATION_LOW = '#ffc107'       // Yellow for low participation (1 response)
chart_colors::PARTICIPATION_NONE = '#6c757d'      // Gray for no participation (0 responses)
```

**Public Methods:**

- `get_difficulty_color($level)` - Returns hex color code for difficulty level ('difficult', 'moderate', or 'easy')
- `get_participation_colors()` - Returns array of hex color codes for participation distribution [high, moderate, low, none]

**Use Cases:**

1. **Chart.js Integration** - Use in JavaScript chart configurations for consistent colors
2. **PHP Rendering** - Apply colors to HTML elements in analytics renderer
3. **Theme Customization** - Centralized location for updating chart colors across the plugin
4. **Accessibility** - All colors meet WCAG AA contrast requirements

**Integration Example:**

```php
// In analytics_renderer.php
use mod_classengage\chart_colors;

// Render concept difficulty with color coding
foreach ($concepts as $concept) {
    $color = chart_colors::get_difficulty_color($concept->difficulty_level);
    echo html_writer::div($concept->question_text, '', [
        'style' => 'border-left: 4px solid ' . $color
    ]);
}

// Pass colors to JavaScript for Chart.js
$chartdata = [
    'difficulty' => $difficultydata,
    'colors' => [
        'easy' => chart_colors::DIFFICULTY_EASY,
        'moderate' => chart_colors::DIFFICULTY_MODERATE,
        'hard' => chart_colors::DIFFICULTY_HARD
    ]
];
$PAGE->requires->js_call_amd('mod_classengage/analytics_charts', 'init', [json_encode($chartdata)]);
```

**Color Scheme Rationale:**

- **Red (#dc3545)**: Indicates difficulty or areas needing attention (Bootstrap danger color)
- **Yellow (#ffc107)**: Indicates moderate concern or caution (Bootstrap warning color)
- **Green (#28a745)**: Indicates success or well-understood concepts (Bootstrap success color)
- **Blue (#007bff)**: Indicates neutral information (Bootstrap primary color)
- **Gray (#6c757d)**: Indicates inactive or no data (Bootstrap secondary color)

All colors are chosen from Bootstrap 4's standard palette for consistency with Moodle's UI and to ensure proper contrast ratios for accessibility.

### Running Tests

```bash
# Run PHPUnit tests
cd /path/to/moodle
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit mod/classengage/tests/

# Check code style
vendor/bin/phpcs --standard=moodle mod/classengage/
```

### Load Testing

ClassEngage includes a comprehensive load testing script to verify performance under concurrent user load:

```bash
# Test API with 50 concurrent users (default)
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2

# Test with 100 concurrent users
php mod/classengage/tests/load_test_api.php --users=100 --sessionid=1 --courseid=2

# With cleanup (removes test users after completion)
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --cleanup

# Add delay between requests (100ms)
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --delay=100

# Verbose output for debugging
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --verbose

# View help
php mod/classengage/tests/load_test_api.php --help
```

**What the script does:**

1. **Creates test users** - Generates N test users with credentials
2. **Enrolls users** - Enrolls all users in the specified course as students
3. **Generates token** - Creates web service token for API authentication
4. **Gets current question** - Retrieves the active question from the session
5. **Simulates responses** - Sends concurrent POST requests using curl_multi
6. **Verifies integrity** - Checks database for correct response count
7. **Cleanup** - Optionally removes all test users and data

**Performance Metrics:**
- Total requests sent
- Success/failure count and rate
- Total execution time
- Throughput (requests/second)
- Average response time (milliseconds)

**Requirements:**
- Active quiz session (status must be 'active')
- Web services enabled in Moodle
- Admin access for token generation
- PHP curl extension with curl_multi support

**Options:**
- `--users=N` - Number of concurrent users (1-1000, default: 50)
- `--sessionid=N` - Session ID to test (required)
- `--courseid=N` - Course ID for enrollment (required)
- `--delay=N` - Delay in milliseconds between requests (default: 0)
- `--cleanup` - Remove test users after completion
- `--verbose` - Show detailed output for each request

**Debugging Features:**
- Automatic debug output for first failed request
- Displays HTTP status codes and raw API responses
- Extracts error messages from JSON and XML responses
- Shows up to 10 unique error messages in summary
- Sample request details (URL and POST data) in verbose mode
- Database integrity verification

**Automated Test Suite:**

Run multiple tests with increasing load:
```bash
# Linux/Mac
chmod +x mod/classengage/tests/run_load_tests.sh
./mod/classengage/tests/run_load_tests.sh

# Windows
mod\classengage\tests\run_load_tests.bat
```

This runs tests with 10, 25, 50, 75, and 100 users, saving results to a timestamped file.

**Example Output:**
```
ClassEngage Load Test
Session ID: 1
Course ID: 2
Number of users: 50
Cleanup after test: Yes
Delay between requests: 0ms

Step 1: Creating test users
Created 50 users in 2.34 seconds

Step 2: Enrolling users in course
Enrolled 50 users in 1.12 seconds

Step 3: Generating web service token
Token generated successfully

Step 4: Getting current question
Current question: What is the capital of France?...
Correct answer: B

Step 5: Simulating concurrent clicker responses

Results
Total requests: 50
Successful: 50
Failed: 0
Success rate: 100.00%
Total time: 0.87 seconds
Throughput: 57.47 requests/second
Average response time: 17.40 ms

Step 6: Verifying database integrity
Responses in database: 50
✓ Database integrity verified

Step 7: Cleaning up test users
Cleaned up 50 users in 1.56 seconds

Load test completed
```

**For detailed documentation**, see [tests/LOAD_TESTING.md](tests/LOAD_TESTING.md)

### Debugging

Enable Moodle debugging:

```
Site Administration → Development → Debugging
Debug messages: DEVELOPER
Display debug messages: Yes
```

View logs:

```
Site Administration → Reports → Logs
```

## Troubleshooting

### Plugin Installation Issues

**Problem:** Plugin not detected

```bash
# Check file structure
ls -la mod/classengage/version.php

# Check permissions
sudo chown -R www-data:www-data mod/classengage
sudo chmod -R 755 mod/classengage

# Clear Moodle cache
php admin/cli/purge_caches.php
```

**Problem:** Database errors

```bash
# Check database connection
php admin/cli/check_database_schema.php

# Manually run install
mysql -u moodle_user -p moodle_db < mod/classengage/db/install.xml
```

### NLP Service Issues

**Problem:** Connection failed

```
Error: NLP service connection failed
```

**Solution:**
1. Verify NLP service is running (see separate repo)
2. Check endpoint URL in Moodle settings
3. Test service: `curl http://localhost:3000/health`
4. Check firewall rules if on different server

For NLP service troubleshooting, see:
**See:** https://github.com/cong-minhh/classengage-nlp-service

### File Upload Issues

**Problem:** Upload fails

```bash
# Check PHP limits
php -i | grep upload_max_filesize
php -i | grep post_max_size

# Check Moodle limits
# Site Administration → Security → Site policies → Maximum uploaded file size

# Check disk space
df -h
```

### AJAX/Real-time Issues

**Problem:** Updates not working

```
1. Check browser console for errors (F12)
   - Debug logging is enabled in controlpanel.js
   - Look for "Updating display with data:" messages
   - Check for "Updating distribution:" and "Updating chart" logs
   - Verify data structure matches expected format
2. Verify polling is enabled in settings
3. Clear browser cache (Ctrl+Shift+Delete)
4. Check $CFG->wwwroot in config.php
5. Disable browser extensions temporarily
```

**Debug Logging:**

The control panel JavaScript includes comprehensive debug logging to help troubleshoot real-time updates. Open your browser's developer console (F12) to see:

- `Control panel init called with sessionId: X interval: Y` - Shows initialization parameters
- `Updating display with data:` - Shows all incoming AJAX response data
- `Updating distribution:` - Shows answer distribution being processed
- `Updating chart` / `Chart not initialized` - Shows chart update status
- `Option A/B/C/D - count: X percentage: Y` - Shows per-option calculations

These logs help identify issues with:
- Module initialization and parameter passing
- AJAX polling and data retrieval
- Response distribution calculations
- Chart.js initialization and updates
- DOM element availability

### Performance Issues

**Problem:** Slow quiz sessions

```bash
# Increase polling interval
# Site Administration → Plugins → ClassEngage
# Set Polling Interval: 2000 (or higher)

# Enable Moodle caching
# Site Administration → Plugins → Caching

# Check server resources
top
htop
```

## Capabilities

| Capability | Teacher | Student | Description |
|---|---|---|---|
| `mod/classengage:addinstance` | Yes | No | Add activity to course |
| `mod/classengage:view` | Yes | Yes | View activity |
| `mod/classengage:uploadslides` | Yes | No | Upload slides |
| `mod/classengage:managequestions` | Yes | No | Manage questions |
| `mod/classengage:configurequiz` | Yes | No | Configure sessions |
| `mod/classengage:startquiz` | Yes | No | Start/stop sessions |
| `mod/classengage:takequiz` | No | Yes | Participate in quizzes |
| `mod/classengage:viewanalytics` | Yes | No | View analytics |
| `mod/classengage:grade` | Yes | No | Grade responses |

## Privacy & GDPR

This plugin implements Moodle's Privacy API (GDPR compliant):

- **Data Stored:** Quiz responses, scores, timestamps
- **Data Export:** Users can export their quiz data
- **Data Deletion:** Users can request data deletion
- **Retention:** Follows Moodle's data retention policies

Configure at: **Site Administration → Users → Privacy and policies**

## Deployment Checklist

### Pre-Deployment

- [ ] Backup Moodle database
- [ ] Backup moodledata files
- [ ] Test on staging environment
- [ ] Review PHP error logs
- [ ] Check disk space
- [ ] Verify PHP version and extensions

### Deployment

- [ ] Put site in maintenance mode
- [ ] Upload plugin files
- [ ] Set correct permissions
- [ ] Run database upgrade
- [ ] Configure plugin settings
- [ ] Set up NLP service (if using)
- [ ] Test functionality
- [ ] Disable maintenance mode

### Post-Deployment

- [ ] Monitor error logs
- [ ] Test with real users
- [ ] Check performance metrics
- [ ] Verify backups working
- [ ] Document any customizations

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see <https://www.gnu.org/licenses/>.

## Credits

**Developed by:** [Your Name/Organization]  
**Copyright:** 2025  
**Version:** 1.1.0-alpha (2025110308)  
**Moodle Version:** 4.0+

### Version History

- **1.1.0-alpha (2025110308)** - Enhanced analytics with filtering, sorting, pagination, insights, and visualizations
- **1.0.0** - Initial release with basic quiz functionality

### Powered By

- **Google Gemini AI** - Question generation
- **Chart.js** - Analytics visualization
- **Moodle** - Learning management platform

## Related Repositories

- **NLP Service:** https://github.com/cong-minhh/classengage-nlp-service
  Node.js service for AI-powered question generation

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Code Standards

- Follow [Moodle Coding Style](https://docs.moodle.org/dev/Coding_style)
- Add PHPDoc comments to all functions
- Write unit tests for new features
- Update documentation as needed

## Support

- **Documentation:** This README
- **Moodle Forums:** https://moodle.org/forums/
- **Issue Tracker:** GitHub Issues
- **Email:** your.email@example.com

## Roadmap

### Version 1.1 (Planned)

- [ ] Multiple question types (True/False, Short Answer)
- [ ] Question bank integration
- [ ] Mobile app support
- [ ] Advanced analytics (heat maps, trends)
- [ ] Gamification (badges, leaderboards)

### Version 2.0 (Future)

- [ ] Video slide support
- [ ] Live polling and surveys
- [ ] Breakout rooms
- [ ] AI-powered personalized learning paths
- [ ] Integration with external LTI tools

## Acknowledgments

Special thanks to:
- The Moodle community
- Google AI for Gemini API
- All contributors and testers

---

**Made for educators and students worldwide**



## Export Analytics

ClassEngage provides CSV export functionality for analytics data, allowing teachers to download session insights for reporting or offline analysis.

### Export Features

The export functionality (`export.php`) generates a CSV file containing:

**Session Information:**
- Session name
- Completion date and time

**Engagement Metrics:**
- Engagement percentage (0-100%)
- Engagement level (high/moderate/low)

**Comprehension Data:**
- Comprehension summary message
- List of confused topics (if any)

**Activity Counts:**
- Questions answered (total response count)
- Poll submissions (placeholder for future feature)
- Reactions/clicks (placeholder for future feature)

**Responsiveness:**
- Pace indicator (quick/normal/slow)
- Average response time in seconds

**Teaching Insights:**
- List of difficult concepts with correctness rates
- Teaching recommendations (up to 5 prioritized suggestions)

### Privacy Compliance

The export functionality is designed with privacy in mind:
- **No individual student names** are included in the export
- **No identifiable information** is exported
- Only **class-level aggregated data** is included
- Complies with GDPR and educational privacy standards

### Usage

**From Analytics Page:**

1. Navigate to the Analytics tab
2. Select the session you want to export
3. Click the "Export Analytics" button at the bottom of the page
4. A CSV file will be downloaded automatically

**Direct URL:**

```
/mod/classengage/export.php?id={cmid}&sessionid={sessionid}
```

**Parameters:**
- `id` (required) - Course module ID
- `sessionid` (required) - Session ID to export

### CSV Format

The exported CSV file includes:

**Filename Format:**
```
{ActivityName}_{SessionName}_analytics_{YYYY-MM-DD}.csv
```

**Example:**
```
Lecture_5_Quiz_Session_1_analytics_2025-11-22.csv
```

**CSV Structure:**

| Column | Description | Example |
|--------|-------------|---------|
| Session Name | Name of the quiz session | "Lecture 5 Live Quiz" |
| Completed Date | When session was completed | "2025-11-22 14:30" |
| Engagement Percentage | Participation rate | "85.5%" |
| Engagement Level | high/moderate/low | "high" |
| Comprehension Summary | Class understanding message | "Most students understood the core concepts" |
| Questions Answered | Total response count | "245" |
| Poll Submissions | Poll response count | "0" |
| Reactions | Reaction count | "0" |
| Responsiveness Pace | quick/normal/slow | "quick" |
| Average Response Time | Mean response time | "12.3s" |
| Difficult Concepts | List of challenging topics | "Quantum mechanics (42.3%); Wave functions (38.1%)" |
| Teaching Recommendations | Actionable suggestions | "Consider additional examples for Topic X; Interactive activities boosted engagement" |

**Special Values:**
- Empty lists show as "None"
- Semicolons (`;`) separate multiple items in list fields
- HTML tags are stripped from all text content
- UTF-8 BOM included for Excel compatibility

### Security

**Capability Required:**
- `mod/classengage:viewanalytics` - User must have permission to view analytics

**Validation:**
- Session must belong to the specified activity
- User must be logged in and enrolled in the course
- All parameters are validated and sanitized

**Data Protection:**
- No SQL injection vulnerabilities (parameterized queries)
- No XSS vulnerabilities (all output escaped)
- Session validation prevents unauthorized access

### Technical Implementation

**File:** `mod/classengage/export.php`

**Dependencies:**
- `engagement_calculator` - Calculates engagement metrics
- `comprehension_analyzer` - Analyzes class comprehension
- `teaching_recommender` - Generates teaching suggestions

**Process Flow:**

1. **Validate Parameters** - Check cmid and sessionid
2. **Security Checks** - Verify login and capability
3. **Calculate Analytics** - Compute all metrics using analytics classes
4. **Generate CSV** - Build CSV with proper headers and encoding
5. **Stream Output** - Send file directly to browser for download

**Performance:**
- Uses cached analytics data (5-minute cache)
- Minimal database queries
- Efficient CSV generation with `fputcsv()`
- Streams output directly (no temporary files)

**Error Handling:**
- Invalid session: Throws `invalidsession` exception
- Analytics calculation failure: Throws `analyticsfailed` exception
- Missing capability: Displays access denied error
- All errors logged for debugging

### Integration Example

**Add Export Button to Custom Page:**

```php
// In your custom analytics page
$exporturl = new moodle_url('/mod/classengage/export.php', [
    'id' => $cm->id,
    'sessionid' => $sessionid
]);

echo html_writer::link(
    $exporturl,
    get_string('exportanalytics', 'mod_classengage'),
    ['class' => 'btn btn-secondary']
);
```

**Programmatic Export (for automated reports):**

```php
// Generate export URL for external systems
$exporturl = new moodle_url('/mod/classengage/export.php', [
    'id' => $cmid,
    'sessionid' => $sessionid
]);

// Add to report or email
$message = "Download analytics: " . $exporturl->out(false);
```

### Troubleshooting

**Issue: CSV file is empty**
- Check that the session has completed (status = 'completed')
- Verify analytics data exists for the session
- Check PHP error logs for calculation failures

**Issue: Excel shows garbled characters**
- File includes UTF-8 BOM for Excel compatibility
- Try opening with "Import Data" in Excel and select UTF-8 encoding

**Issue: Download doesn't start**
- Check browser popup blocker settings
- Verify user has `mod/classengage:viewanalytics` capability
- Check that session belongs to the specified activity

**Issue: "Analytics failed" error**
- Check that all required analytics classes are installed
- Verify database tables exist and are accessible
- Check Moodle error logs for detailed error messages
- Ensure cache is properly configured (gracefully degrades if not)
