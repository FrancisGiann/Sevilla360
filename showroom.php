<?php
$page_title = 'Virtual Showroom | SEVILLA360';
$extra_css = 'assets/css/showroom.css?v=' . time();
$extra_js = 'assets/js/showroom.js?v= ' . time();
$active_page = 'showroom';

require_once 'config/db_connect.php';

// 1. Fetch all venues
$venues_query = $conn->query("
    SELECT 
        v.id, v.category, v.name AS venue_name, v.status, v.description, v.amenities,
        hr.room_type, hr.base_capacity, hr.max_capacity, hr.nightly_rate,
        eh.base_rate AS eh_rate, eh.max_capacity AS eh_cap,
        vi.day_rate AS vi_rate, vi.max_capacity AS vi_cap
    FROM venues v
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    LEFT JOIN event_halls eh ON v.id = eh.venue_id
    LEFT JOIN villas vi ON v.id = vi.venue_id
    WHERE v.status != 'Inactive'
    GROUP BY v.name, hr.room_type
");

$showroom_data = [];

if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        $display_name = ($v['category'] === 'Hotel Room' && !empty($v['room_type'])) ? $v['venue_name'] . ' - ' . $v['room_type'] : $v['venue_name'];
        $safe_id = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name)), '_');
        
        $cap = 'N/A'; $rate = 'N/A';
        if ($v['category'] === 'Hotel Room') { $cap = $v['max_capacity'] . ' pax'; $rate = '₱' . number_format($v['nightly_rate']) . ' /night'; }
        if ($v['category'] === 'Event Hall') { $cap = $v['eh_cap'] . ' pax'; $rate = '₱' . number_format($v['eh_rate']) . ' /day'; }
        if ($v['category'] === 'Resort Villa') { $cap = $v['vi_cap'] . ' pax'; $rate = '₱' . number_format($v['vi_rate']) . ' /day'; }

        // Fallback text 
        $desc = !empty($v['description']) ? $v['description'] : "Experience ultimate luxury and comfort at $display_name.";
        $amenities = !empty($v['amenities']) ? explode(',', $v['amenities']) : ['Free Wi-Fi', 'Fully Air-Conditioned'];

        $showroom_data[$safe_id] = [
            'id' => $safe_id,
            'title' => strtoupper($display_name),
            'category' => $v['category'],
            'capacity' => $cap,
            'rate' => $rate,
            'status' => $v['status'],
            'description' => $desc,
            'amenities' => $amenities,
            'pano_url' => '', 
            'gallery' => []   
        ];
    }
}

// 2. Fetch Media from CMS and attach to the correct showroom venue
$media_query = $conn->query("SELECT slot_assignment, file_path, media_type FROM media_cms");
if ($media_query) {
while($m = $media_query->fetch_assoc()) {
$slot = $m['slot_assignment'];

// If it's a 360 image (e.g., venue_deluxe_room_360)
if ($m['media_type'] === '360' && strpos($slot, '_360') !== false) {
$base_id = str_replace(['venue_', '_360'], '', $slot);
if (isset($showroom_data[$base_id])) {
$showroom_data[$base_id]['pano_url'] = $m['file_path'];
}
}
// If it's a standard gallery image (e.g., venue_deluxe_room)
elseif ($m['media_type'] === 'standard' && strpos($slot, 'venue_') === 0 && strpos($slot, '_std') === false) {
$base_id = str_replace('venue_', '', $slot);
if (isset($showroom_data[$base_id])) {
$showroom_data[$base_id]['gallery'][] = $m['file_path'];
}
}
}
}

include 'includes/header.php';
?>

<!-- Pass PHP Data to Javascript -->
<script>
window.showroomData = <?php echo json_encode($showroom_data); ?>;
</script>

<!-- EXACT Three.js version required by Panolens -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/105/three.min.js"></script>

