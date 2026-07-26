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
        v.category, 
        v.name,
        hr.room_type
");

// 2. Setup Base Arrays
$website_slots = [
    'home-hero' => ['title' => 'Landing Page - Hero Banner', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-eventhall' => ['title' => 'Homepage - Event Hall Preview', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-villa' => ['title' => 'Homepage - Villa Preview', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-hotel' => ['title' => 'Homepage - Hotel Preview', 'badge' => 'Homepage', 'type' => 'standard']
];

$venue_360_slots = []; // Strictly 1 slot per venue
$venue_categories = []; // Dropdown options

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
        
        // MISSING BLOCK RESTORED: Slot for standard picture!
        $website_slots['venue_' . $safe_id] = [
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

// 3. Fetch all uploaded media and group them
$query = "SELECT * FROM media_cms";
$result = $conn->query($query);

$uploaded_media = []; // For 1-to-1 slots (Homepage Previews)
$gallery_items = [];  // General gallery
$standard_venue_photos = []; // Grouped standard photos
$pano_venue_photos = [];     // NEW: Grouped 360 panoramas!

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $slot = $row['slot_assignment'];
        
        if ($slot === 'gallery') {
            $gallery_items[] = $row;
        } elseif (strpos($slot, 'home-') === 0) {
            $uploaded_media[$slot] = $row;
        } elseif (strpos($slot, '_360') !== false) {
            $pano_venue_photos[$slot][] = $row; // Stack 360 photos here!
        } else {
            $standard_venue_photos[$slot][] = $row;
        }
    }
}
?>

<!-- Pass ALL grouped photos to JavaScript so the modal can read them! -->
<script>
window.galleryData = <?php echo json_encode(array_merge($standard_venue_photos, $pano_venue_photos)); ?>;
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

        <!-- 1. SYSTEM SLOTS (Hero Banner & Homepage Previews) -->
        <?php foreach($website_slots as $slot_key => $slot_info): 
            $has_img = isset($uploaded_media[$slot_key]);
            $img_path = $has_img ? $uploaded_media[$slot_key]['file_path'] : 'assets/img/placeholder.jpg';
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?> <img src="<?php echo htmlspecialchars($img_path); ?>"> <?php else: ?> <span
                    style="color:#888;">Empty Slot</span> <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gray"><?php echo $slot_info['badge']; ?></span>
                </div>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="standard">
                        <?php echo $has_img ? 'Replace' : 'Upload'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 2. 360 PANORAMA SLOTS (Multiple Allowed!) -->
        <?php foreach($venue_360_slots as $slot_key => $slot_info): 
            $photos_array = isset($pano_venue_photos[$slot_key]) ? $pano_venue_photos[$slot_key] : [];
            $photo_count = count($photos_array);
            $has_img = $photo_count > 0;
            $first_photo = $has_img ? $photos_array[0]['file_path'] : '';
        ?>
        <div class="cms-card" data-type="360">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <img src="<?php echo htmlspecialchars($first_photo); ?>">
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

                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="360">
                        <?php echo $has_img ? 'Add More' : 'Upload'; ?>
                    </button>
                    <!-- NEW: Manage button for 360s! -->
                    <?php if ($has_img): ?>
                    <button class="btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 6px 12px; font-size: 0.85rem; border: 1px solid var(--color-gold); color: var(--color-dark); border-radius: 4px; cursor: pointer; background: transparent;">Manage</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 3. STANDARD VENUE PHOTOS (Grouped into Single Cards!) -->
        <?php foreach($standard_venue_photos as $slot_key => $photos_array): 
            $photo_count = count($photos_array);
            $first_photo = $photos_array[0]['file_path']; // Show the first photo as the thumbnail
        ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper">
                <img src="<?php echo htmlspecialchars($first_photo); ?>">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">
                        <?php echo isset($venue_categories[$slot_key]) ? $venue_categories[$slot_key] : 'Unknown Venue'; ?>
                    </h4>
                    <span class="badge badge-gray"><?php echo $photo_count; ?> Photos</span>
                </div>
                <p class="cms-size">Standard Photo Gallery</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="<?php echo $slot_key; ?>"
                        data-type="standard">Add More</button>
                    <button class="btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 6px 12px; font-size: 0.85rem; border: 1px solid var(--color-gold); color: var(--color-dark); border-radius: 4px; cursor: pointer; background: transparent;">Manage</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 4. GENERAL GALLERY ITEMS -->
        <?php foreach($gallery_items as $item): ?>
        <div class="cms-card" data-type="<?php echo $item['media_type']; ?>">
            <div class="cms-img-wrapper">
                <img src="<?php echo htmlspecialchars($item['file_path']); ?>">
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

<!-- NEW: Upload Progress Bar -->
<div id="upload-progress-container" style="display: none; margin-top: 15px;">
    <div style="width: 100%; background: #eee; border-radius: 4px; height: 8px; overflow: hidden;">
        <div id="upload-progress-bar"
            style="width: 0%; height: 100%; background: var(--color-gold); transition: width 0.2s;"></div>
    </div>
    <div id="upload-progress-text" style="font-size: 0.8rem; color: #888; text-align: right; margin-top: 5px;">0%</div>
</div>

<div class="cms-modal-actions">
    <!-- UPLOAD MODAL -->
    <div class="cms-modal-overlay" id="uploadModal">
        <div class="cms-modal-content">
            <h3 class="cms-modal-title">Upload Website Media</h3>
            <form class="cms-form" id="cms-upload-form">
                <div class="cms-drag-drop" id="dragDropArea">
                    <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                    <p class="drop-text"><strong>Drag and drop</strong> images here<br>or <span class="highlight">Click
                            to
                            browse</span></p>
                    <input type="file" id="fileInput" accept="image/jpeg, image/png, image/webp" multiple hidden>
                </div>

                <div class="cms-form-group">
                    <label>Media Type</label>
                    <select name="media_type" id="modal-media-type" required>
                        <option value="" disabled selected>Select media type...</option>
                        <option value="standard">Standard Photo</option>
                        <option value="360">360 Panorama</option>
                    </select>
                </div>

                <div class="cms-form-group">
                    <label>Assign to Website Slot</label>
                    <select name="website_slot" id="modal-website-slot" required>
                        <option value="" disabled selected>Select where this image goes...</option>

                        <optgroup label="System Slots">
                            <!-- Updated to include the Homepage Slots! -->
                            <option value="home-hero" data-type="standard" style="display:none;">Landing Page - Hero
                                Banner
                            </option>
                            <option value="home-eventhall" data-type="standard" style="display:none;">Homepage - Event
                                Hall
                                Preview</option>
                            <option value="home-villa" data-type="standard" style="display:none;">Homepage - Villa
                                Preview
                            </option>
                            <option value="home-hotel" data-type="standard" style="display:none;">Homepage - Hotel
                                Preview
                            </option>
                            <option value="gallery" data-type="standard" style="display:none;">General Gallery</option>
                        </optgroup>

                        <!-- Dynamic Venue Options -->
                        <optgroup label="Resort Venues">
                            <?php foreach($venue_categories as $key => $name): ?>
                            <!-- The Standard Photo option uses the base key (allows multiples) -->
                            <option value="<?php echo $key; ?>" data-type="standard" style="display:none;">
                                <?php echo $name; ?> (Standard Photo)
                            </option>
                            <!-- The 360 option appends '_360' to the key -->
                            <option value="<?php echo $key . '_360'; ?>" data-type="360" style="display:none;">
                                <?php echo $name; ?> (360 Panorama)
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="cms-modal-actions">
                    <button type="button" class="btn cms-btn-outline" id="btnCloseModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MANAGE GALLERY MODAL -->
    <div class="cms-modal-overlay" id="manageGalleryModal">
        <div class="cms-modal-content" style="max-width: 800px;">
            <h3 class="cms-modal-title" id="mg-title">Manage Gallery</h3>

            <div id="mg-grid"
                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 50vh; overflow-y: auto; padding-right: 5px; margin-bottom: 20px;">
                <!-- JavaScript will inject photos here -->
            </div>

            <div class="cms-modal-actions" style="justify-content: space-between;">
                <button type="button" class="btn cms-btn-outline" id="btnCloseGalleryModal">Close</button>
                <!-- NEW: Add Photos Button -->
                <button type="button" class="btn btn-primary" id="btn-mg-add">Add Photos</button>
            </div>
        </div>
    </div>

    <!-- NEW: LIGHTBOX FOR FULLSCREEN VIEWING -->
    <div id="cms-lightbox"
        style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 100000; display: none; align-items: center; justify-content: center; cursor: zoom-out;">
        <img id="cms-lightbox-img"
            style="max-width: 90%; max-height: 90vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
    </div>


    <!-- Universal Confirm Modal -->
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

    <!-- Universal Alert Modal -->
    <div class="cms-modal-overlay" id="uniAlertModal" style="z-index: 10000;">
        <div class="cms-modal-content" style="max-width: 400px; text-align: center;">
            <i id="ua-icon" class="fa-solid fa-circle-info"
                style="font-size: 3rem; color: var(--color-gold); margin-bottom: 15px;"></i>
            <h3 class="cms-modal-title" id="ua-title" style="margin-bottom: 10px;">Notice</h3>
            <p id="ua-message" style="color: var(--color-dark-light); font-size: 0.95rem; margin-bottom: 25px;">Message
                goes
                here.</p>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" id="ua-btn-ok" style="flex: 1;">OK</button>
            </div>
        </div>
    </div>