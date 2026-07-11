<?php
/**
 * Admin Kiosk Control Panel and Device Manager.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
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
$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/ewa_lockdown/manage_devices.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'quizaccess_ewa_lockdown') . ' - Device Manager');
$PAGE->set_heading('EWA Kiosk Admin Control Center');

global $DB, $OUTPUT, $PAGE;

// 2. Handle Actions (Approve, Block, Delete, Expiry, Reset Password)
$action = optional_param('action', '', PARAM_ALPHAEXT);
$id     = optional_param('id', 0, PARAM_INT);
$msg    = '';
$msgtype = 'success'; // 'success' or 'error'

if (!empty($action) && confirm_sesskey()) {
    try {
        if ($action === 'approve') {
            $device = $DB->get_record('quizaccess_ewa_devices', ['id' => $id], '*', MUST_EXIST);
            $device->status = 1; // Active
            $device->expirydate = time() + (365 * 86400); // 1 Year Default
            $device->timemodified = time();
            $DB->update_record('quizaccess_ewa_devices', $device);
            $msg = "تم تفعيل الجهاز {$device->hardwareid} بنجاح لمدة عام!";
        } else if ($action === 'block') {
            $device = $DB->get_record('quizaccess_ewa_devices', ['id' => $id], '*', MUST_EXIST);
            $device->status = 2; // Suspended
            $device->timemodified = time();
            $DB->update_record('quizaccess_ewa_devices', $device);
            $msg = "تم حظر وتجميد ترخيص الجهاز {$device->hardwareid} بنجاح.";
        } else if ($action === 'delete') {
            $device = $DB->get_record('quizaccess_ewa_devices', ['id' => $id], '*', MUST_EXIST);
            $DB->delete_records('quizaccess_ewa_devices', ['id' => $id]);
            $msg = "تم حذف الجهاز {$device->hardwareid} نهائياً من النظام.";
        } else if ($action === 'extend') {
            $days = required_param('days', PARAM_INT);
            $device = $DB->get_record('quizaccess_ewa_devices', ['id' => $id], '*', MUST_EXIST);
            $device->expirydate = time() + ($days * 86400);
            $device->status = 1; // Ensure active
            $device->timemodified = time();
            $DB->update_record('quizaccess_ewa_devices', $device);
            $msg = "تم تمديد ترخيص الجهاز {$device->hardwareid} إلى {$days} يوماً.";
        } else if ($action === 'resetpass') {
            $userid = required_param('userid', PARAM_INT);
            $newpassword = required_param('newpassword', PARAM_RAW);
            if (strlen($newpassword) < 6) {
                throw new moodle_exception('errorpasswordlength', 'quizaccess_ewa_lockdown', '', null, 'يجب ألا تقل كلمة المرور عن 6 خانات.');
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

// 3. Retrieve Dashboard Stats & Lists
$total_devices = $DB->count_records('quizaccess_ewa_devices');
$active_devices = $DB->count_records_select('quizaccess_ewa_devices', 'status = 1 AND expirydate > :now', ['now' => time()]);
$pending_devices = $DB->count_records('quizaccess_ewa_devices', ['status' => 0]);
$active_exams = $DB->count_records_select('quizaccess_ewa_sessions', 'timeexpires > :now', ['now' => time()]);

// Get all devices
$devices = $DB->get_records('quizaccess_ewa_devices', null, 'timecreated DESC');

// Get active exam sessions with details
$sql_sessions = "
    SELECT s.id, s.token, s.deviceid, s.timecreated, s.timeexpires,
           u.id AS userid, u.firstname, u.lastname, u.email,
           q.id AS quizid, q.name AS quizname
      FROM {quizaccess_ewa_sessions} s
      JOIN {user} u ON u.id = s.userid
      JOIN {quiz} q ON q.id = s.quizid
     WHERE s.timeexpires > :now
  ORDER BY s.timecreated DESC";
$active_sessions = $DB->get_records_sql($sql_sessions, ['now' => time()]);

// Get recent violations
$sql_violations = "
    SELECT v.id, v.violationtype, v.deviceid, v.timecreated, v.details,
           u.firstname, u.lastname, u.email,
           q.name AS quizname
      FROM {quizaccess_ewa_violations} v
      JOIN {user} u ON u.id = v.userid
      JOIN {quiz} q ON q.id = v.quizid
  ORDER BY v.timecreated DESC
     LIMIT 10";
$violations = $DB->get_records_sql($sql_violations);

// Start rendering page.
echo $OUTPUT->header();
?>

<!-- Premium UI Stylesheet Override -->
<style>
    :root {
        --dash-primary: #0f172a;
        --dash-secondary: #1e293b;
        --dash-accent: #06b6d4;
        --dash-accent-hover: #0891b2;
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

    .ewa-container {
        padding: 20px;
        max-width: 1300px;
        margin: 0 auto;
    }

    .ewa-title {
        font-weight: 700;
        color: var(--dash-accent);
        margin-bottom: 25px;
        font-size: 26px;
        border-right: 4px solid var(--dash-accent);
        padding-right: 15px;
    }

    /* Message Alert */
    .ewa-alert {
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideDown 0.3s ease;
    }
    .ewa-alert-success {
        background-color: rgba(16, 185, 129, 0.15);
        border: 1px solid var(--dash-success);
        color: #34d399;
    }
    .ewa-alert-error {
        background-color: rgba(239, 68, 68, 0.15);
        border: 1px solid var(--dash-danger);
        color: #f87171;
    }

    @keyframes slideDown {
        from { transform: translateY(-10px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Metric Cards Grid */
    .ewa-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .ewa-metric-card {
        background: var(--dash-card-bg);
        border: 1px solid var(--dash-glass-border);
        border-radius: 16px;
        padding: 24px;
        backdrop-filter: blur(12px);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .ewa-metric-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    }
    .ewa-metric-card::after {
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

    .ewa-metric-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--dash-text-muted);
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .ewa-metric-value {
        font-size: 32px;
        font-weight: 800;
        color: var(--dash-text);
        line-height: 1;
    }

    /* Primary and secondary layouts */
    .ewa-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 25px;
    }

    @media (max-width: 992px) {
        .ewa-layout {
            grid-template-columns: 1fr;
        }
    }

    .ewa-card {
        background: var(--dash-card-bg);
        border: 1px solid var(--dash-glass-border);
        border-radius: 16px;
        padding: 25px;
        backdrop-filter: blur(12px);
        margin-bottom: 25px;
    }

    .ewa-card-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--dash-text);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Table Styles */
    .ewa-table-container {
        overflow-x: auto;
    }

    .ewa-table {
        width: 100%;
        border-collapse: collapse;
        text-align: right;
    }

    .ewa-table th {
        background-color: var(--dash-secondary);
        color: var(--dash-text);
        padding: 14px 16px;
        font-weight: 700;
        font-size: 13px;
        border-bottom: 2px solid var(--dash-glass-border);
    }

    .ewa-table td {
        padding: 14px 16px;
        font-size: 14px;
        color: var(--dash-text);
        border-bottom: 1px solid var(--dash-glass-border);
    }

    .ewa-table tr:hover {
        background-color: rgba(255, 255, 255, 0.02);
    }

    /* Status Badges */
    .ewa-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        display: inline-block;
    }
    .ewa-badge-pending {
        background-color: rgba(245, 158, 11, 0.15);
        color: var(--dash-warning);
        border: 1px solid var(--dash-warning);
    }
    .ewa-badge-active {
        background-color: rgba(16, 185, 129, 0.15);
        color: var(--dash-success);
        border: 1px solid var(--dash-success);
    }
    .ewa-badge-suspended {
        background-color: rgba(239, 68, 68, 0.15);
        color: var(--dash-danger);
        border: 1px solid var(--dash-danger);
    }

    /* Controls & Buttons */
    .ewa-btn {
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
    .ewa-btn-primary {
        background-color: var(--dash-accent);
        color: #ffffff !important;
    }
    .ewa-btn-primary:hover {
        background-color: var(--dash-accent-hover);
    }
    .ewa-btn-success {
        background-color: var(--dash-success);
        color: #ffffff !important;
    }
    .ewa-btn-success:hover {
        background-color: #059669;
    }
    .ewa-btn-danger {
        background-color: var(--dash-danger);
        color: #ffffff !important;
    }
    .ewa-btn-danger:hover {
        background-color: #dc2626;
    }
    .ewa-btn-warning {
        background-color: var(--dash-warning);
        color: #ffffff !important;
    }
    .ewa-btn-warning:hover {
        background-color: #d97706;
    }
    .ewa-btn-icon {
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

<div class="ewa-container" dir="rtl">
    <div class="ewa-title">🔑 لوحة تفعيل الأجهزة ومراقبة الاختبارات (Kiosk Admin)</div>

    <!-- Notifications Alert -->
    <?php if (!empty($msg)): ?>
        <div class="ewa-alert ewa-alert-<?php echo $msgtype; ?>">
            <span><?php echo ($msgtype === 'success' ? '✅' : '❌'); ?></span>
            <span><?php echo s($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Dashboard Stat Cards -->
    <div class="ewa-metrics-grid">
        <div class="ewa-metric-card">
            <div class="ewa-metric-title">الأجهزة المسجلة</div>
            <div class="ewa-metric-value"><?php echo $total_devices; ?></div>
        </div>
        <div class="ewa-metric-card">
            <div class="ewa-metric-title">الرخص النشطة حالياً</div>
            <div class="ewa-metric-value" style="color: #34d399;"><?php echo $active_devices; ?></div>
        </div>
        <div class="ewa-metric-card">
            <div class="ewa-metric-title">أجهزة بانتظار التفعيل</div>
            <div class="ewa-metric-value" style="color: #fbbf24;"><?php echo $pending_devices; ?></div>
        </div>
        <div class="ewa-metric-card">
            <div class="ewa-metric-title">الطلاب في الامتحانات الآن</div>
            <div class="ewa-metric-value" style="color: #22d3ee;"><?php echo $active_exams; ?></div>
        </div>
    </div>

    <!-- Main Layout Grid -->
    <div class="ewa-layout">
        
        <!-- Right Panel: Device List -->
        <div>
            <div class="ewa-card">
                <div class="ewa-card-title">
                    <span>📱 قائمة أجهزة التابلت المسجلة</span>
                </div>
                
                <!-- Simple live filter -->
                <input type="text" id="deviceSearch" class="search-box" placeholder="ابحث برمز الجهاز (Hardware ID) أو نوع الموديل..." onkeyup="filterDevices()">

                <div class="ewa-table-container">
                    <table class="ewa-table" id="deviceTable">
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
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--dash-text-muted);">لا توجد أجهزة مسجلة في قاعدة البيانات بعد. قم بتشغيل التطبيق على التابلت ليتم تسجيله تلقائياً.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $d): ?>
                                    <?php 
                                        $formatted_id = implode('-', str_split($d->hardwareid, 4));
                                        
                                        // Expiration string
                                        $expiry_str = 'غير محدد';
                                        $expired = false;
                                        if ($d->expirydate > 0) {
                                            $expiry_str = userdate($d->expirydate, '%d-%m-%Y');
                                            if ($d->expirydate <= time()) {
                                                $expired = true;
                                            }
                                        }

                                        // Badge class
                                        if ($d->status == 1) {
                                            $badge_class = $expired ? 'suspended' : 'active';
                                            $badge_label = $expired ? 'منتهية الصلاحية' : 'نشط';
                                        } else if ($d->status == 2) {
                                            $badge_class = 'suspended';
                                            $badge_label = 'محظور';
                                        } else {
                                            $badge_class = 'pending';
                                            $badge_label = 'قيد الانتظار';
                                        }
                                    ?>
                                    <tr class="device-row">
                                        <td>
                                            <strong style="color: var(--dash-text);"><?php echo s($d->devicebrand); ?></strong><br>
                                            <span style="font-size: 12px; color: var(--dash-text-muted);"><?php echo s($d->devicemodel); ?></span>
                                        </td>
                                        <td style="font-family: monospace; font-size: 15px; font-weight: 700; color: var(--dash-accent);"><?php echo $formatted_id; ?></td>
                                        <td>
                                            <span class="ewa-badge ewa-badge-<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
                                        </td>
                                        <td>
                                            <span style="<?php echo $expired ? 'color: var(--dash-danger); font-weight: 700;' : ''; ?>">
                                                <?php echo $expiry_str; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($d->status == 0 || $expired): ?>
                                                <a href="?action=approve&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="ewa-btn ewa-btn-success">تفعيل الرخصه</a>
                                            <?php endif; ?>

                                            <?php if ($d->status == 1 && !$expired): ?>
                                                <a href="?action=block&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="ewa-btn ewa-btn-warning">تعطيل وحظر</a>
                                            <?php endif; ?>

                                            <?php if ($d->status == 2): ?>
                                                <a href="?action=approve&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="ewa-btn ewa-btn-success">إلغاء الحظر</a>
                                            <?php endif; ?>

                                            <!-- Dropdown or quick extend of 1 year -->
                                            <a href="?action=extend&id=<?php echo $d->id; ?>&days=365&sesskey=<?php echo sesskey(); ?>" class="ewa-btn ewa-btn-primary" title="تجديد سنة">+ سنة</a>
                                            
                                            <a href="?action=delete&id=<?php echo $d->id; ?>&sesskey=<?php echo sesskey(); ?>" class="ewa-btn ewa-btn-danger ewa-btn-icon" onclick="return confirm('هل أنت متأكد من حذف هذا الجهاز نهائياً؟');" title="حذف">🗑️</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Active Exam Monitor Grid -->
            <div class="ewa-card">
                <div class="ewa-card-title">
                    <span>
                        <span class="live-pulse"></span>
                        مراقبة جلسات الاختبارات النشطة الآن
                    </span>
                    <span style="font-size: 13px; color: var(--dash-text-muted);">تحديث فوري لكل الأجهزة المتصلة بالاختبارات</span>
                </div>

                <div class="ewa-table-container">
                    <table class="ewa-table">
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
                            <?php if (empty($active_sessions)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--dash-text-muted); padding: 20px;">لا يوجد أي طالب يؤدي امتحاناً في الوقت الحالي.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($active_sessions as $s): ?>
                                    <?php 
                                        $formatted_device = $s->deviceid ? implode('-', str_split($s->deviceid, 4)) : 'غير مسجل (قديم)';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo s($s->firstname . ' ' . $s->lastname); ?></strong><br>
                                            <span style="font-size: 11px; color: var(--dash-text-muted);"><?php echo s($s->email); ?></span>
                                        </td>
                                        <td style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--dash-accent);"><?php echo $formatted_device; ?></td>
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
            <div class="ewa-card">
                <div class="ewa-card-title">🔐 إدارة وتغيير كلمة مرور طالب</div>
                
                <form action="" method="post" class="reset-form">
                    <input type="hidden" name="action" value="resetpass">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--dash-text-muted);">1. حدد الطالب من القائمة:</label>
                        <select name="userid" required style="width: 100%;">
                            <option value="">-- اختر الطالب --</option>
                            <?php
                                // Fetch all students (role student) or simply all active users (since they are only students on this site)
                                $all_students = $DB->get_records_select('user', 'id > 2 AND suspended = 0 AND deleted = 0', [], 'firstname ASC', 'id,firstname,lastname,email');
                                foreach ($all_students as $student) {
                                    echo "<option value=\"{$student->id}\">{$student->firstname} {$student->lastname} ({$student->email})</option>";
                                }
                            ?>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 12px; font-weight: 700; color: var(--dash-text-muted);">2. اكتب كلمة المرور الجديدة:</label>
                        <input type="text" name="newpassword" value="EwaStudent@2026" placeholder="اكتب كلمة السر هنا" required>
                    </div>

                    <button type="submit" class="ewa-btn ewa-btn-success" style="width: 100%; justify-content: center; height: 45px; font-size: 14px;">🔄 حفظ وتغيير كلمة المرور فوراً</button>
                </form>
            </div>

            <!-- Security Violations Alerts Card -->
            <div class="ewa-card">
                <div class="ewa-card-title" style="color: var(--dash-danger);">🚨 سجل الخروقات والانتهاكات الأمنية الأخيرة</div>
                <div style="display: flex; flex-direction: column; gap: 12px; max-height: 400px; overflow-y: auto;">
                    <?php if (empty($violations)): ?>
                        <div style="text-align: center; color: var(--dash-text-muted); font-size: 13px;">لم يتم تسجيل أي خروقات أمنية مؤخراً. ممتاز!</div>
                    <?php else: ?>
                        <?php foreach ($violations as $v): ?>
                            <?php 
                                $violation_ar = 'محاولة خروج / فقدان تركيز';
                                if ($v->violationtype === 'invalid_token') $violation_ar = 'توكن غير صالح';
                                if ($v->violationtype === 'wrong_browser') $violation_ar = 'دخول بمتصفح غير آمن';
                                
                                $formatted_time = userdate($v->timecreated, '%H:%M:%S (%d-%m-%Y)');
                            ?>
                            <div style="border-right: 3px solid var(--dash-danger); padding-right: 10px; background-color: rgba(239, 68, 68, 0.05); padding: 8px; border-radius: 4px;">
                                <strong style="color: var(--dash-danger); font-size: 13px;"><?php echo $violation_ar; ?></strong><br>
                                <span style="font-size: 12px; font-weight: 700;">البيانات: <?php echo s($v->firstname . ' ' . $v->lastname); ?></span><br>
                                <span style="font-size: 11px; color: var(--dash-text-muted);">الاختبار: <?php echo s($v->quizname); ?></span><br>
                                <span style="font-size: 11px; color: var(--dash-text-muted);">التوقيت: <?php echo $formatted_time; ?></span>
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
