<?php
require_once 'config/db_connect.php';
require_once 'includes/media_helper.php';

// 1. Fetch DISTINCT venues by grouping by BOTH Building Name AND Room Type
$venues_query = $conn->query("
    SELECT 
        v.category,
        v.name AS venue_name,
        hr.room_type 
    FROM venues v
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    WHERE v.status != 'Inactive'
    GROUP BY 
        v.id,
        v.category, 
        v.name,
        hr.room_type
");

// 2. Setup Base Arrays
$website_slots = [
    'home-hero' => ['title' => 'Landing Page - Hero Banner', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-about' => ['title' => 'Homepage - About/Welcome Photo', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-1' => ['title' => 'Homepage - Experience 1', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-2' => ['title' => 'Homepage - Experience 2', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-3' => ['title' => 'Homepage - Experience 3', 'badge' => 'Homepage', 'type' => 'standard']
];

$venue_standard_slots = []; // Distinct array for venue standard slots
$venue_360_slots = [];
$venue_categories = []; 

// Automatically create picture slots per unique building/room combination
if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        
        if ($v['category'] === 'Hotel Room' && !empty($v['room_type'])) {
            $display_name = $v['venue_name'] . ' - ' . $v['room_type'];
        } else {
            $display_name = $v['venue_name']; 
        }
        
        $clean_name = htmlspecialchars($display_name);
        
        $slot_key = media_cms_venue_slot_key($display_name);
        
        $venue_categories[$slot_key] = $clean_name;
        
        // Place into dedicated venue_standard_slots array
        $venue_standard_slots[$slot_key] = [
            'title' => $clean_name . ' (Standard Photo)',
            'badge' => $v['category'],
            'type' => 'standard'
        ];
        
        // Slot for 360 panorama
        $venue_360_slots[$slot_key . '_360'] = [
            'title' => $clean_name . ' (360 View)',
            'badge' => '360 Panorama',
            'type' => '360',
            'category_badge' => $v['category']
        ];
    }
}

// One narrow, stable query powers cards, galleries, and hotspot editing.
$query = "SELECT id, file_name, file_path, media_type, slot_assignment, is_primary,
                 showroom_view_x, showroom_view_y, showroom_view_z, showroom_fov
          FROM media_cms ORDER BY is_primary DESC, id DESC";
$result = $conn->query($query);

$uploaded_media = []; // For the one-to-one homepage system slots
$gallery_items = [];  // General gallery
$standard_venue_photos = []; // Grouped standard photos
$pano_venue_photos = [];     // Grouped 360 panoramas

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        try {
            $row['file_path'] = media_cms_upload_relative_path((string)$row['file_path']);
        } catch (Throwable $e) {
            error_log('CMS media path unavailable for media id ' . (int)$row['id'] . ': ' . $e->getMessage());
            continue;
        }
        try {
            $thumbnail = media_cms_ensure_admin_thumbnail($row['file_path']);
            $thumbnailFile = media_cms_thumbnail_file_path($row['file_path']);
            $row['thumbnail_path'] = $thumbnail['path'];
            $row['thumbnail_version'] = (int)(@filemtime($thumbnailFile) ?: 0);
            $row['thumbnail_width'] = (int)$thumbnail['width'];
            $row['thumbnail_height'] = (int)$thumbnail['height'];
        } catch (Throwable $e) {
            // A failed derivative must never send the full source to a card.
            error_log('CMS thumbnail unavailable for media id ' . (int)$row['id'] . ': ' . $e->getMessage());
            $row['thumbnail_path'] = 'assets/img/placeholder.jpg';
            $row['thumbnail_version'] = 0;
            $row['thumbnail_width'] = MEDIA_CMS_THUMBNAIL_WIDTH;
            $row['thumbnail_height'] = MEDIA_CMS_THUMBNAIL_HEIGHT;
        }
        $slot = $row['slot_assignment'];
        
        if ($slot === 'gallery') {
            $gallery_items[] = $row;
        } elseif (strpos($slot, 'home-') === 0) {
            // Grab the newest record for single slots
            if (!isset($uploaded_media[$slot])) {
                $uploaded_media[$slot] = $row;
            }
        } elseif (strpos($slot, '_360') !== false) {
            $pano_venue_photos[$slot][] = $row;
        } else {
            $standard_venue_photos[$slot][] = $row;
        }
    }
}

