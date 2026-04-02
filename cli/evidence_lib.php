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
 * Shared helpers for ClassEngage report evidence CLI scripts.
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Load the core records needed for a session evidence export.
 *
 * @param int $sessionid
 * @return array
 */
function mod_classengage_evidence_load_bundle(int $sessionid): array {
    global $DB;

    $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
    $classengage = $DB->get_record('classengage', ['id' => $session->classengageid], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $classengage->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('classengage', $classengage->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    return [
        'session' => $session,
        'classengage' => $classengage,
        'course' => $course,
        'cm' => $cm,
        'context' => $context,
    ];
}

/**
 * Resolve the most relevant slide deck for a session.
 *
 * @param array $bundle
 * @param int $slideid
 * @return stdClass|null
 */
function mod_classengage_evidence_resolve_slide(array $bundle, int $slideid = 0): ?stdClass {
    global $DB;

    $classengage = $bundle['classengage'];
    $session = $bundle['session'];

    if ($slideid > 0) {
        $slide = $DB->get_record('classengage_slides', ['id' => $slideid], '*', MUST_EXIST);
        if ((int)$slide->classengageid !== (int)$classengage->id) {
            throw new moodle_exception('invalidslide', 'mod_classengage');
        }
        return $slide;
    }

    $sql = "SELECT s.id, COUNT(q.id) AS questioncount
              FROM {classengage_slides} s
              JOIN {classengage_questions} q ON q.slideid = s.id
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE s.classengageid = :classengageid
               AND sq.sessionid = :sessionid
          GROUP BY s.id
          ORDER BY questioncount DESC, s.id DESC";

    $params = [
        'classengageid' => $classengage->id,
        'sessionid' => $session->id,
    ];

    $matches = $DB->get_records_sql($sql, $params, 0, 1);
    if (!empty($matches)) {
        $match = reset($matches);
        return $DB->get_record('classengage_slides', ['id' => $match->id], '*', MUST_EXIST);
    }

    $latest = $DB->get_records(
        'classengage_slides',
        ['classengageid' => $classengage->id],
        'COALESCE(nlp_job_completed, 0) DESC, timecreated DESC, id DESC',
        '*',
        0,
        1
    );

    if (empty($latest)) {
        return null;
    }

    return reset($latest) ?: null;
}

/**
 * Return the stored slide file for a slide record.
 *
 * @param stdClass $slide
 * @param context_module $context
 * @return stored_file|null
 */
function mod_classengage_evidence_get_slide_file(stdClass $slide, context_module $context): ?stored_file {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_classengage', 'slides', $slide->id, 'id', false);

    if (empty($files)) {
        return null;
    }

    return reset($files) ?: null;
}

/**
 * Decode NLP metadata safely.
 *
 * @param stdClass|null $slide
 * @return array
 */
function mod_classengage_evidence_decode_metadata(?stdClass $slide): array {
    if (empty($slide) || empty($slide->nlp_generation_metadata)) {
        return [];
    }

    $metadata = json_decode((string)$slide->nlp_generation_metadata, true);
    return is_array($metadata) ? $metadata : [];
}

/**
 * Determine the actual number of pages/slides in a deck.
 *
 * @param stdClass|null $slide
 * @param context_module $context
 * @return int|null
 */
function mod_classengage_evidence_get_slide_page_count(?stdClass $slide, context_module $context): ?int {
    if (empty($slide)) {
        return null;
    }

    $metadata = mod_classengage_evidence_decode_metadata($slide);

    if (!empty($metadata['page_count'])) {
        return (int)$metadata['page_count'];
    }

    if (!empty($metadata['pages']) && is_array($metadata['pages'])) {
        return count($metadata['pages']);
    }

    if (!empty($metadata['selectedSlides']) && is_array($metadata['selectedSlides'])) {
        return count(array_unique(array_map('intval', $metadata['selectedSlides'])));
    }

    $file = mod_classengage_evidence_get_slide_file($slide, $context);
    if ($file) {
        try {
            $generator = new \mod_classengage\nlp_generator();
            $inspection = $generator->inspect_document($file);
            if (!empty($inspection['pages']) && is_array($inspection['pages'])) {
                return count($inspection['pages']);
            }
        } catch (Throwable $e) {
            // Fall through to weaker metadata heuristics.
        }
    }

    return null;
}

/**
 * Calculate latency distribution statistics.
 *
 * @param int[] $latenciesms
 * @return array
 */
function mod_classengage_evidence_calculate_latency_stats(array $latenciesms): array {
    $latenciesms = array_values(array_filter(array_map('intval', $latenciesms), static function(int $value): bool {
        return $value >= 0;
    }));

    sort($latenciesms);
    $count = count($latenciesms);

    if ($count === 0) {
        return [
            'count' => 0,
            'min_ms' => null,
            'avg_ms' => null,
            'p50_ms' => null,
            'p95_ms' => null,
            'max_ms' => null,
            'max_seconds_ceiling' => null,
        ];
    }

    $p50index = max(0, (int)floor(($count - 1) * 0.50));
    $p95index = max(0, (int)floor(($count - 1) * 0.95));
    $maxms = (int)$latenciesms[$count - 1];

    return [
        'count' => $count,
        'min_ms' => (int)$latenciesms[0],
        'avg_ms' => round(array_sum($latenciesms) / $count, 2),
        'p50_ms' => round((float)$latenciesms[$p50index], 2),
        'p95_ms' => round((float)$latenciesms[$p95index], 2),
        'max_ms' => $maxms,
        'max_seconds_ceiling' => ceil($maxms / 10) / 100,
    ];
}

/**
 * Shorten question text for terminal output.
 *
 * @param string $text
 * @param int $limit
 * @return string
 */
function mod_classengage_evidence_preview_text(string $text, int $limit = 110): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if ($text === '') {
        return '';
    }

    if (core_text::strlen($text) <= $limit) {
        return $text;
    }

    return core_text::substr($text, 0, max(0, $limit - 3)) . '...';
}

/**
 * Format a nullable duration in seconds.
 *
 * @param int|null $seconds
 * @return string
 */
function mod_classengage_evidence_format_seconds(?int $seconds): string {
    if ($seconds === null || $seconds < 0) {
        return 'n/a';
    }

    return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}

/**
 * Format a nullable float in seconds.
 *
 * @param float|null $seconds
 * @param int $precision
 * @return string
 */
function mod_classengage_evidence_format_float_seconds(?float $seconds, int $precision = 2): string {
    if ($seconds === null) {
        return 'n/a';
    }

    return number_format($seconds, $precision) . ' seconds';
}

/**
 * Format a nullable float in milliseconds.
 *
 * @param float|null $ms
 * @param int $precision
 * @return string
 */
function mod_classengage_evidence_format_milliseconds(?float $ms, int $precision = 2): string {
    if ($ms === null) {
        return 'n/a';
    }

    return number_format($ms, $precision) . ' ms';
}

/**
 * Build the quantitative beta-testing sentence from recorded metrics.
 *
 * @param array $metrics
 * @return string
 */
function mod_classengage_evidence_build_quantitative_sentence(array $metrics): string {
    $participants = (int)$metrics['participants'];
    $pagecount = $metrics['slide_page_count'];
    $questions = (int)$metrics['generated_question_count'];
    $duration = $metrics['nlp_generation_duration_s'];
    if ($duration === null) {
        $duration = $metrics['nlp_upload_to_completion_duration_s'];
    }
    $broadcastceiling = $metrics['broadcast_latency']['max_seconds_ceiling'];

    $presentation = 'a source presentation';
    if (!empty($pagecount)) {
        $presentation = 'a presentation containing ' . $pagecount . ' slides';
    }

    $sentence = 'To validate the system under realistic conditions, a simulated classroom session was conducted with ' .
        $participants . ' participants. ';

    $sentence .= 'The instructor successfully uploaded ' . $presentation;

    if ($duration !== null) {
        $sentence .= ', which the NLP engine processed in ' . $duration . ' seconds';
    }

    $sentence .= ' and converted into ' . $questions . ' viable questions. ';

    if ($broadcastceiling !== null) {
        $sentence .= 'During the live session, the system broadcast questions to connected student devices with an observed maximum server-side latency of less than ' .
            number_format($broadcastceiling, 2) . ' seconds.';
    } else {
        $sentence .= 'During the live session, the system successfully broadcast questions to connected student devices.';
    }

    return $sentence;
}

/**
 * Build the optional qualitative feedback paragraph.
 *
 * @param string $studentfeedback
 * @param string $instructorfeedback
 * @return string
 */
function mod_classengage_evidence_build_feedback_paragraph(string $studentfeedback, string $instructorfeedback): string {
    $studentfeedback = trim($studentfeedback);
    $instructorfeedback = trim($instructorfeedback);

    if ($studentfeedback === '' && $instructorfeedback === '') {
        return '[Insert qualitative feedback from the post-session student and instructor survey here.]';
    }

    $parts = [];

    if ($studentfeedback !== '') {
        $parts[] = 'Student feedback indicated that ' . rtrim($studentfeedback, '.') . '.';
    }

    if ($instructorfeedback !== '') {
        $parts[] = 'The instructor noted that ' . rtrim($instructorfeedback, '.') . '.';
    }

    return implode(' ', $parts);
}

/**
 * Collect the full evidence payload for a session.
 *
 * @param int $sessionid
 * @param int $slideid
 * @return array
 */
function mod_classengage_evidence_collect_metrics(int $sessionid, int $slideid = 0): array {
    global $DB;

    $bundle = mod_classengage_evidence_load_bundle($sessionid);
    $session = $bundle['session'];
    $context = $bundle['context'];
    $slide = mod_classengage_evidence_resolve_slide($bundle, $slideid);

    $analytics = new \mod_classengage\analytics_engine($bundle['classengage']->id, $context);
    $summary = $analytics->get_session_summary($sessionid);
    $breakdown = $analytics->get_question_breakdown($sessionid);

    $participantparams = [
        'sessionid' => $sessionid,
        'createdby' => $session->createdby,
    ];

    $responseparticipants = (int)$DB->get_field_sql(
        "SELECT COUNT(DISTINCT userid)
           FROM {classengage_responses}
          WHERE sessionid = :sessionid
            AND userid <> :createdby",
        $participantparams
    );

    $connectedparticipants = (int)$DB->get_field_sql(
        "SELECT COUNT(DISTINCT userid)
           FROM {classengage_connections}
          WHERE sessionid = :sessionid
            AND userid <> :createdby",
        $participantparams
    );

    $activeliveparticipants = (int)$DB->get_field_sql(
        "SELECT COUNT(DISTINCT userid)
           FROM {classengage_connections}
          WHERE sessionid = :sessionid
            AND userid <> :createdby
            AND status = :status",
        $participantparams + ['status' => 'connected']
    );

    $participants = max($responseparticipants, $connectedparticipants);

    $questioncount = (int)$DB->count_records('classengage_session_questions', ['sessionid' => $sessionid]);

    $hardestquestion = null;
    $hardestrate = null;
    foreach ($breakdown as $questionstat) {
        if ((int)$questionstat->total_responses <= 0) {
            continue;
        }

        if ($hardestrate === null || (float)$questionstat->success_rate < $hardestrate) {
            $hardestrate = (float)$questionstat->success_rate;
            $hardestquestion = mod_classengage_evidence_preview_text((string)$questionstat->question_text, 120);
        }
    }

    $broadcasttypes = ['session_start', 'next_question'];
    list($insql, $inparams) = $DB->get_in_or_equal($broadcasttypes, SQL_PARAMS_NAMED);
    $latencysql = "SELECT latency_ms
                     FROM {classengage_session_log}
                    WHERE sessionid = :sessionid
                      AND event_type {$insql}
                      AND latency_ms IS NOT NULL
                 ORDER BY latency_ms ASC";
    $latencies = $DB->get_fieldset_sql($latencysql, ['sessionid' => $sessionid] + $inparams);
    $broadcaststats = mod_classengage_evidence_calculate_latency_stats($latencies);

    $metadata = mod_classengage_evidence_decode_metadata($slide);
    $slidepagecount = mod_classengage_evidence_get_slide_page_count($slide, $context);
    $slidequestioncount = !empty($slide) ? (int)$DB->count_records('classengage_questions', ['slideid' => $slide->id]) : 0;
    $generatedquestioncount = !empty($slide) ? max((int)$slide->nlp_questions_count, $slidequestioncount) : 0;

    $nlpgenerationduration = null;
    $uploadtocompletionduration = null;
    if (!empty($slide)) {
        if (!empty($slide->nlp_job_started) && !empty($slide->nlp_job_completed)) {
            $nlpgenerationduration = max(0, (int)$slide->nlp_job_completed - (int)$slide->nlp_job_started);
        }

        if (!empty($slide->timecreated) && !empty($slide->nlp_job_completed)) {
            $uploadtocompletionduration = max(0, (int)$slide->nlp_job_completed - (int)$slide->timecreated);
        }
    }

    $questionscompleted = max(0, (int)$session->currentquestion + ($session->status === 'completed' ? 0 : 1));

    return [
        'bundle' => $bundle,
        'slide' => $slide,
        'metadata' => $metadata,
        'participants' => $participants,
        'participants_from_responses' => $responseparticipants,
        'participants_from_connections' => $connectedparticipants,
        'active_connected_participants' => $activeliveparticipants,
        'session_question_count' => $questioncount,
        'questions_completed_or_started' => min($questioncount, $questionscompleted),
        'generated_question_count' => $generatedquestioncount,
        'slide_question_count' => $slidequestioncount,
        'slide_page_count' => $slidepagecount,
        'nlp_generation_duration_s' => $nlpgenerationduration,
        'nlp_upload_to_completion_duration_s' => $uploadtocompletionduration,
        'broadcast_latency' => $broadcaststats,
        'summary' => $summary,
        'hardest_question' => $hardestquestion,
        'hardest_success_rate' => $hardestrate,
        'session_started_at' => !empty($session->timestarted) ? (int)$session->timestarted : null,
        'session_completed_at' => !empty($session->timecompleted) ? (int)$session->timecompleted : null,
    ];
}

/**
 * Build a screenshot-friendly benchmark report.
 *
 * @param array $metrics
 * @param string $studentfeedback
 * @param string $instructorfeedback
 * @return string
 */
function mod_classengage_evidence_build_benchmark_text(
    array $metrics,
    string $studentfeedback = '',
    string $instructorfeedback = ''
): string {
    $bundle = $metrics['bundle'];
    $session = $bundle['session'];
    $classengage = $bundle['classengage'];
    $slide = $metrics['slide'];
    $summary = $metrics['summary'];
    $latency = $metrics['broadcast_latency'];

    $lines = [];
    $lines[] = 'ClassEngage Classroom Beta Benchmark';
    $lines[] = '===================================';
    $lines[] = '';
    $lines[] = 'Session';
    $lines[] = '  Session ID: ' . $session->id;
    $lines[] = '  Session name: ' . format_string($session->name);
    $lines[] = '  Activity ID: ' . $classengage->id;
    $lines[] = '  Activity name: ' . format_string($classengage->name);
    $lines[] = '  Status: ' . $session->status;
    $lines[] = '  Questions in session: ' . $metrics['session_question_count'];
    $lines[] = '  Questions completed/started: ' . $metrics['questions_completed_or_started'];
    $lines[] = '  Timelimit per question: ' . (int)$session->timelimit . ' seconds';
    if (!empty($metrics['session_started_at'])) {
        $lines[] = '  Started: ' . userdate($metrics['session_started_at']);
    }
    if (!empty($metrics['session_completed_at'])) {
        $lines[] = '  Completed: ' . userdate($metrics['session_completed_at']);
    }
    $lines[] = '';
    $lines[] = 'Presentation and NLP';
    $lines[] = '  Slide ID: ' . (!empty($slide) ? $slide->id : 'n/a');
    $lines[] = '  Filename: ' . (!empty($slide) ? $slide->filename : 'n/a');
    $lines[] = '  Slide count: ' . ($metrics['slide_page_count'] ?? 'n/a');
    $lines[] = '  NLP status: ' . (!empty($slide) ? $slide->nlp_job_status : 'n/a');
    $lines[] = '  Generated questions: ' . $metrics['generated_question_count'];
    $lines[] = '  Provider: ' . (!empty($slide) && !empty($slide->nlp_provider) ? $slide->nlp_provider : 'n/a');
    $lines[] = '  Model: ' . (!empty($slide) && !empty($slide->nlp_model) ? $slide->nlp_model : 'n/a');
    $lines[] = '  NLP generation time: ' . mod_classengage_evidence_format_seconds($metrics['nlp_generation_duration_s']);
    $lines[] = '  Upload-to-completion time: ' . mod_classengage_evidence_format_seconds($metrics['nlp_upload_to_completion_duration_s']);
    $lines[] = '';
    $lines[] = 'Live Session Metrics';
    $lines[] = '  Participants observed: ' . $metrics['participants'];
    $lines[] = '  Participants with responses: ' . $metrics['participants_from_responses'];
    $lines[] = '  Participants seen in connections: ' . $metrics['participants_from_connections'];
    $lines[] = '  Currently connected participants: ' . $metrics['active_connected_participants'];
    $lines[] = '  Total responses: ' . (int)$summary->total_responses;
    $lines[] = '  Completion rate: ' . number_format((float)$summary->completion_rate, 2) . '%';
    $lines[] = '  Average score: ' . number_format((float)$summary->avg_score, 2) . '%';
    $lines[] = '  Average response time: ' . number_format((float)$summary->avg_response_time, 2) . ' seconds';
    $lines[] = '  Hardest question success rate: ' .
        ($metrics['hardest_success_rate'] === null ? 'n/a' : number_format((float)$metrics['hardest_success_rate'], 2) . '%');
    $lines[] = '  Hardest question preview: ' . ($metrics['hardest_question'] ?? 'n/a');
    $lines[] = '';
    $lines[] = 'Broadcast Latency';
    $lines[] = '  Samples: ' . $latency['count'];
    $lines[] = '  Min: ' . mod_classengage_evidence_format_milliseconds($latency['min_ms'], 0);
    $lines[] = '  Avg: ' . mod_classengage_evidence_format_milliseconds($latency['avg_ms']);
    $lines[] = '  P50: ' . mod_classengage_evidence_format_milliseconds($latency['p50_ms']);
    $lines[] = '  P95: ' . mod_classengage_evidence_format_milliseconds($latency['p95_ms']);
    $lines[] = '  Max: ' . mod_classengage_evidence_format_milliseconds($latency['max_ms'], 0);
    $lines[] = '  Claim-safe wording: ' .
        ($latency['max_seconds_ceiling'] === null ? 'n/a' : 'less than ' . number_format((float)$latency['max_seconds_ceiling'], 2) . ' seconds');
    $lines[] = '';
    $lines[] = 'Report-ready paragraph';
    $lines[] = mod_classengage_evidence_build_quantitative_sentence($metrics);
    $lines[] = mod_classengage_evidence_build_feedback_paragraph($studentfeedback, $instructorfeedback);
    $lines[] = '';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * Build a JSON-friendly payload.
 *
 * @param array $metrics
 * @return array
 */
function mod_classengage_evidence_build_json_payload(array $metrics): array {
    $bundle = $metrics['bundle'];
    $session = $bundle['session'];
    $classengage = $bundle['classengage'];
    $slide = $metrics['slide'];
    $summary = $metrics['summary'];

    return [
        'session' => [
            'id' => (int)$session->id,
            'name' => (string)$session->name,
            'status' => (string)$session->status,
            'timelimit' => (int)$session->timelimit,
            'question_count' => (int)$metrics['session_question_count'],
            'questions_completed_or_started' => (int)$metrics['questions_completed_or_started'],
            'started_at' => $metrics['session_started_at'],
            'completed_at' => $metrics['session_completed_at'],
        ],
        'activity' => [
            'id' => (int)$classengage->id,
            'name' => (string)$classengage->name,
            'course_id' => (int)$classengage->course,
        ],
        'slide' => $slide ? [
            'id' => (int)$slide->id,
            'filename' => (string)$slide->filename,
            'status' => (string)$slide->nlp_job_status,
            'provider' => (string)($slide->nlp_provider ?? ''),
            'model' => (string)($slide->nlp_model ?? ''),
            'slide_count' => $metrics['slide_page_count'],
            'generated_question_count' => (int)$metrics['generated_question_count'],
            'generation_duration_s' => $metrics['nlp_generation_duration_s'],
            'upload_to_completion_duration_s' => $metrics['nlp_upload_to_completion_duration_s'],
        ] : null,
        'live_metrics' => [
            'participants' => (int)$metrics['participants'],
            'participants_from_responses' => (int)$metrics['participants_from_responses'],
            'participants_from_connections' => (int)$metrics['participants_from_connections'],
            'active_connected_participants' => (int)$metrics['active_connected_participants'],
            'total_responses' => (int)$summary->total_responses,
            'completion_rate' => (float)$summary->completion_rate,
            'avg_score' => (float)$summary->avg_score,
            'avg_response_time_s' => (float)$summary->avg_response_time,
            'hardest_question' => $metrics['hardest_question'],
            'hardest_success_rate' => $metrics['hardest_success_rate'],
        ],
        'broadcast_latency' => $metrics['broadcast_latency'],
    ];
}

/**
 * Write a text artifact to disk, creating parent directories when needed.
 *
 * @param string $path
 * @param string $content
 * @return void
 */
function mod_classengage_evidence_write_file(string $path, string $content): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new moodle_exception('cannotcreatedir', 'error', '', $dir);
    }

    if (file_put_contents($path, $content) === false) {
        throw new moodle_exception('cannotwritefile', 'error', '', $path);
    }
}
