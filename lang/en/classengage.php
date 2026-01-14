<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English strings for classengage
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'In-class Learning Engagement';
$string['modulenameplural'] = 'In-class Learning Engagements';
$string['modulename_help'] = 'The In-class Learning Engagement module enables instructors to upload lecture slides, automatically generate quiz questions using NLP, and conduct real-time interactive quizzes with students.';
$string['moduleintro'] = 'Description';
$string['pluginadministration'] = 'In-class Learning Engagement administration';
$string['pluginname'] = 'In-class Learning Engagement';

// Capabilities
$string['classengage:addinstance'] = 'Add a new In-class Learning Engagement activity';
$string['classengage:view'] = 'View In-class Learning Engagement activity';
$string['classengage:managequestions'] = 'Manage quiz questions';
$string['classengage:uploadslides'] = 'Upload lecture slides';
$string['classengage:configurequiz'] = 'Configure quiz sessions';
$string['classengage:startquiz'] = 'Start and manage quiz sessions';
$string['classengage:takequiz'] = 'Participate in quiz sessions';
$string['classengage:viewanalytics'] = 'View analytics and reports';
$string['classengage:grade'] = 'Grade student responses';
$string['classengage:submitclicker'] = 'Submit clicker responses via Web Services API';

// General
$string['name'] = 'Activity name';
$string['grade'] = 'Maximum grade';
$string['grade_help'] = 'The maximum grade that can be awarded for this activity';
$string['gradesettings'] = 'Grade settings';

// View page
$string['teacherwelcome'] = 'Welcome! Use the tabs above to manage slides, questions, sessions, and view analytics.';
$string['uploadslides'] = 'Upload Slides';
$string['managequestions'] = 'Manage Questions';
$string['managesessions'] = 'Quiz Sessions';
$string['analytics'] = 'Analytics';
$string['joinquiz'] = 'Join Active Quiz';
$string['nosession'] = 'No active quiz session at the moment. Please wait for your instructor to start a quiz.';
$string['yourresults'] = 'Your Results';
$string['sessionname'] = 'Session Name';
$string['score'] = 'Score';
$string['noresults'] = 'You haven\'t participated in any quiz sessions yet.';

// Slides page
$string['slidespage'] = 'Manage Slides';
$string['uploadnewslides'] = 'Upload New Slides';
$string['slidefile'] = 'Slide File (PDF or PPT)';
$string['slidetitle'] = 'Slide Title';
$string['uploadslide'] = 'Upload';
$string['uploadedslideslist'] = 'Uploaded Slides';
$string['filename'] = 'File Name';
$string['uploaddate'] = 'Upload Date';
$string['actions'] = 'Actions';
$string['generatequestions'] = 'Generate Questions';
$string['delete'] = 'Delete';
$string['confirmdelete'] = 'Are you sure you want to delete this slide?';
$string['slideuploaded'] = 'Slide uploaded successfully';
$string['slidedeleted'] = 'Slide deleted successfully';
$string['invalidfiletype'] = 'Invalid file type. Please upload a PDF or PPT/PPTX file.';
$string['filesizeexceeded'] = 'File size exceeds maximum allowed size';
$string['erroruploadingfile'] = 'Error uploading file. Please try again.';
$string['usefilename'] = 'Use File Name';

// Questions page
$string['questionspage'] = 'Manage Questions';
$string['generatedquestions'] = 'Generated Questions';
$string['questiontext'] = 'Question';
$string['options'] = 'Answer Options';
$string['correctanswer'] = 'Correct Answer';
$string['difficulty'] = 'Difficulty';
$string['edit'] = 'Edit';
$string['approve'] = 'Approve';
$string['approved'] = 'Approved';
$string['pending'] = 'Pending';
$string['noquestions'] = 'No questions generated yet. Upload slides and generate questions.';
$string['slidesdeleted'] = '{$a} slides deleted successfully';
$string['selectall'] = 'Select All';
$string['confirmbulkdelete'] = 'Are you sure you want to delete the selected slides? This will also delete all associated questions.';
$string['processed'] = 'Processed';
$string['uploaded'] = 'Uploaded';
$string['error'] = 'Error';
$string['editquestion'] = 'Edit Question';
$string['savequestion'] = 'Save Question';
$string['questionupdated'] = 'Question updated successfully';
$string['questiondeleted'] = 'Question deleted successfully';
$string['addquestion'] = 'Add Manual Question';
$string['optiona'] = 'Option A';
$string['optionb'] = 'Option B';
$string['optionc'] = 'Option C';
$string['optiond'] = 'Option D';

