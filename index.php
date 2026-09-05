<?php
$page_title = 'SEVILLA360 | M.I. Sevilla Resort & Events Place';
$extra_css = 'assets/css/index.css?v=' . time();
$active_page = 'home';

require_once 'includes/session_init.php';
require_once 'config/db_connect.php';

// Public venue discovery is sourced from the same active venue/detail/media
// records used by booking.php. Keep this payload deliberately limited to
// public facts; no customer, booking, lock, or internal note fields leave the
// server.
$public_media = [];
$public_media_query = $conn->query("SELECT slot_assignment, file_path, is_primary FROM media_cms WHERE media_type = 'standard' ORDER BY is_primary DESC, id ASC");
if ($public_media_query) {
    while ($media = $public_media_query->fetch_assoc()) {
        $public_media[$media['slot_assignment']][] = (string)$media['file_path'];
    }
}
$public_slot_key = static function (string $name): string {
    $safe = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
    return 'venue_' . trim($safe, '_');
};
$public_images = static function (string $displayName) use (&$public_media, $public_slot_key): array {
    $images = $public_media[$public_slot_key($displayName)] ?? [];
    $images = array_values(array_filter(array_map(static fn($path) => trim((string)$path), $images)));
    return $images ?: ['assets/img/placeholder.jpg'];
};
$venue_reviews_table_result = $conn->query("SHOW TABLES LIKE 'venue_reviews'");
$venue_reviews_available = $venue_reviews_table_result instanceof mysqli_result && $venue_reviews_table_result->num_rows > 0;
$event_review_fields = $venue_reviews_available
    ? ", COALESCE((SELECT AVG(vr.rating) FROM venue_reviews vr INNER JOIN bookings rb ON rb.id = vr.booking_id WHERE vr.venue_id = v.id AND vr.moderation_status = 'Approved' AND rb.booking_status <> 'Cancelled' AND COALESCE(rb.payment_status, '') <> 'Refunded'), 0) AS rating_average, (SELECT COUNT(*) FROM venue_reviews vr INNER JOIN bookings rb ON rb.id = vr.booking_id WHERE vr.venue_id = v.id AND vr.moderation_status = 'Approved' AND rb.booking_status <> 'Cancelled' AND COALESCE(rb.payment_status, '') <> 'Refunded') AS rating_count"
    : ', 0 AS rating_average, 0 AS rating_count';
$public_venues = ['Event Hall' => [], 'Hotel Room' => [], 'Resort Villa' => []];
$public_event_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, e.base_rate, e.capacity_theater, e.capacity_classroom, e.capacity_banquet {$event_review_fields} FROM venues v INNER JOIN event_halls e ON e.venue_id = v.id WHERE v.category = 'Event Hall' AND v.status = 'Available' ORDER BY v.name");
if ($public_event_query) while ($venue = $public_event_query->fetch_assoc()) {
    $public_venues['Event Hall'][] = [
        'key' => 'event-' . (int)$venue['id'], 'review_key' => 'event-' . (int)$venue['id'], 'category' => 'Event Hall', 'venue_name' => (string)$venue['name'],
        'venue_id' => (int)$venue['id'], 'rate' => is_numeric($venue['base_rate'] ?? null) ? (float)$venue['base_rate'] : null,
        'facts' => ['Theater' => (int)$venue['capacity_theater'] . ' guests', 'Classroom' => (int)$venue['capacity_classroom'] . ' guests', 'Banquet' => (int)$venue['capacity_banquet'] . ' guests', 'Booking' => 'Inquiry, no date hold'],
        'description' => (string)($venue['description'] ?? ''), 'amenities' => (string)($venue['amenities'] ?? ''), 'rating_average' => round((float)$venue['rating_average'], 1), 'rating_count' => (int)$venue['rating_count'],
        'images' => $public_images((string)$venue['name'])
    ];
}
$hotel_review_fields = $venue_reviews_available
    ? ", COALESCE((SELECT AVG(vr.rating) FROM venue_reviews vr INNER JOIN bookings rb ON rb.id = vr.booking_id INNER JOIN venues rv ON rv.id = rb.venue_id WHERE vr.moderation_status = 'Approved' AND rb.booking_status <> 'Cancelled' AND COALESCE(rb.payment_status, '') <> 'Refunded' AND rv.name = v.name AND EXISTS (SELECT 1 FROM hotel_rooms rh WHERE rh.venue_id = rb.venue_id AND rh.room_type = h.room_type)), 0) AS rating_average, (SELECT COUNT(*) FROM venue_reviews vr INNER JOIN bookings rb ON rb.id = vr.booking_id INNER JOIN venues rv ON rv.id = rb.venue_id WHERE vr.moderation_status = 'Approved' AND rb.booking_status <> 'Cancelled' AND COALESCE(rb.payment_status, '') <> 'Refunded' AND rv.name = v.name AND EXISTS (SELECT 1 FROM hotel_rooms rh WHERE rh.venue_id = rb.venue_id AND rh.room_type = h.room_type)) AS rating_count"
    : ', 0 AS rating_average, 0 AS rating_count';
