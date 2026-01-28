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
 * NLP question generation class (stub implementation)
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * NLP question generator class
 */
class nlp_generator
{

    /**
     * Generate quiz questions from file using NLP service
     * This is the main entry point - sends file directly to Node.js service
     *
     * @param \stored_file $file Moodle stored file
     * @param int $classengageid Activity instance ID
     * @param int $slideid Slide ID
     * @param int $numquestions Number of questions to generate (optional)
     * @return array Array of generated question IDs
     */
    /**
     * Inspect document content to get structure (pages/images)
     * 
     * @param \stored_file $file Moodle stored file
     * @return array Document inspection result with docId and pages
     */
    public function inspect_document($file)
    {
        $endpoint = '/api/documents/inspect';

        // Create temporary file from stored_file
        $tmpfile = tempnam(sys_get_temp_dir(), 'moodle_nlp_inspect_');
        $file->copy_content_to($tmpfile);

        try {
            $postdata = array(
                'file' => new \CURLFile($tmpfile, $file->get_mimetype(), $file->get_filename())
            );

            // We need to construct multipart manually to handle file upload correctly with Moodle's curl class
            // or use the helper method similar to generate_questions_from_file

            $boundary = '----WebKitFormBoundary' . uniqid();
            $delimiter = "\r\n";

            $body = '';
            // Add file
            $body .= '--' . $boundary . $delimiter;
            $body .= 'Content-Disposition: form-data; name="file"; filename="' . $file->get_filename() . '"' . $delimiter;
            $body .= 'Content-Type: ' . $file->get_mimetype() . $delimiter . $delimiter;
            $body .= file_get_contents($tmpfile) . $delimiter;
            $body .= '--' . $boundary . '--' . $delimiter;

            $headers = array('Content-Type: multipart/form-data; boundary=' . $boundary);

            $response = $this->call_api($endpoint, $body, 'POST', $headers);
            return $response;

        } finally {
            if (file_exists($tmpfile)) {
                unlink($tmpfile);
            }
        }
    }

    /**
     * Generate questions from a previously inspected document
     * 
     * @param string $docid Document ID from inspection
     * @param int $classengageid
     * @param int $slideid
     * @param array $options Generation options (includeSlides, includeImages, etc.)
     * @return array Result with 'questionids', 'metadata', 'provider', 'model', 'analysis'
     */
    public function generate_questions_from_document($docid, $classengageid, $slideid, $options = [])
    {
        $endpoint = '/api/documents/generate';

        $payload = array(
            'docId' => $docid,
            'options' => $options
        );

        // Ensure defaults
        if (!isset($payload['options']['numQuestions'])) {
            $payload['options']['numQuestions'] = get_config('mod_classengage', 'defaultquestions') ?: 10;
        }

        $response = $this->call_api($endpoint, json_encode($payload), 'POST', ['Content-Type: application/json']);

        if (empty($response['questions'])) {
            throw new \Exception('NLP service returned no questions');
        }

        $questionids = $this->store_questions($response['questions'], $classengageid, $slideid);

        // Return extended response with metadata
        return [
            'questionids' => $questionids,
            'count' => count($questionids),
            'provider' => $response['provider'] ?? null,
            'model' => $response['metadata']['model'] ?? null,
            'analysis' => $response['analysis'] ?? null,
            'metadata' => $response['metadata'] ?? null
        ];
    }

    /**
     * Start async question generation from a document (non-blocking)
     * Returns immediately with a job ID for polling.
     * 
     * @param string $docid Document ID from prior inspection
     * @param array $options Generation options
     * @return array Contains 'jobId', 'statusUrl', 'resultUrl'
     */
    public function start_async_generation($docid, $options = [])
    {
        $endpoint = '/api/documents/generate-async';

        $payload = array(
            'docId' => $docid,
            'options' => $options
        );

        // Ensure defaults
        if (!isset($payload['options']['numQuestions'])) {
            $payload['options']['numQuestions'] = get_config('mod_classengage', 'defaultquestions') ?: 10;
        }

        $response = $this->call_api_extended(
            $endpoint,
            json_encode($payload),
            'POST',
            ['Content-Type: application/json'],
            30,   // Short timeout - this returns quickly
            [200, 202]  // Accept both 200 and 202
        );

        if (empty($response['jobId'])) {
            throw new \Exception('NLP service did not return a jobId');
        }

        return [
            'jobId' => $response['jobId'],
            'docId' => $response['docId'] ?? $docid,
            'statusUrl' => $response['statusUrl'] ?? null,
            'resultUrl' => $response['resultUrl'] ?? null,
            'message' => $response['message'] ?? 'Job queued'
        ];
    }

