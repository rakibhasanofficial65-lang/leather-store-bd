<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/auth.php';
$stmt = $pdo->prepare("SELECT id, name, role, status FROM admins WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['status'] !== 'active') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function uploadProductImage($file, $directory) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Each image must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime]) || getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Only JPG, PNG and WebP images are allowed.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return 'assets/images/products/' . $filename;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$productId) {
    header("Location: products.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedFiles = [];

    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Security verification failed. Refresh the page.');
        }

        $name = trim($_POST['product_name'] ?? '');
        $slug = strtolower(trim($_POST['slug'] ?? ''));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
        $saleInput = trim($_POST['sale_price'] ?? '');
        $salePrice = $saleInput === '' ? null : filter_var($saleInput, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);

        $sizes = trim($_POST['sizes'] ?? '');
        $colors = trim($_POST['colors'] ?? '');
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $metaKeywords = trim($_POST['meta_keywords'] ?? '');
        $status = $_POST['status'] ?? '';

        if ($name === '' || $slug === '' || $category === '' || $description === '') {
            throw new RuntimeException('Please complete all required fields.');
        }

        if ($price === false || $price <= 0) {
            throw new RuntimeException('Enter a valid regular price.');
        }

        if ($salePrice === false || ($salePrice !== null && ($salePrice < 0 || $salePrice > $price))) {
            throw new RuntimeException('Sale price must not exceed regular price.');
        }

        if ($stock === false || $stock < 0) {
            throw new RuntimeException('Enter a valid stock quantity.');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Invalid product status.');
        }

        $check = $pdo->prepare("SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1");
        $check->execute([$slug, $productId]);

        if ($check->fetch()) {
            throw new RuntimeException('This product slug is already being used.');
        }

        $uploadDirectory = __DIR__ . '/../assets/images/products';

        if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
            throw new RuntimeException('Product image folder is missing or not writable.');
        }

        $mainImage = $product['image'];

        if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $mainImage = uploadProductImage($_FILES['main_image'], $uploadDirectory);
            $savedFiles[] = $mainImage;
        }

        $galleryFiles = $_FILES['gallery_images'] ?? null;
        $galleryCount = 0;

        if ($galleryFiles && isset($galleryFiles['name'])) {
            foreach ($galleryFiles['name'] as $i => $filename) {
                if ($galleryFiles['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($galleryFiles['error'][$i] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('A gallery image upload failed.');
                }
                $galleryCount++;
            }
        }

        if ($galleryCount > 10) {
            throw new RuntimeException('You can add a maximum of 10 gallery images at once.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE products SET
                product_name = ?,
                slug = ?,
                category = ?,
                description = ?,
                price = ?,
                sale_price = ?,
                stock = ?,
                image = ?,
                size_options = ?,
                color_options = ?,
                meta_title = ?,
                meta_description = ?,
                meta_keywords = ?,
                status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $name,
            $slug,
            $category,
            $description,
            $price,
            $salePrice,
            $stock,
            $mainImage,
            $sizes,
            $colors,
            $metaTitle ?: $name,
            $metaDescription,
            $metaKeywords,
            $status,
            $productId
        ]);

        // Delete selected gallery images
        $removeImages = $_POST['remove_images'] ?? [];

        if (!is_array($removeImages)) {
            $removeImages = [];
        }

        $removeImages = array_values(array_filter(
            array_map('intval', $removeImages),
            fn($id) => $id > 0
        ));

        $deletedPaths = [];

        if ($removeImages) {
            $placeholders = implode(',', array_fill(0, count($removeImages), '?'));
            $params = array_merge([$productId], $removeImages);

            $stmt = $pdo->prepare("
                SELECT id, image_path FROM product_images
                WHERE product_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute($params);
            $deletedPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                DELETE FROM product_images
                WHERE product_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute($params);
        }

        // Add new gallery images
        if ($galleryCount > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO product_images (product_id, image_path, sort_order)
                VALUES (?, ?, ?)
            ");

            $nextSort = (int)$pdo->query("
                SELECT COALESCE(MAX(sort_order), 0) + 1
                FROM product_images
                WHERE product_id = " . (int)$productId
            )->fetchColumn();

            foreach ($galleryFiles['name'] as $i => $filename) {
                if ($galleryFiles['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

                $file = [
                    'name' => $galleryFiles['name'][$i],
                    'type' => $galleryFiles['type'][$i],
                    'tmp_name' => $galleryFiles['tmp_name'][$i],
                    'error' => $galleryFiles['error'][$i],
                    'size' => $galleryFiles['size'][$i]
                ];

                $path = uploadProductImage($file, $uploadDirectory);
                $savedFiles[] = $path;

                $stmt->execute([$productId, $path, $nextSort++]);
            }
        }

        $pdo->commit();

        // Remove old files only after successful database commit
        if ($mainImage !== $product['image'] && $product['image']) {
            $oldPath = __DIR__ . '/../' . $product['image'];
            if (is_file($oldPath)) unlink($oldPath);
        }

        foreach ($deletedPaths as $item) {
            $oldPath = __DIR__ . '/../' . $item['image_path'];
            if (is_file($oldPath)) unlink($oldPath);
        }

        header("Location: products.php?updated=1");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($savedFiles as $path) {
            $fullPath = __DIR__ . '/../' . $path;
            if (is_file($fullPath)) unlink($fullPath);
        }

        error_log($e->getMessage());

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Could not update product. Please try again.';
    }
}

// Reload product data
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT id, image_path
    FROM product_images
    WHERE product_id = ?
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute([$productId]);
$gallery = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Product | Leather Store BD</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#eee9e2;color:#30251c}
.layout{display:flex;min-height:100vh}
.sidebar{
 width:270px;min-width:270px;min-height:100vh;padding:30px 20px;flex-shrink:0;
 background:radial-gradient(ellipse at 10% 5%,rgba(177,111,49,.25),transparent 38%),linear-gradient(145deg,#211812,#130f0d 55%,#090807);
 border-right:1px solid rgba(210,155,91,.35)
}
.logo{font:800 25px Georgia,serif;color:#fff5e9;margin-bottom:9px}
.logo-sub{color:#e0b477;font-size:11px;font-weight:800;letter-spacing:2px;margin-bottom:38px}
.menu-title{color:#e2ae68;font-size:11px;font-weight:800;letter-spacing:1.8px;margin:27px 10px 13px}
.sidebar a{display:block;text-decoration:none;color:#f5eee6;font-size:15px;font-weight:700;padding:14px;border:1px solid transparent;border-radius:9px;margin-bottom:7px}
.sidebar a:hover{background:rgba(191,127,62,.2);border-color:rgba(218,164,99,.4)}
.sidebar a.active{color:#21150b;background:linear-gradient(110deg,#ffda9b,#d49349);border-color:#ffe2ad}
.main{flex:1;min-width:0;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}
h1{font:800 28px Georgia,serif;color:#382719}
.subtitle{font-size:13px;color:#796b5d;margin-top:7px}
.admin-info{background:white;padding:12px 17px;border:1px solid #e1d3c2;border-radius:10px;font-size:13px;font-weight:700;color:#704a32}
.panel{max-width:1050px;background:white;border:1px solid #e6dacc;border-radius:13px;padding:25px}
.section-title{font:800 19px Georgia,serif;color:#704a32;margin:25px 0 15px;padding-bottom:10px;border-bottom:1px solid #eee5da}
.section-title:first-child{margin-top:0}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}
.field{display:flex;flex-direction:column;gap:8px}
.full{grid-column:1/-1}
label{font-size:13px;font-weight:800;color:#574332}
input,select,textarea{width:100%;padding:12px 13px;border:1px solid #ddcfc0;border-radius:8px;background:#fffdfa;color:#35291f;font:14px Arial}
textarea{min-height:115px;resize:vertical}
.hint{font-size:11px;color:#958575}
.alert{padding:13px 15px;border-radius:8px;margin-bottom:18px;background:#ffe4e1;color:#a33b32;font-size:13px;font-weight:700}
.btn{border:0;border-radius:8px;padding:13px 20px;background:linear-gradient(110deg,#93623e,#68442c);color:white;font-size:14px;font-weight:800;cursor:pointer}
.cancel{padding:13px 18px;border-radius:8px;background:#eee5da;color:#60432e;text-decoration:none;font-weight:700;font-size:14px}
.actions{display:flex;gap:12px;margin-top:25px;flex-wrap:wrap}
.preview{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px}
.image-card{border:1px solid #e5d8c9;border-radius:9px;padding:8px;width:120px}
.image-card img{width:100%;height:95px;object-fit:cover;border-radius:6px}
.image-card label{display:flex;align-items:center;gap:5px;margin-top:8px;font-size:11px}
.image-card input{width:auto}
@media(max-width:900px){.form-grid{grid-template-columns:1fr}}
@media(max-width:700px){
 .layout{display:block}.sidebar{width:100%;min-width:0;min-height:auto;padding:22px 15px}
 .logo{font-size:23px}.logo-sub{margin-bottom:18px}
 .menu-title{margin:18px 8px 10px}
 .sidebar a{display:inline-block;font-size:13px;padding:11px;margin:3px}
 .main{padding:18px 13px}.topbar{align-items:flex-start;flex-direction:column}
 .panel{padding:17px}
}
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
            <h1>Edit Product</h1>
            <p class="subtitle">Update product information, images and SEO details.</p>
        </div>
        <div class="admin-info"><?= h($admin['name']) ?> · <?= h($admin['role']) ?></div>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="panel" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <h2 class="section-title">Product Information</h2>

        <div class="form-grid">
            <div class="field">
                <label>Product Name *</label>
                <input type="text" name="product_name" required value="<?= h($product['product_name']) ?>">
            </div>

            <div class="field">
                <label>Product Slug *</label>
                <input type="text" name="slug" required value="<?= h($product['slug']) ?>">
            </div>

            <div class="field">
                <label>Category *</label>
                <input type="text" name="category" required value="<?= h($product['category']) ?>">
            </div>

            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= $product['status']==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $product['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </div>

            <div class="field full">
                <label>Description *</label>
                <textarea name="description" required><?= h($product['description']) ?></textarea>
            </div>
        </div>

        <h2 class="section-title">Price & Inventory</h2>

        <div class="form-grid">
            <div class="field">
                <label>Regular Price (৳) *</label>
                <input type="number" name="price" min="0.01" step="0.01" required value="<?= h($product['price']) ?>">
            </div>

            <div class="field">
                <label>Sale Price (৳)</label>
                <input type="number" name="sale_price" min="0" step="0.01" value="<?= h($product['sale_price']) ?>">
            </div>

            <div class="field">
                <label>Stock *</label>
                <input type="number" name="stock" min="0" required value="<?= h($product['stock']) ?>">
            </div>

            <div class="field">
                <label>Available Sizes</label>
                <input type="text" name="sizes" value="<?= h($product['size_options']) ?>" placeholder="39, 40, 41, 42">
            </div>

            <div class="field full">
                <label>Available Colors</label>
                <input type="text" name="colors" value="<?= h($product['color_options']) ?>" placeholder="Black, Brown, Chocolate">
            </div>
        </div>

        <h2 class="section-title">Main Product Image</h2>

        <div class="form-grid">
            <div class="field full">
                <label>Current Main Image</label>
                <div class="preview">
                    <?php if ($product['image']): ?>
                        <div class="image-card">
                            <img src="../<?= h(ltrim($product['image'], '/\\')) ?>" alt="Current main image">
                        </div>
                    <?php else: ?>
                        <p>No main image available.</p>
                    <?php endif; ?>
                </div>

                <label>Upload Replacement Image (Optional)</label>
                <input type="file" name="main_image" accept="image/jpeg,image/png,image/webp">
                <span class="hint">JPG, PNG or WebP. Maximum 5 MB. Leave empty to keep current image.</span>
            </div>
        </div>

        <h2 class="section-title">Gallery Images</h2>

        <div class="field full">
            <label>Existing Gallery Images</label>

            <div class="preview">
                <?php if ($gallery): ?>
                    <?php foreach ($gallery as $image): ?>
                        <div class="image-card">
                            <img src="../<?= h(ltrim($image['image_path'], '/\\')) ?>" alt="Gallery image">
                            <label>
                                <input type="checkbox" name="remove_images[]" value="<?= (int)$image['id'] ?>">
                                Remove image
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="hint">No gallery images added yet.</p>
                <?php endif; ?>
            </div>

            <label style="margin-top:15px">Add New Gallery Images</label>
            <input type="file" name="gallery_images[]" id="gallery_images" accept="image/jpeg,image/png,image/webp" multiple>
            <span class="hint">You can add up to 10 new images per update. Each image maximum 5 MB.</span>
            <div class="preview" id="newGalleryPreview"></div>
        </div>

        <h2 class="section-title">SEO Information</h2>

        <div class="form-grid">
            <div class="field full">
                <label>Meta Title</label>
                <input type="text" name="meta_title" maxlength="255" value="<?= h($product['meta_title']) ?>">
            </div>

            <div class="field full">
                <label>Meta Description</label>
                <textarea name="meta_description" maxlength="1000"><?= h($product['meta_description']) ?></textarea>
            </div>

            <div class="field full">
                <label>SEO Keywords</label>
                <input type="text" name="meta_keywords" value="<?= h($product['meta_keywords']) ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn">Update Product</button>
            <a href="products.php" class="cancel">Cancel</a>
        </div>

    </form>
</main>
</div>

<script>
document.getElementById('gallery_images').addEventListener('change', function () {
    const preview = document.getElementById('newGalleryPreview');
    preview.innerHTML = '';

    if (this.files.length > 10) {
        alert('You can add a maximum of 10 images at once.');
        this.value = '';
        return;
    }

    Array.from(this.files).forEach(file => {
        if (!file.type.startsWith('image/')) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.alt = 'New gallery image';
            img.style.width = '100px';
            img.style.height = '90px';
            img.style.objectFit = 'cover';
            img.style.borderRadius = '8px';
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
});
</script>

</body>
</html>
