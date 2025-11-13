# Implementation Plan: mod_classengage Completion and Improvements

## Overview
This implementation plan covers both completed work and remaining tasks to bring the mod_classengage plugin to production-ready status. Tasks are organized by sprint/phase with clear dependencies and requirements references.

---

## Sprint 1: Foundation (COMPLETED ✓)

- [x] 1. Set up plugin structure and Moodle integration
  - Create mod/classengage directory structure
  - Implement version.php with plugin metadata
  - Define component name as 'mod_classengage'
  - _Requirements: 6.1, 6.2_

- [x] 1.1 Create database schema
  - Define install.xml with 7 tables (classengage, slides, questions, sessions, session_questions, responses, clicker_devices)
  - Implement proper foreign keys and indices
  - Add composite index on (sessionid, questionid, userid) for responses
  - _Requirements: 1.3, 2.1, 3.4_

- [x] 1.2 Implement access control
  - Create db/access.php with 9 capabilities
  - Define archetypes for teacher, student, manager roles
  - Set appropriate risk bitmasks
  - _Requirements: 6.1_

- [x] 1.3 Create core library functions
  - Implement classengage_add_instance() in lib.php
  - Implement classengage_update_instance()
  - Implement classengage_delete_instance()
  - Implement classengage_supports() for feature flags
  - _Requirements: 6.2_

- [x] 1.4 Implement gradebook integration
  - Create classengage_grade_item_update() function
  - Create classengage_grade_item_delete() function
  - Implement classengage_get_user_grades() for grade calculation
  - Hook into Moodle's grade_update API
  - _Requirements: 5.1, 5.2, 5.3_

---

## Sprint 2: Content Management (COMPLETED ✓)

- [x] 2. Implement slide upload and processing
  - Create slide_processor class
  - Implement file upload handling with Moodle File API
  - Store files in 'mod_classengage/slides' filearea
  - _Requirements: 1.1_

- [x] 2.1 Integrate NLP service for question generation
  - Create nlp_generator class
  - Implement generate_questions_from_file() method
  - Send files via multipart/form-data to NLP endpoint
  - Handle API authentication with Bearer token
  - _Requirements: 1.2, 7.3, 7.4_

- [x] 2.2 Store generated questions
  - Implement store_questions() method
  - Save questions with status='pending', source='nlp'
  - Link questions to slide via slideid foreign key
  - _Requirements: 1.3_

- [x] 2.3 Create question management interface
  - Build questions.php page
  - Display questions in table format
  - Add edit and delete functionality
  - _Requirements: 1.4_

---

## Sprint 3: Session Management (COMPLETED ✓)

- [x] 3. Implement session lifecycle management
  - Create session_manager class
  - Implement create_session() with question selection
  - Support shuffle and time limit configuration
  - _Requirements: 2.1_

- [x] 3.1 Implement session control methods
  - Create start_session() - sets status to 'active', stops other sessions
  - Create stop_session() - sets status to 'completed', triggers gradebook sync
  - Create next_question() - increments counter, auto-completes at end
  - _Requirements: 2.2, 2.4, 2.5_

- [x] 3.2 Build session management UI
  - Create sessions.php page
  - Implement create_session_form for configuration
  - Display active, ready, and completed sessions
  - Add start/stop/control panel links
  - _Requirements: 2.1, 2.2_

- [x] 3.3 Implement question selection logic
  - Create select_questions() method
  - Filter for approved questions only
  - Support shuffling and limiting question count
  - Store in session_questions table with order
  - _Requirements: 2.3_

---

## Sprint 4: Student Participation (COMPLETED ✓)

- [x] 4. Build student quiz interface
  - Create quiz.php page for students
  - Implement real-time question display
  - Add timer display with visual warnings
  - _Requirements: 3.1_

- [x] 4.1 Implement AJAX polling for real-time updates
  - Create amd/src/quiz.js AMD module
  - Implement getCurrentQuestion() with 1-2 second polling
  - Update UI when new question appears
  - Handle session completion state
  - _Requirements: 3.1, 4.5_