// Sessions page
$string['sessionspage'] = 'Quiz Sessions';
$string['createnewsession'] = 'Create New Session';
$string['sessiontitle'] = 'Session Title';
$string['numberofquestions'] = 'Number of Questions';
$string['timelimit'] = 'Time Limit per Question (seconds)';
$string['shufflequestions'] = 'Shuffle Questions';
$string['shuffleanswers'] = 'Shuffle Answers';
$string['createsession'] = 'Create Session';
$string['sessioncreated'] = 'Session created successfully';
$string['activesessions'] = 'Active Sessions';
$string['completedsessions'] = 'Completed Sessions';
$string['status'] = 'Status';
$string['startsession'] = 'Start';
$string['stop'] = 'Stop';
$string['stopsession'] = 'Stop';
$string['viewresults'] = 'View Results';
$string['sessionstarted'] = 'Session started successfully';
$string['sessionstopped'] = 'Session stopped successfully';
$string['nosessions'] = 'No sessions created yet.';
$string['active'] = 'Active';
$string['completed'] = 'Completed';
$string['participants'] = 'Participants';
$string['withselected'] = 'With selected';
$string['sessiondeleted'] = 'Session deleted successfully';
$string['sessionsdeleted'] = 'Selected sessions deleted successfully';
$string['sessionsstopped'] = 'Selected sessions stopped successfully';
$string['deleteconfirm'] = 'Are you sure you want to delete this session?';

// Quiz page (student)
$string['quizpage'] = 'Live Quiz';
$string['currentquestion'] = 'Question {$a->current} of {$a->total}';
$string['timeleft'] = 'Time Remaining';
$string['submitanswer'] = 'Submit Answer';
$string['answersubmitted'] = 'Answer submitted!';
$string['waitingnextquestion'] = 'Waiting for next question...';
$string['quizcompleted'] = 'Quiz completed! Your score: {$a}';
$string['correct'] = 'Correct!';
$string['incorrect'] = 'Incorrect';
$string['alreadyanswered'] = 'You have already answered this question';

// Analytics page
$string['analyticspage'] = 'Analytics Dashboard';
$string['selectsession'] = 'Select Session';
$string['overallperformance'] = 'Overall Performance';
$string['averagescore'] = 'Average Score';
$string['participationrate'] = 'Participation Rate';
$string['questionbreakdown'] = 'Question Breakdown';
$string['responsetime'] = 'Average Response Time';
$string['studentperformance'] = 'Student Performance';
$string['studentname'] = 'Student Name';
$string['totalresponses'] = 'Total Responses';
$string['correctresponses'] = 'Correct Responses';
$string['incorrectresponses'] = 'Incorrect Responses';
$string['exportcsv'] = 'Export to CSV';
$string['lastupdated'] = 'Last updated: {$a}';

// Settings
$string['settings:nlpendpoint'] = 'NLP Service Endpoint';
$string['settings:nlpendpoint_desc'] = 'URL endpoint for the NLP question generation service';
$string['settings:nlpapikey'] = 'NLP API Key';
$string['settings:nlpapikey_desc'] = 'API key for authenticating with the NLP service (optional, leave empty if not required)';
$string['settings:autogeneratequestions'] = 'Auto-generate Questions on Upload';
$string['settings:autogeneratequestions_desc'] = 'Automatically generate quiz questions when slides are uploaded (requires NLP service)';
$string['settings:maxfilesize'] = 'Maximum Slide File Size';
$string['settings:maxfilesize_desc'] = 'Maximum file size for slide uploads (in MB)';
$string['settings:defaultquestions'] = 'Default Number of Questions';
$string['settings:defaultquestions_desc'] = 'Default number of questions to generate per slide set';
$string['settings:defaulttimelimit'] = 'Default Time Limit';
$string['settings:defaulttimelimit_desc'] = 'Default time limit per question (in seconds)';
$string['settings:enablerealtime'] = 'Enable Real-time Updates';
$string['settings:enablerealtime_desc'] = 'Use AJAX polling for real-time question delivery (recommended)';
$string['settings:pollinginterval'] = 'Polling Interval';
$string['settings:pollinginterval_desc'] = 'Interval for AJAX polling in milliseconds (default: 1000)';

