
/* =========================================
   LEATHER STORE BD - GLOBAL HELPERS
========================================= */

const LSBD_CART_KEY = "leatherStoreCart";
const LSBD_BUY_NOW_KEY = "leatherStoreBuyNow";
const LSBD_IMAGE_PATH = "/leather-store-bd/assets/images/";

function lsbdGetCart() {
    try {
        const cart = JSON.parse(localStorage.getItem(LSBD_CART_KEY));
        return Array.isArray(cart) ? cart : [];
    } catch (error) {
        return [];
    }
}

function lsbdSaveCart(cart) {
    localStorage.setItem(LSBD_CART_KEY, JSON.stringify(cart));
    window.dispatchEvent(new Event("lsbd-cart-updated"));
}

function lsbdEscapeHTML(value) {
    return String(value ?? "").replace(/[&<>"']/g, function (character) {
        return {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"
        }[character];
    });
}

function lsbdMoney(amount) {
    return "৳ " + Number(amount || 0).toLocaleString("en-BD");
}

function lsbdProductImage(id) {
    const images = {
        "1": "product-wallet-1.jpg",
        "2": "product-belt-1.jpg",
        "3": "product-bag-1.jpg",
        "4": "product-shoes-1.jpg",
        "5": "product-wallet-2.jpg",
        "6": "product-belt-2.jpg",
        "7": "product-bag-2.jpg",
        "8": "product-shoes-2.jpg"
    };

    return LSBD_IMAGE_PATH + (images[String(id)] || "product-wallet-1.jpg");
}


