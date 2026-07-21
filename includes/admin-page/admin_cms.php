<?php
require_once 'config/db_connect.php';

// 1. Fetch all real venues from your database!
$venues_query = $conn->query("SELECT id, name, category FROM venues");

// 2. Build dynamic website slots
$website_slots = [
    'home-hero' => ['title' => 'Landing Page - Hero Banner', 'badge' => 'System', 'type' => 'standard']
];

// Automatically create a picture slot for every physical venue in the resort
if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        $clean_name = htmlspecialchars($v['name']);
        
        // Slot for standard picture
        $website_slots['venue_' . $v['id'] . '_std'] = [
            'title' => $clean_name . ' (Standard Photo)',
            'badge' => $v['category'],
            'type' => 'standard'
        ];
        
        // Slot for 360 panorama
        $website_slots['venue_' . $v['id'] . '_360'] = [
            'title' => $clean_name . ' (360 View)',
            'badge' => '360 Panorama',
            'type' => '360'
        ];
    }
}

// 3. Fetch all uploaded media
$query = "SELECT * FROM media_cms";
$result = $conn->query($query);
$uploaded_media = [];
$gallery_items = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        if ($row['slot_assignment'] === 'gallery') {
            $gallery_items[] = $row;
        } else {
            $uploaded_media[$row['slot_assignment']] = $row;
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

        <!-- DYNAMIC SYSTEM & VENUE SLOTS -->
        <?php foreach($website_slots as $slot_key => $slot_info): 
            $has_img = isset($uploaded_media[$slot_key]);
            $img_path = $has_img ? $uploaded_media[$slot_key]['file_path'] : 'assets/img/placeholder.jpg';
            $file_name = $has_img ? $uploaded_media[$slot_key]['file_name'] : 'No file uploaded yet.';
            $badge_class = ($slot_info['type'] === '360') ? 'badge-gold' : 'badge-gray';
        ?>
        <div class="cms-card" data-type="<?php echo $slot_info['type']; ?>">
            <div class="cms-img-wrapper"
                style="background: #e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <img src="<?php echo htmlspecialchars($img_path); ?>" alt="<?php echo $slot_info['title']; ?>">
                <?php else: ?>
                <span style="color: #888; font-weight: 500;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge <?php echo $badge_class; ?>"><?php echo $slot_info['badge']; ?></span>
                </div>
                <p class="cms-size">File: <?php echo htmlspecialchars($file_name); ?></p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="<?php echo $slot_key; ?>"
                        data-type="<?php echo $slot_info['type']; ?>">
                        <?php echo $has_img ? 'Replace' : 'Upload'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- GENERAL GALLERY ITEMS -->
        <?php foreach($gallery_items as $item): ?>
        <div class="cms-card" data-type="<?php echo $item['media_type']; ?>">
            <div class="cms-img-wrapper">
                <img src="<?php echo htmlspecialchars($item['file_path']); ?>" alt="Gallery Image">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Gallery Image</h4>
                    <span class="badge badge-gray">General Gallery</span>
                </div>
                <p class="cms-size">File: <?php echo htmlspecialchars($item['file_name']); ?></p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal" data-slot="gallery"
                        data-type="<?php echo $item['media_type']; ?>">Replace</button>
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
                <input type="file" id="fileInput" accept="image/jpeg, image/png, image/webp" hidden>
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

                    <!-- Dynamic Options -->
                    <option value="home-hero" data-type="standard" style="display:none;">Landing Page - Hero Banner
                    </option>
                    <option value="gallery" data-type="standard" style="display:none;">General Gallery (Standard Photo)
                    </option>
                    <option value="gallery" data-type="360" style="display:none;">General Gallery (360 Panorama)
                    </option>

                    <?php foreach($website_slots as $key => $slot): ?>
                    <option value="<?php echo $key; ?>" data-type="<?php echo $slot['type']; ?>" style="display:none;">
                        <?php echo $slot['title']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cms-modal-actions">
                <button type="button" class="btn cms-btn-outline" id="btnCloseModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>