// Events
$string['eventcoursemodulesviewed'] = 'Course module viewed';
$string['eventcoursemodulesinstancelistviewed'] = 'Course module instance list viewed';
$string['eventsessionstarted'] = 'Quiz session started';
$string['eventsessionstopped'] = 'Quiz session stopped';
$string['eventquestionanswered'] = 'Question answered';
$string['eventslidesuploaded'] = 'Slides uploaded';
$string['eventquestionsgenerated'] = 'Questions generated';

// Additional strings
$string['noslides'] = 'No slides uploaded yet.';
$string['noactivesessions'] = 'No active sessions at the moment.';
$string['noreadysessions'] = 'No sessions ready to start.';
$string['readysessions'] = 'Sessions Ready';
$string['nocompletedsessions'] = 'No completed sessions yet.';
$string['questionsgeneratedsuccess'] = '{$a} questions generated successfully';
$string['questionapproved'] = 'Question approved successfully';
$string['controlpanel'] = 'Control Panel';
$string['time'] = 'Time';
$string['currentquestiontext'] = 'Current Question';
$string['liveresponses'] = 'Live Responses';
$string['nextquestion'] = 'Next Question';
$string['sessionnotactive'] = 'This session is not active';
$string['completeddate'] = 'Completed';
$string['sessionnotstarted'] = 'The quiz session has not started yet. Please wait for your instructor.';
$string['noresponses'] = 'No responses recorded';
$string['correctanswers'] = 'Correct Answers';
$string['percentage'] = 'Percentage';

// Advanced Analysis strings
$string['advancedanalysis'] = 'Advanced Analysis';
$string['conceptdifficulty'] = 'Concept Difficulty';
$string['engagementtimeline'] = 'Engagement Timeline';
$string['responsetrends'] = 'Common Response Trends';
$string['teachingrecommendations'] = 'Teaching Recommendations';
$string['participationdistribution'] = 'Participation Distribution';
$string['difficultylevel'] = 'Difficulty Level';
$string['correctnessrate'] = 'Correctness Rate';
$string['easy'] = 'Easy';
$string['moderate'] = 'Moderate';
$string['difficult'] = 'Difficult';
$string['noconceptdata'] = 'No concept difficulty data available yet';
$string['notrendsdata'] = 'No response trends data available yet';
$string['norecommendations'] = 'No recommendations at this time';
$string['noparticipationdata'] = 'No participation data available yet';
$string['commonwronganswer'] = 'Common Wrong Answer';
$string['misconception'] = 'Misconception';
$string['priority'] = 'Priority';
$string['category'] = 'Category';
$string['recommendation'] = 'Recommendation';
$string['evidence'] = 'Evidence';
$string['highparticipation'] = 'High (5+ responses)';
$string['moderateparticipation'] = 'Moderate (2-4 responses)';
$string['notenoughquestions'] = 'Not enough approved questions. You have {$a} approved questions.';
$string['minimumquestions'] = 'You must have at least 1 question';
$string['minimumtimelimit'] = 'Time limit must be at least 5 seconds';
$string['selectanswer'] = 'Please select an answer';
$string['eventslidedeleted'] = 'Slide deleted';

// Errors
$string['error:invalidcourse'] = 'Invalid course';
$string['error:invalidcoursemodule'] = 'Invalid course module';
$string['error:missingidandcmid'] = 'Missing course module ID or instance ID';
$string['error:nopermission'] = 'You do not have permission to perform this action';
$string['error:sessionnotfound'] = 'Session not found';
$string['error:slidenotfound'] = 'Slide not found';
$string['error:questionnotfound'] = 'Question not found';
$string['error:cannotextracttext'] = 'Cannot extract text from slide file';
$string['error:nlpservicefailed'] = 'NLP service request failed';
$string['error:invalidresponse'] = 'Invalid response data';
$string['error:analyticsfailed'] = 'Failed to calculate analytics data. Please try again later.';
$string['errorprocessingslide'] = 'Error processing slide upload';
$string['nofileuploaded'] = 'No file was uploaded';
$string['invalidfiletype'] = 'Invalid file type: {$a}. Only PDF, PPT, PPTX, DOC, and DOCX files are allowed';

