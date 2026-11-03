<?php
$page_title = "Leather Store BD | Premium Leather Collection";

require_once __DIR__ . '/config/database.php';

// Get latest active products for Home Featured Collection
$stmt = $pdo->prepare("
    SELECT id, product_name, slug, category, price, sale_price, image
    FROM products
    WHERE status = 'active'
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute();

$featuredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function homeEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

include 'includes/header.php';
?>

<main class="home-page">
<link rel="stylesheet" href="assets/css/style.css">

    <!-- HERO SECTION -->
    <section class="home-hero">

        <div class="home-hero-content">

            <span class="home-eyebrow">
                THE ART OF EVERYDAY LUXURY
            </span>

            <h1>
                Made For Your
                <span>Everyday Journey.</span>
            </h1>

            <p>
                Discover timeless leather essentials.
                Designed with character, crafted for comfort
                and made to complement your lifestyle.
            </p>

            <div class="home-hero-buttons">
                <a href="shop.php" class="home-primary-btn">
                    Explore Collection
                </a>

                <a href="about.php" class="home-secondary-btn">
                    Our Story →
                </a>
            </div>

            <div class="home-hero-note">
                <span>TIMELESS DESIGN</span>
                <span class="home-note-line"></span>
                <span>EVERYDAY ESSENTIALS</span>
            </div>

        </div>

        <div class="home-hero-image">
    <img src="assets/images/home-hero.jpg"
         alt="Leather Store BD Premium Leather Collection">


        <div class="home-image-caption">
           Leather Store BD: </br>Timeless Leather Essentials for Everyday Elegance'
        </div>
  
    </div>

    </section>

    <!-- CATEGORIES -->
    <section class="home-categories">

        <div class="home-section-heading">
            <div>
                <span class="home-eyebrow">EXPLORE BY CATEGORY</span>
                <h2>Find Your Everyday Essential</h2>
            </div>

            <a href="shop.php" class="home-view-all">
                View All Products →
            </a>
        </div>

        <div class="home-category-grid">

            <a href="shop.php?category=wallet"
               class="home-category-card">

                <img
                    src="assets/images/category-wallet.jpg"
                    alt="Leather wallets"
                    loading="lazy"
                >

                <div class="home-category-info">
                    <span>01 / ESSENTIALS</span>
                    <h3>Wallets</h3>
                    <strong>Explore Collection →</strong>
                </div>

            </a>


            <a href="shop.php?category=belt"
               class="home-category-card">

                <img
                    src="assets/images/category-belt.jpg"
                    alt="Leather belts"
                    loading="lazy"
                >

                <div class="home-category-info">
                    <span>02 / CLASSICS</span>
                    <h3>Belts</h3>
                    <strong>Explore Collection →</strong>
                </div>

            </a>


            <a href="shop.php?category=bag"
               class="home-category-card">

                <img
                    src="assets/images/category-bag.jpg"
                    alt="Leather bags"
                    loading="lazy"
                >

                <div class="home-category-info">
                    <span>03 / LIFESTYLE</span>
                    <h3>Bags</h3>
                    <strong>Explore Collection →</strong>
                </div>

            </a>


            <a href="shop.php?category=shoes"
               class="home-category-card">

                <img
                    src="assets/images/category-shoes.jpg"
                    alt="Leather shoes"
                    loading="lazy"
                >

                <div class="home-category-info">
                    <span>04 / FOOTWEAR</span>
                    <h3>Shoes</h3>
                    <strong>Explore Collection →</strong>
                </div>

            </a>

        </div>

    </section>


   <!-- FEATURED PRODUCTS -->
<section class="home-featured">

    <div class="home-section-heading">
        <div>
            <span class="home-eyebrow">CURATED FOR YOU</span>
            <h2>Featured Collection</h2>
            <p>
                Discover products selected for your everyday style.
            </p>
        </div>

        <a href="shop.php" class="home-view-all">
            Shop All →
        </a>
    </div>

    <?php if (!empty($featuredProducts)): ?>

        <div class="home-product-grid">

            <?php foreach ($featuredProducts as $product): ?>

                <?php
                    $image = !empty($product['image'])
                        ? $product['image']
                        : 'assets/images/no-image.png';

                    $price = (float)$product['price'];
                    $salePrice = $product['sale_price'];

                    $hasSale = $salePrice !== null
                        && $salePrice !== ''
                        && (float)$salePrice > 0
                        && (float)$salePrice < $price;

                    $productUrl = 'product.php?id=' . (int)$product['id'];
                ?>

                <div class="home-product-card">

                    <a href="<?= homeEscape($productUrl) ?>"
                       class="home-product-image">

                        <img
                            src="<?= homeEscape($image) ?>"
                            alt="<?= homeEscape($product['product_name']) ?>"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='assets/images/no-image.png';"
                        >

                        <?php if ($hasSale): ?>
                            <span class="home-product-badge">
                                SALE
                            </span>
                        <?php endif; ?>

                    </a>

                    <div class="home-product-info">

                        <span class="home-product-category">
                            <?= homeEscape($product['category']) ?>
                        </span>

                        <h3>
                            <a href="<?= homeEscape($productUrl) ?>">
                                <?= homeEscape($product['product_name']) ?>
                            </a>
                        </h3>

                        <div class="home-product-price">

                            <?php if ($hasSale): ?>

                                <strong>
                                    ৳<?= number_format((float)$salePrice, 2) ?>
                                </strong>

                                <del>
                                    ৳<?= number_format($price, 2) ?>
                                </del>

                            <?php else: ?>

                                <strong>
                                    ৳<?= number_format($price, 2) ?>
                                </strong>

                            <?php endif; ?>

                        </div>

                        <div class="home-product-actions">

                            <a href="<?= homeEscape($productUrl) ?>"
                               class="home-product-view">
                                View Product
                            </a>

                            <a href="<?= homeEscape($productUrl) ?>"
                               class="home-product-buy">
                                Buy Now →
                            </a>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="home-featured-placeholder">

            <div class="home-featured-placeholder-icon">✦</div>

            <h3>Your Next Favourite Starts Here</h3>

            <p>
                Explore our complete product collection
                and discover your everyday essentials.
            </p>

            <a href="shop.php" class="home-primary-btn">
                Browse Products
            </a>

        </div>

    <?php endif; ?>

</section>


    <!-- BRAND BANNER -->
    <section class="home-brand-banner">

        <div class="home-brand-banner-content">

            <span class="home-eyebrow">
                THE LEATHER EXPERIENCE
            </span>

            <h2>
                Carry Confidence.
                <span>Wear Character.</span>
            </h2>

            <p>
                Find pieces that express your personality
                and become part of your everyday journey.
            </p>

            <a href="shop.php" class="home-light-btn">
                Discover The Collection →
            </a>

        </div>

    </section>
    <!-- BRAND INTRO -->
    <section class="home-intro">

        <span class="home-eyebrow">LEATHER STORE BD</span>

        <h2>
            Not Just An Accessory.
            <span>A Part Of Your Story.</span>
        </h2>

        <p>
            Everyday essentials should feel as good as they look.
            Explore thoughtfully selected leather products that
            bring together timeless style and practical design.
        </p>

        <a href="about.php" class="home-text-link">
            Discover Our Story →
        </a>

    </section>



    <!-- WHY CHOOSE US -->
    <section class="home-benefits">

        <div class="home-section-heading home-benefit-heading">
            <div>
                <span class="home-eyebrow">THE DIFFERENCE</span>
                <h2>Shopping Made Simple</h2>
            </div>
        </div>

        <div class="home-benefit-grid">

            <div class="home-benefit-card">
                <span>✦</span>
                <h3>Carefully Selected</h3>
                <p>
                    Products chosen with attention
                    to style and everyday use.
                </p>
            </div>

            <div class="home-benefit-card">
                <span>◇</span>
                <h3>Easy Shopping</h3>
                <p>
                    A simple and convenient
                    online shopping experience.
                </p>
            </div>

            <div class="home-benefit-card">
                <span>♡</span>
                <h3>Customer Support</h3>
                <p>
                    We are here to help you
                    with your shopping questions.
                </p>
            </div>

            <div class="home-benefit-card">
                <span>✓</span>
                <h3>Easy Ordering</h3>
                <p>
                    Browse, select and place
                    your order with ease.
                </p>
            </div>

        </div>

    </section>


    <!-- FINAL CTA -->
    <section class="home-final-cta">

        <span class="home-eyebrow">YOUR STYLE STARTS HERE</span>

        <h2>
            Discover Something
            <span>Worth Carrying.</span>
        </h2>

        <p>
            Explore the Leather Store BD collection today.
        </p>

        <a href="shop.php" class="home-primary-btn">
            Start Shopping →
        </a>

    </section>

</main>
<script src="/leather-store-bd/assets/js/script.js" defer></script>
<?php include 'includes/footer.php'; ?>