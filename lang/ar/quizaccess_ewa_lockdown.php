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
 * Arabic language strings for quizaccess_ewa_lockdown.
 *
 * @package   quizaccess_ewa_lockdown
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin name.
$string['pluginname'] = 'قاعدة الوصول لمتصفح EWA الآمن';

// Settings form strings.
$string['requireewalockdown'] = 'طلب متصفح EWA الآمن';
$string['requireewalockdown_help'] = 'عند التفعيل، يجب على الطلاب استخدام تطبيق متصفح EWA الآمن للأندرويد لبدء هذا الاختبار. سيتم حظر الدخول من المتصفحات العادية. سيظهر رمز الاستجابة السريعة (QR) ليقوم الطلاب بمسحه بالتطبيق.';
$string['tokenexpiry'] = 'صلاحية رمز الجلسة (بالثواني)';
$string['tokenexpiry_help'] = 'المدة الزمنية التي يظل فيها رمز الجلسة صالحاً بعد مسح رمز الاستجابة السريعة. الافتراضي هو 1800 ثانية (30 دقيقة). يجب أن تكون أكبر من أو تساوي حد وقت الاختبار.';
$string['exitpassword'] = 'كلمة مرور الخروج في حالات الطوارئ';
$string['exitpassword_help'] = 'كلمة مرور يمكن للمراقبين إدخالها في تطبيق متصفح EWA الآمن لتحرير الجهاز من وضع كشك الامتحانات في حالات الطوارئ. اتركها فارغة لتعطيل الخروج الطارئ.';
$string['alloweddomains'] = 'النطاقات والروابط المسموح بزيارتها (القائمة البيضاء)';
$string['alloweddomains_help'] = 'أدخل أي نطاقات أو روابط خارجية إضافية يُسمح للطالب بالدخول إليها أثناء الامتحان (نطاق واحد في كل سطر، مثل: backup-lms.ewa.edu.sa أو cdn.ewa.edu.sa). سيقوم متصفح EWA الآمن بالسماح بالاتصال بهذه العناوين وحظر ما سواها.';

// Preflight / access denied strings.
$string['accessdenied'] = 'تم رفض الوصول';
$string['mustuseewaapp'] = 'هذا الاختبار يتطلب استخدام تطبيق متصفح EWA الآمن على الأندرويد. لا يمكنك فتحه من متصفح عادي.';
$string['scanqrtostart'] = 'امسح رمز الاستجابة السريعة (QR) أدناه باستخدام تطبيق متصفح EWA الآمن لبدء الاختبار:';
$string['qrcode_alttext'] = 'رمز الاستجابة السريعة لبدء هذا الاختبار في متصفح EWA الآمن';
$string['downloadapp'] = 'ألا تملك التطبيق بعد؟ قم بتنزيل متصفح EWA الآمن من متجر Google Play.';
$string['tokenerror'] = 'رمز الجلسة الخاص بك غير صالح أو انتهت صلاحيته. يرجى إعادة مسح رمز الاستجابة السريعة باستخدام تطبيق متصفح EWA الآمن.';
$string['tokenexpired'] = 'انتهت صلاحية جلستك الآمنة. يرجى مطالبة المراقب بإعادة إصدار رمز الاستجابة السريعة.';
$string['requestfromteacher'] = 'يرجى طلب رمز الاستجابة السريعة (QR Code) من معلّم المادة أو مراقب القاعة لبدء الاختبار.';

// Violation log strings.
$string['violation_invalid_token'] = 'رمز أمان غير صالح';
$string['violation_wrong_browser'] = 'محاولة دخول من متصفح غير مصرح به';
$string['violation_focus_lost'] = 'فقدان التركيز والتنقل خارج التطبيق أثناء الاختبار';
$string['violation_expired_token'] = 'رمز أمان منتهي الصلاحية';

// ── لوحة تحكم المراقبة المباشرة ──────────────────────────────────────────────
$string['plugindisabled']       = 'ميزة EWA Lockdown غير مفعّلة لهذا الاختبار.';
$string['monitor_title']        = 'متصفح EWA الآمن — المراقبة المباشرة';
$string['monitor_link']         = 'المراقبة المباشرة EWA';

