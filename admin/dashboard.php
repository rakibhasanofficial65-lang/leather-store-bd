<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Admin login check
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// Verify active admin
$stmt = $pdo->prepare("
    SELECT id, name, username, role, status
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['status'] !== 'active') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['admin_name'] = $admin['name'];

require_once __DIR__ . '/auth.php';

// Dashboard statistics
$totalProducts = (int)$pdo->query("
    SELECT COUNT(*) FROM products
")->fetchColumn();

$activeProducts = (int)$pdo->query("
    SELECT COUNT(*) FROM products WHERE status = 'active'
")->fetchColumn();

$totalOrders = (int)$pdo->query("
    SELECT COUNT(*) FROM orders
")->fetchColumn();

$pendingOrders = (int)$pdo->query("
    SELECT COUNT(*) FROM orders WHERE status = 'pending'
")->fetchColumn();

$deliveredOrders = (int)$pdo->query("
    SELECT COUNT(*) FROM orders WHERE status = 'delivered'
")->fetchColumn();

$totalSales = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE status = 'delivered'
")->fetchColumn();

// Recent orders
$recentOrders = $pdo->query("
    SELECT order_number, customer_name, total_amount, status, created_at
    FROM orders
    ORDER BY created_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusClass($status) {
    $status = strtolower($status);

    if ($status === 'delivered') return 'delivered';
    if ($status === 'cancelled') return 'cancelled';
    if ($status === 'pending') return 'pending';
    if ($status === 'shipped') return 'shipped';

    return 'processing';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | Leather Store BD</title>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #eee9e2;
    color: #30251c;
}

/* MAIN LAYOUT */
.layout {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    min-height: 100vh;
    width: 100%;
}

/* PREMIUM DARK LEATHER SIDEBAR */
.sidebar {
    width: 270px;
    min-width: 270px;
    min-height: 100vh;
    flex-shrink: 0;
    padding: 30px 20px;

    background:
        radial-gradient(ellipse at 10% 5%, rgba(177,111,49,.25), transparent 38%),
        linear-gradient(145deg, #211812 0%, #130f0d 55%, #090807 100%);

    border-right: 1px solid rgba(210,155,91,.35);
    box-shadow: 8px 0 30px rgba(0,0,0,.22);
    color: #fff;
}

/* BRAND */
.logo {
    font-family: Georgia, serif;
    font-size: 25px;
    font-weight: 800;
    color: #fff5e9;
    letter-spacing: .3px;
    text-shadow: 0 0 12px rgba(225,165,94,.35);
    margin-bottom: 9px;
}

.logo-sub {
    color: #e0b477;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2.2px;
    margin-bottom: 38px;
}

/* SECTION TITLE */
.menu-title {
    color: #e2ae68;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.8px;
    margin: 27px 10px 13px;
    text-transform: uppercase;
}

/* MENU LINKS */
.sidebar a {
    display: block;
    text-decoration: none;
    color: #f5eee6;
    font-family: Arial, sans-serif;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .25px;
    padding: 14px;
    border: 1px solid transparent;
    border-radius: 9px;
    margin-bottom: 7px;
    transition: all .25s ease;
}

/* HOVER */
.sidebar a:hover {
    color: #fff;
    background: linear-gradient(
        100deg,
        rgba(191,127,62,.30),
        rgba(191,127,62,.07)
    );
    border-color: rgba(218,164,99,.4);
    box-shadow: 0 0 16px rgba(196,132,65,.18);
    transform: translateX(3px);
}

/* ACTIVE MENU */
.sidebar a.active {
    color: #21150b;
    font-weight: 800;
    background: linear-gradient(110deg, #ffda9b, #d49349);
    border: 1px solid #ffe2ad;
    box-shadow:
        0 0 8px rgba(255,183,91,.65),
        0 0 22px rgba(255,166,65,.30);
}

/* MAIN CONTENT */
.main {
    flex: 1;
    min-width: 0;
    padding: 30px;
    overflow-x: hidden;
}

/* TOP BAR */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
}

.topbar h1 {
    font-family: Georgia, serif;
    font-size: 28px;
    font-weight: 800;
    color: #382719;
}

.topbar p {
    color: #796b5d;
    font-size: 13px;
    margin-top: 6px;
}

.admin-info {
    background: #fff;
    padding: 12px 17px;
    border: 1px solid #e1d3c2;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    color: #704a32;
}

/* WELCOME BANNER */
.welcome {
    background:
        radial-gradient(circle at 90% 10%, rgba(255,218,155,.2), transparent 35%),
        linear-gradient(110deg, #513521, #94653f);
    color: white;
    padding: 30px;
    border-radius: 14px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(75,47,27,.16);
}

.welcome h2 {
    font-family: Georgia, serif;
    font-size: 26px;
    font-weight: 800;
    margin-bottom: 9px;
}

.welcome p {
    font-size: 13px;
    color: #f5e8d9;
}

/* STATISTICS */
.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin-bottom: 28px;
}

.stat-card {
    background: #fff;
    padding: 22px;
    border: 1px solid #e6dacc;
    border-radius: 12px;
    box-shadow: 0 5px 18px rgba(65,44,25,.05);
}

.stat-card span {
    color: #766656;
    font-size: 13px;
    font-weight: 700;
}

.stat-card h2 {
    font-size: 27px;
    font-weight: 800;
    margin-top: 13px;
    color: #805333;
}

.stat-card small {
    display: block;
    margin-top: 8px;
    color: #928273;
}

/* RECENT ORDERS */
.panel {
    background: #fff;
    border: 1px solid #e6dacc;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 5px 18px rgba(65,44,25,.05);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.panel-header h3 {
    font-family: Georgia, serif;
    font-size: 20px;
    font-weight: 800;
    color: #493321;
}

.panel-header a {
    color: #805333;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}

th, td {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 1px solid #eee8e1;
    font-size: 13px;
}

th {
    color: #796958;
    font-weight: 800;
    background: #f8f5f0;
}

td {
    color: #42352a;
    font-weight: 600;
}

/* ORDER STATUS */
.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: capitalize;
}

.pending { background: #fff1d8; color: #95600c; }
.processing { background: #e9e6ff; color: #5b4aa0; }
.shipped { background: #e1efff; color: #28639a; }
.delivered { background: #e2f5e8; color: #287441; }
.cancelled { background: #ffe4e1; color: #a33b32; }

.empty {
    text-align: center;
    padding: 30px;
    color: #928273;
}

/* TABLET */
@media (max-width: 1000px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .main {
        padding: 22px;
    }
}

/* MOBILE */
@media (max-width: 700px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        min-width: 0;
        min-height: auto;
        padding: 22px 15px;
    }

    .logo {
        font-size: 23px;
    }

    .logo-sub {
        margin-bottom: 18px;
    }

    .menu-title {
        margin: 18px 8px 10px;
    }

    .sidebar a {
        display: inline-block;
        font-size: 13px;
        padding: 11px 13px;
        margin: 3px;
    }

    .main {
        width: 100%;
        padding: 18px 13px;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .topbar h1 {
        font-size: 24px;
    }

    .welcome {
        padding: 22px;
    }

    .panel {
        padding: 15px;
    }
}

@media (max-width: 380px) {
    .stats {
        grid-template-columns: 1fr;
    }
}
</style>

</style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <div class="logo">Leather Store BD</div>
        <div class="logo-sub">ADMINISTRATION PANEL</div>

        <div class="menu-title">Main Menu</div>

        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="products.php">Products</a>
        <a href="add-product.php">Add Product</a>
        <a href="orders.php">All Orders</a>

        <div class="menu-title">Management</div>

        <a href="invite-admin.php">Admin Invitations</a>
        <a href="admins.php">Admin Management</a>
        <a href="settings.php">Settings</a>

        <div class="menu-title">Account</div>

        <a href="../index.php" target="_blank">View Store</a>
        <a href="logout.php">Logout</a>

    </aside>

    <main class="main">

        <div class="topbar">
            <div>
                <h1>Dashboard Overview</h1>
                <p>Welcome back to Leather Store BD administration.</p>
            </div>

            <div class="admin-info">
                <?= h($admin['name']) ?> · <?= h($admin['role']) ?>
            </div>
        </div>

        <section class="welcome">
            <h2>Welcome, <?= h($admin['name']) ?>!</h2>
            <p>Manage your products, orders and store performance from one place.</p>
        </section>

        <section class="stats">

            <div class="stat-card">
                <span>Total Sales</span>
                <h2>৳<?= number_format($totalSales, 2) ?></h2>
                <small>Delivered orders only</small>
            </div>

            <div class="stat-card">
                <span>Total Orders</span>
                <h2><?= number_format($totalOrders) ?></h2>
                <small>All customer orders</small>
            </div>

            <div class="stat-card">
                <span>Total Products</span>
                <h2><?= number_format($totalProducts) ?></h2>
                <small><?= number_format($activeProducts) ?> active products</small>
            </div>

            <div class="stat-card">
                <span>Pending Orders</span>
                <h2><?= number_format($pendingOrders) ?></h2>
                <small><?= number_format($deliveredOrders) ?> delivered orders</small>
            </div>

        </section>

        <section class="panel">

            <div class="panel-header">
                <h3>Recent Orders</h3>
                <a href="orders.php">View All Orders →</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($recentOrders)): ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No orders found yet.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentOrders as $order): ?>

                            <tr>
                                <td><?= h($order['order_number']) ?></td>
                                <td><?= h($order['customer_name']) ?></td>
                                <td>৳<?= number_format((float)$order['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge <?= h(statusClass($order['status'])) ?>">
                                        <?= h($order['status']) ?>
                                    </span>
                                </td>
                                <td><?= h(date('d M Y', strtotime($order['created_at']))) ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </section>

    </main>

</div>

</body>
</html>
