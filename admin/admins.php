<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function adminH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
require_once __DIR__ . '/auth.php';
/* Always verify the current account from the database */
$stmt = $pdo->prepare("
    SELECT id, name, email, role, status, email_verified_at
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([(int)$_SESSION['admin_id']]);
$currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$currentAdmin ||
    $currentAdmin['role'] !== 'super_admin' ||
    $currentAdmin['status'] !== 'active' ||
    empty($currentAdmin['email_verified_at'])
) {
    http_response_code(403);
    exit('Access denied. Active verified Super Admin permission required.');
}

if (empty($_SESSION['admins_csrf'])) {
    $_SESSION['admins_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

/* Check login history table */
$historyAvailable = false;

try {
    $check = $pdo->query("SHOW TABLES LIKE 'admin_login_history'");
    $historyAvailable = (bool)$check->fetchColumn();
} catch (Throwable $e) {
    error_log('Admin history table check: ' . $e->getMessage());
}

/* Handle actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !$token ||
        !hash_equals($_SESSION['admins_csrf'], $token)
    ) {
        $error = 'Security verification failed. Refresh and try again.';
    } else {
        $targetId = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? '';

        try {
            if (!$targetId || $targetId === (int)$currentAdmin['id']) {
                throw new RuntimeException('Invalid target admin.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT id, role, status
                FROM admins
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('Admin account not found.');
            }

            if ($target['role'] === 'super_admin') {
                throw new RuntimeException('Super Admin accounts are protected.');
            }

            if ($action === 'toggle') {
                $newStatus = $target['status'] === 'active'
                    ? 'inactive'
                    : 'active';

                $update = $pdo->prepare("
                    UPDATE admins SET status = ? WHERE id = ?
                ");
                $update->execute([$newStatus, $targetId]);

                $message = $newStatus === 'active'
                    ? 'Admin account activated.'
                    : 'Admin account deactivated. Access will be denied on pages that verify the account status.';
            } elseif ($action === 'delete') {
                /*
                 * Delete the account permanently.
                 * Keep login history for audit purposes.
                 * Existing sessions must be checked against admins table
                 * by every protected admin page.
                 */
                $delete = $pdo->prepare("
                    DELETE FROM admins
                    WHERE id = ? AND role != 'super_admin'
                ");
                $delete->execute([$targetId]);

                if ($delete->rowCount() !== 1) {
                    throw new RuntimeException('Admin could not be deleted.');
                }

                $message = 'Admin account permanently deleted. Its old account ID can no longer authenticate.';
            } else {
                throw new RuntimeException('Invalid action.');
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Admin management error: ' . $e->getMessage());

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Something went wrong. Please try again.';
        }
    }
}

