<?php
/**
 * Lightweight zero-dependency environment loader (.env and .env.local).
 * Platform-level environment variables (e.g., Vercel Environment Variables)
 * always take precedence over local files.
 */
if (defined('CAPSTONE_ENV_LOADED')) {
    return;
}
define('CAPSTONE_ENV_LOADED', true);

if (!function_exists('enformasys_load_env_file')) {
    function enformasys_load_env_file(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            if (stripos($trimmed, 'export ') === 0) {
                $trimmed = trim(substr($trimmed, 7));
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eqPos));
            $val = trim(substr($trimmed, $eqPos + 1));
            if ($key === '') {
                continue;
            }
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false && !array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
                @putenv("{$key}={$val}");
            }
        }
    }
}

if (!function_exists('enformasys_set_env_default')) {
    function enformasys_set_env_default(string $key, string $val, bool $override = false): void
    {
        if ($key === '') {
            return;
        }
        $exists = (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER));
        if (!$exists || $override) {
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            @putenv("{$key}={$val}");
        }
    }
}

if (!function_exists('enformasys_load_php_config')) {
    /**
     * Loads optional includes/config.php (used on shared hosting like InfinityFree)
     * when not running inside Vercel serverless containers.
     */
    function enformasys_load_php_config(string $path): void
    {
        static $loadedPaths = [];
        $normPath = str_replace('\\', '/', $path);
        if (isset($loadedPaths[$normPath]) || !is_file($path) || !is_readable($path)) {
            return;
        }
        $loadedPaths[$normPath] = true;

        $isVercel = (getenv('VERCEL') !== false && getenv('VERCEL') !== '')
            || (getenv('VERCEL_ENV') !== false && getenv('VERCEL_ENV') !== '')
            || !empty($_ENV['VERCEL'])
            || !empty($_SERVER['VERCEL']);
        if ($isVercel) {
            return;
        }

        $cfg = require $path;
        if (is_array($cfg)) {
            foreach ($cfg as $k => $v) {
                $key = trim((string)$k);
                if ($key === '' || $v === null) {
                    continue;
                }
                if (is_bool($v)) {
                    $strVal = $v ? 'true' : 'false';
                } elseif (is_scalar($v)) {
                    $strVal = (string)$v;
                } else {
                    continue;
                }
                enformasys_set_env_default($key, $strVal, true);
            }
        }

        // Also support classic define('DB_HOST', '...') config files
        $knownConsts = [
            'APP_ENV', 'APP_URL', 'ALLOWED_ORIGINS',
            'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_PASSWORD', 'DB_NAME',
            'DB_SSL', 'DB_SSL_CA', 'DB_PERSISTENT', 'DB_SKIP_SCHEMA_SYNC',
            'DB_SESSION_HANDLER', 'SESSION_DRIVER', 'SESSION_LIFETIME',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME',
        ];
        foreach ($knownConsts as $cname) {
            if (defined($cname)) {
                $cv = constant($cname);
                if (is_bool($cv)) {
                    enformasys_set_env_default($cname, $cv ? 'true' : 'false', true);
                } elseif (is_scalar($cv) && $cv !== null) {
                    enformasys_set_env_default($cname, (string)$cv, true);
                }
            }
        }
    }
}

$__root = dirname(__DIR__);
// Load .env.local and .env first; includes/config.php (when present off-Vercel) takes precedence on shared hosts
enformasys_load_env_file($__root . DIRECTORY_SEPARATOR . '.env.local');
enformasys_load_env_file($__root . DIRECTORY_SEPARATOR . '.env');
enformasys_load_php_config(__DIR__ . DIRECTORY_SEPARATOR . 'config.php');

if (!function_exists('enformasys_env')) {
    function enformasys_env(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string)$_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return (string)$_SERVER[$key];
        }
        $val = getenv($key);
        if ($val !== false) {
            return (string)$val;
        }
        return $default;
    }
}

if (!function_exists('enformasys_is_production')) {
    function enformasys_is_production(): bool
    {
        $env = strtolower(trim((string)enformasys_env('APP_ENV', enformasys_env('VERCEL_ENV', 'development'))));
        return $env === 'production' || $env === 'prod';
    }
}
