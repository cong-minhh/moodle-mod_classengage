<?php
/**
 * Test script for ClassEngage Clicker Web Services API
 * 
 * This script helps verify that the Web Services API is working correctly.
 * Place this in your Moodle root directory and access via browser.
 * 
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/test_clicker_api.php'));
$PAGE->set_title('ClassEngage Clicker API Test');

echo $OUTPUT->header();
echo $OUTPUT->heading('ClassEngage Clicker API Test');

echo html_writer::div('This script tests the Web Services API integration for classroom clickers.', 'alert alert-info');

// Check if Web Services are enabled
echo html_writer::tag('h3', '1. Web Services Configuration');

$wsenabled = $CFG->enablewebservices ?? false;
if ($wsenabled) {
    echo html_writer::div('✓ Web services are enabled', 'alert alert-success');
} else {
    echo html_writer::div('✗ Web services are NOT enabled. Enable at: Site Administration → Advanced features', 'alert alert-danger');
}

// Check if REST protocol is enabled
$restprotocol = $DB->get_record('external_services_functions', array('functionname' => 'core_webservice_get_site_info'));
if ($restprotocol || $wsenabled) {
    echo html_writer::div('✓ REST protocol appears to be configured', 'alert alert-success');
} else {
    echo html_writer::div('⚠ REST protocol may not be enabled. Check at: Site Administration → Server → Web services → Manage protocols', 'alert alert-warning');
}

// Check if ClassEngage service exists
echo html_writer::tag('h3', '2. ClassEngage Clicker Service');

$service = $DB->get_record('external_services', array('shortname' => 'classengage_clicker'));
if ($service) {
    echo html_writer::div('✓ ClassEngage Clicker Service exists', 'alert alert-success');
    echo html_writer::div('Service ID: ' . $service->id . '<br>Enabled: ' . ($service->enabled ? 'Yes' : 'No'), 'alert alert-info');
    
    if (!$service->enabled) {
        echo html_writer::div('⚠ Service is not enabled. Enable at: Site Administration → Server → Web services → External services', 'alert alert-warning');
    }
} else {
    echo html_writer::div('✗ ClassEngage Clicker Service NOT found. You may need to reinstall the plugin.', 'alert alert-danger');
}

// Check if functions are registered
echo html_writer::tag('h3', '3. Web Service Functions');

$functions = array(
    'mod_classengage_submit_clicker_response' => 'Submit single clicker response',
    'mod_classengage_submit_bulk_responses' => 'Submit bulk responses',
    'mod_classengage_get_active_session' => 'Get active session',
    'mod_classengage_get_current_question' => 'Get current question',
    'mod_classengage_register_clicker' => 'Register clicker device',
);

$table = new html_table();
$table->head = array('Function', 'Description', 'Status');
$table->attributes['class'] = 'generaltable';

foreach ($functions as $funcname => $description) {
    $exists = $DB->record_exists('external_functions', array('name' => $funcname));
    
    $status = $exists 
        ? html_writer::tag('span', '✓ Registered', array('class' => 'badge badge-success'))
        : html_writer::tag('span', '✗ Not Found', array('class' => 'badge badge-danger'));
    
    $table->data[] = array($funcname, $description, $status);
}

echo html_writer::table($table);

// Check database tables
echo html_writer::tag('h3', '4. Database Tables');

$tables = array(
    'classengage_clicker_devices' => 'Clicker device registrations'
);

$tablecheck = new html_table();
$tablecheck->head = array('Table', 'Purpose', 'Status');
$tablecheck->attributes['class'] = 'generaltable';

foreach ($tables as $tablename => $purpose) {
    $exists = $DB->get_manager()->table_exists($tablename);
    
    $status = $exists 
        ? html_writer::tag('span', '✓ Exists', array('class' => 'badge badge-success'))
        : html_writer::tag('span', '✗ Missing', array('class' => 'badge badge-danger'));
    
    $tablecheck->data[] = array($tablename, $purpose, $status);
    
    if ($exists) {
        $count = $DB->count_records($tablename);
        $tablecheck->data[] = array('', "Records: $count", '');
    }
}

echo html_writer::table($tablecheck);

// Check capabilities
echo html_writer::tag('h3', '5. Required Capabilities');

$capabilities = array(
    'mod/classengage:submitclicker' => 'Submit clicker responses (bulk)',
    'mod/classengage:takequiz' => 'Take quiz (individual responses)',
    'mod/classengage:view' => 'View activity',
);

$captable = new html_table();
$captable->head = array('Capability', 'Description', 'Status');
$captable->attributes['class'] = 'generaltable';

foreach ($capabilities as $capability => $description) {
    $exists = $DB->record_exists('capabilities', array('name' => $capability));
    
    $status = $exists 
        ? html_writer::tag('span', '✓ Defined', array('class' => 'badge badge-success'))
        : html_writer::tag('span', '✗ Not Found', array('class' => 'badge badge-danger'));
    
    $captable->data[] = array($capability, $description, $status);
}

echo html_writer::table($captable);

// Instructions
echo html_writer::tag('h3', '6. Next Steps');

echo html_writer::start_tag('ol');
echo html_writer::tag('li', 'If any checks failed above, go to <a href="' . new moodle_url('/admin/index.php') . '">Site Administration → Notifications</a> and upgrade the database.');
echo html_writer::tag('li', 'Enable the ClassEngage Clicker Service at: Site Administration → Server → Web services → External services');
echo html_writer::tag('li', 'Create a dedicated user account for the clicker hub (e.g., "clicker_hub")');
echo html_writer::tag('li', 'Create a role with the required capabilities and assign to the hub user');
echo html_writer::tag('li', 'Generate a web service token for the hub user at: Site Administration → Server → Web services → Manage tokens');
echo html_writer::tag('li', 'See CLICKER_API_DOCUMENTATION.md for complete setup and usage instructions');
echo html_writer::end_tag('ol');

// Sample API call
echo html_writer::tag('h3', '7. Sample API Call');

$baseurl = $CFG->wwwroot;
$samplecode = <<<'EOT'
# Example: Get active session
curl -X POST "{BASEURL}/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN_HERE" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_get_active_session" \
  -d "classengageid=1"

# Example: Submit clicker response
curl -X POST "{BASEURL}/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN_HERE" \
  -d "moodlewsrestformat=json" \
  -d "wsfunction=mod_classengage_submit_clicker_response" \
  -d "sessionid=1" \
  -d "userid=2" \
  -d "clickerid=CLICKER-001" \
  -d "answer=B"
EOT;

$samplecode = str_replace('{BASEURL}', $baseurl, $samplecode);

echo html_writer::tag('pre', htmlspecialchars($samplecode), array('class' => 'bg-light p-3'));

echo html_writer::div(
    '<strong>Documentation:</strong> See <code>CLICKER_API_DOCUMENTATION.md</code> for complete API reference and integration examples.',
    'alert alert-primary mt-4'
);

echo $OUTPUT->footer();

