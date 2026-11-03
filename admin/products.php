<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, role, status FROM admins WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['status'] !== 'active') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? '';

        if ($productId && $action === 'toggle') {
            $stmt = $pdo->prepare("
                UPDATE products
                SET status = CASE
                    WHEN status = 'active' THEN 'inactive'
                    ELSE 'active'
                END
                WHERE id = ?
            ");
            $stmt->execute([$productId]);
            header("Location: products.php?updated=1");
            exit;
        }

        if ($productId && $action === 'delete') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("DELETE FROM product_images WHERE product_id = ?");
                $stmt->execute([$productId]);

                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$productId]);

                $pdo->commit();

                header("Location: products.php?deleted=1");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Product could not be deleted. It may be linked to an order.';
            }
        }
    }
}

if (isset($_GET['updated'])) $message = 'Product status updated successfully.';
if (isset($_GET['deleted'])) $message = 'Product deleted successfully.';

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(product_name LIKE ? OR slug LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category !== '') {
    $where[] = "category = ?";
    $params[] = $category;
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = "status = ?";
    $params[] = $status;
}

$sql = "SELECT id, product_name, category, price, sale_price, stock, image, status
        FROM products";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT DISTINCT category FROM products
    WHERE category IS NOT NULL AND category <> ''
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$activeProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$inactiveProducts = $totalProducts - $activeProducts;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products Management | Leather Store BD</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#eee9e2;color:#30251c}
.layout{display:flex;min-height:100vh}