$public_hotel_query = $conn->query("SELECT v.name AS building_name, h.room_type, MIN(h.nightly_rate) AS nightly_rate, MAX(h.nightly_rate) AS max_nightly_rate, MIN(h.base_capacity) AS base_capacity, MAX(h.max_capacity) AS max_capacity, MIN(h.bed_count) AS min_bed_count, MAX(h.bed_count) AS max_bed_count, MIN(h.extra_pax_rate) AS extra_pax_rate, MIN(h.check_in_time) AS check_in_time, MAX(h.check_out_time) AS check_out_time, MAX(v.description) AS description, MAX(v.amenities) AS amenities, COUNT(*) AS inventory_count {$hotel_review_fields} FROM venues v INNER JOIN hotel_rooms h ON h.venue_id = v.id WHERE v.category = 'Hotel Room' AND v.status = 'Available' GROUP BY v.name, h.room_type ORDER BY v.name, h.room_type");
if ($public_hotel_query) while ($venue = $public_hotel_query->fetch_assoc()) {
    $displayName = $venue['building_name'] . ' - ' . $venue['room_type'];
    $minBeds = (int)$venue['min_bed_count']; $maxBeds = (int)$venue['max_bed_count'];
    $formatBeds = static fn(int $min, int $max): string => $min === $max ? $min . ' ' . ($min === 1 ? 'bed' : 'beds') : $min . '–' . $max . ' beds';
    $public_venues['Hotel Room'][] = [
        'key' => 'hotel-' . md5($displayName), 'stable_key' => 'hotel-' . md5($displayName), 'review_key' => 'hotel-' . md5($displayName), 'category' => 'Hotel Room', 'venue_name' => (string)$venue['building_name'], 'building_name' => (string)$venue['building_name'],
        'room_type' => (string)$venue['room_type'], 'rate' => is_numeric($venue['nightly_rate'] ?? null) ? (float)$venue['nightly_rate'] : null, 'max_nightly_rate' => is_numeric($venue['max_nightly_rate'] ?? null) ? (float)$venue['max_nightly_rate'] : null, 'rate_is_starting' => is_numeric($venue['nightly_rate'] ?? null) && is_numeric($venue['max_nightly_rate'] ?? null) && (float)$venue['nightly_rate'] < (float)$venue['max_nightly_rate'], 'overnight_rate' => null,
        'facts' => ['Beds' => $formatBeds($minBeds, $maxBeds), 'Inventory' => (int)$venue['inventory_count'] . ' units', 'Capacity' => (int)$venue['base_capacity'] . '–' . (int)$venue['max_capacity'] . ' guests', 'Stay' => 'Per night', 'Check-in' => substr((string)$venue['check_in_time'], 0, 5), 'Check-out' => substr((string)$venue['check_out_time'], 0, 5)],
        'description' => (string)($venue['description'] ?? ''), 'amenities' => (string)($venue['amenities'] ?? ''), 'rating_average' => round((float)$venue['rating_average'], 1), 'rating_count' => (int)$venue['rating_count'],
        'images' => $public_images($displayName)
    ];
}
$public_villa_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, vi.day_rate, vi.overnight_rate, vi.base_capacity, vi.max_capacity, vi.extra_pax_rate, vi.has_private_pool, vi.day_check_in_time, vi.day_check_out_time, vi.overnight_check_in_time, vi.overnight_check_out_time {$event_review_fields} FROM venues v INNER JOIN villas vi ON vi.venue_id = v.id WHERE v.category = 'Resort Villa' AND v.status = 'Available' ORDER BY v.name");
if ($public_villa_query) while ($venue = $public_villa_query->fetch_assoc()) {
    $public_venues['Resort Villa'][] = [
        'key' => 'villa-' . (int)$venue['id'], 'review_key' => 'villa-' . (int)$venue['id'], 'category' => 'Resort Villa', 'venue_name' => (string)$venue['name'],
        'venue_id' => (int)$venue['id'], 'rate' => is_numeric($venue['day_rate'] ?? null) ? (float)$venue['day_rate'] : null, 'overnight_rate' => is_numeric($venue['overnight_rate'] ?? null) ? (float)$venue['overnight_rate'] : null,
        'facts' => ['Capacity' => (int)$venue['base_capacity'] . '–' . (int)$venue['max_capacity'] . ' guests', 'Stay' => 'Day or overnight', 'Pool' => ((int)$venue['has_private_pool'] === 1 ? 'Private pool' : 'Pool access'), 'Day hours' => substr((string)$venue['day_check_in_time'], 0, 5) . '–' . substr((string)$venue['day_check_out_time'], 0, 5), 'Overnight hours' => substr((string)$venue['overnight_check_in_time'], 0, 5) . '–' . substr((string)$venue['overnight_check_out_time'], 0, 5)],
        'description' => (string)($venue['description'] ?? ''), 'amenities' => (string)($venue['amenities'] ?? ''), 'rating_average' => round((float)$venue['rating_average'], 1), 'rating_count' => (int)$venue['rating_count'],
        'images' => $public_images((string)$venue['name'])
    ];
}

