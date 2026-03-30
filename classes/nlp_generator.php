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
 * Internal NLP question generation engine for ClassEngage.
 *
 * This class replaces the external Node.js HTTP dependency with an
 * in-plugin PHP pipeline for:
 * - Document inspection (PDF, PPTX, DOCX, TXT)
 * - Image extraction and pluginfile delivery
 * - Multi-provider LLM generation
 * - Session analytics insights
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * NLP generator class.
 */
class nlp_generator {
    /** @var string Temporary cache subdirectory name. */
    private const CACHE_SUBDIR = 'mod_classengage_nlp';
    /** @var string File area used for extracted image assets. */
    private const ASSET_FILEAREA = 'nlpassets';
    /** @var string Built-in heuristic generator model label. */
    private const BUILTIN_MODEL = 'classengage-builtin-v1';
    /** @var array Supported provider identifiers. */
    private const PROVIDERS = ['gemini', 'openai', 'anthropic', 'deepseek', 'kimi', 'kimicn', 'local', 'builtin'];

    /**
     * Inspect document content and return pages/images inventory.
     *
     * @param \stored_file $file Moodle stored file
     * @return array
     */
    public function inspect_document($file) {
        if (!$file instanceof \stored_file) {
            throw new \Exception('Invalid file object for inspection');
        }

        $docid = $this->build_doc_id($file);
        $slideid = (int)$file->get_itemid();
        $contextid = (int)$file->get_contextid();

        // Check cache but validate it has actual content.
        $cached = $this->load_doc_cache($docid);
        if (!empty($cached) && (int)($cached['slideId'] ?? 0) === $slideid) {
            $pages = $cached['pages'] ?? [];
            // Only use cache if it has actual pages with content.
            if (!empty($pages) && is_array($pages)) {
                // Check at least first page has text or images.
                $firstPage = $pages[0] ?? null;
                if ($firstPage && (!empty($firstPage['text']) || !empty($firstPage['images']))) {
                    \debugging("ClassEngage NLP: Using cached inspection for docid=$docid", \DEBUG_DEVELOPER);
                    return [
                        'docId' => $docid,
                        'pages' => $pages,
                    ];
                }
            }
            \debugging("ClassEngage NLP: Cache invalid (empty content), re-inspecting docid=$docid", \DEBUG_DEVELOPER);
        }

        $tmpfile = $this->create_temp_copy($file);
        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            switch ($extension) {
                case 'pdf':
                    $pages = $this->inspect_pdf($tmpfile, $contextid, $slideid, $docid);
                    break;
                case 'pptx':
                    $pages = $this->inspect_pptx($tmpfile, $contextid, $slideid, $docid);
                    break;
                case 'docx':
                    $pages = $this->inspect_docx($tmpfile, $contextid, $slideid, $docid);
                    break;
                case 'txt':
                    $pages = $this->inspect_text($tmpfile);
                    break;
                default:
                    // Best-effort fallback for unsupported extensions.
                    $pages = $this->inspect_binary_fallback($tmpfile);
            }

            $cachedata = [
                'docId' => $docid,
                'slideId' => $slideid,
                'contextId' => $contextid,
                'filename' => $filename,
                'mimetype' => $file->get_mimetype(),
                'pages' => $pages,
                'createdAt' => time(),
            ];

            $this->save_doc_cache($docid, $cachedata);

            return [
                'docId' => $docid,
                'pages' => $pages,
            ];
        } finally {
            if (is_file($tmpfile)) {
                @unlink($tmpfile);
            }
        }
    }

    /**
     * Clear the inspection cache for a document.
     *
     * @param string $docid Document ID
     * @return bool True if cache was cleared
     */
    public function clear_doc_cache(string $docid): bool {
        $dir = \make_temp_directory(self::CACHE_SUBDIR);
        $path = $dir . '/' . $docid . '.json';
        if (is_file($path)) {
            return @unlink($path);
        }
        return false;
    }

    /**
     * Generate questions from an inspected document and store in DB.
     *
     * @param string $docid
     * @param int $classengageid
     * @param int $slideid
     * @param array $options
     * @return array
     */
    public function generate_questions_from_document($docid, $classengageid, $slideid, $options = []) {
        $cache = $this->load_doc_cache($docid);
        if (empty($cache)) {
            throw new \Exception('Document not found in local inspection cache');
        }

        if ((int)($cache['slideId'] ?? 0) !== (int)$slideid) {
            throw new \Exception('Document/slide mismatch');
        }

        $generationinput = $this->build_generation_input($cache, $options);
        $text = $generationinput['text'];
        $images = $generationinput['images'];

        if (trim($text) === '' && empty($images)) {
            throw new \Exception('No selected content available for generation');
        }

        $numquestions = (int)($options['numQuestions'] ?? \get_config('mod_classengage', 'defaultquestions') ?: 5);
        if ($numquestions < 1) {
            $numquestions = 1;
        }

        $prompt = $this->build_generation_prompt($text, $images, $options, $numquestions);
        $providerresponse = $this->request_provider_response($prompt, $images, 'questions', [
            'text' => $text,
            'images' => $images,
            'selectedslides' => $generationinput['selectedslides'],
            'options' => $options,
            'numquestions' => $numquestions,
        ]);

        $parsed = $this->parse_questions_response(
            $providerresponse['text'],
            $numquestions,
            $images,
            $generationinput['selectedslides'],
            $options
        );

        $questionids = $this->store_questions($parsed['questions'], $classengageid, $slideid, $text, $images);

        $metadata = [
            'provider' => $providerresponse['provider'],
            'model' => $providerresponse['model'],
            'requested' => $numquestions,
            'generated' => count($questionids),
            'selectedSlides' => $generationinput['selectedslides'],
            'selectedImages' => array_values(array_map(static function(array $img): string {
                return $img['imageId'];
            }, $images)),
            'plan' => $this->build_plan_metadata($options),
            'generatedAt' => time(),
        ];

        return [
            'questionids' => $questionids,
            'count' => count($questionids),
            'provider' => $providerresponse['provider'],
            'model' => $providerresponse['model'],
            'analysis' => $parsed['analysis'],
            'metadata' => $metadata,
        ];
    }

    /**
     * Compatibility stub for legacy async contract.
     *
     * @param string $docid
     * @param array $options
     * @return array
     */
    public function start_async_generation($docid, $options = []) {
        return [
            'jobId' => null,
            'docId' => $docid,
            'statusUrl' => null,
            'resultUrl' => null,
            'message' => 'Local PHP mode uses Moodle adhoc tasks for async execution',
        ];
    }

    /**
     * Compatibility stub for legacy async contract.
     *
     * @param string $jobid
     * @return array
     */
    public function poll_job_status($jobid) {
        return [
            'status' => 'unknown',
            'progress' => 0,
            'error' => null,
            'createdAt' => null,
            'startedAt' => null,
            'completedAt' => null,
        ];
    }

    /**
     * Compatibility stub for legacy async contract.
     *
     * @param string $jobid
     * @return array
     */
    public function get_job_result($jobid) {
        throw new \Exception('External NLP job result endpoint is not available in local PHP mode');
    }

    /**
     * No-op in local PHP mode (status is updated directly by adhoc task).
     *
     * @param int $slideid
     * @return void
     */
    public function check_and_update_job_status($slideid) {
        return;
    }

    /**
     * Compatibility wrapper for older call sites.
     *
     * @param string $docid
     * @param int $classengageid
     * @param int $slideid
     * @param array $options
     * @param int $maxwait
     * @param int $pollinterval
     * @return array
     */
    public function generate_questions_async($docid, $classengageid, $slideid, $options = [], $maxwait = 600, $pollinterval = 2) {
        $result = $this->generate_questions_from_document($docid, $classengageid, $slideid, $options);
        $result['jobId'] = null;
        return $result;
    }

    /**
     * Generate questions from raw text and store in DB.
     *
     * @param string $text
     * @param int $classengageid
     * @param int $numquestions
     * @param string $difficulty
     * @return array
     */
    public function generate_questions_from_text($text, $classengageid, $numquestions = 10, $difficulty = 'medium') {
        $text = trim((string)$text);
        if ($text === '') {
            throw new \Exception('Text content cannot be empty');
        }

        $options = [
            'numQuestions' => max(1, (int)$numquestions),
            'difficulty' => $difficulty,
            'bloomLevel' => 'remember',
        ];

        $prompt = $this->build_generation_prompt($text, [], $options, (int)$options['numQuestions']);
        $providerresponse = $this->request_provider_response($prompt, [], 'questions', [
            'text' => $text,
            'images' => [],
            'selectedslides' => [],
            'options' => $options,
            'numquestions' => (int)$options['numQuestions'],
        ]);
        $parsed = $this->parse_questions_response($providerresponse['text'], (int)$options['numQuestions'], [], [], $options);

        return $this->store_questions($parsed['questions'], $classengageid, 0);
    }

    /**
     * Analyze session data using configured LLM providers.
     *
     * @param array $sessiondata
     * @param array $options
     * @return array
     */
    public function analyze_session($sessiondata, $options = []) {
        $prompt = $this->build_analysis_prompt($sessiondata, $options);
        $response = $this->request_provider_response($prompt, [], 'text', [
            'sessiondata' => $sessiondata,
            'options' => $options,
        ]);

        $parsed = $this->try_decode_json_payload($response['text']);
        if (!is_array($parsed)) {
            return [
                'summary' => 'Analysis generated but provider did not return valid JSON.',
                'strengths' => ['Could not parse AI output into the expected structure.'],
                'areas_for_improvement' => [],
                'actionable_advice' => ['Please retry analysis or switch provider settings.'],
                'provider' => $response['provider'],
                'model' => $response['model'],
            ];
        }

        return [
            'summary' => (string)($parsed['summary'] ?? ''),
            'strengths' => $this->normalize_string_list($parsed['strengths'] ?? []),
            'areas_for_improvement' => $this->normalize_string_list($parsed['areas_for_improvement'] ?? []),
            'actionable_advice' => $this->normalize_string_list($parsed['actionable_advice'] ?? []),
            'provider' => $response['provider'],
            'model' => $response['model'],
        ];
    }

    /**
     * Legacy compatibility: inspect + generate from full document.
     *
     * @param \stored_file $file
     * @param int $classengageid
     * @param int $slideid
     * @param int|null $numquestions
     * @return array
     */
    public function generate_questions_from_file($file, $classengageid, $slideid, $numquestions = null) {
        $inspection = $this->inspect_document($file);
        $options = [
            'numQuestions' => $numquestions === null
                ? ((int)(\get_config('mod_classengage', 'defaultquestions') ?: 5))
                : max(1, (int)$numquestions),
            'difficulty' => 'mixed',
            'bloomLevel' => 'remember',
        ];

        $result = $this->generate_questions_from_document($inspection['docId'], $classengageid, $slideid, $options);
        return $result['questionids'];
    }

    /**
     * Check if required PDF tools are available.
     *
     * @return array Status of each tool
     */
    public function check_pdf_tools(): array {
        $status = [
            'pdftotext' => false,
            'pdfinfo' => false,
            'imagick' => false,
            'shell_exec' => false,
            'pdfparser' => $this->bundled_pdf_parser_available(),
        ];

        if (function_exists('shell_exec')) {
            $status['shell_exec'] = true;
            $status['pdftotext'] = !empty(shell_exec('which pdftotext 2>/dev/null'));
            $status['pdfinfo'] = !empty(shell_exec('which pdfinfo 2>/dev/null'));
        }

        $status['imagick'] = class_exists('\Imagick');

        return $status;
    }

    /**
     * Get the configured PDF text extraction mode.
     *
     * Modes:
     * - auto: use external tools when available, otherwise bundled parser
     * - external: require pdftotext
     * - bundled: always use the bundled parser
     *
     * @return string
     */
    public function get_pdf_text_extraction_mode(): string {
        $mode = (string)(\get_config('mod_classengage', 'pdftextmode') ?: 'auto');
        if (!in_array($mode, ['auto', 'external', 'bundled'], true)) {
            return 'auto';
        }
        return $mode;
    }

    /**
     * Get the backend that would currently be used for PDF text extraction.
     *
     * @return string
     */
    public function get_active_pdf_text_backend(): string {
        $mode = $this->get_pdf_text_extraction_mode();
        if ($mode !== 'auto') {
            return $mode;
        }

        $tools = $this->check_pdf_tools();
        if (!empty($tools['shell_exec']) && !empty($tools['pdftotext'])) {
            return 'external';
        }

        return 'bundled';
    }

    /**
     * Run a lightweight PDF extraction check for diagnostics pages and CLI.
     *
     * This verifies that the current runtime can extract text from a local PDF
     * without needing a Moodle stored_file context.
     *
     * @param string $filepath Absolute path to a local PDF
     * @return array
     */
    public function test_pdf_extraction(string $filepath): array {
        $texts = $this->extract_pdf_text_by_page($filepath);
        $pages = [];

        foreach ($texts as $page => $text) {
            $pages[] = [
                'page' => (int)$page,
                'text' => $text,
                'images' => [],
            ];
        }

        return $pages;
    }

    /**
     * Inspect PDF file.
     *
     * @param string $filepath
     * @param int $contextid
     * @param int $slideid
     * @param string $docid
     * @return array
     */
    private function inspect_pdf(string $filepath, int $contextid, int $slideid, string $docid): array {
        // Verify file exists and is readable.
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new \Exception('PDF file not accessible: ' . $filepath);
        }

        // Check if required tools are available.
        $tools = $this->check_pdf_tools();
        $mode = $this->get_pdf_text_extraction_mode();

        if ($mode === 'external' && (!$tools['shell_exec'] || !$tools['pdftotext'])) {
            throw new \Exception(
                'PDF extraction mode is set to external, but shell_exec() or pdftotext is not available. ' .
                'Switch the plugin to bundled or auto mode, or install the external PDF tools.'
            );
        }

        if ($mode === 'bundled' && !$tools['pdfparser']) {
            throw new \Exception(
                'PDF extraction mode is set to bundled, but the bundled PDF parser is not available in this plugin installation.'
            );
        }

        if ($mode === 'auto' && !$tools['pdftotext'] && !$tools['pdfparser']) {
            throw new \Exception(
                'PDF extraction requires either pdftotext or the bundled PDF parser, but neither is available in this runtime.'
            );
        }

        $texts = $this->extract_pdf_text_by_page($filepath);
        $pagecount = max(1, count($texts));

        // Try to get more accurate page count from Imagick.
        if ($tools['imagick']) {
            try {
                $probe = new \Imagick();
                $probe->pingImage($filepath);
                $imagickPageCount = (int)$probe->getNumberImages();
                if ($imagickPageCount > 0) {
                    $pagecount = max($pagecount, $imagickPageCount);
                }
                $probe->clear();
                $probe->destroy();
            } catch (\Exception $e) {
                \debugging('ClassEngage NLP PDF page probe failed: ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }

        \debugging("ClassEngage NLP: Processing PDF with $pagecount pages", \DEBUG_DEVELOPER);

        $pages = [];
        $successCount = 0;

        for ($i = 1; $i <= $pagecount; $i++) {
            $images = [];
            $text = $texts[$i] ?? '';

            // Render page preview image using Imagick.
            // OPTIMIZED: Use lower resolution for faster processing
            if ($tools['imagick']) {
                try {
                    $imagick = new \Imagick();
                    // Use 72 DPI for faster rendering (still sufficient for question context)
                    $imagick->setResolution(72, 72);
                    $imagick->readImage($filepath . '[' . ($i - 1) . ']');
                    $imagick->setImageFormat('png');
                    // Scale down to max 800px width to reduce file size and processing time
                    $imagick->scaleImage(800, 0);
                    $blob = $imagick->getImageBlob();

                    if (strlen($blob) > 0) {
                        $hash = substr(sha1($blob), 0, 6);
                        $imageid = 'img_' . $i . '_1_' . $hash;

                        $saved = $this->save_image_asset(
                            $contextid,
                            $slideid,
                            $docid,
                            $imageid,
                            $blob,
                            'image/png',
                            'page_' . $i . '_preview.png'
                        );

                        $images[] = [
                            'imageId' => $imageid,
                            'label' => 'Page ' . $i . ' preview',
                            'source' => 'PDF page ' . $i,
                            'url' => $saved['url'],
                            'filename' => $saved['filename'],
                            'mimetype' => 'image/png',
                        ];
                    }

                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Exception $e) {
                    \debugging('ClassEngage NLP PDF image render failed on page ' . $i . ': ' . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }

            $pages[] = [
                'page' => $i,
                'text' => $text,
                'images' => $images,
            ];

            if (!empty($text) || !empty($images)) {
                $successCount++;
            }
        }

        \debugging("ClassEngage NLP: PDF inspection complete - $successCount/$pagecount pages have content", \DEBUG_DEVELOPER);

        // Validate that we actually extracted something.
        if ($successCount === 0) {
            throw new \Exception('PDF extraction failed: no text or images could be extracted from any page');
        }

        return $pages;
    }

    /**
     * Inspect PPTX file.
     *
     * @param string $filepath
     * @param int $contextid
     * @param int $slideid
     * @param string $docid
     * @return array
     */
    private function inspect_pptx(string $filepath, int $contextid, int $slideid, string $docid): array {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Unable to open PPTX archive');
        }

        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide([0-9]+)\.xml$#', $name, $matches)) {
                $slides[(int)$matches[1]] = $name;
            }
        }
        ksort($slides);

        $pages = [];
        $seenhashes = [];

        foreach ($slides as $slidenum => $slidename) {
            $xml = (string)$zip->getFromName($slidename);
            $text = $this->extract_xml_text($xml, '/<a:t[^>]*>(.*?)<\/a:t>/si');

            $images = [];
            $relsname = dirname($slidename) . '/_rels/' . basename($slidename) . '.rels';
            $relsxml = (string)$zip->getFromName($relsname);

            if ($relsxml !== '') {
                $targets = [];
                if (preg_match_all('/<Relationship[^>]*Type="[^"]*\/image"[^>]*Target="([^"]+)"/i', $relsxml, $matches)) {
                    $targets = $matches[1];
                }

                $order = 0;
                foreach ($targets as $target) {
                    $entry = $this->normalize_zip_target($slidename, $target);
                    $binary = $zip->getFromName($entry);
                    if ($binary === false || $binary === '') {
                        continue;
                    }

                    $hash = md5($binary);
                    if (isset($seenhashes[$hash])) {
                        continue;
                    }
                    $seenhashes[$hash] = true;

                    $order++;
                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    $mimetype = $this->mime_from_extension($ext);
                    if ($mimetype === '') {
                        continue;
                    }

                    $imageid = 'img_' . $slidenum . '_' . $order . '_' . substr($hash, 0, 6);
                    $saved = $this->save_image_asset(
                        $contextid,
                        $slideid,
                        $docid,
                        $imageid,
                        $binary,
                        $mimetype,
                        basename($entry)
                    );

                    $images[] = [
                        'imageId' => $imageid,
                        'label' => 'Slide ' . $slidenum . ' image ' . $order,
                        'source' => basename($entry),
                        'url' => $saved['url'],
                        'filename' => $saved['filename'],
                        'mimetype' => $mimetype,
                    ];
                }
            }

            $pages[] = [
                'page' => $slidenum,
                'text' => $text,
                'images' => $images,
            ];
        }

        $zip->close();
        return $pages;
    }

    /**
     * Inspect DOCX file.
     *
     * @param string $filepath
     * @param int $contextid
     * @param int $slideid
     * @param string $docid
     * @return array
     */
    private function inspect_docx(string $filepath, int $contextid, int $slideid, string $docid): array {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Unable to open DOCX archive');
        }

        $documentxml = (string)$zip->getFromName('word/document.xml');
        $text = $this->extract_xml_text($documentxml, '/<w:t[^>]*>(.*?)<\/w:t>/si');

        $images = [];
        $seenhashes = [];
        $order = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (!preg_match('#^word/media/[^/]+\.(png|jpe?g|gif|bmp|webp)$#i', $entry)) {
                continue;
            }

            $binary = $zip->getFromName($entry);
            if ($binary === false || $binary === '') {
                continue;
            }

            $hash = md5($binary);
            if (isset($seenhashes[$hash])) {
                continue;
            }
            $seenhashes[$hash] = true;

            $order++;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $mimetype = $this->mime_from_extension($ext);
            if ($mimetype === '') {
                continue;
            }

            $imageid = 'img_1_' . $order . '_' . substr($hash, 0, 6);
            $saved = $this->save_image_asset(
                $contextid,
                $slideid,
                $docid,
                $imageid,
                $binary,
                $mimetype,
                basename($entry)
            );

            $images[] = [
                'imageId' => $imageid,
                'label' => 'Document image ' . $order,
                'source' => basename($entry),
                'url' => $saved['url'],
                'filename' => $saved['filename'],
                'mimetype' => $mimetype,
            ];
        }

        $zip->close();

        return [[
            'page' => 1,
            'text' => $text,
            'images' => $images,
        ]];
    }

    /**
     * Inspect plain text file.
     *
     * @param string $filepath
     * @return array
     */
    private function inspect_text(string $filepath): array {
        $content = @file_get_contents($filepath);
        $text = is_string($content) ? trim($content) : '';

        return [[
            'page' => 1,
            'text' => $this->normalize_text($text),
            'images' => [],
        ]];
    }

    /**
     * Inspect unknown/binary formats with a conservative text-only fallback.
     *
     * @param string $filepath
     * @return array
     */
    private function inspect_binary_fallback(string $filepath): array {
        $content = @file_get_contents($filepath);
        $text = '';

        if (is_string($content) && $content !== '') {
            // Keep printable ASCII and whitespace only.
            $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $content);
            $text = $this->normalize_text($text);
        }

        return [[
            'page' => 1,
            'text' => $text,
            'images' => [],
        ]];
    }

    /**
     * Build generation input from cached document and options.
     *
     * @param array $cache
     * @param array $options
     * @return array
     */
    private function build_generation_input(array $cache, array $options): array {
        $pages = $cache['pages'] ?? [];
        $docid = (string)$cache['docId'];
        $slideid = (int)$cache['slideId'];
        $contextid = (int)$cache['contextId'];

        $requestedslides = array_values(array_filter(array_map('intval', (array)($options['includeSlides'] ?? []))));
        $selectedslides = empty($requestedslides)
            ? array_values(array_map(static function(array $page): int {
                return (int)$page['page'];
            }, $pages))
            : $requestedslides;

        $slidemap = array_fill_keys($selectedslides, true);
        $textparts = [];

        foreach ($pages as $page) {
            $pagenum = (int)$page['page'];
            if (!isset($slidemap[$pagenum])) {
                continue;
            }

            $text = trim((string)($page['text'] ?? ''));
            if ($text !== '') {
                $textparts[] = '--- Page ' . $pagenum . ' ---' . "\n" . $text;
            }
        }

        $hasimageselection = array_key_exists('includeImages', $options);
        $requestedimageids = array_values(array_filter(array_map('strval', (array)($options['includeImages'] ?? []))));
        $imagemap = array_fill_keys($requestedimageids, true);

        $images = [];
        $fs = \get_file_storage();

        // OPTIMIZATION: Limit images to prevent slow API calls and timeouts
        $maxImages = 10; // Maximum number of images to process
        $imageCount = 0;

        foreach ($pages as $page) {
            $pagenum = (int)$page['page'];
            $fromselectedslide = isset($slidemap[$pagenum]);

            foreach ((array)($page['images'] ?? []) as $image) {
                // Skip if we've reached the image limit
                if ($imageCount >= $maxImages) {
                    break 2; // Break out of both loops
                }

                $imageid = (string)($image['imageId'] ?? '');
                if ($imageid === '') {
                    continue;
                }

                if ($hasimageselection) {
                    if (!isset($imagemap[$imageid])) {
                        continue;
                    }
                } else if (!$fromselectedslide) {
                    // Default mode: include images from selected slides only.
                    continue;
                }

                $filename = (string)($image['filename'] ?? '');
                if ($filename === '') {
                    continue;
                }

                $filepath = '/' . $docid . '/';
                $stored = $fs->get_file(
                    $contextid,
                    'mod_classengage',
                    self::ASSET_FILEAREA,
                    $slideid,
                    $filepath,
                    $filename
                );

                if (!$stored) {
                    continue;
                }

                $binary = $stored->get_content();
                $mimetype = (string)($image['mimetype'] ?? $stored->get_mimetype());

                $images[] = [
                    'imageId' => $imageid,
                    'label' => (string)($image['label'] ?? $imageid),
                    'url' => (string)($image['url'] ?? ''),
                    'mimetype' => $mimetype,
                    'data' => base64_encode($binary),
                ];
                $imageCount++;
            }
        }

        // Log warning if images were truncated
        if ($imageCount >= $maxImages) {
            \debugging("ClassEngage NLP: Image limit reached ({$maxImages}). Some images were skipped for faster processing.", \DEBUG_DEVELOPER);
        }

        return [
            'text' => $this->normalize_text(implode("\n\n", $textparts)),
            'images' => $images,
            'selectedslides' => array_values($selectedslides),
        ];
    }

    /**
     * Request a provider response with fallback across configured providers.
     *
     * @param string $prompt
     * @param array $images
     * @param string $mode questions|text
     * @return array
     */
    private function request_provider_response(
        string $prompt,
        array $images,
        string $mode = 'questions',
        array $context = []
    ): array {
        $order = $this->get_provider_order();
        $errors = [];

        foreach ($order as $provider) {
            $config = $this->get_provider_config($provider);
            if (!$this->provider_is_configured($provider, $config)) {
                continue;
            }

            try {
                if ($provider === 'builtin') {
                    $result = $mode === 'text'
                        ? $this->call_builtin_text($context)
                        : $this->call_builtin_questions($context);
                } else if ($mode === 'text') {
                    $result = $this->call_provider_text($provider, $config, $prompt);
                } else {
                    $result = $this->call_provider_questions($provider, $config, $prompt, $images);
                }

                return [
                    'provider' => $provider,
                    'model' => $result['model'] ?? $config['model'],
                    'text' => $result['text'] ?? '',
                ];
            } catch (\Exception $e) {
                $errors[] = $provider . ': ' . $e->getMessage();
                \debugging('ClassEngage NLP provider failure - ' . $provider . ': ' . $e->getMessage(), \DEBUG_DEVELOPER);
            }
        }

        if (empty($errors)) {
            throw new \Exception('No question-generation providers are available in this runtime.');
        }

        throw new \Exception('All configured NLP providers failed: ' . implode(' | ', $errors));
    }

    /**
     * Provider question generation dispatcher.
     *
     * @param string $provider
     * @param array $config
     * @param string $prompt
     * @param array $images
     * @return array
     */
    private function call_provider_questions(string $provider, array $config, string $prompt, array $images): array {
        $timeout = (int)($config['timeout'] ?? 120);

        switch ($provider) {
            case 'gemini':
                return $this->call_gemini($config, $prompt, $images, true, $timeout);

            case 'openai':
                return $this->call_openai_compatible($config, $prompt, $images, true, true, $timeout);

            case 'anthropic':
                return $this->call_anthropic($config, $prompt, $images, true, $timeout);

            case 'deepseek':
                return $this->call_openai_compatible($config, $prompt, [], false, false, $timeout);

            case 'kimi':
                return $this->call_openai_compatible($config, $prompt, [], false, false, $timeout);

            case 'kimicn':
                return $this->call_openai_compatible($config, $prompt, [], false, false, $timeout);

            case 'local':
                return $this->call_local($config, $prompt, $images, true, $timeout);

            default:
                throw new \Exception('Unsupported provider: ' . $provider);
        }
    }

    /**
     * Provider text generation dispatcher.
     *
     * @param string $provider
     * @param array $config
     * @param string $prompt
     * @return array
     */
    private function call_provider_text(string $provider, array $config, string $prompt): array {
        $timeout = (int)($config['timeout'] ?? 120);

        switch ($provider) {
            case 'gemini':
                return $this->call_gemini($config, $prompt, [], false, $timeout);

            case 'openai':
            case 'deepseek':
            case 'kimi':
            case 'kimicn':
                return $this->call_openai_compatible($config, $prompt, [], false, false, $timeout);

            case 'anthropic':
                return $this->call_anthropic($config, $prompt, [], false, $timeout);

            case 'local':
                return $this->call_local($config, $prompt, [], false, $timeout);

            default:
                throw new \Exception('Unsupported provider: ' . $provider);
        }
    }

    /**
     * Call Gemini provider.
     *
     * @param array $config
     * @param string $prompt
     * @param array $images
     * @param bool $jsonmode
     * @param int $timeout
     * @return array
     */
    private function call_gemini(array $config, string $prompt, array $images, bool $jsonmode, int $timeout): array {
        $apikey = (string)$config['apikey'];
        $model = (string)$config['model'];
        $endpoint = rtrim((string)$config['endpoint'], '/');

        $normalizedmodel = preg_replace('#^models/#', '', $model);
        $url = $endpoint . '/models/' . $normalizedmodel . ':generateContent?key=' . urlencode($apikey);

        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['text' => 'Image reference ID: ' . $image['imageId'] . '. Use this value in question_image when relevant.'];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image['mimetype'],
                    'data' => $image['data'],
                ],
            ];
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        if ($jsonmode) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $data = $this->http_json_request(
            $url,
            $payload,
            ['Content-Type: application/json'],
            $timeout
        );

        $text = '';
        foreach ((array)($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (!empty($part['text'])) {
                $text .= (string)$part['text'];
            }
        }

        if (trim($text) === '') {
            $feedback = (string)($data['promptFeedback']['blockReason'] ?? 'Empty Gemini response');
            throw new \Exception($feedback);
        }

        return [
            'text' => $text,
            'model' => (string)($data['modelVersion'] ?? $model),
        ];
    }

    /**
     * Call OpenAI-compatible provider.
     *
     * @param array $config
     * @param string $prompt
     * @param array $images
     * @param bool $multimodal
     * @param bool $jsonmode
     * @param int $timeout
     * @return array
     */
    private function call_openai_compatible(
        array $config,
        string $prompt,
        array $images,
        bool $multimodal,
        bool $jsonmode,
        int $timeout
    ): array {
        $endpoint = rtrim((string)$config['endpoint'], '/');
        if (!preg_match('#/v[0-9]+$#', $endpoint)) {
            $endpoint .= '/v1';
        }

        $url = $endpoint . '/chat/completions';
        $model = (string)$config['model'];

        if ($multimodal && !empty($images)) {
            $usercontent = [['type' => 'text', 'text' => $prompt]];
            foreach ($images as $image) {
                $usercontent[] = [
                    'type' => 'text',
                    'text' => 'Image reference ID: ' . $image['imageId'] . '. Use this value in question_image when relevant.',
                ];
                $usercontent[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:' . $image['mimetype'] . ';base64,' . $image['data'],
                    ],
                ];
            }
        } else {
            $usercontent = $prompt;
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert educator. Follow output instructions exactly and return JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $usercontent,
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => 4096,
        ];

        if ($jsonmode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $headers = [
            'Authorization: Bearer ' . $config['apikey'],
            'Content-Type: application/json',
        ];

        $data = $this->http_json_request($url, $payload, $headers, $timeout);

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $tmp = '';
            foreach ($content as $item) {
                if (is_array($item) && !empty($item['text'])) {
                    $tmp .= (string)$item['text'];
                }
            }
            $content = $tmp;
        }

        $content = trim((string)$content);
        if ($content === '') {
            throw new \Exception('Provider returned empty completion content');
        }

        return [
            'text' => $content,
            'model' => (string)($data['model'] ?? $model),
        ];
    }

    /**
     * Call Anthropic provider.
     *
     * @param array $config
     * @param string $prompt
     * @param array $images
     * @param bool $jsonmode
     * @param int $timeout
     * @return array
     */
    private function call_anthropic(array $config, string $prompt, array $images, bool $jsonmode, int $timeout): array {
        $endpoint = rtrim((string)$config['endpoint'], '/');
        $url = $endpoint . '/messages';
        $model = (string)$config['model'];

        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $image) {
            $content[] = [
                'type' => 'text',
                'text' => 'Image reference ID: ' . $image['imageId'] . '. Use this value in question_image when relevant.',
            ];
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mimetype'],
                    'data' => $image['data'],
                ],
            ];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $jsonmode ? 4096 : 1400,
            'temperature' => 0.2,
            'messages' => [[
                'role' => 'user',
                'content' => $content,
            ]],
        ];

        $headers = [
            'x-api-key: ' . $config['apikey'],
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ];

        $data = $this->http_json_request($url, $payload, $headers, $timeout);

        $text = '';
        foreach ((array)($data['content'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'text') {
                $text .= (string)($item['text'] ?? '');
            }
        }

        if (trim($text) === '') {
            throw new \Exception('Anthropic returned empty text content');
        }

        return [
            'text' => $text,
            'model' => (string)($data['model'] ?? $model),
        ];
    }

    /**
     * Call local Ollama-compatible provider.
     *
     * @param array $config
     * @param string $prompt
     * @param array $images
     * @param bool $jsonmode
     * @param int $timeout
     * @return array
     */
    private function call_local(array $config, string $prompt, array $images, bool $jsonmode, int $timeout): array {
        $endpoint = rtrim((string)$config['endpoint'], '/');
        $url = $endpoint . '/api/generate';
        $model = (string)$config['model'];

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.1,
                'num_ctx' => 4096,
            ],
        ];

        if ($jsonmode) {
            $payload['format'] = 'json';
        }

        if (!empty($images)) {
            $payload['images'] = array_values(array_map(static function(array $image): string {
                return $image['data'];
            }, $images));
        }

        $data = $this->http_json_request(
            $url,
            $payload,
            ['Content-Type: application/json'],
            $timeout
        );

        if (!empty($data['error'])) {
            throw new \Exception((string)$data['error']);
        }

        $text = trim((string)($data['response'] ?? ''));
        if ($text === '') {
            $text = trim((string)($data['thinking'] ?? ''));
        }

        if ($text === '') {
            throw new \Exception('Local provider returned an empty response');
        }

        return [
            'text' => $text,
            'model' => $model,
        ];
    }

    /**
     * Call the built-in heuristic question generator.
     *
     * @param array $context
     * @return array
     */
    private function call_builtin_questions(array $context): array {
        $text = $this->normalize_text((string)($context['text'] ?? ''));
        $images = !empty($context['images']) && is_array($context['images']) ? $context['images'] : [];
        $selectedslides = !empty($context['selectedslides']) && is_array($context['selectedslides'])
            ? $context['selectedslides']
            : [];
        $options = !empty($context['options']) && is_array($context['options']) ? $context['options'] : [];
        $numquestions = max(1, (int)($context['numquestions'] ?? 5));

        $questions = $this->generate_builtin_questions($text, $images, $selectedslides, $options, $numquestions);

        return [
            'text' => json_encode(['questions' => $questions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'model' => self::BUILTIN_MODEL,
        ];
    }

    /**
     * Call the built-in session analysis generator.
     *
     * @param array $context
     * @return array
     */
    private function call_builtin_text(array $context): array {
        $sessiondata = !empty($context['sessiondata']) && is_array($context['sessiondata'])
            ? $context['sessiondata']
            : [];

        return [
            'text' => json_encode(
                $this->build_builtin_session_analysis($sessiondata),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'model' => self::BUILTIN_MODEL,
        ];
    }

    /**
     * Generate multiple-choice questions without an external AI provider.
     *
     * @param string $text
     * @param array $images
     * @param array $selectedslides
     * @param array $options
     * @param int $numquestions
     * @return array
     */
    private function generate_builtin_questions(
        string $text,
        array $images,
        array $selectedslides,
        array $options,
        int $numquestions
    ): array {
        if ($text === '') {
            throw new \Exception(
                'Built-in question generation requires extractable text. ' .
                'For image-only content, configure an external multimodal provider.'
            );
        }

        $passages = $this->extract_builtin_passages($text);
        if (empty($passages)) {
            throw new \Exception('Built-in question generation could not find enough usable source sentences.');
        }

        $termbank = $this->build_builtin_term_bank($text, $passages);
        if (count($termbank) < 4) {
            throw new \Exception('Built-in question generation needs more distinct terms in the selected content.');
        }

        $defaultdifficulty = $this->normalize_difficulty((string)($options['difficulty'] ?? 'medium'));
        if ($defaultdifficulty === 'mixed') {
            $defaultdifficulty = 'medium';
        }

        $defaultbloom = $this->normalize_bloom_level((string)($options['bloomLevel'] ?? 'remember'));
        if ($defaultbloom === '') {
            $defaultbloom = 'remember';
        }

        $difficulties = $this->build_builtin_distribution_sequence(
            $options['difficultyDistribution'] ?? null,
            ['easy', 'medium', 'hard'],
            $numquestions,
            $defaultdifficulty
        );

        $blooms = $this->build_builtin_distribution_sequence(
            $options['bloomDistribution'] ?? null,
            ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'],
            $numquestions,
            $defaultbloom
        );

        $questions = [];
        $usedanswers = [];
        $usedstems = [];
        $defaultimageid = count($images) === 1 && !empty($images[0]['imageId'])
            ? (string)$images[0]['imageId']
            : null;

        foreach ($passages as $passage) {
            if (count($questions) >= $numquestions) {
                break;
            }

            $answer = $this->select_builtin_answer_term($passage, $termbank);
            if ($answer === '') {
                continue;
            }

            $answerkey = \core_text::strtolower($answer);
            if (isset($usedanswers[$answerkey])) {
                continue;
            }

            $distractors = $this->build_builtin_distractors($answer, $termbank, $passage);
            if (count($distractors) < 3) {
                continue;
            }

            $stem = $this->build_builtin_question_stem($passage, $answer, $blooms[count($questions)]);
            $stemkey = \core_text::strtolower($stem);
            if (isset($usedstems[$stemkey])) {
                continue;
            }

            $choices = [$answer, $distractors[0], $distractors[1], $distractors[2]];
            $choices = $this->stable_sort_builtin_choices($choices, $passage . '|' . $answer);

            $optionletters = ['A', 'B', 'C', 'D'];
            $correctanswer = 'A';
            $row = [
                'questiontext' => $stem,
                'difficulty' => $difficulties[count($questions)],
                'cognitive_level' => $blooms[count($questions)],
                'rationale' => 'The source states: ' . $passage,
            ];

            foreach ($optionletters as $index => $letter) {
                $row['option' . strtolower($letter)] = $choices[$index];
                if ($choices[$index] === $answer) {
                    $correctanswer = $letter;
                }
            }

            if ($defaultimageid !== null) {
                $row['question_image'] = $defaultimageid;
            }

            $row['correctanswer'] = $correctanswer;
            $questions[] = $row;
            $usedanswers[$answerkey] = true;
            $usedstems[$stemkey] = true;
        }

        if (count($questions) < $numquestions) {
            foreach ($termbank as $term => $score) {
                if (count($questions) >= $numquestions) {
                    break;
                }

                $passage = $this->find_builtin_passage_for_term($passages, $term);
                if ($passage === '') {
                    continue;
                }

                $answerkey = \core_text::strtolower($term);
                if (isset($usedanswers[$answerkey])) {
                    continue;
                }

                $distractors = $this->build_builtin_distractors($term, $termbank, $passage);
                if (count($distractors) < 3) {
                    continue;
                }

                $stem = $this->build_builtin_question_stem($passage, $term, $blooms[count($questions)]);
                $stemkey = \core_text::strtolower($stem);
                if (isset($usedstems[$stemkey])) {
                    continue;
                }

                $choices = [$term, $distractors[0], $distractors[1], $distractors[2]];
                $choices = $this->stable_sort_builtin_choices($choices, $passage . '|' . $term);

                $row = [
                    'questiontext' => $stem,
                    'difficulty' => $difficulties[count($questions)],
                    'cognitive_level' => $blooms[count($questions)],
                    'rationale' => 'The source states: ' . $passage,
                ];

                foreach (['A', 'B', 'C', 'D'] as $index => $letter) {
                    $row['option' . strtolower($letter)] = $choices[$index];
                    if ($choices[$index] === $term) {
                        $row['correctanswer'] = $letter;
                    }
                }

                if ($defaultimageid !== null) {
                    $row['question_image'] = $defaultimageid;
                }

                $questions[] = $row;
                $usedanswers[$answerkey] = true;
                $usedstems[$stemkey] = true;
            }
        }

        if (empty($questions)) {
            throw new \Exception('Built-in question generation could not build a valid multiple-choice set from the selected text.');
        }

        return $questions;
    }

    /**
     * Build a heuristic session analysis without an external AI provider.
     *
     * @param array $sessiondata
     * @return array
     */
    private function build_builtin_session_analysis(array $sessiondata): array {
        $engagement = !empty($sessiondata['engagement']) && is_array($sessiondata['engagement'])
            ? $sessiondata['engagement']
            : [];
        $comprehension = !empty($sessiondata['comprehension']) && is_array($sessiondata['comprehension'])
            ? $sessiondata['comprehension']
            : [];

        $participants = (int)($engagement['unique_participants'] ?? $engagement['participants'] ?? 0);
        $participation = (float)($engagement['participation_rate'] ?? $engagement['participationrate'] ?? 0);
        $correctness = (float)($comprehension['avg_correctness'] ?? $comprehension['correctness'] ?? 0);
        $responsetime = (float)($engagement['avg_response_time'] ?? $engagement['avgresponsetime'] ?? 0);

        if ($participants === 0 && $participation <= 0 && $correctness <= 0) {
            return [
                'summary' => 'No student response data is available yet. Wait until learners participate before relying on the analytics view.',
                'strengths' => [],
                'areas_for_improvement' => ['There is not enough evidence yet to judge engagement or comprehension.'],
                'actionable_advice' => [
                    'Run at least one question cycle and collect responses before using the analytics summary.',
                    'Encourage students to join the activity early so the first analytics snapshot is meaningful.',
                ],
            ];
        }

        $summaryparts = [];
        $summaryparts[] = $participants > 0
            ? $participants . ' student(s) participated in this session.'
            : 'Participation data is limited.';

        if ($correctness >= 75) {
            $summaryparts[] = 'Overall comprehension appears strong.';
        } else if ($correctness >= 50) {
            $summaryparts[] = 'Overall comprehension is mixed.';
        } else {
            $summaryparts[] = 'Overall comprehension appears weak and needs reinforcement.';
        }

        if ($participation > 0) {
            $summaryparts[] = 'Observed participation rate: ' . round($participation, 1) . '%.';
        }

        $strengths = [];
        if ($participation >= 70) {
            $strengths[] = 'Student participation was high across the session.';
        }
        if ($correctness >= 75) {
            $strengths[] = 'Most students answered correctly, which suggests the core concepts were understood.';
        }
        if ($responsetime > 0 && $responsetime <= 20) {
            $strengths[] = 'Responses were submitted quickly, which suggests students were confident.';
        }

        $improvements = [];
        if ($participation > 0 && $participation < 50) {
            $improvements[] = 'Participation was lower than expected, so some students may not be engaging consistently.';
        }
        if ($correctness > 0 && $correctness < 60) {
            $improvements[] = 'Correctness is low enough that the main topic likely needs re-teaching or additional examples.';
        }
        if ($responsetime >= 35) {
            $improvements[] = 'Students took a long time to respond, which may indicate uncertainty or unclear prompts.';
        }

        if (empty($strengths)) {
            $strengths[] = 'The session produced enough data to identify participation and comprehension trends.';
        }
        if (empty($improvements)) {
            $improvements[] = 'No major risk indicators were detected in the available session metrics.';
        }

        $advice = [];
        if ($correctness < 60) {
            $advice[] = 'Revisit the hardest concept with one worked example and one follow-up check question.';
        }
        if ($participation < 50) {
            $advice[] = 'Use a short warm-up question at the start of the next session to increase early participation.';
        }
        if ($responsetime >= 35) {
            $advice[] = 'Shorten the question wording or reduce the number of new ideas introduced per question.';
        }
        if (empty($advice)) {
            $advice[] = 'Keep the current pacing and add one higher-order follow-up question to confirm durable understanding.';
        }

        return [
            'summary' => implode(' ', $summaryparts),
            'strengths' => array_values(array_unique($strengths)),
            'areas_for_improvement' => array_values(array_unique($improvements)),
            'actionable_advice' => array_values(array_unique($advice)),
        ];
    }

    /**
     * Extract candidate passages for built-in question generation.
     *
     * @param string $text
     * @return array
     */
    private function extract_builtin_passages(string $text): array {
        $text = str_replace(["\xE2\x80\xA2", "\xC2\xB7", "\t"], ["\n", "\n", ' '], $text);
        $blocks = preg_split('/\n+/u', $text);
        $passages = [];

        foreach ($blocks as $block) {
            $block = $this->normalize_text((string)$block);
            if ($block === '') {
                continue;
            }

            $parts = preg_split('/(?<=[.!?])\s+/u', $block);
            if (!$parts) {
                $parts = [$block];
            }

            foreach ($parts as $part) {
                $candidate = $this->normalize_text((string)$part);
                if ($candidate === '') {
                    continue;
                }

                $wordcount = $this->builtin_word_count($candidate);
                if ($wordcount < 6 || $wordcount > 40) {
                    continue;
                }

                $length = \core_text::strlen($candidate);
                if ($length < 35 || $length > 280) {
                    continue;
                }

                if (preg_match('/^[0-9\W]+$/u', $candidate)) {
                    continue;
                }

                $key = \core_text::strtolower($candidate);
                $passages[$key] = $candidate;
            }
        }

        return array_values($passages);
    }

    /**
     * Build an importance-ranked term bank from the source text.
     *
     * @param string $text
     * @param array $passages
     * @return array
     */
    private function build_builtin_term_bank(string $text, array $passages): array {
        $scores = [];
        $stopwords = $this->get_builtin_stopwords();

        if (preg_match_all('/\b(?:[A-Z][\p{L}\p{Mn}-]+|[A-Z]{2,})(?:\s+(?:[A-Z][\p{L}\p{Mn}-]+|[A-Z]{2,})){0,3}\b/u', $text, $matches)) {
            foreach ($matches[0] as $phrase) {
                $phrase = trim((string)$phrase);
                if ($phrase === '' || \core_text::strlen($phrase) < 3) {
                    continue;
                }
                $scores[$phrase] = ($scores[$phrase] ?? 0) + 8 + min(4, substr_count($phrase, ' '));
            }
        }

        if (preg_match_all('/\b[\p{L}][\p{L}\p{Mn}-]{3,}\b/u', \core_text::strtolower($text), $matches)) {
            foreach ($matches[0] as $word) {
                if (isset($stopwords[$word])) {
                    continue;
                }
                $term = trim((string)$word);
                if ($term === '') {
                    continue;
                }
                $scores[$term] = ($scores[$term] ?? 0) + 1;
            }
        }

        foreach ($passages as $passage) {
            $terms = $this->extract_builtin_sentence_terms($passage);
            foreach ($terms as $term) {
                $scores[$term] = ($scores[$term] ?? 0) + 4;
            }
        }

        arsort($scores);
        return array_slice($scores, 0, 120, true);
    }

    /**
     * Extract sentence-level answer candidates.
     *
     * @param string $sentence
     * @return array
     */
    private function extract_builtin_sentence_terms(string $sentence): array {
        $terms = [];
        $stopwords = $this->get_builtin_stopwords();

        if (preg_match_all('/\b(?:[A-Z][\p{L}\p{Mn}-]+|[A-Z]{2,})(?:\s+(?:[A-Z][\p{L}\p{Mn}-]+|[A-Z]{2,})){0,2}\b/u', $sentence, $matches)) {
            foreach ($matches[0] as $phrase) {
                $phrase = trim((string)$phrase);
                if ($phrase !== '' && \core_text::strlen($phrase) >= 3) {
                    $terms[$phrase] = $phrase;
                }
            }
        }

        if (preg_match_all('/\b[\p{L}][\p{L}\p{Mn}-]{4,}\b/u', $sentence, $matches)) {
            foreach ($matches[0] as $word) {
                $normalized = \core_text::strtolower((string)$word);
                if (isset($stopwords[$normalized])) {
                    continue;
                }
                $terms[$normalized] = $word;
            }
        }

        return array_values($terms);
    }

    /**
     * Pick the best answer term for a sentence.
     *
     * @param string $sentence
     * @param array $termbank
     * @return string
     */
    private function select_builtin_answer_term(string $sentence, array $termbank): string {
        $sentenceTerms = $this->extract_builtin_sentence_terms($sentence);
        $bestterm = '';
        $bestscore = -1;

        foreach ($sentenceTerms as $term) {
            $score = (int)($termbank[$term] ?? $termbank[\core_text::strtolower($term)] ?? 0);
            if ($score <= 0) {
                $score = $this->builtin_word_count($term) > 1 ? 4 : 1;
            }

            if (\core_text::strlen($term) > 18) {
                $score += 2;
            }

            if ($score > $bestscore) {
                $bestscore = $score;
                $bestterm = $term;
            }
        }

        return $bestterm;
    }

    /**
     * Build distractors from the ranked term bank.
     *
     * @param string $answer
     * @param array $termbank
     * @param string $sentence
     * @return array
     */
    private function build_builtin_distractors(string $answer, array $termbank, string $sentence): array {
        $answerlower = \core_text::strtolower($answer);
        $answerwords = $this->builtin_word_count($answer);
        $answerlength = \core_text::strlen($answer);
        $candidates = [];

        foreach ($termbank as $term => $score) {
            $candidate = (string)$term;
            if ($this->builtin_terms_conflict($answerlower, $candidate)) {
                continue;
            }

            $candidatelower = \core_text::strtolower($candidate);
            if ($candidatelower === '') {
                continue;
            }

            $rank = (int)$score;
            $rank -= abs($this->builtin_word_count($candidate) - $answerwords) * 2;
            $rank -= (int)floor(abs(\core_text::strlen($candidate) - $answerlength) / 6);

            if (preg_match('/\b' . preg_quote($candidate, '/') . '\b/ui', $sentence)) {
                $rank -= 2;
            }

            $candidates[$candidate] = $rank;
        }

        arsort($candidates);
        $result = [];
        foreach (array_keys($candidates) as $candidate) {
            $candidatekey = \core_text::strtolower($candidate);
            if (isset($result[$candidatekey])) {
                continue;
            }
            $result[$candidatekey] = $candidate;
            if (count($result) >= 3) {
                break;
            }
        }

        return array_values($result);
    }

    /**
     * Build a question stem for a selected passage.
     *
     * @param string $sentence
     * @param string $answer
     * @param string $bloom
     * @return string
     */
    private function build_builtin_question_stem(string $sentence, string $answer, string $bloom): string {
        $replacement = preg_replace('/' . preg_quote($answer, '/') . '/u', '_____', $sentence, 1);
        if (!is_string($replacement) || trim($replacement) === '') {
            $replacement = $sentence;
        }

        $templates = [
            'remember' => 'Which term best completes the following statement?',
            'understand' => 'Which concept best fits the following explanation?',
            'apply' => 'Based on the material, which option best completes the following statement?',
            'analyze' => 'Which concept best matches the relationship described below?',
            'evaluate' => 'Which option is most consistent with the statement below?',
            'create' => 'Which idea from the material best completes the statement below?',
        ];

        $lead = $templates[$bloom] ?? $templates['remember'];
        return $lead . "\n\n" . $replacement;
    }

    /**
     * Find a representative passage for a term.
     *
     * @param array $passages
     * @param string $term
     * @return string
     */
    private function find_builtin_passage_for_term(array $passages, string $term): string {
        foreach ($passages as $passage) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/ui', $passage)) {
                return $passage;
            }
        }
        return '';
    }

    /**
     * Expand a configured distribution into a per-question sequence.
     *
     * @param mixed $distribution
     * @param array $allowed
     * @param int $count
     * @param string $fallback
     * @return array
     */
    private function build_builtin_distribution_sequence($distribution, array $allowed, int $count, string $fallback): array {
        $normalized = $this->normalize_distribution($distribution, $allowed);
        if (empty($normalized)) {
            return array_fill(0, $count, $fallback);
        }

        $sequence = [];
        foreach ($allowed as $item) {
            $portion = max(0.0, min(1.0, (float)($normalized[$item] ?? 0)));
            $target = (int)round($portion * $count);
            for ($i = 0; $i < $target; $i++) {
                $sequence[] = $item;
            }
        }

        while (count($sequence) < $count) {
            $sequence[] = $fallback;
        }

        return array_slice($sequence, 0, $count);
    }

    /**
     * Deterministically order answer choices.
     *
     * @param array $choices
     * @param string $seed
     * @return array
     */
    private function stable_sort_builtin_choices(array $choices, string $seed): array {
        usort($choices, static function(string $left, string $right) use ($seed): int {
            $leftkey = sha1($seed . '|' . $left);
            $rightkey = sha1($seed . '|' . $right);
            return strcmp($leftkey, $rightkey);
        });
        return array_values($choices);
    }

    /**
     * Count words in Unicode text.
     *
     * @param string $text
     * @return int
     */
    private function builtin_word_count(string $text): int {
        if (!preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\p{Mn}-]*\b/u', $text, $matches)) {
            return 0;
        }
        return count($matches[0]);
    }

    /**
     * Check whether two candidate terms are too similar to coexist as options.
     *
     * @param string $answerlower
     * @param string $candidate
     * @return bool
     */
    private function builtin_terms_conflict(string $answerlower, string $candidate): bool {
        $candidatelower = \core_text::strtolower($candidate);
        if ($candidatelower === $answerlower) {
            return true;
        }

        if (strpos($answerlower, $candidatelower) !== false || strpos($candidatelower, $answerlower) !== false) {
            return true;
        }

        return false;
    }

    /**
     * Common stopwords used by the built-in generator.
     *
     * @return array
     */
    private function get_builtin_stopwords(): array {
        $words = [
            'a', 'about', 'after', 'all', 'also', 'an', 'and', 'are', 'as', 'at', 'be', 'because', 'been', 'being',
            'between', 'both', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'during', 'each', 'for', 'from',
            'had', 'has', 'have', 'how', 'however', 'if', 'in', 'into', 'is', 'it', 'its', 'may', 'more', 'most',
            'must', 'not', 'of', 'on', 'one', 'or', 'other', 'our', 'out', 'over', 'should', 'since', 'so', 'some',
            'such', 'than', 'that', 'the', 'their', 'them', 'there', 'these', 'they', 'this', 'those', 'through',
            'to', 'under', 'up', 'use', 'used', 'using', 'very', 'was', 'we', 'were', 'what', 'when', 'where',
            'which', 'while', 'who', 'why', 'will', 'with', 'within', 'without', 'would', 'you', 'your'
        ];

        return array_fill_keys($words, true);
    }

    /**
     * Parse and normalize generated questions response.
     *
     * @param string $raw
     * @param int $expectedcount
     * @param array $selectedimages
     * @param array $selectedslides
     * @param array $options
     * @return array
     */
    private function parse_questions_response(
        string $raw,
        int $expectedcount,
        array $selectedimages,
        array $selectedslides,
        array $options
    ): array {
        $decoded = $this->decode_json_payload($raw);
        $analysis = null;

        if (isset($decoded['analysis']) && is_string($decoded['analysis'])) {
            $analysis = trim($decoded['analysis']);
        }

        if (isset($decoded['result']) && is_array($decoded['result'])) {
            if (isset($decoded['result']['analysis']) && is_string($decoded['result']['analysis'])) {
                $analysis = trim($decoded['result']['analysis']);
            }
            if (isset($decoded['result']['questions']) && is_array($decoded['result']['questions'])) {
                $rows = $decoded['result']['questions'];
            } else {
                $rows = [];
            }
        } else if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            $rows = $decoded['questions'];
        } else if ($this->is_list_array($decoded)) {
            $rows = $decoded;
        } else {
            $rows = [];
        }

        $imagemap = [];
        foreach ($selectedimages as $image) {
            $imagemap[(string)$image['imageId']] = $image;
        }

        $defaultdifficulty = $this->normalize_difficulty((string)($options['difficulty'] ?? 'medium'));
        if ($defaultdifficulty === 'mixed') {
            $defaultdifficulty = 'medium';
        }

        $defaultbloom = $this->normalize_bloom_level((string)($options['bloomLevel'] ?? 'remember'));
        if ($defaultbloom === '') {
            $defaultbloom = 'remember';
        }

        $sources = [
            'slides' => array_values(array_map('intval', $selectedslides)),
            'images' => array_values(array_map(static function(array $image): array {
                return [
                    'imageId' => $image['imageId'],
                    'label' => $image['label'],
                    'url' => $image['url'],
                    'reason' => 'Selected for generation',
                ];
            }, $selectedimages)),
        ];

        $questions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $question = $this->sanitize_question_row(
                $row,
                $imagemap,
                $sources,
                $defaultdifficulty,
                $defaultbloom
            );

            if (!empty($question)) {
                $questions[] = $question;
            }
        }

        if (empty($questions)) {
            throw new \Exception('Provider response did not contain valid questions');
        }

        if (count($questions) > $expectedcount) {
            $questions = array_slice($questions, 0, $expectedcount);
        }

        return [
            'questions' => $questions,
            'analysis' => $analysis,
        ];
    }

    /**
     * Sanitize single question row.
     *
     * @param array $row
     * @param array $imagemap
     * @param array $sources
     * @param string $defaultdifficulty
     * @param string $defaultbloom
     * @return array|null
     */
    private function sanitize_question_row(
        array $row,
        array $imagemap,
        array $sources,
        string $defaultdifficulty,
        string $defaultbloom
    ): ?array {
        $questiontext = trim((string)(
            $row['questiontext'] ??
            $row['question'] ??
            $row['questionText'] ??
            ''
        ));

        if ($questiontext === '') {
            return null;
        }

        $options = $this->extract_option_map($row);
        if (empty($options)) {
            return null;
        }

        $correctraw = $row['correctanswer'] ?? $row['correctAnswer'] ?? $row['answer'] ?? null;
        $correctanswer = $this->normalize_correct_answer($correctraw, $options);
        if ($correctanswer === null) {
            return null;
        }

        $difficulty = $this->normalize_difficulty((string)($row['difficulty'] ?? $defaultdifficulty));
        if ($difficulty === 'mixed') {
            $difficulty = $defaultdifficulty;
        }

        $bloomraw = (string)(
            $row['cognitive_level'] ??
            $row['cognitiveLevel'] ??
            $row['bloomlevel'] ??
            $row['bloomLevel'] ??
            $row['bloom_level'] ??
            $defaultbloom
        );
        $bloom = $this->normalize_bloom_level($bloomraw);
        if ($bloom === '') {
            $bloom = $defaultbloom;
        }

        $imagecandidate = (string)($row['question_image'] ?? $row['imageId'] ?? $row['image_id'] ?? '');
        $questionimage = $this->resolve_question_image($imagecandidate, $imagemap);

        $rationale = trim((string)($row['rationale'] ?? $row['explanation'] ?? ''));
        if ($rationale === '') {
            $rationale = null;
        }

        return [
            'questiontext' => $questiontext,
            'optiona' => $options['A'],
            'optionb' => $options['B'],
            'optionc' => $options['C'],
            'optiond' => $options['D'],
            'correctanswer' => $correctanswer,
            'difficulty' => $difficulty,
            'cognitive_level' => $bloom,
            'bloomlevel' => $bloom,
            'rationale' => $rationale,
            'question_image' => $questionimage,
            'sources' => $sources,
        ];
    }

    /**
     * Extract A/B/C/D option map from provider row.
     *
     * @param array $row
     * @return array|null
     */
    private function extract_option_map(array $row): ?array {
        $a = trim((string)($row['optiona'] ?? $row['optionA'] ?? ''));
        $b = trim((string)($row['optionb'] ?? $row['optionB'] ?? ''));
        $c = trim((string)($row['optionc'] ?? $row['optionC'] ?? ''));
        $d = trim((string)($row['optiond'] ?? $row['optionD'] ?? ''));

        if ($a !== '' && $b !== '' && $c !== '' && $d !== '') {
            return ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d];
        }

        $options = $row['options'] ?? $row['choices'] ?? null;
        if (!is_array($options)) {
            return null;
        }

        // Associative map with letter keys.
        $letters = ['A', 'B', 'C', 'D'];
        $assoc = [];
        foreach ($letters as $letter) {
            if (isset($options[$letter])) {
                $assoc[$letter] = trim((string)$options[$letter]);
            } else if (isset($options[strtolower($letter)])) {
                $assoc[$letter] = trim((string)$options[strtolower($letter)]);
            }
        }

        if (count($assoc) === 4 && $assoc['A'] !== '' && $assoc['B'] !== '' && $assoc['C'] !== '' && $assoc['D'] !== '') {
            return $assoc;
        }

        // Indexed list.
        if ($this->is_list_array($options) && count($options) >= 4) {
            $vals = array_values($options);
            $map = [
                'A' => trim((string)$vals[0]),
                'B' => trim((string)$vals[1]),
                'C' => trim((string)$vals[2]),
                'D' => trim((string)$vals[3]),
            ];
            if ($map['A'] !== '' && $map['B'] !== '' && $map['C'] !== '' && $map['D'] !== '') {
                return $map;
            }
        }

        return null;
    }

    /**
     * Resolve question image to a URL.
     *
     * @param string $candidate
     * @param array $imagemap
     * @return string|null
     */
    private function resolve_question_image(string $candidate, array $imagemap): ?string {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (isset($imagemap[$candidate])) {
            return (string)$imagemap[$candidate]['url'];
        }

        if (preg_match('/(img_[a-zA-Z0-9_]+)/', $candidate, $matches) && isset($imagemap[$matches[1]])) {
            return (string)$imagemap[$matches[1]]['url'];
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            return $candidate;
        }

        if (ctype_digit($candidate)) {
            $index = (int)$candidate;
            $images = array_values($imagemap);
            if (isset($images[$index])) {
                return (string)$images[$index]['url'];
            }
            if ($index > 0 && isset($images[$index - 1])) {
                return (string)$images[$index - 1]['url'];
            }
        }

        return null;
    }

    /**
     * Normalize correct answer into A/B/C/D.
     *
     * @param mixed $value
     * @param array $options
     * @return string|null
     */
    private function normalize_correct_answer($value, array $options): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim((string)$value));
        if (in_array($normalized, ['A', 'B', 'C', 'D'], true)) {
            return $normalized;
        }

        $numbermap = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];
        if (isset($numbermap[$normalized])) {
            return $numbermap[$normalized];
        }

        if (preg_match('/\b([ABCD])\b/', $normalized, $matches)) {
            return $matches[1];
        }

        foreach ($options as $letter => $optiontext) {
            $trimmedoption = trim((string)$optiontext);
            if ($trimmedoption === '') {
                continue;
            }

            if (strcasecmp($normalized, strtoupper($trimmedoption)) === 0) {
                return $letter;
            }

            if (strpos($normalized, strtoupper($trimmedoption)) !== false) {
                return $letter;
            }
        }

        return null;
    }

    /**
     * Normalize difficulty value.
     *
     * @param string $difficulty
     * @return string
     */
    private function normalize_difficulty(string $difficulty): string {
        $difficulty = strtolower(trim($difficulty));
        if (in_array($difficulty, ['easy', 'medium', 'hard', 'mixed'], true)) {
            return $difficulty;
        }

        if ($difficulty === 'difficult') {
            return 'hard';
        }

        return 'medium';
    }

    /**
     * Normalize bloom/cognitive value.
     *
     * @param string $bloom
     * @return string
     */
    private function normalize_bloom_level(string $bloom): string {
        $bloom = strtolower(trim($bloom));
        $aliases = [
            'remembering' => 'remember',
            'comprehend' => 'understand',
            'comprehension' => 'understand',
            'application' => 'apply',
            'analysis' => 'analyze',
            'evaluation' => 'evaluate',
            'creation' => 'create',
        ];

        if (isset($aliases[$bloom])) {
            $bloom = $aliases[$bloom];
        }

        if (in_array($bloom, ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'], true)) {
            return $bloom;
        }

        return '';
    }

    /**
     * Build generation prompt.
     *
     * @param string $text
     * @param array $images
     * @param array $options
     * @param int $numquestions
     * @return string
     */
    private function build_generation_prompt(string $text, array $images, array $options, int $numquestions): string {
        $difficulty = strtolower((string)($options['difficulty'] ?? 'mixed'));
        $bloom = strtolower((string)($options['bloomLevel'] ?? 'apply'));

        $difficultydist = $this->normalize_distribution(
            $options['difficultyDistribution'] ?? null,
            ['easy', 'medium', 'hard']
        );

        $bloomdist = $this->normalize_distribution(
            $options['bloomDistribution'] ?? null,
            ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create']
        );

        $textlimit = 80000; // Reduced from 120000 for faster processing
        if (\core_text::strlen($text) > $textlimit) {
            $text = \core_text::substr($text, 0, $textlimit) . '...[content truncated for performance]';
        }

        $imageinventory = 'None';
        if (!empty($images)) {
            $lines = [];
            foreach ($images as $image) {
                $lines[] = '- ' . $image['imageId'] . ': ' . ($image['label'] ?? $image['imageId']);
            }
            $imageinventory = implode("\n", $lines);
        }

        $isimageonly = trim($text) === '' && !empty($images);

        $prompt = [];
        $prompt[] = 'You are an expert educator. Generate multiple-choice questions from the provided material.';
        $prompt[] = '';
        $prompt[] = 'Return ONLY valid JSON with this schema:';
        $prompt[] = '{"questions":[{"questiontext":"...","optiona":"...","optionb":"...","optionc":"...","optiond":"...","correctanswer":"A","difficulty":"medium","cognitive_level":"apply","rationale":"..."}]}';
        $prompt[] = 'correctanswer must be A, B, C, or D.';
        $prompt[] = '';
        $prompt[] = 'Requirements:';
        $prompt[] = '- ' . $numquestions . ' questions total';
        $prompt[] = '- Difficulty: ' . $difficulty;
        $prompt[] = '- Bloom level: ' . $bloom;

        if (!empty($difficultydist)) {
            $prompt[] = '- Difficulty distribution: ' . json_encode($difficultydist);
        }
        if (!empty($bloomdist)) {
            $prompt[] = '- Bloom distribution: ' . json_encode($bloomdist);
        }

        $prompt[] = '';
        $prompt[] = 'Available image IDs:';
        $prompt[] = $imageinventory;
        $prompt[] = '';
        $prompt[] = $isimageonly
            ? 'No text content is available. Build questions from the attached images and image IDs.'
            : 'Source text:';

        if (!$isimageonly) {
            $prompt[] = $text;
        }

        return implode("\n", $prompt);
    }

    /**
     * Build session analysis prompt.
     *
     * @param array $sessiondata
     * @param array $options
     * @return string
     */
    private function build_analysis_prompt(array $sessiondata, array $options = []): string {
        // Check if there's actual data to analyze
        $hasengagement = isset($sessiondata['engagement']['unique_participants']) && $sessiondata['engagement']['unique_participants'] > 0;
        $hascomprehension = isset($sessiondata['comprehension']['avg_correctness']) && $sessiondata['comprehension']['avg_correctness'] > 0;
        
        $json = json_encode($sessiondata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        $context = '';
        if (!$hasengagement && !$hascomprehension) {
            $context = 'IMPORTANT: There is NO DATA for this session yet (no students have responded). 
            In your response, acknowledge this and suggest the teacher wait for students to participate before analyzing.';
        } elseif (!$hasengagement) {
            $context = 'IMPORTANT: There are no participants yet, but some comprehension data exists.
            Focus your advice on how to increase student engagement.';
        } elseif (!$hascomprehension) {
            $context = 'IMPORTANT: There are participants but no comprehension data (no questions answered correctly yet).
            Focus your advice on basic concept reinforcement.';
        }

        return implode("\n", [
            'You are an expert pedagogy analyst helping teachers understand their classroom performance.',
            'Analyze this class session data and provide actionable insights for what to teach next.',
            $context,
            '',
            'Output ONLY valid JSON with this exact schema:',
            '{"summary":"2-3 sentence overview","strengths":["what went well - be specific"],"areas_for_improvement":["topics/concepts that need re-teaching"],"actionable_advice":["specific next steps for the teacher"]}',
            '',
            'Session data:',
            $json ?: '{}',
            '',
            'Guidelines:',
            '- Be specific about which topics need re-teaching',
            '- If engagement is low, suggest ways to increase participation',
            '- If comprehension is low, suggest specific teaching strategies',
            '- Keep advice practical and immediately actionable',
            '- Output ONLY valid JSON, no other text',
        ]);
    }

    /**
     * Decode JSON payload from potentially wrapped model output.
     *
     * @param string $raw
     * @return array
     */
    private function decode_json_payload(string $raw): array {
        $decoded = $this->try_decode_json_payload($raw);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON payload from provider');
        }
        return $decoded;
    }

    /**
     * Try decode JSON payload.
     *
     * @param string $raw
     * @return array|null
     */
    private function try_decode_json_payload(string $raw): ?array {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }

        $clean = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $clean);
        $clean = preg_replace('/```(?:json)?/i', '', $clean);
        $clean = str_replace('```', '', $clean);
        $clean = trim($clean);

        // Try direct decode first.
        $direct = json_decode($clean, true);
        if (is_array($direct)) {
            return $direct;
        }

        $startbrace = strpos($clean, '{');
        $startbracket = strpos($clean, '[');

        if ($startbrace === false && $startbracket === false) {
            return null;
        }

        if ($startbracket !== false && ($startbrace === false || $startbracket < $startbrace)) {
            $start = $startbracket;
            $end = strrpos($clean, ']');
        } else {
            $start = $startbrace;
            $end = strrpos($clean, '}');
        }

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($clean, $start, $end - $start + 1);
        $candidate = preg_replace('/,\s*([}\]])/', '$1', $candidate);

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Perform JSON HTTP POST request.
     *
     * @param string $url
     * @param array $payload
     * @param array $headers
     * @param int $timeout
     * @return array
     */
    private function http_json_request(string $url, array $payload, array $headers, int $timeout): array {
        // Check if curl extension is available
        if (!extension_loaded('curl')) {
            throw new \Exception('PHP curl extension is not installed. Please contact your server administrator to enable the curl extension.');
        }
        
        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_HTTPHEADER' => $headers,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $response = $curl->post($url, $body, $options);

        $httpcode = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno()) {
            throw new \Exception('Connection failed: ' . $curl->error);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            $snippet = is_string($response) ? trim(substr($response, 0, 600)) : '';
            throw new \Exception('HTTP ' . $httpcode . ' ' . $snippet);
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON response from provider');
        }

        return $decoded;
    }

    /**
     * Build provider order from settings.
     *
     * @return array
     */
    private function get_provider_order(): array {
        $default = (string)(\get_config('mod_classengage', 'nlpdefaultprovider') ?: 'gemini');
        $prioritycsv = (string)(\get_config('mod_classengage', 'nlpproviderpriority')
            ?: 'gemini,openai,anthropic,deepseek,kimi,kimicn,local,builtin');

        $priority = array_values(array_filter(array_map('trim', explode(',', strtolower($prioritycsv)))));
        $ordered = [];

        $push = static function(string $provider) use (&$ordered): void {
            if (!in_array($provider, $ordered, true)) {
                $ordered[] = $provider;
            }
        };

        $push(strtolower($default));
        foreach ($priority as $provider) {
            $push($provider);
        }
        foreach (self::PROVIDERS as $provider) {
            $push($provider);
        }

        return array_values(array_filter($ordered, static function(string $provider): bool {
            return in_array($provider, self::PROVIDERS, true);
        }));
    }

    /**
     * Get provider configuration.
     *
     * @param string $provider
     * @return array
     */
    private function get_provider_config(string $provider): array {
        $timeout = (int)(\get_config('mod_classengage', 'nlprequesttimeout') ?: 120);
        if ($timeout < 15) {
            $timeout = 15;
        }

        switch ($provider) {
            case 'gemini':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'geminiapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'geminimodel') ?: 'gemini-2.5-flash'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'geminiendpoint') ?: 'https://generativelanguage.googleapis.com/v1beta'),
                    'timeout' => $timeout,
                ];

            case 'openai':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'openaiapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'openaimodel') ?: 'gpt-4o-mini'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'openaiendpoint') ?: 'https://api.openai.com/v1'),
                    'timeout' => $timeout,
                ];

            case 'anthropic':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'anthropicapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'anthropicmodel') ?: 'claude-3-5-sonnet-20241022'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'anthropicendpoint') ?: 'https://api.anthropic.com/v1'),
                    'timeout' => $timeout,
                ];

            case 'deepseek':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'deepseekapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'deepseekmodel') ?: 'deepseek-chat'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'deepseekendpoint') ?: 'https://api.deepseek.com/v1'),
                    'timeout' => $timeout,
                ];

            case 'kimi':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'kimiapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'kimimodel') ?: 'moonshot-v1-8k'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'kimiendpoint') ?: 'https://api.moonshot.ai/v1'),
                    'timeout' => $timeout,
                ];

            case 'kimicn':
                return [
                    'apikey' => (string)\get_config('mod_classengage', 'kimicnapikey'),
                    'model' => (string)(\get_config('mod_classengage', 'kimicnmodel') ?: 'moonshot-v1-8k'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'kimicnendpoint') ?: 'https://api.moonshot.cn/v1'),
                    'timeout' => $timeout,
                ];

            case 'local':
                return [
                    'apikey' => '',
                    'model' => (string)(\get_config('mod_classengage', 'localmodel') ?: 'qwen3-vl:4b'),
                    'endpoint' => (string)(\get_config('mod_classengage', 'localendpoint') ?: ''),
                    'timeout' => max($timeout, 180),
                ];

            case 'builtin':
                return [
                    'apikey' => '',
                    'model' => self::BUILTIN_MODEL,
                    'endpoint' => '',
                    'timeout' => 0,
                ];

            default:
                return [
                    'apikey' => '',
                    'model' => '',
                    'endpoint' => '',
                    'timeout' => $timeout,
                ];
        }
    }

    /**
     * Check whether provider has usable configuration.
     *
     * @param string $provider
     * @param array $config
     * @return bool
     */
    private function provider_is_configured(string $provider, array $config): bool {
        if ($provider === 'builtin') {
            return true;
        }

        if ($provider === 'local') {
            return trim((string)($config['endpoint'] ?? '')) !== '';
        }

        return trim((string)($config['apikey'] ?? '')) !== '';
    }

    /**
     * Create deterministic doc ID.
     *
     * @param \stored_file $file
     * @return string
     */
    private function build_doc_id(\stored_file $file): string {
        $seed = $file->get_contenthash() . ':' . $file->get_filesize() . ':' . $file->get_filename();
        return 'doc_' . substr(sha1($seed), 0, 16);
    }

    /**
     * Create temp copy of stored file.
     *
     * @param \stored_file $file
     * @return string
     */
    private function create_temp_copy(\stored_file $file): string {
        $tmpfile = tempnam(sys_get_temp_dir(), 'classengage_nlp_');
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        if ($ext !== '') {
            $withExt = $tmpfile . '.' . $ext;
            if (@rename($tmpfile, $withExt)) {
                $tmpfile = $withExt;
            }
        }

        $file->copy_content_to($tmpfile);
        return $tmpfile;
    }

    /**
     * Save extracted image asset in Moodle file API.
     *
     * @param int $contextid
     * @param int $slideid
     * @param string $docid
     * @param string $imageid
     * @param string $binary
     * @param string $mimetype
     * @param string $originalname
     * @return array
     */
    private function save_image_asset(
        int $contextid,
        int $slideid,
        string $docid,
        string $imageid,
        string $binary,
        string $mimetype,
        string $originalname
    ): array {
        $ext = $this->extension_from_mime($mimetype);
        if ($ext === '') {
            $ext = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));
        }
        if ($ext === '') {
            $ext = 'png';
        }

        $filename = $imageid . '.' . $ext;
        $filepath = '/' . $docid . '/';

        $fs = \get_file_storage();
        $existing = $fs->get_file($contextid, 'mod_classengage', self::ASSET_FILEAREA, $slideid, $filepath, $filename);
        if ($existing) {
            $existing->delete();
        }

        $filerecord = [
            'contextid' => $contextid,
            'component' => 'mod_classengage',
            'filearea' => self::ASSET_FILEAREA,
            'itemid' => $slideid,
            'filepath' => $filepath,
            'filename' => $filename,
            'userid' => 0,
            'mimetype' => $mimetype,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $fs->create_file_from_string($filerecord, $binary);

        // Generate relative URL path (without host) for portability
        // This allows the same data to work with any host (localhost, internal docker, domain)
        // Format: /pluginfile.php/...
        $urlobj = \moodle_url::make_pluginfile_url(
            $contextid,
            'mod_classengage',
            self::ASSET_FILEAREA,
            $slideid,
            $filepath,
            $filename
        );
        
        // Get just the path component (e.g., /pluginfile.php/16/mod_classengage/...)
        $url = $urlobj->get_path();

        return [
            'filename' => $filename,
            'url' => $url,
        ];
    }

    /**
     * Save inspection cache to temp directory.
     *
     * @param string $docid
     * @param array $data
     * @return void
     */
    private function save_doc_cache(string $docid, array $data): void {
        $dir = \make_temp_directory(self::CACHE_SUBDIR);
        $path = $dir . '/' . $docid . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Load inspection cache from temp directory.
     *
     * @param string $docid
     * @return array|null
     */
    private function load_doc_cache(string $docid): ?array {
        $dir = \make_temp_directory(self::CACHE_SUBDIR);
        $path = $dir . '/' . $docid . '.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract text from XML with regex and normalize.
     *
     * @param string $xml
     * @param string $pattern
     * @return string
     */
    private function extract_xml_text(string $xml, string $pattern): string {
        if ($xml === '') {
            return '';
        }

        $parts = [];
        if (preg_match_all($pattern, $xml, $matches)) {
            foreach ($matches[1] as $part) {
                $decoded = html_entity_decode((string)$part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $decoded = trim(strip_tags($decoded));
                if ($decoded !== '') {
                    $parts[] = $decoded;
                }
            }
        }

        return $this->normalize_text(implode(' ', $parts));
    }

    /**
     * Normalize free text whitespace.
     *
     * @param string $text
     * @return string
     */
    private function normalize_text(string $text): string {
        $text = str_replace("\0", ' ', $text);
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Normalize relationship target to ZIP entry path.
     *
     * @param string $baseentry
     * @param string $target
     * @return string
     */
    private function normalize_zip_target(string $baseentry, string $target): string {
        $target = str_replace('\\', '/', $target);
        if (strpos($target, '/') === 0) {
            return ltrim($target, '/');
        }

        $baseparts = explode('/', trim(dirname($baseentry), '/'));
        $targetparts = explode('/', $target);

        foreach ($targetparts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($baseparts);
            } else {
                $baseparts[] = $part;
            }
        }

        return implode('/', $baseparts);
    }

    /**
     * Extract PDF text with pdftotext and split by pages.
     *
     * @param string $filepath
     * @return array
     */
    private function extract_pdf_text_by_page(string $filepath): array {
        $mode = $this->get_pdf_text_extraction_mode();

        if ($mode === 'external') {
            return $this->extract_pdf_text_by_page_external($filepath);
        }

        if ($mode === 'bundled') {
            return $this->extract_pdf_text_by_page_bundled($filepath);
        }

        if (function_exists('shell_exec') && !empty(shell_exec('which pdftotext 2>/dev/null'))) {
            $result = $this->extract_pdf_text_by_page_external($filepath);
            if ($this->has_non_empty_page_text($result)) {
                \debugging('ClassEngage NLP: Using external pdftotext backend', \DEBUG_DEVELOPER);
                return $result;
            }

            \debugging(
                'ClassEngage NLP: External pdftotext backend returned no usable text, falling back to bundled parser',
                \DEBUG_DEVELOPER
            );
        }

        \debugging('ClassEngage NLP: Using bundled PDF parser backend', \DEBUG_DEVELOPER);
        return $this->extract_pdf_text_by_page_bundled($filepath);
    }

    /**
     * Extract PDF text with pdftotext and split by pages.
     *
     * @param string $filepath
     * @return array
     */
    private function extract_pdf_text_by_page_external(string $filepath): array {
        if (!function_exists('shell_exec')) {
            throw new \Exception('PDF extraction requires shell_exec PHP function which is disabled. Please enable it or install a PDF extraction plugin.');
        }

        // Check if pdftotext is available.
        if (empty(shell_exec('which pdftotext 2>/dev/null'))) {
            throw new \Exception(
                'PDF extraction requires pdftotext (Poppler utils) which is not installed. ' .
                'Install the Poppler utilities package on the server or in the worker/container image that executes background tasks.'
            );
        }

        // First try: extract with layout preservation and form feed separators.
        $command = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($filepath) . ' - 2>&1';
        $output = shell_exec($command);

        // Check for errors.
        if (!is_string($output)) {
            \debugging('ClassEngage NLP: pdftotext returned null', \DEBUG_DEVELOPER);
            return [];
        }

        // Check for common errors in output.
        if (strpos($output, 'Error') === 0 || strpos($output, 'I/O Error') !== false) {
            \debugging('ClassEngage NLP: pdftotext error: ' . substr($output, 0, 200), \DEBUG_DEVELOPER);
            return [];
        }

        $trimmed = trim($output);
        if ($trimmed === '') {
            \debugging('ClassEngage NLP: pdftotext returned empty output', \DEBUG_DEVELOPER);
            return [];
        }

        // Split by form feed character (page break).
        $chunks = preg_split('/\f/u', $output);
        $result = [];

        foreach ($chunks as $index => $chunk) {
            $text = $this->normalize_text((string)$chunk);
            if ($text !== '') {
                $result[$index + 1] = $text;
            }
        }

        // If we got results, return them.
        if (!empty($result)) {
            \debugging('ClassEngage NLP: Extracted text from ' . count($result) . ' pages using form feed separation', \DEBUG_DEVELOPER);
            return $result;
        }

        // Fallback: if no form feeds, try to get page count from pdfinfo and extract per-page.
        $pageCount = $this->get_pdf_page_count($filepath);
        if ($pageCount > 0) {
            \debugging('ClassEngage NLP: Attempting per-page extraction for ' . $pageCount . ' pages', \DEBUG_DEVELOPER);
            for ($page = 1; $page <= $pageCount; $page++) {
                $cmd = 'pdftotext -layout -enc UTF-8 -f ' . $page . ' -l ' . $page . ' ' . escapeshellarg($filepath) . ' - 2>/dev/null';
                $pageOutput = shell_exec($cmd);
                if (is_string($pageOutput)) {
                    $text = $this->normalize_text($pageOutput);
                    if ($text !== '') {
                        $result[$page] = $text;
                    }
                }
            }
        }

        // Last resort: return all text as single page.
        if (empty($result) && $trimmed !== '') {
            $result[1] = $this->normalize_text($output);
            \debugging('ClassEngage NLP: Returning all text as single page (no page breaks detected)', \DEBUG_DEVELOPER);
        }

        return $result;
    }

    /**
     * Extract PDF text with the bundled Smalot parser.
     *
     * @param string $filepath
     * @return array
     */
    private function extract_pdf_text_by_page_bundled(string $filepath): array {
        if (!$this->bundled_pdf_parser_available()) {
            throw new \Exception('Bundled PDF parser is not available in this plugin installation.');
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $document = $parser->parseFile($filepath);
            $pages = $document->getPages();
            $result = [];

            foreach ($pages as $index => $page) {
                $text = '';
                if (is_object($page) && method_exists($page, 'getText')) {
                    $text = $this->normalize_text((string)$page->getText());
                }
                $result[$index + 1] = $text;
            }

            if (empty($result)) {
                $fallback = $this->normalize_text((string)$document->getText());
                if ($fallback !== '') {
                    $result[1] = $fallback;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            throw new \Exception('Bundled PDF parser failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get PDF page count using pdfinfo.
     *
     * @param string $filepath
     * @return int
     */
    private function get_pdf_page_count(string $filepath): int {
        if (!function_exists('shell_exec')) {
            return 0;
        }

        $pdfinfo = shell_exec('which pdfinfo 2>/dev/null');
        if (empty($pdfinfo)) {
            return 0;
        }

        $output = shell_exec('pdfinfo ' . escapeshellarg($filepath) . ' 2>/dev/null');
        if (!is_string($output)) {
            return 0;
        }

        if (preg_match('/Pages:\s*(\d+)/', $output, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Determine whether there is any usable page text in an extraction result.
     *
     * @param array $pages
     * @return bool
     */
    private function has_non_empty_page_text(array $pages): bool {
        foreach ($pages as $text) {
            if (trim((string)$text) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the bundled PDF parser is available.
     *
     * @return bool
     */
    private function bundled_pdf_parser_available(): bool {
        if (class_exists('\Smalot\PdfParser\Parser')) {
            return true;
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once($autoload);
        }

        return class_exists('\Smalot\PdfParser\Parser');
    }

    /**
     * Convert extension to MIME type.
     *
     * @param string $ext
     * @return string
     */
    private function mime_from_extension(string $ext): string {
        $ext = strtolower(ltrim($ext, '.'));
        switch ($ext) {
            case 'png':
                return 'image/png';
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'bmp':
                return 'image/bmp';
            case 'webp':
                return 'image/webp';
            default:
                return '';
        }
    }

    /**
     * Convert MIME type to extension.
     *
     * @param string $mimetype
     * @return string
     */
    private function extension_from_mime(string $mimetype): string {
        $mimetype = strtolower(trim($mimetype));
        switch ($mimetype) {
            case 'image/png':
                return 'png';
            case 'image/jpeg':
                return 'jpg';
            case 'image/gif':
                return 'gif';
            case 'image/bmp':
                return 'bmp';
            case 'image/webp':
                return 'webp';
            default:
                return '';
        }
    }

    /**
     * Normalize optional distribution map.
     *
     * @param mixed $distribution
     * @param array $allowed
     * @return array
     */
    private function normalize_distribution($distribution, array $allowed): array {
        if (!is_array($distribution)) {
            return [];
        }

        $allowedmap = array_fill_keys($allowed, true);
        $normalized = [];

        foreach ($distribution as $key => $value) {
            $k = strtolower(trim((string)$key));
            if (!isset($allowedmap[$k])) {
                continue;
            }

            $count = (int)$value;
            if ($count <= 0) {
                continue;
            }

            $normalized[$k] = $count;
        }

        return $normalized;
    }

    /**
     * Build metadata plan summary.
     *
     * @param array $options
     * @return array
     */
    private function build_plan_metadata(array $options): array {
        return [
            'difficultyDistribution' => $this->normalize_distribution(
                $options['difficultyDistribution'] ?? null,
                ['easy', 'medium', 'hard']
            ),
            'bloomDistribution' => $this->normalize_distribution(
                $options['bloomDistribution'] ?? null,
                ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create']
            ),
        ];
    }

    /**
     * Normalize list of strings.
     *
     * @param mixed $value
     * @return array
     */
    private function normalize_string_list($value): array {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }

    /**
     * Check if array is list-like.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_list_array($value): bool {
        if (!is_array($value)) {
            return false;
        }
        return array_values($value) === $value;
    }

    /**
     * Store generated questions in database with trustworthiness analysis.
     *
     * @param array $questions
     * @param int $classengageid
     * @param int $slideid
     * @param string|null $sourcetext Source text for trustworthiness analysis
     * @param array $sourceimages Source images for trustworthiness analysis
     * @return array
     */
    protected function store_questions($questions, $classengageid, $slideid, $sourcetext = null, $sourceimages = []) {
        global $DB;

        $questionids = [];
        $now = time();

        $trustworthiness_analyzer = new question_trustworthiness_analyzer($classengageid);

        foreach ($questions as $q) {
            $question = new \stdClass();
            $question->classengageid = $classengageid;
            $question->slideid = $slideid;
            $question->questiontext = $q['questiontext'];
            $question->questiontype = 'multichoice';
            $question->optiona = $q['optiona'];
            $question->optionb = $q['optionb'];
            $question->optionc = $q['optionc'] ?? '';
            $question->optiond = $q['optiond'] ?? '';
            $question->correctanswer = $q['correctanswer'];
            $question->difficulty = $q['difficulty'] ?? 'medium';
            $question->bloomlevel = $q['bloomLevel'] ?? $q['bloomlevel'] ?? $q['bloom_level']
                ?? $q['cognitiveLevel'] ?? $q['cognitive_level'] ?? null;
            $question->rationale = $q['rationale'] ?? null;
            $question->sources = !empty($q['sources']) ? json_encode($q['sources']) : null;
            $question->question_image = $q['question_image'] ?? null;
            $question->status = 'pending';
            $question->source = 'nlp';
            $question->timecreated = $now;
            $question->timemodified = $now;
            $question->trustworthiness_score = constants::TRUSTWORTHINESS_DEFAULT_SCORE;
            $question->trustworthiness_level = constants::TRUSTWORTHINESS_UNCERTAIN;
            $question->trustworthiness_analyzed = 0;

            $questionid = $DB->insert_record('classengage_questions', $question);
            if ($questionid) {
                $questionids[] = $questionid;

                $question->id = $questionid;

                try {
                    $analysis = $trustworthiness_analyzer->analyze($question, $sourcetext, $sourceimages);
                    \debugging("ClassEngage NLP: Trustworthiness analysis for question {$questionid}: score={$analysis['score']}, level={$analysis['level']}", \DEBUG_DEVELOPER);
                } catch (\Exception $e) {
                    \debugging("ClassEngage NLP: Trustworthiness analysis failed for question {$questionid}: " . $e->getMessage(), \DEBUG_DEVELOPER);
                }
            }
        }

        \debugging("ClassEngage NLP: Stored " . count($questionids) . " questions for classengageid={$classengageid}, slideid={$slideid}", \DEBUG_DEVELOPER);

        return $questionids;
    }
}
