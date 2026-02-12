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
 * DOCX processor for text and image extraction.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\processors;

defined('MOODLE_INTERNAL') || die();

use mod_classengage\nlp\storage\image_asset_storage;

class docx_processor {
    /**
     * Process a DOCX file.
     *
     * @param string $filepath
     * @param array $options
     * @return array
     */
    public function process(string $filepath, array $options = []): array {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Unable to open DOCX file');
        }

        $docxml = $zip->getFromName('word/document.xml');
        $text = '';
        if ($docxml !== false) {
            $paragraphs = [];
            if (preg_match_all('/<w:p[^>]*>(.*?)<\/w:p>/s', $docxml, $pmatches)) {
                foreach ($pmatches[1] as $paragraph) {
                    if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $paragraph, $matches)) {
                        $pieces = array_map(function ($item) {
                            return html_entity_decode($item, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }, $matches[1]);
                        $line = trim(preg_replace('/\s+/', ' ', implode(' ', $pieces)));
                        if ($line !== '') {
                            $paragraphs[] = $line;
                        }
                    }
                }
            }

            if (!empty($paragraphs)) {
                $text = $this->clean_text(implode("\n", $paragraphs));
            } else if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $docxml, $matches)) {
                $pieces = array_map(function ($item) {
                    return html_entity_decode($item, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }, $matches[1]);
                $text = $this->clean_text(implode(' ', $pieces));
            }
        }

        $images = [];
        $imageorder = 0;
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!isset($stat['name'])) {
                continue;
            }
            $name = $stat['name'];
            if (!preg_match('/^word\/media\/.*\.(png|jpe?g|gif|bmp|webp)$/i', $name)) {
                continue;
            }

            $buffer = $zip->getFromIndex($i);
            if ($buffer === false) {
                continue;
            }

            $hash = md5($buffer);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $imageorder++;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $label = 'Document - Image ' . $imageorder;
            if (!empty($options['docId'])) {
                $asset = image_asset_storage::save(
                    $options['docId'],
                    1,
                    $imageorder,
                    $buffer,
                    [
                        'label' => $label,
                        'originalName' => basename($name),
                        'extension' => $ext === 'jpg' ? 'jpeg' : $ext,
                        'source' => basename($name),
                    ]
                );
                $images[] = array_merge($asset, [
                    'page' => 1,
                    'order' => $imageorder,
                    'label' => $label,
                    'source' => basename($name),
                ]);
            } else {
                $mimetype = $this->mime_from_extension($ext);
                $images[] = [
                    'type' => 'base64',
                    'mediaType' => $mimetype,
                    'data' => base64_encode($buffer),
                    'source' => basename($name),
                    'page' => 1,
                    'order' => $imageorder,
                ];
            }
        }

        $zip->close();

        return [
            'text' => $text,
            'images' => $images,
            'pages' => [
                [
                    'page' => 1,
                    'text' => $text,
                    'images' => $images,
                ],
            ],
        ];
    }

    private function mime_from_extension(string $ext): string {
        switch ($ext) {
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'bmp':
                return 'image/bmp';
            case 'jpg':
            case 'jpeg':
            default:
                return 'image/jpeg';
        }
    }

    private function clean_text(string $text): string {
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        $lines = preg_split('/\R/', $text);
        $cleanlines = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if ($line !== '') {
                $cleanlines[] = $line;
            }
        }
        return trim(implode("\n", $cleanlines));
    }
}
