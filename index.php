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
 * List of all classengages in course
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/classengage/lib.php');

$id = required_param('id', PARAM_INT); // Course id

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

require_course_login($course, true);
$PAGE->set_pagelayout('incourse');

// Trigger instances list viewed event.
$params = array('context' => context_course::instance($course->id));
$event = \mod_classengage\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

// Get all required strings
$strclassengages = get_string('modulenameplural', 'mod_classengage');
$strclassengage = get_string('modulename', 'mod_classengage');
$strname = get_string('name');
$strintro = get_string('moduleintro', 'mod_classengage');
$strlastmodified = get_string('lastmodified');

$PAGE->set_url('/mod/classengage/index.php', array('id' => $course->id));
$PAGE->set_title($course->shortname.': '.$strclassengages);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strclassengages);

echo $OUTPUT->header();
echo $OUTPUT->heading($strclassengages);

// Get all the appropriate data
if (!$classengages = get_all_instances_in_course('classengage', $course)) {
    notice(get_string('thereareno', 'moodle', $strclassengages), "$CFG->wwwroot/course/view.php?id=$course->id");
    exit;
}

// Print the list of instances
$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';

$table->head = array($strname, $strintro);
$table->align = array('left', 'left');

foreach ($classengages as $classengage) {
    $tt = '';
    if (!$classengage->visible) {
        $tt = 'class="dimmed"';
    }

    $nameurl = new moodle_url('/mod/classengage/view.php', array('id' => $classengage->coursemodule));
    $namelink = html_writer::link($nameurl, format_string($classengage->name), array('class' => $tt));

    $intro = format_module_intro('classengage', $classengage, $classengage->coursemodule);

    $table->data[] = array($namelink, $intro);
}

echo html_writer::table($table);

echo $OUTPUT->footer();

