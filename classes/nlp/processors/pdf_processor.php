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
 * PDF processor for text and image extraction.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\processors;

defined('MOODLE_INTERNAL') || die();

use mod_classengage\nlp\storage\image_asset_storage;

class pdf_processor {
    /**
     * Process a PDF file.
     *
     * @param string $filepath
     * @param array $options
     * @return array
     */
    public function process(string $filepath, array $options = []): array {
        $textextractor = new pdf_text_extractor();
        $pages = $textextractor->extract($filepath);

        $pagesbyid = [];
        foreach ($pages as $page) {
            $pagesbyid[(int) $page['page']] = [
                'page' => (int) $page['page'],
                'text' => $page['text'] ?? '',
                'images' => [],
            ];
        }

        $images = [];

        if ($this->imagick_available()) {
            try {
                $images = $this->extract_images_imagick($filepath, $options, $pagesbyid);
            } catch (\Exception $e) {
                $images = $this->extract_images_fallback($filepath, $options, $pagesbyid);
            }
        } else {
            $images = $this->extract_images_fallback($filepath, $options, $pagesbyid);
        }

        $combined = [];
        foreach ($pagesbyid as $page) {
            $combined[] = $page;
        }
        usort($combined, function ($a, $b) {
            return $a['page'] <=> $b['page'];
        });

        $textparts = [];
        foreach ($combined as $page) {
            if (!empty($page['text'])) {
                $textparts[] = '--- Page ' . $page['page'] . " ---\n" . $page['text'];
            }
        }

        return [
            'text' => implode("\n\n", $textparts),
            'images' => $images,
            'pages' => $combined,
        ];
    }

    private function imagick_available(): bool {
        return class_exists('Imagick');
    }

    private function extract_images_imagick(string $filepath, array $options, array &$pagesbyid): array {
        $images = [];
        $docid = $options['docId'] ?? '';

        $imagick = new \Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImage($filepath);

        $pageorder = [];
        foreach ($imagick as $index => $page) {
            $pagenum = $index + 1;
            $page->setImageFormat('png');
            $buffer = $page->getImageBlob();
            $label = 'Page ' . $pagenum . ' - Image 1';

            if ($docid) {
                $asset = image_asset_storage::save(
                    $docid,
                    $pagenum,
                    1,
                    $buffer,
                    [
                        'label' => $label,
                        'extension' => 'png',
                        'source' => 'page-' . $pagenum,
                    ]
                );
                $asset['page'] = $pagenum;
                $asset['order'] = 1;
                $asset['label'] = $label;
                $images[] = $asset;
                $pagesbyid[$pagenum]['images'][] = $asset;
            } else {
                $images[] = [
                    'type' => 'base64',
                    'mediaType' => 'image/png',
                    'data' => base64_encode($buffer),
                    'page' => $pagenum,
                    'order' => 1,
                ];
            }
        }

        $imagick->clear();
        $imagick->destroy();

        return $images;
    }

    private function extract_images_fallback(string $filepath, array $options, array &$pagesbyid): array {
        $images = [];
        $docid = $options['docId'] ?? '';
        $extractor = new pdf_image_extractor();
        $found = $extractor->extract($filepath);

        $order = 0;
        foreach ($found as $img) {
            $order++;
            $page = $img['page'] ?? 1;
            $label = 'Image ' . $order;
            $buffer = $img['buffer'] ?? '';
            if ($buffer === '') {
                continue;
            }
            if ($docid) {
                $asset = image_asset_storage::save(
                    $docid,
                    $page,
                    $order,
                    $buffer,
                    [
                        'label' => $label,
                        'extension' => $img['extension'] ?? 'jpg',
                        'source' => 'embedded-' . $order,
                    ]
                );
                $asset['page'] = $page;
                $asset['order'] = $order;
                $asset['label'] = $label;
                $images[] = $asset;
                if (isset($pagesbyid[$page])) {
                    $pagesbyid[$page]['images'][] = $asset;
                } else {
                    $pagesbyid[$page] = [
                        'page' => $page,
                        'text' => '',
                        'images' => [$asset],
                    ];
                }
            } else {
                $images[] = [
                    'type' => 'base64',
                    'mediaType' => $img['mediaType'] ?? 'image/jpeg',
                    'data' => base64_encode($buffer),
                    'page' => $page,
                    'order' => $order,
                ];
            }
        }

        return $images;
    }
}
