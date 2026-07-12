<?php
// phpcs:ignoreFile
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
 * Teacher monitoring dashboard for the Sanad Lockdown plugin.
 *
 * Shows real-time session status of all enrolled students for a given quiz:
 *  - Active / Inactive / Expired session badges
 *  - Device fingerprint
 *  - Last heartbeat time
 *  - Violation count with breakdown
 *  - Per-student QR code re-issue
 *  - Revoke session button
 *  - Full violation log per student (expandable)
 *  - Auto-refresh every 30 seconds (AJAX)
 *
 * Access: mod/quiz:viewreports or mod/quiz:preview capability required.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use quizaccess_sanad_lockdown\token_manager;
use quizaccess_sanad_lockdown\qr_generator;
use quizaccess_sanad_lockdown\violation_logger;

// ── Parameters ───────────────────────────────────────────────────────────────
$cmid   = required_param('cmid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$uid    = optional_param('uid', 0, PARAM_INT);   // Target user for actions.
$ajax   = optional_param('ajax', 0, PARAM_INT);  // 1 = JSON response for auto-refresh.

// ── Context & Capability ─────────────────────────────────────────────────────
$cm      = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$quiz    = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/quiz:viewreports', $context);

// ── Check Plugin is enabled for this quiz ────────────────────────────────────
$settings = $DB->get_record('quizaccess_sanad_lockdown', ['quizid' => $quiz->id]);
if (!$settings || !$settings->enabled) {
    redirect(
        new moodle_url('/mod/quiz/view.php', ['id' => $cmid]),
        get_string('plugindisabled', 'quizaccess_sanad_lockdown'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// ── CSRF Token ───────────────────────────────────────────────────────────────
$sesskey = sesskey();

// ── Action handling (POST only) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    require_sesskey();

    if ($action === 'revoke' && $uid > 0) {
        token_manager::revoke($quiz->id, $uid);
        $notifymsg  = get_string('session_revoked', 'quizaccess_sanad_lockdown');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    } elseif ($action === 'reissue' && $uid > 0) {
        // Re-issue a QR token for this student.
        $expiry  = (int)$settings->tokenexpiry;
        $newtoken = token_manager::issue($quiz->id, $uid, '', $expiry);
        $launchurl = token_manager::build_launch_url($quiz->id, $cmid, $newtoken);

        // Store launch URL in session so we can show the QR modal after redirect.
        $_SESSION['sanad_reissue_url']  = $launchurl;
        $_SESSION['sanad_reissue_uid']  = $uid;
        $_SESSION['sanad_reissue_quiz'] = $quiz->id;

        $notifymsg  = get_string('session_reissued', 'quizaccess_sanad_lockdown');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    }

    if (!$ajax) {
        redirect(
            new moodle_url('/mod/quiz/accessrule/sanad_lockdown/monitor.php', ['cmid' => $cmid]),
            $notifymsg ?? '',
            null,
            $notifytype ?? \core\output\notification::NOTIFY_INFO
        );
    }
}

// ── Data helpers ──────────────────────────────────────────────────────────────

/**
 * Return all enrolled students in this quiz's course.
 */
function sanad_get_enrolled_students(int $courseid, context_module $context): array
{
    $users = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.firstname, u.lastname, u.email, u.picture', 'u.lastname ASC');
    return $users ?: [];
}

/**
 * Build the monitoring data row for a single student.
 */
function sanad_build_student_row(object $user, int $quizid, object $settings): array
{
    global $DB;

    $now     = time();
    $session = $DB->get_record('quizaccess_sanad_sessions', ['quizid' => $quizid, 'userid' => $user->id]);

    // Session status.
    if (!$session) {
        $status    = 'waiting';    // No session yet.
        $expires   = null;
        $deviceid  = '';
        $lastheartbeat = null;
    } elseif ($now > $session->timeexpires) {
        $status    = 'expired';
        $expires   = $session->timeexpires;
        $deviceid  = $session->deviceid ?? '';
        $lastheartbeat = $session->timeexpires;
    } else {
        $status    = 'active';
        $expires   = $session->timeexpires;
        $deviceid  = $session->deviceid ?? '';
        $lastheartbeat = $session->timeexpires - 120; // timeexpires is set to now+120 on heartbeat.
    }

    // Violations.
    $violations = $DB->get_records(
        'quizaccess_sanad_violations',
        ['quizid' => $quizid, 'userid' => $user->id],
        'timecreated DESC',
        '*',
        0,
        50
    );
    $violationcount = count($violations);

    $mappedviolations = [];
    foreach ($violations as $v) {
        $mappedviolations[] = [
            'type'        => $v->violationtype,
            'time'        => $v->timecreated,
            'time_human'  => userdate($v->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'deviceid'    => $v->deviceid ?? '',
            'details'     => $v->details ?? '',
        ];
    }

    // QR url for this student (only if active session exists and not expired).
    $qrurl = '';
    if ($status === 'active' && $session) {
        $cm = get_coursemodule_from_instance('quiz', $quizid);
        $cmidval = $cm ? (int)$cm->id : 0;
        $qrurl = token_manager::build_launch_url($quizid, $cmidval, $session->token);
    }

    // Check re-issued QR in session.
    $reissuedurl = '';
    if (
        isset($_SESSION['sanad_reissue_uid']) && $_SESSION['sanad_reissue_uid'] == $user->id
        && isset($_SESSION['sanad_reissue_quiz']) && $_SESSION['sanad_reissue_quiz'] == $quizid
    ) {
        $reissuedurl = $_SESSION['sanad_reissue_url'] ?? '';
        unset($_SESSION['sanad_reissue_url'], $_SESSION['sanad_reissue_uid'], $_SESSION['sanad_reissue_quiz']);
    }

    return [
        'user'           => $user,
        'status'         => $status,
        'expires'        => $expires,
        'deviceid'       => $deviceid,
        'lastheartbeat'  => $lastheartbeat,
        'violationcount' => $violationcount,
        'violations'     => $mappedviolations,
        'qrurl'          => $qrurl,
        'reissuedurl'    => $reissuedurl,
    ];
}

// ── AJAX mode: return JSON ────────────────────────────────────────────────────
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    $students = sanad_get_enrolled_students($course->id, $context);
    $rows = [];
    foreach ($students as $student) {
        $row = sanad_build_student_row($student, $quiz->id, $settings);
        $rows[] = [
            'userid'         => $student->id,
            'fullname'       => fullname($student),
            'status'         => $row['status'],
            'expires'        => $row['expires'],
            'lastheartbeat'  => $row['lastheartbeat'],
            'deviceid'       => $row['deviceid'] ? substr($row['deviceid'], 0, 12) . '...' : '',
            'violationcount' => $row['violationcount'],
            'violations'     => $row['violations'],
        ];
    }
    echo json_encode(['students' => $rows, 'servertime' => time()]);
    die();
}

// ── Build full page data ──────────────────────────────────────────────────────
$students = sanad_get_enrolled_students($course->id, $context);
$studentrows = [];
foreach ($students as $student) {
    $studentrows[] = sanad_build_student_row($student, $quiz->id, $settings);
}

$activecnt  = count(array_filter($studentrows, fn($r) => $r['status'] === 'active'));
$waitingcnt = count(array_filter($studentrows, fn($r) => $r['status'] === 'waiting'));
$expiredcnt = count(array_filter($studentrows, fn($r) => $r['status'] === 'expired'));
$totalviolations = array_sum(array_column($studentrows, 'violationcount'));

// ── Moodle Page Setup ─────────────────────────────────────────────────────────
$PAGE->set_url('/mod/quiz/accessrule/sanad_lockdown/monitor.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('monitor_title', 'quizaccess_sanad_lockdown') . ' — ' . format_string($quiz->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('report');

// Add monitor JS module.
$PAGE->requires->js_call_amd('quizaccess_sanad_lockdown/monitor', 'init', [
    'cmid'        => $cmid,
    'sesskey'     => $sesskey,
    'refreshMs'   => 30000,
    'monitorUrl'  => (new moodle_url('/mod/quiz/accessrule/sanad_lockdown/monitor.php', ['cmid' => $cmid]))->out(false),
]);

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

// ── Breadcrumb / Back link ────────────────────────────────────────────────────
$quizviewurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
echo html_writer::tag(
    'div',
    html_writer::link(
        $quizviewurl,
        '← ' . get_string('backto', 'moodle', format_string($quiz->name)),
        ['class' => 'btn btn-sm btn-outline-secondary mb-3']
    ),
    ['class' => 'sanad-monitor-back']
);

// ── Page title ────────────────────────────────────────────────────────────────
echo html_writer::tag(
    'h2',
    '<i class="fa fa-desktop mr-2"></i>' . get_string('monitor_title', 'quizaccess_sanad_lockdown'),
    ['class' => 'sanad-monitor-h2']
);
echo html_writer::tag(
    'p',
    format_string($quiz->name),
    ['class' => 'text-muted mb-4']
);

// ── Stats bar ─────────────────────────────────────────────────────────────────
echo html_writer::start_div('sanad-stats-bar mb-4');
echo sanad_stat_card(count($students), get_string('total_students', 'quizaccess_sanad_lockdown'), 'sanad-stat-total', 'fa-users');
echo sanad_stat_card($activecnt, get_string('stat_active', 'quizaccess_sanad_lockdown'), 'sanad-stat-active', 'fa-shield');
echo sanad_stat_card($waitingcnt, get_string('stat_waiting', 'quizaccess_sanad_lockdown'), 'sanad-stat-waiting', 'fa-clock-o');
echo sanad_stat_card($expiredcnt, get_string('stat_expired', 'quizaccess_sanad_lockdown'), 'sanad-stat-expired', 'fa-times-circle');
echo sanad_stat_card($totalviolations, get_string('stat_violations', 'quizaccess_sanad_lockdown'), 'sanad-stat-violations', 'fa-exclamation-triangle');
echo html_writer::end_div();

// ── Auto-refresh notice ───────────────────────────────────────────────────────
echo html_writer::tag(
    'div',
    '<i class="fa fa-refresh fa-spin mr-1" id="sanad-refresh-icon"></i>'
    . '<span id="sanad-refresh-countdown">' . get_string('next_refresh', 'quizaccess_sanad_lockdown', 30) . '</span>',
    ['class' => 'sanad-refresh-bar mb-3', 'id' => 'sanad-refresh-bar']
);

// ── Students table ────────────────────────────────────────────────────────────
echo html_writer::start_div('sanad-monitor-table-wrap');
echo html_writer::start_tag('table', ['class' => 'sanad-monitor-table table table-hover', 'id' => 'sanad-monitor-table']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('student', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_status', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_device', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_heartbeat', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_expires', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_violations', 'quizaccess_sanad_lockdown'));
echo html_writer::tag('th', get_string('col_actions', 'quizaccess_sanad_lockdown'));
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody', ['id' => 'sanad-student-rows']);

foreach ($studentrows as $row) {
    echo sanad_render_student_row($row, $cmid, $sesskey);
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// ── QR Modal (hidden, filled via JS) ─────────────────────────────────────────
echo sanad_qr_modal_html();

// ── Violation detail modal ────────────────────────────────────────────────────
echo sanad_violations_modal_html();

// ── Inline CSS ────────────────────────────────────────────────────────────────
echo sanad_inline_styles();

echo $OUTPUT->footer();

// ═══════════════════════════════════════════════════════════════════════════════
// Helper render functions
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render a statistics card.
 *
 * @param int    $val   The statistics value.
 * @param string $label The statistics label.
 * @param string $cls   CSS class selector name.
 * @param string $icon  FontAwesome icon class name.
 * @return string HTML statistics card block.
 */
function sanad_stat_card(int $val, string $label, string $cls, string $icon): string
{
    return html_writer::tag(
        'div',
        html_writer::tag('div', '<i class="fa ' . $icon . '"></i>', ['class' => 'sanad-stat-icon'])
        . html_writer::tag('div', $val, ['class' => 'sanad-stat-number'])
        . html_writer::tag('div', $label, ['class' => 'sanad-stat-label']),
        ['class' => "sanad-stat-card $cls"]
    );
}

/**
 * Render a status badge based on enrollment session status.
 *
 * @param string $status Current session status name.
 * @return string HTML status badge element.
 */
function sanad_status_badge(string $status): string
{
    $map = [
        'active'  => ['success', 'fa-check-circle',   get_string('status_active', 'quizaccess_sanad_lockdown')],
        'waiting' => ['warning', 'fa-hourglass-half', get_string('status_waiting', 'quizaccess_sanad_lockdown')],
        'expired' => ['danger',  'fa-times-circle',   get_string('status_expired', 'quizaccess_sanad_lockdown')],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['secondary', 'fa-question-circle', ucfirst($status)];
    return '<span class="sanad-badge sanad-badge-' . $cls . '" data-status="' . $status . '">'
         . '<i class="fa ' . $icon . ' mr-1"></i>' . $label . '</span>';
}

/**
 * Render a student row.
 *
 * @param array  $row      Student row data.
 * @param int    $cmid     Course module ID.
 * @param string $sesskey  Session key.
 * @return string HTML table row.
 */
function sanad_render_student_row(array $row, int $cmid, string $sesskey): string
{
    global $OUTPUT, $DB, $quiz;
    $user       = $row['user'];
    $status     = $row['status'];
    $expires    = $row['expires'];
    $hb         = $row['lastheartbeat'];
    $deviceid   = $row['deviceid'];
    $violations = $row['violations'];
    $vcount     = $row['violationcount'];
    $qrurl      = $row['qrurl'];
    $session    = $DB->get_record('quizaccess_sanad_sessions', ['quizid' => $quiz->id, 'userid' => $user->id]);
    $reissued   = $row['reissuedurl'];

    $now = time();

    // Avatar + name.
    $userpic = $OUTPUT->user_picture($user, ['size' => 36, 'link' => false]);
    $namelink = html_writer::link(
        new moodle_url('/user/view.php', ['id' => $user->id]),
        fullname($user),
        ['class' => 'sanad-student-name', 'target' => '_blank']
    );
    $tdstudent = html_writer::tag(
        'td',
        html_writer::div($userpic . ' ' . $namelink, 'sanad-student-cell'),
        ['data-userid' => $user->id]
    );

    // Status.
    $tdstatus = html_writer::tag('td', sanad_status_badge($status), ['class' => 'sanad-td-status']);

    // Device ID.
    $devshort = $deviceid ? '<code title="' . s($deviceid) . '">' . substr($deviceid, 0, 12) . '…</code>' : '<span class="text-muted">—</span>';
    $tddevice = html_writer::tag('td', $devshort);

    // Last heartbeat.
    if ($hb) {
        $ago = $now - $hb;
        $hbcls = $ago > 120 ? 'text-danger' : 'text-success';
        $hbtext = '<span class="' . $hbcls . '">' . sanad_human_time_ago($ago) . '</span>';
    } else {
        $hbtext = '<span class="text-muted">—</span>';
    }
    $tdhb = html_writer::tag('td', $hbtext, ['class' => 'sanad-td-hb']);

    // Expires.
    if ($expires) {
        $expcls = ($now > $expires) ? 'text-danger' : 'text-success';
        $exptxt = '<span class="' . $expcls . '">' . userdate($expires, '%H:%M:%S') . '</span>';
    } else {
        $exptxt = '<span class="text-muted">—</span>';
    }
    $tdexpires = html_writer::tag('td', $exptxt);

    // Violations.
    if ($vcount > 0) {
        $violdata = htmlspecialchars(json_encode($violations), ENT_QUOTES);
        $vcls = $vcount >= 3 ? 'sanad-badge sanad-badge-danger' : 'sanad-badge sanad-badge-warning';
        $vbtn = '<button type="button" class="' . $vcls . ' sanad-btn-violations"'
              . ' data-userid="' . $user->id . '"'
              . ' data-name="' . s(fullname($user)) . '"'
              . ' data-violations=\'' . $violdata . '\''
              . ' title="' . get_string('show_violations', 'quizaccess_sanad_lockdown') . '">'
              . '<i class="fa fa-exclamation-triangle mr-1"></i>' . $vcount
              . '</button>';
    } else {
        $vbtn = '<span class="sanad-badge sanad-badge-success"><i class="fa fa-check mr-1"></i>0</span>';
    }
    $tdviolations = html_writer::tag('td', $vbtn, ['class' => 'sanad-td-violations']);

    // Actions.
    $monurl = new moodle_url('/mod/quiz/accessrule/sanad_lockdown/monitor.php', ['cmid' => $cmid]);

    // Revoke button (only if active).
    $revokebtm = '';
    if ($status === 'active') {
        $revokeform = html_writer::start_tag(
            'form',
            [
                'method' => 'post',
                'action' => $monurl->out(false),
                'class' => 'd-inline',
                'onsubmit' => "return confirm('" . get_string('confirm_revoke', 'quizaccess_sanad_lockdown') . "')",
            ]
        )
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'revoke'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uid',     'value' => $user->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',    'value' => $cmid])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey])
            . html_writer::tag(
                'button',
                '<i class="fa fa-ban"></i> ' . get_string('action_revoke', 'quizaccess_sanad_lockdown'),
                ['type' => 'submit', 'class' => 'sanad-action-btn sanad-btn-danger btn btn-sm']
            )
            . html_writer::end_tag('form');
        $revokebtm = $revokeform;
    }

    // Re-issue QR button.
    $reissueform = html_writer::start_tag('form', ['method' => 'post', 'action' => $monurl->out(false), 'class' => 'd-inline'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'reissue'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uid',     'value' => $user->id])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',    'value' => $cmid])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey])
        . html_writer::tag(
            'button',
            '<i class="fa fa-qrcode"></i> ' . get_string('action_reissue', 'quizaccess_sanad_lockdown'),
            ['type' => 'submit', 'class' => 'sanad-action-btn sanad-btn-primary btn btn-sm']
        )
        . html_writer::end_tag('form');

    // Show QR button (only if active session exists and QR url available).
    $showqrbtn = '';
    if ($qrurl || $reissued) {
        $targeturl = $reissued ?: $qrurl;
        $qrdata = qr_generator::get_data_uri($targeturl, 320);

        // Get the short code for display.
        $shortcode = '';
        if ($session) {
            $shortcode = token_manager::get_short_code($session->token);
        } elseif ($reissued) {
            $urlparts = parse_url($reissued);
            if (isset($urlparts['query'])) {
                parse_str($urlparts['query'], $query);
                if (isset($query['sanadtoken'])) {
                    $shortcode = token_manager::get_short_code($query['sanadtoken']);
                }
            }
        }

        $showqrbtn = '<button type="button" class="sanad-action-btn sanad-btn-info btn btn-sm sanad-show-qr ml-1"'
            . ' data-qrb64="' . htmlspecialchars($qrdata) . '"'
            . ' data-name="' . s(fullname($user)) . '"'
            . ' data-shortcode="' . s($shortcode) . '"'
            . ' data-qrurl="' . s($targeturl) . '">'
            . '<i class="fa fa-eye"></i> ' . get_string('action_showqr', 'quizaccess_sanad_lockdown')
            . '</button>';
    }

    $tdactions = html_writer::tag(
        'td',
        html_writer::div($reissueform . $revokebtm . $showqrbtn, 'sanad-actions-cell'),
        ['class' => 'sanad-td-actions']
    );

    // Row class.
    $rowcls = 'sanad-student-row sanad-row-' . $status;
    if ($vcount >= 3) {
        $rowcls .= ' sanad-row-alert';
    }

    return html_writer::tag(
        'tr',
        $tdstudent . $tdstatus . $tddevice . $tdhb . $tdexpires . $tdviolations . $tdactions,
        ['class' => $rowcls, 'data-userid' => $user->id]
    );
}



/**
 * Get human readable time ago string.
 *
 * @param int $seconds Time in seconds.
 * @return string Time ago string.
 */
function sanad_human_time_ago(int $seconds): string
{
    if ($seconds < 60) {
        return get_string('ago_seconds', 'quizaccess_sanad_lockdown', $seconds);
    }
    if ($seconds < 3600) {
        return get_string('ago_minutes', 'quizaccess_sanad_lockdown', (int)($seconds / 60));
    }
    return get_string('ago_hours', 'quizaccess_sanad_lockdown', round($seconds / 3600, 1));
}

/**
 * Return QR modal HTML template.
 *
 * @return string HTML contents.
 */
function sanad_qr_modal_html(): string
{
    return '
<div id="sanad-qr-modal" class="sanad-modal" role="dialog" aria-modal="true" aria-label="QR Code" hidden>
  <div class="sanad-modal-backdrop"></div>
  <div class="sanad-modal-box">
    <div class="sanad-modal-header">
      <h4 id="sanad-qr-modal-title" class="sanad-modal-title"><i class="fa fa-qrcode mr-2"></i>' . get_string('qrmodal_title', 'quizaccess_sanad_lockdown') . '</h4>
      <button type="button" class="sanad-modal-close" id="sanad-qr-close" aria-label="Close">&times;</button>
    </div>
    <div class="sanad-modal-body text-center">
      <p id="sanad-qr-student-name" class="sanad-qr-student-name"></p>
      <div class="sanad-qr-container">
        <img id="sanad-qr-img" src="" alt="QR Code" class="sanad-qr-img" />
        <div class="sanad-qr-scan-line"></div>
      </div>
      <p id="sanad-qr-short-code" class="mt-2 text-center font-weight-bold" style="font-size: 1.25em;"></p>
      <p class="sanad-qr-hint mt-3">' . get_string('qrmodal_hint', 'quizaccess_sanad_lockdown') . '</p>
      <code id="sanad-qr-url-text" class="sanad-qr-url-text"></code>
    </div>
    <div class="sanad-modal-footer">
      <button type="button" id="sanad-qr-fullscreen" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-expand mr-1"></i>' . get_string('qrmodal_fullscreen', 'quizaccess_sanad_lockdown') . '
      </button>
      <button type="button" id="sanad-qr-close-btn" class="btn btn-primary btn-sm">
        ' . get_string('close', 'quizaccess_sanad_lockdown') . '
      </button>
    </div>
  </div>
</div>';
}

/**
 * Return violations modal HTML template.
 *
 * @return string HTML contents.
 */
function sanad_violations_modal_html(): string
{
    return '
<div id="sanad-violations-modal" class="sanad-modal" role="dialog" aria-modal="true" hidden>
  <div class="sanad-modal-backdrop"></div>
  <div class="sanad-modal-box sanad-modal-wide">
    <div class="sanad-modal-header">
      <h4 class="sanad-modal-title"><i class="fa fa-exclamation-triangle mr-2 text-warning"></i><span id="sanad-violations-modal-title"></span></h4>
      <button type="button" class="sanad-modal-close" id="sanad-violations-close">&times;</button>
    </div>
    <div class="sanad-modal-body">
      <div id="sanad-violations-content"></div>
    </div>
    <div class="sanad-modal-footer">
      <button type="button" id="sanad-violations-close-btn" class="btn btn-secondary btn-sm">' . get_string('close', 'quizaccess_sanad_lockdown') . '</button>
    </div>
  </div>
</div>';
}

/**
 * Return inline styles template.
 *
 * @return string CSS stylesheet tag.
 */
function sanad_inline_styles(): string
{
    return <<<CSS
<style>
/* ─── Sanad Monitor Dashboard ─────────────────────────────────── */
:root {
  --sanad-green:   #22c55e;
  --sanad-yellow:  #f59e0b;
  --sanad-red:     #ef4444;
  --sanad-blue:    #1a5296;
  --sanad-gray:    #6b7280;
  --sanad-dark:    #1e293b;
  --sanad-surface: #f8fafc;
  --sanad-border:  #e2e8f0;
  --sanad-radius:  12px;
  --sanad-shadow:  0 4px 24px rgba(30,41,59,.10);
}

body { background: var(--sanad-surface); }

/* ── Stats bar ─────────────────────────────────────────────── */
.sanad-stats-bar {
  display: flex; gap: 16px; flex-wrap: wrap;
}
.sanad-stat-card {
  flex: 1 1 140px; min-width: 120px;
  background: #fff; border: 1px solid var(--sanad-border);
  border-radius: var(--sanad-radius); padding: 18px 20px;
  text-align: center; box-shadow: var(--sanad-shadow);
  transition: transform .18s, box-shadow .18s;
}
.sanad-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(30,41,59,.15); }
.sanad-stat-icon { font-size: 1.5rem; margin-bottom: 6px; }
.sanad-stat-number { font-size: 2rem; font-weight: 800; line-height: 1; }
.sanad-stat-label  { font-size: .78rem; color: var(--sanad-gray); margin-top: 4px; }

.sanad-stat-total      { border-top: 4px solid var(--sanad-blue);   }
.sanad-stat-total .sanad-stat-icon, .sanad-stat-total .sanad-stat-number { color: var(--sanad-blue); }
.sanad-stat-active     { border-top: 4px solid var(--sanad-green);  }
.sanad-stat-active .sanad-stat-icon, .sanad-stat-active .sanad-stat-number { color: var(--sanad-green); }
.sanad-stat-waiting    { border-top: 4px solid var(--sanad-yellow); }
.sanad-stat-waiting .sanad-stat-icon, .sanad-stat-waiting .sanad-stat-number { color: var(--sanad-yellow); }
.sanad-stat-expired    { border-top: 4px solid var(--sanad-red);    }
.sanad-stat-expired .sanad-stat-icon, .sanad-stat-expired .sanad-stat-number { color: var(--sanad-red); }
.sanad-stat-violations { border-top: 4px solid #a855f7; }
.sanad-stat-violations .sanad-stat-icon, .sanad-stat-violations .sanad-stat-number { color: #a855f7; }

/* ── Refresh bar ───────────────────────────────────────────── */
.sanad-refresh-bar {
  display: flex; align-items: center; gap: 8px;
  color: var(--sanad-gray); font-size: .85rem;
  background: #fff; border: 1px solid var(--sanad-border);
  border-radius: 8px; padding: 8px 16px;
}

/* ── Table ─────────────────────────────────────────────────── */
.sanad-monitor-table-wrap { overflow-x: auto; }
.sanad-monitor-table {
  width: 100%; border-collapse: separate; border-spacing: 0;
  background: #fff; border-radius: var(--sanad-radius);
  box-shadow: var(--sanad-shadow); overflow: hidden;
}
.sanad-monitor-table thead tr {
  background: var(--sanad-dark); color: #fff;
}
.sanad-monitor-table thead th {
  padding: 14px 16px; font-weight: 600; font-size: .82rem;
  letter-spacing: .04em; text-transform: uppercase;
  white-space: nowrap;
}
.sanad-monitor-table tbody tr {
  transition: background .15s;
}
.sanad-monitor-table tbody tr:hover { background: #f1f5f9; }
.sanad-monitor-table tbody tr + tr { border-top: 1px solid var(--sanad-border); }
.sanad-monitor-table td { padding: 12px 16px; vertical-align: middle; }

/* ── Row variants ──────────────────────────────────────────── */
.sanad-row-active  { }
.sanad-row-waiting { background: #fffbeb; }
.sanad-row-expired { background: #fef2f2; }
.sanad-row-alert   { border-left: 4px solid var(--sanad-red) !important; }

/* ── Student cell ──────────────────────────────────────────── */
.sanad-student-cell { display: flex; align-items: center; gap: 10px; }
.sanad-student-name { font-weight: 600; color: var(--sanad-dark); text-decoration: none; }
.sanad-student-name:hover { color: var(--sanad-blue); }

/* ── Badges ────────────────────────────────────────────────── */
.sanad-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: .78rem; font-weight: 600; padding: 4px 10px;
  border-radius: 999px; border: none; cursor: pointer;
  transition: opacity .15s;
}
.sanad-badge:hover { opacity: .85; }
.sanad-badge-success  { background: #dcfce7; color: #15803d; }
.sanad-badge-warning  { background: #fef9c3; color: #a16207; }
.sanad-badge-danger   { background: #fee2e2; color: #b91c1c; }
.sanad-badge-secondary{ background: #f1f5f9; color: var(--sanad-gray); }

/* ── Action buttons ────────────────────────────────────────── */
.sanad-actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
.sanad-action-btn { border-radius: 8px !important; font-size: .78rem !important; padding: 5px 10px !important; }
.sanad-btn-danger  { background: var(--sanad-red)  !important; color: #fff !important; border: none !important; }
.sanad-btn-primary { background: var(--sanad-blue) !important; color: #fff !important; border: none !important; }
.sanad-btn-info    { background: #6366f1 !important; color: #fff !important; border: none !important; }

/* ── Modals ────────────────────────────────────────────────── */
.sanad-modal {
  position: fixed; inset: 0; z-index: 9999;
  display: flex; align-items: center; justify-content: center;
}
.sanad-modal[hidden] { display: none; }
.sanad-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(15,23,42,.55); backdrop-filter: blur(3px);
}
.sanad-modal-box {
  position: relative; background: #fff;
  border-radius: 16px; box-shadow: 0 24px 64px rgba(0,0,0,.25);
  width: 90%; max-width: 460px; max-height: 90vh;
  overflow-y: auto; animation: sanadModalIn .22s ease;
}
.sanad-modal-wide { max-width: 680px; }
@keyframes sanadModalIn {
  from { transform: translateY(24px) scale(.96); opacity: 0; }
  to   { transform: none; opacity: 1; }
}
.sanad-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 20px 24px 16px; border-bottom: 1px solid var(--sanad-border);
}
.sanad-modal-title { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--sanad-dark); }
.sanad-modal-close {
  background: none; border: none; font-size: 1.5rem; line-height: 1;
  color: var(--sanad-gray); cursor: pointer; padding: 0 4px;
  transition: color .15s;
}
.sanad-modal-close:hover { color: var(--sanad-red); }
.sanad-modal-body { padding: 20px 24px; }
.sanad-modal-footer { padding: 16px 24px; border-top: 1px solid var(--sanad-border); display: flex; gap: 8px; justify-content: flex-end; }

/* ── QR modal specific ─────────────────────────────────────── */
.sanad-qr-student-name { font-size: 1.1rem; font-weight: 700; color: var(--sanad-dark); margin-bottom: 12px; }
.sanad-qr-container {
  position: relative; display: inline-block;
  border: 3px solid var(--sanad-blue); border-radius: 12px;
  padding: 10px; background: #fff; box-shadow: 0 8px 32px rgba(59,130,246,.15);
}
.sanad-qr-img { width: 280px; height: 280px; display: block; border-radius: 6px; }
.sanad-qr-scan-line {
  position: absolute; left: 10px; right: 10px; height: 3px;
  background: linear-gradient(90deg, transparent, var(--sanad-blue), transparent);
  animation: sanadScan 2s linear infinite; top: 10px; opacity: .7;
}
@keyframes sanadScan { 0%{top:10px} 100%{top:calc(100% - 10px)} }
.sanad-qr-hint { color: var(--sanad-gray); font-size: .85rem; }
.sanad-qr-url-text { font-size: .7rem; word-break: break-all; color: var(--sanad-gray); display: block; max-width: 100%; }

/* ── Violations table inside modal ─────────────────────────── */
.sanad-viol-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.sanad-viol-table th { background: var(--sanad-dark); color: #fff; padding: 8px 12px; }
.sanad-viol-table td { padding: 8px 12px; border-bottom: 1px solid var(--sanad-border); }
.sanad-viol-table tr:hover td { background: #f8fafc; }

/* ── Heading ───────────────────────────────────────────────── */
.sanad-monitor-h2 { color: var(--sanad-dark); font-weight: 800; font-size: 1.6rem; margin-bottom: 2px; }
</style>
CSS;
}
