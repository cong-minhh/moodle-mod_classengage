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
 * Image asset storage for extracted images.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\nlp\storage;

defined('MOODLE_INTERNAL') || die();

class image_asset_storage {
    /**
     * Generate a stable image ID.
     *
     * @param string $docid
     * @param int $page
     * @param int $order
     * @return string
     */
    public static function generate_image_id(string $docid, int $page, int $order): string {
        $hash = substr(md5($docid . '_' . $page . '_' . $order), 0, 6);
        return 'img_' . $page . '_' . $order . '_' . $hash;
    }

    /**
     * Save image buffer and return metadata.
     *
     * @param string $docid
     * @param int $page
     * @param int $order
     * @param string $buffer
     * @param array $metadata
     * @return array
     */
    public static function save(string $docid, int $page, int $order, string $buffer, array $metadata = []): array {
        $imageid = self::generate_image_id($docid, $page, $order);
        $extension = $metadata['extension'] ?? 'png';
        $extension = ltrim(strtolower($extension), '.');

        $dir = self::get_images_dir($docid);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        $filepath = $dir . '/' . $imageid . '.' . $extension;
        $bytes = file_put_contents($filepath, $buffer);
        if ($bytes === false) {
            return [
                'imageId' => $imageid,
                'url' => null,
                'page' => $page,
                'order' => $order,
                'label' => $metadata['label'] ?? null,
                'source' => $metadata['source'] ?? null,
                'mediaType' => $metadata['mimeType'] ?? self::mime_from_extension($extension),
                'data' => base64_encode($buffer),
                'error' => 'file_write_failed',
            ];
        }

        if (empty($metadata['mimeType'])) {
            $metadata['mimeType'] = self::mime_from_extension($extension);
        }

        $meta = array_merge([
            'imageId' => $imageid,
            'docId' => $docid,
            'page' => $page,
            'order' => $order,
            'extension' => $extension,
            'createdAt' => time(),
        ], $metadata);

        file_put_contents($filepath . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        return [
            'imageId' => $imageid,
            'url' => self::build_url($docid, $imageid),
            'page' => $page,
            'order' => $order,
            'label' => $meta['label'] ?? null,
            'source' => $meta['source'] ?? null,
            'mediaType' => $meta['mimeType'] ?? self::mime_from_extension($extension),
        ];
    }

    /**
     * Get image buffer by docId and imageId.
     *
     * @param string $docid
     * @param string $imageid
     * @return string|null
     */
    public static function get_buffer(string $docid, string $imageid): ?string {
        $result = self::get_by_docid($docid, $imageid);
        if (!$result) {
            return null;
        }
        return file_get_contents($result['path']);
    }

    /**
     * Find image by docId and imageId.
     *
     * @param string $docid
     * @param string $imageid
     * @return array|null
     */
    public static function get_by_docid(string $docid, string $imageid): ?array {
        $dir = self::get_images_dir($docid);
        if (!is_dir($dir)) {
            return null;
        }

        $extensions = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];
        foreach ($extensions as $ext) {
            $path = $dir . '/' . $imageid . '.' . $ext;
            if (is_file($path)) {
                $meta = null;
                $metapath = $path . '.meta.json';
                if (is_file($metapath)) {
                    $meta = json_decode((string) file_get_contents($metapath), true);
                }
                return [
                    'path' => $path,
                    'extension' => $ext,
                    'metadata' => is_array($meta) ? $meta : [],
                ];
            }
        }

        return null;
    }

    /**
     * List images by document ID.
     *
     * @param string $docid
     * @return array
     */
    public static function list_by_docid(string $docid): array {
        $dir = self::get_images_dir($docid);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.meta.json');
        $images = [];
        foreach ($files as $metafile) {
            $meta = json_decode((string) file_get_contents($metafile), true);
            if (!is_array($meta)) {
                continue;
            }
            $images[] = array_merge($meta, [
                'url' => self::build_url($docid, $meta['imageId'] ?? ''),
            ]);
        }

        usort($images, function ($a, $b) {
            $pagea = (int) ($a['page'] ?? 0);
            $pageb = (int) ($b['page'] ?? 0);
            if ($pagea !== $pageb) {
                return $pagea <=> $pageb;
            }
            $ordera = (int) ($a['order'] ?? 0);
            $orderb = (int) ($b['order'] ?? 0);
            return $ordera <=> $orderb;
        });

        return $images;
    }

    /**
     * Build a public URL for an image.
     *
     * @param string $docid
     * @param string $imageid
     * @return string
     */
    public static function build_url(string $docid, string $imageid): string {
        return '/mod/classengage/asset.php?docid=' . rawurlencode($docid) . '&imageid=' . rawurlencode($imageid);
    }

    /**
     * Get directory for images.
     *
     * @param string $docid
     * @return string
     */
    private static function get_images_dir(string $docid): string {
        return document_storage::get_root_dir() . '/' . $docid . '/images';
    }

    private static function mime_from_extension(string $ext): string {
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'bmp':
                return 'image/bmp';
            default:
                return 'image/png';
        }
    }
}
