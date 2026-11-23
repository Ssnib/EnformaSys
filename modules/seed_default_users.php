<?php
include '../includes/session_fix.php';
require_roles(['Dean', 'Admin', 'Rector']);
include '../includes/db.php';

if (enformasys_is_production() && strtolower((string)enformasys_env('ALLOW_DEFAULT_SEED', 'false')) !== 'true') {
    http_response_code(403);
    exit('Forbidden: Default user seeding is disabled in production.');
}

$role_col_type = '';
$role_col = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' LIMIT 1");
if ($role_col && $r = $role_col->fetch_assoc()) {
    $role_col_type = (string)($r['COLUMN_TYPE'] ?? '');
}

$defaults = [
    ['username' => 'registrar', 'role' => 'Registrar'],
    ['username' => 'admin', 'role' => 'Dean'],
    ['username' => 'rector', 'role' => 'Rector'],
    ['username' => 'academicformator', 'role' => 'AcademicFormator'],
    ['username' => 'spiritualdirector', 'role' => 'SpiritualDirector'],
    ['username' => 'humanformator', 'role' => 'HumanFormator'],
    ['username' => 'pastoralformator', 'role' => 'PastoralFormator'],
    ['username' => 'cashier', 'role' => 'Cashier'],
    ['username' => 'shrine', 'role' => 'Shrine'],
];

$seed_errors = [];
if (isset($_POST['seed_defaults'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $seedPass = (string)enformasys_env('DEFAULT_SEED_PASSWORD', 'pass');
    if ($seedPass === '') {
        $error = 'DEFAULT_SEED_PASSWORD must be configured before seeding.';
    } else {
    $hash = password_hash($seedPass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)");

    foreach ($defaults as $d) {
        $u = (string)$d['username'];
        $r = (string)$d['role'];
        $stmt->bind_param('sss', $u, $hash, $r);
        $stmt->execute();
        if ($stmt->errno) {
            $seed_errors[] = $u . ': ' . $stmt->error;
        }
    }

    // Dummy student1 seeding intentionally omitted for production cleanliness.

    if (count($seed_errors) === 0) {
        $success = 'Default users seeded/reset successfully.';
        log_activity((int)($_SESSION['user'] ?? 0), '[SECURITY] Default users seeded/reset via seed_default_users.php');
    } else {
        $error = 'Some users failed to seed. See details below.';
    }
    }
}

$users = [];
$res = $conn->query("SELECT id, username, role, password FROM users ORDER BY id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}

$test_result = null;
if (isset($_POST['test_login'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $tu = trim((string)($_POST['test_username'] ?? ''));
    $tp = (string)($_POST['test_password'] ?? '');
    $stmt_t = $conn->prepare("SELECT id, username, role, password FROM users WHERE username = ? LIMIT 1");
    $stmt_t->bind_param('s', $tu);
    $stmt_t->execute();
    $row = $stmt_t->get_result()->fetch_assoc();
    if (!$row) {
        $test_result = ['ok' => false, 'msg' => 'User not found in DB.'];
    } else {
        $ok = password_verify($tp, (string)($row['password'] ?? ''));
        $test_result = [
            'ok' => $ok,
            'msg' => $ok ? 'Password verification OK.' : 'Password verification FAILED.',
            'role' => (string)($row['role'] ?? ''),
            'id' => (int)($row['id'] ?? 0),
        ];
    }
}
?>
<?php
$pageTitle = 'Seed Default Users';
$activeNav = 'migrations';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body">
            <h1 class="page-title"><i class="fas fa-user-shield"></i> Seed Default Users</h1>

            <?php if (isset($success)) echo "<p class='success'>" . htmlspecialchars($success) . "</p>"; ?>
            <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>

            <div class="card" style="margin-top: 0;">
                <h3><i class="fas fa-key"></i> Create / Reset Accounts</h3>
                <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">
                    This will create (or reset) the system default accounts and set their password to the configured <strong>DEFAULT_SEED_PASSWORD</strong> environment variable.
                </div>
                <div style="margin-top: 0.5rem; color: var(--text-secondary); font-weight: 700;">
                    users.role column type: <strong><?php echo htmlspecialchars($role_col_type !== '' ? $role_col_type : '(unknown)'); ?></strong>
                </div>
                <form method="post" style="margin-top: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="seed_defaults" style="width: auto; padding: 0.55rem 0.9rem;">
                        <i class="fas fa-rotate"></i> Seed / Reset Default Users
                    </button>
                </form>
            </div>

            <div class="card" style="margin-top: 1.5rem;">
                <h3><i class="fas fa-vial"></i> Test Login</h3>
                <form method="post" style="margin-top: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="field">
                            <label>Username</label>
                            <input type="text" name="test_username" required placeholder="e.g. academicformator" value="<?php echo htmlspecialchars((string)($_POST['test_username'] ?? '')); ?>">
                        </div>
                        <div class="field">
                            <label>Password</label>
                            <input type="password" name="test_password" required placeholder="Configured seed password" value="">
                        </div>
                    </div>
                    <button type="submit" name="test_login" style="margin-top: 0.75rem; width: auto; padding: 0.55rem 0.9rem;">
                        <i class="fas fa-play"></i> Run Test
                    </button>
                </form>
                <?php if ($test_result) { ?>
                    <div style="margin-top: 0.75rem; font-weight: 800; color: <?php echo $test_result['ok'] ? '#16a34a' : '#ef4444'; ?>;">
                        <?php echo htmlspecialchars((string)$test_result['msg']); ?>
                        <?php if (isset($test_result['id'])) { ?>
                            <span style="color: #64748b;">(id: <?php echo (int)$test_result['id']; ?>, role: <?php echo htmlspecialchars((string)($test_result['role'] ?? '')); ?>)</span>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>

            <?php if (count($seed_errors) > 0) { ?>
                <div class="card" style="margin-top: 1.5rem; border-left: 6px solid #ef4444;">
                    <h3><i class="fas fa-triangle-exclamation"></i> Seed Errors</h3>
                    <div style="margin-top: 0.75rem; color: #334155; font-weight: 800; white-space: pre-wrap;"><?php echo htmlspecialchars(implode("\n", $seed_errors)); ?></div>
                </div>
            <?php } ?>

            <div class="data-card" style="margin-top: 1.5rem;">
                <div class="data-card-header">
                    <h3><i class="fas fa-list"></i> Current Users</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Seed Password Match</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $configured_seed_pw = function_exists('enformasys_env') ? (string)enformasys_env('DEFAULT_SEED_PASSWORD', '') : ''; ?>
                            <?php foreach ($users as $u) { ?>
                                <tr>
                                    <td><?php echo (int)$u['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars((string)$u['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars((string)$u['role']); ?></td>
                                    <td>
                                        <?php echo ($configured_seed_pw !== '' && password_verify($configured_seed_pw, (string)($u['password'] ?? ''))) ? "<span class='badge badge-success'>Yes</span>" : "<span class='badge badge-danger'>No</span>"; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>
<?php exit; ?>
