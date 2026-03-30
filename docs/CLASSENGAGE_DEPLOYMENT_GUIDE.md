# ClassEngage Plugin - Complete Deployment Guide

**Version:** 2.0 Enterprise Edition  
**For:** Large University Moodle Deployments  
**Date:** March 2026  

---

> Compatibility note: ClassEngage is compatible with both standard Moodle and Dockerized Moodle. Docker is optional. For the current runtime requirements, use `SETUP_STANDARD.md`, `SETUP_DOCKER.md`, `COMPATIBILITY.md`, and `php mod/classengage/cli/runtime_check.php` as the source of truth.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture Deep Dive](#2-architecture-deep-dive)
3. [Understanding Your Deployment](#3-understanding-your-deployment)
4. [Docker-Based Deployment](#4-docker-based-deployment)
5. [Traditional Server Deployment](#5-traditional-server-deployment)
6. [AI Provider Configuration](#6-ai-provider-configuration)
7. [Production Scaling for Large Universities](#7-production-scaling-for-large-universities)
8. [Monitoring and Health Checks](#8-monitoring-and-health-checks)
9. [Troubleshooting](#9-troubleshooting)
10. [IT Team Handoff](#10-it-team-handoff-checklist)

---

## 1. Overview

### What is ClassEngage?

ClassEngage is a Moodle activity module that enables:

- **AI-Powered Question Generation**: Automatically generate quiz questions from lecture slides (PDF/PPTX)
- **Real-Time Engagement**: Live polling with instant feedback for classroom interaction
- **Analytics Dashboard**: Track student comprehension and engagement metrics
- **Enterprise Ready**: Designed for large-scale university deployments

### Key Features for Universities

| Feature | Benefit for Large Universities |
|---------|-------------------------------|
| High-Performance Worker | 2-5 second response time (vs 1-2 minutes with cron) |
| Multiple AI Providers | Fallback support ensures reliability |
| Scalable Architecture | Multiple workers can run simultaneously |
| Real-Time Sessions | Live engagement with 500+ concurrent users |
| Comprehensive Analytics | Data-driven insights into student performance |

### System Requirements

#### Minimum Requirements
- **Moodle**: 4.0 or higher
- **PHP**: 8.1 or higher
- **MySQL**: 5.7+ or MariaDB 10.3+

#### Required PHP Extensions
```
mbstring      - Text processing
iconv         - Character encoding
zlib          - Compression
curl          - AI API calls
json          - JSON processing
gd            - Image processing
imagick       - PDF image rendering (recommended)
openssl       - HTTPS/API security
```

#### Optional (for optimal performance)
```
pdftotext     - External PDF text extraction
poppler-utils - PDF processing utilities
```

---

## 2. Architecture Deep Dive

### System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           UNIVERSITY NETWORK                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │                         Load Balancer (Optional)                       │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│                                    │                                         │
│          ┌─────────────────────────┼─────────────────────────┐               │
│          │                         │                         │               │
│          ▼                         ▼                         ▼               │
│  ┌──────────────┐          ┌──────────────┐          ┌──────────────┐      │
│  │  Webserver 1 │          │  Webserver 2 │          │  Webserver N │      │
│  │  (Apache/    │          │  (Apache/    │          │  (Apache/    │      │
│  │   Nginx)    │          │   Nginx)     │          │   Nginx)     │      │
│  │             │          │             │          │             │      │
│  │ • PHP-FPM   │          │ • PHP-FPM   │          │ • PHP-FPM   │      │
│  │ • Handles   │          │ • Handles   │          │ • Handles   │      │
│  │   HTTP req  │          │   HTTP req  │          │   HTTP req  │      │
│  └──────────────┘          └──────────────┘          └──────────────┘      │
│           │                       │                         │                │
│           └───────────────────────┼─────────────────────────┘                │
│                                   │                                          │
│  ┌────────────────────────────────┼───────────────────────────────────────┐ │
│  │                     SHARED STORAGE                                      │ │
│  │  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐    │ │
│  │  │  Moodle Code   │    │   Moodledata   │    │   Plugin Files  │    │ │
│  │  │  (/var/www/    │    │   (/moodledata)│    │   (mod/         │    │ │
│  │  │   html)        │    │                 │    │    classengage) │    │ │
│  │  └─────────────────┘    └─────────────────┘    └─────────────────┘    │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│                                   │                                          │
│                                   ▼                                          │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │                         DATABASE (MySQL)                               │ │
│  │  ┌─────────────────────────────────────────────────────────────────┐  │ │
│  │  │ Tables:                                                         │  │ │
│  │  │ • mdl_classengage           (Main activity instances)           │  │ │
│  │  │ • mdl_classengage_slides    (Uploaded slides with NLP status)  │  │ │
│  │  │ • mdl_classengage_questions (Generated quiz questions)         │  │ │
│  │  │ • mdl_classengage_sessions  (Quiz sessions)                   │  │ │
│  │  │ • mdl_task_adhoc            (Task queue for NLP processing)    │  │ │
│  │  └─────────────────────────────────────────────────────────────────┘  │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│                                   │                                          │
│                                   ▼                                          │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │                    TASK PROCESSING LAYER                               │ │
│  │                                                                        │ │
│  │  ┌─────────────────────────────────┐  ┌─────────────────────────────┐  │ │
│  │  │  WORKER CONTAINER(S)           │  │  CRON CONTAINER             │  │ │
│  │  │  (Primary - High Performance)  │  │  (Backup - Standard)        │  │ │
│  │  │                                 │  │                             │  │ │
│  │  │  • Polls every 2 seconds       │  │  • Runs every 60 seconds    │  │ │
│  │  │  • Immediate task execution     │  │  • Processes other tasks   │  │ │
│  │  │  • 2-5 second NLP response     │  │  • Catches missed tasks     │  │ │
│  │  │  • Scalable (multiple workers) │  │  • Provides redundancy      │  │ │
│  │  └─────────────────────────────────┘  └─────────────────────────────┘  │ │
│  │           │                                      │                     │ │
│  │           └──────────────────┬─────────────────┘                     │ │
│  │                              │                                          │ │
│  └──────────────────────────────┼──────────────────────────────────────────┘│
│                                  │                                            │
│                                  ▼                                            │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │                        AI PROVIDERS (External)                         │ │
│  │                                                                        │ │
│  │   ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐            │ │
│  │   │ Google   │  │ OpenAI   │  │Anthropic │  │ DeepSeek │  ...        │ │
│  │   │ Gemini   │  │  GPT-4   │  │ Claude   │  │          │            │ │
│  │   └──────────┘  └──────────┘  └──────────┘  └──────────┘            │ │
│  │                                                                        │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     COMPLETE DATA FLOW - QUESTION GENERATION                  │
└─────────────────────────────────────────────────────────────────────────────┘

USER (Instructor)
     │
     │ 1. Creates ClassEngage activity in course
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ MOODLE DATABASE - mdl_classengage                                            │
│ • Creates new activity record                                                 │
│ • Sets activity name, course, settings                                        │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 2. Uploads lecture slides (PDF/PPTX)
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ MOODLE FILESYSTEM - Moodledata                                               │
│ • Stores uploaded file in: /moodledata/filedir/                              │
│ • Creates file record in: mdl_files                                           │
│ • Creates slide record in: mdl_classengage_slides                             │
│   - status: 'uploaded'                                                       │
│   - nlp_job_status: 'idle'                                                  │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 3. Clicks "Generate Questions" button
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ WEB BROWSER - AJAX Request                                                   │
│ • JavaScript sends request to: /mod/classengage/slides_api.php               │
│ • Includes: classengageid, slideid, generation options                        │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 4. API receives request, queues task
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ MOODLE API (slides_api.php)                                                 │
│ • Validates request parameters                                               │
│ • Creates adhoc task record in: mdl_task_adhoc                                │
│   - classname: '\mod_classengage\task\generate_nlp_task'                   │
│   - component: 'mod_classengage'                                              │
│   - customdata: JSON {slideid, classengageid, contextid, options}           │
│ • Updates slide status: nlp_job_status = 'pending'                           │
│ • Returns task ID to browser                                                  │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 5. Browser polls for status updates (every 1-2 seconds)
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ MOODLE API (slides_api.php - nlpstatus action)                              │
│ • Queries mdl_classengage_slides for current status                          │
│ • Returns: status, progress (0-100%), error message                          │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 6. Meanwhile... Worker polls database
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ WORKER CONTAINER                                                             │
│                                                                              │
│ Loop every 2 seconds:                                                        │
│   │                                                                           │
│   ▼                                                                           │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ SELECT * FROM mdl_task_adhoc                                             │ │
│ │ WHERE component = 'mod_classengage'                                      │ │
│ │ AND (nextruntime IS NULL OR nextruntime <= NOW())                         │ │
│ │ ORDER BY id ASC LIMIT 1                                                   │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        │ Found task?                                         │
│                        ▼                                                      │
│              ┌─────────────────┐                                             │
│              │     YES         │                                             │
│              └─────────────────┘                                             │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ UPDATE mdl_classengage_slides                                            │ │
│ │ SET nlp_job_status = 'running', nlp_job_progress = 10                   │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ PDF TEXT EXTRACTION (15%)                                                │ │
│ │ • Reads file from moodledata                                             │ │
│ │ • Extracts text using PdfParser/Imagick                                   │ │
│ │ • Handles multiple pages                                                  │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ IMAGE RENDERING (40%)                                                     │ │
│ │ • Renders PDF pages to images                                             │ │
│ │ • Extracts embedded images                                                 │ │
│ │ • Creates visual context for questions                                    │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ AI QUESTION GENERATION (60%)                                             │ │
│ │                                                                             │ │
│ │  ┌─────────────────────────────────────────────────────────────────────┐ │ │
│ │  │ AI Provider Selection (in priority order):                           │ │ │
│ │  │                                                                     │ │ │
│ │  │ 1. Google Gemini (default)                                          │ │ │
│ │  │ 2. OpenAI GPT-4 (fallback)                                          │ │ │
│ │  │ 3. Anthropic Claude (fallback)                                      │ │ │
│ │  │ 4. DeepSeek (fallback)                                              │ │ │
│ │  │ 5. Local/Ollama (fallback)                                          │ │ │
│ │  └─────────────────────────────────────────────────────────────────────┘ │ │
│ │                                                                             │ │
│ │  • Sends extracted text + images to AI                                  │ │
│ │  • Specifies question types: MCQ, True/False, Short Answer               │ │
│ │  • Requests Bloom's taxonomy levels: Remember, Understand, Apply         │ │
│ │  • Includes difficulty distribution: Easy 40%, Medium 40%, Hard 20%    │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ STORE QUESTIONS (90%)                                                     │ │
│ │ • Parses AI response (JSON format)                                        │ │
│ │ • Creates question records in: mdl_classengage_questions                  │ │
│ │   - questiontext, options, correctanswer                                 │ │
│ │   - difficulty, bloomlevel, rationale                                     │ │
│ │   - status: 'pending' (for instructor review)                            │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                        │                                                      │
│                        ▼                                                      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ FINALIZE (100%)                                                           │ │
│ │ • UPDATE slide status: nlp_job_status = 'completed'                      │ │
│ │ • SET: nlp_questions_count, nlp_job_completed                           │ │
│ │ • Purge Moodle caches (forces immediate UI update)                        │ │
│ │ • Trigger event: questions_generated                                      │ │
│ │ • DELETE task record from mdl_task_adhoc                                 │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
     │
     │ 7. User refreshes page
     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ MOODLE UI - Questions Page                                                   │
│ • Displays generated questions                                               │
│ • Instructor can edit, approve, or reject questions                          │
│ • Approved questions available for quiz sessions                             │
└─────────────────────────────────────────────────────────────────────────────┘

TIME ELAPSED: 2-5 seconds (small PDF) to 5-15 seconds (large PDF)
```

### Database Schema Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           KEY TABLES FOR CLASSENGAGE                          │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_classengage                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│ id              - Primary key                                               │
│ course          - FK to mdl_course                                          │
│ name            - Activity name                                             │
│ intro           - Activity description                                      │
│ grade           - Maximum grade (default: 100)                              │
│ timecreated     - Creation timestamp                                        │
│ timemodified    - Last modification timestamp                              │
└─────────────────────────────────────────────────────────────────────────────┘
          │
          │ 1:N
          ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_classengage_slides                                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│ id                    - Primary key                                         │
│ classengageid         - FK to mdl_classengage                               │
│ title                 - Slide title                                         │
│ filename              - Original filename                                   │
│ filepath              - Storage path                                        │
│ filesize              - File size in bytes                                  │
│ mimetype              - MIME type (application/pdf, etc.)                   │
│ status                - uploaded, completed, error                           │
│ userid                - Uploader's user ID                                  │
│                                                                             │
│ ── NLP Job Tracking ────────────────────────────────────────────────────── │
│ nlp_job_status        - idle, pending, running, completed, failed          │
│ nlp_job_progress      - Progress percentage (0-100)                        │
│ nlp_job_id            - Adhoc task ID                                      │
│ nlp_job_error         - Error message if failed                            │
│ nlp_questions_count   - Number of questions generated                     │
│ nlp_job_started       - Job start timestamp                               │
│ nlp_job_completed     - Job completion timestamp                           │
│ nlp_provider          - AI provider used (gemini, openai, etc.)           │
│ nlp_model             - AI model used                                      │
│ nlp_generation_metadata - JSON with generation details                    │
└─────────────────────────────────────────────────────────────────────────────┘
          │
          │ 1:N
          ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_classengage_questions                                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ id                    - Primary key                                         │
│ classengageid         - FK to mdl_classengage                               │
│ slideid               - FK to mdl_classengage_slides (nullable)             │
│ questiontext          - The question text                                  │
│ questiontype          - multichoice, truefalse, shortanswer                │
│ optiona, optionb,     - Answer options                                     │
│ optionc, optiond                                                           │
│ correctanswer         - Correct answer (A/B/C/D or text)                    │
│ difficulty            - easy, medium, hard                                 │
│ bloomlevel            - remember, understand, apply, analyze, evaluate,     │
│                        create                                              │
│ rationale             - AI explanation for correct answer                   │
│ sources               - JSON: slides/images used                           │
│ question_image        - Image URL if applicable                             │
│ status                - pending, approved, rejected                         │
│ source                - nlp (AI) or manual                                  │
│ timecreated           - Creation timestamp                                  │
│ timemodified          - Last modification timestamp                         │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_task_adhoc (Moodle Core - Task Queue)                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ id              - Primary key                                               │
│ component       - 'mod_classengage'                                          │
│ classname       - '\mod_classengage\task\generate_nlp_task'                │
│nextruntime      - When to execute (NULL = ASAP)                             │
│ faildelay       - Seconds until retry after failure                        │
│ customdata      - JSON with {slideid, classengageid, contextid, options}  │
│ timecreated     - Task creation timestamp                                   │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_classengage_sessions                                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│ id              - Primary key                                               │
│ classengageid   - FK to mdl_classengage                                    │
│ name            - Session name                                             │
│ numquestions    - Number of questions in session                            │
│ timelimit       - Time per question (seconds)                               │
│ status          - ready, active, paused, completed                         │
│ createdby       - User who created session                                │
│ timestarted     - Session start timestamp                                  │
│ timecompleted   - Session completion timestamp                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ mdl_classengage_responses                                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│ id              - Primary key                                               │
│ sessionid       - FK to mdl_classengage_sessions                            │
│ questionid      - FK to mdl_classengage_questions                          │
│ userid          - Student's user ID                                        │
│ answer          - Student's answer                                         │
│ iscorrect       - 1 if correct, 0 if wrong                                 │
│ score           - Points earned                                            │
│ responsetime    - Time taken to respond (seconds)                         │
│ timecreated     - Response timestamp                                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Understanding Your Deployment

Before setting up ClassEngage, you need to understand how your university's Moodle is deployed. This is critical for choosing the right installation method.

### 3.1 Questions for Your IT Team

Send this questionnaire to your university's Moodle IT administrators:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                   MOODLE INFRASTRUCTURE QUESTIONNAIRE                        │
└─────────────────────────────────────────────────────────────────────────────┘

1. DEPLOYMENT METHOD
   □ Docker / Docker Compose
   □ Kubernetes
   □ Traditional server (bare metal or VM)
   □ Cloud hosting (AWS, Azure, GCP managed services)
   □ Other: ___________________

2. CURRENT MOODLE VERSION
   Version: ___________________

3. PHP VERSION
   Version: ___________________

4. WEB SERVER
   □ Apache with PHP-FPM
   □ Nginx with PHP-FPM
   □ Other: ___________________

5. DATABASE
   □ MySQL (version: ___________)
   □ MariaDB (version: ___________)
   □ PostgreSQL
   □ Other: ___________________

6. CRON CONFIGURATION
   How often does Moodle cron run?
   □ Every minute
   □ Every 5 minutes
   □ Other: ___________________
   
   Command used:
   ___________________________________________________

7. SERVER ACCESS
   Do we have SSH/sudo access to the Moodle servers?
   □ Yes (full access)
   □ Partial (can deploy files, cannot modify system)
   □ No (must submit deployment requests)

8. EXTERNAL CONNECTIONS
   Can the Moodle server make outbound HTTPS connections?
   □ Yes, to any domain
   □ Yes, but restricted to specific domains
   □ No, requires firewall exception

9. EXISTING SERVICES
   Is there a container orchestration system (Docker Swarm, Kubernetes)?
   □ Yes
   □ No
   □ Don't know

10. SHARED STORAGE
    How is moodledata shared across web servers?
    □ Network filesystem (NFS, GlusterFS)
    □ Shared block storage
    □ Each server has local copy (rsync)
    □ Object storage (S3-compatible)
    □ Don't know

11. MONITORING
    What monitoring is in place?
    □ Server-level (CPU, RAM, disk)
    □ Application-level (error logs, uptime)
    □ No monitoring
```

### 3.2 How to Check Yourself

If you have access to Moodle's admin panel or server, you can determine your deployment type:

#### Option A: Check Moodle Admin Panel

1. Log into Moodle as administrator
2. Go to **Site Administration > Server > System Paths**
3. Look for paths - if `/var/www/html` is the web root, likely traditional server

#### Option B: Check for Docker Indicators

```bash
# SSH to your Moodle server and run:

# Check if Docker is installed
docker --version

# Check if Moodle runs in a container
docker ps | grep -i moodle

# Check if there are docker-compose files
find /opt -name "docker-compose.yml" 2>/dev/null
find /home -name "docker-compose.yml" 2>/dev/null

# Check for container-specific paths
ls -la /var/www/html/
```

#### Option C: Check PHP Environment

```bash
# Create a PHP info file (temporary)
echo "<?php phpinfo(); ?>" > /var/www/html/info.php
# Access: http://your-moodle/info.php
# Look for:
# - "Server API" - if shows "FPM" = PHP-FPM (typical for Docker/optimized setups)
# - "Configuration File (php.ini)" path hints at server setup
```

### 3.3 Decision Tree: Which Installation Method?

```
START: What type of server access do you have?
│
├── SSH/Full Access
│    │
│    ├── Is Moodle in Docker?
│    │    │
│    │    ├─ YES → Use DOCKER-BASED DEPLOYMENT (Section 4)
│    │    └─ NO → Use TRADITIONAL SERVER DEPLOYMENT (Section 5)
│    │
│    └── Can you modify Docker configs?
│         │
│         ├─ YES → Use DOCKER-BASED DEPLOYMENT (Section 4)
│         └─ NO → Use TRADITIONAL SERVER DEPLOYMENT (Section 5)
│
├── Partial Access (can upload files)
│    │
│    └─→ Use TRADITIONAL SERVER DEPLOYMENT (Section 5)
│        Use the "Manual Plugin Installation" method
│
└── No Direct Access
     │
     └─→ Work with IT team using Section 5
         Provide them with the IT Team Handoff Checklist (Section 10)
```

---

## 4. Docker-Based Deployment

This section is for Moodle installations running in Docker containers. This provides the **highest performance** with 2-5 second NLP response times.

### 4.1 Architecture for Docker Deployments

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        DOCKER-BASED ARCHITECTURE                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                         docker-compose.yml                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  services:                                                                   │
│    webserver:                                                                │
│      image: moodlehq/moodle-php-apache:latest                               │
│      volumes:                                                                │
│        - moodle-code:/var/www/html                                           │
│        - moodledata:/var/www/moodledata                                     │
│      ports:                                                                 │
│        - "80:80"                                                             │
│                                                                              │
│    worker:                                                                   │
│      build: ./Dockerfile.worker                                              │
│      volumes:                                                                │
│        - moodle-code:/var/www/html:ro                                        │
│        - moodledata:/var/www/moodledata                                     │
│        - worker-logs:/var/log/moodle/worker                                 │
│      environment:                                                            │
│        - WORKER_INTERVAL=2                                                  │
│        - WORKER_COMPONENT=mod_classengage                                    │
│      depends_on:                                                             │
│        - db                                                                  │
│                                                                              │
│    cron:                                                                     │
│      build: ./Dockerfile.cron                                                │
│      volumes:                                                                │
│        - moodle-code:/var/www/html:ro                                        │
│        - moodledata:/var/www/moodledata                                     │
│      depends_on:                                                             │
│        - db                                                                  │
│                                                                              │
│    db:                                                                       │
│      image: mysql:8.0                                                        │
│      volumes:                                                                │
│        - db-data:/var/lib/mysql                                              │
│                                                                              │
│  volumes:                                                                    │
│    moodle-code:                                                              │
│    moodledata:                                                               │
│    worker-logs:                                                              │
│    db-data:                                                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Prerequisites

- Docker Engine 20.10+ installed
- Docker Compose v2.0+ installed
- Access to modify docker-compose configuration
- Git access to clone/update plugin

### 4.3 Step-by-Step Installation

#### Step 1: Get the Plugin Files

```bash
# Navigate to your moodle directory (same level as docker-compose.yml)
cd /path/to/your/moodle

# Clone or copy the ClassEngage plugin
git clone <repository-url> mod_classengage_temp

# Move plugin to correct location
mv mod_classengage_temp/mod/classengage ./mod/classengage
rm -rf mod_classengage_temp

# Verify installation
ls -la mod/classengage/
# Should show: classes/, db/, lang/, lib.php, view.php, etc.
```

#### Step 2: Create/Modify docker-compose.yml

Add the worker and cron services to your existing `docker-compose.yml`:

```yaml
# Add to your existing services section:

services:
  # ... your existing webserver service ...

  worker:
    build:
      context: /path/to/your/bin/       # Path to Dockerfile.worker location
      dockerfile: Dockerfile.worker
    image: moodlehq/moodle-php-apache:worker-${PHP_VERSION:-8.1}
    container_name: ${COMPOSE_PROJECT_NAME:-moodle}_worker_1
    volumes:
      - moodle-code:/var/www/html:ro
      - moodledata:/var/www/moodledata:rw
      - worker-logs:/var/log/moodle/worker
    environment:
      MOODLE_DOCKER_PHP_VERSION: ${PHP_VERSION:-8.1}
      MOODLE_DOCKER_DBTYPE: ${DBTYPE:-mysqli}
      MOODLE_DOCKER_DBHOST: ${DBHOST:-db}
      MOODLE_DOCKER_DBPORT: ${DBPORT:-3306}
      MOODLE_DOCKER_DBNAME: ${DBNAME:-moodle}
      MOODLE_DOCKER_DBUSER: ${DBUSER:-moodle}
      MOODLE_DOCKER_DBPASS: ${DBPASS:-m@0dl3ing}
      # Worker configuration - KEY SETTINGS
      WORKER_INTERVAL: "2"                    # Poll every 2 seconds (1-60 range)
      WORKER_COMPONENT: "mod_classengage"    # Only process ClassEngage tasks
      WORKER_MAX_TASKS: "0"                  # 0 = unlimited tasks
    networks:
      - default
    restart: unless-stopped
    deploy:
      resources:
        limits:
          cpus: '2.0'                        # CPU cores for fast processing
          memory: 2G                         # RAM for PDF/AI processing
        reservations:
          cpus: '0.5'
          memory: 512M
    healthcheck:
      test: ["CMD", "/usr/local/bin/worker-healthcheck"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s
    depends_on:
      db:
        condition: service_healthy

  cron:
    build:
      context: /path/to/your/bin/
      dockerfile: Dockerfile.cron
    image: moodlehq/moodle-php-apache:cron-${PHP_VERSION:-8.1}
    container_name: ${COMPOSE_PROJECT_NAME:-moodle}_cron_1
    volumes:
      - moodle-code:/var/www/html:ro
      - moodledata:/var/www/moodledata:rw
    environment:
      MOODLE_DOCKER_PHP_VERSION: ${PHP_VERSION:-8.1}
      MOODLE_DOCKER_DBHOST: ${DBHOST:-db}
      MOODLE_DOCKER_DBNAME: ${DBNAME:-moodle}
      MOODLE_DOCKER_DBUSER: ${DBUSER:-moodle}
      MOODLE_DOCKER_DBPASS: ${DBPASS:-m@0dl3ing}
    networks:
      - default
    restart: unless-stopped
    depends_on:
      - db

volumes:
  # Add these to existing volumes:
  worker-logs:
    driver: local
```

#### Step 3: Create the Worker Dockerfile

Create a file named `Dockerfile.worker` in your bin directory:

```dockerfile
# Dockerfile.worker - High-Performance Task Worker for ClassEngage
FROM moodlehq/moodle-php-apache:8.1

LABEL maintainer="Your University IT"
LABEL description="ClassEngage Task Worker - High-Performance Database Polling"
LABEL version="2.0"

USER root

# Install required packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils \
    procps \
    logrotate \
    && rm -rf /var/lib/apt/lists/*

# Install Imagick for PDF processing
RUN apt-get update && apt-get install -y --no-install-recommends \
    libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# Fix ImageMagick security policy for PDF
RUN if [ -f /etc/ImageMagick-6/policy.xml ]; then \
        sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' \
        /etc/ImageMagick-6/policy.xml; \
    fi

# Create log directory
RUN mkdir -p /var/log/moodle/worker \
    && touch /var/log/moodle/worker/worker.log \
    && chmod 755 -R /var/log/moodle \
    && chmod 644 /var/log/moodle/worker/worker.log

# Health check script
RUN cat > /usr/local/bin/worker-healthcheck << 'EOF'
#!/bin/bash
set -e

# Check if worker script exists
if [ ! -f /var/www/html/mod/classengage/classes/task/task_worker.php ]; then
    echo "ERROR: Worker script not found"
    exit 1
fi

# Check if worker is running
if ! pgrep -f "task_worker.php" > /dev/null; then
    echo "ERROR: Worker process not running"
    exit 1
fi

echo "OK: Worker is healthy"
exit 0
EOF
RUN chmod +x /usr/local/bin/worker-healthcheck

# Start worker script
RUN cat > /usr/local/bin/start-worker.sh << 'EOF'
#!/bin/bash
set -e

echo "========================================"
echo "ClassEngage Task Worker Starting"
echo "Started at: $(date)"
echo "========================================"

mkdir -p /var/log/moodle/worker
touch /var/log/moodle/worker/worker.log

POLLING_INTERVAL=${WORKER_INTERVAL:-2}
COMPONENT=${WORKER_COMPONENT:-mod_classengage}
MAX_TASKS=${WORKER_MAX_TASKS:-0}

echo "Configuration:"
echo "  - Polling interval: ${POLLING_INTERVAL}s"
echo "  - Component: ${COMPONENT}"

# Build command
CMD="php /var/www/html/mod/classengage/classes/task/task_worker.php"
CMD="${CMD} --interval=${POLLING_INTERVAL}"
CMD="${CMD} --component=${COMPONENT}"
CMD="${CMD} --verbose"

if [ "${MAX_TASKS}" -gt 0 ]; then
    CMD="${CMD} --max-tasks=${MAX_TASKS}"
fi

echo "Starting worker..."
exec ${CMD}
EOF
RUN chmod +x /usr/local/bin/start-worker.sh

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD /usr/local/bin/worker-healthcheck || exit 1

VOLUME ["/var/log/moodle/worker"]

CMD ["/usr/local/bin/start-worker.sh"]
```

#### Step 4: Create the Cron Dockerfile

```dockerfile
# Dockerfile.cron - Backup Cron Container for ClassEngage
FROM moodlehq/moodle-php-apache:8.1

LABEL maintainer="Your University IT"
LABEL description="Moodle Cron - Backup Task Processor"

# Install logrotate
RUN apt-get update && apt-get install -y --no-install-recommends \
    logrotate \
    && rm -rf /var/lib/apt/lists/*

# Health check
RUN cat > /usr/local/bin/healthcheck << 'EOF'
#!/bin/bash
if pgrep -f "cron.php" > /dev/null; then
    echo "OK: Cron is running"
    exit 0
else
    echo "ERROR: Cron not running"
    exit 1
fi
EOF
RUN chmod +x /usr/local/bin/healthcheck

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD /usr/local/bin/healthcheck || exit 1

# Run Moodle cron every minute
CMD ["sh", "-c", "while true; do php /var/www/html/admin/cli/cron.php; sleep 60; done"]
```

#### Step 5: Deploy the Containers

```bash
# Stop existing containers
docker-compose down

# Build and start worker + cron
docker-compose up -d --build worker cron

# Verify all containers are running
docker-compose ps

# Check worker logs
docker logs <project_name>_worker_1

# View real-time worker logs
docker logs -f <project_name>_worker_1
```

#### Step 6: Install Plugin in Moodle

```bash
# The plugin files are mounted with the moodle-code volume
# Now install via Moodle UI:

1. Log into Moodle as administrator
2. Go to: Site Administration > Site Administration
3. Go to: Plugins > Install plugins
4. Or: Navigate to /admin/index.php
5. Moodle will detect new plugin and prompt for installation
6. Click "Upgrade database" to complete installation
```

#### Step 7: Configure AI Provider

See Section 6 for detailed AI provider configuration.

### 4.4 Worker Configuration Options

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `WORKER_INTERVAL` | 2 | Polling interval in seconds (1-60) |
| `WORKER_COMPONENT` | mod_classengage | Component filter |
| `WORKER_MAX_TASKS` | 0 | Max tasks before exit (0 = unlimited) |

#### Performance Tuning

```yaml
# For maximum speed (low-latency environments)
worker:
  environment:
    WORKER_INTERVAL: "1"  # Poll every 1 second

# For high-volume (many concurrent users)
worker:
  deploy:
    replicas: 3  # Run 3 worker instances
```

### 4.5 Scaling Workers

For large universities with high concurrent usage:

```bash
# Scale to multiple workers
docker-compose up -d --scale worker=3

# Each worker polls independently
# Tasks are distributed across workers via database locking
```

### 4.6 Verification Checklist

```bash
# 1. Check all containers are running
docker-compose ps
# Expected output:
# moodle_worker_1   Up (healthy)
# moodle_cron_1     Up (healthy)
# moodle_webserver_1 Up
# moodle_db_1       Up (healthy)

# 2. Check worker health
docker exec moodle_worker_1 /usr/local/bin/worker-healthcheck
# Expected: OK: Worker is healthy

# 3. View worker logs
docker logs moodle_worker_1 | tail -20
# Expected: Should show "ClassEngage Task Worker Started"

# 4. Check polling is working
docker logs moodle_worker_1 | grep "Found.*pending"
# Should see periodic messages about polling

# 5. Test via Moodle UI
# - Create a ClassEngage activity
# - Upload a small PDF (under 5 pages)
# - Click "Generate Questions"
# - Should complete in 2-5 seconds
```

---

## 5. Traditional Server Deployment

This section is for Moodle installations on traditional servers (bare metal, VM, or managed hosting) without Docker.

### 5.1 Two Installation Paths

```
TRADITIONAL SERVER INSTALLATION
│
├── PATH A: Full Installation (Recommended)
│    │
│    ├─ Benefit: 2-5 second response times
│    ├─ Requirement: Ability to run background processes
│    └─ Setup: Install systemd service for worker
│
└── PATH B: Cron-Based (Minimum)
     │
     ├─ Benefit: Simple setup, works everywhere
     ├─ Requirement: Moodle cron already configured
     └─ Setup: Just install plugin and configure cron
```

### 5.2 PATH A: Full Installation with Worker Service

This provides near-instant NLP response times (2-5 seconds).

#### Step 1: Prerequisites

```bash
# Check PHP version
php -v
# Should be 8.1 or higher

# Check required PHP extensions
php -m | grep -E "mbstring|curl|json|gd|zip"

# Install missing extensions (Ubuntu/Debian)
sudo apt-get install php-mbstring php-curl php-json php-gd php-zip

# Install PDF processing tools (recommended)
sudo apt-get install poppler-utils pdftotext

# Install ImageMagick (recommended for better PDF rendering)
sudo apt-get install imagemagick libmagickwand-dev
```

#### Step 2: Install the Plugin

```bash
# Navigate to your Moodle mod directory
cd /path/to/moodle/mod

# Download/clone the plugin
git clone <repository-url> classengage

# Set permissions
chown -R www-data:www-data classengage
chmod -R 755 classengage

# Verify installation
ls -la classengage/
# Should show: classes/, db/, lang/, lib.php, etc.
```

#### Step 3: Complete Installation in Moodle

```bash
# Access Moodle and complete installation:
1. Log in as administrator
2. Go to: Site Administration > Notifications
3. Moodle will detect new plugin
4. Click "Upgrade Moodle database now"
5. Installation completes
```

#### Step 4: Create the Worker Service (systemd)

Create a systemd service file:

```bash
sudo cat > /etc/systemd/system/classengage-worker.service << 'EOF'
[Unit]
Description=ClassEngage Task Worker Service
After=network.target mysql.service mariadb.service
Requires=mysql.service mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html

# Environment variables
Environment="MOODLE_DOCKER_PHP_VERSION=8.1"

# The command to run
ExecStart=/usr/bin/php /var/www/html/mod/classengage/classes/task/task_worker.php \
    --interval=2 \
    --component=mod_classengage \
    --verbose

# Restart policy
Restart=always
RestartSec=10

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=classengage-worker

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadOnlyPaths=/var/www/html

[Install]
WantedBy=multi-user.target
EOF
```

#### Step 5: Create the Worker User (if needed)

```bash
# Check if www-data exists
id www-data

# If not, create a dedicated user
sudo useradd -r -s /bin/false classengage-worker
sudo usermod -a -G www-data classengage-worker
```

#### Step 6: Enable and Start the Service

```bash
# Reload systemd to recognize new service
sudo systemctl daemon-reload

# Enable service to start on boot
sudo systemctl enable classengage-worker

# Start the service
sudo systemctl start classengage-worker

# Check status
sudo systemctl status classengage-worker

# View logs
sudo journalctl -u classengage-worker -f
```

#### Step 7: Verify Worker is Running

```bash
# Check service status
sudo systemctl status classengage-worker
# Should show: "active (running)"

# Check if process is running
ps aux | grep task_worker

# Check for recent activity in logs
sudo journalctl -u classengage-worker --since "5 minutes ago"

# Verify it can access the database
sudo -u www-data php /var/www/html/mod/classengage/classes/task/task_worker.php --help
```

### 5.3 PATH B: Cron-Based Installation

This provides reliable NLP processing but with longer response times (1-5 minutes depending on cron frequency).

#### Step 1: Install the Plugin

```bash
# Same as PATH A Step 2 and 3
cd /path/to/moodle/mod
git clone <repository-url> classengage
chown -R www-data:www-data classengage
chmod -R 755 classengage
```

#### Step 2: Configure Moodle Cron

Edit your crontab:

```bash
sudo crontab -e
```

Add or modify the cron entry:

```bash
# Run Moodle cron every minute (REQUIRED for reasonable NLP response)
* * * * * /usr/bin/php /path/to/moodle/admin/cli/cron.php > /dev/null 2>&1
```

If you're not root, edit user's crontab:

```bash
crontab -e
# Add the same line
```

#### Step 3: Verify Cron is Working

```bash
# Run cron manually to test
sudo -u www-data php /path/to/moodle/admin/cli/cron.php

# Check output for ClassEngage related messages
# Should see: "Starting adhoc task: \mod_classengage\task\generate_nlp_task"

# Check cron logs
grep CRON /var/log/syslog
# or
journalctl -u cron
```

### 5.4 Comparison: Worker vs Cron

| Aspect | Worker Service | Cron (1 min) |
|--------|---------------|--------------|
| **Response Time** | 2-5 seconds | 1-2 minutes |
| **Setup Complexity** | Medium | Low |
| **Reliability** | High (systemd managed) | Medium (depends on cron) |
| **Resource Usage** | Continuous (low) | Periodic (burst) |
| **Recovery on Failure** | Automatic restart | Next cron cycle |
| **Multiple Instances** | Supported | Not supported |
| **Best For** | Production, high volume | Small deployments, testing |

### 5.5 Hybrid Setup (Recommended for Large Universities)

For maximum reliability, run both worker AND cron:

```bash
# 1. Set up worker service (PATH A)
# 2. Configure cron as backup (PATH B)

# The worker processes tasks immediately
# The cron catches any missed tasks if worker fails
```

---

## 6. AI Provider Configuration

ClassEngage supports multiple AI providers with automatic failover.

### 6.1 Supported Providers

| Provider | Model | Pros | Cons |
|---------|-------|------|------|
| **Google Gemini** | gemini-2.5-flash | Fast, affordable, good quality | Requires Google account |
| **OpenAI GPT-4** | gpt-4o-mini | Excellent quality, reliable | Cost per token |
| **Anthropic Claude** | claude-3-5-sonnet | Best reasoning, detailed | Higher latency |
| **DeepSeek** | deepseek-chat | Affordable, good for code | May lack some features |
| **Kimi (Moonshot)** | moonshot-v1 | Good for Chinese text | Region-specific |
| **Local/Ollama** | qwen3-vl | No API costs, private | Requires local GPU |

### 6.2 Configuration in Moodle Admin

1. Log into Moodle as administrator
2. Navigate to: **Site Administration > Plugins > Activity Modules > In-class Learning Engagement**
3. Scroll to **AI Provider Settings**

#### Google Gemini (Recommended for Most Universities)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          AI Provider Configuration                          │
└─────────────────────────────────────────────────────────────────────────────┘

Default Provider: [Google Gemini ▼]

Provider Priority (fallback order):
┌────────────────────────────────────────────────────────────────────────────┐
│ gemini,openai,anthropic,deepseek,kimi,local                                │
└────────────────────────────────────────────────────────────────────────────┘
(Higher priority providers are tried first)

Request Timeout: [120] seconds
(How long to wait for AI response)

─────────────────────────────────────────────────────────────

Google Gemini Settings
─────────────────────────────────────────────────────────────

API Key:        [••••••••••••••••••••••••••••••••••••••••]
(Your Google AI API key)

Model:          [gemini-2.5-flash ▼]

Endpoint:       [https://generativelanguage.googleapis.com/v1beta     ]
```

#### Getting a Google Gemini API Key

1. Visit: https://aistudio.google.com/apikey
2. Sign in with Google account
3. Click "Create API Key"
4. Copy the key
5. Paste into Moodle settings

### 6.3 Environment Variables (Alternative)

For Docker deployments, you can set API keys via environment variables:

```yaml
worker:
  environment:
    # Add to existing environment section
    GEMINI_API_KEY: "your-gemini-api-key-here"
    OPENAI_API_KEY: "your-openai-api-key-here"
```

In traditional servers:

```bash
# Add to /etc/environment or systemd service
export GEMINI_API_KEY="your-gemini-api-key-here"
```

### 6.4 Testing AI Configuration

After configuring, test the AI connection:

1. Navigate to: `/mod/classengage/nlp_diagnostics.php`
2. Look for "AI Provider Status" section
3. Each configured provider should show "✓ Connected" or "✗ Failed"
4. Try a test generation with a small PDF

---

## 7. Production Scaling for Large Universities

### 7.1 Capacity Planning

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     CAPACITY PLANNING GUIDELINES                             │
└─────────────────────────────────────────────────────────────────────────────┘

Assumptions:
- Average PDF size: 10-20 pages
- Average NLP generation time: 3-5 seconds
- Peak hours: 9 AM - 4 PM (7 hours/day)

USER VOLUME TIERING:

┌──────────────────┬────────────────┬──────────────────┬─────────────────────┐
│ University Size  │ Daily Users    │ Workers Needed   │ Recommended Config  │
├──────────────────┼────────────────┼──────────────────┼─────────────────────┤
│ Small            │ < 1,000        │ 1                │ 2 CPU, 2GB RAM      │
│ Medium           │ 1,000 - 10,000 │ 2-3              │ 4 CPU, 4GB RAM      │
│ Large            │ 10,000 - 50,000│ 5-10             │ 8 CPU, 8GB RAM      │
│ Enterprise       │ 50,000+        │ 10-20+           │ Custom scaling      │
└──────────────────┴────────────────┴──────────────────┴─────────────────────┘

Peak Concurrency:
- Assume 10% of daily users active simultaneously
- Each worker handles ~6 tasks/minute
- For 100 concurrent NLP requests: ~17 workers needed
```

### 7.2 Resource Allocation Guidelines

#### Per Worker Container

```yaml
worker:
  deploy:
    resources:
      limits:
        cpus: '2.0'        # 2 CPU cores
        memory: 2G          # 2 GB RAM
      reservations:
        cpus: '0.5'
        memory: 512M
```

#### For 10,000+ Concurrent Users

```yaml
# docker-compose.yml
worker:
  deploy:
    replicas: 5              # 5 parallel workers
    resources:
      limits:
        cpus: '2.0'
        memory: 2G

# Or in Kubernetes:
worker:
  replicas: 5
  resources:
    limits:
      cpu: 2
      memory: 2Gi
```

### 7.3 Horizontal Scaling Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        HORIZONTAL SCALING ARCHITECTURE                       │
└─────────────────────────────────────────────────────────────────────────────┘

                         ┌─────────────────────┐
                         │   Load Balancer     │
                         │   (Optional)         │
                         └──────────┬──────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
              ▼                     ▼                     ▼
     ┌─────────────┐        ┌─────────────┐        ┌─────────────┐
     │  Webserver  │        │  Webserver  │        │  Webserver  │
     │   Pool A    │        │   Pool B    │        │   Pool N    │
     └──────┬──────┘        └──────┬──────┘        └──────┬──────┘
            │                     │                     │
            └─────────────────────┼─────────────────────┘
                                  │
                         ┌────────┴────────┐
                         │                │
                         ▼                ▼
                ┌──────────────┐  ┌──────────────┐
                │   Worker 1   │  │   Worker 2   │
                │ (Container)  │  │ (Container)  │
                └──────────────┘  └──────────────┘
                         │                │
                         └────────┬───────┘
                                  │
                         ┌────────┴────────┐
                         │                │
                         ▼                ▼
                ┌──────────────┐  ┌──────────────┐
                │   Worker 3   │  │   Worker N   │
                │ (Container)  │  │ (Container)  │
                └──────────────┘  └──────────────┘
                                  │
                                  ▼
                         ┌──────────────┐
                         │   Database    │
                         │   (MySQL)    │
                         └──────────────┘

Each worker:
- Polls database independently
- Processes tasks in parallel
- Database locking prevents duplicate processing
```

### 7.4 High Availability Setup

For mission-critical deployments:

```yaml
# docker-compose.override.yml for HA
services:
  worker:
    deploy:
      replicas: 2
      placement:
        constraints:
          - node.role == worker
    restart_policy:
      condition: on-failure
      delay: 5s
      max_attempts: 3

  cron:
    deploy:
      replicas: 2
    restart_policy:
      condition: on-failure
```

---

## 8. Monitoring and Health Checks

### 8.1 Built-in Monitoring Script

For Docker deployments, use the monitoring script:

```bash
cd /path/to/bin

# Quick status check
./monitor_worker.sh status

# View recent logs
./monitor_worker.sh logs

# Health check
./monitor_worker.sh health

# Check pending tasks
./monitor_worker.sh tasks

# Full diagnostic
./monitor_worker.sh diagnose

# Performance metrics
./monitor_worker.sh performance

# Real-time log watching
./monitor_worker.sh watch
```

### 8.2 Manual Health Checks

#### Docker Containers

```bash
# Check all containers
docker ps | grep -E "worker|cron|webserver|db"

# Container health status
docker inspect --format='{{.State.Health.Status}}' <container_name>

# Worker process inside container
docker exec <container> pgrep -f task_worker.php
```

#### systemd Service

```bash
# Service status
sudo systemctl status classengage-worker

# Process check
ps aux | grep task_worker

# Recent logs
sudo journalctl -u classengage-worker --since "1 hour ago"
```

### 8.3 Database Monitoring

```bash
# Check pending tasks
docker exec <container> php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$total = \$DB->count_records('task_adhoc');
\$classengage = \$DB->count_records('task_adhoc', ['component' => 'mod_classengage']);
echo \"Total pending: \$total\n\";
echo \"ClassEngage tasks: \$classengage\n\";
"

# Check failed tasks
docker exec <container> php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$failed = \$DB->get_records_sql(\"
    SELECT * FROM {task_adhoc} 
    WHERE faildelay > 0 
    AND component = 'mod_classengage'
    ORDER BY id DESC LIMIT 10
\");
echo \"Failed ClassEngage tasks: \" . count(\$failed) . \"\n\";
foreach (\$failed as \$task) {
    echo \"  - Task ID: \$task->id, Faildelay: \$task->faildelay\n\";
}
"
```

### 8.4 Setting Up Alerts

#### systemd Service Alert

```bash
# Create alert script
sudo cat > /usr/local/bin/classengage-alert.sh << 'EOF'
#!/bin/bash

# Check if worker is running
if ! systemctl is-active --quiet classengage-worker; then
    echo "CRITICAL: ClassEngage worker is not running!"
    # Send alert (configure your alerting system)
    # Example: send email, Slack, PagerDuty, etc.
    exit 1
fi

# Check for failed tasks
FAILED=$(systemctl show -p ExecMainStatus classengage-worker 2>/dev/null || echo 0)
if [ "$FAILED" -ne 0 ]; then
    echo "WARNING: Worker has issues"
fi
EOF
sudo chmod +x /usr/local/bin/classengage-alert.sh

# Add to crontab for monitoring
echo "*/5 * * * * /usr/local/bin/classengage-alert.sh" | sudo tee -a /var/spool/cron/crontabs/root
```

### 8.5 Performance Metrics to Track

| Metric | Healthy | Warning | Critical |
|--------|---------|---------|----------|
| Worker response time | < 5s | 5-10s | > 10s |
| Pending tasks | 0-5 | 5-20 | > 20 |
| Failed tasks (24h) | 0-2 | 2-10 | > 10 |
| CPU usage (worker) | < 50% | 50-80% | > 80% |
| Memory usage (worker) | < 70% | 70-90% | > 90% |

---

## 9. Troubleshooting

### 9.1 Common Issues and Solutions

#### Issue: Worker container won't start

**Symptoms:**
- `docker-compose up -d worker` fails
- Container exits immediately

**Diagnosis:**
```bash
# Check logs
docker logs <project>_worker_1

# Check configuration
docker-compose config

# Try manual run
docker run --rm -it <worker_image> /usr/local/bin/start-worker.sh
```

**Solutions:**
```bash
# 1. Rebuild from scratch
docker-compose down
docker-compose build --no-cache worker
docker-compose up -d worker

# 2. Check environment variables
docker-compose config | grep -A5 worker:

# 3. Check volume mounts
docker inspect <project>_worker_1 | grep -A10 Mounts
```

#### Issue: Tasks not processing (worker running but idle)

**Symptoms:**
- Worker is running (healthy)
- No NLP tasks complete
- No errors in logs

**Diagnosis:**
```bash
# Check for pending tasks
./monitor_worker.sh tasks

# Check worker polling
docker logs <project>_worker_1 | grep "Found.*pending"

# Verify component filter
docker exec <project>_worker_1 printenv WORKER_COMPONENT
```

**Solutions:**
```bash
# 1. Verify component is set correctly (should be "mod_classengage")
docker exec <project>_worker_1 printenv WORKER_COMPONENT

# 2. Check task queue directly
docker exec <project>_worker_1 php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$tasks = \$DB->get_records('task_adhoc', [], 'id DESC', '*', 0, 5);
foreach (\$tasks as \$t) {
    echo \"Task: \$t->id, Component: \$t->component, Class: \$t->classname\n\";
}
"

# 3. Test with explicit component
# Edit docker-compose.yml, set WORKER_COMPONENT to empty string (process all)
```

#### Issue: PDF processing fails

**Symptoms:**
- NLP task completes but no questions generated
- Error: "No text extracted from PDF"

**Diagnosis:**
```bash
# Check diagnostics page
# Navigate to: http://your-moodle/mod/classengage/nlp_diagnostics.php

# Test PDF extraction manually
docker exec <project>_worker_1 php -r "
\$pdf = '/var/www/moodledata/filedir/XX/YY/yourfile.pdf';
if (file_exists(\$pdf)) {
    echo 'File exists: ' . filesize(\$pdf) . ' bytes\n';
    // Try extraction
    \$text = shell_exec('pdftotext ' . escapeshellarg(\$pdf) . ' - 2>/dev/null');
    echo 'Extracted ' . strlen(\$text) . ' characters\n';
} else {
    echo 'File not found\n';
}
"
```

**Solutions:**
```bash
# 1. Install pdftotext
docker exec <project>_worker_1 apt-get update && apt-get install -y poppler-utils

# 2. Rebuild image with pdftotext
# Add to Dockerfile.worker:
RUN apt-get update && apt-get install -y poppler-utils

# 3. Check PDF is not scanned/image-based
# Image-based PDFs cannot be processed
# Solution: Use text-based PDFs or OCR-processed documents
```

#### Issue: AI API errors

**Symptoms:**
- NLP task fails
- Error in logs: "API request failed" or "Invalid API key"

**Diagnosis:**
```bash
# Check diagnostics page
# http://your-moodle/mod/classengage/nlp_diagnostics.php

# Test API connectivity
curl -X POST "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"contents":[{"parts":[{"text":"test"}]}]}'
```

**Solutions:**
```bash
# 1. Verify API key is correct
docker exec <project>_worker_1 php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
\$key = get_config('mod_classengage', 'geminiapikey');
echo 'API Key configured: ' . (strlen(\$key) > 0 ? 'Yes (' . strlen(\$key) . ' chars)' : 'No') . \"\n\";
"

# 2. Check network connectivity from container
docker exec <project>_worker_1 curl -I https://generativelanguage.googleapis.com

# 3. Check firewall/proxy settings
docker exec <project>_worker_1 env | grep -i proxy

# 4. Try alternative AI provider
# Go to Moodle Admin > ClassEngage Settings
# Change default provider to OpenAI or another
```

#### Issue: Worker uses too much CPU/memory

**Symptoms:**
- Server load spikes
- Worker container using > 80% resources

**Diagnosis:**
```bash
# Check resource usage
docker stats <project>_worker_1

# Check logs for processing time
docker logs <project>_worker_1 | grep "completed in"
```

**Solutions:**
```yaml
# 1. Limit worker resources in docker-compose.yml
worker:
  deploy:
    resources:
      limits:
        cpus: '1.0'      # Reduce from 2.0 to 1.0
        memory: 1G        # Reduce from 2G to 1G

# 2. Increase polling interval
worker:
  environment:
    WORKER_INTERVAL: "5"  # Poll every 5 seconds instead of 2

# 3. Run fewer workers
docker-compose up -d --scale worker=2  # Reduce from 5 to 2
```

#### Issue: Questions not appearing in UI after completion

**Symptoms:**
- NLP task completes successfully (see in logs)
- Questions not visible in Moodle UI
- Must purge cache manually

**Diagnosis:**
```bash
# Check slide status
docker exec <project>_worker_1 php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$slide = \$DB->get_record('classengage_slides', ['nlp_job_status' => 'completed'], '*', IGNORE_MULTIPLE);
if (\$slide) {
    echo \"Completed slide found: ID \$slide->id\n\";
    echo \"Questions: \$slide->nlp_questions_count\n\";
} else {
    echo \"No completed slides found\n\";
}
"

# Check questions table
docker exec <project>_worker_1 php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$questions = \$DB->count_records('classengage_questions', ['source' => 'nlp']);
echo \"NLP-generated questions in database: \$questions\n\";
"
```

**Solutions:**
```bash
# 1. Purge Moodle caches
docker exec <project>_webserver_1 php /var/www/html/admin/cli/purge_caches.php

# 2. Check permissions
docker exec <project>_worker_1 php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;
\$questions = \$DB->get_records('classengage_questions', ['source' => 'nlp'], 'id DESC', 'id, status', 0, 5);
foreach (\$questions as \$q) {
    echo \"Question \$q->id: status = \$q->status\n\";
}
"
# All should have status = 'pending', 'approved', or 'rejected'
```

### 9.2 Emergency Recovery Procedures

#### Complete Worker Failure

```bash
# 1. Switch to cron backup
docker-compose down worker  # Stop broken worker

# Cron will now process tasks (slower but functional)
# Tasks will process during next cron run (every minute)

# 2. Fix worker
docker-compose build worker
docker-compose up -d worker

# 3. Verify recovery
./monitor_worker.sh health
```

#### Database Task Queue Stuck

```bash
# If tasks are stuck in pending state
docker exec <container> php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;

// Find stuck tasks (> 1 hour old, still pending)
\$stuck = \$DB->get_records_sql(\"
    SELECT * FROM {task_adhoc} 
    WHERE component = 'mod_classengage'
    AND timecreated < ? 
    AND (nextruntime IS NULL OR nextruntime <= ?)
\", [time() - 3600, time()]);

echo \"Found \" . count(\$stuck) . \" stuck tasks\n\";

// Reset them for immediate processing
foreach (\$stuck as \$task) {
    \$DB->set_field('task_adhoc', 'nextruntime', time(), ['id' => \$task->id]);
    echo \"Reset task \$task->id\n\";
}
"
```

#### Reset All NLP Jobs

```bash
# Nuclear option - reset ALL pending/processing NLP jobs
docker exec <container> php -r "
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global \$DB;

// Reset all slides to idle
\$DB->execute(\"
    UPDATE {classengage_slides} 
    SET nlp_job_status = 'idle',
        nlp_job_progress = 0,
        nlp_job_error = NULL
    WHERE nlp_job_status IN ('pending', 'running')
\");

// Delete pending adhoc tasks (they'll be recreated on next request)
\$DB->delete_records('task_adhoc', ['component' => 'mod_classengage']);

echo \"All NLP jobs reset\n\";
"
```

### 9.3 Log Analysis

#### Finding Errors

```bash
# Docker
docker logs <container> 2>&1 | grep -i error

# systemd
sudo journalctl -u classengage-worker | grep -i error

# Search for specific error patterns
docker logs <container> 2>&1 | grep -E "(exception|fatal|failed|FATAL)"
```

#### Performance Analysis

```bash
# Extract processing times
docker logs <container> 2>&1 | grep "completed in" | tail -100 | \
  awk '{print $NF}' | sed 's/s//' | \
  awk '{sum+=$1; count++; if($1<min || !min) min=$1; if($1>max) max=$1} END {print "Avg:", sum/count, "s, Min:", min, "s, Max:", max, "s"}'

# Count successful vs failed
docker logs <container> 2>&1 | grep -c "completed in"
docker logs <container> 2>&1 | grep -c "failed:"
```

---

## 10. IT Team Handoff Checklist

When handing off to your university's IT team, use this checklist:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    CLASSENGAGE DEPLOYMENT CHECKLIST                         │
│                    For University IT Team                                    │
└─────────────────────────────────────────────────────────────────────────────┘

PRE-DEPLOYMENT REVIEW
─────────────────────────────────────────────────────────────────────────────

□ Confirmed Moodle version: ___________ (must be 4.0+)
□ Confirmed PHP version: ___________ (must be 8.1+)
□ Confirmed database type: ___________
□ Reviewed server resources (CPU, RAM, disk)
□ Identified deployment type: Docker / Traditional / Kubernetes
□ Assigned IT contact for ongoing support

DEPLOYMENT STEPS
─────────────────────────────────────────────────────────────────────────────

□ Plugin files deployed to: /path/to/moodle/mod/classengage
□ Permissions set: chown -R www-data:www-data classengage
□ Moodle notifications page visited: /admin/index.php
□ Database tables created via upgrade wizard

WORKER SETUP (for Docker deployments)
─────────────────────────────────────────────────────────────────────────────

□ Dockerfile.worker created
□ Dockerfile.cron created
□ docker-compose.yml updated with worker + cron services
□ Containers built: docker-compose build worker cron
□ Containers started: docker-compose up -d worker cron
□ Worker health verified: docker exec <worker> /usr/local/bin/worker-healthcheck

WORKER SETUP (for Traditional servers)
─────────────────────────────────────────────────────────────────────────────

□ systemd service created: /etc/systemd/system/classengage-worker.service
□ Service enabled: systemctl enable classengage-worker
□ Service started: systemctl start classengage-worker
□ Service status verified: systemctl status classengage-worker

CRON CONFIGURATION
─────────────────────────────────────────────────────────────────────────────

□ Crontab entry added: * * * * * /path/to/moodle/admin/cli/cron.php
□ Cron tested manually: php /path/to/moodle/admin/cli/cron.php
□ Cron logging enabled (if needed)

AI PROVIDER CONFIGURATION
─────────────────────────────────────────────────────────────────────────────

□ Accessed: Site Admin > Plugins > Activity Modules > ClassEngage
□ Default provider selected: ___________
□ API key configured: ___________ (masked in config)
□ Provider priority set: ___________
□ Connection tested via diagnostics page

TESTING
─────────────────────────────────────────────────────────────────────────────

□ Diagnostics page accessed: /mod/classengage/nlp_diagnostics.php
□ AI provider status verified: all show "Connected"
□ Test PDF uploaded (small, 1-3 pages)
□ Questions generated successfully
□ Response time measured: ___________ seconds
□ Questions visible in instructor UI
□ Questions available for quiz session

MONITORING SETUP
─────────────────────────────────────────────────────────────────────────────

□ Monitoring script deployed: /path/to/bin/monitor_worker.sh
□ Monitoring script tested: ./monitor_worker.sh health
□ Alerting configured (if applicable): ___________
□ Runbook documented: location ___________

DOCUMENTATION
─────────────────────────────────────────────────────────────────────────────

□ Deployment guide provided to IT team
□ Troubleshooting guide provided
□ Contact information for plugin developer: ___________
□ Link to support/issue tracker: ___________

SIGN-OFF
─────────────────────────────────────────────────────────────────────────────

IT Team Lead: _______________________ Date: ___________
Plugin Developer: _______________________ Date: ___________
University Representative: _______________________ Date: ___________
```

---

## Appendix A: File Locations

```
KEY FILES AND THEIR LOCATIONS

Plugin Files:
  /path/to/moodle/mod/classengage/
  ├── classes/
  │   ├── task/
  │   │   ├── task_worker.php        # Main worker script
  │   │   └── generate_nlp_task.php  # NLP task handler
  │   ├── nlp_generator.php         # AI integration
  │   └── ...
  ├── db/
  │   ├── install.xml               # Database schema
  │   └── upgrade.php               # Upgrade scripts
  ├── settings.php                  # Admin settings page
  └── nlp_diagnostics.php           # Diagnostic page

Worker Files:
  /path/to/bin/
  ├── Dockerfile.worker             # Worker container definition
  ├── Dockerfile.cron               # Cron container definition
  ├── local.yml                      # Docker Compose override
  ├── monitor_worker.sh             # Monitoring script
  └── docker-compose.yml            # Docker orchestration

Configuration:
  Moodle config: /path/to/moodle/config.php
  Moodledata: /path/to/moodledata/
  Worker logs: /var/log/moodle/worker/worker.log
```

---

## Appendix B: Environment Variables Reference

| Variable | Docker | systemd | Description |
|----------|--------|---------|-------------|
| `WORKER_INTERVAL` | ✓ | ✓ | Polling interval (seconds) |
| `WORKER_COMPONENT` | ✓ | ✓ | Component filter |
| `WORKER_MAX_TASKS` | ✓ | ✓ | Max tasks before exit |
| `GEMINI_API_KEY` | ✓ | ✓ | Gemini API key |
| `OPENAI_API_KEY` | ✓ | ✓ | OpenAI API key |
| `MOODLE_DOCKER_DBHOST` | ✓ | - | Database host |
| `MOODLE_DOCKER_DBNAME` | ✓ | - | Database name |

---

## Appendix C: API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/mod/classengage/slides_api.php` | POST | Upload slides, trigger NLP |
| `/mod/classengage/nlp_diagnostics.php` | GET | System diagnostics |
| `/mod/classengage/api.php` | Various | Core API operations |

---

## Appendix D: Glossary

| Term | Definition |
|------|------------|
| **Adhoc Task** | A Moodle task queued for background execution |
| **Worker** | Continuous process that polls and executes tasks |
| **NLP** | Natural Language Processing - AI-powered question generation |
| **Cron** | Time-based job scheduler (backup processing) |
| **Container** | Docker container for isolated execution |
| **Moodledata** | Moodle's file storage directory |
| **Bloom's Taxonomy** | Educational framework for question difficulty levels |

---

## Appendix E: Support and Resources

- **Plugin Documentation**: `/mod/classengage/docs/`
- **Moodle Forums**: https://moodle.org/mod/forum/view.php?id=7126
- **Issue Tracker**: https://github.com/your-repo/issues
- **Diagnostic Page**: `http://your-moodle/mod/classengage/nlp_diagnostics.php`

---

**Document Version:** 2.0  
**Last Updated:** March 2026  
**Author:** Danielle
