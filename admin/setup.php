
<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/auth.php';
if (isset($_SESSION['admin_setup_success'])) {
    unset($_SESSION['admin_setup_success']);
}

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

if ($adminCount > 0) {
    exit('Setup is closed. An admin account already exists.');
}

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['setup_csrf'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } else {

        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (
            !$name || !$username || !$email ||
            !$password || !$confirm
        ) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 12) {
            $error = 'Password must be at least 12 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {

            $adminId = null;

            try {
                $pdo->beginTransaction();

                $check = $pdo->prepare(
                    "SELECT COUNT(*) FROM admins
                     WHERE username = ? OR email = ?"
                );
                $check->execute([$username, $email]);

                if ((int) $check->fetchColumn() > 0) {
                    throw new Exception('Username or email already exists.');
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO admins
                    (name, username, phone, email, password,
                     role, status, email_verified_at)
                    VALUES (?, ?, ?, ?, ?, 'super_admin', 'active', NULL)"
                );

                $stmt->execute([
                    $name,
                    $username,
                    $phone ?: null,
                    $email,
                    $hash
                ]);

                $adminId = (int) $pdo->lastInsertId();

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $tokenStmt = $pdo->prepare(
                    "INSERT INTO admin_tokens
                    (admin_id, token_hash, purpose, expires_at)
                    VALUES (?, ?, 'email_verify', DATE_ADD(NOW(), INTERVAL 1 HOUR))"
                );

                $tokenStmt->execute([$adminId, $tokenHash]);

                $pdo->commit();

                $verifyUrl =
                    'http://localhost/leather-store-bd/admin/verify-email.php?token='
                    . urlencode($token);

                $html = '
                    <h2>Welcome to Leather Store BD</h2>
                    <p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                    <p>Your Super Admin account has been created.</p>
                    <p>Please verify your email address using the link below:</p>
                    <p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">
                    Verify Email Address</a></p>
                    <p>This link expires in 1 hour.</p>
                ';

                if (sendStoreEmail(
                    $email,
                    $name,
                    'Verify Your Admin Email - Leather Store BD',
                    $html
                )) {
                    $message = 'Account created! Please check your email and verify your account.';
                } else {
                    $pdo->prepare("DELETE FROM admin_tokens WHERE admin_id = ?")
                        ->execute([$adminId]);

                    $pdo->prepare("DELETE FROM admins WHERE id = ?")
                        ->execute([$adminId]);

                    $error = 'Verification email could not be sent. Please check SMTP settings and try again.';
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Admin Setup Error: ' . $e->getMessage());

                if (!$error) {
                    $error = 'Setup failed. Please check the information and try again.';
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
<title>Admin Setup | Leather Store BD</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: #f3eee7;
    font-family: Arial, sans-serif;
}

.setup-box {
    width: 100%;
    max-width: 460px;
    padding: 35px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 10px 35px rgba(0,0,0,.08);
}

h2 { color: #65452f; margin-top: 0; }

input {
    width: 100%;
    padding: 13px;
    margin: 7px 0 15px;
    border: 1px solid #ddd;
    border-radius: 7px;
}

button {
    width: 100%;
    padding: 14px;
    border: 0;
    border-radius: 7px;
    background: #765137;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

.error { color: #b42318; }
.success { color: #16803c; }
small { color: #777; }


.password-field {
    position: relative;
    width: 100%;
}

.password-field input {
    width: 100%;
    padding-right: 48px;
}

.toggle-password {
    position: absolute;
    right: 8px;
    top: 7px;
    width: 38px;
    height: 38px;
    padding: 0;
    background: transparent;
    color: #765137;
    font-size: 19px;
    border: none;
    cursor: pointer;
}

</style>
</head>

<body>
<div class="setup-box">

<h2>Leather Store BD</h2>
<p>Create Your First Super Admin Account</p>

<?php if ($message): ?>
<p class="success"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<?php if ($error): ?>
<p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (!$message): ?>
<form method="POST">

<input type="hidden" name="csrf_token"
value="<?= htmlspecialchars($_SESSION['setup_csrf']) ?>">

<label>Full Name</label>
<input type="text" name="name" required>

<label>Username</label>
<input type="text" name="username" required>

<label>Phone</label>
<input type="text" name="phone">

<label>Email Address</label>
<input type="email" name="email" required>


<label>Password</label>
<div class="password-field">
    <input type="password" id="password"
           name="password" minlength="12" required>
    <button type="button" class="toggle-password"
            onclick="togglePassword('password', this)">👁</button>
</div>

<label>Confirm Password</label>
<div class="password-field">
    <input type="password" id="confirm_password"
           name="confirm_password" minlength="12" required>
    <button type="button" class="toggle-password"
            onclick="togglePassword('confirm_password', this)">👁</button>
</div>


<small>Use at least 12 characters for your password.</small>
<br><br>

<button type="submit">Create Super Admin</button>

</form>
<?php endif; ?>

</div>

<script>
function togglePassword(id, button) {
    const input = document.getElementById(id);

    if (input.type === "password") {
        input.type = "text";
        button.textContent = "🙈";
    } else {
        input.type = "password";
        button.textContent = "👁";
    }
}
</script>
</body>
</html>


</body>
</html>
