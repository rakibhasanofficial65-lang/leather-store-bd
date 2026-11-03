
<?php
if (!isset($pageTitle)) {
    $pageTitle = "Leather Store BD | Genuine Leather Products";
}

$currentPage = basename($_SERVER['PHP_SELF']);

$cartCount = 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <meta name="description"
          content="Discover premium leather wallets, belts, bags and shoes at Leather Store BD.">

    <meta name="theme-color" content="#f8f5ef">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap"
          rel="stylesheet">
    
  <!-- Font Awesome Icons -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

<header class="site-header">

    <!-- Announcement -->
    <div class="header-announcement">
        <p>CRAFTED WITH CHARACTER · MADE TO LAST</p>
    </div>

    <!-- Main Header -->
    <div class="header-main">
        
        <div class="header-logo">
            <a href="index.php">
                <span class="logo-main">LEATHER</span>
                <span class="logo-sub">STORE BD</span>
            </a>
        </div>
        

        <!-- Desktop Search -->
        <form class="header-search" action="shop.php" method="GET">

            <input type="search"
                   name="search"
                   placeholder="Search leather products..."
                   aria-label="Search products">

            <button type="submit" aria-label="Search">
                <svg viewBox="0 0 24 24" width="19" height="19"
                     fill="none" stroke="currentColor"
                     stroke-width="1.7">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m16 16 4 4"></path>
                </svg>
            </button>

        </form>


        <!-- Header Actions -->
        <div class="header-actions">

            <a href="cart.php" class="header-cart">
                <svg viewBox="0 0 24 24" width="21" height="21"
                     fill="none" stroke="currentColor"
                     stroke-width="1.6">
                    <path d="M3 3h2l2.2 11.5a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L21 8H6"></path>
                    <circle cx="10" cy="20" r="1"></circle>
                    <circle cx="18" cy="20" r="1"></circle>
                </svg>

                <span>Cart</span>
                <small class="header-cart-count" id="headerCartCount">0</small>
            </a>
               <button type="button" class="site-back-button"
        onclick="goBackPage()"
        aria-label="Go back"
        title="Go Back">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
        </button>

            <button type="button"
                    class="mobile-menu-toggle"
                    id="mobileMenuToggle"
                    aria-label="Open navigation"
                    aria-expanded="false">

                <span></span>
                <span></span>
                <span></span>

            </button>

        </div>

    </div>


    <!-- Navigation -->
    <nav class="main-navigation" id="mainNavigation">

        <div class="mobile-nav-search">
            <form action="shop.php" method="GET">
                <input type="search"
                       name="search"
                       placeholder="Search products...">

                <button type="submit">SEARCH</button>
            </form>
        </div>

     
        <a href="index.php"
           class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
            HOME
        </a>

        <a href="shop.php"
           class="<?php echo $currentPage === 'shop.php' ? 'active' : ''; ?>">
            SHOP
        </a>

        <a href="about.php"
           class="<?php echo $currentPage === 'about.php' ? 'active' : ''; ?>">
            ABOUT
        </a>

        <a href="contact.php"
           class="<?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>">
            CONTACT
        </a>

        <a href="explore.php"
           class="<?php echo $currentPage === 'explore.php' ? 'active' : ''; ?>">
            EXPLORE
        </a>

        <a href="cart.php"
           class="<?php echo $currentPage === 'cart.php' ? 'active' : ''; ?>">
            MY CART
        </a>

    </nav>

</header>

