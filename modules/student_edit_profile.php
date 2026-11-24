<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_fix.php';

require_login();
if (($_SESSION['role'] ?? '') !== 'Student') {
    capstone_redirect('index.php');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/seminarian_profile_helper.php';

$user_id = (int)$_SESSION['user'];

// 1. Fetch Seminarian Record
$stmt_sem = $conn->prepare("SELECT * FROM seminarians WHERE user_id = ? LIMIT 1");
$stmt_sem->bind_param("i", $user_id);
$stmt_sem->execute();
$seminarian = $stmt_sem->get_result()->fetch_assoc() ?: [];
$stmt_sem->close();

$seminarian_id = (int)($seminarian['id'] ?? 0);
if ($seminarian_id <= 0) {
    capstone_redirect('modules/student_view.php');
}

// 2. Fetch User Profile & Applicant Details
$stmt_prof = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1");
$stmt_prof->bind_param("i", $user_id);
$stmt_prof->execute();
$profile = $stmt_prof->get_result()->fetch_assoc() ?: [];
$stmt_prof->close();

$stmt_app = $conn->prepare("SELECT * FROM applicants WHERE user_id = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$stmt_app->bind_param("i", $user_id);
$stmt_app->execute();
$applicant = $stmt_app->get_result()->fetch_assoc() ?: [];
$stmt_app->close();

// Fetch authoritative username for Account & Security tab
$stmt_acc_user = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt_acc_user->bind_param("i", $user_id);
$stmt_acc_user->execute();
$acc_user_row = $stmt_acc_user->get_result()->fetch_assoc() ?: [];
$stmt_acc_user->close();
$current_username = (string)($acc_user_row['username'] ?? ($_SESSION['username'] ?? ''));
if ($current_username !== '') {
    $_SESSION['username'] = $current_username;
}

$username = $current_username !== '' ? $current_username : 'Seminarian';
$display_name = !empty($profile['full_name'])
    ? (string)$profile['full_name']
    : (!empty($applicant['full_name'])
        ? (string)$applicant['full_name']
        : (!empty($profile['first_name']) ? trim($profile['first_name'] . ' ' . ($profile['last_name'] ?? '')) : $username));

$first_name = !empty($profile['first_name'])
    ? (string)$profile['first_name']
    : (explode(' ', trim($display_name))[0] ?? 'Seminarian');
$last_name = (string)($profile['last_name'] ?? '');

$initials = get_seminarian_initials($first_name, $last_name, $display_name);

// 3. Cooldown Status Calculation (1-week limit)
$cooldown = get_seminarian_cooldown_status($seminarian['last_profile_update'] ?? null);

$flash_success = null;
$flash_error = null;

// 4. Handle Profile Update Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($token)) {
        $flash_error = 'Security validation failed (invalid CSRF token). Please refresh and try again.';
    } elseif ($cooldown['is_active']) {
        $flash_error = 'Cooldown active. You can only update your profile once a week. You can edit again on ' . $cooldown['formatted_unlock_date'] . '.';
    } else {
        // Collect & sanitize editable fields
        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        $email_address = trim((string)($_POST['email_address'] ?? ''));
        $present_address = trim((string)($_POST['present_address'] ?? ''));
        $permanent_address = trim((string)($_POST['permanent_address'] ?? ''));
        $guardian_name = trim((string)($_POST['guardian_name'] ?? ''));
        $guardian_contact_number = trim((string)($_POST['guardian_contact_number'] ?? ''));
        $religion = trim((string)($_POST['religion'] ?? ''));
        $parish_priest = trim((string)($_POST['parish_priest'] ?? ''));
        $parish_chaplaincy = trim((string)($_POST['parish_chaplaincy'] ?? ''));

        if ($email_address !== '' && !filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
            $flash_error = 'Please enter a valid email address.';
        } else {
            $uploaded_picture_path = null;
            $upload_error = null;

            // Check if a profile picture was uploaded
            if (isset($_FILES['profile_picture']) && ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload_res = save_seminarian_profile_picture(
                    $seminarian_id,
                    $_FILES['profile_picture'],
                    $seminarian['profile_picture'] ?? null
                );
                if (!$upload_res['success']) {
                    $upload_error = $upload_res['error'];
                } else {
                    $uploaded_picture_path = $upload_res['path'];
                }
            }

            if ($upload_error !== null) {
                $flash_error = $upload_error;
            } else {
                $conn->begin_transaction();
                try {
                    // Update seminarians table: profile_picture (if updated) and last_profile_update timestamp
                    if ($uploaded_picture_path !== null) {
                        $stmt_sem_upd = $conn->prepare(
                            "UPDATE seminarians 
                             SET profile_picture = ?, last_profile_update = NOW() 
                             WHERE id = ?"
                        );
                        $stmt_sem_upd->bind_param("si", $uploaded_picture_path, $seminarian_id);
                        $stmt_sem_upd->execute();
                        $stmt_sem_upd->close();
                    } else {
                        $stmt_sem_upd = $conn->prepare(
                            "UPDATE seminarians 
                             SET last_profile_update = NOW() 
                             WHERE id = ?"
                        );
                        $stmt_sem_upd->bind_param("i", $seminarian_id);
                        $stmt_sem_upd->execute();
                        $stmt_sem_upd->close();
                    }

                    // Update user_profiles table
                    if (!empty($profile)) {
                        $stmt_prof_upd = $conn->prepare(
                            "UPDATE user_profiles 
                             SET contact_number = ?, contact = ?, email_address = ?, email = ?, 
                                 present_address = ?, address = ?, permanent_address = ?,
                                 guardian_name = ?, guardian_contact_number = ?,
                                 religion = ?, parish_priest = ?, parish_chaplaincy = ?,
                                 updated_at = NOW()
                             WHERE user_id = ?"
                        );
                        $stmt_prof_upd->bind_param(
                            "ssssssssssssi",
                            $contact_number, $contact_number, $email_address, $email_address,
                            $present_address, $present_address, $permanent_address,
                            $guardian_name, $guardian_contact_number,
                            $religion, $parish_priest, $parish_chaplaincy,
                            $user_id
                        );
                        $stmt_prof_upd->execute();
                        $stmt_prof_upd->close();
                    } else {
                        $stmt_prof_ins = $conn->prepare(
                            "INSERT INTO user_profiles 
                             (user_id, full_name, first_name, last_name, contact_number, contact, email_address, email, 
                              present_address, address, permanent_address, guardian_name, guardian_contact_number, 
                              religion, parish_priest, parish_chaplaincy, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
                        );
                        $stmt_prof_ins->bind_param(
                            "isssssssssssssss",
                            $user_id, $display_name, $first_name, $last_name,
                            $contact_number, $contact_number, $email_address, $email_address,
                            $present_address, $present_address, $permanent_address,
                            $guardian_name, $guardian_contact_number,
                            $religion, $parish_priest, $parish_chaplaincy
                        );
                        $stmt_prof_ins->execute();
                        $stmt_prof_ins->close();
                    }

                    // Keep applicants table in sync if matching user exists
                    $stmt_app_upd = $conn->prepare(
                        "UPDATE applicants 
                         SET contact = ?, address = ? 
                         WHERE user_id = ? AND archived_at IS NULL"
                    );
                    $stmt_app_upd->bind_param("ssi", $contact_number, $present_address, $user_id);
                    $stmt_app_upd->execute();
                    $stmt_app_upd->close();

                    $conn->commit();

                    if (function_exists('log_activity')) {
                        try {
                            log_activity($user_id, 'Seminarian updated profile details and avatar picture');
                        } catch (\Throwable $e) {
                            // Non-blocking activity log
                        }
                    }

                    $flash_success = 'Profile and avatar updated successfully! Your 7-day cooldown is now active.';

                    // Re-fetch updated seminarian and profile records to refresh UI
                    $stmt_ref = $conn->prepare("SELECT * FROM seminarians WHERE id = ? LIMIT 1");
                    $stmt_ref->bind_param("i", $seminarian_id);
                    $stmt_ref->execute();
                    $seminarian = $stmt_ref->get_result()->fetch_assoc() ?: $seminarian;
                    $stmt_ref->close();

                    $stmt_p_ref = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1");
                    $stmt_p_ref->bind_param("i", $user_id);
                    $stmt_p_ref->execute();
                    $profile = $stmt_p_ref->get_result()->fetch_assoc() ?: $profile;
                    $stmt_p_ref->close();

                    // Re-calculate cooldown with newly saved timestamp
                    $cooldown = get_seminarian_cooldown_status($seminarian['last_profile_update'] ?? null);
                } catch (\Throwable $ex) {
                    $conn->rollback();
                    $flash_error = 'An error occurred while saving profile changes: ' . $ex->getMessage();
                }
            }
        }
    }
}

$avatar_url = get_seminarian_avatar_url($seminarian['profile_picture'] ?? null);
$csrf_token = (string)($_SESSION['csrf_token'] ?? '');
$initial_tab = (isset($_GET['tab']) && strtolower(trim((string)$_GET['tab'])) === 'account') ? 'account' : 'profile';

$pageTitle = 'Profile & Settings';
$activeNav = 'edit_profile';
require_once __DIR__ . '/../includes/layout_app_start.php';
?>
        <div class="content-body font-['Inter',sans-serif] max-w-4xl mx-auto">
            <!-- Breadcrumbs -->
            <nav class="mb-4 text-xs font-semibold text-[#8B7168] flex items-center gap-2">
                <a href="<?php echo htmlspecialchars(capstone_url('modules/student_view.php')); ?>" class="text-[#59443F] hover:text-[#8B7168] transition">Dashboard</a>
                <span>/</span>
                <span class="text-[#8B7168]">Profile</span>
            </nav>

            <!-- Unified Profile & Account Settings Tab Bar -->
            <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-sm p-2 mb-6 inline-flex flex-wrap items-center gap-1.5 w-full sm:w-auto" role="tablist" aria-label="Profile Settings Tabs">
                <button type="button"
                        role="tab"
                        id="tabBtnProfile"
                        data-profile-tab="profile"
                        aria-selected="<?php echo $initial_tab === 'profile' ? 'true' : 'false'; ?>"
                        aria-controls="tabPanelProfile"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition cursor-pointer <?php echo $initial_tab === 'profile' ? 'bg-[#59443F] text-white shadow-xs' : 'text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F]'; ?>">
                    <i class="fa-solid fa-id-badge"></i>
                    <span>Profile Details</span>
                </button>
                <button type="button"
                        role="tab"
                        id="tabBtnAccount"
                        data-profile-tab="account"
                        aria-selected="<?php echo $initial_tab === 'account' ? 'true' : 'false'; ?>"
                        aria-controls="tabPanelAccount"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition cursor-pointer <?php echo $initial_tab === 'account' ? 'bg-[#59443F] text-white shadow-xs' : 'text-[#8B7168] hover:bg-[#F8EEEE] hover:text-[#59443F]'; ?>">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Account &amp; Security</span>
                </button>
            </div>

            <!-- TAB 1: Profile Details -->
            <div id="tabPanelProfile" role="tabpanel" aria-labelledby="tabBtnProfile" class="<?php echo $initial_tab === 'profile' ? '' : 'hidden'; ?>">

            <!-- Alerts -->
            <?php if ($flash_success !== null): ?>
                <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm font-medium flex items-center gap-3 shadow-xs">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-lg"></i>
                    <span><?php echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flash_error !== null): ?>
                <div class="mb-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium flex items-center gap-3 shadow-xs">
                    <i class="fa-solid fa-triangle-exclamation text-rose-600 text-lg"></i>
                    <span><?php echo htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Cooldown Warning Badge Banner -->
            <?php if ($cooldown['is_active']): ?>
                <div class="rounded-3xl bg-[#FFFBEB] border border-[#FDE68A] text-[#92400E] p-5 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 shadow-sm">
                    <div class="flex items-start gap-3.5">
                        <div class="w-11 h-11 rounded-2xl bg-amber-100 text-amber-800 flex items-center justify-center shrink-0 text-xl border border-amber-200 shadow-2xs">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-heading font-black text-[#78350F] text-base tracking-tight m-0">1-Week Profile Cooldown Active</h3>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-200/90 text-amber-900 border border-amber-300">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-pulse"></span>
                                    <?php echo htmlspecialchars((string)$cooldown['remaining_text'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <p class="text-xs text-amber-900/90 font-medium mt-1 leading-relaxed">
                                <?php echo htmlspecialchars((string)$cooldown['badge_message'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-2xl bg-white text-amber-900 text-xs font-bold border border-amber-200/90 shrink-0 self-start sm:self-center shadow-2xs">
                        <i class="fa-solid fa-lock text-amber-600"></i> Form Locked
                    </span>
                </div>
            <?php else: ?>
                <div class="rounded-3xl bg-[#F8EEEE]/85 border border-[#E8C7C5]/60 p-4 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs text-[#59443F] font-semibold">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-white border border-[#E8C7C5] text-[#59443F] flex items-center justify-center shrink-0 shadow-2xs">
                            <i class="fa-solid fa-shield-halved text-[#D6A6A6]"></i>
                        </div>
                        <span>You can update your avatar picture and personal contact details below. Once submitted, editing enters a <strong>7-day cooldown</strong>.</span>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-white text-emerald-700 border border-emerald-200/80 shadow-2xs shrink-0 self-start sm:self-center">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Ready to Edit
                    </span>
                </div>
            <?php endif; ?>

            <!-- Main Form Floating Container -->
            <form action="" method="post" enctype="multipart/form-data" data-confirm="Save changes to your profile? Your 7-day profile update cooldown will be activated." data-confirm-title="Save Profile Changes" data-confirm-text="Save Changes" data-confirm-variant="primary" data-confirm-loading="Saving..." class="space-y-6 max-w-4xl mx-auto">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- SECTION 1: Profile Picture Upload & Avatar Preview -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 sm:p-8">
                    <div class="flex items-center gap-3 pb-4 mb-6 border-b border-[#E8C7C5]/30">
                        <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                            <i class="fa-solid fa-camera text-base text-[#D6A6A6]"></i>
                        </div>
                        <div>
                            <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight">Profile Picture</h2>
                            <p class="text-xs text-[#8B7168] font-medium">Upload a clear photo for your Seminarian ID and Registrar profile record.</p>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">
                        <!-- Current / Live Preview Avatar -->
                        <div class="flex flex-col items-center shrink-0">
                            <div class="relative w-28 h-28 rounded-3xl overflow-hidden border-4 border-[#F8EEEE] shadow-md bg-[#E8C7C5] flex items-center justify-center">
                                <img id="avatarPreviewImg" 
                                     src="<?php echo $avatar_url ? htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') : ''; ?>" 
                                     alt="Profile Avatar" 
                                     class="<?php echo $avatar_url ? '' : 'hidden'; ?> w-full h-full object-cover">
                                <div id="avatarFallbackBadge" 
                                     class="<?php echo $avatar_url ? 'hidden' : ''; ?> w-full h-full flex items-center justify-center font-heading font-black text-3xl text-[#59443F]">
                                    <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <span class="text-[11px] font-bold text-[#8B7168] mt-2">Current Avatar</span>
                        </div>

                        <!-- Upload Control Area -->
                        <div class="flex-1 w-full space-y-3">
                            <label for="profile_picture" 
                                   class="relative flex flex-col items-center justify-center p-6 border-2 border-dashed border-[#E8C7C5] hover:border-[#D6A6A6] rounded-3xl bg-[#F8EEEE]/40 hover:bg-[#F8EEEE] transition text-center group <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed hover:bg-[#F8EEEE]/40 hover:border-[#E8C7C5]' : 'cursor-pointer'; ?>">
                                <input type="file" 
                                       id="profile_picture" 
                                       name="profile_picture" 
                                       accept="image/jpeg,image/png,image/webp" 
                                       class="sr-only" 
                                       <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                                
                                <div class="w-12 h-12 rounded-2xl bg-white text-[#D6A6A6] group-hover:text-[#59443F] flex items-center justify-center shadow-xs border border-[#E8C7C5]/50 mb-2 transition">
                                    <i class="fa-solid fa-cloud-arrow-up text-xl"></i>
                                </div>
                                <span class="text-xs sm:text-sm font-bold text-[#59443F]">Click to browse or drag &amp; drop your photo</span>
                                <span class="text-[11px] text-[#8B7168] mt-1">Accepts JPG, PNG, or WebP (Max file size: 2MB)</span>
                            </label>

                            <!-- Selected File Information Pill -->
                            <div id="fileSelectionInfo" class="hidden flex items-center justify-between p-3 rounded-2xl bg-[#F8EEEE] border border-[#E8C7C5]/60 text-xs">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="fa-solid fa-file-image text-[#D6A6A6]"></i>
                                    <span id="selectedFileName" class="font-bold text-[#59443F] truncate">No file chosen</span>
                                    <span id="selectedFileSize" class="text-[10px] text-[#8B7168]"></span>
                                </div>
                                <button type="button" id="resetPhotoBtn" class="text-[#8B7168] hover:text-rose-600 font-bold text-xs transition cursor-pointer px-2 py-0.5 rounded-lg hover:bg-white">
                                    <i class="fa-solid fa-xmark"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Official Academic Record (Read-Only) -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 sm:p-8">
                    <div class="flex items-center justify-between pb-4 mb-5 border-b border-[#E8C7C5]/30">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-solid fa-graduation-cap text-base text-[#D6A6A6]"></i>
                            </div>
                            <div>
                                <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight">Academic &amp; Seminary Identity</h2>
                                <p class="text-xs text-[#8B7168] font-medium">Official seminary credentials managed by the Registrar.</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[11px] font-bold bg-[#F8EEEE] text-[#8B7168] border border-[#E8C7C5]/60">
                            <i class="fa-solid fa-lock text-[10px]"></i> Verified Record
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Full Name</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 truncate"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Seminarian ID</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 font-mono">#<?php echo (int)$seminarian_id; ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Year Level</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 truncate"><?php echo htmlspecialchars((string)($seminarian['year_level'] ?? 'Philosophy Year 1'), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Program</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 truncate"><?php echo htmlspecialchars((string)($seminarian['program'] ?? 'Bachelor of Arts in Philosophy'), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Formation Stage</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 truncate"><?php echo htmlspecialchars((string)($seminarian['formation_stage'] ?? 'Philosophy'), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-[#F8EEEE]/50 border border-[#E8C7C5]/40">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8B7168]">Academic Status</span>
                            <div class="text-sm font-bold text-[#59443F] mt-0.5 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full <?php echo (($seminarian['academic_status'] ?? '') === 'Irregular') ? 'bg-amber-500' : 'bg-emerald-500'; ?>"></span>
                                <span><?php echo htmlspecialchars((string)($seminarian['academic_status'] ?? 'Regular'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Editable Contact & Guardian Details -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 sm:p-8">
                    <div class="flex items-center gap-3 pb-4 mb-6 border-b border-[#E8C7C5]/30">
                        <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                            <i class="fa-solid fa-address-book text-base text-[#D6A6A6]"></i>
                        </div>
                        <div>
                            <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight">Contact Information</h2>
                            <p class="text-xs text-[#8B7168] font-medium">Keep your primary contact and emergency guardian details up to date.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <!-- Contact Number -->
                        <div>
                            <label for="contact_number" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Contact / Mobile Number
                            </label>
                            <input type="text" 
                                   id="contact_number" 
                                   name="contact_number" 
                                   value="<?php echo htmlspecialchars((string)($profile['contact_number'] ?? $profile['contact'] ?? $applicant['contact'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="e.g. 0917-123-4567"
                                   class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                   <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                        </div>

                        <!-- Email Address -->
                        <div>
                            <label for="email_address" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Email Address
                            </label>
                            <input type="email" 
                                   id="email_address" 
                                   name="email_address" 
                                   value="<?php echo htmlspecialchars((string)($profile['email_address'] ?? $profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="seminarian@example.com"
                                   class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                   <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                        </div>

                        <!-- Guardian Name -->
                        <div>
                            <label for="guardian_name" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Guardian / Emergency Contact Name
                            </label>
                            <input type="text" 
                                   id="guardian_name" 
                                   name="guardian_name" 
                                   value="<?php echo htmlspecialchars((string)($profile['guardian_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Full name of parent or guardian"
                                   class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                   <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                        </div>

                        <!-- Guardian Contact -->
                        <div>
                            <label for="guardian_contact_number" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Guardian Contact Number
                            </label>
                            <input type="text" 
                                   id="guardian_contact_number" 
                                   name="guardian_contact_number" 
                                   value="<?php echo htmlspecialchars((string)($profile['guardian_contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Guardian phone number"
                                   class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                   <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: Editable Address & Parish Details -->
                <div class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 sm:p-8">
                    <div class="flex items-center gap-3 pb-4 mb-6 border-b border-[#E8C7C5]/30">
                        <div class="w-10 h-10 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 flex items-center justify-center shrink-0 shadow-xs">
                            <i class="fa-solid fa-church text-base text-[#D6A6A6]"></i>
                        </div>
                        <div>
                            <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] tracking-tight">Residential &amp; Parish Details</h2>
                            <p class="text-xs text-[#8B7168] font-medium">Home address and local diocese or parish affiliation.</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <!-- Present Address -->
                        <div>
                            <label for="present_address" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Present / Seminary Address
                            </label>
                            <textarea id="present_address" 
                                      name="present_address" 
                                      rows="2"
                                      placeholder="Current boarding or residence address"
                                      class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                      <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($profile['present_address'] ?? $profile['address'] ?? $applicant['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <!-- Permanent Address -->
                        <div>
                            <label for="permanent_address" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                Permanent Home Address
                            </label>
                            <textarea id="permanent_address" 
                                      name="permanent_address" 
                                      rows="2"
                                      placeholder="Permanent provincial/home address"
                                      class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                      <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($profile['permanent_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                            <!-- Religion -->
                            <div>
                                <label for="religion" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    Religion
                                </label>
                                <input type="text" 
                                       id="religion" 
                                       name="religion" 
                                       value="<?php echo htmlspecialchars((string)($profile['religion'] ?? 'Roman Catholic'), ENT_QUOTES, 'UTF-8'); ?>"
                                       class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                       <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                            </div>

                            <!-- Parish Priest -->
                            <div>
                                <label for="parish_priest" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    Parish Priest
                                </label>
                                <input type="text" 
                                       id="parish_priest" 
                                       name="parish_priest" 
                                       value="<?php echo htmlspecialchars((string)($profile['parish_priest'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="Rev. Fr. Name"
                                       class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                       <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                            </div>

                            <!-- Parish / Chaplaincy -->
                            <div>
                                <label for="parish_chaplaincy" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    Parish / Chaplaincy
                                </label>
                                <input type="text" 
                                       id="parish_chaplaincy" 
                                       name="parish_chaplaincy" 
                                       value="<?php echo htmlspecialchars((string)($profile['parish_chaplaincy'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="Parish name"
                                       class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/70 bg-[#F8EEEE]/40 focus:bg-white text-xs sm:text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition <?php echo $cooldown['is_active'] ? 'opacity-60 cursor-not-allowed' : ''; ?>"
                                       <?php echo $cooldown['is_active'] ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SUBMIT ACTIONS -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
                    <a href="<?php echo htmlspecialchars(capstone_url('modules/student_view.php')); ?>" 
                       class="w-full sm:w-auto px-6 py-3 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5]/50 text-[#59443F] font-bold text-xs sm:text-sm transition border border-[#E8C7C5]/70 text-center shadow-xs">
                        Back to Dashboard
                    </a>

                    <?php if ($cooldown['is_active']): ?>
                        <button type="button" 
                                disabled 
                                class="w-full sm:w-auto px-7 py-3.5 rounded-2xl bg-[#8B7168] text-white/80 font-bold text-xs sm:text-sm shadow-none opacity-60 cursor-not-allowed flex items-center justify-center gap-2">
                            <i class="fa-solid fa-lock text-xs"></i>
                            Profile Locked (Cooldown Active)
                        </button>
                    <?php else: ?>
                        <button type="submit" 
                                class="w-full sm:w-auto px-7 py-3.5 rounded-2xl bg-[#59443F] hover:bg-[#6E554F] active:scale-[0.99] text-white font-bold text-xs sm:text-sm transition shadow-md hover:shadow-lg flex items-center justify-center gap-2.5 cursor-pointer">
                            <i class="fa-solid fa-floppy-disk text-[#E8C7C5]"></i>
                            Save Profile Changes
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            </div><!-- /#tabPanelProfile -->

            <!-- TAB 2: Account & Security -->
            <div id="tabPanelAccount" role="tabpanel" aria-labelledby="tabBtnAccount" class="<?php echo $initial_tab === 'account' ? '' : 'hidden'; ?>">
                <!-- Inline Feedback Banner (Account Settings) -->
                <div id="accountAlertBox"
                     class="hidden mb-5 rounded-2xl px-4 py-3 text-sm font-medium items-center gap-3 shadow-xs"
                     role="alert">
                    <i id="accountAlertIcon" class="fa-solid fa-circle-check text-lg shrink-0"></i>
                    <span id="accountAlertText"></span>
                </div>

                <!-- Consolidated Account Settings Card -->
                <section class="bg-white rounded-3xl border border-[#E8C7C5]/50 shadow-lg p-6 sm:p-8">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 mb-6 border-b border-[#E8C7C5]/35">
                        <div class="flex items-center gap-3.5">
                            <div class="w-11 h-11 rounded-2xl bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/70 flex items-center justify-center shrink-0 shadow-xs">
                                <i class="fa-solid fa-shield-halved text-lg text-[#D6A6A6]"></i>
                            </div>
                            <div>
                                <h2 class="font-heading font-extrabold text-base sm:text-lg text-[#59443F] m-0 tracking-tight">Account &amp; Security Settings</h2>
                                <p class="text-xs text-[#8B7168] font-medium mt-0.5 mb-0">Update your portal login username and password anytime (not subject to the 7-day profile cooldown).</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-[#F8EEEE] text-[#59443F] border border-[#E8C7C5]/60 shrink-0 self-start sm:self-center">
                            <i class="fa-solid fa-lock text-[10px] text-[#8B7168]"></i> Security Verified
                        </span>
                    </div>

                    <form id="accountSettingsForm" method="post" action="student_account.php" class="space-y-5" novalidate style="margin: 0; padding: 0; border: none; box-shadow: none; background: transparent;">
                        <input type="hidden" name="csrf_token" id="csrfTokenInput" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- New Username -->
                        <div>
                            <label for="new_username" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                New Username
                            </label>
                            <input type="text"
                                   id="new_username"
                                   name="new_username"
                                   value="<?php echo htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8'); ?>"
                                   data-current-username="<?php echo htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8'); ?>"
                                   autocomplete="username"
                                   required
                                   minlength="3"
                                   maxlength="50"
                                   placeholder="Enter your username"
                                   class="w-full px-4 py-3 rounded-2xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                            <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                                Must be 3–50 characters (letters, numbers, underscores, hyphens, or dots).
                            </p>
                        </div>

                        <div class="pt-4 border-t border-[#E8C7C5]/30 space-y-5">
                            <!-- Current Password -->
                            <div>
                                <label for="current_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                    Current Password <span class="text-rose-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password"
                                           id="current_password"
                                           name="current_password"
                                           autocomplete="current-password"
                                           required
                                           placeholder="Enter your current password to confirm changes"
                                           class="w-full px-4 py-3 pr-11 rounded-2xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                    <button type="button"
                                            data-toggle-pw="current_password"
                                            aria-label="Toggle current password visibility"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                            style="width:auto;margin:0;box-shadow:none;">
                                        <i class="fa-regular fa-eye text-sm"></i>
                                    </button>
                                </div>
                                <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                                    Required for updating either your username or password.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <!-- New Password -->
                                <div>
                                    <label for="new_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                        New Password
                                    </label>
                                    <div class="relative">
                                        <input type="password"
                                               id="new_password"
                                               name="new_password"
                                               autocomplete="new-password"
                                               minlength="8"
                                               placeholder="Minimum 8 characters"
                                               class="w-full px-4 py-3 pr-11 rounded-2xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                        <button type="button"
                                                data-toggle-pw="new_password"
                                                aria-label="Toggle new password visibility"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                                style="width:auto;margin:0;box-shadow:none;">
                                            <i class="fa-regular fa-eye text-sm"></i>
                                        </button>
                                    </div>
                                    <p class="text-[11px] font-medium text-[#8B7168] mt-1.5 mb-0">
                                        Leave blank to keep your current password.
                                    </p>
                                </div>

                                <!-- Confirm New Password -->
                                <div>
                                    <label for="confirm_password" class="block text-xs font-bold text-[#59443F] uppercase tracking-wider mb-2">
                                        Confirm New Password
                                    </label>
                                    <div class="relative">
                                        <input type="password"
                                               id="confirm_password"
                                               name="confirm_password"
                                               autocomplete="new-password"
                                               minlength="8"
                                               placeholder="Re-enter new password"
                                               class="w-full px-4 py-3 pr-11 rounded-2xl border border-[#E8C7C5]/80 bg-[#F8EEEE]/40 focus:bg-white text-sm font-semibold text-[#59443F] focus:outline-none focus:ring-2 focus:ring-[#D6A6A6] transition">
                                        <button type="button"
                                                data-toggle-pw="confirm_password"
                                                aria-label="Toggle confirm password visibility"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[#8B7168] hover:text-[#59443F] p-1 cursor-pointer bg-transparent border-0"
                                                style="width:auto;margin:0;box-shadow:none;">
                                            <i class="fa-regular fa-eye text-sm"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="pt-4 border-t border-[#E8C7C5]/30 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <a href="<?php echo htmlspecialchars(capstone_url('modules/student_view.php')); ?>"
                               class="w-full sm:w-auto px-6 py-3 rounded-2xl bg-[#F8EEEE] hover:bg-[#E8C7C5]/50 text-[#59443F] font-bold text-xs sm:text-sm transition border border-[#E8C7C5]/70 text-center shadow-xs">
                                Back to Dashboard
                            </a>
                            <button type="submit"
                                    id="saveAccountBtn"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 px-7 py-3.5 rounded-2xl bg-[#59443F] hover:bg-[#6E554F] active:scale-[0.99] text-white text-xs sm:text-sm font-bold shadow-md hover:shadow-lg transition disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer">
                                <i id="saveAccountSpinner" class="fa-solid fa-circle-notch fa-spin hidden"></i>
                                <i id="saveAccountIcon" class="fa-solid fa-floppy-disk text-[#E8C7C5]"></i>
                                <span id="saveAccountBtnText">Save Account Changes</span>
                            </button>
                        </div>
                    </form>
                </section>
            </div><!-- /#tabPanelAccount -->
        </div>

        <!-- Unified Tabs + Account Settings Script -->
        <script>
        (function () {
            const tabButtons = document.querySelectorAll('[data-profile-tab]');
            const tabPanelProfile = document.getElementById('tabPanelProfile');
            const tabPanelAccount = document.getElementById('tabPanelAccount');

            function switchProfileTab(tabName, updateUrl) {
                const isAccount = tabName === 'account';
                if (tabPanelProfile) tabPanelProfile.classList.toggle('hidden', isAccount);
                if (tabPanelAccount) tabPanelAccount.classList.toggle('hidden', !isAccount);

                tabButtons.forEach(function (btn) {
                    const active = btn.getAttribute('data-profile-tab') === (isAccount ? 'account' : 'profile');
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                    btn.classList.toggle('bg-[#59443F]', active);
                    btn.classList.toggle('text-white', active);
                    btn.classList.toggle('shadow-xs', active);
                    btn.classList.toggle('text-[#8B7168]', !active);
                    btn.classList.toggle('hover:bg-[#F8EEEE]', !active);
                    btn.classList.toggle('hover:text-[#59443F]', !active);
                });

                if (updateUrl && window.history && window.history.replaceState) {
                    const url = new URL(window.location.href);
                    if (isAccount) {
                        url.searchParams.set('tab', 'account');
                    } else {
                        url.searchParams.delete('tab');
                    }
                    window.history.replaceState(null, '', url.toString());
                }
            }

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switchProfileTab(btn.getAttribute('data-profile-tab') || 'profile', true);
                });
            });

            if (window.location.hash === '#account') {
                switchProfileTab('account', false);
            }

            // Account & Security AJAX Handler
            const form = document.getElementById('accountSettingsForm');
            if (!form) return;

            const usernameInput = document.getElementById('new_username');
            const currentPwInput = document.getElementById('current_password');
            const newPwInput = document.getElementById('new_password');
            const confirmPwInput = document.getElementById('confirm_password');
            const csrfInput = document.getElementById('csrfTokenInput');
            const submitBtn = document.getElementById('saveAccountBtn');
            const spinner = document.getElementById('saveAccountSpinner');
            const icon = document.getElementById('saveAccountIcon');
            const btnText = document.getElementById('saveAccountBtnText');
            const alertBox = document.getElementById('accountAlertBox');
            const alertIcon = document.getElementById('accountAlertIcon');
            const alertText = document.getElementById('accountAlertText');

            form.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = document.getElementById(btn.getAttribute('data-toggle-pw'));
                    if (!target) return;
                    const show = target.type === 'password';
                    target.type = show ? 'text' : 'password';
                    const i = btn.querySelector('i');
                    if (i) {
                        i.classList.toggle('fa-eye', !show);
                        i.classList.toggle('fa-eye-slash', show);
                    }
                });
            });

            function showFeedback(message, isError) {
                if (typeof window.showToast === 'function') {
                    window.showToast(message, isError ? 'error' : 'success');
                }
                if (!alertBox || !alertText || !alertIcon) return;
                alertBox.classList.remove('hidden', 'bg-rose-50', 'border-rose-200', 'text-rose-800', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-800');
                alertBox.classList.add('flex');
                if (isError) {
                    alertBox.classList.add('bg-rose-50', 'border', 'border-rose-200', 'text-rose-800');
                    alertIcon.className = 'fa-solid fa-triangle-exclamation text-rose-600 text-lg shrink-0';
                } else {
                    alertBox.classList.add('bg-emerald-50', 'border', 'border-emerald-200', 'text-emerald-800');
                    alertIcon.className = 'fa-solid fa-circle-check text-emerald-600 text-lg shrink-0';
                }
                alertText.textContent = message;
            }

            function setLoading(loading) {
                submitBtn.disabled = loading;
                spinner.classList.toggle('hidden', !loading);
                icon.classList.toggle('hidden', loading);
                btnText.textContent = loading ? 'Saving Changes...' : 'Save Account Changes';
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                const newUsername = (usernameInput.value || '').trim();
                const originalUsername = usernameInput.getAttribute('data-current-username') || '';
                const currentPassword = currentPwInput.value || '';
                const newPassword = newPwInput.value || '';
                const confirmPassword = confirmPwInput.value || '';

                const usernameChanged = newUsername !== originalUsername;
                const passwordChanged = newPassword !== '' || confirmPassword !== '';

                if (!currentPassword) {
                    showFeedback('Current password is required to save changes.', true);
                    currentPwInput.focus();
                    return;
                }
                if (!usernameChanged && !passwordChanged) {
                    showFeedback('Please update your username or enter a new password.', true);
                    return;
                }
                if (usernameChanged && (newUsername.length < 3 || newUsername.length > 50 || !/^[A-Za-z0-9_.-]+$/.test(newUsername))) {
                    showFeedback('Username must be 3–50 characters (letters, numbers, underscores, hyphens, or dots).', true);
                    usernameInput.focus();
                    return;
                }
                if (passwordChanged && newPassword.length < 8) {
                    showFeedback('New password must be at least 8 characters long.', true);
                    newPwInput.focus();
                    return;
                }
                if (passwordChanged && newPassword !== confirmPassword) {
                    showFeedback('New password and confirmation password do not match.', true);
                    confirmPwInput.focus();
                    return;
                }

                setLoading(true);
                try {
                    const res = await fetch('student_account.php?ajax=1', {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfInput ? csrfInput.value : ''
                        },
                        body: JSON.stringify({
                            csrf_token: csrfInput ? csrfInput.value : '',
                            new_username: newUsername,
                            current_password: currentPassword,
                            new_password: newPassword,
                            confirm_password: confirmPassword
                        })
                    });

                    const data = await res.json();
                    if (!res.ok || !data || data.ok !== true) {
                        throw new Error((data && data.error) ? data.error : 'Failed to update account settings.');
                    }

                    if (data.username) {
                        usernameInput.value = data.username;
                        usernameInput.setAttribute('data-current-username', data.username);
                        const sbUsernameEl = document.getElementById('sidebarUsernameText');
                        if (sbUsernameEl) sbUsernameEl.textContent = data.username;
                        const sbAvatarImg = document.getElementById('sidebarUserAvatar');
                        if (sbAvatarImg) sbAvatarImg.alt = data.username;
                    }
                    currentPwInput.value = '';
                    newPwInput.value = '';
                    confirmPwInput.value = '';
                    showFeedback(data.message || 'Account settings updated successfully.', false);
                } catch (err) {
                    showFeedback(err && err.message ? err.message : 'Unable to save changes. Please try again.', true);
                } finally {
                    setLoading(false);
                }
            });
        })();
        </script>

        <!-- Instant Avatar Live Preview Script -->
        <script>
        (function() {
            var fileInput = document.getElementById('profile_picture');
            var previewImg = document.getElementById('avatarPreviewImg');
            var fallbackBadge = document.getElementById('avatarFallbackBadge');
            var infoBox = document.getElementById('fileSelectionInfo');
            var fileNameSpan = document.getElementById('selectedFileName');
            var fileSizeSpan = document.getElementById('selectedFileSize');
            var resetBtn = document.getElementById('resetPhotoBtn');

            if (!fileInput) return;

            var originalSrc = previewImg ? previewImg.getAttribute('src') : '';
            var originalHasImg = originalSrc && originalSrc.trim() !== '';

            fileInput.addEventListener('change', function(e) {
                var file = e.target.files && e.target.files[0];
                if (!file) return;

                // Validate file size client-side (2MB)
                if (file.size > 2 * 1024 * 1024) {
                    alert('The selected image is larger than 2MB. Please select a smaller photo.');
                    fileInput.value = '';
                    return;
                }

                // Show live preview
                var reader = new FileReader();
                reader.onload = function(evt) {
                    if (previewImg) {
                        previewImg.src = evt.target.result;
                        previewImg.classList.remove('hidden');
                    }
                    if (fallbackBadge) {
                        fallbackBadge.classList.add('hidden');
                    }
                };
                reader.readAsDataURL(file);

                // Update file info pill
                if (fileNameSpan) fileNameSpan.textContent = file.name;
                if (fileSizeSpan) {
                    var kb = Math.round(file.size / 1024);
                    fileSizeSpan.textContent = '(' + (kb > 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB') + ')';
                }
                if (infoBox) infoBox.classList.remove('hidden');
            });

            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    fileInput.value = '';
                    if (infoBox) infoBox.classList.add('hidden');
                    if (originalHasImg && previewImg) {
                        previewImg.src = originalSrc;
                        previewImg.classList.remove('hidden');
                        if (fallbackBadge) fallbackBadge.classList.add('hidden');
                    } else {
                        if (previewImg) previewImg.classList.add('hidden');
                        if (fallbackBadge) fallbackBadge.classList.remove('hidden');
                    }
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../includes/layout_app_end.php'; ?>