// Clicker Integration
$string['clickerdevice'] = 'Clicker device';
$string['clickerid'] = 'Clicker ID';
$string['registerclicker'] = 'Register clicker';
$string['clickerregistered'] = 'Clicker device registered successfully';
$string['clickeralreadyregistered'] = 'This clicker is already registered';
$string['clickernotregistered'] = 'Clicker device not registered';
$string['clickerinuse'] = 'This clicker is already registered to another user';
$string['webserviceapi'] = 'Web Services API';
$string['clickerapi'] = 'Clicker API Integration';
$string['clickerapi_help'] = 'This activity supports classroom clicker integration via REST/JSON Web Services API. See CLICKER_API_DOCUMENTATION.md for setup instructions.';

// Privacy
$string['privacy:metadata:classengage_responses'] = 'Information about user responses to quiz questions';
$string['privacy:metadata:classengage_responses:userid'] = 'The ID of the user who submitted the response';
$string['privacy:metadata:classengage_responses:questionid'] = 'The ID of the question answered';
$string['privacy:metadata:classengage_responses:sessionid'] = 'The ID of the quiz session';
$string['privacy:metadata:classengage_responses:answer'] = 'The answer submitted by the user';
$string['privacy:metadata:classengage_responses:score'] = 'The score achieved for this response';
$string['privacy:metadata:classengage_responses:timecreated'] = 'The time when the response was submitted';

$string['privacy:metadata:classengage_clicker_devices'] = 'Information about registered clicker devices';
$string['privacy:metadata:classengage_clicker_devices:userid'] = 'The ID of the user who owns the clicker device';
$string['privacy:metadata:classengage_clicker_devices:clickerid'] = 'The unique identifier of the clicker device';
$string['privacy:metadata:classengage_clicker_devices:timecreated'] = 'When the clicker was registered';
$string['privacy:metadata:classengage_clicker_devices:lastused'] = 'When the clicker was last used';

// Control panel strings
$string['responsedistribution'] = 'Response Distribution';
$string['overallresponserate'] = 'Overall Response Rate';
$string['responsedistributionchart'] = 'Response Distribution Chart';
$string['answer'] = 'Answer';
$string['distribution'] = 'Distribution';
$string['count'] = 'Count';
$string['quizsessions'] = 'Quiz Sessions';
$string['uploadslidesdesc'] = 'Upload lecture slides in PDF or PowerPoint format for automatic question generation';
$string['managequestionsdesc'] = 'Review, approve, edit, and manage quiz questions';
$string['analyticsdesc'] = 'View post-session analytics and student performance reports';
$string['comingsoon'] = 'This feature is coming soon';

// Additional error strings
$string['error:noquestionfound'] = 'No question found for current session state';
$string['error:cannotloadquestion'] = 'Cannot load current question';
$string['error:connectionissues'] = 'Connection issues detected. Retrying...';
$string['invalidaction'] = 'Invalid action: {$a}';
$string['notimplemented'] = 'This feature is not yet implemented';
$string['invalidsession'] = 'Invalid session - session does not belong to this activity';
$string['sessioncompleted'] = 'This session has been completed';
$string['sessionpaused'] = 'This session is currently paused';
$string['backtosessions'] = 'Back to Sessions';

// Analytics enhancement - Filter toolbar
$string['filtertoolbar'] = 'Filter Options';
$string['namesearch'] = 'Search by name';
$string['minscore'] = 'Minimum score (%)';
$string['maxscore'] = 'Maximum score (%)';
$string['minresponsetime'] = 'Min response time (s)';
$string['maxresponsetime'] = 'Max response time (s)';
$string['topperformersonly'] = 'Show only top 10 performers';
$string['filterbyquestion'] = 'Filter by question';
$string['applyfilters'] = 'Apply Filters';
$string['clearfilters'] = 'Clear Filters';

