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
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module: EWA Monitor Dashboard — auto-refresh, modals, and live data.
 *
 * @module    quizaccess_ewa_lockdown/monitor
 * @copyright 2026 Mahmoud Salem <m.salem@ewa.bh>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    // ── State ─────────────────────────────────────────────────────────────────
    var Config = {};
    var refreshTimer   = null;
    var countdownTimer = null;
    var refreshSeconds = 30;
    var secondsLeft    = refreshSeconds;

    // ── Violation type labels (bilingual-friendly) ────────────────────────────
    var VIOLATION_LABELS = {
        'wrong_browser':              'Wrong Browser',
        'invalid_token':              'Invalid Token',
        'expired_token':              'Expired Token',
        'focus_lost':                 'App Lost Focus',
        'device_mismatch':            'Device Mismatch',
        'invalid_exit_password_attempt': 'Bad Exit Password',
        'unknown_violation':          'Unknown'
    };

    // ── Init ──────────────────────────────────────────────────────────────────
    function init(config) {
        Config = config;
        refreshSeconds = Math.round((config.refreshMs || 30000) / 1000);
        secondsLeft    = refreshSeconds;

        bindStaticEvents();
        startCountdown();
        startAutoRefresh();
    }

    // ── Bind events on page-load elements ────────────────────────────────────
    function bindStaticEvents() {
        // QR show buttons (delegated — rows may be re-rendered).
        $(document).on('click', '.ewa-show-qr', function() {
            var qrdata = $(this).data('qrb64');
            var name   = $(this).data('name');
            var url    = $(this).data('qrurl');
            openQrModal(name, qrdata, url);
        });

        // Violation buttons (delegated).
        $(document).on('click', '.ewa-btn-violations', function() {
            var name       = $(this).data('name');
            var violations = $(this).data('violations');
            openViolationsModal(name, violations);
        });

        // QR modal close.
        $('#ewa-qr-close, #ewa-qr-close-btn, #ewa-qr-modal .ewa-modal-backdrop').on('click', closeQrModal);

        // Violations modal close.
        $('#ewa-violations-close, #ewa-violations-close-btn, #ewa-violations-modal .ewa-modal-backdrop').on('click', closeViolationsModal);

        // Fullscreen QR.
        $('#ewa-qr-fullscreen').on('click', function() {
            var img = document.getElementById('ewa-qr-img');
            if (img.requestFullscreen) {
                img.requestFullscreen();
            } else if (img.webkitRequestFullscreen) {
                img.webkitRequestFullscreen();
            }
        });

        // ESC key closes any open modal.
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQrModal();
                closeViolationsModal();
            }
        });
    }

    // ── Auto-refresh ──────────────────────────────────────────────────────────
    function startAutoRefresh() {
        refreshTimer = setTimeout(function() {
            fetchAndUpdateData();
        }, Config.refreshMs);
    }

    function fetchAndUpdateData() {
        $('#ewa-refresh-icon').addClass('fa-spin');

        $.ajax({
            url:  Config.monitorUrl,
            type: 'GET',
            data: { ajax: 1, cmid: Config.cmid },
            dataType: 'json',
            success: function(response) {
                if (response && response.students) {
                    updateStatsBar(response.students);
                    updateStudentRows(response.students);
                }
                $('#ewa-refresh-icon').removeClass('fa-spin');
                secondsLeft = refreshSeconds;
                startAutoRefresh();
            },
            error: function() {
                $('#ewa-refresh-icon').removeClass('fa-spin');
                secondsLeft = refreshSeconds;
                startAutoRefresh();
            }
        });
    }

    // ── Countdown timer UI ────────────────────────────────────────────────────
    function startCountdown() {
        clearInterval(countdownTimer);
        countdownTimer = setInterval(function() {
            secondsLeft--;
            if (secondsLeft < 0) { secondsLeft = 0; }
            var el = document.getElementById('ewa-refresh-countdown');
            if (el) {
                el.textContent = 'Auto-refresh in ' + secondsLeft + 's';
            }
        }, 1000);
    }

    // ── DOM update helpers ────────────────────────────────────────────────────
    function updateStatsBar(students) {
        var active = 0, waiting = 0, expired = 0, violations = 0;
        students.forEach(function(s) {
            if (s.status === 'active')  { active++; }
            if (s.status === 'waiting') { waiting++; }
            if (s.status === 'expired') { expired++; }
            violations += (s.violationcount || 0);
        });

        setStatNumber('.ewa-stat-total .ewa-stat-number',      students.length);
        setStatNumber('.ewa-stat-active .ewa-stat-number',     active);
        setStatNumber('.ewa-stat-waiting .ewa-stat-number',    waiting);
        setStatNumber('.ewa-stat-expired .ewa-stat-number',    expired);
        setStatNumber('.ewa-stat-violations .ewa-stat-number', violations);
    }

    function setStatNumber(sel, val) {
        var el = document.querySelector(sel);
        if (el && el.textContent != String(val)) {
            el.textContent = val;
            el.closest('.ewa-stat-card').classList.add('ewa-pulse');
            setTimeout(function() {
                el.closest('.ewa-stat-card').classList.remove('ewa-pulse');
            }, 600);
        }
    }

    function updateStudentRows(students) {
        students.forEach(function(s) {
            var row = document.querySelector('tr[data-userid="' + s.userid + '"]');
            if (!row) { return; }

            // Update status badge.
            var statusTd = row.querySelector('.ewa-td-status');
            if (statusTd) {
                var badge = statusTd.querySelector('.ewa-badge');
                if (badge && badge.dataset.status !== s.status) {
                    statusTd.innerHTML = renderStatusBadge(s.status);
                }
            }

            // Update last heartbeat.
            var hbTd = row.querySelector('.ewa-td-hb');
            if (hbTd && s.lastheartbeat) {
                var ago = Math.round(Date.now() / 1000) - s.lastheartbeat;
                hbTd.innerHTML = '<span class="' + (ago > 120 ? 'text-danger' : 'text-success') + '">'
                    + humanAgo(ago) + '</span>';
            }

            // Update violation count badge.
            var violTd = row.querySelector('.ewa-td-violations');
            if (violTd) {
                var cnt = s.violationcount || 0;
                var existing = violTd.querySelector('.ewa-btn-violations, .ewa-badge-success');
                if (existing && parseInt(existing.textContent) !== cnt) {
                    if (cnt > 0) {
                        var vcls = cnt >= 3 ? 'ewa-badge ewa-badge-danger' : 'ewa-badge ewa-badge-warning';
                        violTd.innerHTML = '<button type="button" class="' + vcls + ' ewa-btn-violations"'
                            + ' data-userid="' + s.userid + '"'
                            + ' data-name="' + escHtml(s.fullname) + '"'
                            + ' data-violations=\'' + JSON.stringify(s.violations) + '\'>'
                            + '<i class="fa fa-exclamation-triangle mr-1"></i>' + cnt
                            + '</button>';
                    } else {
                        violTd.innerHTML = '<span class="ewa-badge ewa-badge-success"><i class="fa fa-check mr-1"></i>0</span>';
                    }
                }
            }

            // Update row class.
            row.className = row.className.replace(/ewa-row-\w+/g, '').trim();
            row.classList.add('ewa-row-' + s.status);
            if (s.violationcount >= 3) { row.classList.add('ewa-row-alert'); }
        });
    }

    function renderStatusBadge(status) {
        var badges = {
            'active':  '<span class="ewa-badge ewa-badge-success" data-status="active"><i class="fa fa-check-circle mr-1"></i>Active</span>',
            'waiting': '<span class="ewa-badge ewa-badge-warning" data-status="waiting"><i class="fa fa-hourglass-half mr-1"></i>Waiting</span>',
            'expired': '<span class="ewa-badge ewa-badge-danger"  data-status="expired"><i class="fa fa-times-circle mr-1"></i>Expired</span>'
        };
        return badges[status] || '<span class="ewa-badge ewa-badge-secondary">' + escHtml(status) + '</span>';
    }

    // ── QR Modal ──────────────────────────────────────────────────────────────
    function openQrModal(name, imgSrc, url) {
        document.getElementById('ewa-qr-modal-title').textContent = 'QR Code — ' + name;
        document.getElementById('ewa-qr-student-name').textContent = name;
        document.getElementById('ewa-qr-img').src = imgSrc;
        document.getElementById('ewa-qr-url-text').textContent = url;
        var modal = document.getElementById('ewa-qr-modal');
        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeQrModal() {
        var modal = document.getElementById('ewa-qr-modal');
        modal.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    // ── Violations Modal ──────────────────────────────────────────────────────
    function openViolationsModal(name, violations) {
        var isArabic = $('html').attr('lang') === 'ar';
        
        var modalTitle = isArabic 
            ? 'المخالفات المرصودة — ' + name + ' (' + violations.length + ')'
            : 'Violations — ' + name + ' (' + violations.length + ')';
            
        document.getElementById('ewa-violations-modal-title').textContent = modalTitle;

        var headers = {
            num: '#',
            type: isArabic ? 'النوع' : 'Type',
            time: isArabic ? 'الوقت' : 'Time',
            device: isArabic ? 'الجهاز' : 'Device',
            details: isArabic ? 'التفاصيل' : 'Details'
        };

        var html = '<table class="ewa-viol-table"><thead><tr>'
            + '<th>' + headers.num + '</th>'
            + '<th>' + headers.type + '</th>'
            + '<th>' + headers.time + '</th>'
            + '<th>' + headers.device + '</th>'
            + '<th>' + headers.details + '</th>'
            + '</tr></thead><tbody>';

        if (!violations || violations.length === 0) {
            var noViolMsg = isArabic ? 'لم يتم رصد أي مخالفات.' : 'No violations recorded.';
            html += '<tr><td colspan="5" class="text-center text-muted py-3">' + noViolMsg + '</td></tr>';
        } else {
            violations.forEach(function(v, idx) {
                // Determine localized violation type label
                var label = v.type;
                if (isArabic) {
                    var arLabels = {
                        'wrong_browser':                 'متصفح غير مصرح به',
                        'invalid_token':                 'رمز أمان غير صالح',
                        'expired_token':                 'رمز أمني منتهي',
                        'focus_lost':                    'الخروج من التطبيق',
                        'device_mismatch':               'تغيير الجهاز المستخدم',
                        'invalid_exit_password_attempt': 'كلمة مرور خروج خاطئة'
                    };
                    label = arLabels[v.type] || v.type;
                } else {
                    label = VIOLATION_LABELS[v.type] || v.type;
                }

                // Format technical JSON details to human-readable message
                var detailsHtml = '';
                if (v.details) {
                    try {
                        var data = JSON.parse(v.details);
                        if (data.page) {
                            if (data.page === 'prevent_new_attempt') {
                                detailsHtml = isArabic 
                                    ? 'حاول فتح صفحة بدء الاختبار من متصفح عادي وتم حظره.'
                                    : 'Attempted to open the quiz entry page from a regular browser.';
                            } else if (data.page === 'description') {
                                detailsHtml = isArabic 
                                    ? 'حاول فتح صفحة معلومات الاختبار من متصفح عادي.'
                                    : 'Opened the quiz information page from a regular browser.';
                            } else {
                                detailsHtml = escHtml(data.page);
                            }
                        } else if (data.bound_device) {
                            var boundShort = escHtml(data.bound_device.substring(0, 12)) + '...';
                            if (isArabic) {
                                detailsHtml = 'الجهاز غير متطابق. الجلسة مرتبطة بجهاز آخر: <code>' + boundShort + '</code>';
                            } else {
                                detailsHtml = 'Device mismatch. Session is locked to device: <code>' + boundShort + '</code>';
                            }
                        } else if (data.remaining_attempts !== undefined) {
                            detailsHtml = isArabic
                                ? 'أدخل كلمة مرور خروج خاطئة. المحاولات المتبقية: ' + data.remaining_attempts
                                : 'Incorrect exit password entered. Remaining attempts: ' + data.remaining_attempts;
                        } else if (data.api_log && data.raw_details) {
                            detailsHtml = escHtml(data.raw_details);
                        } else {
                            detailsHtml = '<pre class="mb-0" style="font-size:.72rem;white-space:pre-wrap">' + escHtml(JSON.stringify(data, null, 1)) + '</pre>';
                        }
                    } catch (e) {
                        detailsHtml = escHtml(v.details);
                    }
                }

                var devId = v.deviceid ? escHtml(v.deviceid.substring(0, 12)) : '—';

                html += '<tr>'
                    + '<td>' + (idx + 1) + '</td>'
                    + '<td><strong>' + escHtml(label) + '</strong></td>'
                    + '<td>' + escHtml(v.time_human || '') + '</td>'
                    + '<td><code>' + devId + '</code></td>'
                    + '<td>' + detailsHtml + '</td>'
                    + '</tr>';
            });
        }
        html += '</tbody></table>';

        document.getElementById('ewa-violations-content').innerHTML = html;
        var modal = document.getElementById('ewa-violations-modal');
        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeViolationsModal() {
        var modal = document.getElementById('ewa-violations-modal');
        modal.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function humanAgo(seconds) {
        if (seconds < 60)   { return seconds + 's ago'; }
        if (seconds < 3600) { return Math.round(seconds / 60) + 'm ago'; }
        return (seconds / 3600).toFixed(1) + 'h ago';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#039;');
    }

    // ── Pulse animation style injection ──────────────────────────────────────
    (function addPulseStyle() {
        var style = document.createElement('style');
        style.textContent = '@keyframes ewaPulse{0%{transform:scale(1)}50%{transform:scale(1.1)}100%{transform:scale(1)}}'
            + '.ewa-pulse{animation:ewaPulse .6s ease}';
        document.head.appendChild(style);
    })();

    return { init: init };
});