- [x] 4.2 Create response submission system
  - Build ajax.php endpoint for answer submission
  - Validate answer format (A/B/C/D)
  - Check for duplicate submissions
  - Calculate correctness and response time
  - _Requirements: 3.2, 3.3, 3.4_

- [x] 4.3 Implement immediate feedback
  - Display correct/incorrect message after submission
  - Show correct answer
  - Update student view to waiting state
  - _Requirements: 3.1_

---

## Sprint 5: Clicker Integration (COMPLETED ✓ - BONUS FEATURE)

- [x] 5. Implement Web Service API for clickers
  - Create db/services.php with external function definitions
  - Define 'ClassEngage Clicker Service'
  - _Requirements: 3.5 (BONUS)_

- [x] 5.1 Create clicker response submission endpoint
  - Implement classes/external/submit_clicker_response.php
  - Validate session status and question state
  - Support clickerid parameter for device tracking
  - Calculate response time from timestamp
  - _Requirements: 3.5_

- [x] 5.2 Implement bulk response submission
  - Create classes/external/submit_bulk_responses.php
  - Support multiple student responses in single API call
  - Optimize for hub-based submission
  - _Requirements: 3.5_

- [x] 5.3 Add clicker device registration
  - Create classes/external/register_clicker.php
  - Implement device-to-user mapping
  - Store in classengage_clicker_devices table
  - Track last used timestamp
  - _Requirements: 3.5_

- [x] 5.4 Create session status API
  - Implement classes/external/get_active_session.php
  - Return current session state for activity
  - Implement classes/external/get_current_question.php
  - Provide question details for external devices
  - _Requirements: 3.5_

---

## Sprint 6: Analytics and Reporting (PARTIALLY COMPLETE)

- [x] 6. Create basic analytics page
  - Build analytics.php page structure
  - Add tab navigation
  - _Requirements: 4.4_

- [x] 6.1 Implement analytics_engine class





  - Create classes/analytics_engine.php
  - Implement get_current_question_stats() for real-time aggregation
  - Implement get_session_summary() for overview statistics
  - Implement get_question_breakdown() for detailed analysis
  - Implement get_student_performance() for individual stats
  - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 6.2 Add response aggregation caching





  - Implement Moodle cache API integration
  - Create 'response_stats' cache definition in db/caches.php
  - Cache aggregated stats for 2 seconds
  - Invalidate cache on new response submission
  - _Requirements: 4.2, Performance Optimization_

- [ ] 6.3 Build real-time instructor dashboard
  - Create controlpanel.php with live updates
  - Display current question with response distribution
  - Show bar charts using Chart.js or similar
  - Add participant count and response rate
  - Implement AJAX polling (1 second interval)
  - _Requirements: 4.1, 4.2_

- [ ] 6.4 Create post-session analytics reports
  - Implement question-by-question breakdown view
  - Add student performance comparison table
  - Show average response times
  - Display difficulty vs. success rate analysis
  - Add export functionality (CSV/PDF)
  - _Requirements: 4.4_

- [ ] 6.5 Add session results page
  - Create sessionresults.php for completed sessions
  - Display participant list with scores
  - Show question statistics
  - Add grade distribution histogram
  - _Requirements: 4.4_

---

## Sprint 7: Question Review Workflow (PARTIALLY COMPLETE)

- [ ] 7. Implement question_manager class
  - Create classes/question_manager.php
  - Implement get_pending_questions() method
  - Implement approve_question() method
  - Implement bulk_approve() for multiple questions
  - Implement reject_question() with reason tracking
  - Implement update_question() for editing
  - _Requirements: 1.4, 1.5_

- [ ] 7.1 Create question review database table
  - Add classengage_question_reviews table to install.xml
  - Track reviewer, action (approved/rejected/edited), and comments
  - Create upgrade script in db/upgrade.php
  - _Requirements: 1.5_

