<?php
require_once '../../../config.php';
require_once $CFG->libdir.'/gradelib.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/ppreport/lib.php';
require_once $CFG->dirroot.'/grade/report/ppreport/classes/output/action_bar.php';

$courseid = optional_param('id', SITEID, PARAM_INT);
$userid   = optional_param('userid', null, PARAM_INT);
$quizid   = optional_param('quizid', null, PARAM_INT);
$groupid  = optional_param('groupid', 0, PARAM_INT); // New parameter for group selection

$PAGE->set_url(new moodle_url('/grade/report/ppreport/index.php', array('id' => $courseid, 'userid' => $userid, 'groupid' => $groupid)));

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    throw new \moodle_exception('invalidcourseid');
}
require_login(null, false);
$PAGE->set_course($course);

$context = context_course::instance($course->id);
$systemcontext = context_system::instance();
$personalcontext = null;

if ($courseid != SITEID) {
    require_capability('gradereport/ppreport:view', $context);
}

if (!empty($userid)) {
    if (!$DB->get_record('user', array('id'=>$userid, 'deleted'=>0)) or isguestuser($userid)) {
        throw new \moodle_exception('invaliduserid');
    }
    $personalcontext = context_user::instance($userid);
}

if (isset($personalcontext) && $courseid == SITEID) {
    $PAGE->set_context($personalcontext);
} else {
    $PAGE->set_context($context);
}
if ($userid == $USER->id) {
    $settings = $PAGE->settingsnav->find('mygrades', null);
    $settings->make_active();
} else if ($courseid != SITEID && $userid) {
    $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
    $PAGE->navigation->extend_for_user($user);
}

$access = true; // TODO: grade_report_overview::check_access($systemcontext, $context, $personalcontext, $course, $userid);

if (!$access) {
    throw new \moodle_exception('nopermissiontoviewgrades', 'error',  $CFG->wwwroot.'/course/view.php?id='.$courseid);
}

$gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'overview', 'courseid'=>$course->id, 'userid'=>$userid));

if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
$USER->grade_last_report[$course->id] = 'ppreport';

$actionbar = new \ppreport\output\action_bar($context, 1);
print_grade_page_head($courseid, 'report', 'ppreport', false, false, false, true, null, null, null, $actionbar);

// Fetch groups for the dropdown
$groups = groups_get_all_groups($courseid);

// Fetch quizzes for the dropdown
$quizzes = $DB->get_records('quiz', ['course' => $courseid], 'name', 'id, name');

// Create the form with both dropdowns
echo '<form method="get" action="index.php" class="mb-4">';
echo '<input type="hidden" name="id" value="'.$courseid.'">';
echo '<div class="container">';
echo '<div class="row">';

// Group dropdown
echo '<div class="col-md-6">';
echo '<div class="form-group">';
echo '<label for="groupid">Группа</label>';
echo '<select name="groupid" id="groupid" class="form-control" onchange="this.form.submit()">';
echo '<option value="0">Все группы</option>';
foreach ($groups as $group) {
    $selected = ($group->id == $groupid) ? 'selected' : '';
    echo '<option value="'.$group->id.'" '.$selected.'>'.$group->name.'</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

// Quiz dropdown
echo '<div class="col-md-6">';
echo '<div class="form-group">';
echo '<label for="quizid">Тест</label>';
echo '<select name="quizid" id="quizid" class="form-control" onchange="this.form.submit()">';
echo '<option value="0">Все тесты</option>';
foreach ($quizzes as $quiz) {
    $selected = ($quiz->id == $quizid) ? 'selected' : '';
    echo '<option value="'.$quiz->id.'" '.$selected.'>'.$quiz->name.'</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

echo '</div>'; // row
echo '</div>'; // container
echo '</form>';

$report = new grade_report_ppreport($userid, $gpr, $context);

if ($quizid) {
    $report->print_quiz_page($quizid, $groupid);
} else if ($userid) {
    $report->print_user_page($userid);
} else {
    echo $report->print_quiz_list($groupid);
}
echo $OUTPUT->footer();