/* Load admin accounts and latest recorded device/IP */
if ($historyAvailable) {
    $admins = $pdo->query("
        SELECT
            a.id,
            a.name,
            a.email,
            a.role,
            a.status,
            a.email_verified_at,
            a.created_at,
            h.ip_address AS last_ip,
            h.device_type AS last_device,
            h.browser AS last_browser,
            h.operating_system AS last_os,
            h.city AS last_city,
            h.region AS last_region,
            h.country AS last_country,
            h.login_time AS last_login
        FROM admins a
        LEFT JOIN admin_login_history h
            ON h.id = (
                SELECT MAX(h2.id)
                FROM admin_login_history h2
                WHERE h2.admin_id = a.id
            )
        ORDER BY a.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $admins = $pdo->query("
        SELECT id, name, email, role, status, email_verified_at, created_at
        FROM admins
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$totalAdmins = count($admins);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Management | Leather Store BD</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f2ed;color:#34271f;font-family:Arial,sans-serif}
header{background:#38281f;color:white;padding:18px 5%;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
header a{color:#f1d4ad;text-decoration:none}
.header-links{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.back{background:#fff;color:#38281f;padding:9px 13px;border-radius:7px;font-weight:bold}
main{max-width:1250px;margin:30px auto;padding:0 16px}
.card{background:white;padding:22px;border-radius:12px;margin-bottom:20px;box-shadow:0 3px 15px #0000000b}
.table-wrap{overflow-x:auto}
table{width:100%;min-width:1050px;border-collapse:collapse}
th,td{text-align:left;padding:13px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top}
th{background:#faf7f3}
button{border:0;padding:9px 13px;border-radius:6px;cursor:pointer;background:#79543b;color:white}
.delete{background:#a43b32}
.alert{padding:13px;border-radius:7px;margin-bottom:15px}
.success{background:#e2f5e6;color:#236b32}
.error{background:#ffe5e3;color:#9b2923}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#eee;font-size:11px}
.active{background:#e0f3df;color:#27712c}
.inactive{background:#ffe5e3;color:#a52e28}
.protected{color:#27712c;font-weight:bold}
.device-info{line-height:1.7;min-width:145px}
.device-info strong{color:#4d3829}
form{display:inline}
.actions{display:flex;gap:7px;flex-wrap:wrap}
.small{font-size:12px;color:#817367;line-height:1.6}
@media(max-width:600px){
 main{margin:18px auto}
 .card{padding:14px}
 header{padding:15px}
}
</style>
</head>
<body>

<header>
    <strong>Leather Store BD | Admin Management</strong>
    <div class="header-links">
        <?= adminH($currentAdmin['name']) ?>
        <a class="back" href="dashboard.php">← Back to Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>
</header>

<main>
    <h1>Admin Management</h1>
    <p class="small">Manage admin accounts, access status and recent login devices.</p>

    <?php if ($message): ?>
        <div class="alert success"><?= adminH($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= adminH($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h3>Total Admin Accounts: <?= $totalAdmins ?> / 5</h3>
        <p class="small">
            Super Admin accounts are protected. Only active, verified Super Admins
            can access this page.
        </p>
        <?php if (!$historyAvailable): ?>
            <p class="small" style="color:#a43b32">
                Login history table is not available. Device and IP information
                will appear after the login history table and login logging are installed.
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name / Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Email Verification</th>
                    <th>Last Login Device / IP</th>
                    <th>Last Login</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= (int)$admin['id'] ?></td>

                    <td>
                        <strong><?= adminH($admin['name']) ?></strong><br>
                        <?= adminH($admin['email']) ?>
                    </td>

                    <td><?= adminH($admin['role']) ?></td>

                    <td>
                        <span class="badge <?= adminH($admin['status']) ?>">
                            <?= adminH($admin['status']) ?>
                        </span>
                    </td>

                    <td>
                        <?= !empty($admin['email_verified_at']) ? 'Verified' : 'Not verified' ?>
                    </td>

                    <td>
                        <?php if (!empty($admin['last_login'])): ?>
                            <div class="device-info">
                                <strong>Device:</strong> <?= adminH($admin['last_device'] ?? 'Unknown') ?><br>
                                <strong>Browser:</strong> <?= adminH($admin['last_browser'] ?? 'Unknown') ?><br>
                                <strong>OS:</strong> <?= adminH($admin['last_os'] ?? 'Unknown') ?><br>
                                <strong>IP:</strong> <?= adminH($admin['last_ip'] ?? 'Unknown') ?><br>
                                <strong>Location:</strong>
                                <?= adminH($admin['last_city'] ?? '') ?>
                                <?= adminH($admin['last_region'] ?? '') ?>
                                <?= adminH($admin['last_country'] ?? '') ?>
                            </div>
                        <?php else: ?>
                            <span class="small">No login recorded yet.</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= adminH($admin['last_login'] ?? 'Never') ?>
                    </td>

                    <td>
                    <?php if ($admin['role'] !== 'super_admin'): ?>
                        <div class="actions">
                            <form method="POST">
                                <input type="hidden" name="csrf_token"
                                    value="<?= adminH($_SESSION['admins_csrf']) ?>">
                                <input type="hidden" name="admin_id"
                                    value="<?= (int)$admin['id'] ?>">
                                <input type="hidden" name="action" value="toggle">

                                <button type="submit">
                                    <?= $admin['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <form method="POST"
                                onsubmit="return confirm('Permanently delete this Admin account? This cannot be undone.')">
                                <input type="hidden" name="csrf_token"
                                    value="<?= adminH($_SESSION['admins_csrf']) ?>">
                                <input type="hidden" name="admin_id"
                                    value="<?= (int)$admin['id'] ?>">
                                <input type="hidden" name="action" value="delete">

                                <button class="delete" type="submit">Delete</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="protected">Protected</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$admins): ?>
                <tr><td colspan="8">No admin accounts found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
</main>
</body>
</html>
