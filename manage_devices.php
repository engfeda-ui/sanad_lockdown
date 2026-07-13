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
 * Admin Kiosk Control Panel and Device Manager.
 *
 * @package   quizaccess_sanad_lockdown
 * @copyright 2026 Mahmoud Salem <eng.feda@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// 1. Authenticate and authorize admin access.
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Set up page.
$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/sanad_lockdown/manage_devices.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'quizaccess_sanad_lockdown') . ' - Device Manager');
$PAGE->set_heading('Sanad Kiosk Admin Control Center');

global $DB, $OUTPUT, $PAGE;

// 2. Handle Actions (Approve, Block, Delete, Expiry, Reset Password)
$action = optional_param('action', '', PARAM_ALPHAEXT);
$id     = optional_param('id', 0, PARAM_INT);
$msg    = '';
$msgtype = 'success'; // 'success' or 'error'

if (!empty($action) && confirm_sesskey()) {
    try {
        if ($action === 'approve') {
            $device = $DB->get_record('quizaccess_sanad_devices', ['id' => $id], '*', MUST_EXIST);
            $device->status = 1; // Active
            $device->expirydate = time() + (365 * 86400); // 1 Year Default
            $device->timemodified = time();
            $DB->update_record('quizaccess_sanad_devices', $device);
            $msg = "تم تفعيل الجهاز {$device->hardwareid} بنجاح لمدة عام!";
        } elseif ($action === 'block') {
            $device = $DB->get_record('quizaccess_sanad_devices', ['id' => $id], '*', MUST_EXIST);
            $device->status = 2; // Suspended
            $device->timemodified = time();
            $DB->update_record('quizaccess_sanad_devices', $device);
            $msg = "تم حظر وتجميد ترخيص الجهاز {$device->hardwareid} بنجاح.";
        } elseif ($action === 'delete') {
            $device = $DB->get_record('quizaccess_sanad_devices', ['id' => $id], '*', MUST_EXIST);
            $DB->delete_records('quizaccess_sanad_devices', ['id' => $id]);
            $msg = "تم حذف الجهاز {$device->hardwareid} نهائياً من النظام.";
        } elseif ($action === 'extend') {
            $days = required_param('days', PARAM_INT);
            $device = $DB->get_record('quizaccess_sanad_devices', ['id' => $id], '*', MUST_EXIST);
            $device->expirydate = time() + ($days * 86400);
            $device->status = 1; // Ensure active
            $device->timemodified = time();
            $DB->update_record('quizaccess_sanad_devices', $device);
            $msg = "تم تمديد ترخيص الجهاز {$device->hardwareid} إلى {$days} يوماً.";
        } elseif ($action === 'resetpass') {
            $userid = required_param('userid', PARAM_INT);
            $newpassword = required_param('newpassword', PARAM_RAW);
            if (strlen($newpassword) < 6) {
                throw new moodle_exception('errorpasswordlength', 'quizaccess_sanad_lockdown', '', null, 'يجب ألا تقل كلمة المرور عن 6 خانات.');
            }
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

            // Set password
            $user->password = password_hash($newpassword, PASSWORD_DEFAULT);
            $user->timemodified = time();
            $DB->update_record('user', $user);

            // Clear cache and log user out of active web sessions to force re-auth
            \core_user::update_user($user);
            $msg = "تم بنجاح تغيير كلمة مرور الطالب ({$user->firstname} {$user->lastname}) إلى الكلمة الجديدة.";
        }
    } catch (Exception $e) {
        $msg = "حدث خطأ: " . $e->getMessage();
        $msgtype = 'error';
    }
}

// 3. Retrieve Dashboard Stats & Lists.
$totaldevices = $DB->count_records('quizaccess_sanad_devices');
$activedevices = $DB->count_records_select('quizaccess_sanad_devices', 'status = 1 AND expirydate > :now', ['now' => time()]);
$pendingdevices = $DB->count_records('quizaccess_sanad_devices', ['status' => 0]);
$activeexams = $DB->count_records_select('quizaccess_sanad_sessions', 'timeexpires > :now', ['now' => time()]);

// Get all devices.
$devices = $DB->get_records('quizaccess_sanad_devices', null, 'timecreated DESC');

