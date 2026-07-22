document.addEventListener("DOMContentLoaded", () => {
    
    const dataMap = window.showroomData || {};
    let currentGallery = [];
    let currentImageIndex = 0;

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

        // Load 360 Panorama
        if (room.pano_url) {
            const panorama = new PANOLENS.ImagePanorama(room.pano_url);
            viewer.add(panorama);
            viewer.setPanorama(panorama);
        }

        // Setup Photo Gallery
        currentGallery = room.gallery || [];
        currentImageIndex = 0;
        
        // Disable "View Photos" button if they haven't uploaded any standard photos yet
        if (currentGallery.length === 0) {
            btnViewPhotos.disabled = true;
            btnViewPhotos.style.opacity = '0.5';
            btnViewPhotos.innerText = "NO PHOTOS";
        } else {
            btnViewPhotos.disabled = false;
            btnViewPhotos.style.opacity = '1';
            btnViewPhotos.innerText = "VIEW PHOTOS";
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