- [ ] 7.2 Build question review interface
  - Enhance questions.php with review UI
  - Add inline editing capability
  - Implement bulk selection checkboxes
  - Add approve/reject/edit buttons per question
  - Show question source (slide number, NLP vs manual)
  - _Requirements: 1.4_

- [ ] 7.3 Add question editing form
  - Create classes/form/edit_question_form.php
  - Support editing question text and all options
  - Allow changing correct answer
  - Add difficulty level selector
  - _Requirements: 1.4_

- [ ] 7.4 Implement rejection workflow
  - Add rejection reason text field
  - Store rejection in question_reviews table
  - Update question status to 'rejected'
  - Optionally hide rejected questions from list
  - _Requirements: 1.5_

---

## Sprint 8: Session State Enhancements

- [ ] 8. Add pause/resume functionality
  - Update session status to support 'paused' state
  - Implement pause_session() in session_manager
  - Implement resume_session() in session_manager
  - Update state machine transitions
  - _Requirements: 2.2, Design Enhancement_

- [ ] 8.1 Add previous question navigation
  - Implement previous_question() method
  - Decrement currentquestion counter
  - Update questionstarttime
  - Add validation to prevent going before question 1
  - _Requirements: 2.4_

- [ ] 8.2 Enhance session state API
  - Implement get_session_state() method
  - Return complete session state with current question details
  - Include time remaining calculation
  - Add participant count
  - _Requirements: 4.1_

- [ ] 8.3 Update control panel UI
  - Add [Pause] and [Resume] buttons
  - Add [Previous] button for navigation
  - Show session state clearly (active/paused)
  - Disable student submissions when paused
  - _Requirements: 2.2_

---

## Sprint 9: Performance Optimization

- [ ] 9. Add database indices for performance
  - Create index on (sessionid, questionid, answer) in responses table
  - Create index on (userid, classengageid, iscorrect) in responses table
  - Create index on (status) in questions table (already exists)
  - Test query performance with 100+ concurrent users
  - _Requirements: Performance Optimization_

- [ ] 9.1 Implement response aggregation cache table
  - Add classengage_response_cache table to install.xml
  - Store pre-calculated stats as JSON
  - Update cache on each response submission
  - Add cleanup task for old cache entries
  - _Requirements: Performance Optimization_

- [ ] 9.2 Optimize polling mechanism
  - Implement exponential backoff in quiz.js
  - Start at 1 second, increase to max 5 seconds when no changes
  - Reset to 1 second when question changes
  - Reduce server load during waiting periods
  - _Requirements: 4.5, Performance Optimization_

- [ ] 9.3 Add rate limiting for submissions
  - Implement check_rate_limit() in response_handler
  - Use Moodle cache API for rate limit tracking
  - Limit to 1 submission per second per user
  - Return user-friendly error message
  - _Requirements: Security Enhancement_

- [ ] 9.4 Optimize gradebook updates
  - Batch gradebook updates instead of per-response
  - Update only at session completion or on-demand
  - Add scheduled task for periodic grade sync
  - _Requirements: 5.4, Performance Optimization_

---

## Sprint 10: Error Handling and Logging

- [ ] 10. Implement error code system
  - Create classes/classengage_error.php
  - Define error code constants (ERR_NLP_CONNECTION, ERR_SESSION_NOT_ACTIVE, etc.)
  - Implement get_message() for user-friendly messages
  - Add error codes to lang/en/mod_classengage.php
  - _Requirements: Error Handling_

- [ ] 10.1 Enhance NLP error handling
  - Add specific error codes for NLP failures
  - Implement retry logic with exponential backoff
  - Log detailed error information for debugging
  - Provide fallback to manual question creation
  - _Requirements: 7.3, 7.4_

- [ ] 10.2 Implement comprehensive event logging
  - Create classes/event/document_uploaded.php
  - Create classes/event/questions_generated.php
  - Create classes/event/response_submitted.php
  - Create classes/event/session_finished.php
  - Enhance existing session_started and session_stopped events
  - _Requirements: Event Logging_