// Analytics enhancement - Pagination
$string['showing'] = 'Showing {$a->start} to {$a->end} of {$a->total}';
$string['perpage'] = 'Per page';
$string['previous'] = 'Previous';
$string['next'] = 'Next';

// Analytics enhancement - Summary cards
$string['accuracytrend'] = 'Accuracy Trend';
$string['responsespeed'] = 'Response Speed';
$string['higheststreak'] = 'Highest Streak';
$string['stddev'] = 'Std Dev';
$string['improvement'] = 'Improvement';

// Analytics enhancement - Insights
$string['insights'] = 'Insights';
$string['atriskstudents'] = 'At-Risk Students';
$string['missingparticipants'] = 'Missing Participants';
$string['performancebadges'] = 'Performance Badges';
$string['mostimproved'] = 'Most Improved';
$string['fastestresponder'] = 'Fastest Responder';
$string['mostconsistent'] = 'Most Consistent';
$string['anomalies'] = 'Anomalies';
$string['suspiciousspeed'] = 'Suspicious Speed';
$string['perfectfast'] = 'Perfect Score with Fast Time';

// Analytics enhancement - Question insights
$string['questioninsights'] = 'Question Insights';
$string['highestperforming'] = 'Highest Performing Question';
$string['lowestperforming'] = 'Lowest Performing Question';
$string['difficultquestions'] = 'Difficult Questions';
$string['easyquestions'] = 'Easy Questions';

// Analytics enhancement - Charts
$string['leaderboardchart'] = 'Top Students Leaderboard';
$string['scoredistribution'] = 'Score Distribution';
$string['engagementtimeline'] = 'Engagement Timeline';
$string['questiondifficulty'] = 'Question Difficulty';

// Analytics enhancement - Table
$string['rank'] = 'Rank';
$string['sortby'] = 'Sort by {$a}';
$string['ascending'] = 'Ascending';
$string['descending'] = 'Descending';

// Analytics enhancement - Accessibility
$string['chartalternative'] = 'Chart showing {$a}';
$string['filterform'] = 'Student performance filter form';

// Analytics enhancement - Anomaly details
$string['anomaly_suspicious_speed'] = 'Average response time of {$a->avgtime}s across {$a->count} responses (< 1 second threshold)';
$string['anomaly_perfect_fast'] = 'Perfect score (100%) with average response time of {$a->avgtime}s across {$a->count} responses';

// Analytics enhancement - Additional strings
$string['question'] = 'Question';
$string['page'] = 'Page';
$string['pagination'] = 'Pagination';
$string['topperformer'] = 'Top Performer';
$string['atriskstudent'] = 'At-Risk Student';
$string['nostudentdata'] = 'No student data available for this session';
$string['noatriskstudents'] = 'No at-risk students identified';
$string['nomissingparticipants'] = 'All enrolled students have participated';
$string['lowscore'] = 'Low score';
$string['slowresponse'] = 'Slow response time';
$string['visualizations'] = 'Visualizations';

// Analytics enhancement - Two-tab interface
$string['simpleanalysis'] = 'Simple Analysis';
$string['advancedanalysis'] = 'Advanced Analysis';

// Simple Analysis - Engagement
$string['engagementlevel'] = 'Overall Engagement Level';
$string['engagementhigh'] = 'High engagement - {$a}% of students participated';
$string['engagementmoderate'] = 'Moderate engagement - {$a}% of students participated';
$string['engagementlow'] = 'Low engagement - {$a}% of students participated';

// Simple Analysis - Comprehension
$string['comprehensionsummary'] = 'Lesson Comprehension';
$string['comprehensionstrong'] = 'Most students understood the core concepts';
$string['comprehensionpartial'] = 'Partial understanding with some areas of confusion';
$string['comprehensionweak'] = 'Significant confusion detected - review recommended';
$string['confusedtopics'] = 'Topics needing attention: {$a}';

// Simple Analysis - Activity Counts
$string['activitycounts'] = 'Activity Participation';
$string['questionsanswered'] = 'Questions Answered';
$string['pollsubmissions'] = 'Poll Submissions';
$string['reactions'] = 'Reactions/Clicks';

