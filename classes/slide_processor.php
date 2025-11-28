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
 * Slide processor class for handling slide uploads and text extraction
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Slide processor class
 */
class slide_processor {
    
    /** @var \context_module Context */
    protected $context;
    
    /** @var string Configuration key for NLP endpoint */
    const CONFIG_NLP_ENDPOINT = 'nlpendpoint';
    
    /** @var string Configuration key for auto-generation */
    const CONFIG_AUTO_GENERATE = 'autogeneratequestions';
    
    /** @var string Component name for file storage */
    const COMPONENT = 'mod_classengage';
    
    /** @var string File area for slides */
    const FILEAREA_SLIDES = 'slides';
    
    /** @var array Allowed MIME types for slide uploads */
    const ALLOWED_MIMETYPES = [
        'application/pdf',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    
    /**
     * Constructor
     *
     * @param \context_module $context Module context
     */
    public function __construct($context) {
        $this->context = $context;
    }
    
    /**
     * Process uploaded slide file
     *
     * Validates, stores, and optionally triggers question generation for uploaded slides.
     *
     * @param object $data Form data containing slidefile (draft itemid) and title
     * @param int $classengageid Activity instance ID
     * @param int $userid User ID performing the upload
     * @return int Slide ID on success
     * @throws \moodle_exception If validation fails or file cannot be processed
     */
    public function process_upload($data, $classengageid, $userid) {
        global $DB;
        
        // Validate and retrieve file.
        $file = $this->get_draft_file($data->slidefile, $userid);
        
        // Validate file type.
        $this->validate_file($file);
        
        // Use transaction for data integrity.
        $transaction = $DB->start_delegated_transaction();
        
        try {
            // Create slide database record.
            $slideid = $this->create_slide_record($data, $file, $classengageid, $userid);
            
            // Store file permanently.
            $storedfile = $this->store_file($file, $slideid);
            
            // Mark slide as completed.
            $this->update_slide_status($slideid, 'completed');
            
            // Commit transaction before triggering async operations.
            $transaction->allow_commit();
            
            // Trigger question generation (non-blocking).
            $this->trigger_question_generation($storedfile, $classengageid, $slideid);
            
            return $slideid;
            
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw new \moodle_exception('errorprocessingslide', 'mod_classengage', '', null, $e->getMessage());
        }
    }
    
    /**
     * Get file from draft area
     *
     * @param int $draftitemid Draft item ID
     * @param int $userid User ID
     * @return \stored_file The uploaded file
     * @throws \moodle_exception If no file found
     */
    protected function get_draft_file($draftitemid, $userid) {
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        
        if (empty($draftfiles)) {
            throw new \moodle_exception('nofileuploaded', 'mod_classengage');
        }
        
        return reset($draftfiles);
    }
    
    /**
     * Validate uploaded file
     *
     * @param \stored_file $file File to validate
     * @throws \moodle_exception If file is invalid
     */
    protected function validate_file($file) {
        $mimetype = $file->get_mimetype();
        
        if (!in_array($mimetype, self::ALLOWED_MIMETYPES)) {
            throw new \moodle_exception('invalidfiletype', 'mod_classengage', '', $mimetype);
        }
        
        // Check file size against Moodle's maximum.
        $maxbytes = get_config('mod_classengage', 'maxbytes');
        if ($maxbytes && $file->get_filesize() > $maxbytes) {
            throw new \moodle_exception('filesizeexceeded', 'mod_classengage');
        }
    }
    
    /**
     * Create slide database record
     *
     * @param object $data Form data
     * @param \stored_file $file Uploaded file
     * @param int $classengageid Activity ID
     * @param int $userid User ID
     * @return int Slide ID
     */
    protected function create_slide_record($data, $file, $classengageid, $userid) {
        global $DB;
        
        $slide = new \stdClass();
        $slide->classengageid = $classengageid;
        $slide->title = $data->title;
        $slide->filename = $file->get_filename();
        $slide->filepath = $file->get_filepath();
        $slide->filesize = $file->get_filesize();
        $slide->mimetype = $file->get_mimetype();
        $slide->status = 'uploaded';
        $slide->userid = $userid;
        $slide->timecreated = time();
        $slide->timemodified = time();
        
        return $DB->insert_record('classengage_slides', $slide);
    }
    
    /**
     * Store file in permanent area
     *
     * @param \stored_file $file Source file
     * @param int $slideid Slide ID for itemid
     * @return \stored_file Stored file
     */
    protected function store_file($file, $slideid) {
        $fs = get_file_storage();
        
        $filerecord = [
            'contextid' => $this->context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA_SLIDES,
            'itemid' => $slideid,
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ];
        
        return $fs->create_file_from_storedfile($filerecord, $file);
    }
    
    /**
     * Update slide status
     *
     * @param int $slideid Slide ID
     * @param string $status New status
     */
    protected function update_slide_status($slideid, $status) {
        global $DB;
        $DB->set_field('classengage_slides', 'status', $status, ['id' => $slideid]);
    }
    
    /**
     * Trigger question generation if configured
     *
     * This is non-blocking - failures won't affect the upload.
     *
     * @param \stored_file $file Stored file
     * @param int $classengageid Activity ID
     * @param int $slideid Slide ID
     */
    protected function trigger_question_generation($file, $classengageid, $slideid) {
        if (!$this->is_auto_generation_enabled()) {
            return;
        }
        
        try {
            $generator = new nlp_generator();
            $generator->generate_questions_from_file($file, $classengageid, $slideid);
        } catch (\Exception $e) {
            // Log but don't fail - question generation is optional.
            debugging('Auto-generation of questions failed for slide ' . $slideid . ': ' . 
                     $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    
    /**
     * Check if auto-generation is enabled
     *
     * @return bool True if NLP service is configured and auto-generation is enabled
     */
    protected function is_auto_generation_enabled() {
        $nlpendpoint = get_config('mod_classengage', self::CONFIG_NLP_ENDPOINT);
        $autogenerate = get_config('mod_classengage', self::CONFIG_AUTO_GENERATE);
        
        return !empty($nlpendpoint) && $autogenerate;
    }

}

