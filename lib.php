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
 * Definition of the grade_ppreport_report class
 *
 * @package gradereport_ppreport
 * @copyright 2007 Nicolas Connault
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir.'/tablelib.php');

/**
 * Class providing an API for the ppreport report building and displaying.
 * @uses grade_report
 * @package gradereport_ppreport
 */
class grade_report_ppreport extends grade_report {

    /**
     * The user's courses
     * @var array $courses
     */
    public $courses;

    /**
     * A flexitable to hold the data.
     * @var object $table
     */
    public $table;

    /**
     * Show student ranks within each course.
     * @var array $showrank
     */
    public $showrank;

    /**
     * An array of course ids that the user is a student in.
     * @var array $studentcourseids
     */
    public $studentcourseids;

    /**
     * An array of courses that the user is a teacher in.
     * @var array $teachercourses
     */
    public $teachercourses;

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $userid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     */
    public function __construct($userid, $gpr, $context) {
        global $CFG, $COURSE, $DB, $USER;
        parent::__construct($COURSE->id, $gpr, $context);

        // Get the user (for full name).
        $this->user = $DB->get_record('user', array('id' => $userid));

        // Set onlyactive flag to true if the user's viewing his/her report.
        $onlyactive = ($this->user->id === $USER->id);

        // Load the user's courses.
        $this->courses = enrol_get_users_courses($this->user->id, $onlyactive, 'id, shortname, showgrades');

        $this->showrank = array();
        $this->showrank['any'] = false;

        $this->showtotalsifcontainhidden = array();

        $this->studentcourseids = array();
        $this->teachercourses = array();
        $roleids = explode(',', get_config('moodle', 'gradebookroles'));

        if ($this->courses) {
            foreach ($this->courses as $course) {
                $this->showrank[$course->id] = grade_get_setting($course->id, 'report_ppreport_showrank', !empty($CFG->grade_report_ppreport_showrank));
                if ($this->showrank[$course->id]) {
                    $this->showrank['any'] = true;
                }

                $this->showtotalsifcontainhidden[$course->id] = grade_get_setting($course->id, 'report_ppreport_showtotalsifcontainhidden', $CFG->grade_report_ppreport_showtotalsifcontainhidden);

                $coursecontext = context_course::instance($course->id);

                foreach ($roleids as $roleid) {
                    if (user_has_role_assignment($userid, $roleid, $coursecontext->id)) {
                        $this->studentcourseids[$course->id] = $course->id;
                        // We only need to check if one of the roleids has been assigned.
                        break;
                    }
                }

                if (has_capability('moodle/grade:viewall', $coursecontext, $userid)) {
                    $this->teachercourses[$course->id] = $course;
                }
            }
        }


        // base url for sorting by first/last name
        $this->baseurl = $CFG->wwwroot.'/grade/ppreport/index.php?id='.$userid;
        $this->pbarurl = $this->baseurl;

        $this->setup_table();
    }

    /**
     * Regrades all courses if needed.
     *
     * If $frontend is true, this may show a progress bar and redirect back to the page (possibly
     * several times if multiple courses need it). Otherwise, it will not return until all the
     * courses have been updated.
     *
     * @param bool $frontend True if we are running front-end code and can safely redirect back
     */
    public function regrade_all_courses_if_needed(bool $frontend = false): void {
        foreach ($this->courses as $course) {
            if ($frontend) {
                grade_regrade_final_grades_if_required($course);
            } else {
                grade_regrade_final_grades($course->id);
            }
        }
    }

    /**
     * Prepares the headers and attributes of the flexitable.
     */
    public function setup_table() {
        /*
         * Table has 3 columns
         *| user  | timestart | timefinish | timediff
         */

        $this->table = new flexible_table('grade-report-ppreport-'.$this->user->id);

        $tableheaders = array(
            get_string('user'),
            get_string('timestart'),
            get_string('timefinish'),
            get_string('timediff')
        );

        $tablecolumns = array('username', 'timestart', 'timefinish', 'timediff');

        $this->table->define_columns($tablecolumns);
        $this->table->define_headers($tableheaders);
        $this->table->define_baseurl($this->baseurl);

        $this->table->set_attribute('cellspacing', '0');
        $this->table->set_attribute('id', 'ppreport-grade');
        $this->table->set_attribute('class', 'boxaligncenter generaltable');

        $this->table->setup();
    }