- [ ] 10.3 Add validation error handling
  - Validate all form inputs with clear error messages
  - Add client-side validation in JavaScript
  - Implement server-side validation in all endpoints
  - Return structured error responses from AJAX calls
  - _Requirements: 6.3, Error Handling_

---

## Sprint 11: Configuration and Settings

- [ ] 11. Implement admin settings page
  - Enhance settings.php with all configuration options
  - Add NLP endpoint URL setting (PARAM_URL)
  - Add NLP API key setting (password field)
  - Add auto-generate questions checkbox
  - _Requirements: 7.1_

- [ ] 11.1 Add performance configuration
  - Add default questions count setting (PARAM_INT)
  - Add polling interval setting (milliseconds)
  - Add max concurrent sessions setting
  - Add cache TTL configuration
  - _Requirements: Performance Optimization_

- [ ] 11.2 Add feature toggles
  - Add enable/disable clicker support
  - Add enable/disable NLP auto-generation
  - Add enable/disable real-time analytics
  - Add debug mode toggle
  - _Requirements: Configuration Management_

- [ ] 11.3 Create configuration validation
  - Test NLP endpoint connectivity on save
  - Validate API key format
  - Check polling interval is reasonable (500-5000ms)
  - Warn if performance settings are too aggressive
  - _Requirements: 7.1_

---

## Sprint 12: Testing Infrastructure

- [ ] 12. Create PHPUnit test suite
  - Set up tests/generator/lib.php for test data generation
  - Create base test class with common setup
  - _Requirements: Testing Strategy_

- [ ] 12.1 Write session_manager unit tests
  - Create tests/session_manager_test.php
  - Test create_session_selects_approved_questions_only()
  - Test start_session_stops_other_active_sessions()
  - Test next_question_increments_counter()
  - Test next_question_completes_session_at_end()
  - Test pause_resume_session()
  - Test stop_session_triggers_gradebook_update()
  - _Requirements: Testing Strategy_

- [ ] 12.2 Write analytics_engine unit tests
  - Create tests/analytics_engine_test.php
  - Test get_current_question_stats_aggregates_correctly()
  - Test get_session_summary_calculates_averages()
  - Test caching_reduces_database_queries()
  - Test cache_invalidation_on_new_response()
  - _Requirements: Testing Strategy_

- [ ] 12.3 Write question_manager unit tests
  - Create tests/question_manager_test.php
  - Test approve_question_changes_status()
  - Test bulk_approve_handles_multiple_questions()
  - Test reject_question_records_reason()
  - Test update_question_modifies_content()
  - _Requirements: Testing Strategy_

- [ ] 12.4 Write integration tests
  - Test complete workflow: upload → generate → approve → session → grade
  - Test concurrent student submissions (simulate 50 students)
  - Test NLP service failure handling
  - Test gradebook synchronization accuracy
  - _Requirements: Testing Strategy_

---

## Sprint 13: Behat Acceptance Tests

- [ ] 13. Create Behat test infrastructure
  - Set up tests/behat/behat_mod_classengage.php
  - Create custom step definitions
  - _Requirements: Testing Strategy_

- [ ] 13.1 Write instructor workflow feature
  - Create tests/behat/instructor_workflow.feature
  - Test creating and starting a quiz session
  - Test advancing through questions
  - Test ending session and viewing results
  - _Requirements: Testing Strategy_

- [ ] 13.2 Write student participation feature
  - Create tests/behat/student_participation.feature
  - Test joining active session
  - Test submitting answers
  - Test viewing feedback
  - Test viewing final results
  - _Requirements: Testing Strategy_

- [ ] 13.3 Write question management feature
  - Create tests/behat/question_management.feature
  - Test uploading slides
  - Test reviewing generated questions
  - Test approving/rejecting questions
  - Test editing questions
  - _Requirements: Testing Strategy_