// Keep a PHP-only ordered view for the card's Hotspots button. The editor
// derives the same order from galleryData, so no duplicate panorama payload is
// emitted and View 1/2/3 stays aligned with showroom.php's pano_urls order.
$pano_venue_photos_ordered = [];
foreach ($pano_venue_photos as $slot => $photos) {
    usort($photos, static function (array $left, array $right): int {
        $primaryOrder = (int)$right['is_primary'] <=> (int)$left['is_primary'];
        return $primaryOrder !== 0 ? $primaryOrder : ((int)$left['id'] <=> (int)$right['id']);
    });
    $pano_venue_photos_ordered[$slot] = $photos;
}

$cms_gallery_data = [];
foreach (array_merge($standard_venue_photos, $pano_venue_photos) as $slot => $photos) {
    $cms_gallery_data[$slot] = array_map(static function (array $photo): array {
        return [
            'id' => (int)$photo['id'],
            'file_name' => (string)$photo['file_name'],
            'file_path' => (string)$photo['file_path'],
            'is_primary' => (int)$photo['is_primary'],
            'thumbnail_path' => (string)$photo['thumbnail_path'],
            'thumbnail_version' => (int)$photo['thumbnail_version'],
            'thumbnail_width' => (int)$photo['thumbnail_width'],
            'thumbnail_height' => (int)$photo['thumbnail_height'],
            'showroom_view_x' => $photo['showroom_view_x'],
            'showroom_view_y' => $photo['showroom_view_y'],
            'showroom_view_z' => $photo['showroom_view_z'],
            'showroom_fov' => $photo['showroom_fov'],
        ];
    }, $photos);
}
?>

