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
 * Document storage for NLP processing.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\storage;

defined('MOODLE_INTERNAL') || die();

class document_storage {
    /**
     * Get storage root directory.
     *
     * @return string
     */
    public static function get_root_dir(): string {
        global $CFG;
        $root = $CFG->dataroot . '/classengage/nlp';
        if (!is_dir($root)) {
            mkdir($root, 0770, true);
        }
        return $root;
    }

    /**
     * Compute docId from stored file contenthash and context.
     *
     * @param \stored_file $file
     * @param int $contextid
     * @return string
     */
    public static function compute_doc_id(\stored_file $file, int $contextid): string {
        $contenthash = $file->get_contenthash();
        $hash = hash('sha256', $contenthash . ':' . $contextid);
        return 'doc_' . substr($hash, 0, 16);
    }

    /**
     * Ensure document is stored locally.
     *
     * @param \stored_file $file
     * @param int $contextid
     * @param int $classengageid
     * @param int $slideid
     * @return array [docId, path]
     */
    public static function ensure_document(\stored_file $file, int $contextid, int $classengageid, int $slideid): array {
        $docid = self::compute_doc_id($file, $contextid);
        $docdir = self::get_root_dir() . '/' . $docid;
        if (!is_dir($docdir)) {
            mkdir($docdir, 0770, true);
        }

        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $filename = 'source' . ($ext ? ('.' . $ext) : '');
        $targetpath = $docdir . '/' . $filename;

        $metapath = $docdir . '/meta.json';
        $contenthash = $file->get_contenthash();

        $existingmeta = null;
        if (is_file($metapath)) {
            $existingmeta = json_decode((string) file_get_contents($metapath), true);
        }

        if (empty($existingmeta) || ($existingmeta['contenthash'] ?? '') !== $contenthash || !is_file($targetpath)) {
            $file->copy_content_to($targetpath);
        }

        $meta = [
            'docId' => $docid,
            'contenthash' => $contenthash,
            'originalname' => $file->get_filename(),
            'mimetype' => $file->get_mimetype(),
            'size' => $file->get_filesize(),
            'contextid' => $contextid,
            'classengageid' => $classengageid,
            'slideid' => $slideid,
            'path' => $targetpath,
            'createdAt' => time(),
        ];

        file_put_contents($metapath, json_encode($meta, JSON_PRETTY_PRINT));

        return [
            'docId' => $docid,
            'path' => $targetpath,
            'meta' => $meta,
        ];
    }

    /**
     * Load metadata for docId.
     *
     * @param string $docid
     * @return array
     */
    public static function get_metadata(string $docid): array {
        $metapath = self::get_root_dir() . '/' . $docid . '/meta.json';
        if (!is_file($metapath)) {
            throw new \Exception('Document metadata not found for ' . $docid);
        }
        $meta = json_decode((string) file_get_contents($metapath), true);
        if (!is_array($meta)) {
            throw new \Exception('Invalid document metadata for ' . $docid);
        }
        return $meta;
    }

    /**
     * Save extraction results.
     *
     * @param string $docid
     * @param array $data
     * @return void
     */
    public static function save_extraction(string $docid, array $data): void {
        $path = self::get_root_dir() . '/' . $docid . '/extraction.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get extraction results if present.
     *
     * @param string $docid
     * @return array|null
     */
    public static function get_extraction(string $docid): ?array {
        $path = self::get_root_dir() . '/' . $docid . '/extraction.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
