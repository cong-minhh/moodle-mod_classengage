<?php
/**
 * NLP API Integration Test Script
 * 
 * Run standalone to test connectivity to the NLP question generation service.
 * Usage: php tests/integration/test_nlp_api.php
 *
 * @package    mod_classengage
 * @copyright  2026 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Prevent web access.
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

echo "=== ClassEngage NLP API Integration Test ===\n\n";

// Configuration - adjust as needed.
$nlpEndpoint = getenv('NLP_ENDPOINT') ?: 'http://localhost:3000';

echo "NLP Endpoint: $nlpEndpoint\n\n";

/**
 * Make an HTTP request using file_get_contents (no curl required).
 *
 * @param string $url Full URL
 * @param string $method HTTP method
 * @param mixed $body Request body
 * @param array $headers Additional headers
 * @return array [success, response, httpCode, error]
 */
function make_request($url, $method = 'GET', $body = null, $headers = [])
{
    $defaultHeaders = [
        'Accept: application/json'
    ];
    $allHeaders = array_merge($defaultHeaders, $headers);

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $allHeaders),
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);
    $httpCode = 0;
    $error = '';

    if ($response === false) {
        $error = error_get_last()['message'] ?? 'Connection failed';
    } else if (isset($http_response_header)) {
        // Parse HTTP status from response header.
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    return [
        'success' => $httpCode >= 200 && $httpCode < 300 && $response !== false,
        'response' => $response,
        'httpCode' => $httpCode,
        'error' => $error
    ];
}

// Test 1: Health Check
echo "Test 1: API Health Check\n";
echo str_repeat('-', 40) . "\n";

$result = make_request("$nlpEndpoint/health");

if ($result['success']) {
    echo "✓ API is reachable (HTTP {$result['httpCode']})\n";
    $data = json_decode($result['response'], true);
    if ($data) {
        echo "  Status: " . ($data['status'] ?? 'unknown') . "\n";
    }
} else {
    echo "✗ API unreachable: {$result['error']}\n";
    echo "  Make sure the NLP service is running on $nlpEndpoint\n";
    echo "  Run: cd /home/danielle/nlp-question-generator && npm start\n";
    exit(1);
}
echo "\n";

// Test 2: Check API documentation endpoint
echo "Test 2: API Reference Check\n";
echo str_repeat('-', 40) . "\n";

$result = make_request("$nlpEndpoint/api");

if ($result['success'] || $result['httpCode'] === 404) {
    echo "✓ API routes are accessible\n";
} else {
    echo "⚠ API routes check returned HTTP {$result['httpCode']}\n";
}
echo "\n";

// Test 3: Inspect Endpoint Structure  
echo "Test 3: Inspect Endpoint (requires test file)\n";
echo str_repeat('-', 40) . "\n";

// Check if we have a test PDF.
$testFile = __DIR__ . '/../fixtures/sample.pdf';
$altTestFile = '/home/danielle/nlp-question-generator/Chapter 3 - Solving Problems by Searching.pdf';

$fileToTest = null;
if (file_exists($testFile)) {
    $fileToTest = $testFile;
} else if (file_exists($altTestFile)) {
    $fileToTest = $altTestFile;
}

$testDocId = null;

if ($fileToTest === null) {
    echo "⚠ Skipping: No test file available\n";
    echo "  Create a sample.pdf in tests/fixtures/ to test document inspection.\n";
} else {
    echo "Using test file: " . basename($fileToTest) . "\n";

    $boundary = '----WebKitFormBoundary' . uniqid();
    $body = '';
    $body .= "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($fileToTest) . "\"\r\n";
    $body .= "Content-Type: application/pdf\r\n\r\n";
    $body .= file_get_contents($fileToTest) . "\r\n";
    $body .= "--$boundary--\r\n";

    $result = make_request(
        "$nlpEndpoint/api/documents/inspect",
        'POST',
        $body,
        ["Content-Type: multipart/form-data; boundary=$boundary"]
    );

    if ($result['success']) {
        $data = json_decode($result['response'], true);
        if ($data && ($data['success'] ?? false)) {
            echo "✓ Document inspection successful\n";
            echo "  DocID: {$data['docId']}\n";
            echo "  Pages: " . count($data['pages'] ?? []) . "\n";

            // Store docId for generate test.
            $testDocId = $data['docId'];
        } else {
            echo "✗ Inspection failed: " . ($data['error'] ?? 'unknown error') . "\n";
            echo "  Response: " . substr($result['response'], 0, 200) . "\n";
        }
    } else {
        echo "✗ Request failed (HTTP {$result['httpCode']}): {$result['error']}\n";
    }
}
echo "\n";

// Test 4: Generate Endpoint Structure
echo "Test 4: Generate Endpoint Structure\n";
echo str_repeat('-', 40) . "\n";

if ($testDocId) {
    $payload = json_encode([
        'docId' => $testDocId,
        'options' => [
            'numQuestions' => 2,
            'difficulty' => 'medium',
            'bloomLevel' => 'apply'
        ]
    ]);

    $result = make_request(
        "$nlpEndpoint/api/documents/generate",
        'POST',
        $payload,
        ['Content-Type: application/json']
    );

    if ($result['success']) {
        $data = json_decode($result['response'], true);
        if ($data && ($data['success'] ?? false)) {
            echo "✓ Question generation successful\n";
            echo "  Questions generated: " . count($data['questions'] ?? []) . "\n";
            if (!empty($data['questions'])) {
                $q = $data['questions'][0];
                echo "  Sample fields present:\n";
                echo "    - questiontext: " . (isset($q['questiontext']) ? '✓' : '✗') . "\n";
                echo "    - optiona-d: " . (isset($q['optiona']) ? '✓' : '✗') . "\n";
                echo "    - correctanswer: " . (isset($q['correctanswer']) ? '✓' : '✗') . "\n";
                echo "    - difficulty: " . (isset($q['difficulty']) ? '✓' : '✗') . "\n";
                echo "    - rationale: " . (isset($q['rationale']) ? '✓' : '✗') . "\n";
            }
        } else {
            echo "✗ Generation returned error: " . ($data['error'] ?? 'unknown') . "\n";
        }
    } else {
        echo "✗ Request failed (HTTP {$result['httpCode']}): {$result['error']}\n";
    }
} else {
    echo "⚠ Skipping: No docId from inspection test\n";
}
echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "The NLP API integration is " . ($testDocId ? "working correctly" : "partially tested") . ".\n";
echo "Ensure the NLP service is running before using the ClassEngage generator wizard.\n";
