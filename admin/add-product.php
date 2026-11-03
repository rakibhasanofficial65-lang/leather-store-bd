<?php
session_start();
require_once __DIR__ . '/../config/database.php';

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

function makeSlug($value) {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-') ?: 'product-' . bin2hex(random_bytes(4));
}

function uploadProductImage($file, $directory) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('An image could not be uploaded.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Each image must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime]) || getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Only JPG, PNG and WebP images are allowed.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return 'assets/images/products/' . $filename;
}

$error = '';
$success = '';

$old = [
    'product_name' => '',
    'category' => '',
    'description' => '',
    'price' => '',
    'sale_price' => '',
    'stock' => '0',
    'sizes' => '',
    'colors' => '',
    'meta_title' => '',
    'meta_description' => '',
    'meta_keywords' => '',
    'status' => 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $value) {
        $old[$key] = trim((string)($_POST[$key] ?? $value));
    }

    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Security verification failed. Refresh the page.');
        }

        $name = $old['product_name'];
        $category = $old['category'];
        $description = $old['description'];
        $price = filter_var($old['price'], FILTER_VALIDATE_FLOAT);
        $salePrice = $old['sale_price'] === '' ? null : filter_var($old['sale_price'], FILTER_VALIDATE_FLOAT);
        $stock = filter_var($old['stock'], FILTER_VALIDATE_INT);

        if ($name === '' || $category === '' || $description === '') {
            throw new RuntimeException('Please fill in Product Name, Category and Description.');
        }

        if ($price === false || $price <= 0) {
            throw new RuntimeException('Enter a valid product price.');
        }

        if ($salePrice === false || ($salePrice !== null && ($salePrice < 0 || $salePrice > $price))) {
            throw new RuntimeException('Sale price must be valid and cannot exceed regular price.');
        }

        if ($stock === false || $stock < 0) {
            throw new RuntimeException('Enter a valid stock quantity.');
        }

        if (!in_array($old['status'], ['active', 'inactive'], true)) {
            throw new RuntimeException('Invalid product status.');
        }

        $slug = makeSlug($_POST['slug'] ?? $name);

        $check = $pdo->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);

        if ($check->fetch()) {
            throw new RuntimeException('This product slug already exists. Please use a different slug.');
        }

        $uploadDirectory = __DIR__ . '/../assets/images/products';

        if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
            throw new RuntimeException('Product image folder is missing or not writable.');
        }

        if (!isset($_FILES['main_image']) || $_FILES['main_image']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a main product image.');
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
            throw new RuntimeException('You can upload a maximum of 10 gallery images.');
        }

        $savedFiles = [];

        try {
            $mainImage = uploadProductImage($_FILES['main_image'], $uploadDirectory);
            $savedFiles[] = $mainImage;

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO products
                (product_name, slug, category, description, price, sale_price,
                 stock, image, size_options, color_options, meta_title,
                 meta_description, meta_keywords, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $old['sizes'],
                $old['colors'],
                $old['meta_title'] ?: $name,
                $old['meta_description'],
                $old['meta_keywords'],
                $old['status']
            ]);

            $productId = (int)$pdo->lastInsertId();

            if ($galleryCount > 0) {
                $galleryInsert = $pdo->prepare("
                    INSERT INTO product_images (product_id, image_path, sort_order)
                    VALUES (?, ?, ?)
                ");

                $sort = 1;

                foreach ($galleryFiles['name'] as $i => $filename) {
                    if ($galleryFiles['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

                    $file = [
                        'name' => $galleryFiles['name'][$i],
                        'type' => $galleryFiles['type'][$i],
                        'tmp_name' => $galleryFiles['tmp_name'][$i],
                        'error' => $galleryFiles['error'][$i],
                        'size' => $galleryFiles['size'][$i]
                    ];

                    $galleryImage = uploadProductImage($file, $uploadDirectory);
                    $savedFiles[] = $galleryImage;

                    $galleryInsert->execute([$productId, $galleryImage, $sort]);
                    $sort++;
                }
            }

            $pdo->commit();

            header("Location: products.php?added=1");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($savedFiles as $path) {
                $fullPath = __DIR__ . '/../' . $path;
                if (is_file($fullPath)) unlink($fullPath);
            }

            throw $e;
        }

    } catch (Throwable $e) {
        error_log($e->getMessage());
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Could not add product. Please check the database and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Product | Leather Store BD</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#eee9e2;color:#30251c}
.layout{display:flex;min-height:100vh}
.sidebar{
 width:270px;min-width:270px;min-height:100vh;padding:30px 20px;flex-shrink:0;
 background:radial-gradient(ellipse at 10% 5%,rgba(177,111,49,.25),transparent 38%),linear-gradient(145deg,#211812,#130f0d 55%,#090807);
 border-right:1px solid rgba(210,155,91,.35);box-shadow:8px 0 30px rgba(0,0,0,.22)
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
.panel{max-width:1050px;background:white;border:1px solid #e6dacc;border-radius:13px;padding:25px;box-shadow:0 5px 18px rgba(65,44,25,.05)}
.section-title{font:800 19px Georgia,serif;color:#704a32;margin:25px 0 15px;padding-bottom:10px;border-bottom:1px solid #eee5da}
.section-title:first-child{margin-top:0}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}
.field{display:flex;flex-direction:column;gap:8px}
.field.full{grid-column:1/-1}
label{font-size:13px;font-weight:800;color:#574332}
input,select,textarea{width:100%;padding:12px 13px;border:1px solid #ddcfc0;border-radius:8px;background:#fffdfa;color:#35291f;font:14px Arial;outline:none}
input:focus,select:focus,textarea:focus{border-color:#a87548;box-shadow:0 0 0 3px rgba(168,117,72,.12)}
textarea{min-height:115px;resize:vertical}
.hint{font-size:11px;color:#958575;line-height:1.5}
.alert{padding:13px 15px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:700}
.error{background:#ffe4e1;color:#a33b32}
.btn{border:0;border-radius:8px;padding:13px 20px;background:linear-gradient(110deg,#93623e,#68442c);color:white;font-size:14px;font-weight:800;cursor:pointer}
.btn:hover{filter:brightness(1.12)}
.actions{display:flex;gap:12px;margin-top:25px;flex-wrap:wrap}
.cancel{padding:13px 18px;border-radius:8px;background:#eee5da;color:#60432e;text-decoration:none;font-weight:700;font-size:14px}
.image-preview{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
.image-preview img{width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #ddd0c1}
@media(max-width:900px){.form-grid{grid-template-columns:1fr}}
@media(max-width:700px){
 .layout{display:block}.sidebar{width:100%;min-width:0;min-height:auto;padding:22px 15px}
 .logo{font-size:23px}.logo-sub{margin-bottom:18px}
 .menu-title{margin:18px 8px 10px}
 .sidebar a{display:inline-block;font-size:13px;padding:11px;margin:3px}
 .main{padding:18px 13px}.topbar{align-items:flex-start;flex-direction:column}
 .panel{padding:17px}
}

.image-upload-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-top: 12px;
}

.image-upload-box {
    padding: 15px;
    border: 1px dashed #a77b50;
    border-radius: 12px;
    background: #fffaf3;
}

.image-upload-box label {
    display: block;
    font-weight: 700;
    margin-bottom: 10px;
}

.image-upload-box input {
    width: 100%;
    font-size: 12px;
}

@media(max-width:600px) {
    .image-upload-grid {
        grid-template-columns: 1fr;
    }
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
    <a href="products.php">Products</a>
    <a href="add-product.php" class="active">Add Product</a>
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
            <h1>Add New Product</h1>
            <p class="subtitle">Add a new leather product to your online store.</p>
        </div>
        <div class="admin-info"><?= h($admin['name']) ?> · <?= h($admin['role']) ?></div>
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="panel" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <h2 class="section-title">Product Information</h2>

        <div class="form-grid">
            <div class="field">
                <label>Product Name *</label>
                <input type="text" name="product_name" id="product_name" required maxlength="255" value="<?= h($old['product_name']) ?>" placeholder="Example: Premium Leather Wallet">
            </div>

            <div class="field">
                <label>Product Slug</label>
                <input type="text" name="slug" id="slug" placeholder="premium-leather-wallet">
                <span class="hint">SEO-friendly URL. Leave blank to use product name.</span>
            </div>

            <div class="field">
                <label>Category *</label>
                <input type="text" name="category" required value="<?= h($old['category']) ?>" placeholder="Wallet, Belt, Shoes, Bag">
            </div>

            <div class="field">
                <label>Product Status</label>
                <select name="status">
                    <option value="active" <?= $old['status']==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $old['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </div>

            <div class="field full">
                <label>Product Description *</label>
                <textarea name="description" required placeholder="Write detailed product information..."><?= h($old['description']) ?></textarea>
            </div>
        </div>

        <h2 class="section-title">Price & Inventory</h2>

        <div class="form-grid">
            <div class="field">
                <label>Regular Price (৳) *</label>
                <input type="number" name="price" min="0.01" step="0.01" required value="<?= h($old['price']) ?>">
            </div>

            <div class="field">
                <label>Sale Price (৳)</label>
                <input type="number" name="sale_price" min="0" step="0.01" value="<?= h($old['sale_price']) ?>">
                <span class="hint">Optional. Must not exceed regular price.</span>
            </div>

            <div class="field">
                <label>Stock Quantity *</label>
                <input type="number" name="stock" min="0" step="1" required value="<?= h($old['stock']) ?>">
            </div>

            <div class="field">
                <label>Available Sizes</label>
                <input type="text" name="sizes" value="<?= h($old['sizes']) ?>" placeholder="39, 40, 41, 42">
            </div>

            <div class="field full">
                <label>Available Colors</label>
                <input type="text" name="colors" value="<?= h($old['colors']) ?>" placeholder="Black, Brown, Chocolate">
            </div>
        </div>

        <h2 class="section-title">Product Images</h2>

        <div class="form-group">
    <label>Main Product Image</label>
    <input
        type="file"
        name="main_image"
        accept="image/*"
        required
    >
    <small>Select from computer, phone gallery or files.</small>
</div>

<div class="form-group">
    <label>Additional Product Images</label>

    <div class="image-upload-grid">

        <div class="image-upload-box">
            <label>Add 1</label>
            <input type="file" name="gallery_images[]" accept="image/*">
        </div>

        <div class="image-upload-box">
            <label>Add 2</label>
            <input type="file" name="gallery_images[]" accept="image/*">
        </div>

        <div class="image-upload-box">
            <label>Add 3</label>
            <input type="file" name="gallery_images[]" accept="image/*">
        </div>

    </div>
</div>

        <h2 class="section-title">SEO Information</h2>

        <div class="form-grid">
            <div class="field full">
                <label>Meta Title</label>
                <input type="text" name="meta_title" maxlength="255" value="<?= h($old['meta_title']) ?>" placeholder="Premium Leather Wallet | Leather Store BD">
            </div>

            <div class="field full">
                <label>Meta Description</label>
                <textarea name="meta_description" maxlength="1000" placeholder="Short search engine description..."><?= h($old['meta_description']) ?></textarea>
            </div>

            <div class="field full">
                <label>SEO Keywords</label>
                <input type="text" name="meta_keywords" value="<?= h($old['meta_keywords']) ?>" placeholder="leather wallet, genuine leather wallet, men's wallet">
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn">Save Product</button>
            <a href="products.php" class="cancel">Cancel</a>
        </div>
    </form>

</main>
</div>

<script>
const nameInput = document.getElementById('product_name');
const slugInput = document.getElementById('slug');

nameInput.addEventListener('input', function () {
    if (slugInput.dataset.edited === '1') return;

    slugInput.value = this.value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
});

slugInput.addEventListener('input', function () {
    this.dataset.edited = '1';
});

function previewFiles(input, target, multiple) {
    const box = document.getElementById(target);
    box.innerHTML = '';

    const files = multiple ? Array.from(input.files) : Array.from(input.files).slice(0, 1);

    files.forEach(file => {
        if (!file.type.startsWith('image/')) return;

        const img = document.createElement('img');
        const reader = new FileReader();

        reader.onload = e => img.src = e.target.result;
        reader.readAsDataURL(file);

        box.appendChild(img);
    });
}

document.getElementById('main_image').addEventListener('change', function () {
    previewFiles(this, 'mainPreview', false);
});

document.getElementById('gallery_images').addEventListener('change', function () {
    if (this.files.length > 10) {
        alert('You can select a maximum of 10 gallery images.');
        this.value = '';
    }
    previewFiles(this, 'galleryPreview', true);
});
</script>

</body>
</html>
