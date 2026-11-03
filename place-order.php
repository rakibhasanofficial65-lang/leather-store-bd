<?php
// ==================================================
// LEATHER STORE BD - COMPLETE ORDER + EMAIL SYSTEM
// ==================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mailer.php';

$pageTitle = "Order Confirmation | Leather Store BD";

const STORE_ADMIN_EMAIL = 'leatherstorebd@gmail.com';
const STORE_DELIVERY_CHARGE = 80;


// ==================================================
// HELPER FUNCTIONS
// ==================================================

function orderEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function orderOptions($value): array
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (is_array($decoded)) {
        $options = $decoded;
    } else {
        $options = preg_split('/[,;\r\n]+/', $value);
    }

    $options = array_map(
        fn($item) => trim((string)$item),
        $options
    );

    return array_values(
        array_filter($options, fn($item) => $item !== '')
    );
}


function orderPaymentName($method): string
{
    $methods = [
        'cod' => 'Cash on Delivery',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'bank' => 'Bank Transfer'
    ];

    return $methods[$method] ?? ucfirst($method);
}


// ==================================================
// EMAIL TEMPLATE
// ==================================================

function buildOrderEmail(array $order, bool $isAdmin = false): string
{
    $customer = $order['customer'];
    $payment = $order['payment'];

    $productRows = '';

    foreach ($order['items'] as $item) {

        $productRows .= '
            <tr>
                <td style="padding:12px;border-bottom:1px solid #eee;">
                    <strong>' . orderEscape($item['name']) . '</strong><br>
                    <small>Size: ' . orderEscape($item['size'] ?: 'Standard') . '</small><br>
                    <small>Color: ' . orderEscape($item['color'] ?: 'Standard') . '</small><br>
                    <small>Quantity: ' . (int)$item['quantity'] . '</small>
                </td>

                <td style="padding:12px;border-bottom:1px solid #eee;text-align:right;">
                    ৳' . number_format((float)$item['line_total'], 2) . '
                </td>
            </tr>
        ';
    }

    $adminDetails = '';

    if ($isAdmin) {

        $adminDetails = '
            <h3>Customer Information</h3>

            <p><strong>Name:</strong> ' . orderEscape($customer['name']) . '</p>
            <p><strong>Phone:</strong> ' . orderEscape($customer['phone']) . '</p>
            <p><strong>Email:</strong> ' . orderEscape($customer['email'] ?: 'Not provided') . '</p>
            <p><strong>Address:</strong> ' . orderEscape($customer['address']) . '</p>
            <p><strong>Note:</strong> ' . orderEscape($customer['note'] ?: 'None') . '</p>

            <h3>Payment Information</h3>

            <p><strong>Payment Method:</strong> ' .
                orderEscape(orderPaymentName($payment['method'])) . '</p>

            <p><strong>Sender Number:</strong> ' .
                orderEscape($payment['sender_number'] ?: 'N/A') . '</p>

            <p><strong>Transaction ID:</strong> ' .
                orderEscape($payment['transaction_id'] ?: 'N/A') . '</p>
        ';
    }

    $heading = $isAdmin
        ? 'New Order Received!'
        : 'Thank You for Your Order!';

    $message = $isAdmin
        ? 'A new order has been placed on Leather Store BD.'
        : 'Your order has been successfully received. We will contact you shortly.';

    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>

    <body style="margin:0;background:#f5f1eb;font-family:Arial,sans-serif;color:#30251e;">

        <div style="max-width:650px;margin:30px auto;background:#ffffff;border-radius:12px;overflow:hidden;">

            <div style="background:#493426;padding:28px;text-align:center;color:#ffffff;">
                <h1 style="margin:0;">Leather Store BD</h1>
                <p style="margin:8px 0 0;">Premium Leather Collection</p>
            </div>

            <div style="padding:30px;">

                <h2>' . $heading . '</h2>

                <p>' . $message . '</p>

                <div style="background:#f5f1eb;padding:18px;border-radius:8px;margin:20px 0;">
                    <p style="margin:0 0 5px;">Order ID</p>

                    <h2 style="margin:0;color:#795438;">
                        ' . orderEscape($order['order_id']) . '
                    </h2>

                    <p style="margin:10px 0 0;">
                        <strong>Order Status:</strong> Pending
                    </p>
                </div>

                ' . $adminDetails . '

                <h3>Order Products</h3>

                <table width="100%" cellpadding="0" cellspacing="0"
                    style="border-collapse:collapse;">

                    ' . $productRows . '

                </table>

                <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:15px;">

                    <p>
                        Subtotal:
                        <strong style="float:right;">
                            ৳' . number_format((float)$order['subtotal'], 2) . '
                        </strong>
                    </p>

                    <p>
                        Delivery:
                        <strong style="float:right;">
                            ৳' . number_format((float)$order['delivery'], 2) . '
                        </strong>
                    </p>

                    <h2 style="color:#795438;">
                        Total:
                        <span style="float:right;">
                            ৳' . number_format((float)$order['total'], 2) . '
                        </span>
                    </h2>

                </div>

                ' . (!$isAdmin ? '
                    <p style="margin-top:25px;">
                        Thank you for shopping with Leather Store BD.
                    </p>
                ' : '') . '

            </div>

            <div style="background:#f5f1eb;padding:20px;text-align:center;">
                <p style="margin:0;">Leather Store BD</p>
                <small>Thank you for choosing us.</small>
            </div>

        </div>

    </body>
    </html>
    ';
}


