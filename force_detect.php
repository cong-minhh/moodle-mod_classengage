<?php
/**
 * Force Moodle to detect the plugin as new
 * Run this ONCE, then go to admin/index.php
 */

require_once('config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Remove the plugin from config (if exists)
unset_config('version', 'mod_classengage');

// Also try to unregister from modules table
$DB->delete_records('modules', array('name' => 'classengage'));

echo "<h1>Plugin Detection Reset</h1>";
echo "<p>✓ Plugin has been unregistered from Moodle</p>";
echo "<p><strong>Now go to:</strong></p>";
echo "<p><a href='http://localhost/admin/index.php' style='font-size:20px; color:blue;'>http://localhost/admin/index.php</a></p>";
echo "<p>Moodle should now detect it as a NEW plugin and create all tables.</p>";
echo "<hr>";
echo "<p style='color:red;'><strong>IMPORTANT:</strong> Delete this file after use for security!</p>";

