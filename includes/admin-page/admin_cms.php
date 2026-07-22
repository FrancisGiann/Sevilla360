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

// 2. Setup Base Arrays (Added the missing Homepage preview slots here!)
$website_slots = [
    'home-hero' => ['title' => 'Landing Page - Hero Banner', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-eventhall' => ['title' => 'Homepage - Event Hall Preview', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-villa' => ['title' => 'Homepage - Villa Preview', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-hotel' => ['title' => 'Homepage - Hotel Preview', 'badge' => 'Homepage', 'type' => 'standard']
];

$venue_360_slots = []; // Strictly 1 slot per venue
$venue_categories = []; // Dropdown options

// Automatically create ONE picture slot per unique building/room combination
if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        
        // Combine Building Name AND Room Type (e.g., "Abelardo - Family Superior")
        if ($v['category'] === 'Hotel Room' && !empty($v['room_type'])) {
            $display_name = $v['venue_name'] . ' - ' . $v['room_type'];
        } else {
            $display_name = $v['venue_name']; // Event Halls and Villas just use their name
        }
        
        $clean_name = htmlspecialchars($display_name);
        
        // Create a super safe ID by replacing spaces and special characters with underscores
        $safe_id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name));
        $safe_id = trim($safe_id, '_');
        
        $venue_categories['venue_' . $safe_id] = $clean_name; // Save for the dropdown modal
        
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

$uploaded_media = []; // For 1-to-1 slots (Hero, Homepage Previews, 360s)
$gallery_items = [];  // General gallery
$standard_venue_photos = []; // For grouped venue galleries (Allows multiple)

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $slot = $row['slot_assignment'];
        
        if ($slot === 'gallery') {
            $gallery_items[] = $row;
        } elseif (strpos($slot, '_360') !== false || strpos($slot, 'home-') === 0) {
            // Strictly 1-to-1 slots (360s and Homepage Previews)
            $uploaded_media[$slot] = $row;
        } else {
            // It's a standard venue photo (allows multiples!)
            $standard_venue_photos[$slot][] = $row;
        }
    }
}
?>

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

        <!-- 2. 360 PANORAMA SLOTS (1 per venue) -->
        <?php foreach($venue_360_slots as $slot_key => $slot_info): 
            $has_img = isset($uploaded_media[$slot_key]);
            $img_path = $has_img ? $uploaded_media[$slot_key]['file_path'] : 'assets/img/placeholder.jpg';
        ?>
        <div class="cms-card" data-type="360">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?> <img src="<?php echo htmlspecialchars($img_path); ?>"> <?php else: ?> <span
                    style="color:#888;">Empty Slot</span> <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gold"><?php echo $slot_info['category_badge']; ?></span>
                </div>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="360">
                        <?php echo $has_img ? 'Replace' : 'Upload'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 3. STANDARD VENUE PHOTOS (Multiple Allowed!) -->
        <?php foreach($standard_venue_photos as $slot_key => $photos_array): ?>
        <?php foreach($photos_array as $photo): ?>
        <div class="cms-card" data-type="standard">
            <div class="cms-img-wrapper">
                <img src="<?php echo htmlspecialchars($photo['file_path']); ?>">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">
                        <?php echo isset($venue_categories[$slot_key]) ? $venue_categories[$slot_key] : 'Unknown Venue'; ?>
                    </h4>
                    <span class="badge badge-gray">Standard Photo Gallery</span>
                </div>
                <p class="cms-size">File: <?php echo htmlspecialchars($photo['file_name']); ?></p>
                <div class="cms-actions">
                    <!-- Standard photos can be deleted because we can have multiples! -->
                    <button class="btn-delete btn-delete-media" data-id="<?php echo $photo['id']; ?>">Delete
                        Photo</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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

<!-- UPLOAD MODAL -->
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
                    <option value="360">360 Panorama (1 Per Venue)</option>
                </select>
            </div>

            <div class="cms-form-group">
                <label>Assign to Website Slot</label>
                <select name="website_slot" id="modal-website-slot" required>
                    <option value="" disabled selected>Select where this image goes...</option>

                    <optgroup label="System Slots">
                        <!-- Updated to include the Homepage Slots! -->
                        <option value="home-hero" data-type="standard" style="display:none;">Landing Page - Hero Banner
                        </option>
                        <option value="home-eventhall" data-type="standard" style="display:none;">Homepage - Event Hall
                            Preview</option>
                        <option value="home-villa" data-type="standard" style="display:none;">Homepage - Villa Preview
                        </option>
                        <option value="home-hotel" data-type="standard" style="display:none;">Homepage - Hotel Preview
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