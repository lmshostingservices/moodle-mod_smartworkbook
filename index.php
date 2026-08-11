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
 * AI Smart Workbook - List all instances in a course.
 *
 * @package    mod_smartworkbook
 * @copyright  2026 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);
$PAGE->set_pagelayout('incourse');

$event = \core\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/smartworkbook/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'smartworkbook'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'smartworkbook'));

$workbooks = get_all_instances_in_course('smartworkbook', $course);

if (!$workbooks) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'smartworkbook')),
           new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('status', 'smartworkbook')];

foreach ($workbooks as $wb) {
    $link = html_writer::link(
        new moodle_url('/mod/smartworkbook/view.php', ['id' => $wb->coursemodule]),
        format_string($wb->name)
    );
    $table->data[] = [$link, $wb->status];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