<!-- Polyfill for Panolens Node.js bug -->
<script>
window.process = {
    env: {
        NODE_ENV: 'production'
    }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js"></script>

<!-- Showroom Container -->
<section class="showroom-wrapper" id="showroom-wrapper">
    <div class="showroom-container">

        <!-- 1. The Big Viewer Box -->
        <div class="big-viewer-box">

            <!-- === 360 UI Elements === -->
            <div class="viewer-label ui-360">Showroom</div>

            <div class="viewer-controls ui-360" id="viewer-controls">
                <button id="btn-reload-pano" title="Reload 360">
                    <i class="fa-solid fa-rotate-right"></i>
                </button>
                <button id="btn-zoom-in" title="Zoom In">
                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                </button>
                <button id="btn-zoom-out" title="Zoom Out">
                    <i class="fa-solid fa-magnifying-glass-minus"></i>
                </button>
                <button id="btn-fullscreen" title="Fullscreen">
                    <i class="fa-solid fa-expand"></i>
                </button>
            </div>

            <div id="pano-container" class="ui-360" style="width:100%; height:100%;"></div>

            <!-- NEW: The Loading Placeholder that covers the black screen -->
            <div id="pano-loading-overlay"
                style="position: absolute; top:0; left:0; width:100%; height:100%; z-index: 4; background: url('assets/img/placeholder.jpg') center/cover no-repeat; display: none; align-items: center; justify-content: center;">
                <div
                    style="background: rgba(0,0,0,0.6); color: white; padding: 10px 25px; border-radius: 30px; font-family: var(--font-body); font-weight: 500; letter-spacing: 1px; backdrop-filter: blur(4px);">
                    <i class="fa-solid fa-circle-notch fa-spin" style="margin-right: 8px;"></i> Loading 360° View...
                </div>
            </div>

            <!-- === Photo Gallery UI Elements === -->

            <!-- NEW: Flexbox Header for Gallery Mode -->
            <div class="gallery-header ui-photos">
                <div>
                    <span class="photo-room-title" id="gallery-title">--</span>
                    <span id="gallery-counter"
                        style="color: var(--color-gold); margin-left: 10px; font-weight: bold;"></span>
                </div>
                <button class="btn-back" id="btn-back-to-360">Back to 360</button>
            </div>

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

            <div class="slider-image-container ui-photos">
                <img src="assets/img/placeholder.jpg" alt="Room Photo" id="current-slide-img">
            </div>

            <!-- NEW: The Thumbnail Filmstrip -->
            <div class="thumbnail-strip ui-photos" id="thumbnail-strip"></div>

            <button class="btn-back ui-photos" id="btn-back-to-360">Back to 360</button>

            <!-- === Shared Elements (Room Pills) === -->
            <div class="room-pills" id="dynamic-room-pills">
                <?php 
                $first = true;
                foreach($showroom_data as $id => $data): 
                    // Only show pills for venues that actually have at least 1 image uploaded!
                    if (!empty($data['pano_url']) || !empty($data['gallery'])):
                ?>
                <button class="pill <?php echo $first ? 'active' : ''; ?>" data-room="<?php echo $id; ?>">
                    <?php echo ucwords(strtolower($data['title'])); ?>
                </button>
                <?php 
                    $first = false;
                    endif; 
                endforeach; 
                ?>
            </div>
        </div>

        <!-- 2. The Details Block -->
        <div class="details-box">
            <div class="details-left">
                <h3 class="details-title">VENUE DETAILS</h3>
                <!-- NEW: The Description -->
                <p class="venue-description" id="val-desc">
                    Experience ultimate luxury and comfort. This venue features stunning architecture, natural lighting,
                    and everything you need to make your event unforgettable.
                </p>

                <!-- NEW: Amenities Grid -->
                <div class="amenities-grid">
                    <div class="amenity"><i class="fa-solid fa-wifi"></i> Free Wi-Fi</div>
                    <div class="amenity"><i class="fa-solid fa-snowflake"></i> Fully Air-Conditioned</div>
                    <div class="amenity"><i class="fa-solid fa-square-parking"></i> Ample Parking</div>
                    <div class="amenity"><i class="fa-solid fa-wheelchair"></i> Wheelchair Accessible</div>
                </div>
                <div class="detail-row" style="margin-top: 20px;">
                    <span class="d-label">CURRENTLY VIEWING</span>
                    <span class="d-value" id="val-title">--</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">CATEGORY</span>
                    <span class="d-value" id="val-category">--</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">MAX CAPACITY</span>
                    <span class="d-value" id="val-capacity">--</span>
                </div>
            </div>

            <div class="details-right">
                <h3 class="details-title">AVAILABILITY & ACTION</h3>
                <div class="detail-row">
                    <span class="d-label">Status</span>
                    <span class="d-value" id="val-status">--</span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="d-label">Starting Rate</span>
                    <span class="d-value" id="val-rate">--</span>
                </div>

                <div class="action-buttons">
                    <a href="booking.php" class="btn-mock btn-book"
                        style="text-align:center; text-decoration:none; display:flex; justify-content:center; align-items:center;">BOOK
                        VENUE</a>
                    <button class="btn-mock btn-photos" id="btn-view-photos">VIEW PHOTOS</button>
                </div>
            </div>
        </div>

    </div>
</section>

<?php include 'includes/footer.php'; ?>