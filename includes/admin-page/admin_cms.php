<?php
require_once 'config/db_connect.php';

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
        
        $safe_id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name));
        $safe_id = trim($safe_id, '_');
        
        $venue_categories['venue_' . $safe_id] = $clean_name; 
        
        // Place into dedicated venue_standard_slots array
        $venue_standard_slots['venue_' . $safe_id] = [
            'title' => $clean_name . ' (Standard Photo)',
            'badge' => $v['category'],
            'type' => 'standard'
        ];
        
        // Slot for 360 panorama
        $venue_360_slots['venue_' . $safe_id . '_360'] = [
            'title' => $clean_name . ' (360 View)',
            'badge' => '360 Panorama',
            'type' => '360',
            'category_badge' => $v['category']
        ];
    }
}

// Order by id DESC so the newest uploaded photo takes precedence
$query = "SELECT * FROM media_cms ORDER BY is_primary DESC, id DESC";
$result = $conn->query($query);

$uploaded_media = []; // For the one-to-one homepage system slots
$gallery_items = [];  // General gallery
$standard_venue_photos = []; // Grouped standard photos
$pano_venue_photos = [];     // Grouped 360 panoramas

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
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

// Separate ASC-ordered dataset specifically for hotspot placement
// so "View 1/2/3" numbering always matches showroom.php's pano_urls order.
$pano_asc_query = $conn->query("
    SELECT id, slot_assignment, file_path, file_name, is_primary, showroom_view_x, showroom_view_y, showroom_view_z, showroom_fov
    FROM media_cms 
    WHERE media_type = '360' AND slot_assignment LIKE '%\\_360'
    ORDER BY is_primary DESC, id ASC
");
$pano_venue_photos_ordered = [];
if ($pano_asc_query) {
    while ($row = $pano_asc_query->fetch_assoc()) {
        $pano_venue_photos_ordered[$row['slot_assignment']][] = $row;
    }
}
?>

<script>
window.galleryData = <?php echo json_encode(array_merge($standard_venue_photos, $pano_venue_photos)); ?>;
window.panoDataOrdered = <?php echo json_encode($pano_venue_photos_ordered); ?>;
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
            $img_path = $has_img ? $uploaded_media[$slot_key]['file_path'] : 'assets/img/placeholder.jpg';
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <img src="<?php echo htmlspecialchars($img_path); ?>?v=<?php echo time(); ?>">
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
            // Use ASC-ordered array for thumbnail and Hotspots button ID so it
            // matches what panoDataOrdered[slot][0] resolves to in the JS editor.
            $ordered_photos_array = isset($pano_venue_photos_ordered[$slot_key]) ? $pano_venue_photos_ordered[$slot_key] : $photos_array;
            $first_photo = $has_img ? $ordered_photos_array[0]['file_path'] : '';
        ?>
        <div class="cms-card" data-type="360">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <img src="<?php echo htmlspecialchars($first_photo); ?>?v=<?php echo time(); ?>">
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
            $first_photo = $has_img ? $photos_array[0]['file_path'] : ''; 
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <img src="<?php echo htmlspecialchars($first_photo); ?>?v=<?php echo time(); ?>">
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
        <div class="cms-card" data-type="<?php echo $item['media_type']; ?>">
            <div class="cms-img-wrapper">
                <img src="<?php echo htmlspecialchars($item['file_path']); ?>?v=<?php echo time(); ?>">
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
            <div>
                <h3 class="cms-modal-title" id="hotspot-modal-title">Tour Setup &amp; Hotspots</h3>
                <p>Choose a starting scene and saved view, then place guest directions and information pins.</p>
            </div>
            <button type="button" class="btn cms-btn-outline" id="btnCloseHotspotModal" aria-label="Close tour setup">Close</button>
        </div>

        <div class="hotspot-modal-body">
            <section class="hotspot-tour-setup" aria-label="Panorama tour settings">
                <div class="hotspot-view-selector">
                    <label for="hs-admin-view-selector">Tour panorama</label>
                    <select id="hs-admin-view-selector" aria-describedby="hs-view-description"></select>
                    <p id="hs-view-description" class="hotspot-view-description" aria-live="polite"></p>
                </div>
                <div class="hotspot-tour-status" aria-live="polite" aria-atomic="true">
                    <span id="hs-starting-scene-status" class="hotspot-state-pill">Starting scene</span>
                    <span id="hs-view-preset-status" class="hotspot-state-pill">View not set</span>
                    <span id="hs-hotspot-count" class="hotspot-state-pill">0 hotspots</span>
                </div>
                <div class="hotspot-tour-actions">
                    <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-make-starting-scene"><span data-hotspot-button-label>Make Starting Scene</span></button>
                    <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-set-current-view"><span data-hotspot-button-label>Set Current View</span></button>
                    <button type="button" class="hotspot-btn hotspot-btn-primary" id="btn-save-panorama-view" disabled><span data-hotspot-button-label>Save View</span></button>
                    <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-preview-saved-view" disabled><span data-hotspot-button-label>Preview Saved View</span></button>
                    <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-clear-panorama-view" disabled><span data-hotspot-button-label>Clear Default</span></button>
                </div>
            </section>

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
                        <h4><span id="hs-form-heading">New Hotspot</span></h4>
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
