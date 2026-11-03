<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['signup_csrf'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } else {

        $inviteCode = trim($_POST['invite_code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (
            !$inviteCode || !$name || !$username ||
            !$email || !$password || !$confirm
        ) {
            $error = 'Please complete all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters and use letters, numbers, dots, dashes or underscores.';
        } elseif (strlen($password) < 12) {
            $error = 'Password must contain at least 12 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {

            try {
                $pdo->beginTransaction();

                // Lock and validate invitation
                $inviteStmt = $pdo->prepare(
                    "SELECT id, created_by
                     FROM admin_invites
                     WHERE invite_code_hash = ?
                     AND used_at IS NULL
                     AND expires_at > NOW()
                     LIMIT 1
                     FOR UPDATE"
                );

                $inviteStmt->execute([
                    hash('sha256', $inviteCode)
                ]);

                $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC);

                if (!$invite) {
                    throw new Exception('Invalid, expired or already used invitation code.');
                }

                // Check maximum admin accounts
                $countStmt = $pdo->query(
                    "SELECT COUNT(*) FROM admins"
                );

                if ((int)$countStmt->fetchColumn() >= 5) {
                    throw new Exception('Maximum 5 admin accounts are allowed.');
                }

                // Check duplicate username/email
                $check = $pdo->prepare(
                    "SELECT COUNT(*) FROM admins
                     WHERE username = ? OR email = ?"
                );

                $check->execute([$username, $email]);

                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception('Username or email already exists.');
                }

                // Verify invitation creator is an active Super Admin
                $creatorStmt = $pdo->prepare(
                    "SELECT id FROM admins
                     WHERE id = ?
                     AND role = 'super_admin'
                     AND status = 'active'
                     AND email_verified_at IS NOT NULL
                     LIMIT 1"
                );

                $creatorStmt->execute([$invite['created_by']]);

                if (!$creatorStmt->fetch()) {
                    throw new Exception('Invitation creator is not an active Super Admin.');
                }

                // Create Admin
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $insert = $pdo->prepare(
                    "INSERT INTO admins
                    (name, username, phone, email, password,
                     role, status, email_verified_at)
                    VALUES (?, ?, ?, ?, ?, 'admin', 'active', NULL)"
                );

                $insert->execute([
                    $name,
                    $username,
                    $phone ?: null,
                    $email,
                    $passwordHash
                ]);

                $adminId = (int)$pdo->lastInsertId();

                // Generate verification token
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $tokenInsert = $pdo->prepare(
                    "INSERT INTO admin_tokens
                    (admin_id, token_hash, purpose, expires_at)
                    VALUES (?, ?, 'email_verify',
                    DATE_ADD(NOW(), INTERVAL 1 HOUR))"
                );

                $tokenInsert->execute([
                    $adminId,
                    $tokenHash
                ]);

                // Mark invitation as used
                $useInvite = $pdo->prepare(
                    "UPDATE admin_invites
                     SET used_at = NOW()
                     WHERE id = ? AND used_at IS NULL"
                );

                $useInvite->execute([$invite['id']]);

                if ($useInvite->rowCount() !== 1) {
                    throw new Exception('Invitation could not be used.');
                }

                $pdo->commit();

                $verifyUrl =
                    'http://localhost/leather-store-bd/admin/verify-email.php?token='
                    . urlencode($token);

                $safeName = htmlspecialchars(
                    $name,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeUrl = htmlspecialchars(
                    $verifyUrl,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $html = "
                    <h2>Leather Store BD Admin Registration</h2>
                    <p>Hello {$safeName},</p>
                    <p>Your Admin account has been created.</p>
                    <p>Please verify your email address:</p>
                    <p><a href='{$safeUrl}'>Verify Email Address</a></p>
                    <p>This link expires in 1 hour.</p>
                ";

                if (sendStoreEmail(
                    $email,
                    $name,
                    'Verify Your Admin Account - Leather Store BD',
                    $html
                )) {
                    $success = 'Admin account created! Check your email to verify your account.';
                    unset($_SESSION['signup_csrf']);
                } else {
                    // Keep account pending and allow a later resend workflow
                    $error = 'Account created, but verification email could not be sent. Contact the Super Admin for a resend.';
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Admin Signup Error: ' . $e->getMessage());

                if (!$error) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Sign Up | Leather Store BD</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    background: #05040c;
    color: white;
    font-family: Arial, sans-serif;
    overflow-x: hidden;
}

.scene {
    position: fixed;
    inset: 0;
    overflow: hidden;
    background:
        radial-gradient(ellipse at 50% 40%, #30204d, #100d20 45%, #05040c);
}

.glow {
    position: absolute;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    filter: blur(100px);
    opacity: .3;
    animation: moveGlow 8s ease-in-out infinite alternate;
}

.glow.one {
    background: #7135c9;
    top: -180px;
    left: -150px;
}

.glow.two {
    background: #007eae;
    bottom: -180px;
    right: -150px;
}

@keyframes moveGlow {
    to {
        transform: translate(100px, 100px) scale(1.2);
    }
}

.lightning {
    position: fixed;
    inset: 0;
    pointer-events: none;
    background: #c5a8ff;
    opacity: 0;
    animation: flash 7s infinite;
}

@keyframes flash {
    0%, 93%, 95%, 97%, 100% { opacity: 0; }
    94% { opacity: .12; }
    96% { opacity: .2; }
}

.signup-wrap {
    position: relative;
    z-index: 2;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 25px 15px;
}

.signup-card {
    width: 100%;
    max-width: 470px;
    padding: 35px;
    border-radius: 18px;
    border: 1px solid #7954a766;
    background: rgba(13, 11, 25, .88);
    backdrop-filter: blur(20px);
    box-shadow: 0 0 45px #7644c633;
}

.brand {
    text-align: center;
    margin-bottom: 25px;
}

.brand h1 {
    font-size: 24px;
    letter-spacing: 2px;
    color: #e6d9ff;
    text-shadow: 0 0 15px #8f55ff;
}

.brand p {
    font-size: 12px;
    letter-spacing: 3px;
    color: #aaa1bd;
    margin-top: 8px;
}

label {
    display: block;
    margin: 14px 0 7px;
    color: #d0c5e3;
    font-size: 13px;
}

input {
    width: 100%;
    padding: 13px;
    border-radius: 7px;
    border: 1px solid #39314f;
    background: #ffffff0b;
    color: white;
    outline: none;
}

input:focus {
    border-color: #a77aff;
    box-shadow: 0 0 13px #7041bb55;
}

.password-field {
    position: relative;
}

.password-field input {
    padding-right: 50px;
}

.toggle {
    position: absolute;
    right: 7px;
    top: 5px;
    width: 40px;
    height: 39px;
    border: none;
    background: transparent;
    color: #d7b9ff;
    cursor: pointer;
    font-size: 18px;
}

.submit-btn {
    width: 100%;
    margin-top: 24px;
    padding: 14px;
    border: none;
    border-radius: 8px;
    color: white;
    font-weight: bold;
    letter-spacing: 1px;
    cursor: pointer;
    background: linear-gradient(100deg, #6639bb, #176fa7);
    box-shadow: 0 0 20px #7041bb55;
}

.submit-btn:hover {
    box-shadow: 0 0 30px #925bff99;
}

.error, .success {
    padding: 12px;
    border-radius: 7px;
    margin-bottom: 15px;
    font-size: 13px;
}

.error {
    background: #541e35;
    color: #ffb9cf;
}

.success {
    background: #123c2d;
    color: #9df0c2;
}

.footer-link {
    text-align: center;
    margin-top: 22px;
    font-size: 13px;
    color: #aaa1bd;
}

.footer-link a {
    color: #c9a4ff;
    text-decoration: none;
}

@media(max-width: 520px) {
    .signup-card {
        padding: 25px 20px;
    }
}

@media(prefers-reduced-motion: reduce) {
    *, *:before, *:after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
    }
}
</style>
</head>

<body>

<div class="scene">
    <div class="glow one"></div>
    <div class="glow two"></div>
</div>

<div class="lightning"></div>

<div class="signup-wrap">
<div class="signup-card">

    <div class="brand">
        <h1>LEATHER STORE BD</h1>
        <p>ADMIN REGISTRATION</p>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>

    <form method="POST">

        <input type="hidden" name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['signup_csrf']) ?>">

        <label>Super Admin Invitation Code *</label>
        <input type="text" name="invite_code"
        placeholder="Enter invitation code" required>

        <label>Full Name *</label>
        <input type="text" name="name"
        placeholder="Your full name" required>

        <label>Username *</label>
        <input type="text" name="username"
        placeholder="Choose a username" required>

        <label>Email Address *</label>
        <input type="email" name="email"
        placeholder="Your email address" required>

        <label>Phone Number</label>
        <input type="text" name="phone"
        placeholder="Your phone number">

        <label>Password *</label>
        <div class="password-field">
            <input type="password" id="password"
            name="password" minlength="12"
            placeholder="Minimum 12 characters" required>
            <button type="button" class="toggle"
            onclick="togglePassword('password', this)">👁</button>
        </div>

        <label>Confirm Password *</label>
        <div class="password-field">
            <input type="password" id="confirm_password"
            name="confirm_password" minlength="12"
            placeholder="Confirm your password" required>
            <button type="button" class="toggle"
            onclick="togglePassword('confirm_password', this)">👁</button>
        </div>

        <button type="submit" class="submit-btn">
            CREATE ADMIN ACCOUNT
        </button>

    </form>

    <?php endif; ?>

    <div class="footer-link">
        Already have an account?
        <a href="login.php">Admin Login</a>
    </div>

</div>
</div>

<script>
function togglePassword(id, button) {
    const input = document.getElementById(id);

    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
    } else {
        input.type = 'password';
        button.textContent = '👁';
    }
}
</script>

</body>
</html>
