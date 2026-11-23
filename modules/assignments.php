<?php
/**
 * System: EnformaSys
 * Module: Assignments (Deprecated & Disabled)
 * 
 * This module has been decommissioned per administrative request.
 * Direct requests are safely redirected to the Academics dashboard.
 */
include_once __DIR__ . '/../includes/session_fix.php';
require_login();

header('Location: ' . capstone_url('modules/academic.php'));
exit;
