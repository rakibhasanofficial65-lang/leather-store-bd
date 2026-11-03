<?php
if (session_status() === PHP_SESSION_NONE) {
      session_start();
}
$pageTitle = "Checkout | Leather Store BD";

$deliveryCharge = 80;

include 'includes/header.php';
?>

<main class="checkout-page">

    <!-- Checkout Hero -->
    <section class="checkout-hero">
        <span>ALMOST YOURS</span>
        <h1>Complete Your <em>Order.</em></h1>
        <p>Just a few details before your leather essentials arrive.</p>

        <div class="checkout-breadcrumb">
            <a href="index.php">Home</a>
            <span>/</span>
            <a href="cart.php">Cart</a>
            <span>/</span>
            <span>Checkout</span>
        </div>
    </section>


    <!-- Checkout Main -->
    <section class="checkout-container">

        <form id="checkoutForm" class="checkout-form">

            <div class="checkout-layout">

                <!-- Customer Information -->
                <div class="checkout-left">

                    <section class="checkout-card">
                        <div class="checkout-card-heading">
                            <span>01</span>
                            <div>
                                <h2>Customer Information</h2>
                                <p>Enter your contact details.</p>
                            </div>
                        </div>

                        <div class="checkout-fields">

                            <div class="checkout-field">
                                <label for="customerName">Full Name *</label>
                                <input type="text" id="customerName"
                                       name="customer_name"
                                       placeholder="Enter your full name"
                                       autocomplete="name" required>
                            </div>

                            <div class="checkout-field">
                                <label for="customerPhone">Phone Number *</label>
                                <input type="tel" id="customerPhone"
                                       name="phone"
                                       placeholder="01XXXXXXXXX"
                                       autocomplete="tel"
                                       maxlength="11" required>
                            </div>

                            <div class="checkout-field full-width">
                                <label for="customerEmail">Email Address</label>
                                <input type="email" id="customerEmail"
                                       name="email"
                                       placeholder="example@email.com"
                                       autocomplete="email">
                            </div>

                        </div>
                    </section>


                    <!-- Delivery -->
                    <section class="checkout-card">

                        <div class="checkout-card-heading">
                            <span>02</span>
                            <div>
                                <h2>Delivery Information</h2>
                                <p>Where should we deliver your order?</p>
                            </div>
                        </div>

                        <div class="checkout-fields">

                            <div class="checkout-field full-width">
                                <label for="deliveryAddress">
                                    Full Delivery Address *
                                </label>

                                <textarea id="deliveryAddress"
                                          name="address"
                                          rows="4"
                                          placeholder="House, Road, Area, District"
                                          autocomplete="street-address"
                                          required></textarea>
                            </div>

                            <div class="checkout-field full-width">
                                <label for="orderNote">
                                    Order Note (Optional)
                                </label>

                                <textarea id="orderNote"
                                          name="note"
                                          rows="3"
                                          placeholder="Any special delivery instructions?"></textarea>
                            </div>

                        </div>
                    </section>


                    <!-- Payment -->
                    <section class="checkout-card">

                        <div class="checkout-card-heading">
                            <span>03</span>
                            <div>
                                <h2>Payment Method</h2>
                                <p>Choose your preferred payment option.</p>
                            </div>
                        </div>

                        <div class="checkout-payment-options">

                            <label class="checkout-payment-option">
                                <input type="radio" name="payment_method"
                                       value="cod" checked>

                                <span class="checkout-payment-icon">৳</span>

                                <span class="checkout-payment-text">
                                    <strong>Cash on Delivery</strong>
                                    <small>Pay when your order arrives.</small>
                                </span>
                            </label>

                            <label class="checkout-payment-option">
                                <input type="radio" name="payment_method"
                                       value="bkash">

                                <span class="checkout-payment-icon">bK</span>

                                <span class="checkout-payment-text">
                                    <strong>bKash</strong>
                                    <small>Pay through bKash.</small>
                                </span>
                            </label>

                            <label class="checkout-payment-option">
                                <input type="radio" name="payment_method"
                                       value="nagad">

                                <span class="checkout-payment-icon">N</span>

                                <span class="checkout-payment-text">
                                    <strong>Nagad</strong>
                                    <small>Pay through Nagad.</small>
                                </span>
                            </label>

                            <label class="checkout-payment-option">
                                <input type="radio" name="payment_method"
                                       value="rocket">

                                <span class="checkout-payment-icon">R</span>

                                <span class="checkout-payment-text">
                                    <strong>Rocket</strong>
                                    <small>Pay through Rocket.</small>
                                </span>
                            </label>

                            <label class="checkout-payment-option">
                                <input type="radio" name="payment_method"
                                       value="bank">

                                <span class="checkout-payment-icon">B</span>

                                <span class="checkout-payment-text">
                                    <strong>Bank Transfer</strong>
                                    <small>Pay through bank transfer.</small>
                                </span>
                            </label>

                        </div>


                        <!-- Mobile Banking Details -->
                        <div id="mobilePaymentDetails"
                             class="checkout-payment-details" hidden>

                            <h3>Payment Information</h3>

                            <p>
                                Complete your payment using the official
                                payment instructions provided by the store.
                            </p>

                            <div class="checkout-field">
                                <label for="senderNumber">
                                    Sender Number *
                                </label>

                                <input type="tel" id="senderNumber"
                                       name="sender_number"
                                       placeholder="01XXXXXXXXX"
                                       maxlength="11">
                            </div>

                            <div class="checkout-field">
                                <label for="transactionId">
                                    Transaction ID *
                                </label>

                                <input type="text" id="transactionId"
                                       name="transaction_id"
                                       placeholder="Enter transaction ID">
                            </div>

                            <p class="checkout-payment-warning">
                                Do not submit a payment until you have
                                verified the official payment instructions.
                            </p>

                        </div>

                    </section>

                </div>


                <!-- Order Summary -->
                <aside class="checkout-right">

                    <div class="checkout-summary-card">

                        <span class="checkout-summary-label">
                            YOUR ORDER
                        </span>

                        <h2>Order Summary</h2>

                        <div id="checkoutCartItems"
                             class="checkout-cart-items">
                            <!-- JavaScript loads products -->
                        </div>

                        <div class="checkout-summary-row">
                            <span>Subtotal</span>
                            <strong id="checkoutSubtotal">৳ 0</strong>
                        </div>

                        <div class="checkout-summary-row">
                            <span>Delivery Charge</span>
                            <strong id="checkoutDelivery">৳ 80</strong>
                        </div>

                        <div class="checkout-summary-divider"></div>

                        <div class="checkout-grand-total">
                            <span>Total Payable</span>
                            <strong id="checkoutGrandTotal">৳ 0</strong>
                        </div>

                        <button type="submit"
                                id="placeOrderButton"
                                class="checkout-submit-button">
                            PLACE YOUR ORDER →
                        </button>

                        <p class="checkout-terms">
                            By placing your order, you agree to our
                            order and delivery terms.
                        </p>

                    </div>

                </aside>

            </div>

        </form>

    </section>

</main>

<?php include 'includes/footer.php'; ?>