// Get active exam sessions with details.
$sqlsessions = "
    SELECT s.id, s.token, s.deviceid, s.timecreated, s.timeexpires,
           u.id AS userid, u.firstname, u.lastname, u.email,
           q.id AS quizid, q.name AS quizname
      FROM {quizaccess_sanad_sessions} s
      JOIN {user} u ON u.id = s.userid
      JOIN {quiz} q ON q.id = s.quizid
     WHERE s.timeexpires > :now
  ORDER BY s.timecreated DESC";
$activesessions = $DB->get_records_sql($sqlsessions, ['now' => time()]);

// Get recent violations.
$sqlviolations = "
    SELECT v.id, v.violationtype, v.deviceid, v.timecreated, v.details,
           u.firstname, u.lastname, u.email,
           q.name AS quizname
      FROM {quizaccess_sanad_violations} v
      JOIN {user} u ON u.id = v.userid
      JOIN {quiz} q ON q.id = v.quizid
  ORDER BY v.timecreated DESC
     LIMIT 10";
$violations = $DB->get_records_sql($sqlviolations);

// Start rendering page.
echo $OUTPUT->header();
?>

<!-- Premium UI Stylesheet Override -->
<style>
    :root {
        --dash-primary: #0f172a;
        --dash-secondary: #1e293b;
        --dash-accent: #1a5296;
        --dash-accent-hover: #0f3e7a;
        --dash-success: #10b981;
        --dash-danger: #ef4444;
        --dash-warning: #f59e0b;
        --dash-card-bg: rgba(30, 41, 59, 0.7);
        --dash-glass-border: rgba(255, 255, 255, 0.08);
        --dash-text: #e2e8f0;
        --dash-text-muted: #94a3b8;
    }

    #page-content {
        background: linear-gradient(135deg, #020617 0%, #0f172a 100%) !important;
        color: var(--dash-text) !important;
        font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .sanad-container {
        padding: 20px;
        max-width: 1300px;
        margin: 0 auto;
    }

    .sanad-title {
        font-weight: 700;
        color: var(--dash-accent);
        margin-bottom: 25px;
        font-size: 26px;
        border-right: 4px solid var(--dash-accent);
        padding-right: 15px;
    }

    /* Message Alert */
    .sanad-alert {
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideDown 0.3s ease;
    }
    .sanad-alert-success {
        background-color: rgba(16, 185, 129, 0.15);
        border: 1px solid var(--dash-success);
        color: #34d399;
    }
    .sanad-alert-error {
        background-color: rgba(239, 68, 68, 0.15);
        border: 1px solid var(--dash-danger);
        color: #f87171;
    }

    @keyframes slideDown {
        from { transform: translateY(-10px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Metric Cards Grid */
    .sanad-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .sanad-metric-card {
        background: var(--dash-card-bg);
        border: 1px solid var(--dash-glass-border);
        border-radius: 16px;
        padding: 24px;
        backdrop-filter: blur(12px);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .sanad-metric-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    }
    .sanad-metric-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        right: 0;
        width: 60px;
        height: 60px;
        background: radial-gradient(circle, var(--dash-accent) 0%, transparent 70%);
        opacity: 0.15;
        border-radius: 50%;
    }

    .sanad-metric-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--dash-text-muted);
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .sanad-metric-value {
        font-size: 32px;
        font-weight: 800;
        color: var(--dash-text);
        line-height: 1;
    }

    /* Primary and secondary layouts */
    .sanad-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 25px;
    }

    @media (max-width: 992px) {
        .sanad-layout {
            grid-template-columns: 1fr;
        }
    }

    .sanad-card {
        background: var(--dash-card-bg);
        border: 1px solid var(--dash-glass-border);
        border-radius: 16px;
        padding: 25px;
        backdrop-filter: blur(12px);
        margin-bottom: 25px;
    }

    .sanad-card-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dash-text);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Table Styles */
    .sanad-table-container {
        overflow-x: auto;
    }

    .sanad-table {
        width: 100%;
        border-collapse: collapse;
        text-align: right;
    }

    .sanad-table th {
        background-color: var(--dash-secondary);
        color: var(--dash-text);
        padding: 14px 16px;
        font-weight: 700;
        font-size: 13px;
        border-bottom: 2px solid var(--dash-glass-border);
    }

    .sanad-table td {
        padding: 14px 16px;
        font-size: 14px;
        color: var(--dash-text);
        border-bottom: 1px solid var(--dash-glass-border);
    }

    .sanad-table tr:hover {
        background-color: rgba(255, 255, 255, 0.02);
    }

    /* Status Badges */
    .sanad-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        display: inline-block;
    }
    .sanad-badge-pending {
        background-color: rgba(245, 158, 11, 0.15);
        color: var(--dash-warning);
        border: 1px solid var(--dash-warning);
    }
    .sanad-badge-active {
        background-color: rgba(16, 185, 129, 0.15);
        color: var(--dash-success);
        border: 1px solid var(--dash-success);
    }
    .sanad-badge-suspended {
        background-color: rgba(239, 68, 68, 0.15);
        color: var(--dash-danger);
        border: 1px solid var(--dash-danger);
    }

    /* Controls & Buttons */
    .sanad-btn {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .sanad-btn-primary {
        background-color: var(--dash-accent);
        color: #ffffff !important;
    }
    .sanad-btn-primary:hover {
        background-color: var(--dash-accent-hover);
    }
    .sanad-btn-success {
        background-color: var(--dash-success);
        color: #ffffff !important;
    }
    .sanad-btn-success:hover {
        background-color: #059669;
    }
    .sanad-btn-danger {
        background-color: var(--dash-danger);
        color: #ffffff !important;
    }
    .sanad-btn-danger:hover {
        background-color: #dc2626;
    }
    .sanad-btn-warning {
        background-color: var(--dash-warning);
        color: #ffffff !important;
    }
    .sanad-btn-warning:hover {
        background-color: #d97706;
    }
    .sanad-btn-icon {
        padding: 6px 8px;
    }

    /* Password Reset Sidebar Form */
    .reset-form {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .reset-form input, .reset-form select {
        background-color: var(--dash-secondary);
        border: 1px solid var(--dash-glass-border);
        color: var(--dash-text);
        padding: 12px;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
    }
    .reset-form input:focus {
        border-color: var(--dash-accent);
    }

    /* Search Box styling */
    .search-box {
        width: 100%;
        margin-bottom: 20px;
        padding: 10px 14px;
        background-color: var(--dash-secondary);
        border: 1px solid var(--dash-glass-border);
        border-radius: 8px;
        color: var(--dash-text);
    }

    /* Live Monitoring Row style */
    .live-pulse {
        display: inline-block;
        width: 10px;
        height: 10px;
        background-color: var(--dash-success);
        border-radius: 50%;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
</style>

<div class="sanad-container" dir="rtl">
    <div class="sanad-title">🔑 لوحة تفعيل الأجهزة ومراقبة الاختبارات (Kiosk Admin)</div>

    <!-- Notifications Alert -->
    <?php if (!empty($msg)) : ?>
        <div class="sanad-alert sanad-alert-<?php echo $msgtype; ?>">
            <span><?php echo ($msgtype === 'success' ? '✅' : '❌'); ?></span>
            <span><?php echo s($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Dashboard Stat Cards -->
    <div class="sanad-metrics-grid">
        <div class="sanad-metric-card">
            <div class="sanad-metric-title">الأجهزة المسجلة</div>
            <div class="sanad-metric-value"><?php echo $totaldevices; ?></div>
        </div>
        <div class="sanad-metric-card">
            <div class="sanad-metric-title">الرخص النشطة حالياً</div>
            <div class="sanad-metric-value" style="color: #34d399;"><?php echo $activedevices; ?></div>
        </div>
        <div class="sanad-metric-card">
            <div class="sanad-metric-title">أجهزة بانتظار التفعيل</div>
            <div class="sanad-metric-value" style="color: #fbbf24;"><?php echo $pendingdevices; ?></div>
        </div>
        <div class="sanad-metric-card">
            <div class="sanad-metric-title">الطلاب في الامتحانات الآن</div>
            <div class="sanad-metric-value" style="color: #22d3ee;"><?php echo $activeexams; ?></div>
        </div>
    </div>

    <!-- Main Layout Grid -->
    <div class="sanad-layout">
        
        <!-- Right Panel: Device List -->
        <div>
            <div class="sanad-card">
                <div class="sanad-card-title">
                    <span>📱 قائمة أجهزة التابلت المسجلة</span>
                </div>
                
                <!-- Simple live filter -->
                <input type="text" id="deviceSearch" class="search-box" placeholder="ابحث برمز الجهاز (Hardware ID) أو نوع الموديل..." onkeyup="filterDevices()">

                <div class="sanad-table-container">
                    <table class="sanad-table" id="deviceTable">
                        <thead>
                            <tr>
                                <th>الشركة / الموديل</th>
                                <th>رمز الجهاز (Hardware ID)</th>
                                <th>حالة التفعيل</th>
                                <th>تاريخ الانتهاء</th>
                                <th>الإجراءات السريعة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($devices)) : ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--dash-text-muted);">لا توجد أجهزة مسجلة في قاعدة البيانات بعد. قم بتشغيل التطبيق على التابلت ليتم تسجيله تلقائياً.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($devices as $d) : ?>
                                    <?php
                                        $formattedid = implode('-', str_split($d->hardwareid, 4));

                                        // Expiration string.
                                        $expirystr = 'غير محدد';
                                        $expired = false;
                                    if ($d->expirydate > 0) {
                                        $expirystr = userdate($d->expirydate, '%d-%m-%Y');
                                        if ($d->expirydate <= time()) {
                                            $expired = true;
                                        }
                                    }

                                        // Badge class.
                                    if ($d->status == 1) {
                                        $badgeclass = $expired ? 'suspended' : 'active';
                                        $badgelabel = $expired ? 'منتهية الصلاحية' : 'نشط';
                                    } elseif ($d->status == 2) {
                                        $badgeclass = 'suspended';
                                        $badgelabel = 'محظور';
                                    } else {
                                        $badgeclass = 'pending';
                                        $badgelabel = 'قيد الانتظار';
                                    }
                                    ?>
                                    <tr class="device-row">
                                        <td>
                                            <strong style="color: var(--dash-text);"><?php echo s($d->devicebrand); ?></strong><br>
                                            <span style="font-size: 12px; color: var(--dash-text-muted);"><?php echo s($d->devicemodel); ?></span>
                                        </td>
                                        <td style="font-family: monospace; font-size: 15px; font-weight: 700; color: var(--dash-accent);"><?php echo $formattedid; ?></td>
                                        <td>
                                            <span class="sanad-badge sanad-badge-<?php echo $badgeclass; ?>"><?php echo $badgelabel; ?></span>
                                        </td>
                                        <td>
                                            <span style="<?php echo $expired ? 'color: var(--dash-danger); font-weight: 700;' : ''; ?>">
                                                <?php echo $expirystr; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($d->status == 0 || $expired) : ?>
                                                <a href="?action=approve&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="sanad-btn sanad-btn-success">تفعيل الرخصه</a>
                                            <?php endif; ?>

                                            <?php if ($d->status == 1 && !$expired) : ?>
                                                <a href="?action=block&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="sanad-btn sanad-btn-warning">تعطيل وحظر</a>
                                            <?php endif; ?>

                                            <?php if ($d->status == 2) : ?>
                                                <a href="?action=approve&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="sanad-btn sanad-btn-success">إلغاء الحظر</a>
                                            <?php endif; ?>

                                            <!-- Dropdown or quick extend of 1 year -->
                                            <a href="?action=extend&id=<?php echo $d->id; ?>&days=365&sesskey=<?php echo sesskey(); ?>" class="sanad-btn sanad-btn-primary" title="تجديد سنة">+ سنة</a>
                                            
                                            <a href="?action=delete&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="sanad-btn sanad-btn-danger sanad-btn-icon" onclick="return confirm('هل أنت متأكد من حذف هذا الجهاز نهائياً؟');" title="حذف">🗑️</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Active Exam Monitor Grid -->
            <div class="sanad-card">
                <div class="sanad-card-title">
                    <span>
                        <span class="live-pulse"></span>
                        مراقبة جلسات الاختبارات النشطة الآن
                    </span>
                    <span style="font-size: 13px; color: var(--dash-text-muted);">تحديث فوري لكل الأجهزة المتصلة بالاختبارات</span>
                </div>

                <div class="sanad-table-container">
                    <table class="sanad-table">
                        <thead>
                            <tr>
                                <th>اسم الطالب</th>
                                <th>كود التابلت المستعمل</th>
                                <th>الاختبار الحالي</th>
                                <th>ساعة بدء الدخول</th>
                                <th>توقيت انتهاء الجلسة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activesessions)) : ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--dash-text-muted); padding: 20px;">لا يوجد أي طالب يؤدي امتحاناً في الوقت الحالي.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($activesessions as $s) : ?>
                                    <?php
                                        $formatteddevice = $s->deviceid ? implode('-', str_split($s->deviceid, 4)) : 'غير مسجل (قديم)';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo s($s->firstname . ' ' . $s->lastname); ?></strong><br>
                                            <span style="font-size: 11px; color: var(--dash-text-muted);"><?php echo s($s->email); ?></span>
                                        </td>
                                        <td style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--dash-accent);"><?php echo $formatteddevice; ?></td>
                                        <td><strong><?php echo s($s->quizname); ?></strong></td>
                                        <td><?php echo userdate($s->timecreated, '%H:%M:%S (%d-%m-%Y)'); ?></td>
                                        <td style="color: var(--dash-success); font-weight: 700;"><?php echo userdate($s->timeexpires, '%H:%M:%S'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Left Sidebar: Tools Panel -->
        <div>
            
            <!-- Quick Password Reset Tools Card -->
            <div class="sanad-card">
                <div class="sanad-card-title">🔐 إدارة وتغيير كلمة مرور طالب</div>
                
                <form action="" method="post" class="reset-form">
                    <input type="hidden" name="action" value="resetpass">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--dash-text-muted);">1. حدد الطالب من القائمة:</label>
                        <select name="userid" required style="width: 100%;">
                            <option value="">-- اختر الطالب --</option>
                            <?php
                                // Fetch all students (role student) or simply all active users (since they are only students on this site).
                                $allstudents = $DB->get_records_select('user', 'id > 2 AND suspended = 0 AND deleted = 0', [], 'firstname ASC', 'id,firstname,lastname,email');
                            foreach ($allstudents as $student) {
                                echo "<option value=\"{$student->id}\">{$student->firstname} {$student->lastname} ({$student->email})</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--dash-text-muted);">2. اكتب كلمة المرور الجديدة:</label>
                        <input type="text" name="newpassword" value="Sanad@2026" placeholder="اكتب كلمة السر هنا" required>
                    </div>

                    <button type="submit" class="sanad-btn sanad-btn-success" style="width: 100%; justify-content: center; height: 45px; font-size: 14px;">🔄 حفظ وتغيير كلمة المرور فوراً</button>
                </form>
            </div>

            <!-- Security Violations Alerts Card -->
            <div class="sanad-card">
                <div class="sanad-card-title" style="color: var(--dash-danger);">🚨 سجل الخروقات والانتهاكات الأمنية الأخيرة</div>
                <div style="display: flex; flex-direction: column; gap: 12px; max-height: 400px; overflow-y: auto;">
                    <?php if (empty($violations)) : ?>
                        <div style="text-align: center; color: var(--dash-text-muted); font-size: 13px;">لم يتم تسجيل أي خروقات أمنية مؤخراً. ممتاز!</div>
                    <?php else : ?>
                        <?php foreach ($violations as $v) : ?>
                            <?php
                                $violationar = 'محاولة خروج / فقدان تركيز';
                            if ($v->violationtype === 'invalid_token') {
                                $violationar = 'توكن غير صالح';
                            }
                            if ($v->violationtype === 'wrong_browser') {
                                $violationar = 'دخول بمتصفح غير آمن';
                            }

                                $formattedtime = userdate($v->timecreated, '%H:%M:%S (%d-%m-%Y)');
                            ?>
                            <div style="border-right: 3px solid var(--dash-danger); padding-right: 10px; background-color: rgba(239, 68, 68, 0.05); padding: 8px; border-radius: 4px;">
                                <strong style="color: var(--dash-danger); font-size: 13px;"><?php echo $violationar; ?></strong><br>
                                <span style="font-size: 12px; font-weight: 700;">البيانات: <?php echo s($v->firstname . ' ' . $v->lastname); ?></span><br>
                                <span style="font-size: 11px; color: var(--dash-text-muted);">الاختبار: <?php echo s($v->quizname); ?></span><br>
                                <span style="font-size: 11px; color: var(--dash-text-muted);">التوقيت: <?php echo $formattedtime; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- JS Live Filtering Logic -->
<script>
    function filterDevices() {
        const input = document.getElementById("deviceSearch");
        const filter = input.value.toUpperCase();
        const rows = document.getElementsByClassName("device-row");

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const text = row.innerText || row.textContent;
            if (text.toUpperCase().indexOf(filter) > -1) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        }
    }
</script>

<?php
echo $OUTPUT->footer();
