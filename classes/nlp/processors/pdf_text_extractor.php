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
 * Basic PDF text extractor (pure PHP fallback).
 *
 * This is a best-effort parser for common PDF text streams.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\processors;

defined('MOODLE_INTERNAL') || die();

class pdf_text_extractor {
    /**
     * Extract per-page text from a PDF.
     *
     * @param string $filepath
     * @return array
     */
    public function extract(string $filepath): array {
        $pdftotext = $this->extract_with_pdftotext($filepath);
        if (!empty($pdftotext)) {
            return $pdftotext;
        }

        $data = file_get_contents($filepath);
        if ($data === false) {
            throw new \Exception('Failed to read PDF');
        }

        $objects = $this->parse_objects($data);
        $pages = $this->extract_pages($objects);

        if (empty($pages)) {
            $fallbacktext = $this->extract_text_from_stream($data);
            return [
                ['page' => 1, 'text' => $fallbacktext],
            ];
        }

        return $pages;
    }

    private function extract_with_pdftotext(string $filepath): ?array {
        if (!function_exists('proc_open')) {
            return null;
        }

        $binary = $this->find_pdftotext_binary();
        if ($binary === null) {
            return null;
        }

        $cmd = [$binary, '-layout', '-enc', 'UTF-8', $filepath, '-'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || $output === false || trim($output) === '') {
            return null;
        }

        $chunks = preg_split('/\f/', $output);
        $pages = [];
        $pagenum = 1;
        foreach ($chunks as $chunk) {
            $chunk = str_replace("\r", "", $chunk);
            $lines = preg_split('/\n/', $chunk);
            $cleanlines = [];
            foreach ($lines as $line) {
                $line = trim(preg_replace('/\s+/', ' ', $line));
                if ($line !== '') {
                    $cleanlines[] = $line;
                }
            }
            $text = implode("\n", $cleanlines);
            $pages[] = [
                'page' => $pagenum,
                'text' => $text,
            ];
            $pagenum++;
        }

        return $pages;
    }

    private function find_pdftotext_binary(): ?string {
        $candidates = [
            '/usr/bin/pdftotext',
            '/usr/local/bin/pdftotext',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

    private function extract_pages(array $objects): array {
        $pages = [];
        $pageindex = 0;

        foreach ($objects as $objid => $object) {
            if (strpos($object, '/Type /Page') === false) {
                continue;
            }

            $contents = $this->extract_content_refs($object);
            if (empty($contents)) {
                continue;
            }

            $pageindex++;
            $pagetextparts = [];
            foreach ($contents as $ref) {
                if (!isset($objects[$ref])) {
                    continue;
                }
                $stream = $this->extract_stream($objects[$ref]);
                if ($stream === null) {
                    continue;
                }
                $pagetextparts[] = $this->extract_text_from_stream($stream);
            }

            $pagetext = trim(preg_replace('/\s+/', ' ', implode(' ', $pagetextparts)));
            $pages[] = [
                'page' => $pageindex,
                'text' => $pagetext,
            ];
        }

        return $pages;
    }

    private function extract_content_refs(string $object): array {
        $refs = [];
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $object, $matches)) {
            $refs[] = (int) $matches[1];
        } else if (preg_match('/\/Contents\s*\[(.*?)\]/s', $object, $matches)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $matches[1], $refmatches)) {
                foreach ($refmatches[1] as $refid) {
                    $refs[] = (int) $refid;
                }
            }
        }
        return $refs;
    }

    private function extract_stream(string $object): ?string {
        if (!preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $object, $matches)) {
            return null;
        }

        $stream = $matches[1];
        $filters = [];
        if (preg_match('/\/Filter\s*\/(\w+)/', $object, $fmatch)) {
            $filters[] = $fmatch[1];
        } else if (preg_match('/\/Filter\s*\[(.*?)\]/s', $object, $fmatch)) {
            if (preg_match_all('/\/(\w+)/', $fmatch[1], $filtermatches)) {
                $filters = $filtermatches[1];
            }
        }

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

    private function extract_text_from_stream(string $stream): string {
        $textparts = [];
        if (preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                $textparts = array_merge($textparts, $this->extract_text_from_block($block));
            }
        }

        $text = implode(' ', $textparts);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function extract_text_from_block(string $block): array {
        $texts = [];

        $patterns = [
            '/\((?:\\\\.|[^\\\\\)])*\)\s*Tj/s',
            '/<([0-9A-Fa-f\s]+)>\s*Tj/s',
            '/\((?:\\\\.|[^\\\\\)])*\)\s*\'/s',
            '/<([0-9A-Fa-f\s]+)>\s*\'/s',
            '/-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\s*\((?:\\\\.|[^\\\\\)])*\)\s*\"/s',
            '/-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\s*<([0-9A-Fa-f\s]+)>\s*\"/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $block, $matches)) {
                foreach ($matches[0] as $match) {
                    if (preg_match('/\((?:\\\\.|[^\\\\\)])*\)/s', $match, $smatch)) {
                        $texts[] = $this->decode_pdf_string(substr($smatch[0], 1, -1));
                    } else if (preg_match('/<([0-9A-Fa-f\s]+)>/', $match, $hmatch)) {
                        $texts[] = $this->decode_pdf_hex_string($hmatch[1]);
                    }
                }
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $matches)) {
            foreach ($matches[1] as $arraystr) {
                if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)/s', $arraystr, $smatches)) {
                    foreach ($smatches[0] as $str) {
                        $texts[] = $this->decode_pdf_string(substr($str, 1, -1));
                    }
                }
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', $arraystr, $hmatches)) {
                    foreach ($hmatches[1] as $hex) {
                        $texts[] = $this->decode_pdf_hex_string($hex);
                    }
                }
            }
        }

        $texts = array_filter(array_map('trim', $texts), function ($value) {
            return $value !== '';
        });

        return $texts;
    }

    private function decode_pdf_string(string $text): string {
        $text = preg_replace('/\\\\/', '\\', $text);
        $text = preg_replace('/\\\(/', '(', $text);
        $text = preg_replace('/\\\)/', ')', $text);
        $text = preg_replace('/\\n/', "\n", $text);
        $text = preg_replace('/\\r/', "\r", $text);
        $text = preg_replace('/\\t/', "\t", $text);

        $text = preg_replace_callback('/\\([0-7]{1,3})/', function ($matches) {
            return chr(octdec($matches[1]));
        }, $text);

        return $text;
    }

    private function decode_pdf_hex_string(string $hex): string {
        $hex = preg_replace('/\s+/', '', $hex);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $bin = @hex2bin($hex);
        if ($bin === false) {
            return '';
        }

        if (substr($bin, 0, 2) === "\xFE\xFF") {
            $payload = substr($bin, 2);
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($payload, 'UTF-8', 'UTF-16BE');
            }
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1');
        }

        return utf8_encode($bin);
    }
}
