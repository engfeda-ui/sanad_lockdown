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
 * Language strings for quizaccess_sanad_lockdown.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin name.
$string['pluginname'] = 'Sanad Secure Browser access rule';

// Settings form strings.
$string['requiresanadlockdown'] = 'Require Sanad Secure Browser';
$string['requiresanadlockdown_help'] = 'When enabled, students must use the Sanad Secure Browser Android app to start this quiz. Access from regular browsers will be blocked. A QR code will be displayed for students to scan with the app.';
$string['tokenexpiry'] = 'Session token expiry (seconds)';
$string['tokenexpiry_help'] = 'How long a session token remains valid after the student scans the QR code. Default is 1800 seconds (30 minutes). Must be greater than or equal to the quiz time limit.';
$string['exitpassword'] = 'Emergency exit password';
$string['exitpassword_help'] = 'A password that supervisors can enter in the Sanad Secure Browser app to release the device from kiosk mode in case of emergency. Leave blank to disable emergency exit.';
$string['exitpassword_encrypted'] = 'Encrypted (Please edit quiz settings and enter a new plain text password to display it here)';
$string['regeneratepassword'] = 'Regenerate a new emergency exit password (random)';
$string['alloweddomains'] = 'Allowed domains / Whitelisted URLs';
$string['strictness'] = 'Security Lockdown Strictness Level';
$string['strictness_help'] = 'Choose the security enforcement level for this exam: Standard (basic kiosk & notification lock), High (neutralize shortcuts & URL whitelist), or Strict Exam (Full OS Device Owner lockdown & session timer).';
$string['strictness_standard'] = 'Standard (Basic Kiosk)';
$string['strictness_high'] = 'High Security (Shortcut & URL Filtering)';
$string['strictness_exam'] = 'Strict Exam Mode (Full OS Device Owner Lockdown)';

// Settings page strings.
$string['settings_desc'] = 'This page allows you to monitor connected devices, licenses, and active security violations in kiosk mode in real time.';
$string['settings_link'] = 'Open Kiosk Devices & License Monitor Dashboard (Admin only)';

// Preflight / access denied strings.
$string['accessdenied'] = 'Access Denied';
$string['mustusesanadapp'] = 'This quiz requires the Sanad Secure Browser app on Android. You cannot open it from a regular browser.';
$string['scanqrtostart'] = 'Scan the QR code below with the Sanad Secure Browser app to start the quiz:';
$string['qrcode_alttext'] = 'QR code to launch this quiz in Sanad Secure Browser';
$string['downloadapp'] = 'Don\'t have the app yet? Download Sanad Secure Browser from the Play Store.';
$string['tokenerror'] = 'Your session token is invalid or has expired. Please re-scan the QR code with the Sanad Secure Browser app.';
$string['tokenexpired'] = 'Your secure session has expired. Please ask your supervisor to re-issue the QR code.';
$string['requestfromteacher'] = 'Please ask your exam supervisor or teacher for the QR code to start the exam.';
$string['shortcode'] = 'Short Code';
$string['or_enter_shortcode'] = 'Or enter the short code if the camera fails';
$string['serverurl'] = 'Moodle Server URL';

// Violation log strings.
$string['violation_invalid_token'] = 'Invalid security token';
$string['violation_wrong_browser'] = 'Attempt from non-Sanad browser';
$string['violation_focus_lost'] = 'App lost focus during exam';
$string['violation_expired_token'] = 'Expired security token';

// Live monitor dashboard.
$string['plugindisabled']       = 'Sanad Lockdown is not enabled for this quiz.';
$string['monitor_title']        = 'Sanad Secure Browser — Live Monitor';
$string['monitor_link']         = 'Sanad Live Monitor';

// Stats bar.
$string['total_students']       = 'Total Students';
$string['stat_active']          = 'Active Sessions';
$string['stat_waiting']         = 'Waiting / No Session';
$string['stat_expired']         = 'Expired Sessions';
$string['stat_violations']      = 'Total Violations';