    public function print_quiz_list() {
        global $DB, $COURSE;
        $sql = "SELECT id, name FROM {quiz} q
                WHERE course = ?";

        $quizes = $DB->get_records_sql($sql, array($COURSE->id));
        $html = '<ul>';
        foreach($quizes as $quiz) {
            $html .= '<li>' . html_writer::link(new moodle_url('/grade/report/ppreport/index.php', [
                'id' => $COURSE->id, 
                'quizid' => $quiz->id
            ]), $quiz->name)
            . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Set up the courses grades data for the report.
     *
     * @param bool $studentcoursesonly Only show courses that the user is a student of.
     * @return array of course grades information
     */
    public function setup_courses_data($quizid, $studentcoursesonly) {
        global $USER, $DB;

        $sql = "SELECT timestart, timefinish, u.firstname, u.lastname, u.id as userid FROM {quiz_attempts} qa
                LEFT JOIN {user} u ON qa.userid = u.id
                WHERE state = 'finished' AND quiz = ?";

        return $DB->get_records_sql($sql, array($quizid));
    }

    /**
     * Fill the table for displaying.
     *
     * @param bool $activitylink If this report link to the activity report or the user report.
     * @param bool $studentcoursesonly Only show courses that the user is a student of.
     */
    public function fill_table($quizid, $activitylink = false, $studentcoursesonly = false) {
        global $CFG, $DB, $OUTPUT, $USER;

        $quiz_times = $this->setup_courses_data($quizid, $studentcoursesonly);

        foreach ($quiz_times as $quiz_time) {
            $date_format = 'Y-m-d\TH:i:s\Z';
            $data = [
                $quiz_time->firstname . ' ' . $quiz_time->lastname, 
                gmdate($date_format, $quiz_time->timestart), 
                gmdate($date_format, $quiz_time->timefinish),
                format_period($quiz_time->timefinish - $quiz_time->timestart)
            ];
            
            $this->table->add_data($data);
        }

        return true;
    }

    public function print_avg_data($quizid) {
        global $DB;
        $sql = "SELECT avg(timefinish - timestart) as avg_diff FROM {quiz_attempts} qa
        WHERE state = 'finished' AND quiz = ?";

        $r = $DB->get_records_sql($sql, array($quizid));
        echo "<div><b>Среднее значение: ". array_values($r)[0]->avg_diff . "</b></div>";   
    }

    /**
     * Prints or returns the HTML from the flexitable.
     * @param bool $return Whether or not to return the data instead of printing it directly.
     * @return string
     */
    public function print_table($quizid, $return=false) {
        $this->fill_table($quizid);
        ob_start();
        $this->table->print_html();
        $html = ob_get_clean();
        if ($return) {
            return $html;
        } else {
            echo $html;
        }
    }

    /**
     * Print a table to show courses that the user is able to grade.
     */
    public function print_teacher_table() {
        $table = new html_table();
        $table->head = array(get_string('coursename', 'grades'));
        $table->data = null;
        foreach ($this->teachercourses as $courseid => $course) {
            $coursecontext = context_course::instance($course->id);
            $coursenamelink = format_string($course->fullname, true, ['context' => $coursecontext]);
            $url = new moodle_url('/grade/report/index.php', array('id' => $courseid));
            $table->data[] = array(html_writer::link($url, $coursenamelink));
        }
        echo html_writer::table($table);
    }

    /**
     * Processes the data sent by the form (grades and feedbacks).
     * @param array $data
     * @return bool Success or Failure (array of errors).
     */
    function process_data($data) {
    }
    function process_action($target, $action) {
    }

    /**
     * This report supports being set as the 'grades' report.
     */
    public static function supports_mygrades() {
        return true;
    }

    public function print_user_page($userid) {
        global $DB, $OUTPUT, $COURSE;
        $sql = "SELECT firstname, lastname, email FROM {user} u WHERE id = ?";
        $result = $DB->get_record_sql($sql, array($userid));

        
        $result->userQuizGradeChart = $this->create_user_quiz_grades_chart($userid);
        $result->solveTimeChart = $this->create_solve_time_chart($userid);

        echo $OUTPUT->render_from_template("gradereport_ppreport/user", $result);
    }

    private function create_user_quiz_grades_chart($userid) {
        global $DB, $COURSE, $OUTPUT;

        $sql = "SELECT q.id, qg.grade, q.name FROM {quiz_grades} qg
                LEFT JOIN {quiz} q ON qg.quiz = q.id AND q.course = ?
                WHERE qg.userid = ?
                ORDER BY q.timecreated ASC";
        $userQuizData = $DB->get_records_sql($sql, array($COURSE->id, $userid));

        //TODO: extract to function
        $sql = "SELECT q.id, q.name FROM {quiz} q
                WHERE q.course = ?
                ORDER BY q.timecreated ASC";
        $allQuizData = $DB->get_records_sql($sql, array($COURSE->id,));
        
        $userQuizIds = array_map(fn ($a) => $a->id, array_values($userQuizData));
        $quizLabels = array_map(fn ($a) => $a->name, array_values($allQuizData));
        $userGrades = [];
        foreach ($allQuizData as $quizData) {
            if (!in_array($quizData->id, $userQuizIds)){
                $userGrades[]=0;
                continue;
            }
            $userGrades[] = $this->array_search_func($userQuizData, fn ($d) => $d->id == $quizData->id)->grade;
        }
        // echo json_encode($userGrades, JSON_PRETTY_PRINT);
        $chart = new \core\chart_line();
        $chart->add_series(new core\chart_series('User quiz grades', array_merge($userGrades)));
        $chart->set_labels($quizLabels);

        return $OUTPUT->render($chart);
    }

    private function create_solve_time_chart($userid) {
        global $DB, $COURSE, $OUTPUT;

        $sql = "SELECT q.id, qa.quiz, qa.timefinish - qa.timestart as timediff FROM {quiz_attempts} qa
                INNER JOIN {quiz_grades} qg ON qg.quiz = qa.quiz AND qg.userid = qa.userid AND qg.timemodified = qa.timefinish
                INNER JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course = ? AND qg.userid = ? 
                ORDER BY q.timecreated ASC";
        $userQuizTimeData = $DB->get_records_sql($sql, array($COURSE->id, $userid));

        //TODO: extract to function
        $sql = "SELECT q.id, q.name FROM {quiz} q
                WHERE q.course = ?
                ORDER BY q.timecreated ASC";
        $allQuizData = $DB->get_records_sql($sql, array($COURSE->id,));
        $quizLabels = array_map(fn ($a) => $a->name, array_values($allQuizData));
        $userQuizIds = array_map(fn ($a) => $a->id, array_values($userQuizTimeData));

        $userTimes = [];
        foreach ($allQuizData as $quizData) {
            if (!in_array($quizData->id, $userQuizIds)){
                $userTimes[]=0;
                continue;
            }
            $userTimes[] = $this->array_search_func($userQuizTimeData, fn ($d) => $d->id == $quizData->id)->timediff;
        }

        $chart = new \core\chart_line();
        $chart->add_series(new core\chart_series('User quiz solve time', array_merge($userTimes)));
        $chart->set_labels($quizLabels);

        return $OUTPUT->render($chart);
    }

    private function array_search_func(array $arr, $func)
    {
        foreach ($arr as $key => $v)
            if ($func($v))
                return $v;

        return false;
    }

    /**
     * Check if the user can access the report.
     *
     * @param  stdClass $systemcontext   system context
     * @param  stdClass $context         course context
     * @param  stdClass $personalcontext personal context
     * @param  stdClass $course          course object
     * @param  int $userid               userid
     * @return bool true if the user can access the report
     * @since  Moodle 3.2
     */
    public static function check_access($systemcontext, $context, $personalcontext, $course, $userid) {
        global $USER;

        $access = false;
        if (has_capability('moodle/grade:viewall', $systemcontext)) {
            // Ok - can view all course grades.
            $access = true;

        } else if (has_capability('moodle/grade:viewall', $context)) {
            // Ok - can view any grades in context.
            $access = true;

        } else if ($userid == $USER->id and ((has_capability('moodle/grade:view', $context) and $course->showgrades)
                || $course->id == SITEID)) {
            // Ok - can view own course grades.
            $access = true;

        } else if (has_capability('moodle/grade:viewall', $personalcontext) and $course->showgrades) {
            // Ok - can view grades of this user - parent most probably.
            $access = true;
        } else if (has_capability('moodle/user:viewuseractivitiesreport', $personalcontext) and $course->showgrades) {
            // Ok - can view grades of this user - parent most probably.
            $access = true;
        }
        return $access;
    }

    /**
     * Trigger the grade_report_viewed event
     *
     * @param  stdClass $context  course context
     * @param  int $courseid      course id
     * @param  int $userid        user id
     * @since Moodle 3.2
     */
    public static function viewed($context, $courseid, $userid) {
        $event = \gradereport_overview\event\grade_report_viewed::create(
            array(
                'context' => $context,
                'courseid' => $courseid,
                'relateduserid' => $userid,
            )
        );
        $event->trigger();
    }
}

function grade_report_ppreport_settings_definition(&$mform) {
    global $CFG;

    //show rank
    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('hide'),
                      1 => get_string('show'));

    if (empty($CFG->grade_report_ppreport_showrank)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $mform->addElement('select', 'report_ppreport_showrank', get_string('showrank', 'grades'), $options);
    $mform->addHelpButton('report_ppreport_showrank', 'showrank', 'grades');

    //showtotalsifcontainhidden
    $options = array(-1 => get_string('default', 'grades'),
                      GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN => get_string('hide'),
                      GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN => get_string('hidetotalshowexhiddenitems', 'grades'),
                      GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN => get_string('hidetotalshowinchiddenitems', 'grades') );

    if (!array_key_exists($CFG->grade_report_ppreport_showtotalsifcontainhidden, $options)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[$CFG->grade_report_ppreport_showtotalsifcontainhidden]);
    }

    $mform->addElement('select', 'report_ppreport_showtotalsifcontainhidden', get_string('hidetotalifhiddenitems', 'grades'), $options);
    $mform->addHelpButton('report_ppreport_showtotalsifcontainhidden', 'hidetotalifhiddenitems', 'grades');
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 */
function gradereport_ppreport_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (empty($course)) {
        // We want to display these reports under the site context.
        $course = get_fast_modinfo(SITEID)->get_course();
    }
    $systemcontext = context_system::instance();
    $usercontext = context_user::instance($user->id);
    $coursecontext = context_course::instance($course->id);
    if (grade_report_ppreport::check_access($systemcontext, $coursecontext, $usercontext, $course, $user->id)) {
        $url = new moodle_url('/grade/report/ppreport/index.php', array('userid' => $user->id, 'id' => $course->id));
        $node = new core_user\output\myprofile\node('reports', 'gradesppreport', get_string('gradesppreport', 'gradereport_ppreport'),
                null, $url);
        $tree->add_node($node);
    }
}

function format_period($seconds_input)
{
  $hours = (int)($minutes = (int)($seconds = (int)($milliseconds = (int)($seconds_input * 1000)) / 1000) / 60) / 60;
  return $hours.':'.($minutes%60).':'.($seconds%60).(($milliseconds===0)?'':'.'.rtrim($milliseconds%1000, '0'));
}