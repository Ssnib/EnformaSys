<?php
include '../includes/session_fix.php';
require_roles(['Cashier']);
include '../includes/db.php';

$status = trim((string)($_GET['status'] ?? 'Pending'));
$allowed_status = ['Pending', 'Processing', 'Sent', 'Completed'];
if (!in_array($status, $allowed_status, true)) {
    $status = 'Pending';
}

if (isset($_POST['update_request_status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $req_id = (int)($_POST['request_id'] ?? 0);
    $new_status = trim((string)($_POST['new_status'] ?? ''));
    if ($req_id > 0 && in_array($new_status, $allowed_status, true)) {
        $processed_by = (int)($_SESSION['user'] ?? 0);
        $processed_at = in_array($new_status, ['Sent', 'Completed'], true) ? date('Y-m-d H:i:s') : null;
        if ($new_status === 'Sent') {
            $stmt = $conn->prepare("UPDATE cashier_soa_requests SET status = ?, processed_by = ?, processed_at = COALESCE(?, processed_at), is_read = 0 WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE cashier_soa_requests SET status = ?, processed_by = ?, processed_at = COALESCE(?, processed_at) WHERE id = ?");
        }
        $stmt->bind_param('sisi', $new_status, $processed_by, $processed_at, $req_id);
        $stmt->execute();
        $success = $new_status === 'Sent' ? 'SOA request accepted and sent to the seminarian.' : 'Request updated.';
    }
}

$status_counts = ['Pending' => 0, 'Processing' => 0, 'Sent' => 0, 'Completed' => 0];
$res_sc = $conn->query("SELECT status, COUNT(*) as c FROM cashier_soa_requests GROUP BY status");
if ($res_sc) {
    while ($rsc = $res_sc->fetch_assoc()) {
        $st = (string)($rsc['status'] ?? '');
        if (isset($status_counts[$st])) {
            $status_counts[$st] = (int)$rsc['c'];
        }
    }
}

$requests = [];
$stmt = $conn->prepare(
    "SELECT r.*, COALESCE(up.full_name, u.username) as full_name\n"
    . "FROM cashier_soa_requests r\n"
    . "JOIN seminarians s ON s.id = r.seminarian_id\n"
    . "JOIN users u ON u.id = s.user_id\n"
    . "LEFT JOIN user_profiles up ON up.user_id = u.id\n"
    . "WHERE r.status = ?\n"
    . "ORDER BY r.requested_at DESC, r.id DESC\n"
    . "LIMIT 200"
);
$stmt->bind_param('s', $status);
$stmt->execute();
$res = $stmt->get_result();
while ($res && ($r = $res->fetch_assoc())) {
    $requests[] = $r;
}
?>
<?php
$pageTitle = 'SOA Requests';
$activeNav = 'cashier_requests';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body minimal-container">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 class="page-title" style="margin: 0 0 0.25rem 0;"><i class="fas fa-file-invoice"></i> Statement of Account Requests</h1>
                    <div style="color: var(--text-secondary); font-size: 0.85rem;">Review, process, and release digital statements of accounts to seminarians.</div>
                </div>
            </div>

            <?php if (isset($success)) echo "<p class='success' style='margin-bottom: 1rem;'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error' style='margin-bottom: 1rem;'>" . htmlspecialchars($error) . "</p>"; ?>
            <div id="ajaxMessage"></div>

            <!-- Stats Overview -->
            <div class="stats-row">
                <div class="stat-pill" style="border-left: 3px solid #f59e0b;">
                    <div class="stat-pill-label">Pending Requests</div>
                    <div class="stat-pill-val" data-stat-count="Pending" style="color: #d97706;"><?php echo (int)$status_counts['Pending']; ?></div>
                </div>
                <div class="stat-pill" style="border-left: 3px solid #3b82f6;">
                    <div class="stat-pill-label">In Processing</div>
                    <div class="stat-pill-val" data-stat-count="Processing" style="color: #2563eb;"><?php echo (int)$status_counts['Processing']; ?></div>
                </div>
                <div class="stat-pill" style="border-left: 3px solid #0f766e;">
                    <div class="stat-pill-label">Sent / Emailed</div>
                    <div class="stat-pill-val" data-stat-count="Sent" style="color: #0f766e;"><?php echo (int)$status_counts['Sent']; ?></div>
                </div>
                <div class="stat-pill" style="border-left: 3px solid #10b981;">
                    <div class="stat-pill-label">Completed</div>
                    <div class="stat-pill-val" data-stat-count="Completed" style="color: #059669;"><?php echo (int)$status_counts['Completed']; ?></div>
                </div>
            </div>

            <div class="minimal-card">
                <div class="minimal-toolbar">
                    <!-- 1-Click Status Navigation Pills -->
                    <div class="pill-group">
                        <?php foreach ($allowed_status as $s) { ?>
                            <a href="cashier_requests.php?status=<?php echo urlencode($s); ?>"
                               data-pill-status="<?php echo htmlspecialchars($s); ?>"
                               class="pill-btn <?php echo $s === $status ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($s); ?>
                                <span data-pill-count="<?php echo htmlspecialchars($s); ?>" style="font-size: 0.75rem; opacity: 0.75; margin-left: 4px;">(<?php echo (int)($status_counts[$s] ?? 0); ?>)</span>
                            </a>
                        <?php } ?>
                    </div>

                    <!-- Live Instant Filter -->
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="soaSearchInput" placeholder="Filter student or ID..." autocomplete="off">
                        <span class="search-kbd">/</span>
                    </div>
                </div>

                <!-- Hidden fallback form for CapstoneAjax compat -->
                <form method="get" id="soaFilterForm" style="display: none;">
                    <select name="status">
                        <?php foreach ($allowed_status as $s) { ?>
                            <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $s === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                        <?php } ?>
                    </select>
                </form>

                <div style="overflow-x:auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th>Date Requested</th>
                                <th>Seminarian</th>
                                <th>Academic Term</th>
                                <th>Status</th>
                                <th style="text-align: right;">Quick Actions</th>
                            </tr>
                        </thead>
                        <tbody id="soaTbody">
                            <?php if (count($requests) === 0) { ?>
                                <tr><td colspan="5" style="color: var(--text-secondary); text-align: center; padding: 2rem;">No <?php echo strtolower(htmlspecialchars($status)); ?> requests.</td></tr>
                            <?php } else { foreach ($requests as $r) {
                                $rid = (int)($r['id'] ?? 0);
                                $sid = (int)($r['seminarian_id'] ?? 0);
                                $badge_class = 'status-muted';
                                if ($r['status'] === 'Pending') $badge_class = 'status-due';
                                elseif ($r['status'] === 'Processing') $badge_class = 'status-info';
                                elseif ($r['status'] === 'Sent' || $r['status'] === 'Completed') $badge_class = 'status-cleared';
                            ?>
                                <tr class="soa-row" data-request-row="<?php echo $rid; ?>" data-search="<?php echo htmlspecialchars(strtolower(($r['full_name'] ?? '') . ' ' . $sid . ' ' . ($r['academic_year'] ?? ''))); ?>">
                                    <td><i class="far fa-clock" style="color: #94a3b8; margin-right: 4px;"></i> <?php echo htmlspecialchars((string)($r['requested_at'] ?? '')); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string)($r['full_name'] ?? '')); ?></strong>
                                        <div style="color: var(--text-secondary); font-size: 0.75rem;">Student ID: <?php echo $sid; ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($r['academic_year'] ?? '') . ' · ' . (string)($r['semester'] ?? '')); ?></td>
                                    <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></span></td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div class="inline-flex items-center justify-end gap-2">
                                            <a class="action-btn-secondary inline-flex items-center gap-1.5" href="cashier_soa.php?student_id=<?php echo $sid; ?>&academic_year=<?php echo urlencode((string)($r['academic_year'] ?? '')); ?>&semester=<?php echo urlencode((string)($r['semester'] ?? '')); ?>" title="View Statement of Account">
                                                <i class="fas fa-file-invoice"></i> View SOA
                                            </a>

                                            <?php if (in_array((string)($r['status'] ?? ''), ['Pending', 'Processing'], true)) { ?>
                                                <form method="post" class="soaAcceptForm inline-block" data-request-id="<?php echo $rid; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="request_id" value="<?php echo $rid; ?>">
                                                    <input type="hidden" name="new_status" value="Sent">
                                                    <button type="submit" name="update_request_status" class="soa-accept-btn bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium inline-flex items-center gap-1.5 transition cursor-pointer" data-request-id="<?php echo $rid; ?>" title="Accept and send Statement of Account to student">
                                                        <i class="fas fa-check"></i>
                                                        <span>Accept &amp; Send</span>
                                                    </button>
                                                </form>
                                            <?php } else { ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <i class="fas fa-check-circle"></i>
                                                    <span><?php echo htmlspecialchars((string)($r['status'] ?? 'Sent')); ?></span>
                                                </span>
                                            <?php } ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function () {
            const msg = document.getElementById('ajaxMessage');
            const form = document.getElementById('soaFilterForm');
            const tbody = document.getElementById('soaTbody');
            if (!form || !tbody) return;

            function setMessage(type, text) {
                if (!msg) return;
                msg.innerHTML = '';
                if (!text) return;
                const p = document.createElement('p');
                p.className = type === 'error' ? 'error' : 'success';
                p.style.marginBottom = '1rem';
                p.textContent = text;
                msg.appendChild(p);
            }

            function updateCounts(counts) {
                if (!counts || typeof counts !== 'object') return;
                Object.keys(counts).forEach(function (st) {
                    const statEl = document.querySelector('[data-stat-count="' + st + '"]');
                    if (statEl) statEl.textContent = String(counts[st]);
                    const pillEl = document.querySelector('[data-pill-count="' + st + '"]');
                    if (pillEl) pillEl.textContent = '(' + String(counts[st]) + ')';
                });
                const sidebarBadge = document.querySelector('[data-badge-for="cashier_requests"]');
                if (sidebarBadge && typeof counts.Pending !== 'undefined') {
                    const pendingCount = parseInt(counts.Pending, 10) || 0;
                    if (pendingCount > 0) {
                        sidebarBadge.textContent = String(pendingCount);
                        sidebarBadge.style.display = '';
                    } else {
                        sidebarBadge.style.display = 'none';
                    }
                }
            }

            function escapeHtml(str) {
                return String(str || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;

            function renderRows(rows) {
                tbody.innerHTML = '';
                const currentStatus = (document.querySelector('#soaFilterForm select[name="status"]') || {}).value || 'Pending';
                if (!rows || rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="color: var(--text-secondary); text-align: center; padding: 2rem;">No ' + escapeHtml(currentStatus.toLowerCase()) + ' requests.</td></tr>';
                    return;
                }
                rows.forEach(function (r) {
                    const rid = parseInt(r.id, 10) || 0;
                    const sid = parseInt(r.seminarian_id, 10) || 0;
                    const st = String(r.status || '');
                    let badgeClass = 'status-muted';
                    if (st === 'Pending') badgeClass = 'status-due';
                    else if (st === 'Processing') badgeClass = 'status-info';
                    else if (st === 'Sent' || st === 'Completed') badgeClass = 'status-cleared';

                    const soaUrl = 'cashier_soa.php?student_id=' + sid
                        + '&academic_year=' + encodeURIComponent(r.academic_year || '')
                        + '&semester=' + encodeURIComponent(r.semester || '');

                    let actionHtml = '<a class="action-btn-secondary inline-flex items-center gap-1.5" href="' + escapeHtml(soaUrl) + '" title="View Statement of Account">'
                        + '<i class="fas fa-file-invoice"></i> View SOA</a>';

                    if (st === 'Pending' || st === 'Processing') {
                        actionHtml += '<form method="post" class="soaAcceptForm inline-block" data-request-id="' + rid + '">'
                            + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
                            + '<input type="hidden" name="request_id" value="' + rid + '">'
                            + '<input type="hidden" name="new_status" value="Sent">'
                            + '<button type="submit" name="update_request_status" class="soa-accept-btn bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium inline-flex items-center gap-1.5 transition cursor-pointer" data-request-id="' + rid + '">'
                            + '<i class="fas fa-check"></i><span>Accept &amp; Send</span>'
                            + '</button></form>';
                    } else {
                        actionHtml += '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">'
                            + '<i class="fas fa-check-circle"></i><span>' + escapeHtml(st) + '</span></span>';
                    }

                    const searchText = ((r.full_name || '') + ' ' + sid + ' ' + (r.academic_year || '')).toLowerCase();
                    const tr = document.createElement('tr');
                    tr.className = 'soa-row';
                    tr.setAttribute('data-request-row', String(rid));
                    tr.setAttribute('data-search', searchText);
                    tr.innerHTML =
                        '<td><i class="far fa-clock" style="color: #94a3b8; margin-right: 4px;"></i> ' + escapeHtml(r.requested_at || '') + '</td>' +
                        '<td><strong>' + escapeHtml(r.full_name || '') + '</strong><div style="color: var(--text-secondary); font-size: 0.75rem;">Student ID: ' + sid + '</div></td>' +
                        '<td>' + escapeHtml((r.academic_year || '') + ' · ' + (r.semester || '')) + '</td>' +
                        '<td><span class="status-badge ' + badgeClass + '">' + escapeHtml(st) + '</span></td>' +
                        '<td style="text-align: right; white-space: nowrap;"><div class="inline-flex items-center justify-end gap-2">' + actionHtml + '</div></td>';
                    tbody.appendChild(tr);
                });
            }

            async function load(status) {
                if (!window.CapstoneAjax) return;
                const data = await window.CapstoneAjax.get('ajax.php', { action: 'cashier_soa_requests_list', status: status });
                if (data.counts) updateCounts(data.counts);
                renderRows(data.requests || []);
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                setMessage('', '');
                try {
                    const fd = new FormData(form);
                    await load(fd.get('status') || 'Pending');
                } catch (err) {
                    setMessage('error', err && err.message ? err.message : 'Failed to load requests.');
                }
            });

            tbody.addEventListener('submit', async function (e) {
                const target = e.target;
                if (!(target instanceof HTMLFormElement)) return;
                if (!target.classList.contains('soaAcceptForm')) return;
                if (!window.CapstoneAjax) return; // Allow standard POST fallback if CapstoneAjax unavailable
                e.preventDefault();
                setMessage('', '');

                const btn = target.querySelector('.soa-accept-btn');
                const row = target.closest('tr');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Sending...</span>';
                }

                try {
                    const fd = new FormData(target);
                    fd.append('action', 'cashier_soa_requests_update');
                    const resp = await window.CapstoneAjax.post('ajax.php?action=cashier_soa_requests_update', fd);
                    if (resp && resp.counts) {
                        updateCounts(resp.counts);
                    }
                    setMessage('success', 'SOA request accepted and sent to the seminarian.');
                    if (row) {
                        row.style.transition = 'opacity 180ms ease';
                        row.style.opacity = '0';
                        setTimeout(function () {
                            row.remove();
                            if (tbody.querySelectorAll('tr.soa-row').length === 0) {
                                const currentStatus = (document.querySelector('#soaFilterForm select[name="status"]') || {}).value || 'Pending';
                                tbody.innerHTML = '<tr><td colspan="5" style="color: var(--text-secondary); text-align: center; padding: 2rem;">No ' + escapeHtml(currentStatus.toLowerCase()) + ' requests.</td></tr>';
                            }
                        }, 180);
                    } else {
                        const status = (document.querySelector('#soaFilterForm select[name="status"]') || {}).value || 'Pending';
                        await load(status);
                    }
                } catch (err) {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i><span>Accept &amp; Send</span>';
                    }
                    setMessage('error', err && err.message ? err.message : 'Failed to accept request.');
                }
            });

            // Live in-page filter
            const searchInput = document.getElementById('soaSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const q = searchInput.value.toLowerCase().trim();
                    const rows = tbody.querySelectorAll('tr.soa-row');
                    rows.forEach(function (tr) {
                        const hay = tr.getAttribute('data-search') || '';
                        tr.style.display = hay.includes(q) ? '' : 'none';
                    });
                });
                window.addEventListener('keydown', function (e) {
                    if (e.key === '/' && document.activeElement !== searchInput && (!document.activeElement || (document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'SELECT' && document.activeElement.tagName !== 'TEXTAREA'))) {
                        e.preventDefault();
                        searchInput.focus();
                    }
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
