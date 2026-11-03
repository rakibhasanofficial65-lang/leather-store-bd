<?php
$pageTitle = "Shop All Products | Leather Store BD";

require_once __DIR__ . '/config/database.php';

function shopEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Get all active products
$stmt = $pdo->prepare("
    SELECT
        id,
        product_name,
        slug,
        category,
        price,
        sale_price,
        stock,
        image,
        created_at
    FROM products
    WHERE status = 'active'
    ORDER BY created_at DESC
");

$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalProducts = count($products);

// Category from URL
$selectedCategory = strtolower(trim($_GET['category'] ?? 'all'));

include 'includes/header.php';
?>

<main class="shop-page">

<link rel="stylesheet" href="assets/css/style.css">

    <!-- Shop Hero -->
    <section class="shop-hero">

        <div class="shop-hero-content">

            <span class="shop-eyebrow">THE LEATHER COLLECTION</span>

            <h1>
                Discover Your<br>
                <span>Everyday Essentials.</span>
            </h1>

            <p>
                Explore our collection of carefully selected leather
                products, designed to bring style and quality into
                your everyday life.
            </p>

            <div class="shop-breadcrumb">
                <a href="index.php">Home</a>
                <span>/</span>
                <span>Shop</span>
            </div>

        </div>

        <div class="shop-hero-image">
            <img
                src="assets/images/shop-hero.jpg"
                alt="Leather Store BD Collection"
            >
        </div>

    </section>


    <!-- Shop Main Section -->
    <section class="shop-main">

        <div class="shop-heading">

            <div>
                <span class="shop-eyebrow">OUR COLLECTION</span>
                <h2>Shop All Products</h2>
                <p>
                    Find the perfect leather piece for your lifestyle.
                </p>
            </div>

            <div class="shop-product-count">
                <span>
                    <?= str_pad((string)$totalProducts, 2, '0', STR_PAD_LEFT) ?>
                    PRODUCTS
                </span>
                <small>OUR COLLECTION</small>
            </div>

        </div>


        <!-- Search and Filter -->
        <div class="shop-toolbar">

            <div class="shop-search">

                <input
                    type="search"
                    id="shopSearch"
                    placeholder="Search leather products..."
                    aria-label="Search products"
                >

                <button
                    type="button"
                    id="shopSearchBtn"
                    aria-label="Search"
                >
                    &#9906;
                </button>

            </div>


            <div class="shop-filter">

                <label for="shopCategory">Category:</label>

                <select id="shopCategory">

                    <option value="all"
                        <?= $selectedCategory === 'all' ? 'selected' : '' ?>>
                        All Products
                    </option>

                    <option value="wallet"
                        <?= $selectedCategory === 'wallet' ? 'selected' : '' ?>>
                        Wallets
                    </option>

                    <option value="belt"
                        <?= $selectedCategory === 'belt' ? 'selected' : '' ?>>
                        Belts
                    </option>

                    <option value="bag"
                        <?= $selectedCategory === 'bag' ? 'selected' : '' ?>>
                        Bags
                    </option>

                    <option value="shoes"
                        <?= $selectedCategory === 'shoes' ? 'selected' : '' ?>>
                        Shoes
                    </option>

                </select>

            </div>


            <div class="shop-sort">

                <label for="shopSort">Sort:</label>

                <select id="shopSort">

                    <option value="default">Newest First</option>
                    <option value="low-high">Price: Low to High</option>
                    <option value="high-low">Price: High to Low</option>
                    <option value="name">Name: A to Z</option>

                </select>

            </div>

        </div>


        <!-- Dynamic Product Grid -->
        <div class="shop-product-grid" id="shopProductGrid">

            <?php if (!empty($products)): ?>

                <?php foreach ($products as $product): ?>

                    <?php
                        $productId = (int)$product['id'];

                        $productName = $product['product_name'];
                        $category = strtolower(trim($product['category'] ?? ''));

                        // Normalize category names for filtering
                        $categoryMap = [
                            'wallets' => 'wallet',
                            'leather wallet' => 'wallet',
                            'belts' => 'belt',
                            'leather belt' => 'belt',
                            'bags' => 'bag',
                            'leather bag' => 'bag',
                            'shoe' => 'shoes',
                            'leather shoes' => 'shoes'
                        ];

                        $category = $categoryMap[$category] ?? $category;

                        $price = (float)$product['price'];
                        $salePrice = $product['sale_price'];

                        $hasSale = $salePrice !== null
                            && $salePrice !== ''
                            && (float)$salePrice > 0
                            && (float)$salePrice < $price;

                        $displayPrice = $hasSale
                            ? (float)$salePrice
                            : $price;

                        $image = !empty($product['image'])
                            ? $product['image']
                            : 'assets/images/no-image.png';

                        $productUrl = 'product.php?id=' . $productId;
                    ?>

                    <article
                        class="shop-product-card"
                        data-category="<?= shopEscape($category) ?>"
                        data-name="<?= shopEscape($productName) ?>"
                        data-price="<?= $displayPrice ?>"
                    >

                        <div class="shop-product-image">

                            <?php if ($hasSale): ?>

                                <span class="shop-product-tag">
                                    SALE
                                </span>

                            <?php elseif ((int)$product['stock'] <= 0): ?>

                                <span class="shop-product-tag">
                                    OUT OF STOCK
                                </span>

                            <?php endif; ?>

                            <img
                                src="<?= shopEscape($image) ?>"
                                alt="<?= shopEscape($productName) ?>"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='assets/images/no-image.png';"
                            >

                            <a
                                href="<?= shopEscape($productUrl) ?>"
                                class="shop-quick-view"
                            >
                                Quick View
                            </a>

                        </div>


                        <div class="shop-product-info">

                            <span class="shop-product-category">
                                <?= shopEscape($product['category']) ?>
                            </span>

                            <h3>
                                <?= shopEscape($productName) ?>
                            </h3>


                            <div class="shop-product-price">

                                <?php if ($hasSale): ?>

                                    <strong>
                                        ৳ <?= number_format($displayPrice, 2) ?>
                                    </strong>

                                    <del>
                                        ৳ <?= number_format($price, 2) ?>
                                    </del>

                                <?php else: ?>

                                    <strong>
                                        ৳ <?= number_format($price, 2) ?>
                                    </strong>

                                <?php endif; ?>

                            </div>


                            <div class="shop-product-actions">

                                <a
                                    href="<?= shopEscape($productUrl) ?>"
                                    class="shop-view-btn"
                                >
                                    View
                                </a>

                                <a
                                    href="<?= shopEscape($productUrl) ?>"
                                    class="shop-buy-btn"
                                >
                                    Buy Now
                                </a>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- No Products -->
        <div
            class="shop-no-results"
            id="shopNoResults"
            <?= !empty($products) ? 'hidden' : '' ?>
        >

            <h3>
                <?= empty($products) ? 'No Products Available Yet' : 'No products found' ?>
            </h3>

            <p>
                <?= empty($products)
                    ? 'Our new leather collection will be available soon.'
                    : 'Try another search or select a different category.' ?>
            </p>

            <a href="shop.php" class="shop-view-btn">
                View All Products
            </a>

        </div>

    </section>

</main>

<script src="/leather-store-bd/assets/js/script.js" defer></script>

<?php include 'includes/footer.php'; ?>
