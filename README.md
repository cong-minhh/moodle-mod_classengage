# ClassEngage - AI-Powered In-Class Learning Engagement for Moodle

[![Moodle](https://img.shields.io/badge/Moodle-4.0%2B-orange)](https://moodle.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v3-green)](https://www.gnu.org/licenses/gpl-3.0)

## Features

- **Slide Upload** - Upload PDF, PPT, PPTX, DOC, DOCX lecture slides
- **AI Question Generation** - Automatically generate quiz questions using Google Gemini AI
- **Question Management** - Review, edit, and approve AI-generated questions
- **Live Quiz Sessions** - Conduct real-time interactive quizzes with instant feedback
- **Clicker Integration** - Full Web Services API for classroom clicker devices (A/B/C/D keypads)
- **Analytics Dashboard** - Comprehensive performance analytics and reporting
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

- **Moodle:** 4.0 or later
- **PHP:** 7.4 or later
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
2. Control Panel opens
3. Students can now join
4. Click "Next Question" to advance
5. Monitor live responses
6. Click "Stop Session" when done
```

#### 6. View Analytics

```
1. Click "Analytics" tab
2. Select completed session
3. View:
   - Average score
   - Participation rate
   - Question breakdown
   - Individual performance
4. Export to CSV if needed
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

```bash
curl -X POST "https://your-moodle.edu/webservice/rest/server.php" \
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
- extractedtext (longtext)
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

### Running Tests

```bash
# Run PHPUnit tests
cd /path/to/moodle
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit mod/classengage/tests/

# Check code style
vendor/bin/phpcs --standard=moodle mod/classengage/
```

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
2. Verify polling is enabled in settings
3. Clear browser cache (Ctrl+Shift+Delete)
4. Check $CFG->wwwroot in config.php
5. Disable browser extensions temporarily
```

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
**Version:** 1.0.0-alpha  
**Moodle Version:** 4.0+

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