// Fetch the current system CMS images used by the homepage.
$cms_query = $conn->query("SELECT slot_assignment, file_path FROM media_cms WHERE slot_assignment IN ('home-hero', 'home-about')");
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
    <section id="accommodations" class="idx-discovery" aria-labelledby="discovery-title">
        <div class="idx-discovery-head reveal">
            <h2 id="discovery-title">Spaces for the moment ahead</h2>
            <p>Browse available halls, rooms and villas, then carry your choice into a live availability check.</p>
        </div>
        <div class="idx-catalog-stack">
        <?php foreach (['Event Hall' => 'Event Halls', 'Resort Villa' => 'Resort Villas', 'Hotel Room' => 'Hotel Rooms'] as $category => $label): $category_slug = strtolower(str_replace(' ', '-', $category)); ?>
        <section class="idx-catalog-section idx-catalog-section--<?php echo htmlspecialchars($category_slug, ENT_QUOTES, 'UTF-8'); ?> reveal" data-catalog-category="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="catalog-<?php echo $category_slug; ?>">
            <div class="idx-catalog-heading">
                <h3 id="catalog-<?php echo $category_slug; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h3>
            </div>
            <div class="idx-catalog-shell">
                <div class="idx-catalog-viewport">
                    <div class="idx-catalog-track" tabindex="0"></div>
                </div>
                <div class="idx-carousel-controls" aria-label="<?php echo htmlspecialchars($label); ?> carousel controls">
                    <button type="button" class="idx-carousel-prev" aria-label="Previous <?php echo htmlspecialchars($label); ?>"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                    <span class="idx-carousel-position" aria-live="polite">0 of 0</span>
                    <button type="button" class="idx-carousel-next" aria-label="Next <?php echo htmlspecialchars($label); ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                </div>
            </div>
            <p class="idx-catalog-empty" hidden>No currently available options in this category.</p>
        </section>
        <?php endforeach; ?>
        </div>
    </section>

    <div class="idx-venue-modal" id="idx-venue-modal" hidden aria-hidden="true">
        <div class="idx-venue-dialog" role="dialog" aria-modal="true" aria-labelledby="idx-modal-title">
            <button type="button" class="idx-modal-close" aria-label="Close venue details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            <div class="idx-modal-gallery">
                <button type="button" class="idx-modal-gallery-prev" aria-label="Previous image"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <img id="idx-modal-image" src="assets/img/placeholder.jpg" alt="">
                <button type="button" class="idx-modal-gallery-next" aria-label="Next image"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <div class="idx-modal-thumbnails" role="list"></div>
            </div>
            <div class="idx-modal-copy">
                <h2 id="idx-modal-title"></h2>
                <p class="idx-modal-category"></p>
                <p class="idx-modal-rate"></p>
                <p class="idx-modal-rating" id="idx-modal-rating" aria-live="polite"></p>
                <section class="idx-modal-reviews" aria-labelledby="idx-modal-reviews-title">
                    <h3 id="idx-modal-reviews-title">Recent reviews</h3>
                    <div id="idx-modal-reviews-list"><p>No ratings yet</p></div>
                </section>
                <div class="idx-modal-facts"></div>
                <p class="idx-modal-description"></p>
                <ul class="idx-modal-amenities"></ul>
                <div class="idx-modal-availability">
                    <h3>Check availability</h3>
                    <div class="calendar-ui idx-modal-calendar" id="idx-modal-calendar">
                        <div class="cal-header"><button type="button" class="cal-nav prev-month" aria-label="Previous month"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><h4 class="cal-month-year">Month Year</h4><button type="button" class="cal-nav next-month" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button></div>
                        <div class="cal-weekdays"><span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span></div>
                        <div class="cal-days-grid" aria-live="polite"></div>
                        <ul class="idx-modal-calendar-legend" aria-label="Calendar legend">
                            <li><span class="idx-modal-calendar-legend-dot idx-modal-calendar-legend-dot--available" aria-hidden="true"></span><span>Available</span></li>
                            <li><span class="idx-modal-calendar-legend-dot idx-modal-calendar-legend-dot--selected" aria-hidden="true"></span><span>Selected</span></li>
                            <li><span class="idx-modal-calendar-legend-dot idx-modal-calendar-legend-dot--unavailable" aria-hidden="true"></span><span>Unavailable</span></li>
                            <li data-calendar-legend="checkout-boundary" hidden><span class="idx-modal-calendar-legend-dot idx-modal-calendar-legend-dot--checkout" aria-hidden="true"></span><span>Available checkout date</span></li>
                            <li><span class="idx-modal-calendar-legend-dot idx-modal-calendar-legend-dot--past" aria-hidden="true"></span><span>Past date</span></li>
                        </ul>
                    </div>
                    <p class="idx-modal-calendar-note">This calendar checks availability only; it never places a hold.</p>
                </div>
                <a class="idx-btn idx-btn-gold idx-modal-continue" href="booking.php">Continue booking</a>
            </div>
        </div>
    </div>

    <script>
    window.publicVenueCatalog = <?php echo json_encode($public_venues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    </script>

</main>

<?php include 'includes/footer.php'; ?>
