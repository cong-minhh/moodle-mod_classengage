# Testing Checklist for Control Panel Refactoring

## Pre-Testing Setup

- [ ] Clear Moodle caches: `php admin/cli/purge_caches.php`
- [ ] Verify AMD modules compiled: Check `amd/build/controlpanel.min.js` exists
- [ ] Verify no PHP syntax errors (all files checked ✓)
- [ ] Create test course with ClassEngage activity
- [ ] Upload test slides and generate questions
- [ ] Create test session with approved questions

## Functional Testing

### Tab Navigation
- [ ] Navigate to slides.php - verify tabs display correctly
- [ ] Click each tab - verify navigation works
- [ ] Verify active tab highlighting
- [ ] Test on controlpanel.php - verify no active tab
- [ ] Test on questions.php, sessions.php, analytics.php

### Control Panel Access
- [ ] Access control panel with valid session ID
- [ ] Verify page loads without errors
- [ ] Check browser console for JavaScript errors
- [ ] Verify all status cards display correctly
- [ ] Verify session heading displays

### Session Status Display
- [ ] Test with active session - verify "Active" badge is green
- [ ] Test with completed session - verify "Completed" badge is gray
- [ ] Test with ready session - verify appropriate message
- [ ] Verify participant count displays correctly (uses analytics_engine->get_session_summary()->total_participants)
- [ ] Verify question progress displays (e.g., "1 / 10")
- [ ] Test participant count with 0 participants - verify displays "0"
- [ ] Test participant count with multiple participants - verify accurate count

### Current Question Display
- [ ] Verify question text displays correctly
- [ ] Verify question card has proper styling
- [ ] Test with long question text - verify wrapping
- [ ] Test with special characters in question

### Response Distribution
- [ ] Verify table displays all four options (A, B, C, D)
- [ ] Verify correct answer has checkmark (✓)
- [ ] Verify progress bars start at 0%
- [ ] Verify count displays start at 0
- [ ] Verify percentage displays start at 0%

### Chart Display
- [ ] Verify Chart.js canvas element exists
- [ ] Verify chart initializes (check browser console)
- [ ] Verify chart has proper height (300px)
- [ ] Test chart responsiveness (resize browser)

### Real-time Updates
- [ ] Start session and open control panel
- [ ] Submit student responses from another browser/device
- [ ] Verify response count updates within 2 seconds
- [ ] Verify distribution table updates
- [ ] Verify progress bars animate
- [ ] Verify chart updates
- [ ] Verify participation rate updates

### Action Handling - Next Question
- [ ] Click "Next Question" button
- [ ] Verify sesskey validation works
- [ ] Verify page redirects to same URL
- [ ] Verify question number increments
- [ ] Verify new question displays
- [ ] Test at last question - verify button disappears

### Action Handling - Stop Session
- [ ] Click "Stop Session" button
- [ ] Verify sesskey validation works
- [ ] Verify redirect to sessions.php
- [ ] Verify success notification displays
- [ ] Verify session status changes to "completed"
- [ ] Verify polling stops

### Error Handling
- [ ] Access control panel with invalid session ID - verify error
- [ ] Access control panel with session from different activity - verify error
- [ ] Try action without sesskey - verify error
- [ ] Try invalid action parameter - verify error
- [ ] Test with no current question - verify error message
- [ ] Test with network disconnected - verify graceful degradation

### Security Testing
- [ ] Test as student user - verify access denied
- [ ] Test as teacher without startquiz capability - verify access denied
- [ ] Test CSRF by manipulating sesskey - verify rejection
- [ ] Test action whitelist by sending invalid action - verify rejection
- [ ] Test session ownership by accessing other activity's session - verify rejection

### Performance Testing
- [ ] Measure page load time (should be < 2 seconds)
- [ ] Verify Chart.js only loads for active sessions
- [ ] Check number of database queries (should use cached data)
- [ ] Test with 50+ concurrent student responses
- [ ] Verify AJAX polling doesn't cause memory leaks (leave open 10+ minutes)

### Browser Compatibility
- [ ] Test on Chrome (latest)
- [ ] Test on Firefox (latest)
- [ ] Test on Safari (latest)
- [ ] Test on Edge (latest)
- [ ] Test on mobile Chrome (Android)
- [ ] Test on mobile Safari (iOS)

### Responsive Design
- [ ] Test on desktop (1920x1080)
- [ ] Test on laptop (1366x768)
- [ ] Test on tablet (768x1024)
- [ ] Test on mobile (375x667)
- [ ] Verify status cards stack properly on mobile
- [ ] Verify table scrolls horizontally if needed

## Code Quality Checks

### PHP Code Standards
- [ ] Run PHP Code Sniffer: `vendor/bin/phpcs mod/classengage/`
- [ ] Check for deprecated functions
- [ ] Verify all functions have PHPDoc comments
- [ ] Verify proper use of Moodle APIs

### JavaScript Code Standards
- [ ] Run ESLint: `npx grunt eslint:amd` (already passed ✓)
- [ ] Check for console.log statements (remove for production)
- [ ] Verify proper AMD module structure
- [ ] Check for memory leaks

### Database Queries
- [ ] Enable query debugging
- [ ] Count queries on control panel page
- [ ] Verify no N+1 query problems
- [ ] Check for missing indexes

## Regression Testing

### Existing Functionality
- [ ] Slide upload still works
- [ ] Question generation still works
- [ ] Session creation still works
- [ ] Session start still works
- [ ] Student quiz participation still works
- [ ] Analytics page still works
- [ ] Gradebook integration still works

### Web Services API
- [ ] Test clicker API endpoints still work
- [ ] Test submit_clicker_response
- [ ] Test get_active_session
- [ ] Test get_current_question

## Accessibility Testing

### Keyboard Navigation
- [ ] Tab through all interactive elements
- [ ] Verify focus indicators visible
- [ ] Test Enter key on buttons
- [ ] Test Escape key behavior

### Screen Reader
- [ ] Test with NVDA/JAWS
- [ ] Verify ARIA labels present
- [ ] Verify table headers announced
- [ ] Verify progress bars announced

### Color Contrast
- [ ] Check status badge colors meet WCAG AA
- [ ] Check progress bar colors meet WCAG AA
- [ ] Check text on colored backgrounds

## Documentation Review

- [ ] Review REFACTORING_SUMMARY.md for accuracy
- [ ] Verify all new classes documented
- [ ] Verify language strings added
- [ ] Update README.md if needed

## Deployment Checklist

- [ ] All tests passed
- [ ] No console errors
- [ ] No PHP warnings/notices
- [ ] Performance acceptable
- [ ] Security verified
- [ ] Documentation complete
- [ ] Backup created
- [ ] Rollback plan ready

## Post-Deployment Monitoring

- [ ] Monitor error logs for 24 hours
- [ ] Check user feedback
- [ ] Monitor performance metrics
- [ ] Verify no increase in support tickets

## Known Issues / Limitations

- Chart.js loaded from CDN (TODO: bundle locally)
- Pause/resume not yet implemented (placeholders exist)
- Mustache templates not yet used (future enhancement)

## Test Results

**Date**: _______________
**Tester**: _______________
**Environment**: _______________
**Result**: [ ] PASS [ ] FAIL [ ] PARTIAL

**Notes**:
_______________________________________________
_______________________________________________
_______________________________________________

**Issues Found**:
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

**Sign-off**: _______________