    /**
     * Poll job status from NLP service
     * 
     * @param string $jobid Job ID from start_async_generation
     * @return array Job status with 'status', 'progress', 'error'
     */
    public function poll_job_status($jobid)
    {
        $endpoint = '/api/jobs/' . urlencode($jobid);

        $response = $this->call_api_extended(
            $endpoint,
            '',
            'GET',
            ['Content-Type: application/json'],
            15  // Short timeout for status checks
        );

        return [
            'status' => $response['job']['status'] ?? 'unknown',
            'progress' => $response['job']['progress'] ?? 0,
            'error' => $response['job']['error'] ?? null,
            'createdAt' => $response['job']['createdAt'] ?? null,
            'startedAt' => $response['job']['startedAt'] ?? null,
            'completedAt' => $response['job']['completedAt'] ?? null
        ];
    }

    /**
     * Get completed job result from NLP service
     * 
     * @param string $jobid Job ID
     * @return array The job result containing questions
     */
    public function get_job_result($jobid)
    {
        $endpoint = '/api/jobs/' . urlencode($jobid) . '/result';

        $response = $this->call_api($endpoint, '', 'GET', ['Content-Type: application/json']);

        if (empty($response['result'])) {
            throw new \Exception('Job result is empty or job not completed');
        }

        return $response['result'];
    }

    /**
     * Check remote job status and update the local database (On-Demand Sync)
     * Used by Long-Polling endpoint.
     * 
     * @param int $slideid
     * @return void
     */
    public function check_and_update_job_status($slideid)
    {
        global $DB;

        $slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);

        // Only check if we have a job ID and it's not already final
        if (empty($slide->nlp_job_id) || in_array($slide->nlp_job_status, ['completed', 'failed', 'idle'])) {
            return;
        }

