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
 * PPTX processor for text and image extraction.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\processors;

defined('MOODLE_INTERNAL') || die();

use mod_classengage\nlp\storage\image_asset_storage;

class pptx_processor {
    /**
     * Process a PPTX file.
     *
     * @param string $filepath
     * @param array $options
     * @return array
     */
    public function process(string $filepath, array $options = []): array {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Unable to open PPTX file');
        }

        $slideentries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!isset($stat['name'])) {
                continue;
            }
            if (preg_match('/^ppt\/slides\/slide(\d+)\.xml$/', $stat['name'], $matches)) {
                $slideentries[] = [
                    'name' => $stat['name'],
                    'num' => (int) $matches[1],
                ];
            }
        }

        usort($slideentries, function ($a, $b) {
            return $a['num'] <=> $b['num'];
        });

        $pages = [];
        $allimages = [];
        $seen = [];

        foreach ($slideentries as $entry) {
            $slidexml = $zip->getFromName($entry['name']);
            $slidetext = '';
            if ($slidexml !== false) {
                $paragraphs = [];
                if (preg_match_all('/<a:p[^>]*>(.*?)<\/a:p>/s', $slidexml, $pmatches)) {
                    foreach ($pmatches[1] as $paragraph) {
                        if (preg_match_all('/<a:t>(.*?)<\/a:t>/s', $paragraph, $matches)) {
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
                    $slidetext = $this->clean_text(implode("\n", $paragraphs));
                } else if (preg_match_all('/<a:t>(.*?)<\/a:t>/s', $slidexml, $matches)) {
                    $pieces = array_map(function ($item) {
                        return html_entity_decode($item, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }, $matches[1]);
                    $slidetext = $this->clean_text(implode(' ', $pieces));
                }
            }

            $slideimages = [];
            $relsname = 'ppt/slides/_rels/' . basename($entry['name']) . '.rels';
            $relsxml = $zip->getFromName($relsname);
            $imageorder = 0;

            if ($relsxml !== false) {
                preg_match_all('/<Relationship[^>]*Type="[^"]*image[^"]*"[^>]*Target="([^"]+)"[^>]*\/>/i', $relsxml, $relsmatches);
                if (!empty($relsmatches[1])) {
                    foreach ($relsmatches[1] as $target) {
                        $normalized = str_replace('../', 'ppt/', $target);
                        $buffer = $zip->getFromName($normalized);
                        if ($buffer === false) {
                            continue;
                        }

                        if (strlen($buffer) < 3072) {
                            continue;
                        }

                        $hash = md5($buffer);
                        if (isset($seen[$hash])) {
                            continue;
                        }
                        $seen[$hash] = true;

                        $imageorder++;
                        $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
                        $label = 'Slide ' . $entry['num'] . ' - Image ' . $imageorder;

                        if (!empty($options['docId'])) {
                            $asset = image_asset_storage::save(
                                $options['docId'],
                                $entry['num'],
                                $imageorder,
                                $buffer,
                                [
                                    'label' => $label,
                                    'originalName' => basename($normalized),
                                    'extension' => $ext === 'jpg' ? 'jpeg' : $ext,
                                    'source' => basename($normalized),
                                ]
                            );
                            $slideimages[] = array_merge($asset, [
                                'page' => $entry['num'],
                                'order' => $imageorder,
                                'label' => $label,
                                'source' => basename($normalized),
                            ]);
                        } else {
                            $slideimages[] = [
                                'type' => 'base64',
                                'mediaType' => $this->mime_from_extension($ext),
                                'data' => base64_encode($buffer),
                                'source' => basename($normalized),
                                'page' => $entry['num'],
                                'order' => $imageorder,
                            ];
                        }
                    }
                }
            }

            $pages[] = [
                'page' => $entry['num'],
                'text' => $slidetext,
                'images' => $slideimages,
            ];
            $allimages = array_merge($allimages, $slideimages);
        }

        $zip->close();

        $combinedtext = '';
        foreach ($pages as $page) {
            $combinedtext .= '--- Slide ' . $page['page'] . " ---\n" . ($page['text'] ?? '') . "\n\n";
        }
        $combinedtext = trim($combinedtext);

        return [
            'text' => $combinedtext,
            'images' => $allimages,
            'pages' => $pages,
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
