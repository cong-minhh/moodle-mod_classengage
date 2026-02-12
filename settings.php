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
    $settings->add(new admin_setting_heading(
        'mod_classengage/nlpheading',
        get_string('settings:nlpendpoint', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/nlpendpoint',
        get_string('settings:nlpendpoint', 'mod_classengage'),
        get_string('settings:nlpendpoint_desc', 'mod_classengage'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/nlpapikey',
        get_string('settings:nlpapikey', 'mod_classengage'),
        get_string('settings:nlpapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/nlppublicurl',
        get_string('settings:nlppublicurl', 'mod_classengage'),
        get_string('settings:nlppublicurl_desc', 'mod_classengage'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_classengage/autogeneratequestions',
        get_string('settings:autogeneratequestions', 'mod_classengage'),
        get_string('settings:autogeneratequestions_desc', 'mod_classengage'),
        0
    ));

    // NLP Provider Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/nlpprovidersheading',
        get_string('settings:nlpproviders', 'mod_classengage'),
        get_string('settings:nlpproviders_desc', 'mod_classengage')
    ));

    $provideroptions = [
        'gemini' => 'Gemini',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'deepseek' => 'DeepSeek',
        'kimi' => 'Kimi',
        'kimicn' => 'Kimi CN',
    ];

    $settings->add(new admin_setting_configselect(
        'mod_classengage/nlp_default_provider',
        get_string('settings:nlpdefaultprovider', 'mod_classengage'),
        get_string('settings:nlpdefaultprovider_desc', 'mod_classengage'),
        'gemini',
        $provideroptions
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/nlp_provider_priority',
        get_string('settings:nlpproviderpriority', 'mod_classengage'),
        get_string('settings:nlpproviderpriority_desc', 'mod_classengage'),
        'gemini,openai,anthropic,deepseek,kimi,kimicn',
        PARAM_TEXT
    ));

    // Gemini
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/gemini_api_key',
        get_string('settings:geminiapikey', 'mod_classengage'),
        get_string('settings:geminiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/gemini_model',
        get_string('settings:geminimodel', 'mod_classengage'),
        get_string('settings:geminimodel_desc', 'mod_classengage'),
        'gemini-2.5-flash',
        PARAM_TEXT
    ));

    // OpenAI
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/openai_api_key',
        get_string('settings:openaiapikey', 'mod_classengage'),
        get_string('settings:openaiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/openai_model',
        get_string('settings:openaimodel', 'mod_classengage'),
        get_string('settings:openaimodel_desc', 'mod_classengage'),
        'gpt-4o',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/openai_base_url',
        get_string('settings:openaibaseurl', 'mod_classengage'),
        get_string('settings:openaibaseurl_desc', 'mod_classengage'),
        'https://api.openai.com/v1',
        PARAM_URL
    ));

    // Anthropic
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/anthropic_api_key',
        get_string('settings:anthropicapikey', 'mod_classengage'),
        get_string('settings:anthropicapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/anthropic_model',
        get_string('settings:anthropicmodel', 'mod_classengage'),
        get_string('settings:anthropicmodel_desc', 'mod_classengage'),
        'claude-3-5-sonnet-20241022',
        PARAM_TEXT
    ));

    // DeepSeek
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/deepseek_api_key',
        get_string('settings:deepseekapikey', 'mod_classengage'),
        get_string('settings:deepseekapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/deepseek_model',
        get_string('settings:deepseekmodel', 'mod_classengage'),
        get_string('settings:deepseekmodel_desc', 'mod_classengage'),
        'deepseek-chat',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/deepseek_base_url',
        get_string('settings:deepseekbaseurl', 'mod_classengage'),
        get_string('settings:deepseekbaseurl_desc', 'mod_classengage'),
        'https://api.deepseek.com/v1',
        PARAM_URL
    ));

    // Kimi
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/kimi_api_key',
        get_string('settings:kimiapikey', 'mod_classengage'),
        get_string('settings:kimiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimi_model',
        get_string('settings:kimimodel', 'mod_classengage'),
        get_string('settings:kimimodel_desc', 'mod_classengage'),
        'moonshotai/kimi-k2:free',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimi_base_url',
        get_string('settings:kimibaseurl', 'mod_classengage'),
        get_string('settings:kimibaseurl_desc', 'mod_classengage'),
        'https://api.moonshot.ai/v1',
        PARAM_URL
    ));

    // Kimi CN
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/kimicn_api_key',
        get_string('settings:kimicnapikey', 'mod_classengage'),
        get_string('settings:kimicnapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimicn_model',
        get_string('settings:kimicnmodel', 'mod_classengage'),
        get_string('settings:kimicnmodel_desc', 'mod_classengage'),
        'moonshot-v1-8k',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimicn_base_url',
        get_string('settings:kimicnbaseurl', 'mod_classengage'),
        get_string('settings:kimicnbaseurl_desc', 'mod_classengage'),
        'https://api.moonshot.cn/v1',
        PARAM_URL
    ));

    // File Upload Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/fileheading',
        get_string('settings:maxfilesize', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/maxfilesize',
        get_string('settings:maxfilesize', 'mod_classengage'),
        get_string('settings:maxfilesize_desc', 'mod_classengage'),
        '50',
        PARAM_INT
    ));

    // Quiz Defaults
    $settings->add(new admin_setting_heading(
        'mod_classengage/quizheading',
        get_string('settings:defaultquestions', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/defaultquestions',
        get_string('settings:defaultquestions', 'mod_classengage'),
        get_string('settings:defaultquestions_desc', 'mod_classengage'),
        '10',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/defaulttimelimit',
        get_string('settings:defaulttimelimit', 'mod_classengage'),
        get_string('settings:defaulttimelimit_desc', 'mod_classengage'),
        '30',
        PARAM_INT
    ));

    // Real-time Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/realtimeheading',
        get_string('settings:enablerealtime', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_classengage/enablerealtime',
        get_string('settings:enablerealtime', 'mod_classengage'),
        get_string('settings:enablerealtime_desc', 'mod_classengage'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/pollinginterval',
        get_string('settings:pollinginterval', 'mod_classengage'),
        get_string('settings:pollinginterval_desc', 'mod_classengage'),
        '1000',
        PARAM_INT
    ));

    // Enterprise Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/enterpriseheading',
        get_string('settings:enterprisesettings', 'mod_classengage'),
        get_string('settings:enterprisesettings_desc', 'mod_classengage')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/log_retention_days',
        get_string('settings:logretentiondays', 'mod_classengage'),
        get_string('settings:logretentiondays_desc', 'mod_classengage'),
        '90',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/connection_timeout',
        get_string('settings:connectiontimeout', 'mod_classengage'),
        get_string('settings:connectiontimeout_desc', 'mod_classengage'),
        '30',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/stale_connection_threshold',
        get_string('settings:staleconnectionthreshold', 'mod_classengage'),
        get_string('settings:staleconnectionthreshold_desc', 'mod_classengage'),
        '60',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/analytics_window',
        get_string('settings:analyticswindow', 'mod_classengage'),
        get_string('settings:analyticswindow_desc', 'mod_classengage'),
        '60',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/max_concurrent_connections',
        get_string('settings:maxconcurrentconnections', 'mod_classengage'),
        get_string('settings:maxconcurrentconnections_desc', 'mod_classengage'),
        '500',
        PARAM_INT
    ));
}

$stringmanager = get_string_manager();
$providerstesttitle = 'Test NLP Providers';
if ($stringmanager->string_exists('settings:nlpproviderstest', 'mod_classengage')) {
    $providerstesttitle = get_string('settings:nlpproviderstest', 'mod_classengage');
}

$ADMIN->add('modsettings', new admin_externalpage(
    'classengage_nlpproviderstest',
    $providerstesttitle,
    new moodle_url('/mod/classengage/test_providers.php'),
    'moodle/site:config'
));
