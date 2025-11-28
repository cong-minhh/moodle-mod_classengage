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
 * Plugin administration pages are defined here.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // NLP Service Settings
    $settings->add(new admin_setting_heading('mod_classengage/nlpheading',
        get_string('settings:nlpendpoint', 'mod_classengage'),
        ''));

    $settings->add(new admin_setting_configtext('mod_classengage/nlpendpoint',
        get_string('settings:nlpendpoint', 'mod_classengage'),
        get_string('settings:nlpendpoint_desc', 'mod_classengage'),
        '',
        PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask('mod_classengage/nlpapikey',
        get_string('settings:nlpapikey', 'mod_classengage'),
        get_string('settings:nlpapikey_desc', 'mod_classengage'),
        ''));

    $settings->add(new admin_setting_configcheckbox('mod_classengage/autogeneratequestions',
        get_string('settings:autogeneratequestions', 'mod_classengage'),
        get_string('settings:autogeneratequestions_desc', 'mod_classengage'),
        0));

    // File Upload Settings
    $settings->add(new admin_setting_heading('mod_classengage/fileheading',
        get_string('settings:maxfilesize', 'mod_classengage'),
        ''));

    $settings->add(new admin_setting_configtext('mod_classengage/maxfilesize',
        get_string('settings:maxfilesize', 'mod_classengage'),
        get_string('settings:maxfilesize_desc', 'mod_classengage'),
        '50',
        PARAM_INT));

    // Quiz Defaults
    $settings->add(new admin_setting_heading('mod_classengage/quizheading',
        get_string('settings:defaultquestions', 'mod_classengage'),
        ''));

    $settings->add(new admin_setting_configtext('mod_classengage/defaultquestions',
        get_string('settings:defaultquestions', 'mod_classengage'),
        get_string('settings:defaultquestions_desc', 'mod_classengage'),
        '10',
        PARAM_INT));

    $settings->add(new admin_setting_configtext('mod_classengage/defaulttimelimit',
        get_string('settings:defaulttimelimit', 'mod_classengage'),
        get_string('settings:defaulttimelimit_desc', 'mod_classengage'),
        '30',
        PARAM_INT));

    // Real-time Settings
    $settings->add(new admin_setting_heading('mod_classengage/realtimeheading',
        get_string('settings:enablerealtime', 'mod_classengage'),
        ''));

    $settings->add(new admin_setting_configcheckbox('mod_classengage/enablerealtime',
        get_string('settings:enablerealtime', 'mod_classengage'),
        get_string('settings:enablerealtime_desc', 'mod_classengage'),
        1));

    $settings->add(new admin_setting_configtext('mod_classengage/pollinginterval',
        get_string('settings:pollinginterval', 'mod_classengage'),
        get_string('settings:pollinginterval_desc', 'mod_classengage'),
        '1000',
        PARAM_INT));
}

