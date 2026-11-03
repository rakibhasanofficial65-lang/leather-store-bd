<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
$message = '';
$success = false;

$token = $_GET['token'] ?? '';

if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $message = 'Invalid verification link.';
} else {

    $tokenHash = hash('sha256', $token);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT id, admin_id
             FROM admin_tokens
             WHERE token_hash = ?
             AND purpose = 'email_verify'
             AND used_at IS NULL
             AND expires_at > NOW()
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([$tokenHash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new Exception('This verification link is invalid or expired.');
        }

        $updateAdmin = $pdo->prepare(
            "UPDATE admins
             SET email_verified_at = NOW()
             WHERE id = ? AND email_verified_at IS NULL"
        );

        $updateAdmin->execute([$record['admin_id']]);

        if ($updateAdmin->rowCount() !== 1) {
            throw new Exception('This account is already verified or unavailable.');
        }

        $updateToken = $pdo->prepare(
            "UPDATE admin_tokens SET used_at = NOW() WHERE id = ?"
        );

        $updateToken->execute([$record['id']]);

        $pdo->commit();

        $success = true;
        $message = 'Your email has been verified successfully!';

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification | Leather Store BD</title>

<style>
body {
    background: #f3eee7;
    font-family: Arial, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    margin: 0;
    padding: 20px;
}

.box {
    background: white;
    padding: 35px;
    border-radius: 12px;
    text-align: center;
    max-width: 450px;
    width: 100%;
}

h2 { color: #65452f; }
.success { color: #16803c; }
.error { color: #b42318; }
</style>
</head>

<body>
<div class="box">

<h2>Leather Store BD</h2>

<p class="<?= $success ? 'success' : 'error' ?>">
<?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
</p>

<?php if ($success): ?>
<p>You can now proceed to the admin login page.</p>
<?php endif; ?>

</div>
</body>
</html>
