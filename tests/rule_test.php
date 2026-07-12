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
 * PHPUnit tests for the quizaccess_sanad_lockdown plugin.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_sanad_lockdown;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Unit tests for the quizaccess_sanad_lockdown class and token_manager.
 */
class rule_test extends \advanced_testcase {
    public function test_token_issue_and_validate() {
        $this->resetAfterTest();

        $quizid = 100;
        $userid = 200;
        $deviceid = 'test-device-123';
        // 1. Issue a token.
        $token = token_manager::issue($quizid, $userid, $deviceid, 1800);
        $this->assertNotEmpty($token);

        // 2. Validate valid token.
        $this->assertTrue(token_manager::validate($quizid, $userid, $token));

        // 3. Validate with wrong user.
        $this->assertFalse(token_manager::validate($quizid, $userid + 1, $token));

        // 4. Validate with wrong quiz.
        $this->assertFalse(token_manager::validate($quizid + 1, $userid, $token));

        // 5. Revoke.
        token_manager::revoke($quizid, $userid);
        $this->assertFalse(token_manager::validate($quizid, $userid, $token));
    }

    public function test_token_expiration() {
        $this->resetAfterTest();

        $quizid = 101;
        $userid = 201;

        // Issue token that expires immediately (-1 second).
        $token = token_manager::issue($quizid, $userid, '', -1);

        // Validation should fail and delete the token.
        $this->assertFalse(token_manager::validate($quizid, $userid, $token));
    }

    public function test_violation_logger() {
        $this->resetAfterTest();

        $quizid = 102;
        $userid = 202;

        violation_logger::log($quizid, $userid, violation_logger::TYPE_WRONG_BROWSER);
        violation_logger::log($quizid, $userid, violation_logger::TYPE_FOCUS_LOST, 'dev123', ['action' => 'test']);

        $this->assertEquals(2, violation_logger::count($quizid, $userid));
        $this->assertEquals(1, violation_logger::count($quizid, $userid, violation_logger::TYPE_WRONG_BROWSER));
        $this->assertEquals(1, violation_logger::count($quizid, $userid, violation_logger::TYPE_FOCUS_LOST));
    }

    public function test_teacher_token_scanned_by_student() {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $quizid = $quiz->id;

        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $cm = get_coursemodule_from_instance('quiz', $quizid);
        $context = \context_module::instance($cm->id);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $roleid);
        assign_capability('mod/quiz:preview', CAP_ALLOW, $roleid, $context->id);

        $teachertoken = token_manager::issue($quizid, $teacher->id, '', 1800);

        $_SERVER['HTTP_X_Sanad_DEVICE_ID'] = 'student-device-999';

        $this->assertTrue(token_manager::validate($quizid, $student->id, $teachertoken));

        $studentsession = $DB->get_record('quizaccess_sanad_sessions', [
            'quizid' => $quizid,
            'userid' => $student->id,
        ]);
        $this->assertNotEmpty($studentsession);
        $this->assertEquals('student-device-999', $studentsession->deviceid);
        $this->assertNotEquals($teachertoken, $studentsession->token);

        unset($_SERVER['HTTP_X_Sanad_DEVICE_ID']);
    }
}