/* Sidebar */
.sidebar{
 width:270px;min-width:270px;min-height:100vh;padding:30px 20px;
 flex-shrink:0;
 background:radial-gradient(ellipse at 10% 5%,rgba(177,111,49,.25),transparent 38%),
 linear-gradient(145deg,#211812,#130f0d 55%,#090807);
 border-right:1px solid rgba(210,155,91,.35);
 box-shadow:8px 0 30px rgba(0,0,0,.22)
}
.logo{font:800 25px Georgia,serif;color:#fff5e9;margin-bottom:9px}
.logo-sub{color:#e0b477;font-size:11px;font-weight:800;letter-spacing:2px;margin-bottom:38px}
.menu-title{color:#e2ae68;font-size:11px;font-weight:800;letter-spacing:1.8px;margin:27px 10px 13px}
.sidebar a{display:block;text-decoration:none;color:#f5eee6;font-size:15px;font-weight:700;padding:14px;border:1px solid transparent;border-radius:9px;margin-bottom:7px;transition:.2s}
.sidebar a:hover{background:rgba(191,127,62,.2);border-color:rgba(218,164,99,.4);color:white}
.sidebar a.active{color:#21150b;background:linear-gradient(110deg,#ffda9b,#d49349);border-color:#ffe2ad}

/* Main */
.main{flex:1;min-width:0;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}
h1{font:800 28px Georgia,serif;color:#382719}
.subtitle{color:#796b5d;font-size:13px;margin-top:7px}
.admin-info{background:white;padding:12px 17px;border:1px solid #e1d3c2;border-radius:10px;font-size:13px;font-weight:700;color:#704a32}

/* Statistics */
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:22px}
.stat{background:white;padding:20px;border:1px solid #e6dacc;border-radius:12px}
.stat span{font-size:13px;color:#796b5d;font-weight:700}
.stat h2{font-size:27px;color:#805333;margin-top:10px}

/* Content panel */
.panel{background:white;border:1px solid #e6dacc;border-radius:12px;padding:22px;box-shadow:0 5px 18px rgba(65,44,25,.05)}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px}
.panel-head h2{font:800 21px Georgia,serif;color:#493321}
.btn{display:inline-block;border:0;border-radius:7px;padding:11px 15px;background:#805333;color:white;text-decoration:none;font-weight:700;font-size:13px;cursor:pointer}
.btn:hover{background:#5b3923}
.btn-light{background:#eee5da;color:#60432e}
.btn-danger{background:#a83d32}
.btn-small{padding:7px 10px;font-size:12px}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.filters input,.filters select{padding:11px 12px;border:1px solid #ddd0c1;border-radius:7px;background:#fff;color:#433629;font-size:13px;min-width:145px}
.filters input{flex:1;min-width:190px}

/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:780px}
th,td{text-align:left;padding:13px 11px;border-bottom:1px solid #eee8e1;font-size:13px}
th{background:#f8f5f0;color:#796958;font-weight:800}
td{color:#42352a;font-weight:600}
.product-info{display:flex;align-items:center;gap:11px;min-width:180px}
.product-info img{width:55px;height:55px;object-fit:cover;border-radius:7px;background:#eee}
.product-info strong{display:block;font-size:13px}
.product-info small{display:block;color:#958574;margin-top:4px}
.status{display:inline-block;padding:6px 10px;border-radius:20px;font-size:11px;font-weight:800}
.active-status{background:#e2f5e8;color:#287441}
.inactive-status{background:#ffe9df;color:#a34b29}
.actions{display:flex;gap:6px;align-items:center}
.actions form{display:inline}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:700}
.success{background:#e2f5e8;color:#287441}
.error{background:#ffe4e1;color:#a33b32}
.empty{text-align:center;padding:35px;color:#928273}

@media(max-width:1000px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){
 .layout{display:block}
 .sidebar{width:100%;min-width:0;min-height:auto;padding:22px 15px}
 .logo{font-size:23px}
 .logo-sub{margin-bottom:18px}
 .menu-title{margin:18px 8px 10px}
 .sidebar a{display:inline-block;font-size:13px;padding:11px;margin:3px}
 .main{padding:18px 13px}
 .topbar{align-items:flex-start;flex-direction:column}
 .panel{padding:15px}
 .panel-head{align-items:flex-start;flex-direction:column}
}
@media(max-width:380px){.stats{grid-template-columns:1fr}}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="logo">Leather Store BD</div>
    <div class="logo-sub">ADMINISTRATION PANEL</div>

    <div class="menu-title">Main Menu</div>
    <a href="dashboard.php">Dashboard</a>
    <a href="products.php" class="active">Products</a>
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
            <h1>Products Management</h1>
            <p class="subtitle">Manage your store products, stock and visibility.</p>
        </div>
        <div class="admin-info"><?= h($admin['name']) ?> · <?= h($admin['role']) ?></div>
    </div>

    <?php if ($message): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

    <section class="stats">
        <div class="stat"><span>Total Products</span><h2><?= number_format($totalProducts) ?></h2></div>
        <div class="stat"><span>Active Products</span><h2><?= number_format($activeProducts) ?></h2></div>
        <div class="stat"><span>Inactive Products</span><h2><?= number_format($inactiveProducts) ?></h2></div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>All Store Products</h2>
            <a href="add-product.php" class="btn">+ Add New Product</a>
        </div>

        <form method="GET" class="filters">
            <input type="search" name="search" placeholder="Search product name or slug..." value="<?= h($search) ?>">

            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                        <?= h($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All Status</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button class="btn" type="submit">Search</button>
            <a href="products.php" class="btn btn-light">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Sale Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="7" class="empty">No products found.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <div class="product-info">
                                <?php
                                $image = trim((string)$product['image']);
                                $imageUrl = $image !== ''
                                    ? '../' . ltrim($image, '/\\')
                                    : '';
                                ?>
                                <?php if ($imageUrl): ?>
                                    <img src="<?= h($imageUrl) ?>" alt="<?= h($product['product_name']) ?>">
                                <?php else: ?>
                                    <img src="https://placehold.co/100x100?text=No+Image" alt="No image">
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($product['product_name']) ?></strong>
                                    <small>#<?= (int)$product['id'] ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?= h($product['category']) ?></td>
                        <td>৳<?= number_format((float)$product['price'], 2) ?></td>
                        <td><?= $product['sale_price'] !== null && $product['sale_price'] !== '' ? '৳'.number_format((float)$product['sale_price'], 2) : '—' ?></td>
                        <td><?= number_format((int)$product['stock']) ?></td>
                        <td>
                            <span class="status <?= $product['status'] === 'active' ? 'active-status' : 'inactive-status' ?>">
                                <?= h($product['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn btn-small btn-light" href="edit-product.php?id=<?= (int)$product['id'] ?>">Edit</a>

                                <form method="POST" onsubmit="return confirm('Are you sure you want to change this product status?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <button class="btn btn-small" type="submit">
                                        <?= $product['status'] === 'active' ? 'Disable' : 'Activate' ?>
                                    </button>
                                </form>

                                <form method="POST" onsubmit="return confirm('Permanently delete this product? This cannot be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn btn-small btn-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
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
