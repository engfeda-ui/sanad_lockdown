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
 * Token manager for quizaccess_ewa_lockdown.
 *
 * Issues and validates HMAC-SHA256 session tokens for the EWA Secure Browser.
 * Each token encodes: userid, quizid, deviceid, and a creation timestamp,
 * then signs the payload with a per-site secret. Tokens are also stored in
 * the database so they can be invalidated server-side.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_ewa_lockdown;

/**
 * Manages the creation and validation of secure session tokens.
 */
class token_manager {
    /** @var string HTTP header name sent by the EWA Secure Browser app. */
    const HEADER_TOKEN = 'HTTP_X_EWA_SECURE_TOKEN';

    /** @var string HTTP header name for the device fingerprint. */
    const HEADER_DEVICE = 'HTTP_X_EWA_DEVICE_ID';

    /** @var string HTTP header that identifies the app (not secret, just an identifier). */
    const HEADER_APP_ID = 'HTTP_X_EWA_APP';

    /** @var string Expected value for the app identifier header. */
    const EXPECTED_APP_ID = 'ewa-secure-browser-v1';

    /**
     * Issue a new signed session token for a user+quiz combination.
     *
     * Creates a database record and returns the raw token string to embed in the QR.
     *
     * @param int    $quizid   The quiz ID.
     * @param int    $userid   The user ID.
     * @param string $deviceid Device fingerprint from the app (may be empty for QR pre-generation).
     * @param int    $expiry   Seconds until expiry (from quiz settings).
     * @return string The raw token string.
     */
    public static function issue(int $quizid, int $userid, string $deviceid, int $expiry): string {
        global $DB, $CFG;

        // Build signed payload: base64url(json) + "." + hmac.
        $now = time();
        $payload = [
            'q' => $quizid,
            'u' => $userid,
            't' => $now,
        ];
        $payloadb64 = self::base64url_encode(json_encode($payload));
        $secret = self::get_site_secret();
        $signature = self::base64url_encode(hash_hmac('sha256', $payloadb64, $secret, true));
        $token = $payloadb64 . '.' . $signature;

        // Delete any existing unexpired sessions for this user+quiz.
        $DB->delete_records('quizaccess_ewa_sessions', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);

        // Store in DB.
        $record = new \stdClass();
        $record->quizid = $quizid;
        $record->userid = $userid;
        $record->token = $token;
        $record->deviceid = $deviceid;
        $record->timecreated = $now;
        $record->timeexpires = $now + $expiry;
        $DB->insert_record('quizaccess_ewa_sessions', $record);

        return $token;
    }

    /**
     * Validate an incoming token from the EWA Secure Browser HTTP headers or Session query.
     *
     * @param int $quizid Expected quiz ID.
     * @param int $userid Expected user ID.
     * @return bool True if the token is valid and not expired.
     */
    public static function validate_from_request(int $quizid, int $userid): bool {
        // 1. First verify if it's the EWA app (via header OR user-agent fallback)
        if (!self::is_ewa_browser_request()) {
            return false;
        }

        // 2. Extract token from header or fallback to query parameter or active DB session
        $token = $_SERVER[self::HEADER_TOKEN] ?? '';
        if (empty($token)) {
            // Fallback for page loads/POSTs where custom headers are lost: read from URL query parameters
            $token = optional_param('ewatoken', '', PARAM_RAW);
        }

        if (empty($token)) {
            global $DB;
            $session = $DB->get_record_select(
                'quizaccess_ewa_sessions',
                'quizid = :quizid AND userid = :userid AND timeexpires > :now',
                ['quizid' => $quizid, 'userid' => $userid, 'now' => time()],
                'token',
                IGNORE_MULTIPLE
            );
            if ($session) {
                $token = $session->token;
            }
        }

        if (empty($token)) {
            return false;
        }

        return self::validate($quizid, $userid, $token);
    }