// Status badges.
$string['status_active']        = 'Active';
$string['status_waiting']       = 'Waiting';
$string['status_expired']       = 'Expired';

// Table column headers.
$string['student']              = 'Student';
$string['col_status']           = 'Session Status';
$string['col_device']           = 'Device ID';
$string['col_heartbeat']        = 'Last Heartbeat';
$string['col_expires']          = 'Expires At';
$string['col_violations']       = 'Violations';
$string['col_actions']          = 'Actions';

// Action buttons.
$string['action_revoke']        = 'Revoke';
$string['action_reissue']       = 'Issue QR';
$string['action_showqr']        = 'Show QR';
$string['show_violations']      = 'Show violation log';

// Confirmation dialogs.
$string['confirm_revoke']       = 'Are you sure you want to revoke this student\'s session? They will need a new QR code to continue.';

// Session actions feedback.
$string['session_revoked']      = 'Session revoked successfully.';
$string['session_reissued']     = 'New session token issued. Show the QR code to the student.';

// QR modal.
$string['qrmodal_title']        = 'Scan to Start Exam';
$string['qrmodal_hint']         = 'Ask the student to scan this QR code with the Sanad Secure Browser app.';
$string['qrmodal_fullscreen']   = 'Full Screen';

// Auto-refresh.
$string['next_refresh']         = 'Auto-refresh in {$a}s';
$string['close']                = 'Close';

// Time-ago strings.
$string['ago_seconds']          = '{$a}s ago';
$string['ago_minutes']          = '{$a}m ago';
$string['ago_hours']            = '{$a}h ago';

// Privacy.
$string['privacy:metadata:quizaccess_sanad_sessions'] = 'Stores secure exam session tokens issued to students.';
$string['privacy:metadata:quizaccess_sanad_sessions:userid'] = 'The user this session was issued for.';
$string['privacy:metadata:quizaccess_sanad_sessions:quizid'] = 'The quiz this session is for.';
$string['privacy:metadata:quizaccess_sanad_sessions:token'] = 'The HMAC security token (hashed).';
$string['privacy:metadata:quizaccess_sanad_sessions:deviceid'] = 'A fingerprint of the Android device used.';
$string['privacy:metadata:quizaccess_sanad_sessions:timecreated'] = 'When the session was created.';
$string['privacy:metadata:quizaccess_sanad_sessions:timeexpires'] = 'When the session token expires.';
$string['privacy:metadata:quizaccess_sanad_violations'] = 'Logs security violations detected during secure exams.';
$string['privacy:metadata:quizaccess_sanad_violations:userid'] = 'The user associated with the violation.';
$string['privacy:metadata:quizaccess_sanad_violations:quizid'] = 'The quiz during which the violation was detected.';
$string['privacy:metadata:quizaccess_sanad_violations:violationtype'] = 'The type of violation recorded.';
$string['privacy:metadata:quizaccess_sanad_violations:deviceid'] = 'The device fingerprint at time of violation.';
$string['privacy:metadata:quizaccess_sanad_violations:details'] = 'Additional context about the violation.';
$string['privacy:metadata:quizaccess_sanad_violations:timecreated'] = 'When the violation occurred.';

$string['exitpassword_newbtn'] = 'Generate new exit password';
$string['exitpassword_revealonce'] = 'Write this code down now - it will not be shown again.';
$string['exitpassword_regenerated'] = 'A new exit password has been generated and stored encrypted.';

$string['appsharedsecret'] = 'App shared secret (HMAC)';
$string['appsharedsecret_desc'] = 'Optional strong random secret. When set, kiosk API requests must include X-SANAD-TIMESTAMP and X-SANAD-SIGNATURE = HMAC-SHA256(timestamp, secret). Keep empty until the kiosk app supports signing.';