// ==================================================
// SEND ORDER EMAILS
// ==================================================

function sendOrderEmails(array $order): void
{
    // Customer confirmation email
    $customerEmail = trim($order['customer']['email'] ?? '');

    if ($customerEmail !== '') {

        $sent = sendStoreEmail(
            $customerEmail,
            $order['customer']['name'],
            'Order Confirmation - ' . $order['order_id'],
            buildOrderEmail($order, false)
        );

        if (!$sent) {
            error_log(
                'Leather Store BD: Customer email failed for ' .
                $order['order_id']
            );
        }
    }

    // Admin notification email
    $adminSent = sendStoreEmail(
        STORE_ADMIN_EMAIL,
        'Leather Store BD Admin',
        'New Order Received - ' . $order['order_id'],
        buildOrderEmail($order, true)
    );

    if (!$adminSent) {
        error_log(
            'Leather Store BD: Admin email failed for ' .
            $order['order_id']
        );
    }
}


// ==================================================
// MAIN ORDER PROCESS
// ==================================================

$order = null;
$errorMessage = '';

try {

    // ----------------------------------------------
    // PROCESS NEW ORDER
    // ----------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_data'])) {

        $data = json_decode($_POST['order_data'], true);

        if (!is_array($data)) {
            throw new Exception('Invalid order information.');
        }

        $customer = $data['customer'] ?? [];
        $payment = $data['payment'] ?? [];
        $items = $data['items'] ?? [];

        if (
            !is_array($customer) ||
            !is_array($payment) ||
            !is_array($items)
        ) {
            throw new Exception('Invalid order details.');
        }


        // ------------------------------------------
        // CUSTOMER VALIDATION
        // ------------------------------------------

        $name = trim($customer['name'] ?? '');
        $phone = trim($customer['phone'] ?? '');
        $email = trim($customer['email'] ?? '');
        $address = trim($customer['address'] ?? '');
        $note = trim($customer['note'] ?? '');

        if ($name === '' || $phone === '' || $address === '') {
            throw new Exception(
                'Name, phone and delivery address are required.'
            );
        }

        if (strlen($name) > 100 || strlen($phone) > 20) {
            throw new Exception('Customer information is too long.');
        }

        if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw new Exception(
                'Please enter a valid Bangladesh phone number.'
            );
        }

        if (
            $email !== '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new Exception('Please enter a valid email address.');
        }


        // ------------------------------------------
        // PAYMENT VALIDATION
        // ------------------------------------------

        $allowedPayments = [
            'cod',
            'bkash',
            'nagad',
            'rocket',
            'bank'
        ];

        $paymentMethod = strtolower(
            trim($payment['method'] ?? 'cod')
        );

        if (!in_array($paymentMethod, $allowedPayments, true)) {
            throw new Exception('Invalid payment method.');
        }

        $senderNumber = trim($payment['sender_number'] ?? '');
        $transactionId = trim($payment['transaction_id'] ?? '');

        if ($paymentMethod !== 'cod') {

            if ($senderNumber === '' || $transactionId === '') {
                throw new Exception(
                    'Sender number and transaction ID are required.'
                );
            }

            if (
                strlen($senderNumber) > 40 ||
                strlen($transactionId) > 100
            ) {
                throw new Exception('Payment information is too long.');
            }
        }


        // ------------------------------------------
        // CART VALIDATION
        // ------------------------------------------

        if (count($items) === 0 || count($items) > 50) {
            throw new Exception(
                'Your order has no products or contains too many items.'
            );
        }


        // ------------------------------------------
        // START DATABASE TRANSACTION
        // ------------------------------------------

        $pdo->beginTransaction();

        $subtotal = 0;
        $cleanItems = [];


        // Fetch actual product information
        $productStatement = $pdo->prepare("
            SELECT
                id,
                product_name,
                price,
                sale_price,
                stock,
                image,
                size_options,
                color_options
            FROM products
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");


        // Update stock with separate placeholders
        $stockStatement = $pdo->prepare("
            UPDATE products
            SET stock = stock - :quantity
            WHERE id = :id
            AND stock IS NOT NULL
            AND stock >= :check_quantity
        ");


        foreach ($items as $item) {

            if (!is_array($item)) {
                throw new Exception('Invalid product information.');
            }

            $productId = (int)(
                $item['product_id'] ?? $item['id'] ?? 0
            );

            $quantity = (int)($item['quantity'] ?? 0);

            if (
                $productId <= 0 ||
                $quantity < 1 ||
                $quantity > 10
            ) {
                throw new Exception('Invalid product or quantity.');
            }


            // --------------------------------------
            // FETCH PRODUCT FROM DATABASE
            // --------------------------------------

            $productStatement->execute([
                ':id' => $productId
            ]);

            $product = $productStatement->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception(
                    'A product in your cart is no longer available.'
                );
            }


            // --------------------------------------
            // CALCULATE REAL PRODUCT PRICE
            // --------------------------------------

            $regularPrice = (float)$product['price'];

            $salePrice = $product['sale_price'] !== null
                ? (float)$product['sale_price']
                : 0;

            $actualPrice = (
                $salePrice > 0 &&
                $salePrice < $regularPrice
            ) ? $salePrice : $regularPrice;

            if (!is_finite($actualPrice) || $actualPrice < 0) {
                throw new Exception('Invalid product price.');
            }


            // --------------------------------------
            // CHECK AND UPDATE STOCK
            // --------------------------------------

            if ($product['stock'] !== null) {

                if ((int)$product['stock'] < $quantity) {
                    throw new Exception(
                        $product['product_name'] .
                        ' does not have enough stock.'
                    );
                }

                $stockStatement->execute([
                    ':quantity' => $quantity,
                    ':id' => $productId,
                    ':check_quantity' => $quantity
                ]);

                if ($stockStatement->rowCount() !== 1) {
                    throw new Exception(
                        'Stock changed for ' .
                        $product['product_name'] .
                        '. Please try again.'
                    );
                }
            }


            // --------------------------------------
            // VALIDATE SIZE
            // --------------------------------------

            $selectedSize = trim((string)($item['size'] ?? ''));

            $availableSizes = orderOptions(
                $product['size_options']
            );

            if ($selectedSize !== '') {

                if (
                    count($availableSizes) === 0 ||
                    !in_array($selectedSize, $availableSizes, true)
                ) {
                    throw new Exception(
                        'Invalid size selected for ' .
                        $product['product_name'] . '.'
                    );
                }

            } elseif (count($availableSizes) > 0) {

                throw new Exception(
                    'Please select a size for ' .
                    $product['product_name'] . '.'
                );
            }


            // --------------------------------------
            // VALIDATE COLOR
            // --------------------------------------

            $selectedColor = trim((string)($item['color'] ?? ''));

            $availableColors = orderOptions(
                $product['color_options']
            );

            if ($selectedColor !== '') {

                if (
                    count($availableColors) === 0 ||
                    !in_array($selectedColor, $availableColors, true)
                ) {
                    throw new Exception(
                        'Invalid color selected for ' .
                        $product['product_name'] . '.'
                    );
                }

            } elseif (count($availableColors) > 0) {

                throw new Exception(
                    'Please select a color for ' .
                    $product['product_name'] . '.'
                );
            }


            // --------------------------------------
            // PREPARE ORDER ITEM
            // --------------------------------------

            $lineTotal = round($actualPrice * $quantity, 2);

            $subtotal += $lineTotal;

            $cleanItems[] = [
                'product_id' => $productId,
                'name' => $product['product_name'],
                'image' => $product['image'],
                'price' => $actualPrice,
                'quantity' => $quantity,
                'size' => $selectedSize,
                'color' => $selectedColor,
                'line_total' => $lineTotal
            ];
        }


        // ------------------------------------------
        // CALCULATE TOTAL
        // ------------------------------------------

        $subtotal = round($subtotal, 2);

        $delivery = STORE_DELIVERY_CHARGE;

        $total = round($subtotal + $delivery, 2);


        // ------------------------------------------
        // GENERATE ORDER ID
        // ------------------------------------------

        $orderId = 'LSBD-' . date('ymd') . '-' .
            strtoupper(bin2hex(random_bytes(4)));


        // ------------------------------------------
        // SAVE MAIN ORDER
        // ------------------------------------------

        $orderStatement = $pdo->prepare("
            INSERT INTO orders (
                order_id,
                order_number,
                customer_name,
                phone,
                email,
                address,
                note,
                payment_method,
                sender_number,
                transaction_id,
                subtotal,
                delivery_charge,
                total_amount,
                status
            ) VALUES (
                :order_id,
                :order_number,
                :customer_name,
                :phone,
                :email,
                :address,
                :note,
                :payment_method,
                :sender_number,
                :transaction_id,
                :subtotal,
                :delivery_charge,
                :total_amount,
                'pending'
            )
        ");

        $orderStatement->execute([
            ':order_id' => $orderId,
            ':order_number' => $orderId,
            ':customer_name' => $name,
            ':phone' => $phone,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address,
            ':note' => $note !== '' ? $note : null,
            ':payment_method' => $paymentMethod,
            ':sender_number' => $paymentMethod !== 'cod'
                ? $senderNumber
                : null,
            ':transaction_id' => $paymentMethod !== 'cod'
                ? $transactionId
                : null,
            ':subtotal' => $subtotal,
            ':delivery_charge' => $delivery,
            ':total_amount' => $total
        ]);


        // This is the numeric orders.id
        $databaseOrderId = (int)$pdo->lastInsertId();


        // ------------------------------------------
        // SAVE ORDER ITEMS
        // ------------------------------------------

        $itemStatement = $pdo->prepare("
            INSERT INTO order_items (
                order_id,
                product_id,
                product_name,
                product_image,
                price,
                quantity,
                size,
                color,
                line_total
            ) VALUES (
                :order_id,
                :product_id,
                :product_name,
                :product_image,
                :price,
                :quantity,
                :size,
                :color,
                :line_total
            )
        ");

        foreach ($cleanItems as $item) {

            $itemStatement->execute([
                ':order_id' => $databaseOrderId,
                ':product_id' => $item['product_id'],
                ':product_name' => $item['name'],
                ':product_image' => $item['image'],
                ':price' => $item['price'],
                ':quantity' => $item['quantity'],
                ':size' => $item['size'] !== ''
                    ? $item['size']
                    : null,
                ':color' => $item['color'] !== ''
                    ? $item['color']
                    : null,
                ':line_total' => $item['line_total']
            ]);
        }


        // ------------------------------------------
        // COMMIT DATABASE ORDER
        // ------------------------------------------

        $pdo->commit();


        // ------------------------------------------
        // PREPARE CONFIRMATION DATA
        // ------------------------------------------

        $order = [
            'order_id' => $orderId,

            'customer' => [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'note' => $note
            ],

            'payment' => [
                'method' => $paymentMethod,
                'sender_number' => $senderNumber,
                'transaction_id' => $transactionId
            ],

            'items' => $cleanItems,
            'subtotal' => $subtotal,
            'delivery' => $delivery,
            'total' => $total
        ];


        // ------------------------------------------
        // SAVE ORDER IN SESSION
        // ------------------------------------------

        $_SESSION['pending_order'] = $order;


        // ------------------------------------------
        // SEND CUSTOMER AND ADMIN EMAILS
        // ------------------------------------------

        try {

            sendOrderEmails($order);

        } catch (Throwable $mailError) {

            // Never cancel a successfully saved order
            error_log(
                'Leather Store BD Email Error: ' .
                $mailError->getMessage()
            );
        }


        // ------------------------------------------
        // REDIRECT TO PREVENT FORM RESUBMISSION
        // ------------------------------------------

        header('Location: place-order.php?success=1');
        exit;

    } else {

        // ------------------------------------------
        // SHOW SAVED ORDER CONFIRMATION
        // ------------------------------------------

        $order = $_SESSION['pending_order'] ?? null;

        if (!$order) {
            $errorMessage =
                'No order information was found. Please place your order again.';
        }
    }

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Leather Store BD Order Error: ' .
        $e->getMessage()
    );

    $errorMessage = $e->getMessage();
}


