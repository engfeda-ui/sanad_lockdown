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
 * Teacher monitoring dashboard for the EWA Lockdown plugin.
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
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use quizaccess_ewa_lockdown\token_manager;
use quizaccess_ewa_lockdown\qr_generator;
use quizaccess_ewa_lockdown\violation_logger;

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
$settings = $DB->get_record('quizaccess_ewa_lockdown', ['quizid' => $quiz->id]);
if (!$settings || !$settings->enabled) {
    redirect(
        new moodle_url('/mod/quiz/view.php', ['id' => $cmid]),
        get_string('plugindisabled', 'quizaccess_ewa_lockdown'),
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
        $notifymsg  = get_string('session_revoked', 'quizaccess_ewa_lockdown');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;

    } else if ($action === 'reissue' && $uid > 0) {
        // Re-issue a QR token for this student.
        $expiry  = (int)$settings->tokenexpiry;
        $newtoken = token_manager::issue($quiz->id, $uid, '', $expiry);
        $launchurl = token_manager::build_launch_url($quiz->id, $cmid, $newtoken);

        // Store launch URL in session so we can show the QR modal after redirect.
        $_SESSION['ewa_reissue_url']  = $launchurl;
        $_SESSION['ewa_reissue_uid']  = $uid;
        $_SESSION['ewa_reissue_quiz'] = $quiz->id;

        $notifymsg  = get_string('session_reissued', 'quizaccess_ewa_lockdown');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    }

    if (!$ajax) {
        redirect(new moodle_url('/mod/quiz/accessrule/ewa_lockdown/monitor.php', ['cmid' => $cmid]),
            $notifymsg ?? '', null, $notifytype ?? \core\output\notification::NOTIFY_INFO);
    }
}

// ── Data helpers ──────────────────────────────────────────────────────────────

/**
 * Return all enrolled students in this quiz's course.
 */
function ewa_get_enrolled_students(int $courseid, context_module $context): array {
    $users = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.firstname, u.lastname, u.email, u.picture', 'u.lastname ASC');
    return $users ?: [];
}

/**
 * Build the monitoring data row for a single student.
 */
