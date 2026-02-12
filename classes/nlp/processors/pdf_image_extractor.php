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
 * PDF image extractor (pure PHP fallback).
 *
 * This best-effort extractor supports DCTDecode (JPEG) images.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\processors;

defined('MOODLE_INTERNAL') || die();

class pdf_image_extractor {
    /**
     * Extract image buffers from PDF.
     *
     * @param string $filepath
     * @return array
     */
    public function extract(string $filepath): array {
        $data = file_get_contents($filepath);
        if ($data === false) {
            throw new \Exception('Failed to read PDF');
        }

        $images = [];
        $objects = $this->parse_objects($data);

        $pageids = $this->extract_page_ids($objects);
        $pageindex = 0;
        foreach ($pageids as $pageid) {
            $pageindex++;
            if (!isset($objects[$pageid])) {
                continue;
            }

            $pageobject = $objects[$pageid];
            $resources = $this->resolve_resources_for_page($pageid, $objects);
            if ($resources === null) {
                continue;
            }

            $xobjectmap = $this->extract_xobject_map($resources);
            if (empty($xobjectmap)) {
                continue;
            }

            $usednames = $this->find_used_xobject_names($pageobject, $objects);
            $names = !empty($usednames) ? $usednames : array_keys($xobjectmap);

            $order = 0;
            foreach ($names as $name) {
                if (!isset($xobjectmap[$name])) {
                    continue;
                }
                $objid = $xobjectmap[$name];
                if (!isset($objects[$objid])) {
                    continue;
                }
                $object = $objects[$objid];
                if (!$this->is_image_object($object)) {
                    continue;
                }

                $stream = $this->extract_stream($object);
                if ($stream === null) {
                    continue;
                }

                $order++;
                $images[] = [
                    'buffer' => $stream,
                    'extension' => 'jpg',
                    'mediaType' => 'image/jpeg',
                    'order' => $order,
                    'page' => $pageindex,
                ];
            }
        }

        if (!empty($images)) {
            return $images;
        }

        return $this->extract_global_images($objects);
    }

    private function parse_objects(string $data): array {
        $objects = [];
        if (preg_match_all('/(\d+)\s+\d+\s+obj(.*?)endobj/s', $data, $matches)) {
            foreach ($matches[1] as $index => $objid) {
                $objects[(int) $objid] = $matches[2][$index];
            }
        }
        return $objects;
    }

    private function extract_page_ids(array $objects): array {
        $catalogid = null;
        foreach ($objects as $id => $object) {
            if (strpos($object, '/Type /Catalog') !== false) {
                $catalogid = (int) $id;
                break;
            }
        }

        if ($catalogid !== null) {
            $catalog = $objects[$catalogid] ?? '';
            if (preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalog, $matches)) {
                $pagesroot = (int) $matches[1];
                $pageids = $this->collect_page_ids($pagesroot, $objects);
                if (!empty($pageids)) {
                    return $pageids;
                }
            }
        }

