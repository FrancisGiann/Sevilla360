<?php
$page_title = 'SEVILLA360 | M.I. Sevilla Resort & Events Place';
$extra_css = 'assets/css/index.css?v=' . time();
$active_page = 'home';

require_once 'includes/session_init.php';
require_once 'config/db_connect.php';

// Fetch CMS images (same slots the old homepage used, so anything already
// uploaded via Admin > Media CMS keeps working)
$cms_query = $conn->query("SELECT slot_assignment, file_path FROM media_cms");
$cms_images = [];
if ($cms_query) {
    while ($row = $cms_query->fetch_assoc()) {
        $cms_images[$row['slot_assignment']] = $row['file_path'];
    }
}
function get_cms_image($slot_name, $default_url, $cms_images) {
    return isset($cms_images[$slot_name]) ? htmlspecialchars($cms_images[$slot_name]) : $default_url;
}

include 'includes/header.php';
?>

<main class="idx-page">

    <!-- ===================== HERO ===================== -->
    <header class="idx-hero"
        style="background-image: url('<?php echo get_cms_image('home-hero', 'https://images.unsplash.com/photo-1519225421980-715cb0215aed?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80', $cms_images); ?>');">
        <div class="idx-hero-content reveal">
            <span class="idx-hero-script">M.I. Sevilla</span>
            <span class="idx-hero-rule"></span>
            <h1 class="idx-hero-title">
                Where Every Event
                <span class="idx-italic">Becomes A Memory</span>
            </h1>
            <p class="idx-hero-sub">
                A private sanctuary of gardens, water and light — crafted for celebrations that
                deserve to be remembered.
            </p>
            <div class="idx-hero-buttons">
                <a href="booking.php" class="idx-btn idx-btn-gold">Book Your Stay</a>
                <a href="showroom.php" class="idx-btn idx-btn-outline-light">Explore Resort</a>
            </div>
        </div>
        <span class="idx-hero-scroll"></span>
    </header>

    <!-- ===================== WELCOME ===================== -->
    <section class="idx-welcome" id="about">
        <span class="idx-welcome-divider"></span>
        <div class="idx-welcome-grid">
            <div class="idx-welcome-img-wrap reveal">
                <img src="<?php echo get_cms_image('home-about', 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80', $cms_images); ?>"
                    alt="Stone courtyard with tropical greenery at M.I. Sevilla Resort">
                <div class="idx-welcome-badge">
                    <strong>18</strong>
                    <span>Years of Hosting</span>
                </div>
            </div>

            <div class="idx-welcome-text reveal" style="transition-delay: 0.1s;">
                <span class="idx-script">Welcome</span>
                <h2>M.I. Sevilla Resort</h2>
                <span class="idx-rule-gold"></span>
                <p>
                    Tucked between quiet gardens and open sky, M.I. Sevilla Resort &amp; Events Place
                    was built around a single belief — that a place can shape the way a moment is
                    remembered. Our halls, villas and rooms are tended with the same care given to
                    the people who fill them.
                </p>
                <p>
                    From intimate gatherings to grand celebrations, every detail is composed with
                    restraint, warmth and an eye for the unhurried.
                </p>
                <a href="#experiences" class="idx-welcome-cta">
                    Our Story
                    <span class="idx-cta-line"></span>
                </a>
            </div>
        </div>
    </section>

    <!-- ===================== EXPERIENCES ===================== -->
    <section class="idx-experiences" id="experiences">
        <div class="idx-experiences-head reveal">
            <span class="idx-script">Curated</span>
            <h2>Experiences</h2>
            <span class="idx-rule-gold"></span>
        </div>

        <div class="idx-experiences-grid">
            <article class="idx-exp-card reveal" style="transition-delay: 0.1s;">
                <div class="idx-exp-img-wrap">
                    <img src="https://images.unsplash.com/photo-1517457373958-b7bdd4587205?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Meetings & Conferences">
                    <span class="idx-exp-num">01</span>
                </div>
                <h3>Meetings &amp; Conferences</h3>
                <span class="idx-exp-rule"></span>
                <p>Considered spaces for focused work — natural light, quiet acoustics and service
                    that anticipates.</p>
            </article>

            <article class="idx-exp-card reveal" style="transition-delay: 0.2s;">
                <div class="idx-exp-img-wrap">
                    <img src="https://images.unsplash.com/photo-1519741497674-611481863552?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Weddings">
                    <span class="idx-exp-num">02</span>
                </div>
                <h3>Weddings</h3>
                <span class="idx-exp-rule"></span>
                <p>Garden ceremonies that drift into candlelit evenings, held together by an
                    unhurried elegance.</p>
            </article>

            <article class="idx-exp-card reveal" style="transition-delay: 0.3s;">
                <div class="idx-exp-img-wrap">
                    <img src="https://images.unsplash.com/photo-1511795409834-ef04bbd61622?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                        alt="Debut">
                    <span class="idx-exp-num">03</span>
                </div>
                <h3>Debut</h3>
                <span class="idx-exp-rule"></span>
                <p>A ballroom of gold and glass — a coming of age staged with quiet grandeur.</p>
            </article>
        </div>
    </section>

    <!-- ===================== EXPLORE & RESERVE ===================== -->
    <section id="accommodations">

        <!-- Row 1: Event Hall -->
        <div class="idx-reserve-row reveal">
            <div class="idx-reserve-grid">
                <img src="<?php echo get_cms_image('home-eventhall', 'https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80', $cms_images); ?>"
                    alt="Grand event hall with chandeliers and vaulted ceiling">
                <div class="idx-reserve-text">
                    <span class="idx-eyebrow">Venue</span>
                    <h3>The Grand Event Hall</h3>
                    <p>Vaulted ceilings, arched light and a floor that carries five hundred guests
                        without ever feeling crowded. The hall is the heart of the estate.</p>
                    <div class="idx-reserve-buttons">
                        <a href="booking.php?tab=event-hall" class="idx-btn idx-btn-gold">Check Availability</a>
                        <a href="showroom.php?cat=Event Hall" class="idx-btn idx-btn-outline-dark">Explore 360°</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Villa (dark band) -->
        <div class="idx-reserve-row idx-reserve-row-dark reveal">
            <div class="idx-reserve-inner">
                <div class="idx-reserve-text">
                    <span class="idx-eyebrow">Stay</span>
                    <h3>Private Resort Villa</h3>
                    <p>A house of your own — plunge pool, shaded terrace, and the kind of quiet
                        that only comes with distance from everything else.</p>
                    <div class="idx-reserve-buttons">
                        <a href="booking.php?tab=resort-villa" class="idx-btn idx-btn-gold">Check Availability</a>
                        <a href="showroom.php?cat=Resort Villa" class="idx-btn idx-btn-outline-light">Explore 360°</a>
                    </div>
                </div>
                <img src="<?php echo get_cms_image('home-villa', 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80', $cms_images); ?>"
                    alt="Private resort villa with plunge pool at golden hour">
            </div>
        </div>

        <!-- Row 3: Hotel Rooms (centered overlay) -->
        <div class="idx-reserve-centered reveal">
            <div class="idx-reserve-centered-imgwrap">
                <img src="<?php echo get_cms_image('home-hotel', 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80', $cms_images); ?>"
                    alt="Premium hotel room with linen bedding and soft light">
            </div>
            <div class="idx-reserve-centered-card">
                <span class="idx-eyebrow">Rooms</span>
                <h3>Premium Hotel Rooms</h3>
                <span class="idx-rule-gold"></span>
                <p>Linen, timber and morning light. Rooms designed for the hours between the
                    celebration and the next one.</p>
                <div class="idx-reserve-buttons idx-center-mobile">
                    <a href="booking.php?tab=hotel-rooms" class="idx-btn idx-btn-gold">Check Availability</a>
                    <a href="showroom.php?cat=Hotel Room" class="idx-btn idx-btn-outline-dark">Explore 360°</a>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include 'includes/footer.php'; ?>

<!-- Nav hamburger/dropdown behavior + scroll-reveal engine (shared sitewide) -->
<script src="assets/js/index.js?v=<?php echo time(); ?>"></script>

</body>

</html>
