<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$tokenValid = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.admin_id
             FROM admin_tokens t
             INNER JOIN admins a ON a.id = t.admin_id
             WHERE t.token_hash = ?
               AND t.purpose = 'password_reset'
               AND t.expires_at > NOW()
               AND a.status = 'active'
               AND a.email_verified_at IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $tokenValid = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Reset token validation error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!is_string($csrf) || !hash_equals($_SESSION['reset_csrf'], $csrf)) {
        $error = 'Invalid request. Please refresh the page.';
    } elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $error = 'Invalid or expired reset link. Please request a new one.';
    } elseif (strlen($password) < 12) {
        $error = 'Password must contain at least 12 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the token and account while changing the password.
            $stmt = $pdo->prepare(
                "SELECT t.id AS token_id, t.admin_id
                 FROM admin_tokens t
                 INNER JOIN admins a ON a.id = t.admin_id
                 WHERE t.token_hash = ?
                   AND t.purpose = 'password_reset'
                   AND t.expires_at > NOW()
                   AND a.status = 'active'
                   AND a.email_verified_at IS NOT NULL
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([hash('sha256', $token)]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new RuntimeException('This reset link is invalid, expired or already used. Please request a new link.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare(
                "UPDATE admins
                 SET password = ?, session_version = session_version + 1
                 WHERE id = ? AND status = 'active'
                   AND email_verified_at IS NOT NULL"
            );
            $update->execute([$passwordHash, (int)$record['admin_id']]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Password could not be updated. Please contact the Super Admin.');
            }

            // Delete every reset/verification token for this account.
            $delete = $pdo->prepare(
                "DELETE FROM admin_tokens WHERE admin_id = ?"
            );
            $delete->execute([(int)$record['admin_id']]);

            $pdo->commit();

            $success = 'Your password has been reset successfully. You can now log in with your new password.';
            $tokenValid = false;
            unset($_SESSION['reset_csrf']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Password reset error: ' . $e->getMessage());
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Something went wrong. Please request a new reset link.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | Leather Store BD</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:radial-gradient(ellipse at 50% 40%,#30204d,#100d20 50%,#05040c);color:white;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:18px}
.card{width:100%;max-width:430px;padding:34px;border:1px solid #7954a766;border-radius:18px;background:rgba(13,11,25,.9);box-shadow:0 0 45px #7644c633}
.brand{text-align:center;margin-bottom:25px}
.brand h1{font-size:23px;letter-spacing:2px;color:#e6d9ff}
.brand p{font-size:12px;letter-spacing:2px;color:#aaa1bd;margin-top:8px}
label{display:block;margin:15px 0 8px;color:#d0c5e3;font-size:13px}
.password-box{position:relative}
input{width:100%;padding:14px;border-radius:8px;border:1px solid #39314f;background:#ffffff0b;color:white;outline:none;font-size:16px}
input:focus{border-color:#a77aff;box-shadow:0 0 13px #7041bb55}
.password-box input{padding-right:55px}
.toggle{position:absolute;right:7px;top:6px;width:42px;height:39px;border:0;background:transparent;color:#d7b9ff;cursor:pointer}
.submit{width:100%;margin-top:23px;padding:14px;border:0;border-radius:8px;background:linear-gradient(100deg,#6639bb,#176fa7);color:white;font-weight:bold;cursor:pointer}
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
        <p>CREATE A NEW PASSWORD</p>
    </div>

    <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($tokenValid): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['reset_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token"
                   value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">New Password</label>
            <div class="password-box">
                <input type="password" id="password" name="password"
                       minlength="12" placeholder="Minimum 12 characters"
                       required autocomplete="new-password">
                <button type="button" class="toggle"
                        onclick="togglePassword('password',this)">👁</button>
            </div>

            <label for="confirm_password">Confirm New Password</label>
            <div class="password-box">
                <input type="password" id="confirm_password"
                       name="confirm_password" minlength="12"
                       placeholder="Confirm new password"
                       required autocomplete="new-password">
                <button type="button" class="toggle"
                        onclick="togglePassword('confirm_password',this)">👁</button>
            </div>

            <button class="submit" type="submit">RESET PASSWORD</button>
        </form>
    <?php else: ?>
        <div class="message error">
            This reset link is invalid, expired or already used. Please request a new reset link.
        </div>
        <div class="footer">
            <a href="forgot-password.php">Request a new reset link</a>
        </div>
    <?php endif; ?>

    <div class="footer">
        <a href="login.php">← Back to Admin Login</a>
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
