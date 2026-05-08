<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
ActivityLogger::log('LOGOUT', 'user', 'User', $_SESSION['user_id'] ?? null,
    $_SESSION['username'] ?? null, "Logout");
Auth::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
