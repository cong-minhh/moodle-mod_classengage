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
 * Helpers for ClassEngage asset URLs.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers for resolving asset URLs stored in the database.
 */
class asset_helper {
    /**
     * Resolve an asset URL for browser output.
     *
     * Question images are now stored as Moodle-relative pluginfile paths for portability.
     * Convert those to the active site host and keep absolute URLs unchanged.
     *
     * @param string|null $url Stored asset URL or relative path.
     * @return string
     */
    public static function resolve_browser_url(?string $url): string {
        global $CFG;

        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || strpos($url, '//') === 0) {
            return $url;
        }

        return rtrim($CFG->wwwroot, '/') . '/' . ltrim($url, '/');
    }
}
