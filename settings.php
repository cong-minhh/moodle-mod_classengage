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
    // Legacy NLP Service Settings (deprecated, kept for compatibility)
    $settings->add(new admin_setting_heading(
        'mod_classengage/nlpheading',
        get_string('settings:nlpendpoint', 'mod_classengage'),
        get_string('settings:nlpendpoint_deprecated', 'mod_classengage')
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

    // AI Provider Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/ai_providers_heading',
        get_string('settings:ai_providers', 'mod_classengage'),
        get_string('settings:ai_providers_desc', 'mod_classengage')
    ));

    $settings->add(new admin_setting_configselect(
        'mod_classengage/nlpdefaultprovider',
        get_string('settings:nlpdefaultprovider', 'mod_classengage'),
        get_string('settings:nlpdefaultprovider_desc', 'mod_classengage'),
        'gemini',
        [
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic Claude',
            'deepseek' => 'DeepSeek',
            'kimi' => 'Kimi (Global)',
            'kimicn' => 'Kimi (China)',
            'local' => 'Local/Ollama',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/nlpproviderpriority',
        get_string('settings:nlpproviderpriority', 'mod_classengage'),
        get_string('settings:nlpproviderpriority_desc', 'mod_classengage'),
        'gemini,openai,anthropic,deepseek,kimi,kimicn,local'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/nlprequesttimeout',
        get_string('settings:nlprequesttimeout', 'mod_classengage'),
        get_string('settings:nlprequesttimeout_desc', 'mod_classengage'),
        '120',
        PARAM_INT
    ));

    // Gemini Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/gemini_heading',
        get_string('settings:gemini', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/geminiapikey',
        get_string('settings:geminiapikey', 'mod_classengage'),
        get_string('settings:geminiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/geminimodel',
        get_string('settings:geminimodel', 'mod_classengage'),
        get_string('settings:geminimodel_desc', 'mod_classengage'),
        'gemini-2.5-flash'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/geminiendpoint',
        get_string('settings:geminiendpoint', 'mod_classengage'),
        get_string('settings:geminiendpoint_desc', 'mod_classengage'),
        'https://generativelanguage.googleapis.com/v1beta'
    ));

    // OpenAI Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/openai_heading',
        get_string('settings:openai', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/openaiapikey',
        get_string('settings:openaiapikey', 'mod_classengage'),
        get_string('settings:openaiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/openaimodel',
        get_string('settings:openaimodel', 'mod_classengage'),
        get_string('settings:openaimodel_desc', 'mod_classengage'),
        'gpt-4o-mini'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/openaiendpoint',
        get_string('settings:openaiendpoint', 'mod_classengage'),
        get_string('settings:openaiendpoint_desc', 'mod_classengage'),
        'https://api.openai.com/v1'
    ));

    // Anthropic Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/anthropic_heading',
        get_string('settings:anthropic', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/anthropicapikey',
        get_string('settings:anthropicapikey', 'mod_classengage'),
        get_string('settings:anthropicapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/anthropicmodel',
        get_string('settings:anthropicmodel', 'mod_classengage'),
        get_string('settings:anthropicmodel_desc', 'mod_classengage'),
        'claude-3-5-sonnet-20241022'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/anthropicendpoint',
        get_string('settings:anthropicendpoint', 'mod_classengage'),
        get_string('settings:anthropicendpoint_desc', 'mod_classengage'),
        'https://api.anthropic.com/v1'
    ));

    // DeepSeek Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/deepseek_heading',
        get_string('settings:deepseek', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/deepseekapikey',
        get_string('settings:deepseekapikey', 'mod_classengage'),
        get_string('settings:deepseekapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/deepseekmodel',
        get_string('settings:deepseekmodel', 'mod_classengage'),
        get_string('settings:deepseekmodel_desc', 'mod_classengage'),
        'deepseek-chat'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/deepseekendpoint',
        get_string('settings:deepseekendpoint', 'mod_classengage'),
        get_string('settings:deepseekendpoint_desc', 'mod_classengage'),
        'https://api.deepseek.com/v1'
    ));

    // Kimi Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/kimi_heading',
        get_string('settings:kimi', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/kimiapikey',
        get_string('settings:kimiapikey', 'mod_classengage'),
        get_string('settings:kimiapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimimodel',
        get_string('settings:kimimodel', 'mod_classengage'),
        get_string('settings:kimimodel_desc', 'mod_classengage'),
        'moonshot-v1-8k'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimiendpoint',
        get_string('settings:kimiendpoint', 'mod_classengage'),
        get_string('settings:kimiendpoint_desc', 'mod_classengage'),
        'https://api.moonshot.ai/v1'
    ));

    // Kimi CN Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/kimicn_heading',
        get_string('settings:kimicn', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_classengage/kimicnapikey',
        get_string('settings:kimicnapikey', 'mod_classengage'),
        get_string('settings:kimicnapikey_desc', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimicnmodel',
        get_string('settings:kimicnmodel', 'mod_classengage'),
        get_string('settings:kimicnmodel_desc', 'mod_classengage'),
        'moonshot-v1-8k'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/kimicnendpoint',
        get_string('settings:kimicnendpoint', 'mod_classengage'),
        get_string('settings:kimicnendpoint_desc', 'mod_classengage'),
        'https://api.moonshot.cn/v1'
    ));

    // Local/Ollama Settings
    $settings->add(new admin_setting_heading(
        'mod_classengage/local_heading',
        get_string('settings:local', 'mod_classengage'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/localendpoint',
        get_string('settings:localendpoint', 'mod_classengage'),
        get_string('settings:localendpoint_desc', 'mod_classengage'),
        'http://localhost:11434'
    ));

    $settings->add(new admin_setting_configtext(
        'mod_classengage/localmodel',
        get_string('settings:localmodel', 'mod_classengage'),
        get_string('settings:localmodel_desc', 'mod_classengage'),
        'qwen3-vl:4b'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_classengage/autogeneratequestions',
        get_string('settings:autogeneratequestions', 'mod_classengage'),
        get_string('settings:autogeneratequestions_desc', 'mod_classengage'),
        0
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
