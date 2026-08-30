<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
require_once __DIR__ . '/classes/SecurityAudit.php';

// Log logout to security audit before session is destroyed
SecurityAudit::logLogout(
    $_SESSION['user_id'] ?? 0,
    $_SESSION['username'] ?? 'unknown',
    $_SERVER['REMOTE_ADDR'] ?? ''
);

Auth::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