- [ ] 13.4 Write analytics feature
  - Create tests/behat/analytics.feature
  - Test viewing real-time dashboard
  - Test viewing session results
  - Test exporting reports
  - _Requirements: Testing Strategy_

---

## Sprint 14: UI/UX Enhancements

- [ ] 14. Enhance instructor control panel
  - Add real-time bar charts with Chart.js
  - Implement auto-refresh every 1 second
  - Add visual indicators for response rate
  - Add one-click answer reveal button
  - Show participant count (responded / total)
  - _Requirements: 4.1, UI/UX Improvements_

- [ ] 14.1 Improve question review interface
  - Add inline editing capability
  - Implement bulk selection with checkboxes
  - Add difficulty level badges
  - Show source information (slide number)
  - Add preview mode for questions
  - _Requirements: 1.4, UI/UX Improvements_

- [ ] 14.2 Enhance analytics dashboard
  - Add participation rate over time (line chart)
  - Add average score distribution (histogram)
  - Add question difficulty vs. success rate (scatter plot)
  - Implement most missed questions list
  - Add average response time per question
  - _Requirements: 4.4, UI/UX Improvements_

- [ ] 14.3 Improve student quiz interface
  - Add progress indicator (question X of Y)
  - Enhance timer with color coding (green/yellow/red)
  - Add keyboard shortcuts (1-4 for A-D)
  - Improve mobile responsiveness
  - Add loading states for AJAX calls
  - _Requirements: 3.1, UI/UX Improvements_

- [ ] 14.4 Add accessibility improvements
  - Ensure WCAG 2.1 AA compliance
  - Add ARIA labels to all interactive elements
  - Test with screen readers
  - Add keyboard navigation support
  - Ensure sufficient color contrast
  - _Requirements: Accessibility_

---

## Sprint 15: Documentation and Deployment

- [ ] 15. Create user documentation
  - Write instructor guide (how to create sessions)
  - Write student guide (how to participate)
  - Write admin guide (configuration and troubleshooting)
  - Add inline help text to all forms
  - _Requirements: Documentation_

- [ ] 15.1 Create developer documentation
  - Document plugin architecture
  - Document database schema
  - Document API endpoints
  - Add code examples for extending plugin
  - Document event system
  - _Requirements: Documentation_

- [ ] 15.2 Implement backup and restore
  - Complete backup/moodle2/backup_classengage_stepslib.php
  - Include all tables in backup
  - Complete backup/moodle2/restore_classengage_stepslib.php
  - Test backup and restore functionality
  - _Requirements: Backup/Restore_

- [ ] 15.3 Implement privacy API
  - Complete classes/privacy/provider.php
  - Implement get_metadata() for data disclosure
  - Implement export_user_data() for GDPR export
  - Implement delete_data_for_user() for right to be forgotten
  - Test privacy compliance
  - _Requirements: Privacy/GDPR_

- [ ] 15.4 Create upgrade documentation
  - Document upgrade path from alpha to stable
  - Create migration guide for existing data
  - Document breaking changes
  - Add troubleshooting section
  - _Requirements: Documentation_

---

## Sprint 16: Security Hardening

- [ ] 16. Implement CSRF protection enhancements
  - Add request origin validation
  - Validate referer headers
  - Add nonce tokens for AJAX requests
  - _Requirements: Security Enhancement_

- [ ] 16.1 Add input sanitization layer
  - Create validate_answer() function
  - Add question type-specific validation
  - Sanitize all text inputs
  - Validate file uploads (size, type, content)
  - _Requirements: 6.3, Security Enhancement_

- [ ] 16.2 Implement SQL injection prevention audit
  - Review all database queries
  - Ensure all use parameterized queries
  - Add automated SQL injection tests
  - _Requirements: 6.2_

- [ ] 16.3 Add XSS prevention audit
  - Review all output rendering
  - Ensure all use proper escaping
  - Test with XSS payloads
  - Add Content Security Policy headers
  - _Requirements: 6.3_

