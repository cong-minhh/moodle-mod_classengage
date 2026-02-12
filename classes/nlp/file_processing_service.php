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
 * File processing service for NLP extraction.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/processors/pdf_processor.php');
require_once(__DIR__ . '/processors/pptx_processor.php');
require_once(__DIR__ . '/processors/docx_processor.php');

use mod_classengage\nlp\processors\pdf_processor;
use mod_classengage\nlp\processors\pptx_processor;
use mod_classengage\nlp\processors\docx_processor;

class file_processing_service {
    /**
     * Process a file by extension.
     *
     * @param string $filepath
     * @param string $filename
     * @param array $options
     * @return array
     */
    public function process(string $filepath, string $filename, array $options = []): array {
        $this->check_file_size($filepath);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'pdf':
                $processor = new pdf_processor();
                break;
            case 'pptx':
                $processor = new pptx_processor();
                break;
            case 'docx':
                $processor = new docx_processor();
                break;
            default:
                throw new \Exception('Unsupported file type: ' . $ext);
        }

        return $processor->process($filepath, $options);
    }

    private function check_file_size(string $filepath): void {
        $maxmb = (int) (\get_config('mod_classengage', 'maxfilesize') ?: 50);
        $maxbytes = $maxmb * 1024 * 1024;
        $size = filesize($filepath);
        if ($size === false) {
            return;
        }
        if ($size > $maxbytes) {
            throw new \Exception('File exceeds maximum size (' . $maxmb . 'MB)');
        }
    }
}
