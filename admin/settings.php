<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function settingValue(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM store_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

/* Verify current admin from database */
$stmt = $pdo->prepare("
    SELECT id, name, username, email, password, role, status
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || strtolower((string)$admin['status']) !== 'active') {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$isSuperAdmin = strtolower((string)$admin['role']) === 'super_admin';

/* Create settings table if it does not exist */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS store_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Default settings */
$defaults = [
    'store_name' => 'Leather Store BD',
    'store_email' => 'leatherstorebd@gmail.com',
    'store_phone' => '',
    'store_address' => '',
    'delivery_charge' => '80',
    'free_delivery_amount' => '0',
    'payment_methods' => 'cod,bkash,nagad,rocket,bank',
    'maintenance_mode' => '0',
    'session_timeout' => '30',
    'login_alerts' => '1'
];

foreach ($defaults as $key => $value) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM store_settings WHERE setting_key = ?");
    $check->execute([$key]);

    if ((int)$check->fetchColumn() === 0) {
        saveSetting($pdo, $key, $value);
    }
}

$allowedSections = ['store', 'delivery', 'payment', 'account'];

if ($isSuperAdmin) {
    $allowedSections[] = 'password';
    $allowedSections[] = 'security';
}

$section = $_GET['section'] ?? 'store';

if (!in_array($section, $allowedSections, true)) {
    http_response_code(403);
    exit('Access denied. Super Admin permission required.');
}

$message = '';
$error = '';

/* Handle form submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['settings_csrf']) ||
        !hash_equals($_SESSION['settings_csrf'], $_POST['csrf_token'])
    ) {
        $error = 'Security verification failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_store' && $section === 'store') {
                $fields = [
                    'store_name',
                    'store_email',
                    'store_phone',
                    'store_address'
                ];

                foreach ($fields as $field) {
                    saveSetting($pdo, $field, trim($_POST[$field] ?? ''));
                }

                $message = 'Store settings updated successfully.';
            }

            elseif ($action === 'save_delivery' && $section === 'delivery') {
                $charge = filter_var($_POST['delivery_charge'] ?? null, FILTER_VALIDATE_FLOAT);
                $freeAmount = filter_var($_POST['free_delivery_amount'] ?? null, FILTER_VALIDATE_FLOAT);

                if ($charge === false || $charge < 0 || $freeAmount === false || $freeAmount < 0) {
                    throw new RuntimeException('Please enter valid delivery amounts.');
                }

                saveSetting($pdo, 'delivery_charge', (string)$charge);
                saveSetting($pdo, 'free_delivery_amount', (string)$freeAmount);

                $message = 'Delivery settings updated successfully.';
            }

            elseif ($action === 'save_payment' && $section === 'payment') {
                $allowedPayments = ['cod', 'bkash', 'nagad', 'rocket', 'bank'];
                $selected = $_POST['payment_methods'] ?? [];

                if (!is_array($selected)) {
                    $selected = [];
                }

                $selected = array_values(array_intersect($selected, $allowedPayments));

                if (!$selected) {
                    throw new RuntimeException('Select at least one payment method.');
                }

                saveSetting($pdo, 'payment_methods', implode(',', $selected));
                $message = 'Payment settings updated successfully.';
            }

            elseif ($action === 'save_account' && $section === 'account') {
                $name = trim($_POST['name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $currentPassword = $_POST['current_password'] ?? '';

                if ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid name, username and email.');
                }

                if (!password_verify($currentPassword, $admin['password'])) {
                    throw new RuntimeException('Current password is incorrect.');
                }

                $duplicate = $pdo->prepare("
                    SELECT id FROM admins
                    WHERE (username = ? OR email = ?) AND id != ?
                    LIMIT 1
                ");
                $duplicate->execute([$username, $email, $admin['id']]);

                if ($duplicate->fetch()) {
                    throw new RuntimeException('Username or email is already in use.');
                }

                $update = $pdo->prepare("
                    UPDATE admins
                    SET name = ?, username = ?, email = ?
                    WHERE id = ?
                ");
                $update->execute([$name, $username, $email, $admin['id']]);

                $_SESSION['admin_name'] = $name;
                $_SESSION['admin_username'] = $username;

                $message = 'Account information updated successfully.';
                $admin['name'] = $name;
                $admin['username'] = $username;
                $admin['email'] = $email;
            }

            elseif ($action === 'change_password' && $section === 'password') {
                if (!$isSuperAdmin) {
                    http_response_code(403);
                    exit('Access denied. Super Admin only.');
                }

                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (!password_verify($currentPassword, $admin['password'])) {
                    throw new RuntimeException('Current password is incorrect.');
                }

                if (strlen($newPassword) < 10) {
                    throw new RuntimeException('New password must be at least 10 characters.');
                }

                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('New password and confirmation do not match.');
                }

                if (password_verify($newPassword, $admin['password'])) {
                    throw new RuntimeException('New password must be different from the current password.');
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare("
                    UPDATE admins SET password = ? WHERE id = ?
                ");
                $update->execute([$newHash, $admin['id']]);

                $admin['password'] = $newHash;
                $message = 'Super Admin password changed successfully.';
            }

            elseif ($action === 'save_security' && $section === 'security') {
                if (!$isSuperAdmin) {
                    http_response_code(403);
                    exit('Access denied. Super Admin only.');
                }

                $timeout = (int)($_POST['session_timeout'] ?? 30);
                $alerts = isset($_POST['login_alerts']) ? '1' : '0';

                if ($timeout < 5 || $timeout > 1440) {
                    throw new RuntimeException('Session timeout must be between 5 and 1440 minutes.');
                }

                saveSetting($pdo, 'session_timeout', (string)$timeout);
                saveSetting($pdo, 'login_alerts', $alerts);

                $message = 'Security preferences saved. These options require authentication integration before they take effect.';
            }

            else {
                throw new RuntimeException('Invalid action or section.');
            }

        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['settings_csrf'];

/* Read settings */
$settings = [];
foreach ($defaults as $key => $default) {
    $settings[$key] = settingValue($pdo, $key, $default);
}

/* Login history: update activity for this page visit */
$historyAvailable = false;
$historyRows = [];
$historyError = '';

if ($isSuperAdmin && $section === 'security') {
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_login_history'");
        $historyAvailable = (bool)$tableCheck->fetchColumn();

        if ($historyAvailable) {
            $historyId = $_SESSION['admin_login_history_id'] ?? null;

            if ($historyId) {
                $activity = $pdo->prepare("
                    UPDATE admin_login_history
                    SET last_activity = NOW()
                    WHERE id = ? AND admin_id = ?
                ");
                $activity->execute([$historyId, $admin['id']]);
            }

            $historyQuery = $pdo->query("
                SELECT
                    h.*,
                    a.name AS admin_name,
                    a.email AS admin_email
                FROM admin_login_history h
                LEFT JOIN admins a ON a.id = h.admin_id
                ORDER BY h.login_time DESC
                LIMIT 100
            ");

            $historyRows = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $ex) {
        $historyError = 'Could not load login history. Please check the database table and column names.';
        error_log('Login history error: ' . $ex->getMessage());
    }
}

$paymentSelected = explode(',', $settings['payment_methods']);

$menu = [
    'store' => 'Store Information',
    'delivery' => 'Delivery Settings',
    'payment' => 'Payment Methods',
    'account' => 'My Account'
];

if ($isSuperAdmin) {
    $menu['password'] = 'Change Password';
    $menu['security'] = 'Security & Login History';
}

$sectionTitles = [
    'store' => 'Store Information',
    'delivery' => 'Delivery Settings',
    'payment' => 'Payment Methods',
    'account' => 'My Account',
    'password' => 'Super Admin Password',
    'security' => 'Security & Login History'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings - Leather Store BD</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f1eb;color:#30271f;font-family:Arial,sans-serif}
a{text-decoration:none;color:inherit}
.settings-wrap{max-width:1250px;margin:35px auto;padding:0 18px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.topbar h1{margin:0;font-size:27px}
.back{background:#46352a;color:white;padding:11px 17px;border-radius:8px}
.layout{display:grid;grid-template-columns:250px 1fr;gap:22px}
.sidebar,.panel{background:#fff;border:1px solid #e9dfd4;border-radius:14px;padding:20px}
.sidebar h3{margin:0 0 15px}
.menu a{display:block;padding:12px 13px;border-radius:8px;margin:5px 0;color:#59483b}
.menu a.active,.menu a:hover{background:#eee4d8;color:#6d4327;font-weight:bold}
.panel h2{margin-top:0}
.field{margin:17px 0}
.field label{display:block;font-weight:bold;margin-bottom:7px}
.field input,.field textarea,.field select{width:100%;padding:12px;border:1px solid #d8cbbd;border-radius:8px;font-size:15px}
.field textarea{min-height:90px;resize:vertical}
.btn{background:#704b32;color:#fff;border:0;border-radius:8px;padding:12px 20px;cursor:pointer;font-weight:bold}
.notice{padding:12px 15px;border-radius:8px;margin-bottom:16px;background:#e5f5e8;color:#205c2c}
.error{padding:12px 15px;border-radius:8px;margin-bottom:16px;background:#fde8e5;color:#922e25}
.payment-options{display:flex;flex-wrap:wrap;gap:14px}
.payment-options label{font-weight:normal}
.small-note{color:#76695e;font-size:13px;line-height:1.6}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:850px;font-size:13px}
th,td{padding:11px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
th{background:#f5efe8}
.current{color:#16803d;font-weight:bold}
.badge{display:inline-block;padding:4px 8px;border-radius:20px;background:#e8f5e9;color:#246b37}
@media(max-width:750px){
 .layout{grid-template-columns:1fr}
 .menu{display:flex;overflow-x:auto;gap:5px}
 .menu a{white-space:nowrap}
 .topbar h1{font-size:21px}
}
</style>
</head>
<body>
<div class="settings-wrap">

    <div class="topbar">
        <h1>⚙ Settings</h1>
        <a class="back" href="dashboard.php">← Dashboard</a>
    </div>

    <div class="layout">
        <aside class="sidebar">
            <h3>Settings Menu</h3>
            <nav class="menu">
                <?php foreach ($menu as $key => $label): ?>
                    <a class="<?= $section === $key ? 'active' : '' ?>"
                       href="settings.php?section=<?= e($key) ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <p class="small-note">
                Logged in as: <strong><?= e($admin['name']) ?></strong><br>
                Role: <?= e($admin['role']) ?>
            </p>
        </aside>

        <main class="panel">
            <h2><?= e($sectionTitles[$section]) ?></h2>

            <?php if ($message): ?>
                <div class="notice"><?= e($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($section === 'store'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_store">

                    <div class="field">
                        <label>Store Name</label>
                        <input name="store_name" value="<?= e($settings['store_name']) ?>" required>
                    </div>

                    <div class="field">
                        <label>Store Email</label>
                        <input type="email" name="store_email" value="<?= e($settings['store_email']) ?>">
                    </div>

                    <div class="field">
                        <label>Store Phone</label>
                        <input name="store_phone" value="<?= e($settings['store_phone']) ?>">
                    </div>

                    <div class="field">
                        <label>Store Address</label>
                        <textarea name="store_address"><?= e($settings['store_address']) ?></textarea>
                    </div>

                    <button class="btn">Save Store Settings</button>
                </form>

            <?php elseif ($section === 'delivery'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_delivery">

                    <div class="field">
                        <label>Delivery Charge (BDT)</label>
                        <input type="number" min="0" step="0.01" name="delivery_charge"
                               value="<?= e($settings['delivery_charge']) ?>" required>
                    </div>

                    <div class="field">
                        <label>Free Delivery Minimum Order (BDT)</label>
                        <input type="number" min="0" step="0.01" name="free_delivery_amount"
                               value="<?= e($settings['free_delivery_amount']) ?>" required>
                    </div>

                    <button class="btn">Save Delivery Settings</button>
                </form>

            <?php elseif ($section === 'payment'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_payment">

                    <p>Select payment methods available to customers:</p>

                    <div class="payment-options">
                        <?php
                        $paymentLabels = [
                            'cod' => 'Cash on Delivery',
                            'bkash' => 'bKash',
                            'nagad' => 'Nagad',
                            'rocket' => 'Rocket',
                            'bank' => 'Bank Transfer'
                        ];
                        foreach ($paymentLabels as $key => $label):
                        ?>
                            <label>
                                <input type="checkbox" name="payment_methods[]"
                                       value="<?= e($key) ?>"
                                    <?= in_array($key, $paymentSelected, true) ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="small-note">
                        This saves the selected methods in the database. Checkout must read
                        these settings to enforce the selection on the customer page.
                    </p>

                    <button class="btn">Save Payment Settings</button>
                </form>

            <?php elseif ($section === 'account'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_account">

                    <div class="field">
                        <label>Full Name</label>
                        <input name="name" value="<?= e($admin['name']) ?>" required>
                    </div>

                    <div class="field">
                        <label>Username</label>
                        <input name="username" value="<?= e($admin['username']) ?>" required>
                    </div>

                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= e($admin['email']) ?>" required>
                    </div>

                    <div class="field">
                        <label>Current Password (verification)</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <button class="btn">Update Account</button>
                </form>

            <?php elseif ($section === 'password' && $isSuperAdmin): ?>
                <div class="notice">
                    Only the Super Admin can access and change this password.
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="field">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="new_password" minlength="10" required>
                    </div>

                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" minlength="10" required>
                    </div>

                    <p class="small-note">
                        Minimum 10 characters. Use a unique password that is not used elsewhere.
                    </p>

                    <button class="btn">Change Super Admin Password</button>
                </form>

            <?php elseif ($section === 'security' && $isSuperAdmin): ?>
                <h3>Security Preferences</h3>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_security">

                    <div class="field">
                        <label>Session Timeout (minutes)</label>
                        <input type="number" min="5" max="1440" name="session_timeout"
                               value="<?= e($settings['session_timeout']) ?>">
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox" name="login_alerts" value="1"
                                <?= $settings['login_alerts'] === '1' ? 'checked' : '' ?>>
                            Login Alerts Preference
                        </label>
                    </div>

                    <p class="small-note">
                        These are saved preferences only. Session timeout enforcement and
                        email login alerts require additional integration in the shared
                        admin authentication code.
                    </p>

                    <button class="btn">Save Security Preferences</button>
                </form>

                <hr style="border:0;border-top:1px solid #eee;margin:28px 0">

                <h3>Admin Login History</h3>
                <p class="small-note">
                    Latest 100 recorded login sessions. Location is approximate and depends
                    on the IP geolocation service. Historical records start after login
                    logging is installed.
                </p>

                <?php if ($historyError): ?>
                    <div class="error"><?= e($historyError) ?></div>

                <?php elseif (!$historyAvailable): ?>
                    <div class="error">
                        Login history table not found. Create the
                        <strong>admin_login_history</strong> table first using the SQL
                        provided in the previous step.
                    </div>

                <?php elseif (!$historyRows): ?>
                    <p>No login records found yet. Log in again after installing the new login.php.</p>

                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Admin</th>
                                    <th>IP Address</th>
                                    <th>Device</th>
                                    <th>Browser / OS</th>
                                    <th>Approx. Location</th>
                                    <th>Login Time</th>
                                    <th>Last Activity</th>
                                    <th>Logout Time</th>
                                    <th>Session</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($historyRows as $row): ?>
                                <?php
                                $currentHash = hash('sha256', session_id());
                                $isCurrent = !empty($row['session_hash'])
                                    && hash_equals($currentHash, $row['session_hash']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($row['admin_name'] ?? 'Unknown') ?></strong><br>
                                        <?= e($row['admin_email'] ?? '') ?>
                                    </td>
                                    <td><?= e($row['ip_address'] ?? 'Unknown') ?></td>
                                    <td><?= e($row['device_type'] ?? 'Unknown') ?></td>
                                    <td>
                                        <?= e($row['browser'] ?? 'Unknown') ?><br>
                                        <?= e($row['operating_system'] ?? 'Unknown') ?>
                                    </td>
                                    <td>
                                        <?= e($row['city'] ?? '') ?>
                                        <?= e($row['region'] ?? '') ?>
                                        <?= e($row['country'] ?? 'Unknown') ?>
                                    </td>
                                    <td><?= e($row['login_time'] ?? '') ?></td>
                                    <td><?= e($row['last_activity'] ?? '') ?></td>
                                    <td><?= e($row['logout_time'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($isCurrent): ?>
                                            <span class="current">Current Session</span>
                                        <?php elseif (empty($row['logout_time'])): ?>
                                            <span class="badge">No logout recorded</span>
                                        <?php else: ?>
                                            Ended
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>