// Simple Analysis - Responsiveness
$string['responsiveness'] = 'Class Responsiveness';
$string['responsivenessquick'] = 'Class responded quickly today';
$string['responsivenessnormal'] = 'Normal interaction pace';
$string['responsivenessslow'] = 'Interaction pace was slower than usual';
$string['consistentengagement'] = 'Consistent engagement throughout';
$string['fluctuatingengagement'] = 'Attention levels fluctuated during session';

// Advanced Analysis - Concept Difficulty
$string['conceptdifficulty'] = 'Concept Difficulty Insights';
$string['conceptdifficultychart'] = 'Concept Difficulty Chart';
$string['difficultconcepts'] = 'Challenging Topics';
$string['wellunderstoodconcepts'] = 'Well-Understood Topics';
$string['concepteasy'] = 'Easy';
$string['conceptmoderate'] = 'Moderate';
$string['conceptdifficult'] = 'Difficult';

// Advanced Analysis - Timeline
$string['timelinepeak'] = 'Peak engagement';
$string['timelinedip'] = 'Attention dip';

// Advanced Analysis - Response Trends
$string['responsetrends'] = 'Common Response Patterns';
$string['commonwronganswer'] = '{$a}% selected incorrect answer {$b}';
$string['misconception'] = 'Common misconception detected';

// Advanced Analysis - Recommendations
$string['teachingrecommendations'] = 'Teaching Recommendations';
$string['recommendationpacing'] = 'Consider pacing more slowly during introduction segments';
$string['recommendationengagement'] = 'Interactive activities significantly boosted engagement';
$string['recommendationexamples'] = 'Topic "{$a}" needs reinforcement - consider additional examples';
$string['recommendationprompts'] = 'Some quiet periods suggest students may benefit from structured prompts';

// Advanced Analysis - Participation Distribution
$string['participationdistribution'] = 'Participation Distribution';
$string['participationhigh'] = 'High (5+ responses)';
$string['participationmoderate'] = 'Moderate (2-4 responses)';
$string['participationlow'] = 'Low (1 response)';
$string['participationnone'] = 'No participation';
$string['broadparticipation'] = 'Most students engaged at least once';
$string['quietperiodsuggestion'] = 'Some quiet periods suggest students may benefit from structured prompts';

// Advanced Analysis - Response Distribution
$string['lowparticipation'] = 'Low (1 response)';
$string['noparticipation'] = 'None (0 responses)';
$string['studentcount'] = 'Student Count';
$string['timeinterval'] = 'Time Interval';
$string['responsecount'] = 'Response Count';
$string['peak'] = 'Peak';
$string['dip'] = 'Dip';
$string['multichoice'] = 'Multiple Choice';
$string['questiontype'] = 'Question Type';
$string['easy'] = 'Easy';
$string['medium'] = 'Medium';
$string['hard'] = 'Hard';
// Accessibility
$string['tabpanel'] = 'Analytics tab panel';

// Additional analytics strings
$string['participationdetails'] = '{$a->participants} of {$a->total} students participated';
$string['responsivenessdetails'] = 'Average: {$a->avg}s, Median: {$a->median}s';

// Questions Page Improvements
$string['manualquestions'] = 'Manual Questions';
$string['delete_selected'] = 'Delete Selected';
$string['approve_selected'] = 'Approve Selected';
$string['questionsdeleted'] = 'Selected questions deleted successfully';
$string['questionsapproved'] = 'Selected questions approved successfully';
$string['noquestionsselected'] = 'No questions selected';
$string['slide'] = 'Slide';
$string['unknownslide'] = 'Orphaned / Unknown Source';
$string['created'] = 'Created';

// Export
$string['exportoptions'] = 'Export Options';
$string['reporttype'] = 'Report Type';
$string['report_summary'] = 'Session Summary';
$string['report_participation'] = 'Student Participation';
$string['report_questions'] = 'Question Analysis';
$string['report_raw'] = 'Raw Response Data';
$string['export'] = 'Export';
$string['exportanalytics'] = 'Export Analytics';
$string['reporttype_help'] = 'Select the type of report you want to generate:
* **Session Summary**: Overview of engagement, comprehension, and recommendations.
* **Student Participation**: List of students with their scores and participation metrics.
* **Question Analysis**: Detailed breakdown of each question\'s performance and difficulty.
* **Raw Response Data**: Full list of individual student responses for detailed analysis.';

