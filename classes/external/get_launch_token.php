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

namespace quizaccess_sanad_lockdown\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use quizaccess_sanad_lockdown\token_manager;
use stdClass;

/**
 * External function to get a launch token and deep link for SANAD Kiosk.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_launch_token extends external_api {

    /**
     * Describes the parameters for get_launch_token.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'The quiz ID'),
            'cmid'   => new external_value(PARAM_INT, 'The course module ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $quizid The quiz ID.
     * @param int $cmid   The course module ID (optional).
     * @return array Response structure.
     */
    public static function execute($quizid, $cmid = 0) {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'quizid' => $quizid,
            'cmid'   => $cmid,
        ]);

        $quizid = $params['quizid'];
        $cmid   = $params['cmid'];

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);

        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('quiz', $cmid, $course->id, false, MUST_EXIST);
        } else {
            $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
            $cmid = (int)$cm->id;
        }

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:attempt', $context);

        // Check if lockdown rule is enabled for this quiz.
        $ruleconfig = $DB->get_record('quizaccess_sanad_lockdown', ['quizid' => $quizid]);
        $enabled = ($ruleconfig && !empty($ruleconfig->enabled));

        if (!$enabled) {
            return [
                'success'   => true,
                'enabled'   => false,
                'quizid'    => $quizid,
                'cmid'      => $cmid,
                'token'     => '',
                'deeplink'  => '',
                'launchurl' => '',
            ];
        }

        $expiry = max(300, (int)($ruleconfig->tokenexpiry ?? 1800));
        $token  = token_manager::issue($quizid, (int)$USER->id, '', $expiry);
        $launchurl = token_manager::build_launch_url($quizid, $cmid, $token);

        $moodlebase = rtrim($CFG->wwwroot, '/');
        $encodedbase = urlencode($moodlebase);
        $encodedurl = urlencode($launchurl);

        $deeplink = "sanad-kiosk://launch-quiz?quiz_id={$quizid}&token={$token}&base_url={$encodedbase}&start_url={$encodedurl}";

        return [
            'success'   => true,
            'enabled'   => true,
            'quizid'    => $quizid,
            'cmid'      => $cmid,
            'token'     => $token,
            'deeplink'  => $deeplink,
            'launchurl' => $launchurl,
        ];
    }

    /**
     * Describes the return structure for get_launch_token.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, 'Whether the call succeeded'),
            'enabled'   => new external_value(PARAM_BOOL, 'Whether SANAD lockdown is active on this quiz'),
            'quizid'    => new external_value(PARAM_INT, 'The quiz ID'),
            'cmid'      => new external_value(PARAM_INT, 'The course module ID'),
            'token'     => new external_value(PARAM_RAW, 'Signed session token (empty if not enabled)'),
            'deeplink'  => new external_value(PARAM_RAW, 'Direct Deep Link URL to launch SANAD Kiosk'),
            'launchurl' => new external_value(PARAM_RAW, 'Full HTTP launch URL for SANAD browser'),
        ]);
    }
}
