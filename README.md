# 🛡️ EWA Secure Exam System (ewa_lockdown)

[English](#english) | [العربية](#العربية)

---

# English

## Overview
**EWA Secure Exam System** is a high-security, enterprise-grade assessment lockdown solution designed specifically for the **Energy & Water Academy (EWA)**. The system prevents academic dishonesty during Moodle quizzes by strictly restricting student access to a dedicated, hardware-locked Android secure browser application.

The system consists of two primary components:
1. **Moodle Access Rule Plugin (`mod_quiz_accessrule_ewa_lockdown`):** Restricts quiz access to requests containing valid, cryptographically signed EWA session tokens and binds the exam session to a specific device.
2. **EWA Secure Browser (Android App):** An Android application running in **Device Owner Mode** (Kiosk Mode) that blocks access to navigation bar, system notifications, screenshots, screen recording, and unauthorized apps.

---

## 🚀 Key Features

*   **🔒 Device Owner Kiosk Lock:** Completely locks down the Android tablet using Android Enterprise Device Owner policies (pinning the screen and disabling system UI).
*   **🔌 Cryptographic Session Binding:** Binds the student's Moodle attempt to a specific hardware ID, blocking token sharing across multiple devices.
*   **⌨️ Secure Custom Keyboard (IME):** Implements a dedicated keyboard layout with disabled clipboard copy/paste, translation overlays, and autocomplete features.
*   **🔄 Automatic Autoping (Heartbeat):** Android app pings Moodle every 30 seconds to refresh the session token; if the app is closed or network fails, the session immediately expires.
*   **⚠️ Real-Time Focus & Overlay Tracking:** Automatically detects when the app loses focus or an overlay window tries to launch, instantly logging security violations to Moodle database.
*   **🔑 Supervisor Bypass & Verification:** Allows supervisors to exit kiosk mode using a secure, quiz-specific exit password configured in Moodle settings.
*   **💳 Annual Subscription Hardware Licensing:** Restricts app usage to a predefined number of tablet devices using a Challenge-Response RSA licensing system.

---

## 🛠️ Requirements
*   **Moodle Version:** 4.5.0 and above.
*   **PHP Version:** 8.1 / 8.2 / 8.3.
*   **Android OS:** Android 8.0 (Oreo) and above on tablets.

---

## 📦 Installation & Setup

### 1. Install Moodle Access Rule Plugin
1. Clone this repository into your Moodle installation folder:
   ```bash
   git clone https://github.com/engfeda-ui/ewa_lockdown.git mod/quiz/accessrule/ewa_lockdown
   ```
2. Navigate to your Moodle administration page or run the CLI upgrade script:
   ```bash
   php admin/cli/upgrade.php
   ```
3. Enable the **EWA Secure Browser Lockdown** rule inside the specific quiz settings.

### 2. Install and Provision Android App
1. Compile the APK in Android Studio (`app-debug.apk`).
2. Install the app on target tablets using ADB:
   ```bash
   adb install app-debug.apk
   ```
3. Set the app as the **Device Owner** (Kiosk Controller) using this shell command:
   ```bash
   adb shell dpm set-device-owner com.ewa.securebrowser/.DeviceAdminReceiver
   ```

---

## 🔑 Challenge-Response Licensing System
To prevent unauthorized installations and enforce annual subscription plans, EWA Secure Browser uses a hardware-locked licensing protocol:
1. Upon first boot, the tablet displays a **16-digit Hardware ID** (e.g., `EWA1-98F2-A5C3-D8E4`).
2. Open the local license dashboard `ewa_license_generator.html` on your PC.
3. Paste the Hardware ID, select subscription duration (e.g., 365 days), and click **Generate Activation Key**.
4. Type the generated activation key into the tablet. The app decrypts it using an internal **AES-128 key** to unlock the quiz scanner interface.

---

# العربية

## نظرة عامة
**نظام اختبارات EWA الآمن (EWA Secure Exam System)** هو نظام حماية واختبارات متكامل مصمم خصيصاً لـ **أكاديمية الطاقة والمياه (EWA)**. يمنع النظام الغش والتلاعب الأكاديمي أثناء اختبارات مودل عن طريق تقييد الوصول للاختبار وحصره فقط على متصفح مخصص وآمن على أجهزة التابلت مقفل بالكامل.

يتكون النظام من جزأين رئيسيين:
1. **إضافة قواعد الوصول لمودل (`ewa_lockdown`):** تمنع الطلاب من فتح الاختبار إلا عبر إرسال توكن أمان مشفر وموقع رقمياً، وربط الاختبار بجهاز تابلت فيزيائي محدد.
2. **تطبيق متصفح EWA الآمن (تطبيق أندرويد):** تطبيق يعمل بصلاحيات **مسؤول الجهاز المطلق (Device Owner)** لقفل شاشة التابلت بالكامل ومنع الخروج أو تصفح أي تطبيقات أخرى.

---

## 🚀 الميزات الرئيسية

*   **🔒 وضع الكشك المطلق (Device Owner Mode):** يقفل التابلت بالكامل ويمنع فتح شريط الإشعارات، أو أزرار النظام، أو إيماءات التنقل.
*   **🔌 ربط الجلسة بـ Hardware ID:** يربط محاولة اختبار الطالب بجهازه لمنع مشاركة الروابط والتوكنات على أجهزة أخرى.
*   **⌨️ لوحة مفاتيح آمنة مخصصة (IME):** لوحة مفاتيح مدمجة مغلقة وخالية من الحافظة (Clipboard) لمنع النسخ واللصق أو الترجمة الفورية والبحث.
*   **🔄 نبضات القلب التلقائية (Heartbeat):** يتصل التطبيق بمودل كل 30 ثانية لتمديد صلاحية التوكن؛ وإذا تم إغلاق التطبيق أو تعطل الاتصال، تنتهي الجلسة فوراً.
*   **⚠️ رصد ومراقبة النوافذ العائمة:** يسجل التطبيق مخالفة أمنية في خادم المودل فوراً إذا فقد التطبيق التركيز أو حاولت شاشة أخرى الظهور فوق المتصفح.
*   **🔑 خروج اضطراري للمشرفين:** يتيح للمراقب إلغاء وضع الكشك للتابلت محلياً بإدخال كلمة مرور الخروج المحددة في إعدادات الاختبار بمودل.
*   **💳 ترخيص اشتراك سنوي مغلق على الأجهزة:** نظام تراخيص سنوي ذكي (Challenge-Response) يمنع الأكاديمية من تشغيل التطبيق على أجهزة إضافية غير المتفق عليها.

---

## 🛠️ متطلبات التشغيل
*   **إصدار مودل:** 4.5.0 فما فوق.
*   **إصدار PHP:** 8.1 / 8.2 / 8.3.
*   **نظام أندرويد للتابلت:** إصدار Android 8.0 فما فوق.

---

## 📦 التثبيت والإعداد

### 1. تثبيت إضافة مودل
1. قم ببرمجة أو نسخ الإضافة داخل مجلد المودل لديك:
   ```bash
   git clone https://github.com/engfeda-ui/ewa_lockdown.git mod/quiz/accessrule/ewa_lockdown
   ```
2. توجه لصفحة الإدارة في المودل أو قم بتشغيل الترقية من سطر الأوامر:
   ```bash
   php admin/cli/upgrade.php
   ```
3. قم بتفعيل الخيار **EWA Secure Browser Lockdown** في إعدادات الاختبار المطلوب حمايته.

### 2. تثبيت وإعداد تطبيق الأندرويد
1. قم ببناء ملف الـ APK في Android Studio (`app-debug.apk`).
2. قم بتثبيت التطبيق على التابلت عبر الـ ADB:
   ```bash
   adb install app-debug.apk
   ```
3. قم بتعيين التطبيق كـ **Device Owner** (المالك المطلق للجهاز) عبر الأمر:
   ```bash
   adb shell dpm set-device-owner com.ewa.securebrowser/.DeviceAdminReceiver
   ```

---

## 🔑 نظام التراخيص السنوي (Challenge-Response)
لمنع استخدام التطبيق على أجهزة أكثر من المتفق عليها، يتم تطبيق آلية الترخيص السنوية كالتالي:
1. عند تشغيل التطبيق لأول مرة على التابلت، يظهر **كود تعريف فريد للجهاز مكون من 16 رقماً** (مثل: `EWA1-98F2-A5C3-D8E4`).
2. افتح صفحة التفعيل `ewa_license_generator.html` على كمبيوترك الشخصي.
3. اكتب كود الجهاز، وحدد مدة الاشتراك (مثلاً 365 يوم)، واضغط **توليد كود التفعيل**.
4. اكتب كود التفعيل الناتج في التابلت، وسيقوم التطبيق بفك تشفيره بـ **AES-128** وتنشيط نفسه فوراً.

---

## 📄 License & Rights
All software, code assets, and design concepts are proprietary and confidential. Developed specifically for **Energy & Water Academy (EWA)** under commercial licensing agreements. Unauthorized redistribution, copying, or reverse engineering is strictly prohibited.
