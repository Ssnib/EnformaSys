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

$__root = dirname(__DIR__);
// Load .env.local first (higher precedence than .env, lower than OS env)
enformasys_load_env_file($__root . DIRECTORY_SEPARATOR . '.env.local');
enformasys_load_env_file($__root . DIRECTORY_SEPARATOR . '.env');

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