        try {
            $status = $this->poll_job_status($slide->nlp_job_id);

            // Has it changed?
            $status_changed = ($slide->nlp_job_status !== $status['status']);
            $progress_changed = (abs($slide->nlp_job_progress - $status['progress']) >= 5); // 5% granularity update

            if ($status_changed || $progress_changed) {
                // Don't overwrite if it just finished concurrently, handled by lock usually but being safe
                $update = new \stdClass();
                $update->id = $slide->id;
                $update->nlp_job_status = $status['status'];
                $update->nlp_job_progress = $status['progress'];
                $update->timemodified = time(); // Important for long-poll loop detection

                if ($status['status'] === 'failed') {
                    $update->nlp_job_error = $status['error'];
                }

                $DB->update_record('classengage_slides', $update);

                // If completed, fetch results immediately
                if ($status['status'] === 'completed') {
                    $result = $this->get_job_result($slide->nlp_job_id);
                    $classengage = $DB->get_record('classengage', ['id' => $slide->classengageid]);

                    // Transaction to store questions safely
                    $transaction = $DB->start_delegated_transaction();
                    $questionids = $this->store_questions($result['questions'], $classengage->id, $slideid);

                    $final_update = new \stdClass();
                    $final_update->id = $slide->id;
                    $final_update->nlp_questions_count = count($questionids);
                    $final_update->nlp_job_completed = time();
                    $final_update->nlp_provider = $result['provider'] ?? null;
                    $final_update->nlp_model = $result['metadata']['model'] ?? null;
                    $final_update->nlp_generation_metadata = isset($result['metadata']) ? json_encode($result['metadata']) : null;
                    $DB->update_record('classengage_slides', $final_update);

                    $transaction->allow_commit();
                }
            }

        } catch (\Exception $e) {
            // Log but don't crash the poll loop - maybe network blip
            debugging("Error updating job status for slide $slideid: " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Complete async generation flow: poll until done, then store questions
     * This is the robust replacement for synchronous generation.
     * 
     * @param string $docid Document ID
     * @param int $classengageid Activity instance ID
     * @param int $slideid Slide ID
     * @param array $options Generation options
     * @param int $maxwait Maximum seconds to wait (default 600 = 10 minutes)
     * @param int $pollinterval Seconds between polls (default 2)
     * @return array Result with 'questionids', 'metadata', etc.
     */
    public function generate_questions_async($docid, $classengageid, $slideid, $options = [], $maxwait = 600, $pollinterval = 2)
    {
        // 1. Start async job
        $jobinfo = $this->start_async_generation($docid, $options);
        $jobid = $jobinfo['jobId'];

        // 2. Poll until complete (with timeout)
        $starttime = time();
        $laststatus = null;

        while (true) {
            $elapsed = time() - $starttime;
            if ($elapsed > $maxwait) {
                throw new \Exception("Generation timed out after {$maxwait} seconds (job: {$jobid})");
            }

            $status = $this->poll_job_status($jobid);
            $laststatus = $status;

            if ($status['status'] === 'completed') {
                break;
            }

            if ($status['status'] === 'failed') {
                $errmsg = $status['error'] ?? 'Unknown error';
                throw new \Exception("Generation failed: {$errmsg}");
            }

            if ($status['status'] === 'cancelled') {
                throw new \Exception('Generation was cancelled');
            }

            // Wait before next poll
            sleep($pollinterval);
        }

        // 3. Get result
        $result = $this->get_job_result($jobid);

        if (empty($result['questions'])) {
            throw new \Exception('NLP service returned no questions');
        }

        // 4. Store questions
        $questionids = $this->store_questions($result['questions'], $classengageid, $slideid);

        return [
            'questionids' => $questionids,
            'count' => count($questionids),
            'jobId' => $jobid,
            'provider' => $result['provider'] ?? null,
            'model' => $result['metadata']['model'] ?? null,
            'analysis' => $result['analysis'] ?? null,
            'metadata' => $result['metadata'] ?? null
        ];
    }

    /**
     * Generate questions from raw text
     * 
     * @param string $text Content text
     * @param int $classengageid
     * @param int $numquestions
     * @param string $difficulty
     * @return array Array of generated question IDs
     */
    public function generate_questions_from_text($text, $classengageid, $numquestions = 10, $difficulty = 'medium')
    {
        $endpoint = '/api/generate';

        $payload = array(
            'text' => $text,
            'numQuestions' => $numquestions,
            'difficulty' => $difficulty
        );

        $response = $this->call_api($endpoint, json_encode($payload), 'POST', ['Content-Type: application/json']);

        if (empty($response['questions'])) {
            throw new \Exception('NLP service returned no questions');
        }

        // For text generation, slideid is 0 (or null)
        return $this->store_questions($response['questions'], $classengageid, 0);
    }

    /**
     * Analyze class session data using AI
     * 
     * @param array $sessiondata
     * @param array $options
     * @return array Analysis result
     */
    public function analyze_session($sessiondata, $options = [])
    {
        $endpoint = '/api/analyze-session';

        $payload = array(
            'session_data' => $sessiondata,
            'options' => $options
        );

        return $this->call_api($endpoint, json_encode($payload), 'POST', ['Content-Type: application/json']);
    }

    /**
     * Legacy method for backward compatibility
     */
    public function generate_questions_from_file($file, $classengageid, $slideid, $numquestions = null)
    {
        // This could be refactored to use inspect -> generate flow, 
        // but for now keeping the direct file upload endpoint if the API supports it
        // or mapping to the new flow.
        // Assuming the API still supports /api/generate-from-files as per doc

        $endpoint = '/api/generate-from-files';

        if ($numquestions === null) {
            $numquestions = get_config('mod_classengage', 'defaultquestions') ?: 10;
        }

        // Create temporary file from stored_file
        $tmpfile = tempnam(sys_get_temp_dir(), 'moodle_nlp_');
        $file->copy_content_to($tmpfile);

        try {
            $boundary = '----WebKitFormBoundary' . uniqid();
            $delimiter = "\r\n";

            $postdata = '';
            $postdata .= '--' . $boundary . $delimiter;
            $postdata .= 'Content-Disposition: form-data; name="numQuestions"' . $delimiter . $delimiter;
            $postdata .= $numquestions . $delimiter;

            $postdata .= '--' . $boundary . $delimiter;
            $postdata .= 'Content-Disposition: form-data; name="files"; filename="' . $file->get_filename() . '"' . $delimiter;
            $postdata .= 'Content-Type: ' . $file->get_mimetype() . $delimiter . $delimiter;
            $postdata .= file_get_contents($tmpfile) . $delimiter;
            $postdata .= '--' . $boundary . '--' . $delimiter;

            $headers = array('Content-Type: multipart/form-data; boundary=' . $boundary);

            $result = $this->call_api($endpoint, $postdata, 'POST', $headers);

            return $this->store_questions($result['questions'], $classengageid, $slideid);

        } finally {
            if (file_exists($tmpfile)) {
                unlink($tmpfile);
            }
        }
    }

    /**
     * Helper to make API calls
     */
    protected function call_api($endpoint, $postdata, $method = 'POST', $extraheaders = [])
    {
        $baseurl = get_config('mod_classengage', 'nlpendpoint');
        $apikey = get_config('mod_classengage', 'nlpapikey');

        if (empty($baseurl)) {
            throw new \Exception('NLP endpoint not configured');
        }

        $baseurl = rtrim($baseurl, '/');
        // Handle if user put full URL or just base
        if (strpos($endpoint, 'http') === 0) {
            $url = $endpoint;
        } else {
            // Remove /api prefix from endpoint if baseurl already has it, or robust joining
            // User provided baseurl: http://localhost:3000
            // Endpoint: /api/generate
            $url = $baseurl . $endpoint;
        }

        $headers = $extraheaders;
        if (!empty($apikey)) {
            $headers[] = 'Authorization: Bearer ' . $apikey;
        }

        $options = array(
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_HTTPHEADER' => $headers,
        );

        $curl = new \curl();

        if ($method === 'POST') {
            $response = $curl->post($url, $postdata, $options);
        } else {
            $response = $curl->get($url, $postdata, $options);
        }

        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($curl->get_errno()) {
            throw new \Exception('NLP service connection failed: ' . $curl->error);
        }

        if ($httpcode !== 200) {
            debugging('NLP service returned HTTP ' . $httpcode . '. Response: ' . substr($response, 0, 500), DEBUG_DEVELOPER);
            throw new \Exception('NLP service returned error (HTTP ' . $httpcode . ')');
        }

        $result = json_decode($response, true);

        if (!$result) {
            throw new \Exception('Invalid response from NLP service (invalid JSON)');
        }

        if (isset($result['error'])) {
            // Handle "success": false case too
            $errormsg = $result['message'] ?? $result['error'];
            throw new \Exception('NLP service error: ' . $errormsg);
        }

        return $result;
    }

    /**
     * Extended API helper with configurable timeout and accepted status codes
     * 
     * @param string $endpoint API endpoint
     * @param string $postdata POST data or empty for GET
     * @param string $method HTTP method
     * @param array $extraheaders Extra headers
     * @param int $timeout Request timeout in seconds
     * @param array $acceptedcodes HTTP status codes to accept as success
     * @return array Decoded response
     */
    protected function call_api_extended($endpoint, $postdata, $method = 'POST', $extraheaders = [], $timeout = 30, $acceptedcodes = [200])
    {
        $baseurl = get_config('mod_classengage', 'nlpendpoint');
        $apikey = get_config('mod_classengage', 'nlpapikey');

        if (empty($baseurl)) {
            throw new \Exception('NLP endpoint not configured');
        }

        $baseurl = rtrim($baseurl, '/');
        if (strpos($endpoint, 'http') === 0) {
            $url = $endpoint;
        } else {
            $url = $baseurl . $endpoint;
        }

        $headers = $extraheaders;
        if (!empty($apikey)) {
            $headers[] = 'Authorization: Bearer ' . $apikey;
        }

        $options = array(
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_HTTPHEADER' => $headers,
        );

        $curl = new \curl();

        if ($method === 'POST') {
            $response = $curl->post($url, $postdata, $options);
        } else {
            $response = $curl->get($url, [], $options);
        }

        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($curl->get_errno()) {
            throw new \Exception('NLP service connection failed: ' . $curl->error);
        }

        if (!in_array($httpcode, $acceptedcodes)) {
            debugging('NLP service returned HTTP ' . $httpcode . '. Response: ' . substr($response, 0, 500), DEBUG_DEVELOPER);
            throw new \Exception('NLP service returned error (HTTP ' . $httpcode . ')');
        }

        $result = json_decode($response, true);

        if (!$result) {
            throw new \Exception('Invalid response from NLP service (invalid JSON)');
        }

        if (isset($result['error']) && $result['success'] === false) {
            $errormsg = $result['message'] ?? $result['error'];
            throw new \Exception('NLP service error: ' . $errormsg);
        }

        return $result;
    }


    /**
     * Store generated questions in database
     *
     * @param array $questions
     * @param int $classengageid
     * @param int $slideid
     * @return array Array of question IDs
     */
    protected function store_questions($questions, $classengageid, $slideid)
    {
        global $DB;

        $questionids = array();
        $now = time();

        foreach ($questions as $q) {
            $question = new \stdClass();
            $question->classengageid = $classengageid;
            $question->slideid = $slideid;
            $question->questiontext = $q['questiontext'];
            $question->questiontype = 'multichoice';
            $question->optiona = $q['optiona'];
            $question->optionb = $q['optionb'];
            $question->optionc = $q['optionc'];
            $question->optiond = $q['optiond'];
            $question->correctanswer = $q['correctanswer'];
            $question->difficulty = $q['difficulty'] ?? 'medium';
            // Check multiple possible key names for bloom/cognitive level
            $question->bloomlevel = $q['bloomLevel'] ?? $q['bloomlevel'] ?? $q['bloom_level']
                ?? $q['cognitiveLevel'] ?? $q['cognitive_level'] ?? null;
            $question->rationale = $q['rationale'] ?? null;
            // Store source attribution (slides and images used for generation)
            $question->sources = !empty($q['sources']) ? json_encode($q['sources']) : null;
            // Store the specific image path this question references (relative, e.g., /assets/img_xxx?docId=yyy)
            // Full URL is constructed at display time using nlppublicurl config
            $question->question_image = $q['question_image'] ?? null;
            $question->status = 'pending';
            $question->source = 'nlp';
            $question->timecreated = $now;
            $question->timemodified = $now;

            $questionids[] = $DB->insert_record('classengage_questions', $question);
        }

        return $questionids;
    }
}

