<?php
declare(strict_types=1);

/**
 * EnformaSys Seminarian Profile & Avatar Helper
 * 
 * Provides idempotent database schema verification, avatar URL resolution,
 * 7-day cooldown calculation, and secure file upload handling.
 */

require_once __DIR__ . '/url.php';

if (!function_exists('ensure_seminarian_profile_schema')) {
    /**
     * Idempotently verifies and adds profile_picture and last_profile_update columns
     * to the seminarians table, and initializes the uploads directory.
     */
    function ensure_seminarian_profile_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        // Check columns in seminarians table
        $cols_res = @$conn->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'seminarians' 
               AND COLUMN_NAME IN ('profile_picture', 'last_profile_update')"
        );
        $existing_cols = [];
        if ($cols_res) {
            while ($row = $cols_res->fetch_assoc()) {
                $existing_cols[] = strtolower((string)$row['COLUMN_NAME']);
            }
        }

        if (!in_array('profile_picture', $existing_cols, true)) {
            @$conn->query("ALTER TABLE `seminarians` ADD COLUMN `profile_picture` VARCHAR(255) NULL");
        }

        if (!in_array('last_profile_update', $existing_cols, true)) {
            @$conn->query("ALTER TABLE `seminarians` ADD COLUMN `last_profile_update` DATETIME NULL");
        }

        // Initialize secure uploads directory
        $upload_dir = __DIR__ . '/../uploads/profile_pictures';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }
        $index_file = $upload_dir . '/index.html';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');
        }
    }
}

if (!function_exists('get_seminarian_avatar_url')) {
    /**
     * Resolve a web-accessible URL for a seminarian profile picture.
     * Returns null if no picture is set or if the file does not exist on disk.
     */
    function get_seminarian_avatar_url(?string $profile_picture): ?string
    {
        if ($profile_picture === null || trim($profile_picture) === '') {
            return null;
        }

        $clean_rel = ltrim(trim($profile_picture), '/\\');
        $full_path = __DIR__ . '/../' . $clean_rel;

        if (is_file($full_path) && is_readable($full_path)) {
            return capstone_url($clean_rel);
        }

        return null;
    }
}

if (!function_exists('get_seminarian_initials')) {
    /**
     * Compute clean 2-character initials for a seminarian name fallback.
     */
    function get_seminarian_initials(string $first_name, string $last_name = '', string $fallback = 'Seminarian'): string
    {
        $f = trim($first_name);
        $l = trim($last_name);

        if ($f !== '' && $l !== '') {
            $initials = strtoupper(mb_substr($f, 0, 1) . mb_substr($l, 0, 1));
            if (mb_strlen($initials) === 2) {
                return $initials;
            }
        }

        $name = ($f !== '') ? $f : trim($fallback);
        $words = preg_split('/\s+/', $name);
        if ($words && count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }

        if (mb_strlen($name) >= 2) {
            return strtoupper(mb_substr($name, 0, 2));
        }

        return 'SM';
    }
}

if (!function_exists('get_seminarian_cooldown_status')) {
    /**
     * Calculates the 7-day cooldown status between NOW() and last_profile_update.
     *
     * @return array{
     *     is_active: bool,
     *     can_edit: bool,
     *     last_update: ?DateTime,
     *     cooldown_ends: ?DateTime,
     *     formatted_unlock_date: ?string,
     *     remaining_text: ?string,
     *     badge_message: ?string
     * }
     */
    function get_seminarian_cooldown_status(?string $last_profile_update): array
    {
        $default = [
            'is_active' => false,
            'can_edit' => true,
            'last_update' => null,
            'cooldown_ends' => null,
            'formatted_unlock_date' => null,
            'remaining_text' => null,
            'badge_message' => null,
        ];

        if (empty($last_profile_update) || $last_profile_update === '0000-00-00 00:00:00') {
            return $default;
        }

        try {
            $last_update = new DateTime($last_profile_update);
            $cooldown_ends = (clone $last_update)->modify('+7 days');
            $now = new DateTime();

            if ($now < $cooldown_ends) {
                $diff = $now->diff($cooldown_ends);
                $formatted_unlock_date = $cooldown_ends->format('F j, Y \a\t g:i A');

                $parts = [];
                if ($diff->days > 0) {
                    $parts[] = $diff->days . 'd';
                }
                if ($diff->h > 0) {
                    $parts[] = $diff->h . 'h';
                }
                $parts[] = max(1, $diff->i) . 'm';
                $remaining_text = implode(' ', $parts) . ' remaining';

                return [
                    'is_active' => true,
                    'can_edit' => false,
                    'last_update' => $last_update,
                    'cooldown_ends' => $cooldown_ends,
                    'formatted_unlock_date' => $formatted_unlock_date,
                    'remaining_text' => $remaining_text,
                    'badge_message' => "You can only update your profile once a week. You can edit again on {$formatted_unlock_date}.",
                ];
            }
        } catch (\Throwable $e) {
            // Malformed datetime falls through to can_edit = true
        }

        return $default;
    }
}

