<?php
$pageTitle = "Product Details | Leather Store BD";

require_once __DIR__ . '/config/database.php';

function productEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Convert comma/newline separated options into arrays
function productOptions($value) {
    if (empty($value)) {
        return [];
    }

    $items = preg_split('/[,;\r\n]+/', (string)$value);

    return array_values(array_filter(array_map('trim', $items)));
}

// Get product by database ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$product = null;
$gallery = [];

if ($id && $id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM products
        WHERE id = :id
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute(['id' => $id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {

        // Get additional gallery images
        $galleryStmt = $pdo->prepare("
            SELECT image_path
            FROM product_images
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, id ASC
        ");

        $galleryStmt->execute(['product_id' => $id]);

        $galleryRows = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);

        // Main image
        $mainImage = !empty($product['image'])
            ? $product['image']
            : 'assets/images/no-image.png';

        $gallery[] = $mainImage;

        // Additional images
        foreach ($galleryRows as $galleryImage) {
            if (!empty($galleryImage) && !in_array($galleryImage, $gallery, true)) {
                $gallery[] = $galleryImage;
            }
        }

        $colors = productOptions($product['color_options'] ?? '');
        $sizes = productOptions($product['size_options'] ?? '');

        $price = (float)$product['price'];
        $salePrice = $product['sale_price'];

        $hasSale = $salePrice !== null
            && $salePrice !== ''
            && (float)$salePrice > 0
            && (float)$salePrice < $price;

        $displayPrice = $hasSale ? (float)$salePrice : $price;

        $sku = 'LSB-' . str_pad((string)$product['id'], 5, '0', STR_PAD_LEFT);

        $pageTitle = $product['product_name'] . " | Leather Store BD";
    }
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/style.css">
<style>
/* =================================
   PRODUCT MAGNIFIER ZOOM
================================= */

.product-main-image {
    position: relative;
    overflow: hidden;
    cursor: crosshair;
    touch-action: pan-y;
}

.product-main-image img {
    display: block;
    width: 100%;
    height: auto;
    user-select: none;
    -webkit-user-drag: none;
}

.product-magnifier {
    position: absolute;
    width: 190px;
    height: 190px;
    border-radius: 50%;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 20px rgba(0,0,0,0.35);
    background-repeat: no-repeat;
    pointer-events: none;
    display: none;
    z-index: 20;
}

.product-main-image.zoom-active .product-magnifier {
    display: block;
}

.product-zoom-button {
    position: absolute;
    right: 15px;
    bottom: 15px;
    z-index: 25;
    width: 42px;
    height: 42px;
    border: none;
    border-radius: 50%;
    background: #442b1b;
    color: #ffffff;
    font-size: 25px;
    cursor: pointer;
}

.product-zoom-level {
    position: absolute;
    left: 15px;
    bottom: 15px;
    z-index: 25;
    background: rgba(0,0,0,0.75);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    pointer-events: none;
}

@media (max-width: 600px) {
    .product-magnifier {
        width: 155px;
        height: 155px;
    }

    .product-main-image {
        cursor: default;
    }
}</style>
<main class="product-page">

<?php if ($product): ?>

    <!-- Breadcrumb -->
    <div class="product-breadcrumb">

        <a href="index.php">Home</a>
        <span>/</span>

        <a href="shop.php">Shop</a>
        <span>/</span>

        <span>
            <?= productEscape($product['product_name']) ?>
        </span>

    </div>


    <!-- Product Details -->
    <section class="product-details">

        <!-- Product Gallery -->
        <div class="product-gallery">
            <div class="product-main-image" id="productMainImage">

    <span class="product-image-label">
        LEATHER STORE BD
    </span>

    <img
        id="mainProductImage"
        src="<?= productEscape($mainImage) ?>"
        alt="<?= productEscape($product['product_name']) ?>"
        onerror="this.onerror=null;this.src='assets/images/no-image.png';"
        draggable="false"
    >

    <!-- Magnifier Lens -->
    <div id="productMagnifier" class="product-magnifier"></div>

    <!-- Zoom Control -->
    <button
        type="button"
        class="product-zoom-button"
        id="productZoomStep"
        aria-label="Increase image zoom"
    >
        +
    </button>

    <span id="productZoomLevel" class="product-zoom-level">
        2x
    </span>

</div>


            <!-- Thumbnails -->
            <div class="product-thumbnails">

                <?php foreach ($gallery as $index => $image): ?>

                    <button
                        type="button"
                        class="product-thumbnail <?= $index === 0 ? 'active' : '' ?>"
                        data-image="<?= productEscape($image) ?>"
                    >

                        <img
                            src="<?= productEscape($image) ?>"
                            alt="Product view <?= $index + 1 ?>"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='assets/images/no-image.png';"
                        >

                    </button>

                <?php endforeach; ?>

            </div>

            <p class="product-gallery-note">
                Select an image to view different product angles.
            </p>

        </div>


        <!-- Product Information -->
        <div class="product-information">

            <span class="product-category">
                <?= productEscape($product['category']) ?>
            </span>

            <h1>
                <?= productEscape($product['product_name']) ?>
            </h1>

            <p class="product-sku">
                Product Code: <?= productEscape($sku) ?>
            </p>


            <!-- Price -->
            <div class="product-price">

                <strong>
                    ৳ <?= number_format($displayPrice, 2) ?>
                </strong>

                <?php if ($hasSale): ?>

                    <del>
                        ৳ <?= number_format($price, 2) ?>
                    </del>

                <?php endif; ?>

            </div>


            <!-- Description -->
            <p class="product-description">
                <?= nl2br(productEscape($product['description'] ?? '')) ?>
            </p>


            <?php if ((int)$product['stock'] > 0): ?>

                <p class="product-stock">
                    <span>✓ In Stock</span>
                </p>

            <?php else: ?>

                <p class="product-stock">
                    <span>Out of Stock</span>
                </p>

            <?php endif; ?>


            <!-- Order Form -->
            <form class="product-order-form" id="productOrderForm">

                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int)$product['id'] ?>"
                >

                <input
                    type="hidden"
                    name="product_name"
                    value="<?= productEscape($product['product_name']) ?>"
                >

                <input
                    type="hidden"
                    name="product_price"
                    value="<?= $displayPrice ?>"
                >


                <!-- Color -->
                <?php if (!empty($colors)): ?>

                    <div class="product-option">

                        <label for="productColor">
                            Select Color
                        </label>

                        <select
                            id="productColor"
                            name="color"
                            required
                        >

                            <option value="">Choose a color</option>

                            <?php foreach ($colors as $color): ?>

                                <option value="<?= productEscape($color) ?>">
                                    <?= productEscape($color) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                <?php endif; ?>


                <!-- Size -->
                <?php if (!empty($sizes)): ?>

                    <div class="product-option">

                        <label for="productSize">
                            Select Size
                        </label>

                        <select
                            id="productSize"
                            name="size"
                            required
                        >

                            <option value="">Choose a size</option>

                            <?php foreach ($sizes as $size): ?>

                                <option value="<?= productEscape($size) ?>">
                                    <?= productEscape($size) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                <?php endif; ?>


                <!-- Quantity -->
                <div class="product-option">

                    <label for="productQuantity">
                        Quantity
                    </label>

                    <div class="product-quantity">

                        <button type="button" id="quantityMinus">
                            −
                        </button>

                        <input
                            type="number"
                            id="productQuantity"
                            name="quantity"
                            value="1"
                            min="1"
                            max="<?= max(1, min(10, (int)$product['stock'])) ?>"
                        >

                        <button type="button" id="quantityPlus">
                            +
                        </button>

                    </div>

                </div>


                <!-- Buttons -->
                <div class="product-action-buttons">

                    <button
                        type="button"
                        class="product-add-cart"
                        id="addToCartButton"
                        <?= (int)$product['stock'] <= 0 ? 'disabled' : '' ?>
                    >
                        ADD TO CART
                    </button>

                    <button
                        type="button"
                        class="product-buy-now"
                        id="buyNowButton"
                        <?= (int)$product['stock'] <= 0 ? 'disabled' : '' ?>
                    >
                        BUY NOW
                    </button>

                </div>

            </form>


            <!-- Trust Information -->
            <div class="product-trust-info">

                <p>✓ Carefully selected leather products</p>
                <p>✓ Cash on Delivery available</p>
                <p>✓ Customer support available</p>

            </div>

        </div>

    </section>


    <!-- Product Specifications -->
    <section class="product-extra-details">

        <div class="product-extra-heading">

            <span>PRODUCT INFORMATION</span>

            <h2>Details & Specifications</h2>

        </div>


        <div class="product-specification">

            <div>
                <span>Product Name</span>

                <strong>
                    <?= productEscape($product['product_name']) ?>
                </strong>
            </div>


            <div>
                <span>Category</span>

                <strong>
                    <?= productEscape($product['category']) ?>
                </strong>
            </div>


            <div>
                <span>Product Code</span>

                <strong>
                    <?= productEscape($sku) ?>
                </strong>
            </div>


            <div>
                <span>Available Colors</span>

                <strong>
                    <?= productEscape(implode(', ', $colors)) ?: 'Not specified' ?>
                </strong>
            </div>


            <?php if (!empty($sizes)): ?>

                <div>
                    <span>Available Sizes</span>

                    <strong>
                        <?= productEscape(implode(', ', $sizes)) ?>
                    </strong>
                </div>

            <?php endif; ?>

        </div>

    </section>


    <!-- Related Products -->
    <section class="product-related">

        <div class="product-related-heading">

            <span>YOU MAY ALSO LIKE</span>

            <h2>Explore More Products</h2>

        </div>

        <a href="shop.php" class="product-related-link">
            Explore All Products →
        </a>

    </section>


<?php else: ?>

    <!-- Product Not Found -->
    <section class="product-not-found">

        <h1>Product Not Found</h1>

        <p>
            The product you are looking for is unavailable
            or may have been removed.
        </p>

        <a href="shop.php">
            Back to Shop
        </a>

    </section>

<?php endif; ?>

</main>
<style>
.product-main-image {
    position: relative;
    overflow: hidden;
    cursor: crosshair;
    touch-action: pan-y;
}

.product-main-image #mainProductImage {
    display: block;
    width: 100%;
    height: auto;
    transform: scale(1);
    transform-origin: center center;
    transition: transform 0.12s ease-out;
    will-change: transform;
    user-select: none;
    -webkit-user-drag: none;
}

.product-main-image.zooming #mainProductImage {
    transition: none;
}

