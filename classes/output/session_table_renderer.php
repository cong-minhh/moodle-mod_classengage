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
 * Session table renderer for ClassEngage plugin
 *
 * Extracted from sessions.php for improved code organization and reusability.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Session table renderer class
 *
 * Renders session tables for the sessions management page.
 */
class session_table_renderer {
    /** @var \stdClass Course module object */
    private $cm;

    /**
     * Constructor
     *
     * @param \stdClass $cm Course module
     */
    public function __construct(\stdClass $cm) {
        $this->cm = $cm;
    }

    /**
     * Render a session table
     *
     * @param array $sessions Array of session records
     * @param string $type Table type: 'active', 'ready', or 'completed'
     * @return string HTML output
     */
    public function render(array $sessions, string $type): string {
        global $OUTPUT, $DB;

        if (empty($sessions)) {
            return \html_writer::div(
                get_string('no' . $type . 'sessions', 'mod_classengage'),
                'alert alert-info mt-3'
            );
        }

        $formurl = new \moodle_url('/mod/classengage/sessions.php', ['id' => $this->cm->id]);
        $o = \html_writer::start_tag('form', ['action' => $formurl, 'method' => 'post']);
        $o .= \html_writer::input_hidden_params($formurl);
        $o .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table table-hover';

        $table->head = $this->get_table_header($type);

        foreach ($sessions as $session) {
            $table->data[] = $this->get_session_row($session, $type);
        }

        $o .= \html_writer::table($table);
        $o .= $this->render_bulk_actions($type);
        $o .= \html_writer::end_tag('form');

        return $o;
    }

    /**
     * Get table header based on type
     *
     * @param string $type Table type
     * @return array Header cells
     */
    private function get_table_header(string $type): array {
        $head = [
            \html_writer::checkbox('selectall', 1, false, '', [
                'class' => 'select-all-toggle',
                'data-target' => 'session-checkbox-' . $type,
            ]),
            get_string('sessionname', 'mod_classengage'),
            get_string('numberofquestions', 'mod_classengage'),
        ];

        if ($type === 'active' || $type === 'completed') {
            $head[] = get_string('participants', 'mod_classengage');
        }

        if ($type === 'completed') {
            $head[] = get_string('completeddate', 'mod_classengage');
        }

        $head[] = get_string('actions', 'mod_classengage');

        return $head;
    }

    /**
     * Get a session row based on type
     *
     * @param \stdClass $session Session record
     * @param string $type Table type
     * @return array Row cells
     */
    private function get_session_row(\stdClass $session, string $type): array {
        global $OUTPUT, $DB;

        $checkbox = \html_writer::checkbox(
            'sessionids[]',
            $session->id,
            false,
            '',
            ['class' => 'session-checkbox-' . $type]
        );

        $row = [
            $checkbox,
            format_string($session->name),
            $session->numquestions,
        ];

        if ($type === 'active' || $type === 'completed') {
            $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
            $participantcount = $DB->count_records_sql($sql, [$session->id]);
            $row[] = $participantcount;
        }

        if ($type === 'completed') {
            $row[] = userdate($session->timecompleted);
        }

        $row[] = $this->render_actions($session, $type);

        return $row;
    }

    /**
     * Render action buttons for a session
     *
     * @param \stdClass $session Session record
     * @param string $type Table type
     * @return string HTML output
     */
    private function render_actions(\stdClass $session, string $type): string {
        global $OUTPUT;

        $actions = [];

        switch ($type) {
            case 'active':
                $controlurl = new \moodle_url(
                    '/mod/classengage/controlpanel.php',
                    ['id' => $this->cm->id, 'sessionid' => $session->id]
                );
                $actions[] = \html_writer::link(
                    $controlurl,
                    get_string('controlpanel', 'mod_classengage'),
                    ['class' => 'btn btn-sm btn-primary mr-1']
                );

                $stopurl = new \moodle_url(
                    '/mod/classengage/sessions.php',
                    [
                        'id' => $this->cm->id,
                        'action' => 'stop',
                        'sessionid' => $session->id,
                        'sesskey' => sesskey(),
                    ]
                );
                $actions[] = \html_writer::link(
                    $stopurl,
                    get_string('stopsession', 'mod_classengage'),
                    ['class' => 'btn btn-sm btn-warning mr-1']
                );
                break;

            case 'ready':
                $starturl = new \moodle_url(
                    '/mod/classengage/sessions.php',
                    [
                        'id' => $this->cm->id,
                        'action' => 'start',
                        'sessionid' => $session->id,
                        'sesskey' => sesskey(),
                    ]
                );
                $actions[] = \html_writer::link(
                    $starturl,
                    get_string('startsession', 'mod_classengage'),
                    ['class' => 'btn btn-sm btn-success mr-1']
                );
                break;

            case 'completed':
                $viewurl = new \moodle_url(
                    '/mod/classengage/sessionresults.php',
                    ['id' => $this->cm->id, 'sessionid' => $session->id]
                );
                $actions[] = \html_writer::link(
                    $viewurl,
                    get_string('viewresults', 'mod_classengage'),
                    ['class' => 'btn btn-sm btn-info mr-1']
                );
                break;
        }

        // Delete button for all types.
        $deleteurl = new \moodle_url(
            '/mod/classengage/sessions.php',
            [
                'id' => $this->cm->id,
                'action' => 'delete',
                'sessionid' => $session->id,
                'sesskey' => sesskey(),
            ]
        );
        $actions[] = \html_writer::link(
            $deleteurl,
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
            ['class' => 'btn btn-sm btn-link text-danger', 'title' => get_string('delete')]
        );

        return implode(' ', $actions);
    }

    /**
     * Render bulk action controls
     *
     * @param string $type Table type
     * @return string HTML output
     */
    private function render_bulk_actions(string $type): string {
        $o = \html_writer::start_div('d-flex align-items-center mt-2 mb-4');
        $o .= \html_writer::tag(
            'span',
            get_string('withselected', 'mod_classengage') . ': ',
            ['class' => 'mr-2']
        );

        $bulkoptions = ['delete' => get_string('delete')];
        if ($type === 'active') {
            $bulkoptions['stop'] = get_string('stop', 'mod_classengage');
        }

        $o .= \html_writer::select(
            $bulkoptions,
            'bulkaction',
            '',
            ['' => get_string('choose', 'moodle')],
            ['class' => 'custom-select w-auto mr-2']
        );

        $o .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('go'),
            'class' => 'btn btn-secondary',
        ]);

        $o .= \html_writer::end_div();

        return $o;
    }
}