// Export Columns
$string['engagementpercentage'] = 'Engagement Percentage';
$string['avgresponsetime'] = 'Avg Response Time';
$string['difficultconcepts'] = 'Difficult Concepts';
$string['username'] = 'Username';
// Real-time session control strings
$string['sessionnotactive'] = 'Session is not active';
$string['sessionnotpaused'] = 'Session is not paused';
$string['sessionpaused'] = 'Session paused';
$string['sessionresumed'] = 'Session resumed';
$string['connectionregistered'] = 'Connection registered';
$string['connectiondisconnected'] = 'Connection disconnected';

$string['reconnected'] = 'Reconnected to session';
$string['batchsubmitted'] = 'Batch submitted';
$string['invalidresponsesformat'] = 'Invalid responses format';

// Real-time quiz interface strings
$string['offline'] = 'Offline - responses will be saved locally';
$string['reconnecting'] = 'Reconnecting...';
$string['connectionrestored'] = 'Connection restored';
$string['submittingoffline'] = 'Saving response offline...';
$string['pendingsubmissions'] = 'Pending submissions';
$string['quizpaused'] = 'Quiz Paused';
$string['quizresumed'] = 'Quiz resumed';
$string['responsesavedoffline'] = 'Response saved offline';
$string['cachedresponsesubmitted'] = 'Cached response submitted';
$string['submitanswer'] = 'Submit Answer';

// Control panel - session control strings
$string['pause'] = 'Pause';
$string['resume'] = 'Resume';
$string['connectedstudents'] = 'Connected Students';
$string['students'] = 'Students';
$string['loadingstudents'] = 'Loading students...';
$string['sessionstatistics'] = 'Session Statistics';
$string['connected'] = 'Connected';
$string['answered'] = 'Answered';
$string['nostudentsconnected'] = 'No students connected';
$string['searchstudents'] = 'Search students...';

// Enterprise optimization: Scheduled tasks
$string['task:cleanupstaleconnections'] = 'Clean up stale connections';
$string['task:processresponsequeue'] = 'Process response queue';
$string['task:generatenlp'] = 'Generate NLP questions for slide';
$string['task:aggregateanalytics'] = 'Aggregate analytics data';
$string['task:cleanupsessionlogs'] = 'Clean up old session logs';

// Enterprise optimization: Rate limiting
$string['error:ratelimitexceeded'] = 'Rate limit exceeded. Please wait {$a} seconds before trying again.';

// Enterprise optimization: Health check
$string['healthcheck'] = 'Health Check';
$string['healthcheck:database'] = 'Database connectivity';
$string['healthcheck:cache'] = 'Cache system';
$string['healthcheck:sse'] = 'SSE capability';
$string['healthcheck:tables'] = 'Database tables';
$string['healthcheck:diskspace'] = 'Disk space';
$string['healthcheck:memory'] = 'Memory usage';

// Enterprise settings
$string['settings:enterprisesettings'] = 'Enterprise Settings';
$string['settings:enterprisesettings_desc'] = 'Configure enterprise-level settings for large-scale deployments';
$string['settings:logretentiondays'] = 'Log Retention Days';
$string['settings:logretentiondays_desc'] = 'Number of days to retain session logs before cleanup (default: 90)';
$string['settings:connectiontimeout'] = 'Connection Timeout';
$string['settings:connectiontimeout_desc'] = 'SSE connection timeout in seconds before requiring reconnect (default: 30)';
$string['settings:staleconnectionthreshold'] = 'Stale Connection Threshold';
$string['settings:staleconnectionthreshold_desc'] = 'Seconds of inactivity before a connection is considered stale (default: 60)';
$string['settings:analyticswindow'] = 'Analytics Aggregation Window';
$string['settings:analyticswindow_desc'] = 'Minutes between analytics pre-computation runs (default: 60)';
$string['settings:maxconcurrentconnections'] = 'Max Concurrent Connections';
$string['settings:maxconcurrentconnections_desc'] = 'Maximum concurrent SSE connections per session (default: 500)';

// Enterprise scheduled tasks
$string['task:archiveoldsessions'] = 'Archive old quiz sessions';
$string['task:warmactivecaches'] = 'Warm caches for active sessions';