<script>
window.galleryData = <?php echo json_encode($cms_gallery_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<div class="cms-container">
    <div class="cms-toolbar">
        <div class="cms-filters">
            <button class="cms-pill active" data-filter="all">All Media</button>
            <button class="cms-pill" data-filter="360">360 Showroom</button>
            <button class="cms-pill" data-filter="standard">Standard Photos</button>
        </div>
        <div class="cms-controls">
            <button class="btn btn-primary" id="btnOpenUpload">+ Upload Media</button>
        </div>
    </div>

    <!-- Media Grid -->
    <div class="cms-grid" id="cms-grid-container">

        <!-- 1. SYSTEM SLOTS (Hero Banner & Welcome Photo) -->
        <?php foreach($website_slots as $slot_key => $slot_info): 
            $has_img = isset($uploaded_media[$slot_key]);
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($uploaded_media[$slot_key], $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gray"><?php echo $slot_info['badge']; ?></span>
                </div>
                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="standard" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Replace' : 'Upload'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 2. 360 PANORAMA SLOTS -->
        <?php foreach($venue_360_slots as $slot_key => $slot_info): 
            $photos_array = isset($pano_venue_photos[$slot_key]) ? $pano_venue_photos[$slot_key] : [];
            $photo_count = count($photos_array);
            $has_img = $photo_count > 0;
            // Use the primary-first ascending-ID view for the card and hotspot button.
            $ordered_photos_array = isset($pano_venue_photos_ordered[$slot_key]) ? $pano_venue_photos_ordered[$slot_key] : $photos_array;
            $first_photo = $has_img ? $ordered_photos_array[0] : null;
        ?>
        <div class="cms-card" data-type="360">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($first_photo, $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gold">
                        <?php echo $has_img ? $photo_count . ' Panoramas' : $slot_info['category_badge']; ?>
                    </span>
                </div>
                <?php if (!$has_img): ?>
                <p class="cms-size">No 360 view uploaded yet.</p>
                <?php else: ?>
                <p class="cms-size">360 Virtual Tour Active</p>
                <?php endif; ?>

                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="360" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Add More' : 'Upload'; ?>
                    </button>
                    <?php if ($has_img): ?>
                    <button class="btn btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">Manage</button>
                    <button class="btn btn-outline btn-place-hotspots" data-media-id="<?php echo $ordered_photos_array[0]['id']; ?>"
                        data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <i class="fa-solid fa-map-pin"></i> Hotspots</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 3. STANDARD VENUE PHOTOS -->
        <!-- FIX: Iterate via $venue_standard_slots (includes empty ones), not just photos that exist -->
        <?php foreach($venue_standard_slots as $slot_key => $slot_info): 
            $photos_array = isset($standard_venue_photos[$slot_key]) ? $standard_venue_photos[$slot_key] : [];
            $photo_count = count($photos_array);
            $has_img = $photo_count > 0;
            $first_photo = $has_img ? $photos_array[0] : null;
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($first_photo, $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span
                        class="badge badge-gray"><?php echo $has_img ? $photo_count . ' Photos' : $slot_info['badge']; ?></span>
                </div>
                <?php if (!$has_img): ?>
                <p class="cms-size">No standard photos uploaded yet.</p>
                <?php else: ?>
                <p class="cms-size">Standard Photo Gallery</p>
                <?php endif; ?>

                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="standard" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Add More' : 'Upload'; ?>
                    </button>
                    <?php if ($has_img): ?>
                    <button class="btn btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">Manage</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 4. GENERAL GALLERY ITEMS -->
        <?php foreach($gallery_items as $item): ?>
        <div class="cms-card" data-type="<?php echo htmlspecialchars((string)$item['media_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <div class="cms-img-wrapper">
                <?php echo media_cms_card_image_markup($item, (string)$item['file_name']); ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">General Gallery</h4>
                    <span class="badge badge-gray">Unassigned</span>
                </div>
                <p class="cms-size">File: <?php echo htmlspecialchars($item['file_name']); ?></p>
                <div class="cms-actions">
                    <button class="btn-delete btn-delete-media" data-id="<?php echo $item['id']; ?>">Delete</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<!-- ==============================================
     MODALS 
     ============================================== -->

<!-- 1. UPLOAD MODAL -->
<div class="cms-modal-overlay" id="uploadModal">
    <div class="cms-modal-content">
        <h3 class="cms-modal-title">Upload Website Media</h3>
        <form class="cms-form" id="cms-upload-form">
            <div class="cms-drag-drop" id="dragDropArea">
                <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                <p class="drop-text"><strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to
                        browse</span></p>
                <input type="file" id="fileInput" accept="image/jpeg, image/png, image/webp" multiple hidden>
            </div>

            <div class="cms-form-group">
                <label>Media Type</label>
                <select name="media_type" id="modal-media-type" required>
                    <option value="" disabled selected>Select media type...</option>
                    <option value="standard">Standard Photo (Multiple Allowed)</option>
                    <option value="360">360 Panorama (Multiple Allowed)</option>
                </select>
            </div>

            <div class="cms-form-group">
                <label>Assign to Website Slot</label>
                <select name="website_slot" id="modal-website-slot" required>
                    <option value="" disabled selected>Select where this image goes...</option>

                    <optgroup label="System & Gallery">
                        <option value="home-hero" data-type="standard" style="display:none;">Landing Page - Hero Banner
                        </option>
                        <option value="home-about" data-type="standard" style="display:none;">Homepage - About/Welcome
                            Photo</option>
                        <option value="gallery" data-type="standard" style="display:none;">General Gallery (Standard)
                        </option>
                        <option value="gallery" data-type="360" style="display:none;">General Gallery (360)</option>
                    </optgroup>

                    <optgroup label="Resort Venues">
                        <!-- FIX: Iterate over correct Standard Venue array inside dropdown -->
                        <?php foreach($venue_standard_slots as $key => $slot): ?>
                        <option value="<?php echo $key; ?>" data-type="standard" style="display:none;">
                            <?php echo $slot['title']; ?>
                        </option>
                        <?php endforeach; ?>

                        <?php foreach($venue_360_slots as $key => $slot): ?>
                        <option value="<?php echo $key; ?>" data-type="360" style="display:none;">
                            <?php echo $slot['title']; ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div id="upload-progress-container" style="display: none; margin-bottom: 15px;">
                <div
                    style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.85rem; font-weight: 600;">
                    <span style="color: var(--color-dark);">Uploading...</span>
                    <span id="upload-progress-text" style="color: var(--color-gold);">0%</span>
                </div>
                <div style="width: 100%; background: #eee; border-radius: 10px; height: 8px; overflow: hidden;">
                    <div id="upload-progress-bar"
                        style="width: 100%; height: 100%; background: var(--color-gold); transform-origin: left center; transform: scaleX(0); transition: transform 0.2s;"></div>
                </div>
            </div>

            <div class="cms-modal-actions">
                <button type="button" class="btn cms-btn-outline" id="btnCloseModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MANAGE GALLERY MODAL -->
<div class="cms-modal-overlay" id="manageGalleryModal">
    <div class="cms-modal-content manage-gallery-content" style="max-width: 800px;">
        <h3 class="cms-modal-title" id="mg-title">Manage Gallery</h3>

        <!-- NEW: BULK DELETE CONTROLS -->
        <div id="mg-bulk-controls" class="manage-gallery-bulk"
            style="display: none; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f9f9f9; padding: 10px 15px; border-radius: 6px; border: 1px solid #e0e0e0;">
            <label
                style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:8px; color: var(--color-dark);">
                <input type="checkbox" id="mg-select-all" style="transform:scale(1.2);"> Select All
            </label>
            <button type="button" class="btn" style="background:#c75c5c; color:#fff; border:none; opacity:0.5;"
                id="btn-mg-bulk-delete" disabled>
                <i class="fa-solid fa-trash"></i> Delete Selected (<span id="mg-sel-count">0</span>)
            </button>
        </div>

        <div id="mg-grid" class="manage-gallery-grid"
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 50vh; overflow-y: auto; padding-right: 5px; margin-bottom: 20px;">
            <!-- JavaScript will inject photos here -->
        </div>

        <div class="cms-modal-actions manage-gallery-actions" style="justify-content: space-between;">
            <button type="button" class="btn cms-btn-outline" id="btnCloseGalleryModal">Close</button>
            <button type="button" class="btn btn-primary" id="btn-mg-add">Add Photos</button>
        </div>
    </div>
</div>

<!-- 3. LIGHTBOX FOR FULLSCREEN VIEWING -->
<div id="cms-lightbox"
    style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 100000; display: none; align-items: center; justify-content: center; cursor: zoom-out;">
    <img id="cms-lightbox-img"
        style="max-width: 90%; max-height: 90vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
</div>

<!-- 4. UNIVERSAL CONFIRM MODAL -->
<div class="cms-modal-overlay" id="uniConfirmModal" style="z-index: 9999;">
    <div class="cms-modal-content" style="max-width: 400px; text-align: center;">
        <i class="fa-solid fa-circle-question"
            style="font-size: 3rem; color: var(--color-gold); margin-bottom: 15px;"></i>
        <h3 class="cms-modal-title" style="margin-bottom: 10px;">Confirm Action</h3>
        <p id="uc-message" style="color: var(--color-dark-light); font-size: 0.95rem; margin-bottom: 25px;">Are you
            sure?</p>
        <div style="display: flex; gap: 10px;">
            <button class="btn cms-btn-outline" id="uc-btn-no" style="flex: 1;">No, Cancel</button>
            <button class="btn btn-primary" id="uc-btn-yes" style="flex: 1;">Yes, Proceed</button>
        </div>
    </div>
</div>

<!-- 5. UNIVERSAL ALERT MODAL -->
<div class="cms-modal-overlay" id="uniAlertModal" style="z-index: 10000;">
    <div class="cms-modal-content" style="max-width: 400px; text-align: center;">
        <i id="ua-icon" class="fa-solid fa-circle-info"
            style="font-size: 3rem; color: var(--color-gold); margin-bottom: 15px;"></i>
        <h3 class="cms-modal-title" id="ua-title" style="margin-bottom: 10px;">Notice</h3>
        <p id="ua-message" style="color: var(--color-dark-light); font-size: 0.95rem; margin-bottom: 25px;">Message goes
            here.</p>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" id="ua-btn-ok" style="flex: 1;">OK</button>
        </div>
    </div>
</div>

<!-- 6. TOUR SETUP & HOTSPOTS MODAL -->
<div class="cms-modal-overlay" id="hotspotModal" style="z-index: 5000;">
    <div class="cms-modal-content hotspot-modal-content" role="dialog" aria-modal="true" aria-labelledby="hotspot-modal-title" tabindex="-1">
        <div class="hotspot-modal-header">
            <div class="hotspot-modal-header-copy">
                <h3 class="cms-modal-title" id="hotspot-modal-title">Tour Setup &amp; Hotspots</h3>
                <p>Choose a starting scene and saved view, then place guest directions and information pins.</p>
            </div>
            <div class="hotspot-modal-header-actions">
                <button type="button" class="btn cms-btn-outline" id="btn-hotspot-help" aria-label="Replay tour setup guide"><i class="fa-solid fa-circle-question" aria-hidden="true"></i><span>Help</span></button>
                <button type="button" class="btn cms-btn-outline" id="btnCloseHotspotModal" aria-label="Close tour setup"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>Close</span></button>
            </div>
        </div>

        <div class="hotspot-modal-body">
            <div class="hotspot-steps" aria-label="Tour setup steps">
                <section class="hotspot-step" data-hotspot-step="1" aria-labelledby="hotspot-step-1-title">
                    <h4 id="hotspot-step-1-title"><span class="hotspot-step-number" aria-hidden="true">1</span><span>Choose panorama</span></h4>
                    <div class="hotspot-view-selector">
                        <label for="hs-admin-view-selector">Tour panorama</label>
                        <select id="hs-admin-view-selector" aria-describedby="hs-view-description"></select>
                        <p id="hs-view-description" class="hotspot-view-description" aria-live="polite"></p>
                    </div>
                </section>
                <section class="hotspot-step" data-hotspot-step="2" aria-labelledby="hotspot-step-2-title">
                    <h4 id="hotspot-step-2-title"><span class="hotspot-step-number" aria-hidden="true">2</span><span>Set guest view</span></h4>
                    <div class="hotspot-tour-status" aria-live="polite" aria-atomic="true">
                        <span id="hs-starting-scene-status" class="hotspot-state-pill">Starting scene</span>
                        <span id="hs-view-preset-status" class="hotspot-state-pill">View not set</span>
                    </div>
                    <div class="hotspot-tour-actions">
                        <button type="button" class="hotspot-btn hotspot-btn-primary" id="btn-save-panorama-view" disabled><span data-hotspot-button-label>Save current guest view</span></button>
                        <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-make-starting-scene"><span data-hotspot-button-label>Make starting scene</span></button>
                        <details class="hotspot-more-menu">
                            <summary>View options</summary>
                            <div class="hotspot-more-actions">
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-preview-saved-view" disabled><span data-hotspot-button-label>Preview saved view</span></button>
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-clear-panorama-view" disabled><span data-hotspot-button-label>Reset saved view</span></button>
                            </div>
                        </details>
                    </div>
                </section>
                <section class="hotspot-step" data-hotspot-step="3" aria-labelledby="hotspot-step-3-title">
                    <h4 id="hotspot-step-3-title"><span class="hotspot-step-number" aria-hidden="true">3</span><span>Place and manage hotspots</span></h4>
                    <div class="hotspot-step-support">
                        <span id="hs-hotspot-count" class="hotspot-state-pill">0 hotspots</span>
                        <p>Click the panorama to place an information or walk marker, then save it from the form.</p>
                    </div>
                </section>
            </div>

            <p id="hs-editor-status" class="hotspot-editor-status" role="status" aria-live="polite" aria-atomic="true">Choose a panorama to begin editing its tour.</p>

            <div class="hotspot-editor-stage">
                <div class="hotspot-pano-panel">
                    <div id="hotspot-pano-container" aria-label="360 degree panorama preview"></div>
                    <div id="hotspot-loading" role="status" aria-live="polite">
                        <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
                        <span id="hotspot-loading-message">Loading panorama…</span>
                        <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-retry-hotspot-view" hidden><span data-hotspot-button-label>Retry panorama</span></button>
                    </div>
                </div>

                <div class="hotspot-sidebar">
                    <div id="hotspot-form-wrapper" class="hotspot-form hidden">
                        <h4 class="hotspot-form-heading"><span id="hs-form-heading">New Hotspot</span><span class="hotspot-form-state" id="hs-form-state">New pin</span></h4>
                        <div class="hotspot-field">
                            <label for="hs-type">Type</label>
                            <select id="hs-type">
                                <option value="info">Information pin</option>
                                <option value="nav">Walk to another view</option>
                            </select>
                        </div>
                        <div class="hotspot-field">
                            <label for="hs-title">Guest label</label>
                            <input type="text" id="hs-title" maxlength="150" placeholder="e.g. Poolside entrance" autocomplete="off">
                        </div>
                        <div class="hotspot-field" id="hs-desc-wrapper">
                            <label for="hs-description">Description</label>
                            <textarea id="hs-description" rows="3" maxlength="5000" placeholder="Shown when a guest opens this pin"></textarea>
                        </div>
                        <div class="hotspot-field hidden" id="hs-target-wrapper">
                            <label for="hs-target-index">Destination</label>
                            <select id="hs-target-index"></select>
                        </div>
                        <fieldset class="hotspot-field hotspot-rotation hidden" id="hs-arrow-rotation-wrapper">
                            <legend>Arrow direction</legend>
                            <label for="hs-arrow-rotation-range">Rotate the walk marker</label>
                            <input type="range" id="hs-arrow-rotation-range" min="0" max="359" step="1" value="0" aria-label="Rotate walk marker from 0 to 359 degrees">
                            <div class="hotspot-rotation-value">
                                <label for="hs-arrow-rotation">Degrees</label>
                                <input type="number" id="hs-arrow-rotation" min="0" max="359" step="1" value="0" inputmode="numeric">
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-reset-arrow-rotation">Reset</button>
                            </div>
                        </fieldset>
                        <div class="hotspot-form-actions">
                            <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-cancel-hotspot">Cancel</button>
                            <button type="button" class="hotspot-btn hotspot-btn-primary" id="btn-save-hotspot"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span id="hs-save-label" data-hotspot-button-label>Save Pin</span></button>
                        </div>
                    </div>
                    <section class="hotspot-list-section" aria-labelledby="hotspot-list-title">
                        <h4 id="hotspot-list-title">Placed Hotspots</h4>
                        <div id="hotspot-list" aria-live="polite" aria-busy="false">
                            <p class="hotspot-empty-state">No hotspots placed yet.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="hotspot-tour-toast" class="hotspot-tour-toast" role="status" aria-live="polite" aria-atomic="true">
    <span class="hotspot-tour-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
    <span class="hotspot-tour-toast-copy">
        <strong data-tour-toast-message></strong>
        <span data-tour-toast-detail hidden></span>
    </span>
</div>
