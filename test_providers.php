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
 * Web UI for testing NLP provider configuration.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/nlp/provider_manager.php');

admin_externalpage_setup('classengage_nlpproviderstest');

$stringmanager = get_string_manager();
$localstring = function (string $identifier, string $default) use ($stringmanager): string {
    if ($stringmanager->string_exists($identifier, 'mod_classengage')) {
        return get_string($identifier, 'mod_classengage');
    }
    return $default;
};

$defaultprompt = $localstring('nlptest_defaultprompt', 'Machine learning is a subset of artificial intelligence that focuses on patterns in data.');
$prompt = optional_param('prompt', $defaultprompt, PARAM_TEXT);
$run = optional_param('run', 0, PARAM_INT);

$providers = ['gemini', 'openai', 'anthropic', 'deepseek', 'kimi', 'kimicn'];
$manager = new \mod_classengage\nlp\provider_manager();
$results = [];

if ($run && confirm_sesskey()) {
    foreach ($providers as $name) {
        $provider = $manager->get_provider($name);
        if (!$provider) {
            $results[$name] = [
                'status' => 'missing',
                'message' => $localstring('nlptest_missing', 'Provider not available.'),
            ];
            continue;
        }

        if (!$provider->is_configured()) {
            $results[$name] = [
                'status' => 'notconfigured',
                'message' => $localstring('nlptest_notconfigured', 'API key not configured.'),
            ];
            continue;
        }

        try {
            $result = $provider->generate_questions($prompt, [
                'numQuestions' => 1,
                'difficulty' => 'medium',
                'bloomLevel' => 'apply',
            ]);
            $count = count($result['questions'] ?? []);
            $model = $provider->get_current_model();

            $results[$name] = [
                'status' => 'success',
                'message' => $localstring('nlptest_success', 'Success (questions: {$a->count}, model: {$a->model})'),
                'details' => [
                    'model' => $model,
                    'questions' => $result['questions'] ?? [],
                    'metadata' => $result['metadata'] ?? [],
                ],
            ];
            if ($stringmanager->string_exists('nlptest_success', 'mod_classengage')) {
                $results[$name]['message'] = get_string('nlptest_success', 'mod_classengage', [
                    'count' => $count,
                    'model' => $model ?: '-'
                ]);
            } else {
                $results[$name]['message'] = str_replace(
                    ['{$a->count}', '{$a->model}'],
                    [$count, $model ?: '-'],
                    $results[$name]['message']
                );
            }
        } catch (Exception $e) {
            $results[$name] = [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
}

echo $OUTPUT->header();

echo $OUTPUT->heading($localstring('nlptest_heading', 'NLP Provider Test'), 3);
echo html_writer::tag('p', $localstring('nlptest_intro', 'Run a simple one-question test against each configured provider. Results appear below.'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url,
    'class' => 'mform'
]);

echo html_writer::tag('label', $localstring('nlptest_prompt_label', 'Sample prompt text'), [
    'for' => 'id_prompt',
    'class' => 'form-label'
]);
echo html_writer::tag('textarea', s($prompt), [
    'id' => 'id_prompt',
    'name' => 'prompt',
    'rows' => 4,
    'class' => 'form-control',
    'style' => 'width: 100%;'
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'run',
    'value' => 1
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey()
]);
echo html_writer::tag('div',
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => $localstring('nlptest_run', 'Run tests')
    ]),
    ['class' => 'mt-3']
);

echo html_writer::end_tag('form');

if (!empty($results)) {
    $table = new html_table();
    $table->head = [
        $localstring('nlptest_provider', 'Provider'),
        $localstring('nlptest_status', 'Status'),
        $localstring('nlptest_message', 'Message'),
    ];

    foreach ($providers as $name) {
        $provider = $manager->get_provider($name);
        $label = $provider ? $provider->get_description() : ucfirst($name);
        $result = $results[$name] ?? null;
        if ($result) {
            $status = $result['status'];
            $message = $result['message'];
        } else {
            $status = 'skipped';
            $message = $localstring('nlptest_skipped', 'Not tested.');
        }

        $table->data[] = [
            s($label),
            s($status),
            s($message),
        ];
    }

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'mt-4']);

    $detailblocks = [];
    foreach ($providers as $name) {
        $provider = $manager->get_provider($name);
        $label = $provider ? $provider->get_description() : ucfirst($name);
        $result = $results[$name] ?? null;
        if (!$result) {
            continue;
        }

        $status = $result['status'] ?? 'unknown';
        $summary = $label . ' (' . $status . ')';
        $content = '';

        $content .= html_writer::tag('p', html_writer::tag('strong', $localstring('nlptest_status', 'Status')) . ': ' . s($status));
        if (!empty($result['message'])) {
            $content .= html_writer::tag('p', html_writer::tag('strong', $localstring('nlptest_message', 'Message')) . ': ' . s($result['message']));
        }

        $content .= html_writer::tag('p', html_writer::tag('strong', $localstring('nlptest_prompt_used', 'Prompt used')) . ':');
        $content .= html_writer::tag('pre', s($prompt), [
            'style' => 'white-space: pre-wrap; background: #f8f9fa; padding: 8px; border-radius: 4px;'
        ]);

        $details = $result['details'] ?? [];
        if (!empty($details['model'])) {
            $content .= html_writer::tag('p', html_writer::tag('strong', $localstring('nlptest_model', 'Model')) . ': ' . s($details['model']));
        }

        $questions = $details['questions'] ?? [];
        if (!empty($questions)) {
            $content .= html_writer::tag('h4', $localstring('nlptest_questions', 'Generated questions'));
            foreach ($questions as $index => $question) {
                $questiontitle = $localstring('nlptest_question', 'Question {$a}');
                if ($stringmanager->string_exists('nlptest_question', 'mod_classengage')) {
                    $questiontitle = get_string('nlptest_question', 'mod_classengage', $index + 1);
                } else {
                    $questiontitle = str_replace('{$a}', (string) ($index + 1), $questiontitle);
                }

                $content .= html_writer::tag('h5', s($questiontitle), ['style' => 'margin-top: 12px;']);

                $fields = [
                    [
                        'label' => $localstring('nlptest_field_question', 'Question text'),
                        'value' => $question['questiontext'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_optiona', 'Option A'),
                        'value' => $question['optiona'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_optionb', 'Option B'),
                        'value' => $question['optionb'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_optionc', 'Option C'),
                        'value' => $question['optionc'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_optiond', 'Option D'),
                        'value' => $question['optiond'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_correct', 'Correct answer'),
                        'value' => $question['correctanswer'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_difficulty', 'Difficulty'),
                        'value' => $question['difficulty'] ?? '',
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_cognitive', 'Cognitive level'),
                        'value' => $question['cognitive_level'] ?? ($question['bloomLevel'] ?? ''),
                        'raw' => false,
                    ],
                    [
                        'label' => $localstring('nlptest_field_rationale', 'Rationale'),
                        'value' => $question['rationale'] ?? '',
                        'raw' => false,
                    ],
                ];

                $imagevalue = $question['question_image'] ?? '';
                if (!empty($imagevalue)) {
                    $fields[] = [
                        'label' => $localstring('nlptest_field_image', 'Question image'),
                        'value' => html_writer::link(
                            $imagevalue,
                            s($imagevalue),
                            ['target' => '_blank', 'rel' => 'noopener']
                        ),
                        'raw' => true,
                    ];
                } else {
                    $fields[] = [
                        'label' => $localstring('nlptest_field_image', 'Question image'),
                        'value' => $localstring('nlptest_none', 'None'),
                        'raw' => false,
                    ];
                }

                $qtable = new html_table();
                $qtable->attributes = ['class' => 'generaltable'];
                $qtable->data = [];
                foreach ($fields as $field) {
                    $value = $field['raw'] ? $field['value'] : s((string) $field['value']);
                    $qtable->data[] = [
                        s($field['label']),
                        $value,
                    ];
                }
                $content .= html_writer::table($qtable);
            }
        }

        if (!empty($details['metadata'])) {
            $content .= html_writer::tag('h4', $localstring('nlptest_metadata', 'Metadata'));
            $content .= html_writer::tag('pre', s(json_encode($details['metadata'], JSON_PRETTY_PRINT)), [
                'style' => 'white-space: pre-wrap; background: #f8f9fa; padding: 8px; border-radius: 4px;'
            ]);
        }

        $attrs = ['class' => 'nlp-test-details', 'style' => 'margin-top: 12px;'];
        if ($status !== 'success') {
            $attrs['open'] = 'open';
        }

        $detailblocks[] = html_writer::tag('details',
            html_writer::tag('summary', s($summary)) . $content,
            $attrs
        );
    }

    if (!empty($detailblocks)) {
        echo html_writer::tag('div', implode("\n", $detailblocks), ['class' => 'mt-4']);
    }
}

echo $OUTPUT->footer();
