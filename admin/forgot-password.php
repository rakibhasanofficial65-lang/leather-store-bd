<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

if (empty($_SESSION['forgot_csrf'])) {
    $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!is_string($csrf) || !hash_equals($_SESSION['forgot_csrf'], $csrf)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Always show the same message to prevent email/account discovery.
            $success = 'If an active, verified admin account exists for this email, a password reset link will be sent shortly.';

            try {
                $stmt = $pdo->prepare(
                    "SELECT id, name, email
                     FROM admins
                     WHERE email = ?
                       AND status = 'active'
                       AND email_verified_at IS NOT NULL
                     LIMIT 1"
                );
                $stmt->execute([$email]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($admin) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    // Remove previous unused password reset links for this admin.
                    $delete = $pdo->prepare(
                        "DELETE FROM admin_tokens
                         WHERE admin_id = ? AND purpose = 'password_reset'"
                    );
                    $delete->execute([(int)$admin['id']]);

                    $insert = $pdo->prepare(
                        "INSERT INTO admin_tokens
                         (admin_id, token_hash, purpose, expires_at)
                         VALUES (?, ?, 'password_reset', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
                    );
                    $insert->execute([(int)$admin['id'], $tokenHash]);

                    // For local XAMPP. Change this base URL when deploying online.
                    $baseUrl = 'http://localhost/leather-store-bd';
                    $resetUrl = $baseUrl . '/admin/reset-password.php?token=' . urlencode($token);

                    $safeName = htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8');
                    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

                    $html = "
                        <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333'>
                            <h2>Leather Store BD</h2>
                            <p>Hello {$safeName},</p>
                            <p>We received a request to reset your Admin account password.</p>
                            <p>
                                <a href='{$safeUrl}'
                                   style='display:inline-block;padding:12px 22px;background:#7044b8;color:#fff;text-decoration:none;border-radius:6px'>
                                   Reset Password
                                </a>
                            </p>
                            <p>This link expires in 30 minutes and can only be used once.</p>
                            <p>If you did not request this, you can ignore this email.</p>
                        </div>
                    ";

                    $sent = sendStoreEmail(
                        $admin['email'],
                        $admin['name'],
                        'Reset Your Admin Password - Leather Store BD',
                        $html
                    );

                    if (!$sent) {
                        // Remove unusable token if email delivery failed.
                        $remove = $pdo->prepare(
                            "DELETE FROM admin_tokens
                             WHERE admin_id = ? AND token_hash = ? AND purpose = 'password_reset'"
                        );
                        $remove->execute([(int)$admin['id'], $tokenHash]);
                        error_log('Password reset email could not be sent for admin ID ' . $admin['id']);
                    }
                }
            } catch (Throwable $e) {
                error_log('Forgot password error: ' . $e->getMessage());
                // Keep the same public response.
            }

            unset($_SESSION['forgot_csrf']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | Leather Store BD</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:radial-gradient(ellipse at 50% 40%,#30204d,#100d20 50%,#05040c);color:#fff;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:18px}
.card{width:100%;max-width:430px;padding:34px;border:1px solid #7954a766;border-radius:18px;background:rgba(13,11,25,.9);box-shadow:0 0 45px #7644c633}
.brand{text-align:center;margin-bottom:25px}
.brand h1{font-size:23px;letter-spacing:2px;color:#e6d9ff}
.brand p{font-size:12px;letter-spacing:2px;color:#aaa1bd;margin-top:8px}
.info{font-size:14px;line-height:1.6;color:#c8bfd8;margin-bottom:20px;text-align:center}
label{display:block;margin:14px 0 8px;color:#d0c5e3;font-size:13px}
input{width:100%;padding:14px;border-radius:8px;border:1px solid #39314f;background:#ffffff0b;color:white;outline:none;font-size:16px}
input:focus{border-color:#a77aff;box-shadow:0 0 13px #7041bb55}
button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:8px;background:linear-gradient(100deg,#6639bb,#176fa7);color:white;font-weight:bold;cursor:pointer}
.message{padding:12px;border-radius:7px;margin-bottom:15px;font-size:13px;line-height:1.5}
.error{background:#541e35;color:#ffb9cf}
.success{background:#123c2d;color:#9df0c2}
.footer{text-align:center;margin-top:22px;font-size:13px;color:#aaa1bd}
a{color:#c9a4ff;text-decoration:none}
@media(max-width:480px){.card{padding:26px 20px}.brand h1{font-size:21px}}
</style>
</head>
<body>
<div class="card">
    <div class="brand">
        <h1>LEATHER STORE BD</h1>
        <p>ADMIN PASSWORD RECOVERY</p>
    </div>

    <p class="info">Enter the email address registered with your Admin account. If it is eligible, you will receive a secure reset link.</p>

    <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['forgot_csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">Registered Admin Email</label>
        <input type="email" id="email" name="email"
               placeholder="Enter your registered email" required autocomplete="email">
        <button type="submit">SEND RESET LINK</button>
    </form>

    <div class="footer">
        <a href="login.php">← Back to Admin Login</a>
    </div>
</div>
</body>
</html>
