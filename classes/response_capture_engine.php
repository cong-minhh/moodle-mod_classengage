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
 * Response capture engine for real-time quiz sessions
 *
 * Handles receiving, validating, and acknowledging student responses with
 * sub-1-second latency for 200+ concurrent users.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Response result class for single response submissions
 */
class response_result {
    /** @var bool Whether the submission was successful */
    public bool $success;

    /** @var string|null Error message if submission failed */
    public ?string $error;

    /** @var bool|null Whether the answer was correct */
    public ?bool $iscorrect;

    /** @var string|null The correct answer (revealed after submission) */
    public ?string $correctanswer;

    /** @var int|null Response ID if successfully stored */
    public ?int $responseid;

    /** @var bool Whether this was a late submission */
    public bool $islate;

    /** @var int Latency in milliseconds */
    public int $latencyms;

    /**
     * Constructor
     *
     * @param bool $success
     * @param string|null $error
     * @param bool|null $iscorrect
     * @param string|null $correctanswer
     * @param int|null $responseid
     * @param bool $islate
     * @param int $latencyms
     */
    public function __construct(
        bool $success,
        ?string $error = null,
        ?bool $iscorrect = null,
        ?string $correctanswer = null,
        ?int $responseid = null,
        bool $islate = false,
        int $latencyms = 0
    ) {
        $this->success = $success;
        $this->error = $error;
        $this->iscorrect = $iscorrect;
        $this->correctanswer = $correctanswer;
        $this->responseid = $responseid;
        $this->islate = $islate;
        $this->latencyms = $latencyms;
    }
}


/**
 * Batch result class for batch response submissions
 */
class batch_result {
    /** @var bool Whether the batch was processed successfully */
    public bool $success;

    /** @var int Number of responses successfully processed */
    public int $processedcount;

    /** @var int Number of responses that failed */
    public int $failedcount;

    /** @var array Individual results for each response */
    public array $results;

    /** @var string|null Error message if batch processing failed */
    public ?string $error;

    /**
     * Constructor
     *
     * @param bool $success
     * @param int $processedcount
     * @param int $failedcount
     * @param array $results
     * @param string|null $error
     */
    public function __construct(
        bool $success,
        int $processedcount = 0,
        int $failedcount = 0,
        array $results = [],
        ?string $error = null
    ) {
        $this->success = $success;
        $this->processedcount = $processedcount;
        $this->failedcount = $failedcount;
        $this->results = $results;
        $this->error = $error;
    }
}

/**
 * Validation result class for answer format validation
 */
class validation_result {
    /** @var bool Whether the answer format is valid */
    public bool $valid;

    /** @var string|null Error message if validation failed */
    public ?string $error;

    /**
     * Constructor
     *
     * @param bool $valid
     * @param string|null $error
     */
    public function __construct(bool $valid, ?string $error = null) {
        $this->valid = $valid;
        $this->error = $error;
    }
}

/**
 * Response capture engine class
 *
 * Handles response submission, validation, and duplicate detection for real-time quiz sessions.
 * Designed to handle 200+ concurrent users with sub-1-second latency (NFR-01, NFR-03).
 *
 * Requirements: 2.1, 2.2, 2.3, 3.1, 3.5
 */
class response_capture_engine {

    /** @var int Maximum batch size for batch processing */
    const MAX_BATCH_SIZE = 100;

    /** @var int Grace period in seconds for late submissions */
    const LATE_SUBMISSION_GRACE_PERIOD = 5;