if (!function_exists('save_seminarian_profile_picture')) {
    /**
     * Validates and saves an uploaded avatar image for a seminarian.
     * Enforces file size limits, MIME type inspection, image dimension checks,
     * secure unique naming, and cleanup of old picture files.
     *
     * @param int $seminarian_id
     * @param array $file Typically $_FILES['profile_picture']
     * @param string|null $old_picture Existing database path
     * @return array{success: bool, path: ?string, error: ?string}
     */
    function save_seminarian_profile_picture(int $seminarian_id, array $file, ?string $old_picture = null): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file upload parameter.'];
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            // No file uploaded is acceptable (user may be editing profile details without changing photo)
            return ['success' => true, 'path' => null, 'error' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            ];
            $err_msg = $upload_errors[$file['error']] ?? 'File upload failed with error code ' . $file['error'];
            return ['success' => false, 'path' => null, 'error' => $err_msg];
        }

        // Limit file size to 2MB
        $max_size = 2 * 1024 * 1024;
        if ((int)$file['size'] > $max_size) {
            return ['success' => false, 'path' => null, 'error' => 'File size exceeds the 2MB limit. Please upload a smaller image.'];
        }

        // Validate extension
        $raw_ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($raw_ext, $allowed_exts, true)) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file format. Only JPG, PNG, and WebP images are allowed.'];
        }

        // Validate MIME type via finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, (string)$file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed_mimes[$mime])) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid image content type (' . htmlspecialchars((string)$mime) . '). Only valid JPG, PNG, and WebP images are accepted.'];
        }

        $canonical_ext = $allowed_mimes[$mime];

        // Validate image dimensions to ensure file is an authentic image
        $dimensions = @getimagesize((string)$file['tmp_name']);
        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            return ['success' => false, 'path' => null, 'error' => 'Corrupt or unreadable image file.'];
        }

        // Target directory
        $target_dir = __DIR__ . '/../uploads/profile_pictures/';
        if (!is_dir($target_dir)) {
            if (!@mkdir($target_dir, 0755, true)) {
                return ['success' => false, 'path' => null, 'error' => 'Unable to create profile pictures storage directory.'];
            }
        }

        // Generate clean, collision-free filename
        $unique_name = sprintf(
            'seminarian_%d_%d_%s.%s',
            $seminarian_id,
            time(),
            bin2hex(random_bytes(4)),
            $canonical_ext
        );
        $dest_path = $target_dir . $unique_name;

        $moved = is_uploaded_file((string)$file['tmp_name'])
            ? move_uploaded_file((string)$file['tmp_name'], $dest_path)
            : (@copy((string)$file['tmp_name'], $dest_path) && @unlink((string)$file['tmp_name']));

        if (!$moved) {
            return ['success' => false, 'path' => null, 'error' => 'Failed to save uploaded image to destination folder.'];
        }

        @chmod($dest_path, 0644);

        $rel_path = 'uploads/profile_pictures/' . $unique_name;

        // Clean up previous picture if it exists within the profile pictures folder
        if (!empty($old_picture)) {
            $old_clean = ltrim(trim($old_picture), '/\\');
            if (strpos($old_clean, 'uploads/profile_pictures/') === 0) {
                $old_full = realpath(__DIR__ . '/../' . $old_clean);
                $allowed_dir = realpath($target_dir);
                if ($old_full && $allowed_dir && strpos($old_full, $allowed_dir) === 0 && is_file($old_full)) {
                    @unlink($old_full);
                }
            }
        }

        return ['success' => true, 'path' => $rel_path, 'error' => null];
    }
}
