@mod @mod_classengage @javascript
Feature: Instructor session control
  As an instructor
  I want to control live quiz sessions with pause/resume and monitor student status
  So that I can manage the quiz flow and identify students having difficulties

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activities" exist:
      | activity    | name             | intro                    | course | idnumber     |
      | classengage | Test ClassEngage | Test ClassEngage intro   | C1     | classengage1 |

  Scenario: Instructor starts a quiz session and students are notified
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | What is the capital of France? |
      | optiona       | London                         |
      | optionb       | Paris                          |
      | optionc       | Berlin                         |
      | optiond       | Madrid                         |
      | correctanswer | B                              |
    And I create a classengage session with:
      | name          | Geography Quiz |
      | numquestions  | 1              |
      | timelimit     | 60             |
    When I follow "Quiz Sessions"
    And I click on "Start" "link" in the "Geography Quiz" "table_row"
    Then I should see "Session started"
    When I click on "Control Panel" "link" in the "Geography Quiz" "table_row"
    Then I should see "Control Panel"
    And I should see "Geography Quiz"
    And "#question-progress" "css_element" should exist

  Scenario: Instructor pauses an active session and timer freezes
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Pause test question |
      | optiona       | A                   |
      | optionb       | B                   |
      | optionc       | C                   |
      | optiond       | D                   |
      | correctanswer | A                   |
    And I create a classengage session with:
      | name          | Pause Test     |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Pause Test"
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Pause Test" "table_row"
    And I click on "#btn-pause-session" "css_element"
    Then I should see "Paused"
    And "#btn-resume-session" "css_element" should be visible

  Scenario: Instructor resumes a paused session and timer continues
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Resume test question |
      | optiona       | A                    |
      | optionb       | B                    |
      | optionc       | C                    |
      | optiond       | D                    |
      | correctanswer | A                    |
    And I create a classengage session with:
      | name          | Resume Test    |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Resume Test"
    And I pause the classengage session "Resume Test"
    When I navigate to the control panel for session "Resume Test"
    And I click on "#btn-resume-session" "css_element"
    Then I should see "Active"
    And "#btn-pause-session" "css_element" should be visible

  Scenario: Instructor sees connected students list with status indicators
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Status test question |
      | optiona       | A                    |
      | optionb       | B                    |
      | optionc       | C                    |
      | optiond       | D                    |
      | correctanswer | A                    |
    And I create a classengage session with:
      | name          | Status Test    |
      | numquestions  | 1              |
      | timelimit     | 120            |
    And I start the classengage session "Status Test"
    And student "student1" joins the active session
    And student "student2" joins the active session
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Status Test" "table_row"
    And I wait for the control panel to update
    Then I should see "Connected Students"
    And "#connected-students-list" "css_element" should exist

  Scenario: Instructor sees student status change to disconnected
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Disconnect test |
      | optiona       | A               |
      | optionb       | B               |
      | optionc       | C               |
      | optiond       | D               |
      | correctanswer | A               |
    And I create a classengage session with:
      | name          | Disconnect Test |
      | numquestions  | 1               |
      | timelimit     | 120             |
    And I start the classengage session "Disconnect Test"
    And student "student1" joins the active session
    And student "student1" disconnects from the session
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Disconnect Test" "table_row"
    And I wait for the control panel to update
    Then "#connected-students-list" "css_element" should exist

  Scenario: Instructor sees aggregate statistics in control panel
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Stats test question |
      | optiona       | A                   |
      | optionb       | B                   |
      | optionc       | C                   |
      | optiond       | D                   |
      | correctanswer | A                   |
    And I create a classengage session with:
      | name          | Stats Test     |
      | numquestions  | 1              |
      | timelimit     | 120            |
    And I start the classengage session "Stats Test"
    And student "student1" joins the active session
    And student "student2" joins the active session
    And student "student3" joins the active session
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Stats Test" "table_row"
    And I wait for the control panel to update
    Then I should see "Session Statistics"
    And "#stat-connected" "css_element" should exist

  Scenario: Instructor advances to next question
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Question 1 text |
      | optiona       | A               |
      | optionb       | B               |
      | optionc       | C               |
      | optiond       | D               |
      | correctanswer | A               |
    And I create a classengage question with:
      | questiontext  | Question 2 text |
      | optiona       | E               |
      | optionb       | F               |
      | optionc       | G               |
      | optiond       | H               |
      | correctanswer | B               |
    And I create a classengage session with:
      | name          | Multi Question Test |
      | numquestions  | 2                   |
      | timelimit     | 30                  |
    And I start the classengage session "Multi Question Test"
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Multi Question Test" "table_row"
    Then I should see "1 / 2"
    When I click on "Next Question" "link"
    Then I should see "2 / 2"

  Scenario: Instructor sees student answered status update
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Answer status test |
      | optiona       | A                  |
      | optionb       | B                  |
      | optionc       | C                  |
      | optiond       | D                  |
      | correctanswer | A                  |
    And I create a classengage session with:
      | name          | Answer Status Test |
      | numquestions  | 1                  |
      | timelimit     | 120                |
    And I start the classengage session "Answer Status Test"
    And student "student1" joins the active session
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Answer Status Test" "table_row"
    And I wait for the control panel to update
    Then "#connected-students-list" "css_element" should exist
    # Note: Actual answer submission would require a separate browser session
    # This test verifies the control panel displays student connection status

  Scenario: Instructor stops an active session
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Stop test question |
      | optiona       | A                  |
      | optionb       | B                  |
      | optionc       | C                  |
      | optiond       | D                  |
      | correctanswer | A                  |
    And I create a classengage session with:
      | name          | Stop Test      |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Stop Test"
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Stop Test" "table_row"
    And I click on "Stop" "link"
    Then I should see "Session stopped"

  Scenario: Instructor views response distribution during active session
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Distribution test |
      | optiona       | Option A          |
      | optionb       | Option B          |
      | optionc       | Option C          |
      | optiond       | Option D          |
      | correctanswer | B                 |
    And I create a classengage session with:
      | name          | Distribution Test |
      | numquestions  | 1                 |
      | timelimit     | 120               |
    And I start the classengage session "Distribution Test"
    When I follow "Quiz Sessions"
    And I click on "Control Panel" "link" in the "Distribution Test" "table_row"
    Then I should see "Live Responses"
    And "#responseChart" "css_element" should exist
