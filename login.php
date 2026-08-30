<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
require_once __DIR__ . '/classes/SecurityAudit.php';

if (Auth::check()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (Auth::login($username, $password)) {
        // Log successful login to both systems
        SecurityAudit::logLogin($username, true, $ipAddress, $userAgent);
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    } else {
        // Log failed login to security audit for forensics
        SecurityAudit::logLogin($username, false, $ipAddress, $userAgent);
        $error = 'Username atau password salah';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — K-one</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', sans-serif;
    min-height: 100vh;
    background: #f0f4f4;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }

  
  .login-wrapper {
    display: flex;
    width: 100%;
    max-width: 860px;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(2, 103, 102, 0.15);
    overflow: hidden;
    min-height: 520px;
  }

  
  .login-brand {
    flex: 0 0 340px;
    background: #026766;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    position: relative;
    overflow: hidden;
    border-right: none;
  }
  .login-brand::before {
    content: '';
    position: absolute;
    top: -80px; right: -80px;
    width: 260px; height: 260px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
  }
  .login-brand::after {
    content: '';
    position: absolute;
    bottom: -60px; left: -60px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
  }
  .login-brand img {
    width: 72%;
    max-width: 220px;
    object-fit: contain;
    position: relative;
    z-index: 1;
  }

  
  .login-form-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 48px 44px;
  }
  .login-form-panel h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f1f1f;
    margin-bottom: 4px;
  }
  .login-form-panel .subtitle {
    font-size: .85rem;
    color: #6b7280;
    margin-bottom: 32px;
  }

  .form-group { margin-bottom: 20px; }
  .form-group label {
    display: block;
    font-size: .8rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: .4px;
  }
  .input-wrap { position: relative; }
  .input-wrap i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: .9rem;
  }
  .form-group input {
    width: 100%;
    padding: 11px 14px 11px 38px;
    border: 1.5px solid #d1d5db;
    border-radius: 10px;
    font-size: .9rem;
    font-family: inherit;
    color: #0f1f1f;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    background: #fafafa;
  }
  .form-group input:focus {
    border-color: #026766;
    box-shadow: 0 0 0 3px rgba(2, 103, 102, .12);
    background: #fff;
  }

  .alert-error {
    background: #e6f7f7;
    border: 1px solid #b2e5e5;
    color: #013d3c;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: .85rem;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
  }

  .btn-login {
    width: 100%;
    padding: 13px;
    background: #026766;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: .95rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: background .2s, transform .1s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
  }
  .btn-login:hover { background: #014f4e; }
  .btn-login:active { transform: scale(.99); }

  .login-footer {
    margin-top: 28px;
    text-align: center;
    font-size: .78rem;
    color: #9ca3af;
  }

  @media (max-width: 640px) {
    .login-brand { display: none; }
    .login-form-panel { padding: 36px 24px; }
    .login-wrapper { max-width: 420px; }
  }
</style>
</head>
<body>

<div class="login-wrapper">
  
  <div class="login-brand">
    <img src="<?= BASE_URL ?>/assets/logo.png" alt="K-one">
  </div>

  
  <div class="login-form-panel">
    <h2>Selamat Datang</h2>
    <p class="subtitle">Masuk ke akun K-one Anda</p>

    <?php if ($error): ?>
    <div class="alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <div class="input-wrap">
          <i class="fas fa-user"></i>
          <input type="text" name="username" placeholder="Masukkan username" required autofocus>
        </div>
      </div>
      <div class="form-group">
        <label>Password</label>
        <div class="input-wrap">
          <i class="fas fa-lock"></i>
          <input type="password" name="password" placeholder="Masukkan password" required>
        </div>
      </div>
      <button type="submit" class="btn-login">
        <i class="fas fa-sign-in-alt"></i> Masuk
      </button>
    </form>

    <div class="login-footer">
      &copy; <?= date('Y') ?> K-one. All rights reserved.
    </div>
  </div>
</div>

</body>
</html>