/* =========================================
   HOME PAGE JAVASCRIPT
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const homePage = document.querySelector(".home-page");

    if (!homePage) return;

    const revealElements = homePage.querySelectorAll(
        ".home-intro, .home-category-card, .home-featured, " +
        ".home-brand-banner-content, .home-benefit-card, .home-final-cta"
    );

    revealElements.forEach(function (element) {
        element.classList.add("home-reveal");
    });

    const revealStyle = document.createElement("style");

    revealStyle.textContent = `
        .home-page .home-reveal {
            opacity: 0;
            transform: translateY(25px);
            transition: opacity .7s ease, transform .7s ease;
        }

        .home-page .home-reveal.home-visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (prefers-reduced-motion: reduce) {
            .home-page .home-reveal {
                opacity: 1;
                transform: none;
                transition: none;
            }
        }
    `;

    document.head.appendChild(revealStyle);

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add("home-visible");
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        revealElements.forEach(function (element) {
            observer.observe(element);
        });
    } else {
        revealElements.forEach(function (element) {
            element.classList.add("home-visible");
        });
    }
});


/* =========================================
   SHOP PAGE - SEARCH, FILTER & SORT
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const shopPage = document.querySelector(".shop-page");

    if (!shopPage) return;

    const grid = shopPage.querySelector("#shopProductGrid");
    const searchInput = shopPage.querySelector("#shopSearch");
    const searchButton = shopPage.querySelector("#shopSearchBtn");
    const categorySelect = shopPage.querySelector("#shopCategory");
    const sortSelect = shopPage.querySelector("#shopSort");
    const noResults = shopPage.querySelector("#shopNoResults");
    const count = shopPage.querySelector(".shop-product-count span");

    if (!grid) return;

    const cards = Array.from(grid.querySelectorAll(".shop-product-card"));

    function updateProducts() {
        const searchText = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const category = categorySelect ? categorySelect.value : "all";
        const sort = sortSelect ? sortSelect.value : "default";

        let visibleCards = cards.filter(function (card) {
            const name = (card.dataset.name || "").toLowerCase();
            const cardCategory = card.dataset.category || "";

            return name.includes(searchText) &&
                (category === "all" || cardCategory === category);
        });

        visibleCards.sort(function (a, b) {
            const priceA = Number(a.dataset.price) || 0;
            const priceB = Number(b.dataset.price) || 0;
            const nameA = (a.dataset.name || "").toLowerCase();
            const nameB = (b.dataset.name || "").toLowerCase();

            if (sort === "low-high") return priceA - priceB;
            if (sort === "high-low") return priceB - priceA;
            if (sort === "name") return nameA.localeCompare(nameB);

            return cards.indexOf(a) - cards.indexOf(b);
        });

        cards.forEach(function (card) {
            card.hidden = true;
        });

        visibleCards.forEach(function (card) {
            card.hidden = false;
            grid.appendChild(card);
        });

        if (noResults) {
            noResults.hidden = visibleCards.length !== 0;
        }

        if (count) {
            count.textContent =
                String(visibleCards.length).padStart(2, "0") +
                " — " +
                String(cards.length).padStart(2, "0");
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", updateProducts);
    }

    if (searchButton) {
        searchButton.addEventListener("click", updateProducts);
    }

    if (categorySelect) {
        categorySelect.addEventListener("change", updateProducts);
    }

    if (sortSelect) {
        sortSelect.addEventListener("change", updateProducts);
    }

    // Read search/category from URL if supplied.
    const params = new URLSearchParams(window.location.search);

    if (searchInput && params.has("search")) {
        searchInput.value = params.get("search");
    }

    if (categorySelect && params.has("category")) {
        categorySelect.value = params.get("category");
    }

    updateProducts();
});


/* =========================================
   PRODUCT DETAILS PAGE
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const productPage = document.querySelector(".product-page");

    if (!productPage) return;

    const mainImage = productPage.querySelector("#mainProductImage");
    const imageContainer = productPage.querySelector("#productMainImage");
    const zoomButton = productPage.querySelector("#productZoom");
    const thumbnails = productPage.querySelectorAll(".product-thumbnail");

    // Product image gallery
    thumbnails.forEach(function (thumbnail) {
        thumbnail.addEventListener("click", function () {
            if (mainImage && this.dataset.image) {
                mainImage.src = this.dataset.image;
            }

            thumbnails.forEach(function (item) {
                item.classList.remove("active");
            });

            this.classList.add("active");

            if (imageContainer) {
                imageContainer.classList.remove("zoomed");
            }

            if (zoomButton) {
                zoomButton.textContent = "+";
            }
        });
    });

    // Image zoom
    if (zoomButton && imageContainer) {
        zoomButton.addEventListener("click", function (event) {
            event.preventDefault();
            imageContainer.classList.toggle("zoomed");
            zoomButton.textContent =
                imageContainer.classList.contains("zoomed") ? "−" : "+";
        });
    }

    // Quantity controls
    const quantityInput = productPage.querySelector("#productQuantity");
    const minusButton = productPage.querySelector("#quantityMinus");
    const plusButton = productPage.querySelector("#quantityPlus");

    if (minusButton && quantityInput) {
        minusButton.addEventListener("click", function (event) {
            event.preventDefault();
            const value = Number(quantityInput.value) || 1;
            quantityInput.value = Math.max(1, value - 1);
        });
    }

    if (plusButton && quantityInput) {
        plusButton.addEventListener("click", function (event) {
            event.preventDefault();
            const value = Number(quantityInput.value) || 1;
            quantityInput.value = Math.min(10, value + 1);
        });
    }

    if (quantityInput) {
        quantityInput.addEventListener("change", function () {
            const value = Number(this.value) || 1;
            this.value = Math.min(10, Math.max(1, value));
        });
    }

    // Product selection
    const form = productPage.querySelector("#productOrderForm");
    const colorSelect = productPage.querySelector("#productColor");
    const sizeSelect = productPage.querySelector("#productSize");

    function getProductSelection() {
        if (!form) {
            alert("Product form could not be found.");
            return null;
        }

        if (colorSelect && !colorSelect.value) {
            alert("Please select a product color.");
            colorSelect.focus();
            return null;
        }

        if (sizeSelect && !sizeSelect.value) {
            alert("Please select a product size.");
            sizeSelect.focus();
            return null;
        }

        const idField = form.querySelector('[name="product_id"]');
        const nameField = form.querySelector('[name="product_name"]');
        const priceField = form.querySelector('[name="product_price"]');

        if (!idField || !nameField || !priceField) {
            alert("Product information is incomplete. Please check product.php.");
            return null;
        }

        return {
            id: String(idField.value),
            name: nameField.value,
            price: Number(priceField.value),
            color: colorSelect ? colorSelect.value : "",
            size: sizeSelect ? sizeSelect.value : "",
            quantity: quantityInput
                ? Math.min(10, Math.max(1, Number(quantityInput.value) || 1))
                : 1
        };
    }

    // Add to cart
    const addButton = productPage.querySelector("#addToCartButton");

    if (addButton) {
        addButton.addEventListener("click", function (event) {
            event.preventDefault();

            const product = getProductSelection();
            if (!product) return;

            const cart = lsbdGetCart();

            const existing = cart.find(function (item) {
                return String(item.id) === product.id &&
                    item.color === product.color &&
                    item.size === product.size;
            });

            if (existing) {
                existing.quantity = Math.min(
                    10,
                    (Number(existing.quantity) || 1) + product.quantity
                );
            } else {
                cart.push(product);
            }

            lsbdSaveCart(cart);
            alert("Product added to your cart!");
        });
    }

    // Buy Now: send selected product to checkout
    const buyButton = productPage.querySelector("#buyNowButton");

    if (buyButton) {
        buyButton.addEventListener("click", function (event) {
            event.preventDefault();

            const product = getProductSelection();
            if (!product) return;

            // Checkout reads leatherStoreCart, so put this product there.
            lsbdSaveCart([product]);

            localStorage.setItem(
                LSBD_BUY_NOW_KEY,
                JSON.stringify(product)
            );

            window.location.href = "checkout.php";
        });
    }
});


/* =========================================
   GLOBAL HEADER, MOBILE MENU & CART COUNT
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const menuToggle = document.getElementById("mobileMenuToggle");
    const navigation = document.getElementById("mainNavigation");

    if (menuToggle && navigation) {
        menuToggle.addEventListener("click", function () {
            const isOpen = navigation.classList.toggle("open");

            menuToggle.classList.toggle("active", isOpen);
            menuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
            menuToggle.setAttribute(
                "aria-label",
                isOpen ? "Close navigation" : "Open navigation"
            );
        });

        navigation.querySelectorAll("a").forEach(function (link) {
            link.addEventListener("click", function () {
                navigation.classList.remove("open");
                menuToggle.classList.remove("active");
                menuToggle.setAttribute("aria-expanded", "false");
            });
        });
    }

    function updateHeaderCartCount() {
        const countElement = document.getElementById("headerCartCount");
        if (!countElement) return;

        const cart = lsbdGetCart();

        const count = cart.reduce(function (total, item) {
            return total + (Number(item.quantity) || 1);
        }, 0);

        countElement.textContent = count;
    }

    updateHeaderCartCount();

    window.addEventListener("storage", updateHeaderCartCount);
    window.addEventListener("lsbd-cart-updated", updateHeaderCartCount);
});


/* =========================================
   SHOPPING CART PAGE
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const cartPage = document.querySelector(".cart-page");

    if (!cartPage) return;

    const cartItemsContainer = document.getElementById("cartItems");
    const emptyMessage = document.getElementById("cartEmpty");
    const subtotalElement = document.getElementById("cartSubtotal");
    const deliveryElement = document.getElementById("cartDelivery");
    const grandTotalElement = document.getElementById("cartGrandTotal");
    const checkoutButton = document.getElementById("cartCheckoutButton");

    if (
        !cartItemsContainer || !emptyMessage || !subtotalElement ||
        !deliveryElement || !grandTotalElement || !checkoutButton
    ) {
        console.error("Cart page is missing one or more required HTML elements.");
        return;
    }

    const DELIVERY_CHARGE = 80;

    function renderCart() {
        const cart = lsbdGetCart();

        cartItemsContainer.innerHTML = "";

        if (cart.length === 0) {
            emptyMessage.hidden = false;
            cartItemsContainer.hidden = true;
            deliveryElement.textContent = lsbdMoney(0);
            subtotalElement.textContent = lsbdMoney(0);
            grandTotalElement.textContent = lsbdMoney(0);
            checkoutButton.disabled = true;
            return;
        }

        emptyMessage.hidden = true;
        cartItemsContainer.hidden = false;
        checkoutButton.disabled = false;

        let subtotal = 0;

        cart.forEach(function (item, index) {
            const quantity = Math.max(1, Number(item.quantity) || 1);
            const price = Number(item.price) || 0;
            const total = price * quantity;

            subtotal += total;

            const row = document.createElement("div");
            row.className = "cart-item";

            row.innerHTML = `
                <div class="cart-product-info">
                    <img src="${lsbdProductImage(item.id)}"
                         alt="${lsbdEscapeHTML(item.name)}">
                    <div class="cart-product-details">
                        <h3>${lsbdEscapeHTML(item.name)}</h3>
                        <p>Color: ${lsbdEscapeHTML(item.color || "Not selected")}</p>
                        <p>Size: ${lsbdEscapeHTML(item.size || "Standard")}</p>
                        <button class="cart-remove-button"
                                type="button"
                                data-action="remove"
                                data-index="${index}">
                            Remove
                        </button>
                    </div>
                </div>

                <div class="cart-item-price">${lsbdMoney(price)}</div>

                <div class="cart-item-quantity">
                    <button type="button" data-action="minus"
                            data-index="${index}">−</button>
                    <span>${quantity}</span>
                    <button type="button" data-action="plus"
                            data-index="${index}">+</button>
                </div>

                <div class="cart-item-total">${lsbdMoney(total)}</div>
            `;

            cartItemsContainer.appendChild(row);
        });

        const delivery = DELIVERY_CHARGE;

        subtotalElement.textContent = lsbdMoney(subtotal);
        deliveryElement.textContent = lsbdMoney(delivery);
        grandTotalElement.textContent = lsbdMoney(subtotal + delivery);
    }

    cartItemsContainer.addEventListener("click", function (event) {
        const button = event.target.closest("button[data-action]");
        if (!button) return;

        const index = Number(button.dataset.index);
        const action = button.dataset.action;
        const cart = lsbdGetCart();

        if (!cart[index]) return;

        if (action === "remove") {
            cart.splice(index, 1);
        } else if (action === "plus") {
            cart[index].quantity = Math.min(
                10,
                (Number(cart[index].quantity) || 1) + 1
            );
        } else if (action === "minus") {
            cart[index].quantity = Math.max(
                1,
                (Number(cart[index].quantity) || 1) - 1
            );
        }

        lsbdSaveCart(cart);
        renderCart();
    });

    checkoutButton.addEventListener("click", function () {
        const cart = lsbdGetCart();

        if (cart.length === 0) {
            alert("Your cart is empty.");
            return;
        }

        window.location.href = "checkout.php";
    });

    renderCart();
});


/* =========================================
   CHECKOUT PAGE JAVASCRIPT
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const checkoutPage = document.querySelector(".checkout-page");

    if (!checkoutPage) return;

    const form = document.getElementById("checkoutForm");
    const paymentDetails = document.getElementById("mobilePaymentDetails");
    const senderNumber = document.getElementById("senderNumber");
    const transactionId = document.getElementById("transactionId");

    if (!form) {
        console.error("Checkout form #checkoutForm was not found.");
        return;
    }

    const DELIVERY_CHARGE = 80;

    function getCheckoutCart() {
        // Buy Now product takes priority on the checkout page.
        try {
            const buyNow = JSON.parse(localStorage.getItem(LSBD_BUY_NOW_KEY));

            if (buyNow && buyNow.id) {
                return [buyNow];
            }
        } catch (error) {
            // Fall back to normal cart.
        }

        return lsbdGetCart();
    }

    // Display checkout cart
    function renderCheckoutCart() {
        const cart = getCheckoutCart();
        const container = document.getElementById("checkoutCartItems");
        const submitButton = document.getElementById("placeOrderButton");

        if (!container || !submitButton) {
            console.error("Checkout summary elements are missing.");
            return;
        }

        let subtotal = 0;
        container.innerHTML = "";

        cart.forEach(function (item) {
            const quantity = Number(item.quantity) || 1;
            const price = Number(item.price) || 0;
            const total = quantity * price;

            subtotal += total;

            const row = document.createElement("div");
            row.className = "checkout-cart-item";

            row.innerHTML = `
                <img src="${lsbdProductImage(item.id)}"
                     alt="${lsbdEscapeHTML(item.name)}">

                <div class="checkout-cart-item-info">
                    <h4>${lsbdEscapeHTML(item.name)}</h4>
                    <p>Qty: ${quantity} | ${lsbdEscapeHTML(item.color || "Standard")}</p>
                    <p>Size: ${lsbdEscapeHTML(item.size || "Standard")}</p>
                </div>

                <strong class="checkout-cart-item-price">
                    ${lsbdMoney(total)}
                </strong>
            `;

            container.appendChild(row);
        });

        const delivery = cart.length ? DELIVERY_CHARGE : 0;

        const subtotalElement = document.getElementById("checkoutSubtotal");
        const deliveryElement = document.getElementById("checkoutDelivery");
        const totalElement = document.getElementById("checkoutGrandTotal");

        if (subtotalElement) subtotalElement.textContent = lsbdMoney(subtotal);
        if (deliveryElement) deliveryElement.textContent = lsbdMoney(delivery);
        if (totalElement) totalElement.textContent = lsbdMoney(subtotal + delivery);

        submitButton.disabled = cart.length === 0;

        if (cart.length === 0) {
            container.innerHTML =
                '<p>Your cart is empty. <a href="shop.php">Continue shopping</a></p>';
        }
    }

    // Payment selection
    function updatePaymentDetails() {
        const selected = form.querySelector(
            'input[name="payment_method"]:checked'
        );

        const needsDetails = Boolean(selected && selected.value !== "cod");

        if (paymentDetails) {
            paymentDetails.hidden = !needsDetails;
        }

        if (senderNumber) {
            senderNumber.required = needsDetails;
        }

        if (transactionId) {
            transactionId.required = needsDetails;
        }
    }

    form.querySelectorAll('input[name="payment_method"]').forEach(
        function (radio) {
            radio.addEventListener("change", updatePaymentDetails);
        }
    );

    // Submit order
    form.addEventListener("submit", function (event) {
        event.preventDefault();

        const cart = getCheckoutCart();

        if (cart.length === 0) {
            alert("Your cart is empty. Please add products first.");
            window.location.href = "shop.php";
            return;
        }

        const name = document.getElementById("customerName");
        const phone = document.getElementById("customerPhone");
        const email = document.getElementById("customerEmail");
        const address = document.getElementById("deliveryAddress");
        const note = document.getElementById("orderNote");

        if (!name || !phone || !email || !address) {
            alert("Some checkout fields are missing. Please check checkout.php.");
            return;
        }

        const phonePattern = /^01[3-9]\d{8}$/;

        if (!name.value.trim()) {
            alert("Please enter your full name.");
            name.focus();
            return;
        }

        if (!phonePattern.test(phone.value.trim())) {
            alert("Please enter a valid Bangladeshi phone number.");
            phone.focus();
            return;
        }

        if (email.value.trim() && !email.validity.valid) {
            alert("Please enter a valid email address.");
            email.focus();
            return;
        }

        if (!address.value.trim()) {
            alert("Please enter your delivery address.");
            address.focus();
            return;
        }

        const selectedPayment = form.querySelector(
            'input[name="payment_method"]:checked'
        );

        if (!selectedPayment) {
            alert("Please select a payment method.");
            return;
        }

        if (selectedPayment.value !== "cod") {
            if (!senderNumber || !transactionId) {
                alert("Payment details fields are missing.");
                return;
            }

            if (!phonePattern.test(senderNumber.value.trim())) {
                alert("Please enter a valid sender number.");
                senderNumber.focus();
                return;
            }

            if (!transactionId.value.trim()) {
                alert("Please enter your transaction ID.");
                transactionId.focus();
                return;
            }
        }

        const subtotal = cart.reduce(function (sum, item) {
            return sum +
                (Number(item.price) || 0) *
                (Number(item.quantity) || 1);
        }, 0);

        const delivery = DELIVERY_CHARGE;

        const pendingOrder = {
            customer: {
                name: name.value.trim(),
                phone: phone.value.trim(),
                email: email.value.trim(),
                address: address.value.trim(),
                note: note ? note.value.trim() : ""
            },
            payment: {
                method: selectedPayment.value,
                sender_number: senderNumber ? senderNumber.value.trim() : "",
                transaction_id: transactionId ? transactionId.value.trim() : ""
            },
            items: cart,
            subtotal: subtotal,
            delivery: delivery,
            total: subtotal + delivery,
            created_at: new Date().toISOString()
        };

        localStorage.setItem(
            "leatherStorePendingOrder",
            JSON.stringify(pendingOrder)
        );

        // Send order data to PHP using POST.
      const orderForm = document.createElement("form");
orderForm.method = "POST";
orderForm.action = "/leather-store-bd/place-order.php";
        const orderInput = document.createElement("input");
        orderInput.type = "hidden";
        orderInput.name = "order_data";
        orderInput.value = JSON.stringify(pendingOrder);

        orderForm.appendChild(orderInput);
        document.body.appendChild(orderForm);

        orderForm.submit();
    });

    updatePaymentDetails();
    renderCheckoutCart();
});


/* =========================================
   ORDER CONFIRMATION PAGE
========================================= */