// Enterprise capabilities
$string['classengage:viewownresults'] = 'View own quiz results and performance history';
$string['classengage:exportdata'] = 'Export quiz and analytics data';

// Enterprise analytics cache
$string['analyticscachecleared'] = 'Analytics cache cleared successfully';
$string['analyticscachewarmed'] = 'Analytics cache pre-computed for {$a} active sessions';

// Archive task
$string['sessionsarchived'] = '{$a} old sessions archived successfully';
$string['archiveretentiondays'] = 'Archive sessions older than {$a} days';

$string['connecting'] = 'Connecting...';
$string['waitingforquestion'] = 'Waiting for question...';
$string['waitingtostartdesc'] = 'The quiz will begin when your instructor starts the session.';
$string['backtoactivity'] = 'Back to Activity';
$string['error:cannotloadresults'] = 'Cannot load quiz results';
$string['refresh'] = 'Refresh';

// NLP Question Generator
$string['generatefromtext'] = 'Generate from Text';
$string['generatefromdocument'] = 'Generate from Document';
$string['contenttext'] = 'Content Text';
$string['selectcontentinstructions'] = 'Select the pages and images you want to use for question generation.';
$string['pages_slides'] = 'Pages & Slides';
$string['includeimage'] = 'Include Image: ';
$string['nopagesfound'] = 'No pages found in this document.';
$string['generationsettings'] = 'Generation Settings';
$string['numberofquestions'] = 'Number of Questions';
$string['mixed'] = 'Mixed';

// Help strings
$string['contenttext_help'] = 'Enter the text you want to generate questions from. The AI will analyze this text and create relevant multiple-choice questions.';
$string['generatefromtext_help'] = 'Generate questions directly from any text you copy and paste here.';
$string['generatefromdocument_help'] = 'Select pages and images from your uploaded document to generate questions specific to those sections.';
$string['numberofquestions_help'] = 'Select how many questions you want to generate.';
$string['difficulty_help'] = 'Select the difficulty level for the generated questions. Mixed will generate a variety of difficulties.';
$string['generate'] = 'Generate';

// Cognitive Levels (Bloom's Taxonomy)
$string['cognitivelevel'] = 'Cognitive Level';
$string['cognitivelevel_help'] = 'Determines the type of thinking required (e.g., \'Remember\' for factual recall, \'Analyze\' for connecting ideas). This works alongside Difficulty.';
$string['bloom_remember'] = 'Remember: Recall facts and basic concepts';
$string['bloom_understand'] = 'Understand: Explain ideas or concepts';
$string['bloom_apply'] = 'Apply: Use information in new situations';
$string['bloom_analyze'] = 'Analyze: Draw connections among ideas';
$string['bloom_evaluate'] = 'Evaluate: Justify a stand or decision';
$string['bloom_create'] = 'Create: Produce new or original work';

// Advanced Distribution
$string['advanced_settings'] = 'Advanced Distribution Mode';
$string['distribution_help'] = 'Specify the exact number of questions for each category. Counts must sum to the Total Questions.';
$string['difficulty_distribution'] = 'Difficulty Distribution';
$string['cognitive_distribution'] = 'Cognitive Level Distribution';
$string['total_questions_mismatch'] = 'Total count ({$a->current}) must match the selected Number of Questions ({$a->target}).';


// Wizard Strings
$string['pagerange'] = 'Page Range';
$string['pagerange_help'] = 'Enter page numbers or ranges to select';
$string['showmore'] = 'Show More';
$string['noimages'] = 'No images found on this page.';
$string['inspectingdocument'] = 'Inspecting document structure...';
$string['images'] = 'Images';

// AI Analysis
$string['generateaiinsights'] = 'Generate AI Insights';
$string['generating'] = 'Generating...';
$string['aiinsights'] = 'AI Insights';
$string['aianalysis'] = 'AI Session Analysis';
$string['poweredbyai'] = 'Powered by AI';
$string['executivesummary'] = 'Executive Summary';
$string['strengths'] = 'Strengths';
$string['improvementareas'] = 'Areas for Improvement';
$string['actionableadvice'] = 'Actionable Advice';
$string['aidisclaimer'] = 'This analysis is generated by AI and should be used as a supportive tool for your teaching.';
$string['generatedbyai'] = 'Generated by AI';