<?php
$pageTitle = "Shopping Cart | Leather Store BD";
include 'includes/header.php';
?>

<main class="cart-page">

    <!-- Cart Hero -->
    <section class="cart-hero">
        <span>YOUR SHOPPING BAG</span>
        <h1>Shopping <em>Cart</em></h1>
        <p>Review your selected leather essentials before checkout.</p>

        <div class="cart-breadcrumb">
            <a href="index.php">Home</a>
            <span>/</span>
            <span>Shopping Cart</span>
        </div>
    </section>


    <!-- Cart Content -->
    <section class="cart-container">

        <div class="cart-heading">
            <div>
                <span>YOUR SELECTION</span>
                <h2>Your Cart Items</h2>
            </div>

            <a href="shop.php" class="cart-continue-shopping">
                ← Continue Shopping
            </a>
        </div>


        <div class="cart-layout">

            <!-- Cart Items -->
            <div class="cart-items-section">

                <div class="cart-table-heading">
                    <span>PRODUCT</span>
                    <span>PRICE</span>
                    <span>QUANTITY</span>
                    <span>TOTAL</span>
                </div>

                <div id="cartItems" class="cart-items">

                    <!-- JavaScript loads cart products here -->

                </div>

                <div id="cartEmpty" class="cart-empty" hidden>
                    <div class="cart-empty-icon">🛍</div>
                    <h3>Your Cart Is Empty</h3>
                    <p>You haven't added any products to your cart yet.</p>
                    <a href="shop.php" class="cart-shop-button">
                        EXPLORE PRODUCTS
                    </a>
                </div>

            </div>


            <!-- Order Summary -->
            <aside class="cart-summary">

                <span class="cart-summary-label">ORDER SUMMARY</span>
                <h2>Cart Total</h2>

                <div class="cart-summary-row">
                    <span>Subtotal</span>
                    <strong id="cartSubtotal">৳ 0</strong>
                </div>

                <div class="cart-summary-row">
                    <span>Delivery Charge</span>
                    <strong id="cartDelivery">৳ 80</strong>
                </div>

                <div class="cart-summary-divider"></div>

                <div class="cart-summary-total">
                    <span>Total</span>
                    <strong id="cartGrandTotal">৳ 0</strong>
                </div>

                <p class="cart-delivery-note">
                    Delivery charge is calculated separately.
                </p>

                <button type="button" id="cartCheckoutButton"
                        class="cart-checkout-button">
                    PROCEED TO CHECKOUT →
                </button>

                <div class="cart-payment-note">
                    <span>✓</span>
                    Secure shopping experience
                </div>

            </aside>

        </div>

    </section>

</main>

<?php include 'includes/footer.php'; ?>