    /**
     * Process a single response submission
     *
     * @param int $sessionid Session ID
     * @param int $questionid Question ID
     * @param string $answer The submitted answer
     * @param int $userid User ID
     * @param int|null $clienttimestamp Optional client-side timestamp for late detection
     * @return response_result
     */
    public function submit_response(
        int $sessionid,
        int $questionid,
        string $answer,
        int $userid,
        ?int $clienttimestamp = null
    ): response_result {
        global $DB;

        $starttime = microtime(true);

        try {
            // Get session and validate it's active.
            $session = $DB->get_record('classengage_sessions', ['id' => $sessionid]);
            if (!$session) {
                return new response_result(false, 'Session not found');
            }

            if ($session->status !== 'active') {
                return new response_result(false, 'Session not active');
            }

            // Get question and validate.
            $question = $DB->get_record('classengage_questions', ['id' => $questionid]);
            if (!$question) {
                return new response_result(false, 'Question not found');
            }

            // Validate answer format.
            $validation = $this->validate_response($answer, $question->questiontype);
            if (!$validation->valid) {
                return new response_result(false, $validation->error);
            }

            // Check for duplicate submission.
            if ($this->is_duplicate($sessionid, $questionid, $userid)) {
                return new response_result(false, 'Duplicate submission: already answered this question');
            }

            // Determine if this is a late submission.
            $islate = $this->is_late_submission($session, $clienttimestamp);

            // Check if answer is correct.
            $iscorrect = $this->check_answer($answer, $question);

            // Calculate response time.
            $responsetime = time() - $session->questionstarttime;

            // Calculate score.
            $score = $iscorrect ? 1.0 : 0.0;

            // Save response.
            $response = new \stdClass();
            $response->sessionid = $sessionid;
            $response->questionid = $questionid;
            $response->classengageid = $session->classengageid;
            $response->userid = $userid;
            $response->answer = $answer;
            $response->iscorrect = $iscorrect ? 1 : 0;
            $response->score = $score;
            $response->responsetime = $responsetime;
            $response->timecreated = time();

            $responseid = $DB->insert_record('classengage_responses', $response);

            // Calculate latency.
            $latencyms = (int) ((microtime(true) - $starttime) * 1000);

            return new response_result(
                true,
                null,
                $iscorrect,
                $question->correctanswer,
                $responseid,
                $islate,
                $latencyms
            );

        } catch (\Exception $e) {
            $latencyms = (int) ((microtime(true) - $starttime) * 1000);
            return new response_result(false, 'Internal error: ' . $e->getMessage(), null, null, null, false, $latencyms);
        }
    }


