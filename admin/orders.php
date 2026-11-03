
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Admin authentication
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

if (!$admin || strtolower((string)$admin['status']) !== 'active') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/auth.php';

$allowedStatuses = [
    'Pending',
    'Confirmed',
    'Processing',
    'Shipped',
    'Delivered',
    'Cancelled'
];

$message = '';
$error = '';

// CSRF token
if (empty($_SESSION['orders_csrf'])) {
    $_SESSION['orders_csrf'] = bin2hex(random_bytes(32));
}

// Success messages
if (isset($_GET['updated'])) {
    $message = 'Order status updated successfully.';
}

if (isset($_GET['deleted'])) {
    $message = 'Order deleted permanently.';
}


// ========================================
// UPDATE ORDER STATUS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $token = $_POST['csrf_token'] ?? '';
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $newStatus = trim($_POST['status'] ?? '');

    if (
        !$token ||
        !hash_equals($_SESSION['orders_csrf'], $token)
    ) {
        $error = 'Security verification failed. Please try again.';

    } elseif (
        !$orderId ||
        !in_array($newStatus, $allowedStatuses, true)
    ) {
        $error = 'Invalid order or status.';

    } else {
        try {
            $check = $pdo->prepare("
                SELECT id FROM orders WHERE id = ?
            ");
            $check->execute([$orderId]);

            if (!$check->fetch()) {
                $error = 'Order not found.';

            } else {
                $update = $pdo->prepare("
                    UPDATE orders
                    SET status = ?
                    WHERE id = ?
                ");

                $update->execute([$newStatus, $orderId]);

                // Automatically open the updated status list
                header("Location: orders.php?updated=1");
                exit;
            }

        } catch (PDOException $e) {
            error_log('Order status update error: ' . $e->getMessage());
            $error = 'Could not update order status.';
        }
    }
}


// ========================================
// PERMANENTLY DELETE ORDER
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {

    $token = $_POST['csrf_token'] ?? '';
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if (
        !$token ||
        !hash_equals($_SESSION['orders_csrf'], $token)
    ) {
        $error = 'Security verification failed. Please try again.';

    } elseif (!$orderId) {
        $error = 'Invalid order ID.';

    } else {
        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare("
                SELECT id FROM orders WHERE id = ?
            ");
            $check->execute([$orderId]);

            if (!$check->fetch()) {
                $pdo->rollBack();
                $error = 'Order not found.';

            } else {

                // Delete related order items first
                $deleteItems = $pdo->prepare("
                    DELETE FROM order_items
                    WHERE order_id = ?
                ");
                $deleteItems->execute([$orderId]);

                // Delete main order
                $deleteOrder = $pdo->prepare("
                    DELETE FROM orders
                    WHERE id = ?
                ");
                $deleteOrder->execute([$orderId]);

                $pdo->commit();

                header("Location: orders.php?deleted=1");
                exit;
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Order delete error: ' . $e->getMessage());
            $error = 'Could not delete order.';
        }
    }
}


// ========================================
// SEARCH AND FILTER
// ========================================

$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));

$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "(
        order_number LIKE ?
        OR order_id LIKE ?
        OR customer_name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
    )";

    $term = '%' . $search . '%';

    array_push(
        $params,
        $term,
        $term,
        $term,
        $term,
        $term
    );
}

