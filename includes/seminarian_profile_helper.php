<?php
declare(strict_types=1);

/**
 * EnformaSys Seminarian Profile & Avatar Helper
 * 
 * Provides idempotent database schema verification, avatar URL resolution,
 * 7-day cooldown calculation, and secure file upload handling.
 */

require_once __DIR__ . '/url.php';

if (!function_exists('ensure_seminarian_profile_picture_column')) {
    /**
     * Ensures seminarians.profile_picture exists and uses MEDIUMTEXT so
     * compact data:image/...;base64,... URIs store safely on read-only serverless hosts.
     */
    function ensure_seminarian_profile_picture_column(mysqli $conn): void
    {
        static $verified = false;
        if ($verified) {
            return;
        }
        $verified = true;

        $col_res = @$conn->query(
            "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'seminarians'
               AND COLUMN_NAME = 'profile_picture'
             LIMIT 1"
        );
        if (!$col_res) {
            return;
        }
        $row = $col_res->fetch_assoc();
        if (!$row) {
            @$conn->query("ALTER TABLE `seminarians` ADD COLUMN `profile_picture` MEDIUMTEXT NULL");
            return;
        }
        $dtype = strtolower(trim((string)($row['DATA_TYPE'] ?? '')));
        if (!in_array($dtype, ['mediumtext', 'longtext', 'text'], true)) {
            @$conn->query("ALTER TABLE `seminarians` MODIFY COLUMN `profile_picture` MEDIUMTEXT NULL");
        }
    }
}

if (!function_exists('ensure_seminarian_profile_schema')) {
    /**
     * Idempotently verifies and adds profile_picture and last_profile_update columns
     * to the seminarians table, and initializes the uploads directory on writable hosts.
     */
    function ensure_seminarian_profile_schema(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        ensure_seminarian_profile_picture_column($conn);

        $cols_res = @$conn->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'seminarians' 
               AND COLUMN_NAME = 'last_profile_update'"
        );
        if ($cols_res && $cols_res->num_rows === 0) {
            @$conn->query("ALTER TABLE `seminarians` ADD COLUMN `last_profile_update` DATETIME NULL");
        }

        // Initialize secure uploads directory only when filesystem is writable
        $is_vercel = (getenv('VERCEL') !== false && getenv('VERCEL') !== '')
            || (getenv('VERCEL_ENV') !== false && getenv('VERCEL_ENV') !== '')
            || !empty($_ENV['VERCEL'])
            || !empty($_SERVER['VERCEL']);
        if (!$is_vercel) {
            $upload_dir = __DIR__ . '/../uploads/profile_pictures';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            if (is_dir($upload_dir) && is_writable($upload_dir)) {
                $index_file = $upload_dir . '/index.html';
                if (!file_exists($index_file)) {
                    @file_put_contents($index_file, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');
                }
            }
        }
    }
}

if (!function_exists('get_seminarian_avatar_url')) {
    /**
     * Resolve a web-accessible URL (or safe base64 data URI) for a seminarian profile picture.
     * Returns null if no picture is set or if a local file does not exist on disk.
     */
    function get_seminarian_avatar_url(?string $profile_picture): ?string
    {
        if ($profile_picture === null) {
            return null;
        }
        $trimmed = trim($profile_picture);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,[A-Za-z0-9+/=\r\n]+$#i', $trimmed)) {
            return preg_replace('/\s+/', '', $trimmed);
        }

        if (preg_match('#^https?://#i', $trimmed)) {
            return $trimmed;
        }

        $clean_rel = ltrim($trimmed, '/\\');
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

if (!function_exists('enformasys_encode_avatar_data_uri')) {
    /**
     * Resizes and compresses a validated avatar image (max 300x300 px) via PHP GD
     * and returns a data:image/...;base64,... URI. Falls back to raw base64 if GD is unavailable.
     */
    function enformasys_encode_avatar_data_uri(string $tmp_path, string $mime): ?string
    {
        if (!is_file($tmp_path) || !is_readable($tmp_path)) {
            return null;
        }

        if (extension_loaded('gd')) {
            $src = null;
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
                $src = @imagecreatefromjpeg($tmp_path);
                if ($src && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($tmp_path);
                    $orientation = (int)($exif['Orientation'] ?? 1);
                    if ($orientation === 3 && function_exists('imagerotate')) {
                        $rot = @imagerotate($src, 180, 0);
                        if ($rot) $src = $rot;
                    } elseif ($orientation === 6 && function_exists('imagerotate')) {
                        $rot = @imagerotate($src, -90, 0);
                        if ($rot) $src = $rot;
                    } elseif ($orientation === 8 && function_exists('imagerotate')) {
                        $rot = @imagerotate($src, 90, 0);
                        if ($rot) $src = $rot;
                    }
                }
            } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                $src = @imagecreatefrompng($tmp_path);
            } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($tmp_path);
            }

            if ($src) {
                $w = @imagesx($src);
                $h = @imagesy($src);
                if ($w > 0 && $h > 0) {
                    $max_dim = 300;
                    if ($w > $max_dim || $h > $max_dim) {
                        $scale = $max_dim / max($w, $h);
                        $nw = max(1, (int)round($w * $scale));
                        $nh = max(1, (int)round($h * $scale));
                    } else {
                        $nw = $w;
                        $nh = $h;
                    }

                    $dst = @imagecreatetruecolor($nw, $nh);
                    if ($dst) {
                        $use_webp = function_exists('imagewebp');
                        if ($use_webp) {
                            @imagealphablending($dst, false);
                            @imagesavealpha($dst, true);
                            $transparent = @imagecolorallocatealpha($dst, 255, 255, 255, 127);
                            if ($transparent !== false) {
                                @imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
                            }
                        } else {
                            $white = @imagecolorallocate($dst, 255, 255, 255);
                            if ($white !== false) {
                                @imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
                            }
                        }
                        @imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

                        ob_start();
                        $ok = false;
                        $out_mime = 'image/jpeg';
                        if ($use_webp) {
                            $ok = @imagewebp($dst, null, 82);
                            $out_mime = 'image/webp';
                        } elseif (function_exists('imagejpeg')) {
                            $ok = @imagejpeg($dst, null, 85);
                            $out_mime = 'image/jpeg';
                        }
                        $bytes = ob_get_clean();
                        unset($dst, $src);

                        if ($ok && is_string($bytes) && $bytes !== '') {
                            return 'data:' . $out_mime . ';base64,' . base64_encode($bytes);
                        }
                    }
                }
                unset($src);
            }
        }

        $raw = @file_get_contents($tmp_path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}

