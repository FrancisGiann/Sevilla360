document.addEventListener("DOMContentLoaded", () => {
    
    const dataMap = window.showroomData || {};
    let currentGallery = [];
    let currentImageIndex = 0;
    let panoCache = {};

    // --- 1. Init Panolens 360 Viewer ---
    const panoContainer = document.getElementById('pano-container');
    const viewer = new PANOLENS.Viewer({ 
        container: panoContainer,
        controlBar: false, // We built custom buttons
        autoRotate: true,
        autoRotateSpeed: 0.5
    });

    // Custom 360 Controls
    document.getElementById('btn-zoom-in')?.addEventListener('click', () => viewer.camera.fov -= 10);
    document.getElementById('btn-zoom-out')?.addEventListener('click', () => viewer.camera.fov += 10);
    document.getElementById('btn-fullscreen')?.addEventListener('click', () => {
        if (!document.fullscreenElement) panoContainer.requestFullscreen();
        else document.exitFullscreen();
    });

    // --- 2. UI Elements ---
    const valTitle = document.getElementById("val-title");
    const valCategory = document.getElementById("val-category");
    const valCapacity = document.getElementById("val-capacity");
    const valStatus = document.getElementById("val-status");
    const valRate = document.getElementById("val-rate");
    const galleryTitle = document.getElementById("gallery-title");
    const btnViewPhotos = document.getElementById("btn-view-photos");

    // --- Create a "No 360" Image Overlay ---
    const no360Wrapper = document.createElement('div');
    no360Wrapper.className = 'ui-360'; // Hides automatically in Photo Mode
    no360Wrapper.style.cssText = 'position:absolute; top:0; left:0; width:100%; height:100%; display:none; flex-direction:column; align-items:center; justify-content:center; z-index:5; background-size: cover; background-position: center;';
    
    // The HTML inside the overlay (A subtle dark tint + text)
    no360Wrapper.innerHTML = `
        <div style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4);"></div>
        <div style="position:relative; z-index:2; text-align:center; color:white;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:10px; opacity:0.9;">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            <h2 style="font-family: sans-serif; font-weight:500; font-size: 1.3rem; margin-bottom:5px; letter-spacing: 1px;">STANDARD VIEW</h2>
            <p style="font-family: sans-serif; opacity:0.9; font-size: 0.9rem;">No 360° tour available for this venue.</p>
        </div>
    `;
    document.querySelector('.big-viewer-box').appendChild(no360Wrapper);


    // --- 3. Load Room Logic ---
    function loadRoom(roomId) {
        const room = dataMap[roomId];
        if (!room) return;

        // Update Text Details
        valTitle.textContent = room.title;
        valCategory.textContent = room.category;
        valCapacity.textContent = room.capacity;
        valStatus.textContent = room.status;
        valRate.textContent = room.rate;
        galleryTitle.textContent = room.title + " Gallery";

        // Fetch Gallery first so we can use it as a fallback
        currentGallery = room.gallery || [];
        currentImageIndex = 0;

        // --- Handle 360 Panorama ---
        if (room.pano_url) {
            // Hide the image overlay, show the 3D canvas
            no360Wrapper.style.display = 'none';
            panoContainer.style.visibility = 'visible';

            // Check if we ALREADY loaded this 3D room before
            if (!panoCache[roomId]) {
                const originalTitle = room.title;
                valTitle.textContent = "Loading 360... Please wait";

                const panorama = new PANOLENS.ImagePanorama(room.pano_url);
                panorama.addEventListener('load', function() {
                    valTitle.textContent = originalTitle;
                });

                viewer.add(panorama);
                panoCache[roomId] = panorama; // Save it to memory!
            }
            
            // Instantly switch to the room in memory
            viewer.setPanorama(panoCache[roomId]);
            
        } else {
            // NO 360: Set the background image (Use room photo, fallback to placeholder)
            const bgImg = currentGallery.length > 0 ? currentGallery[0] : 'assets/img/placeholder.jpg';
            no360Wrapper.style.backgroundImage = `url('${bgImg}')`;
            
            // Show the image overlay, hide the 3D canvas
            no360Wrapper.style.display = 'flex';
            panoContainer.style.visibility = 'hidden';
            valTitle.textContent = room.title;
        }
        
        // --- Handle Photo Button & Gallery State ---
        if (currentGallery.length === 0) {
            btnViewPhotos.disabled = true;
            btnViewPhotos.innerText = "NO PHOTOS";
            // CRITICAL FIX: Erase the previous room's photo!
            document.getElementById("current-slide-img").src = 'assets/img/placeholder.jpg'; 
            
            // If they click a room with no photos WHILE in photo mode, kick them back to the 360 view
            if (document.getElementById("showroom-wrapper").classList.contains("mode-photos")) {
                document.getElementById("btn-back-to-360").click();
            }
        } else {
            btnViewPhotos.disabled = false;
            btnViewPhotos.innerText = "VIEW PHOTOS";
            // Show the new room's first photo
            document.getElementById("current-slide-img").src = currentGallery[0];
        }
    }

    // --- 4. Pill Click Listeners ---
    const pills = document.querySelectorAll(".pill");
    pills.forEach((pill) => {
        pill.addEventListener("click", function () {
            pills.forEach((p) => p.classList.remove("active"));
            this.classList.add("active");
            loadRoom(this.getAttribute("data-room"));
        });
    });

    // Initialize first room on load
    if (pills.length > 0) {
        loadRoom(pills[0].getAttribute("data-room"));
    }

    // --- 5. Gallery Mode Swap Logic ---
    const btnBackTo360 = document.getElementById("btn-back-to-360");
    const wrapper = document.getElementById("showroom-wrapper");

    btnViewPhotos.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "instant" });
        wrapper.classList.add("mode-photos");
        document.body.classList.add("no-scroll");
    });

    btnBackTo360.addEventListener("click", () => {
        wrapper.classList.remove("mode-photos");
        document.body.classList.remove("no-scroll");
    });

    // --- 6. Gallery Slider Logic ---
    document.getElementById("slide-next")?.addEventListener("click", () => {
        if (currentGallery.length === 0) return;
        currentImageIndex = (currentImageIndex + 1) % currentGallery.length;
        document.getElementById("current-slide-img").src = currentGallery[currentImageIndex];
    });

    document.getElementById("slide-prev")?.addEventListener("click", () => {
        if (currentGallery.length === 0) return;
        currentImageIndex = (currentImageIndex - 1 + currentGallery.length) % currentGallery.length;
        document.getElementById("current-slide-img").src = currentGallery[currentImageIndex];
    });

});