        $pages = [];
        foreach ($objects as $id => $object) {
            if (strpos($object, '/Type /Page') !== false) {
                $pages[] = (int) $id;
            }
        }
        sort($pages);
        return $pages;
    }

    private function collect_page_ids(int $nodeid, array $objects, array $visited = []): array {
        if (isset($visited[$nodeid]) || !isset($objects[$nodeid])) {
            return [];
        }
        $visited[$nodeid] = true;
        $object = $objects[$nodeid];

        if (strpos($object, '/Type /Page') !== false) {
            return [$nodeid];
        }

        $ids = [];
        if (preg_match('/\/Kids\s*\[(.*?)\]/s', $object, $matches)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $matches[1], $refmatches)) {
                foreach ($refmatches[1] as $childid) {
                    $childids = $this->collect_page_ids((int) $childid, $objects, $visited);
                    $ids = array_merge($ids, $childids);
                }
            }
        }
        return $ids;
    }

    private function resolve_resources_for_page(int $pageid, array $objects): ?string {
        $current = $pageid;
        $visited = [];

        while ($current && isset($objects[$current]) && !isset($visited[$current])) {
            $visited[$current] = true;
            $object = $objects[$current];

            if (preg_match('/\/Resources\s+(\d+)\s+\d+\s+R/', $object, $matches)) {
                $resid = (int) $matches[1];
                return $objects[$resid] ?? null;
            }

            if (preg_match('/\/Resources\s*<<(.*?)>>/s', $object, $matches)) {
                return '<<' . $matches[1] . '>>';
            }

            if (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $object, $matches)) {
                $current = (int) $matches[1];
                continue;
            }

            break;
        }

        return null;
    }

    private function extract_xobject_map(string $resources): array {
        if (!preg_match('/\/XObject\s*<<(.*?)>>/s', $resources, $matches)) {
            return [];
        }

        $map = [];
        if (preg_match_all('/\/([A-Za-z0-9_.-]+)\s+(\d+)\s+\d+\s+R/', $matches[1], $refmatches)) {
            foreach ($refmatches[1] as $idx => $name) {
                $map[$name] = (int) $refmatches[2][$idx];
            }
        }

        return $map;
    }

    private function find_used_xobject_names(string $pageobject, array $objects): array {
        $contents = $this->extract_content_refs($pageobject);
        if (empty($contents)) {
            return [];
        }

        $names = [];
        foreach ($contents as $ref) {
            if (!isset($objects[$ref])) {
                continue;
            }
            $decoded = $this->decode_stream_from_object($objects[$ref]);
            if ($decoded === null) {
                continue;
            }
            if (preg_match_all('/\/([A-Za-z0-9_.-]+)\s+Do/', $decoded, $matches)) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    private function extract_content_refs(string $pageobject): array {
        $refs = [];
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pageobject, $matches)) {
            $refs[] = (int) $matches[1];
        } else if (preg_match('/\/Contents\s*\[(.*?)\]/s', $pageobject, $matches)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $matches[1], $refmatches)) {
                foreach ($refmatches[1] as $refid) {
                    $refs[] = (int) $refid;
                }
            }
        }
        return $refs;
    }

    private function decode_stream_from_object(string $object): ?string {
        $stream = $this->extract_stream($object);
        if ($stream === null) {
            return null;
        }

        $filters = $this->get_filters($object);
        return $this->decode_stream($stream, $filters);
    }

    private function decode_stream(string $stream, array $filters): string {
        foreach ($filters as $filter) {
            $filter = strtolower($filter);
            if ($filter === 'flatedecode') {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false && strlen($stream) > 2) {
                    $decoded = @gzinflate(substr($stream, 2));
                }
                if ($decoded !== false) {
                    $stream = $decoded;
                }
            } else if ($filter === 'asciihexdecode') {
                $stream = $this->decode_ascii_hex($stream);
            } else if ($filter === 'ascii85decode') {
                $stream = $this->decode_ascii85($stream);
            }
        }

        return $stream;
    }

    private function is_image_object(string $object): bool {
        if (strpos($object, '/Subtype /Image') === false) {
            return false;
        }

        $filters = $this->get_filters($object);
        if (empty($filters)) {
            return false;
        }

        return in_array('DCTDecode', $filters, true);
    }

    private function extract_global_images(array $objects): array {
        $images = [];
        $order = 0;
        foreach ($objects as $object) {
            if (!$this->is_image_object($object)) {
                continue;
            }

            $stream = $this->extract_stream($object);
            if ($stream === null) {
                continue;
            }

            $order++;
            $images[] = [
                'buffer' => $stream,
                'extension' => 'jpg',
                'mediaType' => 'image/jpeg',
                'order' => $order,
                'page' => 1,
            ];
        }

        return $images;
    }

    private function get_filters(string $object): array {
        $filters = [];
        if (preg_match('/\/Filter\s*\/(\w+)/', $object, $matches)) {
            $filters[] = $matches[1];
        } else if (preg_match('/\/Filter\s*\[(.*?)\]/s', $object, $matches)) {
            if (preg_match_all('/\/(\w+)/', $matches[1], $filtermatches)) {
                $filters = $filtermatches[1];
            }
        }
        return $filters;
    }

    private function extract_stream(string $object): ?string {
        if (!preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $object, $matches)) {
            return null;
        }
        return $matches[1];
    }

    private function decode_ascii_hex(string $data): string {
        $data = preg_replace('/\s+/', '', $data);
        $data = rtrim($data, '>');
        if ($data === '') {
            return '';
        }
        if (strlen($data) % 2 === 1) {
            $data .= '0';
        }
        $bin = @hex2bin($data);
        return $bin === false ? '' : $bin;
    }

    private function decode_ascii85(string $data): string {
        $data = trim($data);
        $data = preg_replace('/\s+/', '', $data);
        $data = trim($data, '<~>');
        if ($data === '') {
            return '';
        }

        $out = '';
        $chunk = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];
            if ($ch === 'z' && $chunk === '') {
                $out .= "\0\0\0\0";
                continue;
            }
            $chunk .= $ch;
            if (strlen($chunk) === 5) {
                $out .= $this->decode_ascii85_chunk($chunk);
                $chunk = '';
            }
        }

        if ($chunk !== '') {
            $pad = 5 - strlen($chunk);
            $chunk .= str_repeat('u', $pad);
            $decoded = $this->decode_ascii85_chunk($chunk);
            $out .= substr($decoded, 0, 4 - $pad);
        }

        return $out;
    }

    private function decode_ascii85_chunk(string $chunk): string {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            $value = $value * 85 + (ord($chunk[$i]) - 33);
        }
        return pack('N', $value);
    }
}