function ewa_build_student_row(object $user, int $quizid, object $settings): array {
    global $DB;

    $now     = time();
    $session = $DB->get_record('quizaccess_ewa_sessions', ['quizid' => $quizid, 'userid' => $user->id]);

    // Session status.
    if (!$session) {
        $status    = 'waiting';    // No session yet.
        $expires   = null;
        $deviceid  = '';
        $lastheartbeat = null;
    } else if ($now > $session->timeexpires) {
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
        'quizaccess_ewa_violations',
        ['quizid' => $quizid, 'userid' => $user->id],
        'timecreated DESC',
        '*',
        0,
        50
    );
    $violationcount = count($violations);

    $mapped_violations = [];
    foreach ($violations as $v) {
        $mapped_violations[] = [
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
        $qrurl = token_manager::build_launch_url($quizid, 0, $session->token);
    }

    // Check re-issued QR in session.
    $reissuedurl = '';
    if (isset($_SESSION['ewa_reissue_uid']) && $_SESSION['ewa_reissue_uid'] == $user->id
        && isset($_SESSION['ewa_reissue_quiz']) && $_SESSION['ewa_reissue_quiz'] == $quizid) {
        $reissuedurl = $_SESSION['ewa_reissue_url'] ?? '';
        unset($_SESSION['ewa_reissue_url'], $_SESSION['ewa_reissue_uid'], $_SESSION['ewa_reissue_quiz']);
    }

    return [
        'user'           => $user,
        'status'         => $status,
        'expires'        => $expires,
        'deviceid'       => $deviceid,
        'lastheartbeat'  => $lastheartbeat,
        'violationcount' => $violationcount,
        'violations'     => $mapped_violations,
        'qrurl'          => $qrurl,
        'reissuedurl'    => $reissuedurl,
    ];
}

// ── AJAX mode: return JSON ────────────────────────────────────────────────────
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    $students = ewa_get_enrolled_students($course->id, $context);
    $rows = [];
    foreach ($students as $student) {
        $row = ewa_build_student_row($student, $quiz->id, $settings);
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
$students = ewa_get_enrolled_students($course->id, $context);
$studentrows = [];
foreach ($students as $student) {
    $studentrows[] = ewa_build_student_row($student, $quiz->id, $settings);
}

$activecnt  = count(array_filter($studentrows, fn($r) => $r['status'] === 'active'));
$waitingcnt = count(array_filter($studentrows, fn($r) => $r['status'] === 'waiting'));
$expiredcnt = count(array_filter($studentrows, fn($r) => $r['status'] === 'expired'));
$totalviolations = array_sum(array_column($studentrows, 'violationcount'));

// ── Moodle Page Setup ─────────────────────────────────────────────────────────
$PAGE->set_url('/mod/quiz/accessrule/ewa_lockdown/monitor.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('monitor_title', 'quizaccess_ewa_lockdown') . ' — ' . format_string($quiz->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('report');

// Add monitor JS module.
$PAGE->requires->js_call_amd('quizaccess_ewa_lockdown/monitor', 'init', [
    'cmid'        => $cmid,
    'sesskey'     => $sesskey,
    'refreshMs'   => 30000,
    'monitorUrl'  => (new moodle_url('/mod/quiz/accessrule/ewa_lockdown/monitor.php', ['cmid' => $cmid]))->out(false),
]);

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

// ── Breadcrumb / Back link ────────────────────────────────────────────────────
$quizviewurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
echo html_writer::tag('div',
    html_writer::link($quizviewurl, '← ' . get_string('backto', 'moodle', format_string($quiz->name)),
        ['class' => 'btn btn-sm btn-outline-secondary mb-3']),
    ['class' => 'ewa-monitor-back']
);

// ── Page title ────────────────────────────────────────────────────────────────
echo html_writer::tag('h2',
    '<i class="fa fa-desktop mr-2"></i>' . get_string('monitor_title', 'quizaccess_ewa_lockdown'),
    ['class' => 'ewa-monitor-h2']
);
echo html_writer::tag('p',
    format_string($quiz->name),
    ['class' => 'text-muted mb-4']
);

// ── Stats bar ─────────────────────────────────────────────────────────────────
echo html_writer::start_div('ewa-stats-bar mb-4');
echo ewa_stat_card(count($students),   get_string('total_students',     'quizaccess_ewa_lockdown'), 'ewa-stat-total', 'fa-users');
echo ewa_stat_card($activecnt,         get_string('stat_active',        'quizaccess_ewa_lockdown'), 'ewa-stat-active', 'fa-shield');
echo ewa_stat_card($waitingcnt,        get_string('stat_waiting',       'quizaccess_ewa_lockdown'), 'ewa-stat-waiting', 'fa-clock-o');
echo ewa_stat_card($expiredcnt,        get_string('stat_expired',       'quizaccess_ewa_lockdown'), 'ewa-stat-expired', 'fa-times-circle');
echo ewa_stat_card($totalviolations,   get_string('stat_violations',    'quizaccess_ewa_lockdown'), 'ewa-stat-violations', 'fa-exclamation-triangle');
echo html_writer::end_div();

// ── Auto-refresh notice ───────────────────────────────────────────────────────
echo html_writer::tag('div',
    '<i class="fa fa-refresh fa-spin mr-1" id="ewa-refresh-icon"></i>'
    . '<span id="ewa-refresh-countdown">' . get_string('next_refresh', 'quizaccess_ewa_lockdown', 30) . '</span>',
    ['class' => 'ewa-refresh-bar mb-3', 'id' => 'ewa-refresh-bar']
);

// ── Students table ────────────────────────────────────────────────────────────
echo html_writer::start_div('ewa-monitor-table-wrap');
echo html_writer::start_tag('table', ['class' => 'ewa-monitor-table table table-hover', 'id' => 'ewa-monitor-table']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('student',      'quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_status',   'quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_device',   'quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_heartbeat','quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_expires',  'quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_violations','quizaccess_ewa_lockdown'));
echo html_writer::tag('th', get_string('col_actions',  'quizaccess_ewa_lockdown'));
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody', ['id' => 'ewa-student-rows']);

foreach ($studentrows as $row) {
    echo ewa_render_student_row($row, $cmid, $quiz->id, $sesskey);
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// ── QR Modal (hidden, filled via JS) ─────────────────────────────────────────
echo ewa_qr_modal_html();

// ── Violation detail modal ────────────────────────────────────────────────────
echo ewa_violations_modal_html();

// ── Inline CSS ────────────────────────────────────────────────────────────────
echo ewa_inline_styles();

echo $OUTPUT->footer();

// ═══════════════════════════════════════════════════════════════════════════════
// Helper render functions
// ═══════════════════════════════════════════════════════════════════════════════

function ewa_stat_card(int $val, string $label, string $cls, string $icon): string {
    return html_writer::tag('div',
        html_writer::tag('div', '<i class="fa ' . $icon . '"></i>', ['class' => 'ewa-stat-icon'])
        . html_writer::tag('div', $val,   ['class' => 'ewa-stat-number'])
        . html_writer::tag('div', $label, ['class' => 'ewa-stat-label']),
        ['class' => "ewa-stat-card $cls"]
    );
}

function ewa_status_badge(string $status): string {
    $map = [
        'active'  => ['success', 'fa-check-circle',   get_string('status_active',  'quizaccess_ewa_lockdown')],
        'waiting' => ['warning', 'fa-hourglass-half', get_string('status_waiting', 'quizaccess_ewa_lockdown')],
        'expired' => ['danger',  'fa-times-circle',   get_string('status_expired', 'quizaccess_ewa_lockdown')],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['secondary', 'fa-question-circle', ucfirst($status)];
    return '<span class="ewa-badge ewa-badge-' . $cls . '" data-status="' . $status . '">'
         . '<i class="fa ' . $icon . ' mr-1"></i>' . $label . '</span>';
}

function ewa_render_student_row(array $row, int $cmid, int $quizid, string $sesskey): string {
    global $OUTPUT;
    $user       = $row['user'];
    $status     = $row['status'];
    $expires    = $row['expires'];
    $hb         = $row['lastheartbeat'];
    $deviceid   = $row['deviceid'];
    $violations = $row['violations'];
    $vcount     = $row['violationcount'];
    $qrurl      = $row['qrurl'];
    $reissued   = $row['reissuedurl'];

    $now = time();

    // ── Avatar + name ─────────────────────────────────────────────────────────
    $userpic = $OUTPUT->user_picture($user, ['size' => 36, 'link' => false]);
    $namelink = html_writer::link(
        new moodle_url('/user/view.php', ['id' => $user->id]),
        fullname($user),
        ['class' => 'ewa-student-name', 'target' => '_blank']
    );
    $td_student = html_writer::tag('td',
        html_writer::div($userpic . ' ' . $namelink, 'ewa-student-cell'),
        ['data-userid' => $user->id]
    );

    // ── Status ───────────────────────────────────────────────────────────────
    $td_status = html_writer::tag('td', ewa_status_badge($status), ['class' => 'ewa-td-status']);

    // ── Device ID ────────────────────────────────────────────────────────────
    $devshort = $deviceid ? '<code title="' . s($deviceid) . '">' . substr($deviceid, 0, 12) . '…</code>' : '<span class="text-muted">—</span>';
    $td_device = html_writer::tag('td', $devshort);

    // ── Last heartbeat ────────────────────────────────────────────────────────
    if ($hb) {
        $ago = $now - $hb;
        $hbcls = $ago > 120 ? 'text-danger' : 'text-success';
        $hbtext = '<span class="' . $hbcls . '">' . ewa_human_time_ago($ago) . '</span>';
    } else {
        $hbtext = '<span class="text-muted">—</span>';
    }
    $td_hb = html_writer::tag('td', $hbtext, ['class' => 'ewa-td-hb']);

    // ── Expires ───────────────────────────────────────────────────────────────
    if ($expires) {
        $expcls = ($now > $expires) ? 'text-danger' : 'text-success';
        $exptxt = '<span class="' . $expcls . '">' . userdate($expires, '%H:%M:%S') . '</span>';
    } else {
        $exptxt = '<span class="text-muted">—</span>';
    }
    $td_expires = html_writer::tag('td', $exptxt);

    // ── Violations ───────────────────────────────────────────────────────────
    if ($vcount > 0) {
        $violdata = htmlspecialchars(json_encode($violations), ENT_QUOTES);
        $vcls = $vcount >= 3 ? 'ewa-badge ewa-badge-danger' : 'ewa-badge ewa-badge-warning';
        $vbtn = '<button type="button" class="' . $vcls . ' ewa-btn-violations"'
              . ' data-userid="' . $user->id . '"'
              . ' data-name="' . s(fullname($user)) . '"'
              . ' data-violations=\'' . $violdata . '\''
              . ' title="' . get_string('show_violations', 'quizaccess_ewa_lockdown') . '">'
              . '<i class="fa fa-exclamation-triangle mr-1"></i>' . $vcount
              . '</button>';
    } else {
        $vbtn = '<span class="ewa-badge ewa-badge-success"><i class="fa fa-check mr-1"></i>0</span>';
    }
    $td_violations = html_writer::tag('td', $vbtn, ['class' => 'ewa-td-violations']);

    // ── Actions ───────────────────────────────────────────────────────────────
    $monurl = new moodle_url('/mod/quiz/accessrule/ewa_lockdown/monitor.php', ['cmid' => $cmid]);

    // Revoke button (only if active).
    $revokebtm = '';
    if ($status === 'active') {
        $revokeform = html_writer::start_tag('form', ['method' => 'post', 'action' => $monurl->out(false), 'class' => 'd-inline', 'onsubmit' => "return confirm('" . get_string('confirm_revoke', 'quizaccess_ewa_lockdown') . "')"])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'revoke'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uid',     'value' => $user->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',    'value' => $cmid])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey])
            . html_writer::tag('button', '<i class="fa fa-ban"></i> ' . get_string('action_revoke', 'quizaccess_ewa_lockdown'),
                ['type' => 'submit', 'class' => 'ewa-action-btn ewa-btn-danger btn btn-sm'])
            . html_writer::end_tag('form');
        $revokebtm = $revokeform;
    }

    // Re-issue QR button.
    $reissueform = html_writer::start_tag('form', ['method' => 'post', 'action' => $monurl->out(false), 'class' => 'd-inline'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'reissue'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uid',     'value' => $user->id])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',    'value' => $cmid])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey])
        . html_writer::tag('button', '<i class="fa fa-qrcode"></i> ' . get_string('action_reissue', 'quizaccess_ewa_lockdown'),
            ['type' => 'submit', 'class' => 'ewa-action-btn ewa-btn-primary btn btn-sm'])
        . html_writer::end_tag('form');

    // Show QR button (only if active session exists and QR url available).
    $showqrbtn = '';
    if ($qrurl || $reissued) {
        $targeturl = $reissued ?: $qrurl;
        $qrdata = qr_generator::get_data_uri($targeturl, 320);
        $showqrbtn = '<button type="button" class="ewa-action-btn ewa-btn-info btn btn-sm ewa-show-qr ml-1"'
            . ' data-qrb64="' . htmlspecialchars($qrdata) . '"'
            . ' data-name="' . s(fullname($user)) . '"'
            . ' data-qrurl="' . s($targeturl) . '">'
            . '<i class="fa fa-eye"></i> ' . get_string('action_showqr', 'quizaccess_ewa_lockdown')
            . '</button>';
    }

    $td_actions = html_writer::tag('td',
        html_writer::div($reissueform . $revokebtm . $showqrbtn, 'ewa-actions-cell'),
        ['class' => 'ewa-td-actions']
    );

    // ── Row class ─────────────────────────────────────────────────────────────
    $rowcls = 'ewa-student-row ewa-row-' . $status;
    if ($vcount >= 3) {
        $rowcls .= ' ewa-row-alert';
    }

    return html_writer::tag('tr',
        $td_student . $td_status . $td_device . $td_hb . $td_expires . $td_violations . $td_actions,
        ['class' => $rowcls, 'data-userid' => $user->id]
    );
}

