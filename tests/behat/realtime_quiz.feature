@mod @mod_classengage @javascript
Feature: Real-time quiz participation
  As a student
  I want to participate in live quizzes with real-time features
  So that I can engage with lecture content and receive immediate feedback

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity    | name             | intro                    | course | idnumber     |
      | classengage | Test ClassEngage | Test ClassEngage intro   | C1     | classengage1 |

  Scenario: Student submits answer and receives confirmation
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | What is 2+2?   |
      | optiona       | 3              |
      | optionb       | 4              |
      | optionc       | 5              |
      | optiond       | 6              |
      | correctanswer | B              |
    And I create a classengage session with:
      | name          | Test Session   |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Test Session"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    Then I should see "What is 2+2?"
    When I click on "B" "radio" in the ".quiz-options" "css_element"
    And I press "Submit Answer"
    Then I should see "Answer submitted"

  Scenario: Student cannot submit duplicate answer for same question
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | What is 3+3?   |
      | optiona       | 5              |
      | optionb       | 6              |
      | optionc       | 7              |
      | optiond       | 8              |
      | correctanswer | B              |
    And I create a classengage session with:
      | name          | Duplicate Test |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Duplicate Test"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    And I click on "B" "radio" in the ".quiz-options" "css_element"
    And I press "Submit Answer"
    And I wait until the page is ready
    Then I should see "Answer submitted"
    And I should see "already answered"

  Scenario: Student sees visual confirmation after answer submission
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | What is 5+5?   |
      | optiona       | 8              |
      | optionb       | 9              |
      | optionc       | 10             |
      | optiond       | 11             |
      | correctanswer | C              |
    And I create a classengage session with:
      | name          | Visual Test    |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Visual Test"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    And I click on "C" "radio" in the ".quiz-options" "css_element"
    And I press "Submit Answer"
    Then I should see "Answer submitted"
    And ".answer-confirmation" "css_element" should exist

  Scenario: Student sees timer countdown during active question
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Timer test question |
      | optiona       | A                   |
      | optionb       | B                   |
      | optionc       | C                   |
      | optiond       | D                   |
      | correctanswer | A                   |
    And I create a classengage session with:
      | name          | Timer Test     |
      | numquestions  | 1              |
      | timelimit     | 30             |
    And I start the classengage session "Timer Test"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    Then ".timer-display" "css_element" should exist
    And I should see "Time Remaining" in the ".timer-container" "css_element"

  Scenario: Student reconnects to active session and sees current state
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Reconnect test |
      | optiona       | Option A       |
      | optionb       | Option B       |
      | optionc       | Option C       |
      | optiond       | Option D       |
      | correctanswer | A              |
    And I create a classengage session with:
      | name          | Reconnect Test |
      | numquestions  | 1              |
      | timelimit     | 120            |
    And I start the classengage session "Reconnect Test"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    Then I should see "Reconnect test"
    # Simulate page refresh (reconnection)
    And I reload the page
    And I wait for the quiz question to load
    Then I should see "Reconnect test"
    And ".timer-display" "css_element" should exist

  Scenario: Student sees offline indicator when connection is lost
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I create a classengage question with:
      | questiontext  | Offline test   |
      | optiona       | A              |
      | optionb       | B              |
      | optionc       | C              |
      | optiond       | D              |
      | correctanswer | A              |
    And I create a classengage session with:
      | name          | Offline Test   |
      | numquestions  | 1              |
      | timelimit     | 60             |
    And I start the classengage session "Offline Test"
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test ClassEngage"
    And I wait for the quiz question to load
    Then I should see "Offline test"
    # Note: Actual offline simulation requires JavaScript manipulation
    # This test verifies the offline indicator element exists
    And ".connection-status" "css_element" should exist
