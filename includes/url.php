<?php

$capstone_dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
if ($capstone_dir === '\\' || $capstone_dir === '.') {
    $capstone_dir = '';
}
$capstone_dir = rtrim($capstone_dir, '/');
$capstone_base_url = preg_replace('#/modules$#', '', $capstone_dir);
if ($capstone_base_url === '/') {
    $capstone_base_url = '';
}

function capstone_url(string $path): string {
    global $capstone_base_url;
    return $capstone_base_url . '/' . ltrim($path, '/');
}

