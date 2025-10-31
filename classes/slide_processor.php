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
 * @copyright  2025 Your Name
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
    
    /**
     * Constructor
     *
     * @param \context_module $context
     */
    public function __construct($context) {
        $this->context = $context;
    }
    
    /**
     * Process uploaded slide file
     *
     * @param object $data Form data
     * @param int $classengageid Activity instance ID
     * @param int $userid User ID
     * @return int|bool Slide ID on success, false on failure
     */
    public function process_upload($data, $classengageid, $userid) {
        global $DB;
        
        try {
            $fs = get_file_storage();
            
            // Get the file from draft area
            $draftitemid = $data->slidefile;
            $draftfiles = $fs->get_area_files(\context_user::instance($userid)->id, 'user', 'draft', $draftitemid, 'id', false);
            
            if (empty($draftfiles)) {
                return false;
            }
            
            $file = reset($draftfiles);
            
            // Create slide record
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
            
            $slideid = $DB->insert_record('classengage_slides', $slide);
            
            // Save file to permanent area
            $filerecord = array(
                'contextid' => $this->context->id,
                'component' => 'mod_classengage',
                'filearea' => 'slides',
                'itemid' => $slideid,
                'filepath' => '/',
                'filename' => $file->get_filename(),
            );
            
            $storedfile = $fs->create_file_from_storedfile($filerecord, $file);
            
            // Extract text content (fallback method)
            $extractedtext = $this->extract_text($storedfile);
            
            // Update slide record with extracted text
            $DB->set_field('classengage_slides', 'extractedtext', $extractedtext, array('id' => $slideid));
            $DB->set_field('classengage_slides', 'status', 'completed', array('id' => $slideid));
            
            // Auto-generate questions if NLP service is configured
            $nlpendpoint = get_config('mod_classengage', 'nlpendpoint');
            $autogenerate = get_config('mod_classengage', 'autogeneratequestions');
            
            if (!empty($nlpendpoint) && $autogenerate) {
                try {
                    $generator = new nlp_generator();
                    // Send file directly to NLP service for text extraction and question generation
                    $generator->generate_questions_from_file($storedfile, $classengageid, $slideid);
                } catch (\Exception $e) {
                    // Don't fail the upload if question generation fails
                    debugging('Auto-generation of questions failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            
            return $slideid;
            
        } catch (\Exception $e) {
            debugging('Error processing slide upload: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    
    /**
     * Extract text from uploaded file
     *
     * @param \stored_file $file
     * @return string Extracted text
     */
    protected function extract_text($file) {
        $mimetype = $file->get_mimetype();
        
        // For now, return a placeholder
        // In a real implementation, this would use libraries like:
        // - pdftotext for PDF files
        // - PhpOffice/PhpPresentation for PPT/PPTX files
        
        $text = "Extracted text from " . $file->get_filename() . "\n\n";
        $text .= "This is a placeholder for extracted slide content. ";
        $text .= "In a production environment, this would contain the actual text extracted from the slides. ";
        $text .= "The extraction would use specialized libraries based on the file type:\n";
        $text .= "- PDF: pdftotext or similar\n";
        $text .= "- PPT/PPTX: PhpOffice/PhpPresentation or similar\n\n";
        $text .= "Sample content: What is the capital of France? Paris is the capital of France. ";
        $text .= "Machine learning is a subset of artificial intelligence. ";
        $text .= "The process of photosynthesis converts light energy into chemical energy.";
        
        return $text;
    }
    
    /**
     * Extract text from PDF file
     *
     * @param \stored_file $file
     * @return string
     */
    protected function extract_text_from_pdf($file) {
        // TODO: Implement PDF text extraction
        // This would use a library like pdftotext or similar
        return '';
    }
    
    /**
     * Extract text from PowerPoint file
     *
     * @param \stored_file $file
     * @return string
     */
    protected function extract_text_from_ppt($file) {
        // TODO: Implement PPT text extraction
        // This would use a library like PhpOffice/PhpPresentation
        return '';
    }
}

