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
 * Violation logger for quizaccess_ewa_lockdown.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_ewa_lockdown;

/**
 * Records security violations detected during secure exams.
 */
class violation_logger {

    /** @var string Attempt from non-EWA browser */
    const TYPE_WRONG_BROWSER   = 'wrong_browser';

    /** @var string Session token is invalid */
    const TYPE_INVALID_TOKEN   = 'invalid_token';

    /** @var string Session token has expired */
    const TYPE_EXPIRED_TOKEN   = 'expired_token';

    /** @var string Application lost focus (overlay or home button) */
    const TYPE_FOCUS_LOST      = 'focus_lost';

    /** @var string Request device ID mismatch with the session */
    const TYPE_DEVICE_MISMATCH = 'device_mismatch';

    /**
     * Log a security violation to the database.
     *
     * @param int    $quizid        The quiz ID.
     * @param int    $userid        The user ID.
     * @param string $violationtype One of the TYPE_* constants.
     * @param string $deviceid      Device fingerprint (may be empty).
     * @param array  $details       Extra context as key-value pairs (stored as JSON).
     */
    public static function log(
        int $quizid,
        int $userid,
        string $violationtype,
        string $deviceid = '',
        array $details = []
    ): void {
        global $DB;

        $record = new \stdClass();
        $record->quizid        = $quizid;
        $record->userid        = $userid;
        $record->violationtype = $violationtype;
        $record->deviceid      = $deviceid;
        $record->details       = !empty($details) ? json_encode($details) : null;
        $record->timecreated   = time();

        $DB->insert_record('quizaccess_ewa_violations', $record);
    }

    /**
     * Return the number of violations of a given type for a user in a quiz.
     *
     * @param int    $quizid        The quiz ID.
     * @param int    $userid        The user ID.
     * @param string $violationtype Violation type to count (or empty for all types).
     * @return int Count of violations.
     */
    public static function count(int $quizid, int $userid, string $violationtype = ''): int {
        global $DB;

        $params = ['quizid' => $quizid, 'userid' => $userid];
        $where  = 'quizid = :quizid AND userid = :userid';

        if ($violationtype !== '') {
            $where .= ' AND violationtype = :violationtype';
            $params['violationtype'] = $violationtype;
        }

        return $DB->count_records_select('quizaccess_ewa_violations', $where, $params);
    }

    /**
     * Return all violations for a user in a quiz, ordered by time.
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     * @return array Array of stdClass records.
     */
    public static function get_all(int $quizid, int $userid): array {
        global $DB;
        return $DB->get_records('quizaccess_ewa_violations', ['quizid' => $quizid, 'userid' => $userid], 'timecreated ASC');
    }
}
