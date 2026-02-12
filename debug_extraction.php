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
 * Debug page for extraction results.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/nlp/file_processing_service.php');
require_once(__DIR__ . '/classes/nlp/storage/document_storage.php');
require_once(__DIR__ . '/classes/nlp/storage/image_asset_storage.php');

$slideid = required_param('slideid', PARAM_INT);
$refresh = optional_param('refresh', 0, PARAM_INT);

$slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', ['id' => $slide->classengageid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($cm->course, false, $cm);
require_capability('mod/classengage:uploadslides', $context);

$PAGE->set_url(new moodle_url('/mod/classengage/debug_extraction.php', ['slideid' => $slideid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Extraction Debug');
$PAGE->set_heading('Extraction Debug');

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slideid, 'id', false);
if (empty($files)) {
    throw new moodle_exception('Slide file not found');
}
$file = reset($files);

$docinfo = \mod_classengage\nlp\storage\document_storage::ensure_document(
    $file,
    $context->id,
    $classengage->id,
    $slideid
);
$docid = $docinfo['docId'];

$extraction = \mod_classengage\nlp\storage\document_storage::get_extraction($docid);
if ($extraction === null || $refresh) {
    $processor = new \mod_classengage\nlp\file_processing_service();
    $extraction = $processor->process($docinfo['path'], $file->get_filename(), [
        'docId' => $docid,
    ]);
    \mod_classengage\nlp\storage\document_storage::save_extraction($docid, $extraction);
}

$pages = $extraction['pages'] ?? [];
$imagick = class_exists('Imagick') ? 'yes' : 'no';
$pdftotext = (is_executable('/usr/bin/pdftotext') || is_executable('/usr/local/bin/pdftotext')) ? 'yes' : 'no';

$slides = $DB->get_records('classengage_slides', ['classengageid' => $classengage->id], 'id ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading('Extraction Debug', 3);

if (!empty($slides)) {
    echo html_writer::tag('h4', 'Slides');
    echo html_writer::start_tag('div', [
        'style' => 'display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;'
    ]);

    foreach ($slides as $s) {
        $label = trim($s->title . ' (' . $s->filename . ')');
        $url = new moodle_url('/mod/classengage/debug_extraction.php', ['slideid' => $s->id]);
        $attrs = [
            'class' => 'btn btn-sm ' . ((int) $s->id === (int) $slideid ? 'btn-primary' : 'btn-outline-secondary'),
            'title' => 'Slide ID ' . (int) $s->id,
        ];
        echo html_writer::link($url, s($label), $attrs);
    }

    echo html_writer::end_tag('div');
}

echo html_writer::tag('p', '<strong>Doc ID:</strong> ' . s($docid));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/mod/classengage/debug_extraction.php', ['slideid' => $slideid, 'refresh' => 1]),
    'Re-extract content'
));
echo html_writer::tag('p', '<strong>File:</strong> ' . s($file->get_filename()));
echo html_writer::tag('p', '<strong>Imagick available:</strong> ' . s($imagick));
echo html_writer::tag('p', '<strong>pdftotext available:</strong> ' . s($pdftotext));
echo html_writer::tag('p', '<strong>Pages:</strong> ' . count($pages));

if (empty($pages)) {
    echo html_writer::tag('div', 'No pages extracted.', ['class' => 'alert alert-warning']);
} else {
    foreach ($pages as $page) {
        $pagenum = (int) ($page['page'] ?? 0);
        $text = (string) ($page['text'] ?? '');
        $images = $page['images'] ?? [];

        echo html_writer::empty_tag('hr');
        echo html_writer::tag('h4', 'Page ' . $pagenum);

        if ($text !== '') {
            echo html_writer::tag('pre', s($text), [
                'style' => 'white-space: pre-wrap; background: #f8f9fa; padding: 10px; border-radius: 4px;'
            ]);
        } else {
            echo html_writer::tag('p', 'No text extracted.', ['class' => 'text-muted']);
        }

        if (!empty($images)) {
            echo html_writer::tag('h5', 'Images');
            echo html_writer::start_tag('div', ['class' => 'd-flex flex-wrap']);
            foreach ($images as $img) {
                $imageid = $img['imageId'] ?? null;
                $url = $img['url'] ?? null;
                $label = $img['label'] ?? '';
                $source = $img['source'] ?? '';
                $exists = $imageid ? \mod_classengage\nlp\storage\image_asset_storage::get_by_docid($docid, $imageid) : null;
                $error = $img['error'] ?? null;

                echo html_writer::start_tag('div', [
                    'style' => 'margin: 8px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 220px;'
                ]);

                $status = $exists ? 'found' : 'missing';
                echo html_writer::tag('div', 'Status: ' . s($status), [
                    'class' => $exists ? 'text-success' : 'text-danger'
                ]);
                if ($error) {
                    echo html_writer::tag('div', 'Error: ' . s($error), ['class' => 'text-danger small']);
                }

                if ($url) {
                    echo html_writer::empty_tag('img', [
                        'src' => $url,
                        'style' => 'max-width: 200px; max-height: 150px; display: block; margin-bottom: 6px;'
                    ]);
                } else if (!empty($img['data'])) {
                    $mediatype = $img['mediaType'] ?? 'image/png';
                    echo html_writer::empty_tag('img', [
                        'src' => 'data:' . $mediatype . ';base64,' . $img['data'],
                        'style' => 'max-width: 200px; max-height: 150px; display: block; margin-bottom: 6px;'
                    ]);
                } else {
                    echo html_writer::tag('div', 'No preview available', ['class' => 'text-muted']);
                }

                echo html_writer::tag('div', 'Image ID: ' . s($imageid ?? '-'), ['class' => 'small']);
                if ($label) {
                    echo html_writer::tag('div', 'Label: ' . s($label), ['class' => 'small']);
                }
                if ($source) {
                    echo html_writer::tag('div', 'Source: ' . s($source), ['class' => 'small']);
                }
                if ($url) {
                    echo html_writer::tag('div', html_writer::link($url, 'Open URL', ['target' => '_blank']), ['class' => 'small']);
                }

                echo html_writer::end_tag('div');
            }
            echo html_writer::end_tag('div');
        } else {
            echo html_writer::tag('p', 'No images extracted.', ['class' => 'text-muted']);
        }
    }
}

echo $OUTPUT->footer();
