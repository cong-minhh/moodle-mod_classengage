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
 * Content filtering for selected slides/images.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp;

defined('MOODLE_INTERNAL') || die();

use mod_classengage\nlp\storage\image_asset_storage;

class content_filter {
    /**
     * Filter extraction data based on options.
     *
     * @param array $extraction
     * @param array $options
     * @return array
     */
    public static function apply(array $extraction, array $options = []): array {
        $includeslides = null;
        if (!empty($options['includeSlides']) && is_array($options['includeSlides'])) {
            $includeslides = array_flip(array_map('intval', $options['includeSlides']));
        }

        $includeimages = [];
        if (!empty($options['includeImages']) && is_array($options['includeImages'])) {
            $includeimages = $options['includeImages'];
        }

        $hasexplicitimages = !empty($includeimages);

        if (empty($extraction['pages']) || !is_array($extraction['pages'])) {
            return [
                'text' => $extraction['text'] ?? '',
                'images' => $extraction['images'] ?? [],
                'imageMetadata' => [],
                'sources' => [
                    'slides' => [],
                    'images' => [],
                ],
            ];
        }

        $textparts = [];
        $images = [];
        $imagemetadata = [];
        $sourceslides = [];

        foreach ($extraction['pages'] as $page) {
            $pagenum = (int) ($page['page'] ?? 0);
            $selectedslide = $includeslides === null || isset($includeslides[$pagenum]);

            if ($selectedslide) {
                $pagetext = trim((string) ($page['text'] ?? ''));
                if ($pagetext !== '') {
                    $textparts[] = '--- Page ' . $pagenum . " ---\n" . $pagetext;
                    $sourceslides[] = $pagenum;
                }
            }

            if (!empty($page['images']) && is_array($page['images'])) {
                foreach ($page['images'] as $img) {
                    if (!$hasexplicitimages) {
                        continue;
                    }

                    $imageid = $img['imageId'] ?? null;
                    $source = $img['source'] ?? null;
                    $isselected = false;

                    if ($imageid && in_array($imageid, $includeimages, true)) {
                        $isselected = true;
                    } else if ($source && in_array($source, $includeimages, true)) {
                        $isselected = true;
                    }

                    if (!$isselected) {
                        continue;
                    }

                    if ($imageid) {
                        $buffer = image_asset_storage::get_buffer($options['docId'] ?? '', $imageid);
                        if ($buffer) {
                            $media = $img['mediaType'] ?? 'image/png';
                            $images[] = [
                                'type' => 'base64',
                                'mediaType' => $media,
                                'data' => base64_encode($buffer),
                                'source' => $source,
                            ];
                            $imagemetadata[] = [
                                'imageId' => $imageid,
                                'url' => $img['url'] ?? null,
                                'label' => $img['label'] ?? null,
                                'page' => $img['page'] ?? $pagenum,
                                'order' => $img['order'] ?? null,
                            ];
                        } else if (!empty($img['data'])) {
                            $media = $img['mediaType'] ?? 'image/png';
                            $images[] = [
                                'type' => 'base64',
                                'mediaType' => $media,
                                'data' => $img['data'],
                                'source' => $source,
                            ];
                            $imagemetadata[] = [
                                'imageId' => $imageid,
                                'url' => $img['url'] ?? null,
                                'label' => $img['label'] ?? null,
                                'page' => $img['page'] ?? $pagenum,
                                'order' => $img['order'] ?? null,
                            ];
                        }
                    } else if (!empty($img['data'])) {
                        $images[] = $img;
                        $imagemetadata[] = [
                            'source' => $source,
                            'page' => $img['page'] ?? $pagenum,
                            'order' => $img['order'] ?? null,
                        ];
                    }
                }
            }
        }

        return [
            'text' => implode("\n\n", $textparts),
            'images' => $images,
            'imageMetadata' => $imagemetadata,
            'sources' => [
                'slides' => array_values(array_unique($sourceslides)),
                'images' => $imagemetadata,
            ],
        ];
    }
}
