<?php
require_once __DIR__ . '/../includes/session_fix.php';
require_roles(['Dean', 'Registrar', 'Rector']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_helper.php';

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['Dean', 'Registrar', 'Rector'], true)) {
    capstone_redirect('index.php');
}

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'sync_triggers') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !verify_csrf_token($token)) {
        $flashMessage = 'Invalid or expired security token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        $syncRes = ensure_audit_trail_schema($conn, true);
        if (empty($syncRes['errors'])) {
            $flashMessage = 'Verified and refreshed ' . (int)$syncRes['created_triggers'] . ' MySQL audit triggers across all monitored grade and evaluation tables.';
            $uid = (int)($_SESSION['user'] ?? ($_SESSION['user_id'] ?? 0));
            if ($uid > 0 && function_exists('log_activity')) {
                try {
                    log_activity($uid, 'Re-synchronized MySQL audit trail triggers');
                } catch (\Throwable $e) {
                    // Non-fatal activity log failure
                }
            }
        } else {
            $flashMessage = 'Trigger sync completed with warnings: ' . implode(' | ', $syncRes['errors']);
            $flashType = 'error';
        }
    }
}

$monitoredTables = audit_trail_monitored_tables();
$filterTable = trim((string)($_GET['table'] ?? ''));
if ($filterTable !== '' && !isset($monitoredTables[$filterTable])) {
    $filterTable = '';
}
$filterAction = strtoupper(trim((string)($_GET['action'] ?? '')));
if (!in_array($filterAction, ['', 'UPDATE', 'DELETE'], true)) {
    $filterAction = '';
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if (!in_array($limit, [25, 50, 100, 200], true)) {
    $limit = 50;
}

$dbStats = backup_get_database_stats($conn);
$triggerStatus = audit_get_trigger_status($conn);
$activeTriggerCount = 0;
foreach ($triggerStatus as $ts) {
    if (!empty($ts['update_trigger'])) {
        $activeTriggerCount++;
    }
    if (!empty($ts['delete_trigger'])) {
        $activeTriggerCount++;
    }
}
$auditLogs = audit_fetch_recent_logs($conn, $limit, $filterTable, $filterAction);
$activityLogs = audit_fetch_activity_logs($conn, $limit);
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$mysqldumpAvailable = backup_find_mysqldump() !== null;

/**
 * Render an inline key/value diff preview for OLD vs NEW JSON blobs.
 */
function backup_audit_format_json(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return 'No snapshot recorded';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $pretty !== false ? $pretty : $raw;
}

function backup_audit_extract_changes(?string $oldRaw, ?string $newRaw): array
{
    $oldArr = $oldRaw ? json_decode($oldRaw, true) : null;
    $newArr = $newRaw ? json_decode($newRaw, true) : null;
    if (!is_array($oldArr)) {
        return [];
    }
    if (!is_array($newArr)) {
        // DELETE event: highlight key fields
        $items = [];
        foreach ($oldArr as $k => $v) {
            if ($v !== null && $v !== '') {
                $items[] = [
                    'field' => (string)$k,
                    'old' => is_scalar($v) ? (string)$v : (string)json_encode($v),
                    'new' => null,
                ];
            }
        }
        return array_slice($items, 0, 8);
    }
    $diffs = [];
    foreach ($oldArr as $k => $v) {
        $newVal = $newArr[$k] ?? null;
        if ($v !== $newVal) {
            $diffs[] = [
                'field' => (string)$k,
                'old' => $v === null ? 'NULL' : (is_scalar($v) ? (string)$v : (string)json_encode($v)),
                'new' => $newVal === null ? 'NULL' : (is_scalar($newVal) ? (string)$newVal : (string)json_encode($newVal)),
            ];
        }
    }
    return $diffs;
}

$pageTitle = 'Backup & Audit Trail';
$activeNav = 'backup_audit';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <?php if ($flashMessage !== ''): ?>
                <div class="mb-5 rounded-2xl border px-4 py-3 text-sm font-medium flex items-center gap-3 <?php echo $flashType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                    <i class="fa-solid <?php echo $flashType === 'success' ? 'fa-circle-check text-emerald-600' : 'fa-triangle-exclamation text-rose-600'; ?>"></i>
                    <span><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Band 1: System & Data Backup Floating Container -->
            <section aria-labelledby="system-backup-heading" class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 mb-6 font-['Inter',sans-serif]">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5 pb-5 border-b border-[#E8C7C5]/35">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/60 text-[#59443F] flex items-center justify-center shrink-0 shadow-xs">
                            <i class="fa-solid fa-database text-lg text-[#D6A6A6]"></i>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h1 id="system-backup-heading" class="font-heading text-xl sm:text-2xl font-extrabold text-[#59443F] tracking-tight">System &amp; Data Backup</h1>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60">
                                    <span class="w-2 h-2 rounded-full <?php echo $activeTriggerCount === count($monitoredTables) * 2 ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span>
                                    <?php echo (int)$activeTriggerCount; ?>/<?php echo (int)(count($monitoredTables) * 2); ?> Audit Triggers Active
                                </span>
                            </div>
                            <p class="text-sm text-[#8B7168] mt-1">
                                Export a complete MySQL `.sql` snapshot (schema, records, views, and audit triggers) and inspect immutable database change logs.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 shrink-0">
                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="sync_triggers">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5]/50 text-[#59443F] border border-[#E8C7C5]/70 text-xs font-bold transition shadow-xs cursor-pointer">
                                <i class="fa-solid fa-rotate text-[#8B7168]"></i>
                                Re-sync Triggers
                            </button>
                        </form>
                        <a href="<?php echo htmlspecialchars('backup_database.php?csrf_token=' . urlencode($csrfToken), ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex items-center gap-2.5 px-5 py-2.5 rounded-2xl bg-[#59443F] hover:bg-[#6E554F] text-white text-sm font-bold transition shadow-md">
                            <i class="fa-solid fa-cloud-arrow-down text-[#E8C7C5]"></i>
                            Download Full Backup
                        </a>
                    </div>
                </div>

                <!-- Summary Pills -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-5">
                    <div class="p-4 rounded-2xl bg-[#F8EEEE]/75 border border-[#E8C7C5]/40">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">Active Schema</div>
                        <div class="text-xl font-black text-[#59443F] mt-1 truncate"><?php echo htmlspecialchars($dbStats['database'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-xs text-[#8B7168] mt-1"><?php echo (int)$dbStats['table_count']; ?> tables · <?php echo (int)$dbStats['view_count']; ?> views</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-[#F8EEEE]/75 border border-[#E8C7C5]/40">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">Estimated Data Size</div>
                        <div class="text-xl font-black text-[#59443F] mt-1"><?php echo number_format((float)$dbStats['size_mb'], 2); ?> MB</div>
                        <div class="text-xs text-[#8B7168] mt-1">Engine: <?php echo $mysqldumpAvailable ? 'mysqldump + PHP fallback' : 'Pure PHP SQL exporter'; ?></div>
                    </div>
                    <div class="p-4 rounded-2xl bg-[#F8EEEE]/75 border border-[#E8C7C5]/40">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">Protected Tables</div>
                        <div class="text-xl font-black text-[#59443F] mt-1"><?php echo (int)count($monitoredTables); ?> Tables</div>
                        <div class="text-xs text-[#8B7168] mt-1">Grades, GPA &amp; 4 Formation Pillars</div>
                    </div>
                    <div class="p-4 rounded-2xl bg-[#F8EEEE]/75 border border-[#E8C7C5]/40">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-[#8B7168]">Captured Audit Events</div>
                        <div class="text-xl font-black text-[#59443F] mt-1"><?php echo number_format((int)$dbStats['history_log_count']); ?></div>
                        <div class="text-xs text-[#8B7168] mt-1">Auto-logged via MySQL Triggers</div>
                    </div>
                </div>

                <!-- Trigger Coverage Strip -->
                <div class="mt-5 pt-4 border-t border-[#E8C7C5]/30">
                    <div class="text-xs font-bold uppercase tracking-wider text-[#8B7168] mb-3">Monitored MySQL Audit Triggers (`AFTER UPDATE` &amp; `BEFORE DELETE`)</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2.5">
                        <?php foreach ($triggerStatus as $tbl => $st): ?>
                            <?php $ok = !empty($st['update_trigger']) && !empty($st['delete_trigger']); ?>
                            <div class="px-3.5 py-2.5 rounded-2xl border <?php echo $ok ? 'bg-white border-[#E8C7C5]/60' : 'bg-amber-50/70 border-amber-200'; ?> flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-[#59443F] truncate"><?php echo htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-[10px] font-mono text-[#8B7168] truncate"><?php echo htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-300'; ?>">
                                    <i class="fa-solid <?php echo $ok ? 'fa-shield-check fa-check' : 'fa-triangle-exclamation'; ?>"></i>
                                    <?php echo $ok ? 'Active' : 'Incomplete'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- Band 2: Audit Logs Table Container -->
            <section aria-labelledby="audit-logs-heading" class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg overflow-hidden font-['Inter',sans-serif]">
                <div class="p-6 pb-4 border-b border-[#E8C7C5]/35 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/60 text-[#59443F] flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-clock-rotate-left text-base text-[#D6A6A6]"></i>
                        </div>
                        <div>
                            <h2 id="audit-logs-heading" class="font-heading text-lg font-extrabold text-[#59443F] tracking-tight">Audit Logs</h2>
                            <p class="text-xs text-[#8B7168]">Historical `OLD` &amp; `NEW` row snapshots archived automatically by MySQL triggers (`history_logs`)</p>
                        </div>
                    </div>

                    <form method="get" class="flex flex-wrap items-center gap-2.5">
                        <label for="auditTableFilter" class="sr-only">Filter by table</label>
                        <select id="auditTableFilter" name="table" class="rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/50 px-3.5 py-2 text-xs font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                            <option value="">All Monitored Tables</option>
                            <?php foreach ($monitoredTables as $tblKey => $meta): ?>
                                <option value="<?php echo htmlspecialchars($tblKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterTable === $tblKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($meta['label'] . ' (' . $tblKey . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="auditActionFilter" class="sr-only">Filter by action</label>
                        <select id="auditActionFilter" name="action" class="rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/50 px-3.5 py-2 text-xs font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                            <option value="">All Actions (UPDATE &amp; DELETE)</option>
                            <option value="UPDATE" <?php echo $filterAction === 'UPDATE' ? 'selected' : ''; ?>>UPDATE Only</option>
                            <option value="DELETE" <?php echo $filterAction === 'DELETE' ? 'selected' : ''; ?>>DELETE Only</option>
                        </select>

                        <label for="auditLimitFilter" class="sr-only">Rows limit</label>
                        <select id="auditLimitFilter" name="limit" class="rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/50 px-3.5 py-2 text-xs font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6]">
                            <?php foreach ([25, 50, 100, 200] as $optLimit): ?>
                                <option value="<?php echo $optLimit; ?>" <?php echo $limit === $optLimit ? 'selected' : ''; ?>><?php echo $optLimit; ?> rows</option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="px-4 py-2 rounded-2xl bg-[#59443F] hover:bg-[#6E554F] text-white text-xs font-bold transition shadow-xs cursor-pointer">
                            Apply Filter
                        </button>
                        <?php if ($filterTable !== '' || $filterAction !== '' || $limit !== 50): ?>
                            <a href="backup_audit.php" class="px-3.5 py-2 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5]/50 text-[#59443F] text-xs font-bold border border-[#E8C7C5]/60 transition">
                                Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($auditLogs)): ?>
                    <div class="p-10 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/50 text-[#D6A6A6] flex items-center justify-center mx-auto mb-3">
                            <i class="fa-solid fa-shield-halved text-lg"></i>
                        </div>
                        <h3 class="text-sm font-bold text-[#59443F]">No Matching Audit Logs Yet</h3>
                        <p class="text-xs text-[#8B7168] max-w-md mx-auto mt-1">
                            Whenever a grade, GPA record, or formation evaluation row is updated or deleted, MySQL triggers automatically archive the previous state here.
                        </p>
                    </div>
                <?php else: ?>
                    <div tabindex="0" role="region" aria-labelledby="audit-logs-heading" class="overflow-x-auto max-h-[580px]">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead class="sticky top-0 z-10 bg-[#F8EEEE] border-b border-[#E8C7C5]/60">
                                <tr class="text-[11px] uppercase font-bold tracking-wider text-[#8B7168]">
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Timestamp</th>
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Action</th>
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Protected Table</th>
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Record &amp; Summary</th>
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Actor</th>
                                    <th scope="col" class="py-3 px-4 whitespace-nowrap">Backed Up Snapshot (OLD vs NEW)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/25">
                                <?php foreach ($auditLogs as $log): ?>
                                    <?php
                                        $actionType = strtoupper((string)($log['action_type'] ?? 'UPDATE'));
                                        $isDelete = ($actionType === 'DELETE');
                                        $tblName = (string)($log['table_name'] ?? '');
                                        $tblLabel = $monitoredTables[$tblName]['label'] ?? $tblName;
                                        $changes = backup_audit_extract_changes($log['old_data'] ?? null, $log['new_data'] ?? null);
                                    ?>
                                    <tr class="hover:bg-[#F8EEEE]/35 transition align-top">
                                        <td class="py-3.5 px-4 whitespace-nowrap text-[#59443F] font-medium">
                                            <div><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$log['changed_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-[11px] text-[#8B7168]"><?php echo htmlspecialchars(date('h:i:s A', strtotime((string)$log['changed_at'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold <?php echo $isDelete ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-amber-50 text-amber-800 border border-amber-200'; ?>">
                                                <i class="fa-solid <?php echo $isDelete ? 'fa-trash-can' : 'fa-pen-to-square'; ?> text-[10px]"></i>
                                                <?php echo htmlspecialchars($actionType, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 whitespace-nowrap">
                                            <div class="font-bold text-[#59443F]"><?php echo htmlspecialchars($tblLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="font-mono text-[10px] text-[#8B7168]"><?php echo htmlspecialchars($tblName, ENT_QUOTES, 'UTF-8'); ?> #<?php echo htmlspecialchars((string)$log['record_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 min-w-[210px] max-w-[300px]">
                                            <div class="font-semibold text-[#59443F]"><?php echo htmlspecialchars((string)($log['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php if (!empty($changes)): ?>
                                                <div class="mt-1.5 space-y-1">
                                                    <?php foreach (array_slice($changes, 0, 3) as $chg): ?>
                                                        <div class="text-[11px] text-[#8B7168] leading-snug">
                                                            <span class="font-mono font-semibold text-[#59443F]"><?php echo htmlspecialchars($chg['field'], ENT_QUOTES, 'UTF-8'); ?>:</span>
                                                            <span class="line-through text-rose-700"><?php echo htmlspecialchars(mb_strimwidth($chg['old'], 0, 28, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php if ($chg['new'] !== null): ?>
                                                                <span class="mx-1">-&gt;</span>
                                                                <span class="font-semibold text-emerald-700"><?php echo htmlspecialchars(mb_strimwidth($chg['new'], 0, 28, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/50 font-semibold text-[11px]">
                                                <i class="fa-solid fa-user-shield text-[10px] text-[#8B7168]"></i>
                                                <?php echo htmlspecialchars((string)($log['changed_by'] ?? 'system'), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 min-w-[240px]">
                                            <details class="group">
                                                <summary class="cursor-pointer select-none inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-[#F8EEEE]/80 hover:bg-[#E8C7C5]/45 text-[#59443F] font-bold text-[11px] border border-[#E8C7C5]/50 transition">
                                                    <i class="fa-solid fa-code text-[10px] text-[#8B7168]"></i>
                                                    Inspect <?php echo $isDelete ? 'Archived Row' : 'OLD vs NEW'; ?>
                                                </summary>
                                                <div class="mt-2 grid grid-cols-1 gap-2 max-w-md">
                                                    <div class="p-2.5 rounded-xl bg-[#F8EEEE]/70 border border-[#E8C7C5]/60">
                                                        <div class="text-[10px] font-bold uppercase tracking-wider text-rose-700 mb-1">OLD Row Snapshot</div>
                                                        <pre class="text-[10px] font-mono text-[#59443F] whitespace-pre-wrap break-all m-0"><?php echo htmlspecialchars(backup_audit_format_json($log['old_data'] ?? null), ENT_QUOTES, 'UTF-8'); ?></pre>
                                                    </div>
                                                    <?php if (!$isDelete): ?>
                                                        <div class="p-2.5 rounded-xl bg-emerald-50/60 border border-emerald-200/70">
                                                            <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 mb-1">NEW Row Snapshot</div>
                                                            <pre class="text-[10px] font-mono text-[#59443F] whitespace-pre-wrap break-all m-0"><?php echo htmlspecialchars(backup_audit_format_json($log['new_data'] ?? null), ENT_QUOTES, 'UTF-8'); ?></pre>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Application Security & Activity Audit Log -->
            <section class="bg-white rounded-3xl border border-[#E8C7C5]/60 p-6 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
                    <div>
                        <h2 class="text-lg font-bold text-[#59443F] flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-[#8B7168]"></i>
                            Application Security &amp; Activity Audit Log
                        </h2>
                        <p class="text-xs text-[#8B7168] mt-1">
                            Chronological security events, authentication failures/logins, financial adjustments, student profile edits, migrations, and database backups.
                        </p>
                    </div>
                    <span class="px-3 py-1.5 rounded-xl bg-[#F8EEEE] border border-[#E8C7C5]/60 text-[11px] font-bold text-[#59443F]">
                        Showing latest <?php echo count($activityLogs); ?> events
                    </span>
                </div>

                <?php if (empty($activityLogs)): ?>
                    <div class="rounded-2xl border border-dashed border-[#E8C7C5] bg-[#FBF6F6] p-8 text-center">
                        <div class="w-12 h-12 mx-auto rounded-2xl bg-[#E8C7C5]/30 text-[#8B7168] flex items-center justify-center mb-3">
                            <i class="fa-solid fa-clipboard-list text-lg"></i>
                        </div>
                        <p class="text-sm font-bold text-[#59443F] m-0">No application activity logs recorded yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-[#E8C7C5]/60 text-[11px] font-bold uppercase tracking-wider text-[#8B7168] bg-[#FBF6F6]">
                                    <th class="py-3 px-3 rounded-tl-xl">Timestamp</th>
                                    <th class="py-3 px-3">Category</th>
                                    <th class="py-3 px-3">Actor</th>
                                    <th class="py-3 px-3">Event / Action</th>
                                    <th class="py-3 px-3 rounded-tr-xl">Entity &amp; Change Snapshot</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E8C7C5]/30 text-xs">
                                <?php foreach ($activityLogs as $act): ?>
                                    <?php
                                        $ent = strtolower(trim((string)($act['entity_type'] ?? '')));
                                        $actText = strtolower((string)($act['action'] ?? ''));
                                        if ($ent === 'auth' || strpos($actText, 'login') !== false || strpos($actText, 'logout') !== false || strpos($actText, 'password') !== false) {
                                            $catLabel = 'Auth / Security';
                                            $catClass = (strpos($actText, 'failed') !== false || strpos($actText, 'throttl') !== false)
                                                ? 'bg-rose-50 text-rose-700 border border-rose-200'
                                                : 'bg-sky-50 text-sky-700 border border-sky-200';
                                        } elseif (strpos($ent, 'cashier') !== false || strpos($ent, 'lodging') !== false || strpos($ent, 'payment') !== false || strpos($ent, 'balance') !== false || strpos($ent, 'fee') !== false) {
                                            $catLabel = 'Financial';
                                            $catClass = 'bg-amber-50 text-amber-800 border border-amber-200';
                                        } elseif ($ent === 'migration' || $ent === 'database_backup') {
                                            $catLabel = $ent === 'database_backup' ? 'Backup' : 'Migration';
                                            $catClass = 'bg-purple-50 text-purple-700 border border-purple-200';
                                        } else {
                                            $catLabel = 'Record Audit';
                                            $catClass = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                                        }
                                        $actorName = !empty($act['username'])
                                            ? (string)$act['username'] . (!empty($act['user_role']) ? ' (' . (string)$act['user_role'] . ')' : '')
                                            : (!empty($act['user_id']) ? 'User #' . (int)$act['user_id'] : 'Anonymous / System');
                                        $prevVal = (string)($act['previous_value'] ?? '');
                                        $newVal = (string)($act['new_value'] ?? '');
                                    ?>
                                    <tr class="hover:bg-[#FBF6F6]/70 transition align-top">
                                        <td class="py-3 px-3 whitespace-nowrap font-semibold text-[#59443F]">
                                            <?php echo htmlspecialchars((string)($act['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold <?php echo $catClass; ?>">
                                                <?php echo htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 whitespace-nowrap text-[#59443F] font-semibold">
                                            <?php echo htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td class="py-3 px-3 text-[#59443F]">
                                            <?php echo htmlspecialchars((string)($act['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td class="py-3 px-3 text-[11px] text-[#8B7168]">
                                            <?php if (!empty($act['entity_type']) || !empty($act['field_name'])): ?>
                                                <div class="font-mono text-[#59443F]">
                                                    <?php echo htmlspecialchars((string)($act['entity_type'] ?? 'system'), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($act['entity_id'])): ?>#<?php echo (int)$act['entity_id']; ?><?php endif; ?>
                                                    <?php if (!empty($act['field_name'])): ?>.<?php echo htmlspecialchars((string)$act['field_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($prevVal !== '' || $newVal !== ''): ?>
                                                <div class="mt-0.5 font-mono break-all">
                                                    <?php echo htmlspecialchars($prevVal !== '' ? $prevVal : '∅', ENT_QUOTES, 'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($newVal !== '' ? $newVal : '∅', ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
