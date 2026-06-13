<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEVILLA360 | M.I. Sevilla Resort & Events Place</title>
    <meta name="description" content="Premium booking system for M.I. Sevilla Resort & Events Place.">
    <link rel="stylesheet" href="assets/css/style.css">
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
            <a href="#about">About</a>
            <a href="#events">Events</a>
            <a href="#accommodations">Accommodations</a>
            <a href="showroom.php">Virtual Showroom</a>
            <a href="login.php" class="btn btn-primary">Login / Register</a>
        </div>
    </div>
</nav>

    <!-- Hero Section -->
    <header class="hero">
        <div class="hero-content reveal">
            <h1>Where Every Event Becomes A Memory</h1>
            <div class="hero-buttons">
                <a href="#book" class="btn btn-primary">Book Your Stay</a>
                <a href="#explore" class="btn btn-outline">Explore Resort</a>
            </div>
        </div>
    </header>

    <!-- Welcome Section -->
    <section id="about" class="bg-white">
        <div class="container split-layout reveal">
            <div class="split-image">
                <!-- Using premium luxury resort imagery -->
                <img src="https://images.unsplash.com/photo-1542314831-c6a4d27ce6a2?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="M.I. Sevilla Resort Welcome">
            </div>
            <div class="split-text">
                <div class="script-heading">Welcome</div>
                <h2>To M.I. Sevilla Resort</h2>
                <p>Discover a sanctuary of elegance and tranquility. Inspired by warm Scandinavian minimalism, our spaces are meticulously crafted to provide an atmosphere of relaxed luxury. Whether you are hosting a grand celebration or seeking a private escape, Sevilla360 ensures your journey is seamless from the moment you book.</p>
                <a href="#story" class="btn btn-primary">Our Story</a>
            </div>
        </div>
    </section>

    <!-- Events Highlight Section -->
    <section id="events" class="bg-beige">
        <div class="container">
            <div class="text-center reveal">
                <h2>Curated Experiences</h2>
                <p>Tailored venues for your most cherished milestones.</p>
            </div>
            <div class="grid-3">
                <!-- Event Card 1 -->
                <div class="event-card reveal" style="transition-delay: 0.1s;">
                    <div class="event-card-img">
                        <img src="https://images.unsplash.com/photo-1517457373958-b7bdd4587205?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Meetings & Conferences">
                    </div>
                    <div class="event-card-content">
                        <h3>Meetings & Conferences</h3>
                        <p>Sophisticated spaces equipped for executive focus.</p>
                    </div>
                </div>
                <!-- Event Card 2 -->
                <div class="event-card reveal" style="transition-delay: 0.2s;">
                    <div class="event-card-img">
                        <img src="https://images.unsplash.com/photo-1519741497674-611481863552?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Weddings">
                    </div>
                    <div class="event-card-content">
                        <h3>Weddings</h3>
                        <p>Breathtaking backdrops for your perfect day.</p>
                    </div>
                </div>
                <!-- Event Card 3 -->
                <div class="event-card reveal" style="transition-delay: 0.3s;">
                    <div class="event-card-img">
                        <img src="https://images.unsplash.com/photo-1511795409834-ef04bbd61622?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Debut">
                    </div>
                    <div class="event-card-content">
                        <h3>Debut</h3>
                        <p>Elegant halls for unforgettable coming-of-age celebrations.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Booking Preview Section -->
    <section id="accommodations" class="bg-white">
        <div class="container">
            <div class="text-center reveal" style="margin-bottom: 4rem;">
                <h2>Explore & Reserve</h2>
                <p>Find the perfect space for your stay or event.</p>
            </div>

            <!-- Row 1: Image Left / Text Right -->
            <div class="booking-row reveal">
                <div class="booking-img-wrapper">
                    <img src="https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="The Grand Event Hall">
                </div>
                <div class="booking-info">
                    <h3>The Grand Event Hall</h3>
                    <p>A masterpiece of architectural design, our Event Hall offers expansive capacities, state-of-the-art acoustics, and a neutral palette ready to be transformed by your unique vision. Ideal for galas, grand debuts, and luxurious weddings.</p>
                    <a href="book.php?type=hall" class="btn btn-primary">Check Availability</a>
                    <a href="showroom.php" class="btn btn-secondary">Explore 360°</a>
                </div>
            </div>

            <!-- Row 2: Image Right / Text Left (Handled by CSS :nth-child) -->
            <div class="booking-row reveal">
                <div class="booking-img-wrapper">
                    <img src="https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Private Resort Villa">
                </div>
                <div class="booking-info">
                    <h3>Private Resort Villa</h3>
                    <p>Experience exclusivity in our Private Villas. Featuring a private pool, sunlit lounging areas, and minimalist Scandinavian interiors, it is the ultimate retreat for families and VIP guests seeking privacy and bespoke service.</p>
                    <a href="book.php?type=villa" class="btn btn-primary">Check Availability</a>
                    <a href="showroom.php" class="btn btn-secondary">Explore 360°</a>
                </div>
            </div>

            <!-- Row 3: Image Left / Text Right -->
            <div class="booking-row reveal">
                <div class="booking-img-wrapper">
                    <img src="https://images.unsplash.com/photo-1618773928121-c32242e63f39?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Premium Hotel Rooms">
                </div>
                <div class="booking-info">
                    <h3>Premium Hotel Rooms</h3>
                    <p>Rest in absolute comfort. Our premium rooms blend warm beige tones with plush, tactile fabrics, creating a calming oasis to unwind after a day of celebration or intensive meetings.</p>
                    <a href="book.php?type=room" class="btn btn-primary">Check Availability</a>
                    <a href="showroom.php" class="btn btn-secondary">Explore 360°</a>
                </div>
            </div>

        </div>
    </section>

    <!-- Footer Section -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid reveal">
                <div class="footer-col">
                    <h4 style="font-family: var(--font-heading); font-size: 1.5rem;">Sevilla360</h4>
                    <p>M.I. Sevilla Resort & Events Place<br>Where every event becomes a memory.</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <a href="#about">About Us</a>
                    <a href="#events">Our Venues</a>
                    <a href="#accommodations">Accommodations</a>
                    <a href="faq.php">FAQs</a>
                </div>
                <div class="footer-col">
                    <h4>Contact Us</h4>
                    <p>+1 (800) 123-4567</p>
                    <p>reservations@sevilla360.com</p>
                    <p>123 Serenity Lane, Resort City</p>
                </div>
                <div class="footer-col">
                    <h4>Newsletter</h4>
                    <p>Subscribe for exclusive offers and updates.</p>
                    <!-- Visual representation of an input for design completeness -->
                    <div style="display:flex; margin-top: 1rem;">
                        <input type="email" placeholder="Your Email" style="padding: 10px; border:none; outline:none; width:100%; border-radius: 4px 0 0 4px;">
                        <button class="btn-primary" style="border:none; padding: 10px 15px; border-radius: 0 4px 4px 0; cursor:pointer;">&#10140;</button>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date("Y"); ?> M.I. Sevilla Resort. Powered by Sevilla360 Booking System. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <script src="assets/js/index.js"></script>
</body>
</html>