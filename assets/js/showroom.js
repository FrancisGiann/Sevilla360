document.addEventListener("DOMContentLoaded", () => {
  const dataMap = window.showroomData || {};
  let currentGallery = [];
  let currentImageIndex = 0;
  let panoCache = {};
  let currentRoomId = null;

  // Variables for Zooming & Dragging
  let currentZoom = 1;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let startX = 0;
  let startY = 0;

  // --- 1. Init Panolens 360 Viewer ---
  const panoContainer = document.getElementById("pano-container");
  const viewer = new PANOLENS.Viewer({
    container: panoContainer,
    controlBar: false, // We built custom buttons
    autoRotate: true,
    autoRotateSpeed: 0.5,
    antialias: true, // Fix for blurriness
    cameraFov: 85, // Fix for blurriness
  });

  // Force high-resolution rendering for modern monitors
  viewer.renderer.setPixelRatio(window.devicePixelRatio);

  // CRITICAL FIX FOR RESIZING BUG: 
  // Tell the 3D camera to recalculate the screen dimensions whenever the window resizes!
  window.addEventListener('resize', () => {
      if (viewer && panoContainer.style.visibility === "visible") {
          viewer.onWindowResize();
      }
  });

  // Custom 360 Controls
  // HARD RELOAD: Safely stops downloads, dumps memory, and re-downloads
  document.getElementById("btn-reload-pano")?.addEventListener("click", () => {
      if (!currentRoomId) return;
      const room = dataMap[currentRoomId];
      if (!room || !room.pano_url) return;

      const panoLoadingOverlay = document.getElementById("pano-loading-overlay");
      if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

      // Safely destroy the old panorama
      if (panoCache[currentRoomId]) {
          const oldPano = panoCache[currentRoomId];
          
          // 1. Remove it from the viewer first
          viewer.remove(oldPano);
          
          // 2. Dispose of materials and geometry safely to free memory
          if (oldPano.material) {
              if (oldPano.material.map) oldPano.material.map.dispose();
              oldPano.material.dispose();
          }
          if (oldPano.geometry) oldPano.geometry.dispose();

          delete panoCache[currentRoomId];
      }

      // 3. Cache Busting: Force download a fresh copy
      let cleanUrl = room.pano_url.split('?')[0]; 
      room.pano_url = cleanUrl + '?t=' + new Date().getTime(); 

      // 4. Reload the room
      loadRoom(currentRoomId);
  });

  // Zoom In/Out Buttons
  document.getElementById("btn-zoom-in")?.addEventListener("click", () => {
    viewer.camera.fov = Math.max(30, viewer.camera.fov - 10);
    viewer.camera.updateProjectionMatrix(); 
  });

  document.getElementById("btn-zoom-out")?.addEventListener("click", () => {
    viewer.camera.fov = Math.min(100, viewer.camera.fov + 10); 
    viewer.camera.updateProjectionMatrix(); 
  });

  // Fullscreen Button
  document.getElementById("btn-fullscreen")?.addEventListener("click", () => {
    if (!document.fullscreenElement) {
      panoContainer.requestFullscreen().then(() => {
        setTimeout(() => viewer.onWindowResize(), 100); // Tell 3D engine to redraw to fill screen
      });
    } else {
      document.exitFullscreen().then(() => {
        setTimeout(() => viewer.onWindowResize(), 100); // Tell 3D engine to shrink back down
      });
    }
  });

  // Backup listener in case they use the phone's native "Back" button to exit fullscreen
  document.addEventListener("fullscreenchange", () => {
    setTimeout(() => viewer.onWindowResize(), 100);
  });

  // --- 2. UI Elements ---
  const valTitle = document.getElementById("val-title");
  const valCategory = document.getElementById("val-category");
  const valCapacity = document.getElementById("val-capacity");
  const valStatus = document.getElementById("val-status");
  const valRate = document.getElementById("val-rate");
  const galleryTitle = document.getElementById("gallery-title");
  const btnViewPhotos = document.getElementById("btn-view-photos");
  const viewerControls = document.getElementById("viewer-controls");
  const panoLoadingOverlay = document.getElementById("pano-loading-overlay");

  // NEW: Target your description and amenities container
  const valDesc = document.getElementById("val-desc");
  const amenitiesGrid = document.querySelector(".amenities-grid");

  // --- Create a "No 360" Image Overlay ---
  const no360Wrapper = document.createElement("div");
  no360Wrapper.className = "ui-360"; // Hides automatically in Photo Mode
  no360Wrapper.style.cssText =
    "position:absolute; top:0; left:0; width:100%; height:100%; display:none; flex-direction:column; align-items:center; justify-content:center; z-index:5; background-size: cover; background-position: center;";

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
  document.querySelector(".big-viewer-box").appendChild(no360Wrapper);

  // --- 3. Load Room Logic ---
  function loadRoom(roomId) {
    currentRoomId = roomId;
    const room = dataMap[roomId];
    if (!room) return;

    // Update Text Details
    valTitle.textContent = room.title;
    valCategory.textContent = room.category;
    valCapacity.textContent = room.capacity;
    valStatus.textContent = room.status;
    valRate.textContent = room.rate;
    galleryTitle.textContent = room.title + " Gallery";

    // NEW: Update Description
    if (valDesc) valDesc.textContent = room.description;

    // NEW: Update Amenities Grid
    if (amenitiesGrid && room.amenities) {
      amenitiesGrid.innerHTML = ""; // Clear old icons

      // Map common amenities to FontAwesome icons dynamically
      const iconMap = {
        "free wi-fi": "fa-wifi",
        "fully air-conditioned": "fa-snowflake",
        "ample parking": "fa-square-parking",
        "wheelchair accessible": "fa-wheelchair",
        "private pool": "fa-water-ladder",
        "smart tv": "fa-tv",
        "mini-fridge": "fa-temperature-arrow-down",
      };

      room.amenities.forEach((item) => {
        const cleanItem = item.trim();
        if (cleanItem === "") return; // Skip empty strings

        const iconClass = iconMap[cleanItem.toLowerCase()] || "fa-check"; // Default to checkmark if icon not mapped

        const div = document.createElement("div");
        div.className = "amenity";
        div.innerHTML = `<i class="fa-solid ${iconClass}"></i> ${cleanItem}`;
        amenitiesGrid.appendChild(div);
      });
    }

    // Fetch Gallery first so we can use it as a fallback
    currentGallery = room.gallery || [];
    currentImageIndex = 0;

    // --- Handle 360 Panorama ---
    if (room.pano_url) {
      // 1. Show the 3D canvas and the UI controls
      no360Wrapper.style.display = "none";
      panoContainer.style.visibility = "visible";
      if (viewerControls) viewerControls.style.display = "flex"; // SHOW buttons

      // Check if we ALREADY loaded this 3D room before
      if (!panoCache[roomId]) {
        const originalTitle = room.title;
        valTitle.textContent = "Loading 360... Please wait";

        // 2. Show the placeholder loading screen while downloading
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

        const panorama = new PANOLENS.ImagePanorama(room.pano_url);
        panorama.addEventListener("load", function () {
          valTitle.textContent = originalTitle;
          // 3. Hide the placeholder loading screen once downloaded
          if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
        });

        viewer.add(panorama);
        panoCache[roomId] = panorama; // Save it to memory!
      } else {
        // If it's already in memory, make sure the loading screen is hidden!
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
      }

      // Instantly switch to the room in memory
      viewer.setPanorama(panoCache[roomId]);
    } else {
      // NO 360: Set the background image (Use room photo, fallback to placeholder)
      const bgImg =
        currentGallery.length > 0
          ? currentGallery[0]
          : "assets/img/placeholder.jpg";
      no360Wrapper.style.backgroundImage = `url('${bgImg}')`;

      // Show the image overlay, hide the 3D canvas AND hide the controls
      no360Wrapper.style.display = "flex";
      panoContainer.style.visibility = "hidden";
      if (viewerControls) viewerControls.style.display = "none"; // HIDE buttons
      valTitle.textContent = room.title;
    }

    // --- Handle Photo Button & Gallery State ---
    if (currentGallery.length === 0) {
      btnViewPhotos.disabled = true;
      btnViewPhotos.innerText = "NO PHOTOS";
      // CRITICAL FIX: Erase the previous room's photo!
      document.getElementById("current-slide-img").src =
        "assets/img/placeholder.jpg";

      // If they click a room with no photos WHILE in photo mode, kick them back to the 360 view
      if (
        document
          .getElementById("showroom-wrapper")
          .classList.contains("mode-photos")
      ) {
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

  // --- 6. Gallery Slider & Filmstrip Logic ---
  const thumbnailStrip = document.getElementById("thumbnail-strip");
  const currentSlideImg = document.getElementById("current-slide-img");

  // Master function to change photos AND highlight the correct thumbnail
  function updateGalleryUI(index) {
    if (currentGallery.length === 0) return;
    currentImageIndex = index;

    // --- RESET ZOOM & PAN ---
    currentZoom = 1;
    panX = 0;
    panY = 0;
    currentSlideImg.style.transform = `translate(0px, 0px) scale(1)`;
    currentSlideImg.style.cursor = "default";

    // 1. Change big image
    currentSlideImg.src = currentGallery[currentImageIndex];

    // 2. Update thumbnail active states
    const thumbs = thumbnailStrip.querySelectorAll(".thumb-img");
    thumbs.forEach((t, i) => {
      if (i === currentImageIndex) {
        t.classList.add("active");
        // Auto-scroll the filmstrip so the active thumb is always visible
        t.scrollIntoView({
          behavior: "smooth",
          inline: "center",
          block: "nearest",
        });
      } else {
        t.classList.remove("active");
      }
    });
    // 3. Update the counter
    document.getElementById("gallery-counter").innerText =
      `• ${currentImageIndex + 1} / ${currentGallery.length}`;

    // --- 4. Slide Animation Effect ---
    // Remove old animation classes
    currentSlideImg.classList.remove("slide-in-left", "slide-in-right");

    // Force browser reflow to reset the animation
    void currentSlideImg.offsetWidth;

    // Determine which direction they clicked/swiped
    if (window.lastGalleryDirection === "prev") {
      currentSlideImg.classList.add("slide-in-left");
    } else {
      currentSlideImg.classList.add("slide-in-right"); // Default to right
    }

    // Change image source instantly
    currentSlideImg.src = currentGallery[currentImageIndex];
  }

  // Function to generate the HTML for the thumbnails
  function generateThumbnails() {
    thumbnailStrip.innerHTML = ""; // Clear old ones
    currentGallery.forEach((imgUrl, i) => {
      const thumb = document.createElement("img");
      thumb.src = imgUrl;
      thumb.className = i === 0 ? "thumb-img active" : "thumb-img";

      // If they click a thumbnail, jump straight to that photo!
      thumb.addEventListener("click", () => updateGalleryUI(i));

      thumbnailStrip.appendChild(thumb);
    });
  }

  // Left Arrow
  document.getElementById("slide-prev")?.addEventListener("click", () => {
    window.lastGalleryDirection = "prev"; // Tell animation to slide left
    let newIndex =
      (currentImageIndex - 1 + currentGallery.length) % currentGallery.length;
    updateGalleryUI(newIndex);
  });

  // Right Arrow
  document.getElementById("slide-next")?.addEventListener("click", () => {
    window.lastGalleryDirection = "next"; // Tell animation to slide right
    let newIndex = (currentImageIndex + 1) % currentGallery.length;
    updateGalleryUI(newIndex);
  });

  // When the user clicks "VIEW PHOTOS", generate the thumbnails!
  btnViewPhotos.addEventListener("click", () => {
    generateThumbnails(); // Build the filmstrip
    updateGalleryUI(0); // Start at photo 0
    window.scrollTo({ top: 0, behavior: "instant" });
    wrapper.classList.add("mode-photos");
    document.body.classList.add("no-scroll");
  });

  // --- 7. TRUE ZOOM TO CURSOR & DRAG TO PAN ---

  // A. Scroll to Zoom (Attached strictly to the IMAGE, not the background)
  currentSlideImg.addEventListener(
    "wheel",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      e.preventDefault();

      const oldZoom = currentZoom;

      // Zoom speed
      if (e.deltaY < 0) currentZoom += 0.2;
      else currentZoom -= 0.2;

      // Limit zoom between 1x and 5x
      currentZoom = Math.min(Math.max(currentZoom, 1), 5);

      if (currentZoom === 1) {
        // Snap back to center if fully zoomed out
        panX = 0;
        panY = 0;
        currentSlideImg.style.cursor = "default"; // Normal mouse pointer
      } else {
        // Get mouse position relative to the CENTER of the screen
        const mouseX = e.clientX - window.innerWidth / 2;
        const mouseY = e.clientY - window.innerHeight / 2;

        // Google Maps Math: Adjust pan to keep pixel locked under the cursor
        const scaleRatio = currentZoom / oldZoom;
        panX = mouseX - (mouseX - panX) * scaleRatio;
        panY = mouseY - (mouseY - panY) * scaleRatio;

        currentSlideImg.style.cursor = "grab"; // Show the open hand!
      }

      currentSlideImg.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
    },
    { passive: false },
  );

  // B. Click and Drag to Pan
  currentSlideImg.addEventListener("mousedown", (e) => {
    if (currentZoom > 1) {
      // Only allow dragging if zoomed in
      e.preventDefault();
      isDragging = true;
      currentSlideImg.style.cursor = "grabbing"; // Show the closed fist!
      startX = e.clientX - panX;
      startY = e.clientY - panY;
    }
  });

  window.addEventListener("mousemove", (e) => {
    if (!isDragging) return;
    panX = e.clientX - startX;
    panY = e.clientY - startY;
    currentSlideImg.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
  });

  window.addEventListener("mouseup", () => {
    if (isDragging) {
      isDragging = false;
      // Go back to the open hand when they let go of the mouse click
      if (currentZoom > 1) currentSlideImg.style.cursor = "grab";
    }
  });

  // --- 8. MOBILE SWIPE LOGIC FOR GALLERY ---
  let touchStartX = 0;
  let touchEndX = 0;

  // Listen for the start of the touch
  currentSlideImg.addEventListener(
    "touchstart",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchStartX = e.changedTouches[0].screenX;
    },
    { passive: true },
  );

  // CRITICAL FIX: Actively block the browser's "Swipe to go back" feature
  currentSlideImg.addEventListener(
    "touchmove",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;

      const touchCurrentX = e.changedTouches[0].screenX;
      const diffX = touchStartX - touchCurrentX;

      // If they move their finger horizontally by more than 10 pixels, stop the browser from doing anything!
      if (Math.abs(diffX) > 10) {
        e.preventDefault();
      }
    },
    { passive: false },
  ); // passive MUST be false for preventDefault() to work on touch events

  // Listen for the end of the touch
  currentSlideImg.addEventListener(
    "touchend",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchEndX = e.changedTouches[0].screenX;
      handleSwipe();
    },
    { passive: true },
  );

  function handleSwipe() {
    const swipeDistance = touchEndX - touchStartX;

    // Require a minimum distance of 50 pixels to count as a swipe
    if (swipeDistance < -50) {
      document.getElementById("slide-next")?.click(); // Swiped Left
    } else if (swipeDistance > 50) {
      document.getElementById("slide-prev")?.click(); // Swiped Right
    }
  }
});