.product-zoom-level {
    position: absolute;
    left: 15px;
    bottom: 15px;
    z-index: 5;
    background: rgba(0,0,0,.78);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    pointer-events: none;
}

@media(max-width:600px) {
    .product-main-image {
        cursor: default;
    }
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const box = document.getElementById("productMainImage");
    const img = document.getElementById("mainProductImage");
    const label = document.getElementById("productZoomLevel");

    if (!box || !img) return;

    let zoom = 2;
    let active = false;
    let x = 50;
    let y = 50;

    const MIN = 2;
    const MAX = 10;

    function update() {
        if (!active) return;

        const rect = img.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();

        const originalWidth = img.offsetWidth;
        const originalHeight = img.offsetHeight;

        const pointX = ((x - (boxRect.left + (rect.width - originalWidth) / 2)) / rect.width) * 100;
        const pointY = ((y - (boxRect.top + (rect.height - originalHeight) / 2)) / rect.height) * 100;

        img.style.transformOrigin =
            Math.max(0, Math.min(100, pointX)) + "% " +
            Math.max(0, Math.min(100, pointY)) + "%";

        img.style.transform = "scale(" + zoom + ")";

        label.textContent = zoom + "x";
    }

    function move(e) {
        x = e.clientX;
        y = e.clientY;
        update();
    }

    function start(e) {
        if (e.pointerType === "mouse" || e.pointerType === "touch") {
            active = true;
            box.classList.add("zooming");
            move(e);
        }
    }

    function stop() {
        active = false;
        box.classList.remove("zooming");
        img.style.transform = "scale(1)";
        img.style.transformOrigin = "center center";
        label.textContent = "2x";
        zoom = 2;
    }

    box.addEventListener("pointerenter", function(e) {
        if (e.pointerType === "mouse") start(e);
    });

    box.addEventListener("pointermove", function(e) {
        if (active) move(e);
    });

    box.addEventListener("pointerleave", function(e) {
        if (e.pointerType === "mouse") stop();
    });

    box.addEventListener("pointerdown", function(e) {
        if (e.pointerType === "touch") start(e);
    });

    window.addEventListener("pointerup", function(e) {
        if (e.pointerType === "touch") stop();
    });

    window.addEventListener("pointercancel", stop);

    box.addEventListener("wheel", function(e) {
        e.preventDefault();

        if (!active) {
            active = true;
            box.classList.add("zooming");
            x = e.clientX;
            y = e.clientY;
        }

        zoom = Math.max(MIN, Math.min(MAX,
            zoom + (e.deltaY < 0 ? 1 : -1)
        ));

        move(e);

    }, { passive: false });

    img.addEventListener("load", stop);

    document.querySelectorAll(".product-thumbnail").forEach(function(btn) {
        btn.addEventListener("click", stop);
    });

});
</script>
<script src="/leather-store-bd/assets/js/script.js" defer></script>

<?php include 'includes/footer.php'; ?>