function ewa_human_time_ago(int $seconds): string {
    if ($seconds < 60) {
        return get_string('ago_seconds', 'quizaccess_ewa_lockdown', $seconds);
    }
    if ($seconds < 3600) {
        return get_string('ago_minutes', 'quizaccess_ewa_lockdown', (int)($seconds / 60));
    }
    return get_string('ago_hours', 'quizaccess_ewa_lockdown', round($seconds / 3600, 1));
}

function ewa_qr_modal_html(): string {
    return '
<div id="ewa-qr-modal" class="ewa-modal" role="dialog" aria-modal="true" aria-label="QR Code" hidden>
  <div class="ewa-modal-backdrop"></div>
  <div class="ewa-modal-box">
    <div class="ewa-modal-header">
      <h4 id="ewa-qr-modal-title" class="ewa-modal-title"><i class="fa fa-qrcode mr-2"></i>' . get_string('qrmodal_title', 'quizaccess_ewa_lockdown') . '</h4>
      <button type="button" class="ewa-modal-close" id="ewa-qr-close" aria-label="Close">&times;</button>
    </div>
    <div class="ewa-modal-body text-center">
      <p id="ewa-qr-student-name" class="ewa-qr-student-name"></p>
      <div class="ewa-qr-container">
        <img id="ewa-qr-img" src="" alt="QR Code" class="ewa-qr-img" />
        <div class="ewa-qr-scan-line"></div>
      </div>
      <p class="ewa-qr-hint mt-3">' . get_string('qrmodal_hint', 'quizaccess_ewa_lockdown') . '</p>
      <code id="ewa-qr-url-text" class="ewa-qr-url-text"></code>
    </div>
    <div class="ewa-modal-footer">
      <button type="button" id="ewa-qr-fullscreen" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-expand mr-1"></i>' . get_string('qrmodal_fullscreen', 'quizaccess_ewa_lockdown') . '
      </button>
      <button type="button" id="ewa-qr-close-btn" class="btn btn-primary btn-sm">
        ' . get_string('close', 'quizaccess_ewa_lockdown') . '
      </button>
    </div>
  </div>
</div>';
}

