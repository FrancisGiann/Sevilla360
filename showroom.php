<?php
$page_title = 'Virtual Showroom | SEVILLA360';
$extra_css = 'assets/css/showroom.css';
$extra_js = 'assets/js/showroom.js';
$active_page = 'showroom';

include 'includes/header.php';
?>

<!-- Showroom Container -->
<section class="showroom-wrapper" id="showroom-wrapper">
    <div class="showroom-container">

        <!-- 1. The Big Viewer Box -->
        <div class="big-viewer-box">

            <!-- === 360 UI Elements === -->
            <div class="viewer-label ui-360">Showroom</div>
            <div class="viewer-controls ui-360">
                <button>+</button>
                <button>-</button>
                <button>⛶</button>
                <button>🧭</button>
            </div>
            <div id="pano-container" class="ui-360" style="width:100%; height:100%;"></div>

            <!-- === Photo Gallery UI Elements === -->
            <div class="photo-room-title ui-photos" id="gallery-title">Event Hall</div>

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
                <img src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=1920&q=80"
                    alt="Room Photo" id="current-slide-img">
            </div>

            <button class="btn-back ui-photos" id="btn-back-to-360">Back</button>

            <!-- === Shared Elements === -->
            <div class="room-pills">
                <button class="pill active" data-room="infinity">Infinity Hall</button>
                <button class="pill" data-room="villa">Villa</button>
                <button class="pill" data-room="standard">Standard Room</button>
                <button class="pill" data-room="deluxe">Deluxe Room</button>
                <button class="pill" data-room="family">Family Room</button>
            </div>
        </div>

        <!-- 2. The Details Block -->
        <div class="details-box">
            <!-- Left Side: Hall Details -->
            <div class="details-left">
                <h3 class="details-title">EVENT HALL DETAILS</h3>
                <div class="detail-row">
                    <span class="d-label">CURRENTLY VIEWING</span>
                    <span class="d-value" id="val-title">INFINITY HALL</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">CAPACITY</span>
                    <span class="d-value" id="val-capacity">Up to 1000 pax</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">IDEAL FOR</span>
                    <span class="d-value" id="val-ideal">Perfect for weddings</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">TECHNICAL AMENITIES</span>
                    <span class="d-value" id="val-tech">Equipped with a built-in sound system</span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="d-label">INCLUSIONS</span>
                    <span class="d-value" id="val-inc">Fully air-conditioned hall</span>
                </div>
            </div>

            <!-- Right Side: Action & Availability -->
            <div class="details-right">
                <h3 class="details-title">AVAILABILITY & ACTION</h3>
                <div class="detail-row">
                    <span class="d-label">Status</span>
                    <span class="d-value" id="val-status">Available</span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="d-label">Starting Rate</span>
                    <span class="d-value" id="val-rate">₱10,000 /day</span>
                </div>

                <div class="action-buttons">
                    <button class="btn-mock btn-book">BOOK THIS VENUE</button>
                    <button class="btn-mock btn-photos" id="btn-view-photos">VIEW PHOTOS</button>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- 1. Load Three.js (The 3D Engine) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

<!-- 2. Load Panolens.js (The 360 Viewer) -->
<script src="https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js"></script>
<?php 
include 'includes/footer.php'; 
?>