// ==================================================
// PAGE OUTPUT
// ==================================================

include __DIR__ . '/includes/header.php';
?>

<main class="order-confirmation-page">

    <section class="order-confirmation-container">

        <?php if ($order && empty($errorMessage)): ?>

            <div class="order-confirmation-success">

                <div class="order-success-icon">✓</div>

                <h1>Thank You for Your Order!</h1>

                <p>
                    Your order has been saved successfully.
                    We will contact you shortly.
                </p>

                <div class="order-number-box">

                    <span>Your Order ID</span>

                    <strong id="orderId">
                        <?= orderEscape($order['order_id']) ?>
                    </strong>

                    <button type="button" id="copyOrderId">
                        Copy Order ID
                    </button>

                </div>

            </div>


            <div class="order-confirmation-details">

                <h2>Order Details</h2>

                <div class="order-customer-details">

                    <p>
                        <strong>Name:</strong>
                        <?= orderEscape($order['customer']['name']) ?>
                    </p>

                    <p>
                        <strong>Phone:</strong>
                        <?= orderEscape($order['customer']['phone']) ?>
                    </p>

                    <p>
                        <strong>Email:</strong>
                        <?= orderEscape($order['customer']['email'] ?: 'Not provided') ?>
                    </p>

                    <p>
                        <strong>Address:</strong>
                        <?= orderEscape($order['customer']['address']) ?>
                    </p>

                    <p>
                        <strong>Payment:</strong>
                        <?= orderEscape(
                            orderPaymentName($order['payment']['method'])
                        ) ?>
                    </p>

                    <?php if ($order['payment']['method'] !== 'cod'): ?>

                        <p>
                            <strong>Sender Number:</strong>
                            <?= orderEscape(
                                $order['payment']['sender_number']
                            ) ?>
                        </p>

                        <p>
                            <strong>Transaction ID:</strong>
                            <?= orderEscape(
                                $order['payment']['transaction_id']
                            ) ?>
                        </p>

                    <?php endif; ?>

                </div>


                <h3>Products</h3>

                <div class="order-product-list">

                    <?php foreach ($order['items'] as $item): ?>

                        <div class="order-product-row">

                            <div>

                                <strong>
                                    <?= orderEscape($item['name']) ?>
                                </strong>

                                <p>
                                    Color:
                                    <?= orderEscape($item['color'] ?: 'Standard') ?>
                                </p>

                                <p>
                                    Size:
                                    <?= orderEscape($item['size'] ?: 'Standard') ?>
                                </p>

                                <p>
                                    Quantity:
                                    <?= (int)$item['quantity'] ?>
                                </p>

                            </div>

                            <strong>
                                ৳<?= number_format(
                                    (float)$item['line_total'],
                                    2
                                ) ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                </div>


                <div class="order-total-details">

                    <p>
                        Subtotal:

                        <strong>
                            ৳<?= number_format(
                                (float)$order['subtotal'],
                                2
                            ) ?>
                        </strong>
                    </p>

                    <p>
                        Delivery:

                        <strong>
                            ৳<?= number_format(
                                (float)$order['delivery'],
                                2
                            ) ?>
                        </strong>
                    </p>

                    <p class="order-grand-total">

                        Total:

                        <strong>
                            ৳<?= number_format(
                                (float)$order['total'],
                                2
                            ) ?>
                        </strong>

                    </p>

                </div>

            </div>


            <div class="order-confirmation-actions">

                <a href="shop.php" class="order-continue-shopping">
                    Continue Shopping
                </a>

                <a href="index.php" class="order-home-button">
                    Back to Home
                </a>

            </div>


        <?php else: ?>

            <div class="order-error-message">

                <h1>Order Could Not Be Completed</h1>

                <p><?= orderEscape($errorMessage) ?></p>

                <a href="checkout.php">
                    Return to Checkout
                </a>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
