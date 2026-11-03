<?php
$page_title = "Contact Us | Leather Store BD";
include 'includes/header.php';
?>

<main class="contact-page">

    <!-- Contact Hero -->
    <section class="contact-hero">
        <div class="contact-hero-content">
            <span class="contact-tag">GET IN TOUCH</span>
            <h1>We'd Love To<br>Hear From You.</h1>
            <p>
                Have a question about our products or your order?
                Our team is here to help.
            </p>
        </div>
    </section>

    <!-- Contact Information -->
    <section class="contact-main">

        <div class="contact-info">

            <span class="contact-tag">CONTACT INFORMATION</span>
            <h2>Let's Talk</h2>

            <p class="contact-description">
                Reach out to us through any of the following channels.
            </p>

            <div class="contact-info-item">
                <div class="contact-icon">☎</div>
                <div>
                    <h3>Phone / WhatsApp</h3>
                    <a href="tel:+8801827189625">
                        +880 1827-189625
                    </a>
                </div>
            </div>

            <div class="contact-info-item">
                <div class="contact-icon">✉</div>
                <div>
                    <h3>Email Address</h3>
                    <a href="mailto:leatherstorebd@gmail.com">
                        leatherstorebd@gmail.com
                    </a>
                </div>
            </div>

            <div class="contact-info-item">
                <div class="contact-icon">⌖</div>
                <div>
                    <h3>Our Address</h3>
                    <p>
                        3rd Floor, Hello Biz IT, Kalir Bazar,
                        Fulchari, Gaibandha, Bangladesh – 5760
                    </p>
                </div>
            </div>

            <a
                href="https://wa.me/8801827189625"
                target="_blank"
                rel="noopener"
                class="contact-whatsapp-btn"
            >
                Chat on WhatsApp
            </a>

        </div>

        <!-- Contact Form -->
        <div class="contact-form-box">

            <span class="contact-tag">SEND A MESSAGE</span>
            <h2>How Can We Help?</h2>

            <form id="contactForm" action="#" method="post">

                <div class="contact-form-group">
                    <label for="contactName">Your Name</label>
                    <input
                        type="text"
                        id="contactName"
                        name="name"
                        placeholder="Enter your full name"
                        required
                    >
                </div>

                <div class="contact-form-group">
                    <label for="contactEmail">Email Address</label>
                    <input
                        type="email"
                        id="contactEmail"
                        name="email"
                        placeholder="Enter your email"
                        required
                    >
                </div>

                <div class="contact-form-group">
                    <label for="contactPhone">Phone Number</label>
                    <input
                        type="tel"
                        id="contactPhone"
                        name="phone"
                        placeholder="01XXXXXXXXX"
                        required
                    >
                </div>

                <div class="contact-form-group">
                    <label for="contactSubject">Subject</label>
                    <input
                        type="text"
                        id="contactSubject"
                        name="subject"
                        placeholder="Message subject"
                        required
                    >
                </div>

                <div class="contact-form-group">
                    <label for="contactMessage">Your Message</label>
                    <textarea
                        id="contactMessage"
                        name="message"
                        rows="5"
                        placeholder="Write your message here..."
                        required
                    ></textarea>
                </div>

                <button type="submit" class="contact-submit-btn">
                    Send Message →
                </button>

                <p id="contactFormMessage" class="contact-form-message"></p>

            </form>

        </div>

    </section>

    <!-- Google Maps -->
    <section class="contact-map-section">

        <div class="contact-map-heading">
            <span class="contact-tag">FIND US</span>
            <h2>Visit Our Location</h2>
        </div>

        <div class="contact-map">
            <iframe
                src="https://www.google.com/maps?q=Kalir%20Bazar%2C%20Fulchari%2C%20Gaibandha%2C%20Bangladesh&output=embed"
                width="100%"
                height="400"
                style="border:0;"
                allowfullscreen=""
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="Leather Store BD Location"
            ></iframe>
        </div>

        <a
            href="https://www.google.com/maps/search/?api=1&query=Kalir+Bazar+Fulchari+Gaibandha+Bangladesh"
            target="_blank"
            rel="noopener"
            class="contact-map-link"
        >
            Open in Google Maps →
        </a>

    </section>

</main>

<?php include 'includes/footer.php'; ?>