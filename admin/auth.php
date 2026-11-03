<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function adminLogoutAndRedirect(): never
{
    global $pdo;

    if (!empty($_SESSION['admin_login_history_id'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE admin_login_history
                SET logout_time = NOW()
                WHERE id = ? AND logout_time IS NULL
            ");
            $stmt->execute([
                $_SESSION['admin_login_history_id']
            ]);
        } catch (Throwable $e) {
            error_log('Admin logout history error: ' . $e->getMessage());
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: login.php');
    exit;
}

// Login session check
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name, username, role, status,
               email_verified_at, session_version
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$admin ||
        $admin['status'] !== 'active' ||
        empty($admin['email_verified_at']) ||
        !isset($_SESSION['admin_session_version']) ||
        (int) $_SESSION['admin_session_version'] !==
        (int) $admin['session_version']
    ) {
        adminLogoutAndRedirect();
    }

    // Refresh current account information from database
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['admin_session_version'] = (int) $admin['session_version'];
    // Update login activity
    if (!empty($_SESSION['admin_login_history_id'])) {
        $activity = $pdo->prepare("
            UPDATE admin_login_history
            SET last_activity = NOW()
            WHERE id = ? AND admin_id = ? AND logout_time IS NULL
        ");

        $activity->execute([
            $_SESSION['admin_login_history_id'],
            $admin['id']
        ]);
    }

} catch (Throwable $e) {
    error_log('Admin authentication error: ' . $e->getMessage());
    adminLogoutAndRedirect();
}