function ewa_violations_modal_html(): string {
    return '
<div id="ewa-violations-modal" class="ewa-modal" role="dialog" aria-modal="true" hidden>
  <div class="ewa-modal-backdrop"></div>
  <div class="ewa-modal-box ewa-modal-wide">
    <div class="ewa-modal-header">
      <h4 class="ewa-modal-title"><i class="fa fa-exclamation-triangle mr-2 text-warning"></i><span id="ewa-violations-modal-title"></span></h4>
      <button type="button" class="ewa-modal-close" id="ewa-violations-close">&times;</button>
    </div>
    <div class="ewa-modal-body">
      <div id="ewa-violations-content"></div>
    </div>
    <div class="ewa-modal-footer">
      <button type="button" id="ewa-violations-close-btn" class="btn btn-secondary btn-sm">' . get_string('close', 'quizaccess_ewa_lockdown') . '</button>
    </div>
  </div>
</div>';
}

function ewa_inline_styles(): string {
    return <<<CSS
<style>
/* ─── EWA Monitor Dashboard ─────────────────────────────────── */
:root {
  --ewa-green:   #22c55e;
  --ewa-yellow:  #f59e0b;
  --ewa-red:     #ef4444;
  --ewa-blue:    #3b82f6;
  --ewa-gray:    #6b7280;
  --ewa-dark:    #1e293b;
  --ewa-surface: #f8fafc;
  --ewa-border:  #e2e8f0;
  --ewa-radius:  12px;
  --ewa-shadow:  0 4px 24px rgba(30,41,59,.10);
}

body { background: var(--ewa-surface); }

/* ── Stats bar ─────────────────────────────────────────────── */
.ewa-stats-bar {
  display: flex; gap: 16px; flex-wrap: wrap;
}
.ewa-stat-card {
  flex: 1 1 140px; min-width: 120px;
  background: #fff; border: 1px solid var(--ewa-border);
  border-radius: var(--ewa-radius); padding: 18px 20px;
  text-align: center; box-shadow: var(--ewa-shadow);
  transition: transform .18s, box-shadow .18s;
}
.ewa-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(30,41,59,.15); }
.ewa-stat-icon { font-size: 1.5rem; margin-bottom: 6px; }
.ewa-stat-number { font-size: 2rem; font-weight: 800; line-height: 1; }
.ewa-stat-label  { font-size: .78rem; color: var(--ewa-gray); margin-top: 4px; }