- [ ] 16.4 Implement security logging
  - Log failed authentication attempts
  - Log suspicious activity (rapid submissions, invalid tokens)
  - Log admin configuration changes
  - Add security event monitoring
  - _Requirements: Security Enhancement_

---

## Sprint 17: Performance Testing and Optimization

- [ ] 17. Set up load testing environment
  - Install Apache JMeter
  - Create test scenarios for 100 concurrent users
  - Set up test data generation
  - _Requirements: Testing Strategy_

- [ ] 17.1 Perform load testing
  - Test get_session_status endpoint under load
  - Test submit_response endpoint under load
  - Measure response times and throughput
  - Identify bottlenecks
  - _Requirements: Testing Strategy_

- [ ] 17.2 Optimize identified bottlenecks
  - Add database query optimization
  - Implement connection pooling if needed
  - Optimize cache hit rates
  - Reduce AJAX payload sizes
  - _Requirements: Performance Optimization_

- [ ] 17.3 Implement monitoring
  - Add performance metrics logging
  - Track average response times
  - Monitor cache hit rates
  - Track concurrent user counts
  - _Requirements: Monitoring_

---

## Sprint 18: Final Polish and Release

- [ ] 18. Code quality review
  - Run Moodle Code Checker (phpcs)
  - Fix all coding standard violations
  - Run PHP Mess Detector
  - Fix all code quality issues
  - _Requirements: Code Quality_

- [ ] 18.1 Security audit
  - Run security scanner
  - Review all user input handling
  - Review all file operations
  - Review all database operations
  - _Requirements: Security_

- [ ] 18.2 Accessibility audit
  - Test with screen readers (NVDA, JAWS)
  - Test keyboard navigation
  - Validate HTML
  - Check color contrast ratios
  - _Requirements: Accessibility_

- [ ] 18.3 Browser compatibility testing
  - Test on Chrome, Firefox, Safari, Edge
  - Test on mobile browsers (iOS Safari, Chrome Mobile)
  - Fix any browser-specific issues
  - _Requirements: Compatibility_

- [ ] 18.4 Prepare for release
  - Update version number to 1.0.0
  - Update maturity to MATURITY_STABLE
  - Create CHANGELOG.md
  - Create release notes
  - Package plugin for Moodle plugins directory
  - _Requirements: Release_

---

## Summary

### Completion Status
- **Completed**: 45 tasks (Sprints 1-5)
- **Remaining**: 108 tasks (Sprints 6-18)
- **Total**: 153 tasks
- **Overall Progress**: ~29% complete

### Critical Path (Priority Order)
1. **Sprint 6**: Analytics Engine and Real-time Dashboard (enables instructor workflow)
2. **Sprint 7**: Question Review Workflow (completes content management)
3. **Sprint 8**: Session State Enhancements (improves session control)
4. **Sprint 9**: Performance Optimization (ensures scalability)
5. **Sprint 10**: Error Handling and Logging (improves reliability)
6. **Sprint 11**: Configuration and Settings (enables customization)
7. **Sprint 12-13**: Testing Infrastructure (ensures quality)
8. **Sprint 14**: UI/UX Enhancements (improves usability)
9. **Sprint 15**: Documentation and Deployment (enables adoption)
10. **Sprint 16-18**: Security, Performance, and Release (production readiness)

### Estimated Timeline
- **Phase 1 (Sprints 6-8)**: 4-6 weeks - Core functionality completion
- **Phase 2 (Sprints 9-11)**: 3-4 weeks - Performance and configuration
- **Phase 3 (Sprints 12-14)**: 4-5 weeks - Testing and UX
- **Phase 4 (Sprints 15-18)**: 3-4 weeks - Documentation and release
- **Total**: 14-19 weeks to production-ready v1.0.0

### Next Steps
1. Start with Sprint 6 (Analytics Engine) - highest priority for instructor workflow
2. Implement analytics_engine class with caching
3. Build real-time instructor control panel
4. Complete question review workflow in Sprint 7