    /**
     * Process batch of responses (for high-load scenarios)
     *
     * Uses database batch operations to minimize connection overhead (Requirement 3.5).
     *
     * @param array $responses Array of response objects with sessionid, questionid, answer, userid, clienttimestamp
     * @return batch_result
     */
    public function submit_batch(array $responses): batch_result {
        global $DB;

        if (empty($responses)) {
            return new batch_result(true, 0, 0, []);
        }

        if (count($responses) > self::MAX_BATCH_SIZE) {
            return new batch_result(false, 0, 0, [], 'Batch size exceeds maximum of ' . self::MAX_BATCH_SIZE);
        }

        $results = [];
        $processedcount = 0;
        $failedcount = 0;

        // Use a transaction for batch processing.
        $transaction = $DB->start_delegated_transaction();

        try {
            // Pre-fetch sessions and questions to minimize database queries.
            $sessionids = array_unique(array_column($responses, 'sessionid'));
            $questionids = array_unique(array_column($responses, 'questionid'));

            $sessions = $DB->get_records_list('classengage_sessions', 'id', $sessionids);
            $questions = $DB->get_records_list('classengage_questions', 'id', $questionids);

            // Pre-check for duplicates in batch.
            $existingresponses = $this->get_existing_responses_batch($responses);

            // Process each response.
            foreach ($responses as $index => $responsedata) {
                $responsedata = (object) $responsedata;

                // Validate session exists and is active.
                if (!isset($sessions[$responsedata->sessionid])) {
                    $results[$index] = new response_result(false, 'Session not found');
                    $failedcount++;
                    continue;
                }

                $session = $sessions[$responsedata->sessionid];
                if ($session->status !== 'active') {
                    $results[$index] = new response_result(false, 'Session not active');
                    $failedcount++;
                    continue;
                }

                // Validate question exists.
                if (!isset($questions[$responsedata->questionid])) {
                    $results[$index] = new response_result(false, 'Question not found');
                    $failedcount++;
                    continue;
                }

                $question = $questions[$responsedata->questionid];

                // Validate answer format.
                $validation = $this->validate_response($responsedata->answer, $question->questiontype);
                if (!$validation->valid) {
                    $results[$index] = new response_result(false, $validation->error);
                    $failedcount++;
                    continue;
                }

                // Check for duplicate.
                $duplicatekey = "{$responsedata->sessionid}_{$responsedata->questionid}_{$responsedata->userid}";
                if (isset($existingresponses[$duplicatekey])) {
                    $results[$index] = new response_result(false, 'Duplicate submission: already answered this question');
                    $failedcount++;
                    continue;
                }

                // Determine if late submission.
                $clienttimestamp = $responsedata->clienttimestamp ?? null;
                $islate = $this->is_late_submission($session, $clienttimestamp);

                // Check if answer is correct.
                $iscorrect = $this->check_answer($responsedata->answer, $question);

                // Calculate response time.
                $responsetime = time() - $session->questionstarttime;

                // Prepare response record.
                $response = new \stdClass();
                $response->sessionid = $responsedata->sessionid;
                $response->questionid = $responsedata->questionid;
                $response->classengageid = $session->classengageid;
                $response->userid = $responsedata->userid;
                $response->answer = $responsedata->answer;
                $response->iscorrect = $iscorrect ? 1 : 0;
                $response->score = $iscorrect ? 1.0 : 0.0;
                $response->responsetime = $responsetime;
                $response->timecreated = time();

                $responseid = $DB->insert_record('classengage_responses', $response);

                // Mark as processed to prevent duplicates within the same batch.
                $existingresponses[$duplicatekey] = true;

                $results[$index] = new response_result(
                    true,
                    null,
                    $iscorrect,
                    $question->correctanswer,
                    $responseid,
                    $islate
                );
                $processedcount++;
            }

            $transaction->allow_commit();

            return new batch_result(true, $processedcount, $failedcount, $results);

        } catch (\Exception $e) {
            $transaction->rollback($e);
            return new batch_result(false, 0, count($responses), [], 'Batch processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate response format based on question type
     *
     * @param string $answer The submitted answer
     * @param string $questiontype The question type (multichoice, truefalse, shortanswer)
     * @return validation_result
     */
    public function validate_response(string $answer, string $questiontype): validation_result {
        // Trim whitespace.
        $answer = trim($answer);

        // Check for empty answer.
        if ($answer === '') {
            return new validation_result(false, 'Answer cannot be empty');
        }

        switch ($questiontype) {
            case 'multichoice':
                // Valid answers are A, B, C, or D (case-insensitive).
                $normalizedanswer = strtoupper($answer);
                if (!in_array($normalizedanswer, ['A', 'B', 'C', 'D'])) {
                    return new validation_result(false, 'Invalid answer format: must be A, B, C, or D');
                }
                break;

            case 'truefalse':
                // Valid answers are TRUE, FALSE, T, F, 1, 0 (case-insensitive).
                $normalizedanswer = strtoupper($answer);
                $validanswers = ['TRUE', 'FALSE', 'T', 'F', '1', '0'];
                if (!in_array($normalizedanswer, $validanswers)) {
                    return new validation_result(false, 'Invalid answer format: must be TRUE, FALSE, T, F, 1, or 0');
                }
                break;

            case 'shortanswer':
                // Short answers have a maximum length.
                if (strlen($answer) > 255) {
                    return new validation_result(false, 'Answer exceeds maximum length of 255 characters');
                }
                break;

            default:
                return new validation_result(false, 'Unknown question type: ' . $questiontype);
        }

        return new validation_result(true);
    }

    /**
     * Check for duplicate submission
     *
     * @param int $sessionid Session ID
     * @param int $questionid Question ID
     * @param int $userid User ID
     * @return bool True if duplicate exists
     */
    public function is_duplicate(int $sessionid, int $questionid, int $userid): bool {
        global $DB;

        return $DB->record_exists('classengage_responses', [
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'userid' => $userid,
        ]);
    }


    /**
     * Check if the submitted answer is correct
     *
     * @param string $answer The submitted answer
     * @param \stdClass $question The question object
     * @return bool True if correct
     */
    protected function check_answer(string $answer, \stdClass $question): bool {
        $normalizedanswer = strtoupper(trim($answer));
        $correctanswer = strtoupper(trim($question->correctanswer));

        switch ($question->questiontype) {
            case 'multichoice':
                return $normalizedanswer === $correctanswer;

            case 'truefalse':
                // Normalize true/false variations.
                $trueanswers = ['TRUE', 'T', '1'];
                $falseanswers = ['FALSE', 'F', '0'];

                $answeristruevariant = in_array($normalizedanswer, $trueanswers);
                $correctistruevariant = in_array($correctanswer, $trueanswers);

                return $answeristruevariant === $correctistruevariant;

            case 'shortanswer':
                // Case-insensitive comparison for short answers.
                return $normalizedanswer === $correctanswer;

            default:
                return false;
        }
    }

    /**
     * Determine if a submission is late (after timer expired)
     *
     * @param \stdClass $session The session object
     * @param int|null $clienttimestamp Optional client-side timestamp
     * @return bool True if late submission
     */
    protected function is_late_submission(\stdClass $session, ?int $clienttimestamp = null): bool {
        $now = time();

        // Calculate when the question timer expired.
        $timerexpiry = $session->questionstarttime + $session->timelimit;

        // If client timestamp is provided, use it for more accurate late detection.
        if ($clienttimestamp !== null) {
            return $clienttimestamp > $timerexpiry;
        }

        // Otherwise use server time.
        return $now > $timerexpiry;
    }

    /**
     * Get existing responses for batch duplicate checking
     *
     * @param array $responses Array of response data
     * @return array Associative array with keys "sessionid_questionid_userid" => true
     */
    protected function get_existing_responses_batch(array $responses): array {
        global $DB;

        if (empty($responses)) {
            return [];
        }

        // Build conditions for batch lookup.
        $conditions = [];
        $params = [];
        $paramindex = 0;

        foreach ($responses as $response) {
            $response = (object) $response;
            $conditions[] = "(sessionid = :sessionid{$paramindex} AND questionid = :questionid{$paramindex} AND userid = :userid{$paramindex})";
            $params["sessionid{$paramindex}"] = $response->sessionid;
            $params["questionid{$paramindex}"] = $response->questionid;
            $params["userid{$paramindex}"] = $response->userid;
            $paramindex++;
        }

        $sql = "SELECT id, sessionid, questionid, userid
                  FROM {classengage_responses}
                 WHERE " . implode(' OR ', $conditions);

        $existingrecords = $DB->get_records_sql($sql, $params);

        // Convert to lookup array.
        $existing = [];
        foreach ($existingrecords as $record) {
            $key = "{$record->sessionid}_{$record->questionid}_{$record->userid}";
            $existing[$key] = true;
        }

        return $existing;
    }

    /**
     * Queue a response for batch processing (used under high load)
     *
     * @param int $sessionid Session ID
     * @param int $questionid Question ID
     * @param string $answer The submitted answer
     * @param int $userid User ID
     * @param int|null $clienttimestamp Optional client-side timestamp
     * @return int Queue entry ID
     */
    public function queue_response(
        int $sessionid,
        int $questionid,
        string $answer,
        int $userid,
        ?int $clienttimestamp = null
    ): int {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid]);
        $islate = $session ? $this->is_late_submission($session, $clienttimestamp) : false;

        $queueentry = new \stdClass();
        $queueentry->sessionid = $sessionid;
        $queueentry->questionid = $questionid;
        $queueentry->userid = $userid;
        $queueentry->answer = $answer;
        $queueentry->client_timestamp = $clienttimestamp;
        $queueentry->server_timestamp = time();
        $queueentry->processed = 0;
        $queueentry->is_late = $islate ? 1 : 0;

        return $DB->insert_record('classengage_response_queue', $queueentry);
    }

    /**
     * Process queued responses from the response queue
     *
     * @param int $limit Maximum number of responses to process
     * @return batch_result
     */
    public function process_queue(int $limit = 100): batch_result {
        global $DB;

        // Get unprocessed queue entries.
        $queueentries = $DB->get_records_select(
            'classengage_response_queue',
            'processed = 0',
            [],
            'server_timestamp ASC',
            '*',
            0,
            $limit
        );

        if (empty($queueentries)) {
            return new batch_result(true, 0, 0, []);
        }

        // Convert to response format.
        $responses = [];
        foreach ($queueentries as $entry) {
            $responses[] = [
                'sessionid' => $entry->sessionid,
                'questionid' => $entry->questionid,
                'answer' => $entry->answer,
                'userid' => $entry->userid,
                'clienttimestamp' => $entry->client_timestamp,
            ];
        }

        // Process the batch.
        $result = $this->submit_batch($responses);

        // Mark processed entries.
        if ($result->success) {
            $ids = array_keys($queueentries);
            list($insql, $params) = $DB->get_in_or_equal($ids);
            $DB->execute("UPDATE {classengage_response_queue} SET processed = 1 WHERE id $insql", $params);
        }

        return $result;
    }
}