.ewa-stat-total      { border-top: 4px solid var(--ewa-blue);   }
.ewa-stat-total .ewa-stat-icon, .ewa-stat-total .ewa-stat-number { color: var(--ewa-blue); }
.ewa-stat-active     { border-top: 4px solid var(--ewa-green);  }
.ewa-stat-active .ewa-stat-icon, .ewa-stat-active .ewa-stat-number { color: var(--ewa-green); }
.ewa-stat-waiting    { border-top: 4px solid var(--ewa-yellow); }
.ewa-stat-waiting .ewa-stat-icon, .ewa-stat-waiting .ewa-stat-number { color: var(--ewa-yellow); }
.ewa-stat-expired    { border-top: 4px solid var(--ewa-red);    }
.ewa-stat-expired .ewa-stat-icon, .ewa-stat-expired .ewa-stat-number { color: var(--ewa-red); }
.ewa-stat-violations { border-top: 4px solid #a855f7; }
.ewa-stat-violations .ewa-stat-icon, .ewa-stat-violations .ewa-stat-number { color: #a855f7; }

/* ── Refresh bar ───────────────────────────────────────────── */
.ewa-refresh-bar {
  display: flex; align-items: center; gap: 8px;
  color: var(--ewa-gray); font-size: .85rem;
  background: #fff; border: 1px solid var(--ewa-border);
  border-radius: 8px; padding: 8px 16px;
}

/* ── Table ─────────────────────────────────────────────────── */
.ewa-monitor-table-wrap { overflow-x: auto; }
.ewa-monitor-table {
  width: 100%; border-collapse: separate; border-spacing: 0;
  background: #fff; border-radius: var(--ewa-radius);
  box-shadow: var(--ewa-shadow); overflow: hidden;
}
.ewa-monitor-table thead tr {
  background: var(--ewa-dark); color: #fff;
}
.ewa-monitor-table thead th {
  padding: 14px 16px; font-weight: 600; font-size: .82rem;
  letter-spacing: .04em; text-transform: uppercase;
  white-space: nowrap;
}
.ewa-monitor-table tbody tr {
  transition: background .15s;
}
.ewa-monitor-table tbody tr:hover { background: #f1f5f9; }
.ewa-monitor-table tbody tr + tr { border-top: 1px solid var(--ewa-border); }
.ewa-monitor-table td { padding: 12px 16px; vertical-align: middle; }

/* ── Row variants ──────────────────────────────────────────── */
.ewa-row-active  { }
.ewa-row-waiting { background: #fffbeb; }
.ewa-row-expired { background: #fef2f2; }
.ewa-row-alert   { border-left: 4px solid var(--ewa-red) !important; }

/* ── Student cell ──────────────────────────────────────────── */
.ewa-student-cell { display: flex; align-items: center; gap: 10px; }
.ewa-student-name { font-weight: 600; color: var(--ewa-dark); text-decoration: none; }
.ewa-student-name:hover { color: var(--ewa-blue); }

/* ── Badges ────────────────────────────────────────────────── */
.ewa-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: .78rem; font-weight: 600; padding: 4px 10px;
  border-radius: 999px; border: none; cursor: pointer;
  transition: opacity .15s;
}
.ewa-badge:hover { opacity: .85; }
.ewa-badge-success  { background: #dcfce7; color: #15803d; }
.ewa-badge-warning  { background: #fef9c3; color: #a16207; }
.ewa-badge-danger   { background: #fee2e2; color: #b91c1c; }
.ewa-badge-secondary{ background: #f1f5f9; color: var(--ewa-gray); }

/* ── Action buttons ────────────────────────────────────────── */
.ewa-actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
.ewa-action-btn { border-radius: 8px !important; font-size: .78rem !important; padding: 5px 10px !important; }
.ewa-btn-danger  { background: var(--ewa-red)  !important; color: #fff !important; border: none !important; }
.ewa-btn-primary { background: var(--ewa-blue) !important; color: #fff !important; border: none !important; }
.ewa-btn-info    { background: #6366f1 !important; color: #fff !important; border: none !important; }

/* ── Modals ────────────────────────────────────────────────── */
.ewa-modal {
  position: fixed; inset: 0; z-index: 9999;
  display: flex; align-items: center; justify-content: center;
}
.ewa-modal[hidden] { display: none; }
.ewa-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(15,23,42,.55); backdrop-filter: blur(3px);
}
.ewa-modal-box {
  position: relative; background: #fff;
  border-radius: 16px; box-shadow: 0 24px 64px rgba(0,0,0,.25);
  width: 90%; max-width: 460px; max-height: 90vh;
  overflow-y: auto; animation: ewaModalIn .22s ease;
}
.ewa-modal-wide { max-width: 680px; }
@keyframes ewaModalIn {
  from { transform: translateY(24px) scale(.96); opacity: 0; }
  to   { transform: none; opacity: 1; }
}
.ewa-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 20px 24px 16px; border-bottom: 1px solid var(--ewa-border);
}
.ewa-modal-title { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--ewa-dark); }
.ewa-modal-close {
  background: none; border: none; font-size: 1.5rem; line-height: 1;
  color: var(--ewa-gray); cursor: pointer; padding: 0 4px;
  transition: color .15s;
}
.ewa-modal-close:hover { color: var(--ewa-red); }
.ewa-modal-body { padding: 20px 24px; }
.ewa-modal-footer { padding: 16px 24px; border-top: 1px solid var(--ewa-border); display: flex; gap: 8px; justify-content: flex-end; }

/* ── QR modal specific ─────────────────────────────────────── */
.ewa-qr-student-name { font-size: 1.1rem; font-weight: 700; color: var(--ewa-dark); margin-bottom: 12px; }
.ewa-qr-container {
  position: relative; display: inline-block;
  border: 3px solid var(--ewa-blue); border-radius: 12px;
  padding: 10px; background: #fff; box-shadow: 0 8px 32px rgba(59,130,246,.15);
}
.ewa-qr-img { width: 280px; height: 280px; display: block; border-radius: 6px; }
.ewa-qr-scan-line {
  position: absolute; left: 10px; right: 10px; height: 3px;
  background: linear-gradient(90deg, transparent, var(--ewa-blue), transparent);
  animation: ewaScan 2s linear infinite; top: 10px; opacity: .7;
}
@keyframes ewaScan { 0%{top:10px} 100%{top:calc(100% - 10px)} }
.ewa-qr-hint { color: var(--ewa-gray); font-size: .85rem; }
.ewa-qr-url-text { font-size: .7rem; word-break: break-all; color: var(--ewa-gray); display: block; max-width: 100%; }

/* ── Violations table inside modal ─────────────────────────── */
.ewa-viol-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.ewa-viol-table th { background: var(--ewa-dark); color: #fff; padding: 8px 12px; }
.ewa-viol-table td { padding: 8px 12px; border-bottom: 1px solid var(--ewa-border); }
.ewa-viol-table tr:hover td { background: #f8fafc; }

/* ── Heading ───────────────────────────────────────────────── */
.ewa-monitor-h2 { color: var(--ewa-dark); font-weight: 800; font-size: 1.6rem; margin-bottom: 2px; }
</style>
CSS;
}
