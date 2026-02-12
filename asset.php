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
 * Serve extracted NLP image assets.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_once(__DIR__ . '/classes/nlp/storage/document_storage.php');
require_once(__DIR__ . '/classes/nlp/storage/image_asset_storage.php');

use mod_classengage\nlp\storage\document_storage;
use mod_classengage\nlp\storage\image_asset_storage;

$docid = required_param('docid', PARAM_ALPHANUMEXT);
$imageid = required_param('imageid', PARAM_ALPHANUMEXT);

try {
    $meta = document_storage::get_metadata($docid);
} catch (Exception $e) {
    send_file_not_found();
}

$contextid = (int) ($meta['contextid'] ?? 0);
if (empty($contextid)) {
    send_file_not_found();
}

$context = \context::instance_by_id($contextid, MUST_EXIST);
if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
    send_file_not_found();
}

$cm = get_coursemodule_from_id('classengage', $context->instanceid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
require_capability('mod/classengage:view', $context);

$asset = image_asset_storage::get_by_docid($docid, $imageid);
if (!$asset) {
    send_file_not_found();
}

$filepath = $asset['path'];
$ext = $asset['extension'] ?? 'png';
$mimetype = $asset['metadata']['mimeType'] ?? null;
if (empty($mimetype)) {
    switch (strtolower($ext)) {
        case 'jpg':
        case 'jpeg':
            $mimetype = 'image/jpeg';
            break;
        case 'gif':
            $mimetype = 'image/gif';
            break;
        case 'webp':
            $mimetype = 'image/webp';
            break;
        case 'bmp':
            $mimetype = 'image/bmp';
            break;
        default:
            $mimetype = 'image/png';
    }
}

$filename = $imageid . '.' . $ext;
send_file($filepath, $filename, 0, 0, false, false, $mimetype);
