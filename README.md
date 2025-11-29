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

### Deep Learning Analytics
Gain actionable insights with our comprehensive analytics dashboard:
- **Concept Difficulty Analysis**: Identify which topics students find most challenging.
- **At-Risk Student Detection**: Early warning system for students who may be falling behind.
- **Response Trends**: Visualize class performance over time to spot engagement dips.
- **Teaching Recommendations**: Receive automated suggestions on areas that may need re-teaching based on class performance.

### Flexible Participation
- **Web Interface**: Students can participate using any device with a browser (laptop, tablet, phone).
- **Clicker Integration**: Native support for physical clicker devices via our REST API, perfect for environments with limited connectivity or strict device policies.

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

### Load Testing
The plugin includes a script to verify performance under load.

**Usage:**
```bash
php mod/classengage/tests/load_test_api.php --users=50 --sessionid=1 --courseid=2 --cleanup
```

**Parameters:**
- `--users`: Number of concurrent users to simulate.
- `--sessionid`: The ID of the active ClassEngage session.
- `--courseid`: The ID of the course.
- `--cleanup`: Automatically remove test users and data after the test.

## License

This project is licensed under the GNU General Public License v3 or later.
