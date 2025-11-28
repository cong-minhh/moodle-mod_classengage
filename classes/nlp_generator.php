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

/**
 * NLP question generator class
 */
class nlp_generator {
    
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
    public function generate_questions_from_file($file, $classengageid, $slideid, $numquestions = null) {
        global $DB;
        
        // Get configuration
        $nlpendpoint = get_config('mod_classengage', 'nlpendpoint');
        $apikey = get_config('mod_classengage', 'nlpapikey');
        
        if (empty($nlpendpoint)) {
            throw new \Exception('NLP endpoint not configured');
        }
        
        if ($numquestions === null) {
            $numquestions = get_config('mod_classengage', 'defaultquestions') ?: 10;
        }
        
        // Ensure endpoint ends with /api/generate-from-files
        $endpoint = rtrim($nlpendpoint, '/');
        if (!preg_match('/\/api\/generate-from-files$/', $endpoint)) {
            $endpoint = preg_replace('/\/(api\/)?generate(-from-files)?$/', '', $endpoint) . '/api/generate-from-files';
        }
        
        // Create temporary file from stored_file
        $tmpfile = tempnam(sys_get_temp_dir(), 'moodle_nlp_');
        $file->copy_content_to($tmpfile);
        
        try {
            // Prepare multipart form data
            $boundary = '----WebKitFormBoundary' . uniqid();
            $delimiter = "\r\n";
            
            // Build multipart body
            $postdata = '';
            
            // Add num_questions field
            $postdata .= '--' . $boundary . $delimiter;
            $postdata .= 'Content-Disposition: form-data; name="num_questions"' . $delimiter . $delimiter;
            $postdata .= $numquestions . $delimiter;
            
            // Add file (use "files" without brackets - Node.js multer expects this)
            $postdata .= '--' . $boundary . $delimiter;
            $postdata .= 'Content-Disposition: form-data; name="files"; filename="' . $file->get_filename() . '"' . $delimiter;
            $postdata .= 'Content-Type: ' . $file->get_mimetype() . $delimiter . $delimiter;
            $postdata .= file_get_contents($tmpfile) . $delimiter;
            $postdata .= '--' . $boundary . '--' . $delimiter;
            
            // Build headers
            $headers = array('Content-Type: multipart/form-data; boundary=' . $boundary);
            
            // Add Authorization header only if API key is configured
            if (!empty($apikey)) {
                $headers[] = 'Authorization: Bearer ' . $apikey;
            }
            
            $options = array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 120, // Longer timeout for file processing
                'CURLOPT_HTTPHEADER' => $headers,
            );
            
            // Make HTTP request
            $curl = new \curl();
            $response = $curl->post($endpoint, $postdata, $options);
            
            // Check for HTTP errors
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
                debugging('NLP service returned invalid JSON. Response: ' . substr($response, 0, 500), DEBUG_DEVELOPER);
                throw new \Exception('Invalid response from NLP service (invalid JSON)');
            }
            
            // Check for error field in response
            if (isset($result['error'])) {
                $errormsg = $result['message'] ?? $result['error'];
                debugging('NLP service returned error: ' . $errormsg, DEBUG_DEVELOPER);
                throw new \Exception('NLP service error: ' . $errormsg);
            }
            
            if (!isset($result['questions']) || !is_array($result['questions'])) {
                debugging('NLP service response missing "questions" field. Response keys: ' . implode(', ', array_keys($result)), DEBUG_DEVELOPER);
                throw new \Exception('Invalid response from NLP service (missing questions array)');
            }
            
            if (empty($result['questions'])) {
                throw new \Exception('NLP service returned no questions');
            }
            
            // Store generated questions
            return $this->store_questions($result['questions'], $classengageid, $slideid);
            
        } finally {
            // Clean up temporary file
            if (file_exists($tmpfile)) {
                unlink($tmpfile);
            }
        }
    }
    
    
    /**
     * Store generated questions in database
     *
     * @param array $questions
     * @param int $classengageid
     * @param int $slideid
     * @return array Array of question IDs
     */
    protected function store_questions($questions, $classengageid, $slideid) {
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
            $question->status = 'pending';
            $question->source = 'nlp';
            $question->timecreated = $now;
            $question->timemodified = $now;
            
            $questionids[] = $DB->insert_record('classengage_questions', $question);
        }
        
        return $questionids;
    }
}