if (!function_exists('save_seminarian_profile_picture')) {
    /**
     * Validates and saves an uploaded avatar image for a seminarian.
     * Enforces file size limits, MIME type inspection, and image dimension checks.
     * On Vercel or read-only filesystems, compresses via GD and returns a compact data URI.
     *
     * @param int $seminarian_id
     * @param array $file Typically $_FILES['profile_picture']
     * @param string|null $old_picture Existing database value
     * @param mysqli|null $conn Optional DB connection to ensure MEDIUMTEXT storage
     * @return array{success: bool, path: ?string, error: ?string}
     */
    function save_seminarian_profile_picture(int $seminarian_id, array $file, ?string $old_picture = null, ?mysqli $conn = null): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file upload parameter.'];
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
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

        $max_size = 2 * 1024 * 1024;
        if ((int)$file['size'] > $max_size) {
            return ['success' => false, 'path' => null, 'error' => 'File size exceeds the 2MB limit. Please upload a smaller image.'];
        }

        $raw_ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($raw_ext, $allowed_exts, true)) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid file format. Only JPG, PNG, and WebP images are allowed.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed_mimes[$mime])) {
            return ['success' => false, 'path' => null, 'error' => 'Invalid image content type (' . htmlspecialchars($mime) . '). Only valid JPG, PNG, and WebP images are accepted.'];
        }

        $canonical_ext = $allowed_mimes[$mime];

        $dimensions = @getimagesize((string)$file['tmp_name']);
        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            return ['success' => false, 'path' => null, 'error' => 'Corrupt or unreadable image file.'];
        }

        $is_vercel = (getenv('VERCEL') !== false && getenv('VERCEL') !== '')
            || (getenv('VERCEL_ENV') !== false && getenv('VERCEL_ENV') !== '')
            || !empty($_ENV['VERCEL'])
            || !empty($_SERVER['VERCEL'])
            || !empty($_ENV['VERCEL_ENV'])
            || !empty($_SERVER['VERCEL_ENV']);

        $target_dir = __DIR__ . '/../uploads/profile_pictures/';
        $saved_rel_path = null;

        if (!$is_vercel) {
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            if (is_dir($target_dir) && is_writable($target_dir)) {
                $unique_name = sprintf(
                    'seminarian_%d_%d_%s.%s',
                    $seminarian_id,
                    time(),
                    bin2hex(random_bytes(4)),
                    $canonical_ext
                );
                $dest_path = $target_dir . $unique_name;
                $moved = is_uploaded_file((string)$file['tmp_name'])
                    ? @move_uploaded_file((string)$file['tmp_name'], $dest_path)
                    : (@copy((string)$file['tmp_name'], $dest_path) && @unlink((string)$file['tmp_name']));

                if ($moved && is_file($dest_path)) {
                    @chmod($dest_path, 0644);
                    $saved_rel_path = 'uploads/profile_pictures/' . $unique_name;
                }
            }
        }

        if ($saved_rel_path === null) {
            $data_uri = enformasys_encode_avatar_data_uri((string)$file['tmp_name'], $mime);
            if ($data_uri === null) {
                return ['success' => false, 'path' => null, 'error' => 'Failed to process uploaded image.'];
            }
            if ($conn instanceof mysqli) {
                ensure_seminarian_profile_picture_column($conn);
            }
            $saved_rel_path = $data_uri;
        }

        // Clean up previous local file picture if it exists inside writable uploads/profile_pictures
        if (!empty($old_picture)) {
            $old_clean = ltrim(trim($old_picture), '/\\');
            if (strpos($old_clean, 'uploads/profile_pictures/') === 0 && is_dir($target_dir) && is_writable($target_dir)) {
                $old_full = realpath(__DIR__ . '/../' . $old_clean);
                $allowed_dir = realpath($target_dir);
                if ($old_full && $allowed_dir && strpos($old_full, $allowed_dir) === 0 && is_file($old_full)) {
                    @unlink($old_full);
                }
            }
        }

        return ['success' => true, 'path' => $saved_rel_path, 'error' => null];
    }
}
