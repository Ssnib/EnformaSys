<?php
/**
 * Global Notification Bell Component (Visible to ALL roles).
 * Displays Upcoming Events for all roles, plus System Alerts for Academic Formators.
 */
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/timetable_helpers.php';

global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    @require_once __DIR__ . '/db.php';
}

$notif_user_id = (int)($_SESSION['user'] ?? 0);
$notif_role = (string)($_SESSION['role'] ?? '');
$notif_is_af = in_array($notif_role, ['AcademicFormator', 'Academic Formator'], true);

$notif_data = ['events' => [], 'unread_count' => 0, 'read_ids' => []];
$notif_alerts = [];
$notif_alerts_unread = 0;

if ($notif_user_id > 0 && isset($conn) && ($conn instanceof mysqli)) {
    $notifCacheKey = $notif_user_id . '|' . $notif_role;
    $cachedBell = $_SESSION['cached_notif_bell'][$notifCacheKey] ?? null;
    if (is_array($cachedBell) && isset($cachedBell['ts']) && (time() - (int)$cachedBell['ts']) < 60) {
        $notif_data = is_array($cachedBell['data'] ?? null) ? $cachedBell['data'] : $notif_data;
        $notif_alerts = is_array($cachedBell['alerts'] ?? null) ? $cachedBell['alerts'] : [];
        $notif_alerts_unread = (int)($cachedBell['alerts_unread'] ?? 0);
    } else {
        $notif_data = timetable_get_upcoming_notifications($conn, $notif_user_id, $notif_role, 7);
        if ($notif_is_af) {
            require_once __DIR__ . '/academic_module.php';
            $alert_data = afms_get_system_alerts($conn, $notif_user_id, 25);
            $notif_alerts = $alert_data['alerts'] ?? [];
            $notif_alerts_unread = (int)($alert_data['unread_count'] ?? 0);
        }
        $_SESSION['cached_notif_bell'][$notifCacheKey] = [
            'ts' => time(),
            'data' => $notif_data,
            'alerts' => $notif_alerts,
            'alerts_unread' => $notif_alerts_unread,
        ];
    }
}

$notif_events = $notif_data['events'] ?? [];
$notif_event_unread = (int)($notif_data['unread_count'] ?? 0);