// إحصائيات.
$string['total_students']       = 'إجمالي الطلاب';
$string['stat_active']          = 'جلسات نشطة';
$string['stat_waiting']         = 'في الانتظار';
$string['stat_expired']         = 'جلسات منتهية';
$string['stat_violations']      = 'إجمالي المخالفات';

// حالات الجلسة.
$string['status_active']        = 'نشطة';
$string['status_waiting']       = 'انتظار';
$string['status_expired']       = 'منتهية';

// رؤوس الأعمدة.
$string['student']              = 'الطالب';
$string['col_status']           = 'حالة الجلسة';
$string['col_device']           = 'معرّف الجهاز';
$string['col_heartbeat']        = 'آخر نبضة';
$string['col_expires']          = 'تنتهي في';
$string['col_violations']       = 'المخالفات';
$string['col_actions']          = 'الإجراءات';

// أزرار الإجراءات.
$string['action_revoke']        = 'إلغاء الجلسة';
$string['action_reissue']       = 'إصدار QR';
$string['action_showqr']        = 'عرض QR';
$string['show_violations']      = 'عرض سجل المخالفات';

// تأكيدات.
$string['confirm_revoke']       = 'هل أنت متأكد من إلغاء جلسة هذا الطالب؟ سيحتاج إلى رمز QR جديد للمتابعة.';

// تغذية راجعة للإجراءات.
$string['session_revoked']      = 'تم إلغاء الجلسة بنجاح.';
$string['session_reissued']     = 'تم إصدار رمز جلسة جديد. أظهر رمز QR للطالب.';

// نافذة QR.
$string['qrmodal_title']        = 'امسح للبدء في الاختبار';
$string['qrmodal_hint']         = 'اطلب من الطالب مسح رمز QR هذا باستخدام تطبيق متصفح EWA الآمن.';
$string['qrmodal_fullscreen']   = 'ملء الشاشة';

// التحديث التلقائي.
$string['next_refresh']         = 'التحديث التلقائي خلال {$a} ثانية';
$string['close']                = 'إغلاق';

// الوقت المنقضي.
$string['ago_seconds']          = 'منذ {$a} ثانية';
$string['ago_minutes']          = 'منذ {$a} دقيقة';
$string['ago_hours']            = 'منذ {$a} ساعة';


// Privacy.
$string['privacy:metadata:quizaccess_ewa_sessions'] = 'تخزين رموز جلسات الاختبار الآمنة المصدرة للطلاب.';
$string['privacy:metadata:quizaccess_ewa_sessions:userid'] = 'المستخدم المصدر له الرمز.';
$string['privacy:metadata:quizaccess_ewa_sessions:quizid'] = 'الاختبار المرتبط بالرمز.';
$string['privacy:metadata:quizaccess_ewa_sessions:token'] = 'رمز الجلسة الأمني المشفر.';
$string['privacy:metadata:quizaccess_ewa_sessions:deviceid'] = 'بصمة جهاز الأندرويد المستخدم.';
$string['privacy:metadata:quizaccess_ewa_sessions:timecreated'] = 'وقت إنشاء الجلسة.';
$string['privacy:metadata:quizaccess_ewa_sessions:timeexpires'] = 'وقت انتهاء صلاحية الجلسة.';
$string['privacy:metadata:quizaccess_ewa_violations'] = 'تسجيل المخالفات الأمنية المرصودة أثناء الاختبار الآمن.';
$string['privacy:metadata:quizaccess_ewa_violations:userid'] = 'المستخدم المرتبط بالمخالفة.';
$string['privacy:metadata:quizaccess_ewa_violations:quizid'] = 'الاختبار الذي تم رصد المخالفة به.';
$string['privacy:metadata:quizaccess_ewa_violations:violationtype'] = 'نوع المخالفة الأمنية.';
$string['privacy:metadata:quizaccess_ewa_violations:deviceid'] = 'بصمة الجهاز وقت حدوث المخالفة.';
$string['privacy:metadata:quizaccess_ewa_violations:details'] = 'تفاصيل إضافية للمخالفة.';
$string['privacy:metadata:quizaccess_ewa_violations:timecreated'] = 'وقت حدوث المخالفة.';