    /**
     * Validate a specific token string against the database and bind/verify the device ID.
     *
     * @param int    $quizid Expected quiz ID.
     * @param int    $userid Expected user ID.
     * @param string $token  The raw token to validate.
     * @return bool True if valid and not expired.
     */
    public static function validate(int $quizid, int $userid, string $token): bool {
        global $DB;

        if (empty($token)) {
            return false;
        }

        // Find the session record by token and quizid.
        $record = $DB->get_record('quizaccess_ewa_sessions', [
            'token'  => $token,
            'quizid' => $quizid,
        ]);

        if (!$record) {
            return false;
        }

        // If the token was not issued for this student, check if it was issued for a teacher/admin.
        if ((int) $record->userid !== (int) $userid) {
            $cm = get_coursemodule_from_instance('quiz', $quizid);
            if (!$cm) {
                return false;
            }
            $context = \context_module::instance($cm->id);
            // Check if the token creator has teacher capabilities (mod/quiz:preview or mod/quiz:viewreports).
            if (
                !has_capability('mod/quiz:preview', $context, $record->userid) &&
                !has_capability('mod/quiz:viewreports', $context, $record->userid)
            ) {
                return false;
            }
        }

        if (time() > $record->timeexpires) {
            // Token has expired — clean up.
            $DB->delete_records('quizaccess_ewa_sessions', ['id' => $record->id]);
            return false;
        }

        // Get the device ID from the request headers.
        $requestdeviceid = $_SERVER[self::HEADER_DEVICE] ?? '';

        // If the session record has no device ID bound yet, bind the current request device ID.
        if (empty($record->deviceid) && !empty($requestdeviceid)) {
            $record->deviceid = $requestdeviceid;
            $DB->update_record('quizaccess_ewa_sessions', $record);
        } else if (!empty($record->deviceid) && !empty($requestdeviceid) && $record->deviceid !== $requestdeviceid) {
            // If the device ID in request does not match the bound device ID, log violation and deny.
            violation_logger::log(
                $quizid,
                $userid,
                violation_logger::TYPE_DEVICE_MISMATCH,
                $requestdeviceid,
                ['bound_device' => $record->deviceid]
            );
            return false;
        }

        return true;
    }

    /**
     * Check whether the current HTTP request originates from the EWA Secure Browser.
     *
     * Supports both custom App ID headers and the User-Agent fallback.
     *
     * @return bool True if the request has the EWA app identifier.
     */
    public static function is_ewa_browser_request(): bool {
        // Check header first
        $appid = $_SERVER[self::HEADER_APP_ID] ?? '';
        if ($appid === self::EXPECTED_APP_ID) {
            return true;
        }

        // Fallback: check User-Agent string (reliable across all GET/POST requests inside the WebView)
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strpos($ua, 'EwaSecureBrowser') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Revoke all active sessions for a user+quiz combination.
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     */
    public static function revoke(int $quizid, int $userid): void {
        global $DB;
        $DB->delete_records('quizaccess_ewa_sessions', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);
    }

    /**
     * Build the payload URL that gets embedded in the QR code.
     *
     * The URL points to the Moodle quiz entry page with the token as a query
     * parameter. When the EWA app loads this URL it will also inject the token
     * as an HTTP header on every subsequent request.
     *
     * @param int    $quizid  The quiz ID.
     * @param int    $cmid    The course-module ID (for the quiz URL).
     * @param string $token   The session token.
     * @return string Absolute URL.
     */
    public static function build_launch_url(int $quizid, int $cmid, string $token): string {
        global $CFG;
        return $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cmid
            . '&ewatoken=' . urlencode($token)
            . '&ewalaunch=1';
    }

    /**
     * Get the short code for a given session token.
     * Generates a secure, human-readable format like "ID-XXXX" (e.g. "125-A8C9").
     *
     * @param string $token The session token.
     * @return string The short code, or empty string on failure.
     */
    public static function get_short_code(string $token): string {
        global $DB;
        $record = $DB->get_record('quizaccess_ewa_sessions', ['token' => $token], 'id');
        if (!$record) {
            return '';
        }
        $secret = self::get_site_secret();
        $sig = substr(hash_hmac('sha256', $record->id, $secret), 0, 4);
        return $record->id . '-' . strtoupper($sig);
    }

    /**
     * Verify a short code and return the corresponding session record if valid.
     *
     * @param string $code The input short code (e.g. "125-A8C9" or "125a8c9").
     * @return \stdClass|null The session record object, or null if invalid/expired.
     */
    public static function verify_short_code(string $code): ?\stdClass {
        global $DB;
        $parts = explode('-', $code);
        if (count($parts) !== 2) {
            return null;
        }
        $id = (int)$parts[0];
        $sig = strtoupper(trim($parts[1]));

        $secret = self::get_site_secret();
        $expectedsig = strtoupper(substr(hash_hmac('sha256', $id, $secret), 0, 4));

        if ($sig !== $expectedsig) {
            return null;
        }

        $record = $DB->get_record('quizaccess_ewa_sessions', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Return a site-specific HMAC secret derived from Moodle's password salt.
     *
     * @return string 32-byte secret.
     */
    private static function get_site_secret(): string {
        global $CFG;
        // Use Moodle's own secret as the base material.
        $base = isset($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : $CFG->wwwroot;
        return substr(hash('sha256', $base . 'quizaccess_ewa_lockdown'), 0, 32);
    }

    /**
     * URL-safe base64 encoding (no padding).
     *
     * @param string $data Raw bytes to encode.
     * @return string URL-safe base64 string.
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
