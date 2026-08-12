<?php
$page_title = 'Virtual Showroom | SEVILLA360';
$extra_css = 'assets/css/showroom.css?v=' . time();
$extra_js = 'assets/js/showroom.js?v=' . time();
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
    GROUP BY v.id, v.category, v.name, hr.room_type
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
// ORDER BY id ASC keeps pano_urls order deterministic, matching admin hotspot indexing.
$media_query = $conn->query("SELECT id, slot_assignment, file_path, media_type FROM media_cms ORDER BY id ASC");

$pano_index_map = []; // [ base_id => [ media_id => pano_url_index ] ]

if ($media_query) {
    while ($m = $media_query->fetch_assoc()) {
        $slot = $m['slot_assignment'];

        if ($m['media_type'] === '360' && preg_match('/^venue_.+_360$/', $slot)) {
            $base_id = preg_replace('/^venue_(.+)_360$/', '$1', $slot);
            if (isset($showroom_data[$base_id])) {
                if (!isset($showroom_data[$base_id]['pano_urls'])) {
                    $showroom_data[$base_id]['pano_urls'] = [];
                    $pano_index_map[$base_id] = [];
                }
                $current_index = count($showroom_data[$base_id]['pano_urls']);
                $showroom_data[$base_id]['pano_urls'][] = $m['file_path'];
                $pano_index_map[$base_id][$m['id']] = $current_index;
            }
        }
        elseif ($m['media_type'] === 'standard' && preg_match('/^venue_/', $slot) && strpos($slot, '_std') === false) {
            $base_id = preg_replace('/^venue_/', '', $slot);
            if (isset($showroom_data[$base_id])) {
                $showroom_data[$base_id]['gallery'][] = $m['file_path'];
            }
        }
    }
}

// 3. Fetch hotspots and group by venue + pano index
$hotspots_query = $conn->query("
    SELECT id, media_id, type, title, description, position_x, position_y, position_z, target_pano_index 
    FROM showroom_hotspots
");
if ($hotspots_query) {
    while ($h = $hotspots_query->fetch_assoc()) {
        $media_id = $h['media_id'];
        foreach ($pano_index_map as $base_id => $media_to_index) {
            if (isset($media_to_index[$media_id])) {
                $pano_index = $media_to_index[$media_id];
                if (!isset($showroom_data[$base_id]['hotspots_by_pano_index'])) {
                    $showroom_data[$base_id]['hotspots_by_pano_index'] = [];
                }
                if (!isset($showroom_data[$base_id]['hotspots_by_pano_index'][$pano_index])) {
                    $showroom_data[$base_id]['hotspots_by_pano_index'][$pano_index] = [];
                }
                $showroom_data[$base_id]['hotspots_by_pano_index'][$pano_index][] = [
                    'id' => $h['id'],
                    'type' => $h['type'],
                    'title' => $h['title'],
                    'description' => $h['description'],
                    'position_x' => $h['position_x'],
                    'position_y' => $h['position_y'],
                    'position_z' => $h['position_z'],
                    'target_pano_index' => $h['target_pano_index'],
                ];
                break;
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
            <div class="viewer-label ui-360" id="top-room-label">Showroom</div>

            <button id="btn-info" class="ui-360 top-right-btn" title="Venue Information">
                <i class="fa-solid fa-circle-info"></i>
            </button>

            <div class="viewer-controls ui-360" id="viewer-controls">

                <button id="btn-switch-pano" title="Switch 360 View"
                    style="display:none; background: var(--color-gold); color: white;">
                    <i class="fa-solid fa-person-walking-arrow-right"></i>
                </button>
                <button id="btn-reload-pano" title="Reload 360"><i class="fa-solid fa-rotate-right"></i></button>
                <button id="btn-zoom-in" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                <button id="btn-zoom-out" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button id="btn-fullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
            </div>

            <div id="pano-container" class="ui-360" style="width:100%; height:100%;"></div>

            <!-- NEW: 360 Interaction Hint Overlay -->
            <div id="interaction-hint" class="ui-360">
                <div class="hint-icon">
                    <i class="fa-solid fa-hand-pointer"></i>
                </div>
                <p>Drag to explore</p>
            </div>

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

            <!-- === Sleek Dropdown Pill Navigation === -->
            <?php 
                $grouped_showroom = ['Event Hall' => [], 'Hotel Room' => [], 'Resort Villa' => []];
                $first_available_category = '';
                $first_available_room = '';

                foreach($showroom_data as $id => $data) {
                    if (!empty($data['pano_urls']) || !empty($data['gallery'])) {
                        $grouped_showroom[$data['category']][$id] = $data;
                        if (empty($first_available_category)) {
                            $first_available_category = $data['category'];
                            $first_available_room = $id;
                        }
                    }
                }
            ?>

            <div class="room-navigation-wrapper ui-360">
                <div class="master-category-pills">

                    <?php foreach($grouped_showroom as $category => $venues): ?>
                    <?php if (!empty($venues)): ?>

                    <!-- Master Pill Wrapper -->
                    <div class="pill-dropdown-wrapper">
                        <button
                            class="master-pill <?php echo ($category === $first_available_category) ? 'active' : ''; ?>"
                            data-category="<?php echo htmlspecialchars($category); ?>">
                            <?php echo htmlspecialchars($category); ?>s
                            <i class="fa-solid fa-chevron-up"
                                style="margin-left: 8px; font-size: 0.75rem; transition: transform 0.3s;"></i>
                        </button>

                        <!-- Floating Dropdown Menu -->
                        <div class="pill-dropdown-menu <?php echo ($category === $first_available_category) ? 'active' : ''; ?>"
                            id="dropdown-<?php echo str_replace(' ', '-', $category); ?>">
                            <?php foreach($venues as $id => $data): ?>
                            <button class="dropdown-item <?php echo ($id === $first_available_room) ? 'active' : ''; ?>"
                                data-room="<?php echo $id; ?>">
                                <?php echo ucwords(strtolower($data['title'])); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php endif; ?>
                    <?php endforeach; ?>

                </div>
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

    <!-- Mobile Info Modal -->
    <div class="modal-overlay" id="info-modal" style="z-index: 999999;">
        <!-- Extremely high z-index to sit over fullscreen! -->
        <div class="modal-content modal-sm"
            style="background: rgba(42, 37, 34, 0.95); color: white; border: 1px solid var(--color-gold); backdrop-filter: blur(10px);">
            <h3 class="modal-title" id="info-modal-title"
                style="color: var(--color-gold); border-bottom: 1px solid rgba(214, 168, 112, 0.3); padding-bottom: 15px;">
                Venue Name</h3>

            <div class="modal-body" style="color: #e0e0e0;">
                <p id="info-modal-desc" style="font-size: 0.95rem; line-height: 1.6; margin-bottom: 20px;">Venue
                    description goes here.</p>

                <div
                    style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-bottom: 20px;">
                    <div><span style="color: var(--color-gold); display: block; font-size: 0.75rem;">CAPACITY</span>
                        <span id="info-modal-cap">--</span>
                    </div>
                    <div><span style="color: var(--color-gold); display: block; font-size: 0.75rem;">RATE</span> <span
                            id="info-modal-rate">--</span></div>
                </div>

                <!-- We will copy the amenities grid into here dynamically! -->
                <div id="info-modal-amenities" class="amenities-grid"
                    style="border-bottom: none; padding-bottom: 0; color: white;"></div>
            </div>

            <div class="modal-actions-center">
                <button class="btn btn-primary" id="btn-close-info" style="width: 100%;">Close</button>
            </div>
        </div>
    </div>

</section>

<?php include 'includes/footer.php'; ?>