<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Showroom | SEVILLA360</title>

    <!-- Master Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Page-Specific Stylesheet -->
    <link rel="stylesheet" href="assets/css/showroom.css">
</head>

<body>

    <!-- Header / Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="navbar-brand">Sevilla360</a>

            <!-- Hamburger Icon -->
            <div class="hamburger" id="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <!-- Nav Links -->
            <div class="nav-links" id="nav-links">
                <a href="index.php">Home</a>
                <a href="index.php#about">About</a>
                <a href="index.php#events">Events</a>
                <a href="index.php#accommodations">Accommodations</a>
                <a href="showroom.php" style="color: var(--color-gold);">Virtual Showroom</a>
                <a href="login.php" class="btn btn-primary">Login / Register</a>
            </div>
        </div>
    </nav>

    <!-- Showroom Container -->
    <section class="showroom-wrapper" id="showroom-wrapper">
        <div class="showroom-container">

            <!-- 1. The Big Viewer Box (Acts as both 360 Viewer AND Photo Gallery) -->
            <div class="big-viewer-box">

                <!-- === 360 UI Elements (Visible by Default) === -->
                <div class="viewer-label ui-360">Showroom</div>
                <div class="viewer-controls ui-360">
                    <button>+</button>
                    <button>-</button>
                    <button>⛶</button>
                    <button>🧭</button>
                </div>
                <!-- Injection point for Marzipano -->
                <div id="pano-container" class="ui-360" style="width:100%; height:100%;"></div>


                <!-- === Photo Gallery UI Elements (Hidden by Default) === -->
                <div class="photo-room-title ui-photos" id="gallery-title">Event Hall</div>

                <button class="slider-arrow left ui-photos" id="slide-prev">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7" />
                    </svg>
                </button>

                <button class="slider-arrow right ui-photos" id="slide-next">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </button>

                <!-- Image Container -->
                <div class="slider-image-container ui-photos">
                    <!-- Example image placeholder -->
                    <img src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=1920&q=80"
                        alt="Room Photo" id="current-slide-img">
                </div>

                <!-- Back Button -->
                <button class="btn-back ui-photos" id="btn-back-to-360">Back</button>


                <!-- === Shared Elements (Always Visible in both views) === -->
                <div class="room-pills">
                    <button class="pill active" data-room="infinity">Infinity Hall</button>
                    <button class="pill" data-room="villa">Villa</button>
                    <button class="pill" data-room="standard">Standard Room</button>
                    <button class="pill" data-room="deluxe">Deluxe Room</button>
                    <button class="pill" data-room="family">Family Room</button>
                </div>
            </div>

            <!-- 2. The Details Block -->
            <div class="details-box">
                <!-- Left Side: Hall Details -->
                <div class="details-left">
                    <h3 class="details-title">EVENT HALL DETAILS</h3>
                    <div class="detail-row">
                        <span class="d-label">CURRENTLY VIEWING</span>
                        <span class="d-value" id="val-title">INFINITY HALL</span>
                    </div>
                    <div class="detail-row">
                        <span class="d-label">CAPACITY</span>
                        <span class="d-value" id="val-capacity">Up to 1000 pax</span>
                    </div>
                    <div class="detail-row">
                        <span class="d-label">IDEAL FOR</span>
                        <span class="d-value" id="val-ideal">Perfect for weddings</span>
                    </div>
                    <div class="detail-row">
                        <span class="d-label">TECHNICAL AMENITIES</span>
                        <span class="d-value" id="val-tech">Equipped with a built-in sound system</span>
                    </div>
                    <div class="detail-row" style="border-bottom: none;">
                        <span class="d-label">INCLUSIONS</span>
                        <span class="d-value" id="val-inc">Fully air-conditioned hall</span>
                    </div>
                </div>

                <!-- Right Side: Action & Availability -->
                <div class="details-right">
                    <h3 class="details-title">AVAILABILITY & ACTION</h3>
                    <div class="detail-row">
                        <span class="d-label">Status</span>
                        <span class="d-value" id="val-status">Available</span>
                    </div>
                    <div class="detail-row" style="border-bottom: none;">
                        <span class="d-label">Starting Rate</span>
                        <span class="d-value" id="val-rate">₱10,000 /day</span>
                    </div>

                    <div class="action-buttons">
                        <button class="btn-mock btn-book">BOOK THIS VENUE</button>
                        <button class="btn-mock btn-photos" id="btn-view-photos">VIEW PHOTOS</button>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4 style="font-family: var(--font-heading);">SEVILLA360</h4>
                    <p>Experience minimal luxury and warm scandinavian comfort in every stay.</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <a href="index.php#accommodations">Accommodations</a>
                    <a href="showroom.php">Virtual Showroom</a>
                    <a href="index.php#events">Event Spaces</a>
                </div>
                <div class="footer-col">
                    <h4>Support</h4>
                    <a href="#">Contact Us</a>
                    <a href="#">FAQ</a>
                    <a href="#">Booking Policy</a>
                </div>
                <div class="footer-col">
                    <h4>Connect</h4>
                    <a href="#">Instagram</a>
                    <a href="#">Facebook</a>
                    <a href="#">Twitter</a>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date("Y"); ?> SEVILLA360. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Global Scripts (Nav Menu etc) -->
    <script src="assets/js/index.js"></script>
    <!-- Page Specific Script -->
    <script src="assets/js/showroom.js"></script>
</body>

</html>