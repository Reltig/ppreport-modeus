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
            get_string('timestart', 'gradereport_ppreport'),
            get_string('timefinish', 'gradereport_ppreport'),
            get_string('timediff', 'gradereport_ppreport'), 
            get_string('grade', 'gradereport_ppreport'),
            get_string('attempts', 'gradereport_ppreport'),
            get_string('avg_score', 'gradereport_ppreport'),
            get_string('diff_btw_avg_time_and_fintime', 'gradereport_ppreport'),
        );

        $tablecolumns = array('username', 'timestart', 'timefinish', 'timediff', 'grade', 'attempts', 'avg_score', 'diff_btw_avg_time_and_fintime');

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
        $quizList = new flexible_table('grade-report-ppreport-quiz-list'.$this->user->id);

        $tableheaders = array(
            get_string('quizname', 'gradereport_ppreport'),
        );

        $tablecolumns = array('quizname');

        $quizList->define_columns($tablecolumns);
        $quizList->define_headers($tableheaders);
        $quizList->define_baseurl($this->baseurl);

        $quizList->set_attribute('cellspacing', '0');
        $quizList->set_attribute('id', 'ppreport-quiz-list');
        $quizList->set_attribute('class', 'boxaligncenter generaltable');

        $quizList->setup();

        foreach($quizes as $quiz) {
            $quizList->add_data([
                html_writer::link(new moodle_url('/grade/report/ppreport/index.php', [
                    'id' => $COURSE->id, 
                    'quizid' => $quiz->id
                ]), $quiz->name)
            ]);
        }
        
        ob_start();
        $quizList->print_html();
        $table_html = ob_get_clean();

        $chart = $this->print_avg_time_chart();
        $chart2 = $this->print_avg_grades_pie_chart();
        $chart3 = $this->print_avg_attempts_bar_chart();
        $chart4 = $this->print_performance_comparison_chart();

        return $table_html . $chart . $chart2 . $chart3 . $chart4;
    }

    /**
     * Set up the courses grades data for the report.
     *
     * @param bool $studentcoursesonly Only show courses that the user is a student of.
     * @return array of course grades information
     */
    public function setup_courses_data($quizid, $studentcoursesonly, $groupid = 0) {
        global $USER, $DB;

        $sql = "SELECT qa.timestart, qa.timefinish, u.firstname, u.lastname, u.id as userid 
                FROM {quiz_grades} qg
                LEFT JOIN {quiz_attempts} qa ON qa.quiz = qg.quiz AND qa.timefinish = qg.timemodified
                LEFT JOIN {user} u ON qa.userid = u.id";
        
        $params = array($quizid);

        if ($groupid) {
            $sql .= " JOIN {groups_members} gm ON gm.userid = u.id 
                      WHERE gm.groupid = ? AND state = 'finished' AND qg.quiz = ?";
            $params = array($groupid, $quizid);
        } else {
            $sql .= " WHERE state = 'finished' AND qg.quiz = ?";
        }

        $sql .= " ORDER BY timefinish - timestart";

        return $DB->get_records_sql($sql, $params);
    }


    /**
     * Вычисляет среднее время выполнения теста.
     *
     * @param int $quizid Идентификатор теста
     * @return float Среднее время выполнения теста
     */
    protected function calculate_avg_time_solve($quizid) {
        global $DB;

        // Получаем среднее время выполнения теста
        $sql = "SELECT ROUND(AVG(timefinish - timestart)) as avg_time 
                FROM {quiz_attempts} 
                WHERE quiz = :quizid AND state = 'finished'";
        $avg_time = $DB->get_field_sql($sql, ['quizid' => $quizid]);

        return $avg_time ? (int)$avg_time : 0;
    }

    /**
     * Fill the table for displaying.
     *
     * @param bool $activitylink If this report link to the activity report or the user report.
     * @param bool $studentcoursesonly Only show courses that the user is a student of.
     */
    public function fill_table($quizid, $activitylink = false, $studentcoursesonly = false, $groupid = 0) {
        global $CFG, $DB, $OUTPUT, $USER, $COURSE;

        // Получаем данные о попытках теста с учетом группы
        $quiz_times = $this->setup_courses_data($quizid, $studentcoursesonly, $groupid);

        // Вычисляем среднее время выполнения теста (avg_time_solve)
        $avg_time_solve = $this->print_avg_data($quizid);

        $avg_time_solve = $this->calculate_avg_time_solve($quizid);

        foreach ($quiz_times as $quiz_time) {

            // Getting user's grade for the test
            $grade = $DB->get_field('quiz_grades', 'grade', [
                'quiz' => $quizid,
                'userid' => $quiz_time->userid
            ]);

            $attempts_count = $DB->count_records('quiz_attempts', [
                'quiz' => $quizid,
                'userid' => $quiz_time->userid,
                'state' => 'finished' // Count only finished attempts
            ]);

            $attempts = $DB->get_records('quiz_attempts', [
                'quiz' => $quizid,
                'userid' => $quiz_time->userid,
                'state' => 'finished' // Учитываем только завершенные попытки
            ]);

            // Count avg score for all attempts
            $sum_grades = 0;
            foreach ($attempts as $attempt) {
                $sum_grades += $attempt->sumgrades;
            }
            $avg_grade = $attempts_count > 0 ? $sum_grades / $attempts_count : 0;

            // Рассчитываем время выполнения теста для текущего пользователя
            $time_diff = $quiz_time->timefinish - $quiz_time->timestart;

            // Рассчитываем разницу между средним временем выполнения и временем выполнения текущего пользователя
            $time_diff_avg = $time_diff - $avg_time_solve;

            $date_format = 'Y-m-d H:i:s';
            $data = [
                html_writer::link(new moodle_url('/grade/report/ppreport/index.php', [
                    'id' => $COURSE->id, 
                    'userid' => $quiz_time->userid
                ]), $quiz_time->firstname . ' ' . $quiz_time->lastname), 
                gmdate($date_format, $quiz_time->timestart), 
                gmdate($date_format, $quiz_time->timefinish),
                format_period($quiz_time->timefinish - $quiz_time->timestart),
                $grade !== false ? format_float($grade, 2) : 'N/A',
                $attempts_count,
                format_float($avg_grade, 2),
                format_period($time_diff_avg)
            ];
            
            $this->table->add_data($data);
        }

        return true;
    }

    public function print_avg_data($quizid) {
        global $DB;
        $sql = "SELECT qg.id, qa.timefinish - qa.timestart as avg_diff FROM {quiz_grades} qg
        LEFT JOIN {quiz_attempts} qa ON qa.quiz =  qg.quiz and qa.timefinish and qg.timemodified
        WHERE qg.quiz = ?";

        $r = $DB->get_records_sql($sql, array($quizid));
        return array_values($r)[0]->avg_diff;
    }

    public function print_quiz_page($quizid, $groupid = 0) {
        global $DB, $OUTPUT, $COURSE;
        
        // Get basic quiz info with group filter
        $sql = "SELECT count(DISTINCT qg.userid) AS studentsCount, avg(grade) AS gradeAvg 
                FROM {quiz_grades} qg 
                JOIN {user} u ON u.id = qg.userid";
        
        $params = array($quizid);
        
        if ($groupid) {
            $sql .= " JOIN {groups_members} gm ON gm.userid = u.id 
                      WHERE quiz = ? AND gm.groupid = ?";
            $params = array($quizid, $groupid);
        } else {
            $sql .= " WHERE quiz = ?";
        }
        
        $result = $DB->get_record_sql($sql, $params);
        $result->quizname = $DB->get_record_sql("SELECT name FROM {quiz} q WHERE id = ?", array($quizid))->name;
        
        // Add charts
        $result->timeChart = $this->generate_time_chart($quizid, $groupid);
        $result->gradeChart = $this->generate_grade_chart($quizid, $groupid);
        
        // Создаем и заполняем таблицу
        $this->setup_table(); // Устанавливаем структуру таблицы
        $this->fill_table($quizid, false, false, $groupid); // Заполняем таблицу данными с учетом группы
        
        // Получаем HTML таблицы
        ob_start();
        $this->table->print_html();
        $result->quizUsersTable = ob_get_clean();
        
        $result->solveTimeAvg = $this->calculate_avg_time_solve($quizid);
        
        $result->attemptsProgressChart = $this->generate_attempts_progress_pie_chart($quizid, $groupid);
        
        echo $OUTPUT->render_from_template("gradereport_ppreport/quiz", $result);
    }

    protected function print_performance_comparison_chart() {
        global $DB, $OUTPUT, $COURSE;

        // Получаем средние оценки по группам для текущего курса
        $sql = "WITH StudentAverages AS (
                    SELECT g.id AS group_id,
                           u.id AS user_id,
                           ROUND(AVG(qg.grade), 2) as student_avg
                    FROM {groups} g
                    JOIN {groups_members} gm ON g.id = gm.groupid
                    JOIN {user} u ON u.id = gm.userid
                    JOIN {quiz_grades} qg ON u.id = qg.userid
                    JOIN {quiz} q ON q.id = qg.quiz
                    WHERE q.course = ?
                    GROUP BY g.id, u.id
                )
                SELECT g.id AS group_id,
                       g.name AS group_name,
                       COUNT(DISTINCT gm.userid) AS student_count,
                       ROUND(AVG(sa.student_avg), 2) AS avg_grade
                FROM {groups} g
                JOIN {groups_members} gm ON g.id = gm.groupid
                JOIN StudentAverages sa ON sa.group_id = g.id
                WHERE g.courseid = ?
                GROUP BY g.id, g.name";

        $params = array($COURSE->id, $COURSE->id);
        $group_performance = $DB->get_records_sql($sql, $params);

        // Проверяем, есть ли группы
        if (empty($group_performance)) {
            return '<div class="no-groups-message">Нет доступных групп для отображения успеваемости по группам.</div>';
        }

        // Подготовка данных для графика
        $group_names = array();
        $avg_grades = array();
        
        foreach ($group_performance as $performance) {
            // Форматируем название группы с добавлением информации о количестве студентов
            $group_names[] = $performance->group_name . ' (' . $performance->student_count . ' студ.)';
            $avg_grades[] = $performance->avg_grade;
        }

        // Создаем столбчатую диаграмму
        $chart = new \core\chart_bar();
        $chart->set_title('Средняя успеваемость по группам');

        // Добавляем серию данных в график
        $series = new \core\chart_series('Средняя оценка', $avg_grades);
        $chart->add_series($series);
        
        // Устанавливаем метки для оси X
        $chart->set_labels($group_names);

        // Настраиваем оси
        $yaxis = new \core\chart_axis('y', 'Средняя оценка', 'left');
        $chart->set_yaxis($yaxis);
        
        $xaxis = new \core\chart_axis('x', 'Группы', 'bottom');
        $chart->set_xaxis($xaxis);

        // Возвращаем HTML-код графика
        return $OUTPUT->render($chart);
    }

    protected function generate_attempts_progress_pie_chart($quizid, $groupid = 0) {
        global $DB, $OUTPUT;
    
        // Получаем среднее количество попыток для каждого теста
        $sql = "SELECT q.id, q.name as quiz_name, 
                ROUND(CAST(COUNT(qa.id) AS FLOAT) / 
                    NULLIF(COUNT(DISTINCT qa.userid), 0), 2) as avg_attempts
                FROM {quiz} q
                LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz AND qa.state = 'finished'
                LEFT JOIN {user} u ON u.id = qa.userid";

        if ($groupid) {
            $sql .= " LEFT JOIN {groups_members} gm ON gm.userid = u.id";
        }

        $sql .= " WHERE q.course = (SELECT course FROM {quiz} WHERE id = ?)";
        
        $params = array($quizid);

        if ($groupid) {
            $sql .= " AND (gm.groupid = ? OR qa.id IS NULL)";
            $params[] = $groupid;
        }

        $sql .= " GROUP BY q.id, q.name
                  ORDER BY q.name";
    
        $attempts = $DB->get_records_sql($sql, $params);
    
        // Подготовка данных для столбчатой диаграммы
        $quiz_names = array();
        $avg_attempts = array();
    
        foreach ($attempts as $attempt) {
            $quiz_names[] = $attempt->quiz_name;
            $avg_attempts[] = (float)$attempt->avg_attempts;
        }
    
        // Создаем столбчатую диаграмму
        $chart = new \core\chart_bar();
        $chart->set_title('Среднее количество попыток по тестам');
    
        // Добавляем серию данных в график
        $series = new \core\chart_series('Среднее количество попыток', $avg_attempts);
        $chart->add_series($series);
        
        // Устанавливаем метки для оси X
        $chart->set_labels($quiz_names);
        
        // Настраиваем оси
        $yaxis = new \core\chart_axis('y', 'Среднее количество попыток', 'left');
        $chart->set_yaxis($yaxis);
        
        $xaxis = new \core\chart_axis('x', 'Тесты', 'bottom');
        $chart->set_xaxis($xaxis);
    
        // Возвращаем HTML-код графика
        return $OUTPUT->render($chart);
    }

    protected function generate_time_chart($quizid, $groupid = 0) {
        global $DB, $OUTPUT;

        $sql = "SELECT u.id, CONCAT(u.firstname, ' ', u.lastname) as username, 
                qa.timefinish - qa.timestart as solve_time
                FROM {quiz_attempts} qa
                JOIN {user} u ON u.id = qa.userid
                WHERE qa.quiz = ? AND qa.state = 'finished'";
        $params = array($quizid);

        if ($groupid) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {groups_members} gm 
                WHERE gm.userid = u.id AND gm.groupid = ?
            )";
            $params[] = $groupid;
        }

        $attempts = $DB->get_records_sql($sql, $params);

        $usernames = array();
        $times = array();
        foreach ($attempts as $attempt) {
            $usernames[] = $attempt->username;
            $times[] = $attempt->solve_time;
        }

        $chart = new \core\chart_bar();
        $chart->set_title('Время выполнения теста');
        $series = new \core\chart_series('Затраченное время', $times);
        $chart->add_series($series);
        $chart->set_labels($usernames);

        return $OUTPUT->render($chart);
    }

    protected function generate_grade_chart($quizid, $groupid = 0) {
        global $DB, $OUTPUT;

        $sql = "SELECT u.id, CONCAT(u.firstname, ' ', u.lastname) as username, 
                qg.grade
                FROM {quiz_grades} qg
                JOIN {user} u ON u.id = qg.userid
                WHERE qg.quiz = ?";
        $params = array($quizid);

        if ($groupid) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {groups_members} gm 
                WHERE gm.userid = u.id AND gm.groupid = ?
            )";
            $params[] = $groupid;
        }

        $grades = $DB->get_records_sql($sql, $params);

        $usernames = array();
        $gradeValues = array();
        foreach ($grades as $grade) {
            $usernames[] = $grade->username;
            $gradeValues[] = $grade->grade;
        }

        $chart = new \core\chart_bar();
        $chart->set_title('Оценки студентов');
        $series = new \core\chart_series('Оценка', $gradeValues);
        $chart->add_series($series);
        $chart->set_labels($usernames);

        return $OUTPUT->render($chart);
    }

    /**
     * Prints or returns the HTML from the flexitable.
     * @param bool $return Whether or not to return the data instead of printing it directly.
     * @return string
     */
    public function print_table($quizid, $return=true) {
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
     * Fetches the list of student groups for the current course.
     *
     * @return array An array of groups with their IDs and names.
     */
    public function get_student_groups() {
        global $DB, $COURSE;

        $groups = $DB->get_records('groups', ['courseid' => $COURSE->id], 'name', 'id, name');
        return $groups;
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

        $this->create_user_general_data($result, $userid);
        $result->userQuizGradeChart = $this->create_user_quiz_grades_chart($userid);
        $result->solveTimeChart = $this->create_solve_time_chart($userid);
        $result->userQuizGradeTable = $this->create_user_quiz_grades_table($userid);

        echo $OUTPUT->render_from_template("gradereport_ppreport/user", $result);
    }

    private function create_user_general_data(&$result, $userid) {
        global $DB, $COURSE, $OUTPUT;

        $sql = "SELECT q.id, qg.grade, q.name FROM {quiz_grades} qg
                LEFT JOIN {quiz} q ON qg.quiz = q.id AND q.course = ?
                WHERE qg.userid = ?
                ORDER BY q.timecreated ASC";
        $data = $DB->get_records_sql($sql, array($COURSE->id, $userid));
        $userGrades = array_map(fn ($x) => $x->grade, $data);
        $solvedQuizCount = count($userGrades);
        $sumQuizGrade = array_sum($userGrades);
        $gradeAvg = $solvedQuizCount == 0 ? 0 : $sumQuizGrade / $solvedQuizCount;

        $result->solvedQuizCount = $solvedQuizCount;
        $result->sumQuizGrade = $sumQuizGrade;
        $result->gradeAvg = $gradeAvg;
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
        $chart->add_series(new core\chart_series('Оценки пользователя', array_merge($userGrades)));
        $chart->set_labels($quizLabels);

        return $OUTPUT->render($chart);
    }

    private function create_solve_time_chart($userid) {
        global $DB, $COURSE, $OUTPUT;

        // Получаем среднее время выполнения для каждого теста
        $sql = "SELECT q.id, q.name, 
                AVG(qa.timefinish - qa.timestart) as avg_time
                FROM {quiz} q
                LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz AND qa.state = 'finished'
                WHERE q.course = ?
                GROUP BY q.id, q.name
                ORDER BY q.timecreated ASC";
        $avgQuizData = $DB->get_records_sql($sql, array($COURSE->id));

        // Получаем время выполнения конкретного пользователя
        $sql = "SELECT q.id, q.name, 
                (qa.timefinish - qa.timestart) as user_time
                FROM {quiz} q
                LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz AND qa.userid = ? AND qa.state = 'finished'
                WHERE q.course = ?
                ORDER BY q.timecreated ASC";
        $userQuizData = $DB->get_records_sql($sql, array($userid, $COURSE->id));

        // Подготовка данных для графика
        $quizLabels = [];
        $userTimes = [];
        $avgTimes = [];

        foreach ($avgQuizData as $quizData) {
            $quizLabels[] = $quizData->name;
            $avgTimes[] = $quizData->avg_time ? (float)$quizData->avg_time : 0;
            
            // Получаем время пользователя для этого теста
            $userTime = isset($userQuizData[$quizData->id]) ? 
                (float)$userQuizData[$quizData->id]->user_time : 0;
            $userTimes[] = $userTime;
        }

        // Создаем график
        $chart = new \core\chart_line();
        $chart->add_series(new \core\chart_series('Время выполнения теста студентом', $userTimes));
        $chart->add_series(new \core\chart_series('Среднее время выполнения теста', $avgTimes));
        $chart->set_labels($quizLabels);

        return $OUTPUT->render($chart);
    }

    private function create_user_quiz_grades_table($userid) {
        $userQuizTable = new flexible_table('grade-report-ppreport-'.$this->user->id);

        $tableheaders = array(
            get_string('quizname', 'gradereport_ppreport'),
            get_string('timestart', 'gradereport_ppreport'),
            get_string('timefinish', 'gradereport_ppreport'),
            get_string('timediff', 'gradereport_ppreport'),
            get_string('grade', 'gradereport_ppreport'),
        );

        $tablecolumns = array('quizname', 'timestart', 'timefinish', 'timediff', 'grade');

        $userQuizTable->define_columns($tablecolumns);
        $userQuizTable->define_headers($tableheaders);
        $userQuizTable->define_baseurl($this->baseurl);

        $userQuizTable->set_attribute('cellspacing', '0');
        $userQuizTable->set_attribute('id', 'ppreport-user-quiz-grades');
        $userQuizTable->set_attribute('class', 'boxaligncenter generaltable');

        $userQuizTable->setup();

        $this->fill_user_quiz_grades_table($userQuizTable, $userid);
        ob_start();
        $userQuizTable->print_html();
        return ob_get_clean();
    }

    public function display() {
        global $OUTPUT;

        // Отображение таблицы
        $this->fill_table($this->quizid);
        echo $this->table->finish_output();

        // Отображение графика
        $chart = $this->print_avg_time_chart($this->quizid);
        $chart2 = $this->print_avg_grades_pie_chart($this->quizid);
        $chart3 = $this->print_avg_attempts_bar_chart($this->quizid);
        $chart4 = $this->print_performance_comparison_chart($this->quizid);
        echo $chart . $chart2 . $chart3 . $chart4;
    }


    public function print_avg_time_chart() {
        global $DB, $OUTPUT, $COURSE;
    
        // Получаем данные о среднем времени выполнения тестов
        $sql = "SELECT q.id, q.name, AVG(qa.timefinish - qa.timestart) as avg_time 
                FROM {quiz} q
                JOIN {quiz_attempts} qa ON q.id = qa.quiz
                WHERE q.course = ? AND qa.state = 'finished'
                GROUP BY q.id, q.name";
        $avg_times = $DB->get_records_sql($sql, array($COURSE->id));
    
        // Создаем массивы для данных графика
        $quiz_names = array();
        $avg_times_array = array();
    
        // Заполняем массивы данными
        foreach ($avg_times as $avg_time) {
            $quiz_names[] = $avg_time->name;
            $avg_times_array[] = $avg_time->avg_time;
        }
    
        // Создаем график
        $chart = new \core\chart_line(); // Используем столбчатую диаграмму
        $series = new \core\chart_series('Среднее время выполнения', $avg_times_array);
        $chart->add_series($series);
        $chart->set_labels($quiz_names);
    
        // Возвращаем HTML-код графика
        return $OUTPUT->render($chart);
    }

    private function fill_user_quiz_grades_table(&$table, $userid) {
        global $DB, $COURSE, $OUTPUT;

        // Измененный SQL-запрос для получения всех завершенных тестов пользователя
        $sql = "SELECT q.id, q.name, qg.grade, qa.timestart, qa.timefinish, qa.timefinish - qa.timestart as timediff 
                FROM {quiz_attempts} qa
                JOIN {quiz_grades} qg ON qg.quiz = qa.quiz AND qg.userid = qa.userid
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course = ? AND qa.state = 'finished' AND qg.userid = ? 
                ORDER BY q.timecreated ASC";
        $userQuizTimeData = $DB->get_records_sql($sql, array($COURSE->id, $userid));

        // Проверка на наличие данных
        if (empty($userQuizTimeData)) {
            return;
        }

        foreach ($userQuizTimeData as $userQuizTime) {
            $date_format = 'Y-m-d H:i:s';
            $data = [
                $userQuizTime->name,
                gmdate($date_format, $userQuizTime->timestart), 
                gmdate($date_format, $userQuizTime->timefinish),
                format_period($userQuizTime->timediff),
                $userQuizTime->grade
            ];
            
            $table->add_data($data);
        }
    }


    public function print_avg_grades_pie_chart() {
        global $DB, $OUTPUT, $COURSE;
    
        // Получаем средние оценки за тесты
        $sql = "SELECT q.id AS quiz_id, q.name AS quiz_name, AVG(qg.grade) AS avg_grade
                FROM {quiz} q
                JOIN {quiz_grades} qg ON q.id = qg.quiz
                WHERE q.course = ?
                GROUP BY q.id, q.name";
        
        $avg_grades = $DB->get_records_sql($sql, array($COURSE->id));
    
        // Подготовка данных для графика
        $quiz_names = array();
        $avg_grades_array = array();
    
        foreach ($avg_grades as $grade) {
            $quiz_names[] = $grade->quiz_name;
            $avg_grades_array[] = $grade->avg_grade;
        }
    
        // Создаем круговую диаграмму
        $chart = new \core\chart_pie();
        $chart->set_title('Средние оценки за тесты');
    
        // Добавляем серию данных в график
        $series = new \core\chart_series('Средняя оценка', $avg_grades_array);
        $chart->add_series($series);
        
        // Устанавливаем метки для круговой диаграммы
        $chart->set_labels($quiz_names);
    
        // Возвращаем HTML-код графика
        return $OUTPUT->render($chart);
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


    /**
     * Ищет элемент в массиве по заданному условию.
     *
     * @param array $array Массив для поиска.
     * @param callable $callback Функция обратного вызова для проверки условия.
     * @return mixed Найденный элемент или null, если не найден.
     */
    private function array_search_func(array $array, callable $callback) {
        foreach ($array as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        return null; // Возвращаем null, если элемент не найден
    }

    public function print_avg_attempts_bar_chart() {
        global $DB, $OUTPUT, $COURSE;
    
        // Получаем данные о количестве попыток за тесты
        $sql = "SELECT q.id AS quiz_id, q.name AS quiz_name, COUNT(qa.id) as attempts_count 
                FROM {quiz} q
                LEFT JOIN {quiz_attempts} qa ON q.id = qa.quiz
                WHERE q.course = ?
                GROUP BY q.id, q.name";
        
        $attempts_data = $DB->get_records_sql($sql, array($COURSE->id));
    
        // Подготовка данных для графика
        $quiz_names = array();
        $attempts_counts = array();
    
        foreach ($attempts_data as $data) {
            $quiz_names[] = $data->quiz_name;
            $attempts_counts[] = $data->attempts_count;
        }
    
        // Создаем столбчатую диаграмму
        $chart = new \core\chart_bar();
        $chart->set_title('Среднее количество попыток по тестам');
    
        // Добавляем серию данных в график
        $series = new \core\chart_series('Среднее количество попыток', $attempts_counts);
        $chart->add_series($series);
        
        // Устанавливаем метки для оси X
        $chart->set_labels($quiz_names);
    
        // Настраиваем оси
        $yaxis = new \core\chart_axis('y', 'Количество попыток', 'left');
        $chart->set_yaxis($yaxis);
        
        $xaxis = new \core\chart_axis('x', 'Тесты', 'bottom');
        $chart->set_xaxis($xaxis);
    
        // Возвращаем HTML-код графика
        return $OUTPUT->render($chart);
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
        $node = new core_user\output\myprofile\node('reports', 'gradesppreport', get_string('pluginname', 'gradereport_ppreport'),
                null, $url);
        $tree->add_node($node);
    }
}

function format_period($seconds_input)
{
  $hours = (int)($minutes = (int)($seconds = (int)($milliseconds = (int)($seconds_input * 1000)) / 1000) / 60) / 60;
  return $hours.':'.($minutes%60).':'.($seconds%60).(($milliseconds===0)?'':'.'.rtrim($milliseconds%1000, '0'));
}