document.addEventListener("DOMContentLoaded", function () {
    const copyButton = document.getElementById("copyOrderId");
    const orderIdElement = document.getElementById("orderId");

    if (copyButton && orderIdElement) {
        copyButton.addEventListener("click", function () {
            const orderId = orderIdElement.textContent.trim();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(orderId).then(function () {
                    alert("Order ID copied!");
                }).catch(function () {
                    alert("Could not copy the Order ID.");
                });
            } else {
                alert("Your Order ID is: " + orderId);
            }
        });
    }

    if (document.querySelector(".order-confirmation-page")) {
        localStorage.removeItem(LSBD_CART_KEY);
        localStorage.removeItem(LSBD_BUY_NOW_KEY);
        window.dispatchEvent(new Event("lsbd-cart-updated"));
    }
});
/* =================================
   EXPLORE PAGE JAVASCRIPT
================================= */

document.addEventListener("DOMContentLoaded", function () {

    const exploreElements = document.querySelectorAll(
        ".explore-category-card, .explore-statement-content, .explore-featured-content, .explore-cta"
    );

    // Scroll Animation
    if ("IntersectionObserver" in window) {

        const exploreObserver = new IntersectionObserver(
            function (entries, observer) {

                entries.forEach(function (entry) {

                    if (entry.isIntersecting) {
                        entry.target.classList.add("explore-visible");
                        observer.unobserve(entry.target);
                    }

                });

            },
            {
                threshold: 0.15
            }
        );

        exploreElements.forEach(function (element) {
            element.classList.add("explore-animate");
            exploreObserver.observe(element);
        });

    } else {

        exploreElements.forEach(function (element) {
            element.classList.add("explore-visible");
        });

    }

    // Category Card Click Protection
    const categoryCards = document.querySelectorAll(
        ".explore-category-card"
    );

    categoryCards.forEach(function (card) {

        card.addEventListener("click", function () {

            const destination = card.getAttribute("href");

            if (destination) {
                window.location.href = destination;
            }

        });

    });

});

/* =================================
   ABOUT PAGE ANIMATIONS
================================= */

document.addEventListener("DOMContentLoaded", function () {

    const aboutSections = document.querySelectorAll(
        ".about-story, .about-value-card, .about-cta"
    );

    if (!("IntersectionObserver" in window)) {
        aboutSections.forEach(function (section) {
            section.classList.add("about-visible");
        });
        return;
    }

    const aboutObserver = new IntersectionObserver(
        function (entries, observer) {

            entries.forEach(function (entry) {

                if (entry.isIntersecting) {
                    entry.target.classList.add("about-visible");
                    observer.unobserve(entry.target);
                }

            });

        },
        {
            threshold: 0.15
        }
    );

    aboutSections.forEach(function (section) {
        section.classList.add("about-animate");
        aboutObserver.observe(section);
    });

});

function goBackPage() {
    if (document.referrer &&
        new URL(document.referrer).origin === window.location.origin) {
        window.history.back();
    } else {
        window.location.href = "/leather-store-bd/index.php";
    }
}