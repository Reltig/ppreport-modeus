<?php

/**
 * Renderer for the grade ppreport report
 *
 * @package   gradereport_ppreport
 */

use core\output\comboboxsearch;

class gradereport_ppreport_renderer extends plugin_renderer_base {
    /**
     * Renders the user selector trigger element.
     *
     * @param object $course The course object.
     * @param int|null $userid The user ID.
     * @param int|null $groupid The group ID.
     * @return string The raw HTML to render.
     * @throws coding_exception
     */
    public function users_selector(object $course, ?int $userid = null, ?int $groupid = null): string {
        $resetlink = new moodle_url('/grade/report/ppreport/index.php', ['id' => $course->id]);
        $submitteduserid = optional_param('userid', '', PARAM_INT);

        if ($submitteduserid) {
            $user = core_user::get_user($submitteduserid);
            $currentvalue = fullname($user);
        } else {
            $currentvalue = '';
        }

        $data = [
            'currentvalue' => $currentvalue,
            'instance' => rand(),
            'resetlink' => $resetlink->out(false),
            'name' => 'userid',
            'value' => $submitteduserid ?? '',
            'courseid' => $course->id,
            'group' => $groupid ?? 0,
        ];

        $searchdropdown = new comboboxsearch(
            true,
            $this->render_from_template('core_user/comboboxsearch/user_selector', $data),
            null,
            'user-search d-flex',
            null,
            'usersearchdropdown overflow-auto',
            null,
            false,
        );
        $this->page->requires->js_call_amd('gradereport_ppreport/user', 'init');
        return $this->render_from_template($searchdropdown->get_template(), $searchdropdown->export_for_template($this));
    }

    /**
     * Renders the group selector dropdown.
     *
     * @param object $course The course object.
     * @return string The raw HTML to render.
     */
    public function group_selector(object $course): string {
        $groups = $this->get_student_groups(); // Fetch groups from lib.php
        $options = '';

        foreach ($groups as $group) {
            $options .= html_writer::tag('option', $group->name, ['value' => $group->id]);
        }

        $dropdown = html_writer::tag('select', $options, ['name' => 'groupid', 'id' => 'groupid']);
        return html_writer::tag('label', 'Выбор группы пользователя') . $dropdown;
    }
}
