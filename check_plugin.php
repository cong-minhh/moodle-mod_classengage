<?php
/**
 * Quick plugin check script
 * Place this in your Moodle root directory and access via browser
 */

require_once('config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

echo "<h1>ClassEngage Plugin Check</h1>";

// Check if version.php exists and is readable
$versionfile = $CFG->dirroot . '/mod/classengage/version.php';
echo "<h2>1. Version File Check</h2>";
if (file_exists($versionfile)) {
    echo "✓ version.php exists<br>";
    include($versionfile);
    echo "✓ Component: " . $plugin->component . "<br>";
    echo "✓ Version: " . $plugin->version . "<br>";
} else {
    echo "✗ version.php NOT found!<br>";
}

// Check if install.xml exists
$installxml = $CFG->dirroot . '/mod/classengage/db/install.xml';
echo "<h2>2. Install XML Check</h2>";
if (file_exists($installxml)) {
    echo "✓ install.xml exists<br>";
    echo "✓ File size: " . filesize($installxml) . " bytes<br>";
} else {
    echo "✗ install.xml NOT found!<br>";
}

// Check database tables
echo "<h2>3. Database Tables Check</h2>";
$tables = [
    'classengage',
    'classengage_slides',
    'classengage_questions',
    'classengage_sessions',
    'classengage_session_questions',
    'classengage_responses'
];

foreach ($tables as $table) {
    if ($DB->get_manager()->table_exists($table)) {
        echo "✓ Table '$table' EXISTS<br>";
    } else {
        echo "✗ Table '$table' DOES NOT EXIST<br>";
    }
}

// Check if plugin is installed in Moodle's config
echo "<h2>4. Moodle Plugin Registry Check</h2>";
$version = get_config('mod_classengage', 'version');
if ($version) {
    echo "✓ Plugin registered with version: $version<br>";
} else {
    echo "✗ Plugin NOT registered in Moodle config<br>";
    echo "<strong>ACTION NEEDED: Go to http://localhost/admin/index.php to install</strong><br>";
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>If tables don't exist: Go to <a href='http://localhost/admin/index.php'>Admin Notifications</a></li>";
echo "<li>Click 'Upgrade Moodle database now'</li>";
echo "<li>After upgrade, refresh this page to verify</li>";
echo "</ol>";