$notif_total_unread = $notif_event_unread + $notif_alerts_unread;
$notif_default_tab = ($notif_is_af && $notif_alerts_unread > 0) ? 'alerts' : 'events';
$notif_ajax_url = capstone_url('modules/ajax.php');
$notif_timetable_url = capstone_url('modules/timetable.php');
$notif_student_record_url = capstone_url('modules/afms_student_record.php');
$notif_csrf = (string)($_SESSION['csrf_token'] ?? '');
?>
<div class="relative inline-flex items-center js-notif-bell-root"
     data-user-id="<?= $notif_user_id ?>"
     data-is-af="<?= $notif_is_af ? '1' : '0' ?>"
     data-active-tab="<?= htmlspecialchars($notif_default_tab, ENT_QUOTES, 'UTF-8') ?>"
     data-ajax-url="<?= htmlspecialchars($notif_ajax_url, ENT_QUOTES, 'UTF-8') ?>"
     data-timetable-url="<?= htmlspecialchars($notif_timetable_url, ENT_QUOTES, 'UTF-8') ?>"
     data-student-record-url="<?= htmlspecialchars($notif_student_record_url, ENT_QUOTES, 'UTF-8') ?>"
     data-csrf="<?= htmlspecialchars($notif_csrf, ENT_QUOTES, 'UTF-8') ?>"
     data-event-ids="<?= htmlspecialchars(json_encode(array_column($notif_events, 'id')), ENT_QUOTES, 'UTF-8') ?>"
     data-server-unread="<?= $notif_event_unread ?>"
     data-alerts-unread="<?= $notif_alerts_unread ?>">
    <button type="button"
            class="js-notif-bell-btn relative inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#F8EEEE] hover:bg-white text-[#59443F] border border-[#E8C7C5]/70 hover:border-[#D6A6A6] transition shadow-2xs hover:shadow-xs cursor-pointer"
            aria-label="Notifications"
            aria-expanded="false"
            title="<?= $notif_is_af ? 'System Alerts &amp; Upcoming Events' : 'Upcoming Events (Next 7 days)' ?>">
        <i class="fa-regular fa-bell text-sm"></i>
        <span class="js-notif-badge <?= $notif_total_unread > 0 ? 'inline-flex' : 'hidden' ?> absolute -top-1 -right-1 min-w-[1.15rem] h-[1.15rem] px-1 items-center justify-center bg-red-500 text-white text-[10px] font-extrabold leading-none rounded-full shadow-xs ring-2 ring-white">
            <?= $notif_total_unread > 99 ? '99+' : $notif_total_unread ?>
        </span>
    </button>

    <!-- Dropdown Popover -->
    <div class="js-notif-popover hidden absolute right-0 top-full mt-2 w-80 sm:w-96 bg-white rounded-xl shadow-xl border border-gray-100 z-[9999] overflow-hidden text-left">
        <div class="flex flex-col max-h-[450px]">
        <div class="px-4 py-3 bg-[#FAF8F6] border-b border-gray-100 flex items-center justify-between gap-2 shrink-0">
            <div>
                <div class="text-xs font-extrabold text-[#59443F] tracking-wide uppercase flex items-center gap-1.5">
                    <i class="fa-regular <?= $notif_is_af ? 'fa-bell' : 'fa-calendar-check' ?> text-[#0f766e]"></i>
                    <span><?= $notif_is_af ? 'Notifications' : 'Upcoming Events' ?></span>
                </div>
                <div class="text-[11px] font-medium text-[#8B7168] mt-0.5">
                    <?= $notif_is_af ? 'System alerts &amp; next 7 days events' : 'Today &amp; next 7 days' ?>
                </div>
            </div>
            <button type="button"
                    class="js-notif-mark-read <?= $notif_total_unread > 0 ? 'inline-flex' : 'hidden' ?> items-center gap-1 text-[11px] font-bold text-[#0f766e] hover:text-[#115e59] hover:underline bg-white px-2.5 py-1 rounded-lg border border-gray-200 cursor-pointer transition">
                <i class="fa-solid fa-check-double text-[10px]"></i>
                <span>Mark all as read</span>
            </button>
        </div>

        <?php if ($notif_is_af): ?>
            <div class="grid grid-cols-2 bg-gray-50/80 border-b border-gray-100 p-1 gap-1 shrink-0">
                <button type="button"
                        data-tab="alerts"
                        class="js-notif-tab-btn flex items-center justify-center gap-1.5 py-1.5 px-2 rounded-lg text-[11px] font-bold transition cursor-pointer border-0 <?= $notif_default_tab === 'alerts' ? 'bg-white text-[#59443F] shadow-xs' : 'bg-transparent text-[#8B7168] hover:text-[#59443F]' ?>">
                    <i class="fa-solid fa-triangle-exclamation text-[10px]"></i>
                    <span>Alerts</span>
                    <span class="js-notif-alerts-count inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[10px] font-extrabold <?= $notif_alerts_unread > 0 ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-600' ?>">
                        <?= $notif_alerts_unread ?>
                    </span>
                </button>
                <button type="button"
                        data-tab="events"
                        class="js-notif-tab-btn flex items-center justify-center gap-1.5 py-1.5 px-2 rounded-lg text-[11px] font-bold transition cursor-pointer border-0 <?= $notif_default_tab === 'events' ? 'bg-white text-[#59443F] shadow-xs' : 'bg-transparent text-[#8B7168] hover:text-[#59443F]' ?>">
                    <i class="fa-regular fa-calendar-check text-[10px]"></i>
                    <span>Upcoming Events</span>
                    <span class="js-notif-events-count inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[10px] font-extrabold <?= $notif_event_unread > 0 ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-600' ?>">
                        <?= count($notif_events) ?>
                    </span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($notif_is_af): ?>
            <div class="js-notif-alerts-panel flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100 <?= $notif_default_tab === 'alerts' ? '' : 'hidden' ?>">
                <?php if (count($notif_alerts) === 0): ?>
                    <div class="px-4 py-8 text-center">
                        <div class="w-10 h-10 mx-auto rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mb-2">
                            <i class="fa-regular fa-bell-slash text-base"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-600 m-0">No unread system alerts</p>
                        <p class="text-xs text-gray-400 mt-1 mb-0">Irregular status and GPA notices will appear here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notif_alerts as $na): ?>
                        <div class="px-4 py-3 hover:bg-[#FAF8F6] transition js-notif-alert-item bg-[#F8EEEE]/40"
                             data-alert-id="<?= (int)$na['id'] ?>">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs font-extrabold text-[#59443F] leading-snug">
                                    <?= htmlspecialchars((string)$na['title']) ?>
                                </span>
                                <span class="shrink-0 text-[10px] font-semibold text-[#8B7168]">
                                    <?= htmlspecialchars((string)$na['date_label']) ?>
                                </span>
                            </div>
                            <p class="text-[11px] text-[#8B7168] leading-relaxed mt-1 mb-2">
                                <?= htmlspecialchars((string)$na['message']) ?>
                            </p>
                            <div class="flex items-center justify-between gap-2">
                                <?php if (!empty($na['student_id'])): ?>
                                    <a href="<?= htmlspecialchars($notif_student_record_url . '?student_id=' . (int)$na['student_id'], ENT_QUOTES, 'UTF-8') ?>"
                                       class="text-[10px] font-bold text-[#0f766e] hover:underline no-underline">
                                        View Record &rarr;
                                    </a>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <button type="button"
                                        class="js-notif-mark-alert-read inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold border border-[#E8C7C5]/80 transition shadow-2xs cursor-pointer"
                                        data-alert-id="<?= (int)$na['id'] ?>">
                                    <i class="fa-solid fa-check text-[9px]"></i>
                                    <span>Mark Read</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="js-notif-list flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100 <?= ($notif_is_af && $notif_default_tab !== 'events') ? 'hidden' : '' ?>">
            <?php if (count($notif_events) === 0): ?>
                <div class="px-4 py-8 text-center">
                    <div class="w-10 h-10 mx-auto rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mb-2">
                        <i class="fa-regular fa-bell-slash text-base"></i>
                    </div>
                    <p class="text-sm font-semibold text-gray-600 m-0">No upcoming events</p>
                    <p class="text-xs text-gray-400 mt-1 mb-0">Scheduled events for the next 7 days will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notif_events as $nev): ?>
                    <a href="<?= htmlspecialchars($notif_timetable_url . '?year=' . date('Y', strtotime($nev['date'])) . '&month=' . date('n', strtotime($nev['date'])), ENT_QUOTES, 'UTF-8') ?>"
                       class="block px-4 py-3 hover:bg-[#FAF8F6] transition no-underline js-notif-item <?= empty($nev['is_read']) ? 'bg-amber-50/30' : '' ?>"
                       data-event-id="<?= (int)$nev['id'] ?>">
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-xs font-bold text-[#59443F] leading-snug break-words">
                                <?= htmlspecialchars((string)$nev['title']) ?>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= htmlspecialchars((string)$nev['tag_class']) ?>">
                                <?= htmlspecialchars((string)$nev['time_tag']) ?>
                            </span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[#8B7168] font-medium">
                            <span><i class="fa-regular fa-calendar mr-1 opacity-75"></i><?= htmlspecialchars((string)$nev['date_label']) ?></span>
                            <?php if (!empty($nev['time_label'])): ?>
                                <span><i class="fa-regular fa-clock mr-1 opacity-75"></i><?= htmlspecialchars((string)$nev['time_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($nev['location'])): ?>
                                <span><i class="fa-solid fa-location-dot mr-1 opacity-75"></i><?= htmlspecialchars((string)$nev['location']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="px-4 py-2.5 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-[11px] shrink-0">
            <span class="text-gray-500 font-medium js-notif-footer-count">
                <?php if ($notif_is_af): ?>
                    <?= $notif_alerts_unread ?> alert<?= $notif_alerts_unread === 1 ? '' : 's' ?> &bull; <?= count($notif_events) ?> event<?= count($notif_events) === 1 ? '' : 's' ?>
                <?php else: ?>
                    <?= count($notif_events) ?> scheduled event<?= count($notif_events) === 1 ? '' : 's' ?>
                <?php endif; ?>
            </span>
            <a href="<?= htmlspecialchars($notif_timetable_url, ENT_QUOTES, 'UTF-8') ?>" class="font-bold text-[#0f766e] hover:underline no-underline">
                View Full Timetable &rarr;
            </a>
        </div>
        </div>
    </div>
</div>

<?php if (!defined('CAPSTONE_NOTIF_BELL_JS_EMITTED')): define('CAPSTONE_NOTIF_BELL_JS_EMITTED', true); ?>
<script>
(function() {
    if (window.__enformasysNotifBellInit) return;
    window.__enformasysNotifBellInit = true;

    function getStorageKey(uid) {
        return 'enformasys_read_events_u_' + String(uid || 0);
    }

    function getLocalReadIds(uid) {
        try {
            const raw = localStorage.getItem(getStorageKey(uid));
            const arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr.map(Number).filter(Boolean) : [];
        } catch (e) {
            return [];
        }
    }

    function saveLocalReadIds(uid, ids) {
        try {
            const existing = getLocalReadIds(uid);
            const merged = Array.from(new Set(existing.concat((ids || []).map(Number).filter(Boolean))));
            localStorage.setItem(getStorageKey(uid), JSON.stringify(merged));
        } catch (e) {
            // ignore storage errors
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setActiveTab(root, tab) {
        const activeTab = (tab === 'alerts' && root.getAttribute('data-is-af') === '1') ? 'alerts' : 'events';
        root.setAttribute('data-active-tab', activeTab);

        const alertsPanel = root.querySelector('.js-notif-alerts-panel');
        const eventsPanel = root.querySelector('.js-notif-list');
        if (alertsPanel) alertsPanel.classList.toggle('hidden', activeTab !== 'alerts');
        if (eventsPanel) eventsPanel.classList.toggle('hidden', activeTab !== 'events');

        root.querySelectorAll('.js-notif-tab-btn').forEach(function(btn) {
            const isTarget = btn.getAttribute('data-tab') === activeTab;
            btn.classList.toggle('bg-white', isTarget);
            btn.classList.toggle('text-[#59443F]', isTarget);
            btn.classList.toggle('shadow-xs', isTarget);
            btn.classList.toggle('bg-transparent', !isTarget);
            btn.classList.toggle('text-[#8B7168]', !isTarget);
        });
    }

    function computeEventUnread(root, events, localRead) {
        if (Array.isArray(events)) {
            let cnt = 0;
            events.forEach(function(ev) {
                const eid = Number(ev.id || 0);
                if (!ev.is_read && !localRead.has(eid)) cnt++;
            });
            return cnt;
        }
        let ids = [];
        try {
            ids = JSON.parse(root.getAttribute('data-event-ids') || '[]') || [];
        } catch (e) {
            ids = [];
        }
        const serverUnread = parseInt(root.getAttribute('data-server-unread') || '0', 10) || 0;
        const items = root.querySelectorAll('.js-notif-item');
        if (items.length > 0) {
            let domUnread = 0;
            items.forEach(function(item) {
                const eid = Number(item.getAttribute('data-event-id') || 0);
                if (item.classList.contains('bg-amber-50/30') && !localRead.has(eid)) {
                    domUnread++;
                } else if (localRead.has(eid)) {
                    item.classList.remove('bg-amber-50/30');
                }
            });
            return Math.min(serverUnread, domUnread);
        }
        return Math.min(serverUnread, ids.filter(function(id) { return !localRead.has(Number(id)); }).length);
    }

    function syncBadgesAcrossRoots(events, alerts) {
        document.querySelectorAll('.js-notif-bell-root').forEach(function(root) {
            const uid = parseInt(root.getAttribute('data-user-id') || '0', 10) || 0;
            const isAf = root.getAttribute('data-is-af') === '1';
            const localRead = new Set(getLocalReadIds(uid));
            const ttUrl = root.getAttribute('data-timetable-url') || 'modules/timetable.php';
            const srUrl = root.getAttribute('data-student-record-url') || 'modules/afms_student_record.php';

            if (Array.isArray(events)) {
                root.setAttribute('data-event-ids', JSON.stringify(events.map(function(ev) { return Number(ev.id || 0); })));
                renderEventList(root, events, localRead, ttUrl);
            }
            if (isAf && Array.isArray(alerts)) {
                root.setAttribute('data-alerts-unread', String(alerts.length));
                renderAlertList(root, alerts, srUrl);
            }

            const eventUnread = computeEventUnread(root, events, localRead);
            const alertUnread = isAf ? (parseInt(root.getAttribute('data-alerts-unread') || '0', 10) || 0) : 0;
            const totalUnread = eventUnread + alertUnread;

            const badge = root.querySelector('.js-notif-badge');
            const markBtn = root.querySelector('.js-notif-mark-read');
            const alertsCntEl = root.querySelector('.js-notif-alerts-count');
            const eventsCntEl = root.querySelector('.js-notif-events-count');
            const footer = root.querySelector('.js-notif-footer-count');

            if (badge) {
                if (totalUnread > 0) {
                    badge.textContent = totalUnread > 99 ? '99+' : String(totalUnread);
                    badge.classList.remove('hidden');
                    badge.classList.add('inline-flex');
                } else {
                    badge.textContent = '0';
                    badge.classList.add('hidden');
                    badge.classList.remove('inline-flex');
                }
            }
            if (markBtn) {
                if (totalUnread > 0) {
                    markBtn.classList.remove('hidden');
                    markBtn.classList.add('inline-flex');
                } else {
                    markBtn.classList.add('hidden');
                    markBtn.classList.remove('inline-flex');
                }
            }
            if (alertsCntEl) {
                alertsCntEl.textContent = String(alertUnread);
                alertsCntEl.className = 'js-notif-alerts-count inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[10px] font-extrabold ' +
                    (alertUnread > 0 ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-600');
            }
            if (eventsCntEl) {
                const evCount = Array.isArray(events) ? events.length : root.querySelectorAll('.js-notif-item').length;
                eventsCntEl.textContent = String(evCount);
                eventsCntEl.className = 'js-notif-events-count inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[10px] font-extrabold ' +
                    (eventUnread > 0 ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-600');
            }
            if (footer) {
                const evCount = Array.isArray(events) ? events.length : root.querySelectorAll('.js-notif-item').length;
                if (isAf) {
                    footer.textContent = alertUnread + ' alert' + (alertUnread === 1 ? '' : 's') + ' \u2022 ' + evCount + ' event' + (evCount === 1 ? '' : 's');
                } else {
                    footer.textContent = evCount + ' scheduled event' + (evCount === 1 ? '' : 's');
                }
            }
        });
    }

    function renderAlertList(root, alerts, srUrl) {
        const panel = root.querySelector('.js-notif-alerts-panel');
        if (!panel) return;
        if (!alerts || alerts.length === 0) {
            panel.innerHTML =
                '<div class="px-4 py-8 text-center">' +
                '<div class="w-10 h-10 mx-auto rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mb-2">' +
                '<i class="fa-regular fa-bell-slash text-base"></i>' +
                '</div>' +
                '<p class="text-sm font-semibold text-gray-600 m-0">No unread system alerts</p>' +
                '<p class="text-xs text-gray-400 mt-1 mb-0">Irregular status and GPA notices will appear here.</p>' +
                '</div>';
            return;
        }
        panel.innerHTML = alerts.map(function(al) {
            const aid = Number(al.id || 0);
            const sid = Number(al.student_id || 0);
            const linkHtml = sid > 0
                ? '<a href="' + escapeHtml(srUrl + '?student_id=' + sid) + '" class="text-[10px] font-bold text-[#0f766e] hover:underline no-underline">View Record &rarr;</a>'
                : '<span></span>';
            return '<div class="px-4 py-3 hover:bg-[#FAF8F6] transition js-notif-alert-item bg-[#F8EEEE]/40" data-alert-id="' + aid + '">' +
                '<div class="flex items-start justify-between gap-2">' +
                '<span class="text-xs font-extrabold text-[#59443F] leading-snug">' + escapeHtml(al.title || al.type) + '</span>' +
                '<span class="shrink-0 text-[10px] font-semibold text-[#8B7168]">' + escapeHtml(al.date_label || '') + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-[#8B7168] leading-relaxed mt-1 mb-2">' + escapeHtml(al.message) + '</p>' +
                '<div class="flex items-center justify-between gap-2">' +
                linkHtml +
                '<button type="button" class="js-notif-mark-alert-read inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white hover:bg-[#E8C7C5] text-[#59443F] text-[10px] font-bold border border-[#E8C7C5]/80 transition shadow-2xs cursor-pointer" data-alert-id="' + aid + '">' +
                '<i class="fa-solid fa-check text-[9px]"></i><span>Mark Read</span>' +
                '</button></div></div>';
        }).join('');
    }

    function renderEventList(root, events, localReadSet, ttUrl) {
        const list = root.querySelector('.js-notif-list');
        if (!list) return;

        if (!events || events.length === 0) {
            list.innerHTML =
                '<div class="px-4 py-8 text-center">' +
                '<div class="w-10 h-10 mx-auto rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mb-2">' +
                '<i class="fa-regular fa-bell-slash text-base"></i>' +
                '</div>' +
                '<p class="text-sm font-semibold text-gray-600 m-0">No upcoming events</p>' +
                '<p class="text-xs text-gray-400 mt-1 mb-0">Scheduled events for the next 7 days will appear here.</p>' +
                '</div>';
            return;
        }

        list.innerHTML = events.map(function(ev) {
            const eid = Number(ev.id || 0);
            const isUnread = !ev.is_read && !localReadSet.has(eid);
            const dParts = String(ev.date || '').split('-');
            const q = dParts.length === 3 ? ('?year=' + Number(dParts[0]) + '&month=' + Number(dParts[1])) : '';
            return '<a href="' + escapeHtml(ttUrl + q) + '" class="block px-4 py-3 hover:bg-[#FAF8F6] transition no-underline js-notif-item ' + (isUnread ? 'bg-amber-50/30' : '') + '" data-event-id="' + eid + '">' +
                '<div class="flex items-start justify-between gap-2">' +
                '<div class="text-xs font-bold text-[#59443F] leading-snug break-words">' + escapeHtml(ev.title) + '</div>' +
                '<span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold ' + escapeHtml(ev.tag_class) + '">' + escapeHtml(ev.time_tag) + '</span>' +
                '</div>' +
                '<div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[#8B7168] font-medium">' +
                '<span><i class="fa-regular fa-calendar mr-1 opacity-75"></i>' + escapeHtml(ev.date_label) + '</span>' +
                (ev.time_label ? '<span><i class="fa-regular fa-clock mr-1 opacity-75"></i>' + escapeHtml(ev.time_label) + '</span>' : '') +
                (ev.location ? '<span><i class="fa-solid fa-location-dot mr-1 opacity-75"></i>' + escapeHtml(ev.location) + '</span>' : '') +
                '</div></a>';
        }).join('');
    }

    async function refreshNotifications(root) {
        const ajaxUrl = root.getAttribute('data-ajax-url');
        if (!ajaxUrl) return;
        try {
            const res = await fetch(ajaxUrl + '?action=upcoming_events_notifications', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data && data.ok && Array.isArray(data.events)) {
                root.setAttribute('data-server-unread', String(parseInt(data.unread_count || 0, 10) || 0));
                if (Array.isArray(data.alerts)) {
                    root.setAttribute('data-alerts-unread', String(parseInt(data.alerts_unread || data.alerts.length || 0, 10) || 0));
                }
                syncBadgesAcrossRoots(data.events, Array.isArray(data.alerts) ? data.alerts : null);
            }
        } catch (e) {
            // Keep SSR fallback silently
        }
    }

    async function markSingleAlertRead(root, alertId) {
        const ajaxUrl = root.getAttribute('data-ajax-url');
        const csrf = root.getAttribute('data-csrf') || '';
        const srUrl = root.getAttribute('data-student-record-url') || 'modules/afms_student_record.php';
        if (!alertId || !ajaxUrl) return;

        // Optimistic UI removal + badge decrement
        document.querySelectorAll('.js-notif-bell-root').forEach(function(r) {
            const item = r.querySelector('.js-notif-alert-item[data-alert-id="' + alertId + '"]');
            if (item) item.remove();
            const curUnread = parseInt(r.getAttribute('data-alerts-unread') || '0', 10) || 0;
            const nextUnread = Math.max(0, curUnread - 1);
            r.setAttribute('data-alerts-unread', String(nextUnread));
            if (nextUnread === 0) {
                renderAlertList(r, [], srUrl);
            }
        });
        syncBadgesAcrossRoots(null, null);

        try {
            const fd = new FormData();
            fd.append('action', 'mark_academic_alert_read');
            fd.append('csrf_token', csrf);
            fd.append('notification_id', String(alertId));
            const res = await fetch(ajaxUrl + '?action=mark_academic_alert_read', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data && data.ok && Array.isArray(data.alerts)) {
                syncBadgesAcrossRoots(null, data.alerts);
            }
        } catch (e) {
            // ignore network error cleanly
        }
    }

    async function markAllRead(root) {
        const uid = parseInt(root.getAttribute('data-user-id') || '0', 10) || 0;
        const isAf = root.getAttribute('data-is-af') === '1';
        const ajaxUrl = root.getAttribute('data-ajax-url');
        const csrf = root.getAttribute('data-csrf') || '';
        const srUrl = root.getAttribute('data-student-record-url') || 'modules/afms_student_record.php';
        let ids = [];
        try {
            ids = JSON.parse(root.getAttribute('data-event-ids') || '[]') || [];
        } catch (e) {
            ids = [];
        }
        saveLocalReadIds(uid, ids);
        document.querySelectorAll('.js-notif-bell-root').forEach(function(r) {
            r.setAttribute('data-server-unread', '0');
            if (isAf) {
                r.setAttribute('data-alerts-unread', '0');
                renderAlertList(r, [], srUrl);
            }
        });
        document.querySelectorAll('.js-notif-item').forEach(function(el) {
            el.classList.remove('bg-amber-50/30');
        });
        syncBadgesAcrossRoots(null, null);

        if (ajaxUrl) {
            try {
                const fd = new FormData();
                fd.append('action', 'mark_upcoming_events_read');
                fd.append('csrf_token', csrf);
                fd.append('event_ids', JSON.stringify(ids));
                if (isAf) fd.append('include_alerts', '1');
                await fetch(ajaxUrl + '?action=mark_upcoming_events_read', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            } catch (e) {
                // localStorage preserves event state
            }
        }
    }

    function closeAllPopovers(exceptRoot) {
        document.querySelectorAll('.js-notif-bell-root').forEach(function(r) {
            if (r === exceptRoot) return;
            const pop = r.querySelector('.js-notif-popover');
            const btn = r.querySelector('.js-notif-bell-btn');
            if (pop) pop.classList.add('hidden');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function(e) {
        const bellBtn = e.target.closest('.js-notif-bell-btn');
        if (bellBtn) {
            e.preventDefault();
            e.stopPropagation();
            const root = bellBtn.closest('.js-notif-bell-root');
            const pop = root ? root.querySelector('.js-notif-popover') : null;
            if (!pop) return;
            const willOpen = pop.classList.contains('hidden');
            closeAllPopovers(willOpen ? root : null);
            pop.classList.toggle('hidden', !willOpen);
            bellBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                refreshNotifications(root);
            }
            return;
        }

        const tabBtn = e.target.closest('.js-notif-tab-btn');
        if (tabBtn) {
            e.preventDefault();
            e.stopPropagation();
            const root = tabBtn.closest('.js-notif-bell-root');
            if (root) setActiveTab(root, tabBtn.getAttribute('data-tab'));
            return;
        }

        const singleAlertBtn = e.target.closest('.js-notif-mark-alert-read');
        if (singleAlertBtn) {
            e.preventDefault();
            e.stopPropagation();
            const root = singleAlertBtn.closest('.js-notif-bell-root');
            const alertId = parseInt(singleAlertBtn.getAttribute('data-alert-id') || '0', 10) || 0;
            if (root && alertId > 0) markSingleAlertRead(root, alertId);
            return;
        }

        const markBtn = e.target.closest('.js-notif-mark-read');
        if (markBtn) {
            e.preventDefault();
            e.stopPropagation();
            const root = markBtn.closest('.js-notif-bell-root');
            if (root) markAllRead(root);
            return;
        }

        if (!e.target.closest('.js-notif-popover')) {
            closeAllPopovers(null);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllPopovers(null);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { syncBadgesAcrossRoots(null, null); });
    } else {
        syncBadgesAcrossRoots(null, null);
    }
})();
</script>
<?php endif; ?>