if (in_array($filterStatus, $allowedStatuses, true)) {
    $where[] = "status = ?";
    $params[] = $filterStatus;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';


// Total orders
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders
    $whereSql
");

$countStmt->execute($params);

$totalOrders = (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil($totalOrders / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}


// Fetch orders
$sql = "
    SELECT
        id,
        order_id,
        order_number,
        customer_name,
        phone,
        email,
        payment_method,
        subtotal,
        delivery_charge,
        total_amount,
        status,
        created_at
    FROM orders
    $whereSql
    ORDER BY id DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ========================================
// HELPER FUNCTIONS
// ========================================

function orderH($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function statusClass($status) {
    return strtolower(
        preg_replace(
            '/[^a-zA-Z0-9]+/',
            '-',
            (string)$status
        )
    );
}

function moneyBD($amount) {
    return '৳' . number_format((float)$amount, 2);
}

function pageUrl($page, $search, $status) {
    return '?' . http_build_query([
        'page' => $page,
        'search' => $search,
        'status' => $status
    ]);
}


// ========================================
// ORDER DETAILS
// ========================================

$selectedOrder = null;
$orderItems = [];

$detailsId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);

if ($detailsId) {

    $detailStmt = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE id = ?
    ");

    $detailStmt->execute([$detailsId]);

    $selectedOrder = $detailStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedOrder) {

        $itemsStmt = $pdo->prepare("
            SELECT *
            FROM order_items
            WHERE order_id = ?
            ORDER BY id ASC
        ");

        $itemsStmt->execute([$detailsId]);

        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>All Orders | Leather Store BD Admin</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f2ed;
    color: #34271f;
    font-family: Arial, sans-serif;
}

.admin-header {
    background: #38281f;
    color: #fff;
    padding: 20px 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.admin-header h2 {
    margin: 0;
    font-size: 21px;
}

.admin-header a {
    color: #f1d4ad;
    text-decoration: none;
}

.container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 18px;
}

.heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.heading h1 {
    margin: 0;
    font-size: 27px;
}

.card {
    background: #fff;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
    margin-bottom: 22px;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

input,
select {
    padding: 11px 13px;
    border: 1px solid #ddd2c7;
    border-radius: 7px;
    font-size: 14px;
}

input {
    flex: 1;
    min-width: 180px;
}

button,
.btn {
    border: 0;
    background: #79543b;
    color: white;
    padding: 11px 16px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 13px;
}

button:hover,
.btn:hover {
    background: #513824;
}

.btn-light {
    background: #eee5dc;
    color: #49382c;
}

.btn-delete {
    background: #b83d35;
    color: #fff;
    padding: 11px 14px;
    border: none;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
}

.btn-delete:hover {
    background: #8f2923;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    padding: 14px 12px;
    text-align: left;
    border-bottom: 1px solid #eee7e0;
    font-size: 13px;
}

th {
    background: #faf7f3;
    color: #74563e;
}

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    background: #eee;
}

.pending {
    background: #fff0c9;
    color: #906000;
}

.confirmed {
    background: #dcecff;
    color: #205a91;
}

.processing {
    background: #e9e0ff;
    color: #6543a2;
}

.shipped {
    background: #d9f2ed;
    color: #187568;
}

.delivered {
    background: #dff3df;
    color: #27712c;
}

.cancelled {
    background: #ffe0df;
    color: #a52e28;
}

.status-form {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.status-form select {
    padding: 7px;
    max-width: 160px;
}

.status-form button {
    padding: 8px 12px;
}

.alert {
    padding: 13px;
    border-radius: 7px;
    margin-bottom: 15px;
}

.success {
    background: #e2f5e6;
    color: #236b32;
}

.danger {
    background: #ffe5e3;
    color: #9b2923;
}

.pagination {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 20px;
    align-items: center;
}

.pagination a {
    padding: 8px 12px;
    background: #eee5dc;
    color: #49382c;
    text-decoration: none;
    border-radius: 6px;
}

.pagination a.active {
    background: #79543b;
    color: white;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(200px, 1fr)
    );
    gap: 15px;
}

.detail-box {
    background: #faf7f3;
    padding: 14px;
    border-radius: 8px;
}

.detail-box small {
    color: #8a7565;
    display: block;
    margin-bottom: 6px;
}

.detail-box strong {
    overflow-wrap: anywhere;
}

.back {
    margin-bottom: 15px;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #88796d;
}

.order-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}

.order-actions form {
    margin: 0;
}

@media(max-width:600px) {

    .container {
        margin: 18px auto;
    }

    .card {
        padding: 14px;
    }

    .heading h1 {
        font-size: 22px;
    }

    .filters > * {
        width: 100%;
    }

    .admin-header {
        padding: 16px 18px;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<header class="admin-header">

    <h2>Leather Store BD | Admin</h2>

    <div>
        <?= orderH($admin['name']) ?> |
        <a href="dashboard.php">Dashboard</a> |
        <a href="logout.php">Logout</a>
    </div>

</header>


<main class="container">

<?php if ($message): ?>

    <div class="alert success">
        <?= orderH($message) ?>
    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="alert danger">
        <?= orderH($error) ?>
    </div>

<?php endif; ?>


<?php if ($selectedOrder): ?>

    <!-- ===================================
         ORDER DETAILS PAGE
    ==================================== -->

    <div class="heading">

        <h1>Order Details</h1>

        <a class="btn btn-light" href="orders.php">
            ← Back to Orders
        </a>

    </div>


    <section class="card">

        <div class="detail-grid">

            <div class="detail-box">
                <small>Order Number</small>
                <strong>
                    <?= orderH(
                        $selectedOrder['order_number']
                        ?: $selectedOrder['order_id']
                    ) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Customer Name</small>
                <strong>
                    <?= orderH($selectedOrder['customer_name']) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Phone</small>
                <strong>
                    <?= orderH($selectedOrder['phone']) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Email</small>
                <strong>
                    <?= orderH($selectedOrder['email']) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Payment Method</small>
                <strong>
                    <?= orderH($selectedOrder['payment_method']) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Order Date</small>
                <strong>
                    <?= orderH($selectedOrder['created_at']) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Address</small>
                <strong>
                    <?= nl2br(orderH($selectedOrder['address'])) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Customer Note</small>
                <strong>
                    <?= nl2br(orderH($selectedOrder['note'])) ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Sender Number</small>
                <strong>
                    <?= orderH($selectedOrder['sender_number'] ?? 'N/A') ?>
                </strong>
            </div>

            <div class="detail-box">
                <small>Transaction ID</small>
                <strong>
                    <?= orderH($selectedOrder['transaction_id'] ?? 'N/A') ?>
                </strong>
            </div>

        </div>

    </section>


    <!-- ORDERED PRODUCTS -->

    <section class="card">

        <h3>Ordered Products</h3>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Size</th>
                        <th>Color</th>
                        <th>Total</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($orderItems as $item): ?>

                    <tr>

                        <td>
                            <?= orderH($item['product_name']) ?>
                        </td>

                        <td>
                            <?= moneyBD($item['price']) ?>
                        </td>

                        <td>
                            <?= (int)$item['quantity'] ?>
                        </td>

                        <td>
                            <?= orderH($item['size'] ?: 'N/A') ?>
                        </td>

                        <td>
                            <?= orderH($item['color'] ?: 'N/A') ?>
                        </td>

                        <td>
                            <?= moneyBD($item['line_total']) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$orderItems): ?>

                    <tr>
                        <td colspan="6">
                            No order items found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <hr style="border:0;border-top:1px solid #eee;margin:20px 0">

        <p>
            Subtotal:
            <strong>
                <?= moneyBD($selectedOrder['subtotal']) ?>
            </strong>
        </p>

        <p>
            Delivery:
            <strong>
                <?= moneyBD($selectedOrder['delivery_charge']) ?>
            </strong>
        </p>

        <h3>
            Grand Total:
            <?= moneyBD($selectedOrder['total_amount']) ?>
        </h3>

    </section>


    <!-- UPDATE STATUS -->

    <section class="card">

        <h3>Update Order Status</h3>

        <p>
            Current Status:
            <span class="status <?= orderH(statusClass($selectedOrder['status'])) ?>">
                <?= orderH($selectedOrder['status']) ?>
            </span>
        </p>

        <form method="POST" class="status-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= orderH($_SESSION['orders_csrf']) ?>"
            >

            <input
                type="hidden"
                name="order_id"
                value="<?= (int)$selectedOrder['id'] ?>"
            >

            <select name="status" required>

                <?php foreach ($allowedStatuses as $status): ?>

                    <option
                        value="<?= orderH($status) ?>"
                        <?= $selectedOrder['status'] === $status ? 'selected' : '' ?>
                    >
                        <?= orderH($status) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                type="submit"
                name="update_status"
            >
                Update Status
            </button>

        </form>

    </section>


<?php else: ?>


    <!-- ===================================
         ALL ORDERS PAGE
    ==================================== -->

    <div class="heading">

        <h1>All Orders Management</h1>

        <a class="btn btn-light" href="dashboard.php">
            ← Dashboard
        </a>

    </div>


    <!-- SEARCH AND FILTER -->

    <section class="card">

        <form method="GET" class="filters">

            <input
                type="text"
                name="search"
                placeholder="Search Order ID, Name, Phone or Email"
                value="<?= orderH($search) ?>"
            >

            <select name="status">

                <option value="">All Status</option>

                <?php foreach ($allowedStatuses as $status): ?>

                    <option
                        value="<?= orderH($status) ?>"
                        <?= $filterStatus === $status ? 'selected' : '' ?>
                    >
                        <?= orderH($status) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button type="submit">
                Search / Filter
            </button>

            <a class="btn btn-light" href="orders.php">
                Reset
            </a>

        </form>

    </section>


    <!-- ORDERS TABLE -->

    <section class="card">

        <h3>
            Total Orders: <?= $totalOrders ?>
        </h3>

        <?php if ($filterStatus !== ''): ?>

            <p>
                Showing:
                <strong><?= orderH($filterStatus) ?></strong>
                Orders
            </p>

        <?php endif; ?>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Date</th>
                        <th>Payment</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                </thead>


                <tbody>

                <?php foreach ($orders as $order): ?>

                    <tr>

                        <td>
                            <strong>
                                <?= orderH(
                                    $order['order_number']
                                    ?: $order['order_id']
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <?= orderH($order['customer_name']) ?>
                        </td>

                        <td>
                            <?= orderH($order['phone']) ?>
                        </td>

                        <td>
                            <?= orderH($order['created_at']) ?>
                        </td>

                        <td>
                            <?= orderH($order['payment_method']) ?>
                        </td>

                        <td>
                            <strong>
                                <?= moneyBD($order['total_amount']) ?>
                            </strong>
                        </td>

                        <td>

                            <span class="status <?= orderH(statusClass($order['status'])) ?>">
                                <?= orderH($order['status']) ?>
                            </span>

                        </td>

                        <td>

                            <div class="order-actions">

                                <a
                                    class="btn"
                                    href="?view=<?= (int)$order['id'] ?>"
                                >
                                    View
                                </a>


                                <form
                                    method="POST"
                                    onsubmit="return confirm('WARNING: This will permanently delete this order and all its order items. Are you sure?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= orderH($_SESSION['orders_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int)$order['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="delete_order"
                                        class="btn-delete"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$orders): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No orders found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="<?= orderH(pageUrl($page - 1, $search, $filterStatus)) ?>">
                        Previous
                    </a>

                <?php endif; ?>


                <?php for ($p = 1; $p <= $totalPages; $p++): ?>

                    <a
                        class="<?= $p === $page ? 'active' : '' ?>"
                        href="<?= orderH(pageUrl($p, $search, $filterStatus)) ?>"
                    >
                        <?= $p ?>
                    </a>

                <?php endfor; ?>


                <?php if ($page < $totalPages): ?>

                    <a href="<?= orderH(pageUrl($page + 1, $search, $filterStatus)) ?>">
                        Next
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

<?php endif; ?>

</main>

</body>
</html>
