/**
 * ==========================================================================
 * SEVILLA360 - Virtual Showroom Controller
 * Handles 360 Panolens Viewer, Dynamic Galleries, and Mobile Touch Events
 * ==========================================================================
 */
document.addEventListener("DOMContentLoaded", () => {
  
  // --- 1. Global State Variables ---
  const dataMap = window.showroomData || {};
  let currentGallery = [];
  let currentImageIndex = 0;
  
  let panoCache = {};
  let currentRoomId = null; 
  let currentPanoIndex = 0; 
  let activePanoramas = []; 

  let currentZoom = 1;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let startX = 0;
  let startY = 0;

  // --- 2. DOM Elements ---
  const panoContainer = document.getElementById("pano-container");
  const viewerControls = document.getElementById("viewer-controls");
  const panoLoadingOverlay = document.getElementById("pano-loading-overlay");
  
  const valTitle = document.getElementById("val-title");
  const valCategory = document.getElementById("val-category");
  const valCapacity = document.getElementById("val-capacity");
  const valStatus = document.getElementById("val-status");
  const valRate = document.getElementById("val-rate");
  const valDesc = document.getElementById("val-desc");
  const amenitiesGrid = document.querySelector(".amenities-grid");
  const galleryTitle = document.getElementById("gallery-title");
  const btnViewPhotos = document.getElementById("btn-view-photos");
  const currentSlideImg = document.getElementById("current-slide-img");
  const wrapper = document.getElementById("showroom-wrapper");
  const topRoomLabel = document.getElementById("top-room-label");

  // --- 3. Create "No 360" Fallback Overlay ---
  const no360Wrapper = document.createElement("div");
  no360Wrapper.className = "ui-360-placeholder"; 
  no360Wrapper.style.cssText = "position:absolute; top:0; left:0; width:100%; height:100%; display:none; flex-direction:column; align-items:center; justify-content:center; z-index:5; background-size: cover; background-position: center;";
  no360Wrapper.innerHTML = `
      <div style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6);"></div>
      <div style="position:relative; z-index:1000; text-align:center; color:white; padding: 20px;">
          <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:15px; opacity:0.9;">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
              <circle cx="8.5" cy="8.5" r="1.5"></circle>
              <polyline points="21 15 16 10 5 21"></polyline>
          </svg>
          <h2 style="font-family: var(--font-body), sans-serif; font-weight:600; font-size: 1.4rem; margin-bottom:8px; letter-spacing: 1px;">STANDARD VIEW</h2>
          <p style="font-family: var(--font-body), sans-serif; opacity:0.8; font-size: 0.95rem;">No 360° tour available for this venue.</p>
      </div>
  `;
  document.querySelector(".big-viewer-box").appendChild(no360Wrapper);

  // --- 4. Initialize Panolens 360 Viewer ---
  if (!panoContainer) return;

  const viewer = new PANOLENS.Viewer({
    container: panoContainer,
    controlBar: false, 
    autoRotate: true,
    autoRotateSpeed: 0.5,
    antialias: true, 
    cameraFov: 85    
  });
  
  viewer.renderer.setPixelRatio(window.devicePixelRatio);

  window.addEventListener('resize', () => {
      if (viewer && panoContainer.style.visibility === "visible") {
          viewer.onWindowResize();
      }
  });

  // --- 5. Custom 360 UI Controls ---
  document.getElementById("btn-zoom-in")?.addEventListener("click", () => {
      viewer.camera.fov = Math.max(30, viewer.camera.fov - 10); 
      viewer.camera.updateProjectionMatrix(); 
  });
  
  document.getElementById("btn-zoom-out")?.addEventListener("click", () => {
      viewer.camera.fov = Math.min(100, viewer.camera.fov + 10); 
      viewer.camera.updateProjectionMatrix(); 
  });
  
  document.getElementById("btn-fullscreen")?.addEventListener("click", () => {
      if (!document.fullscreenElement) {
          panoContainer.requestFullscreen().then(() => { setTimeout(() => viewer.onWindowResize(), 100); });
      } else {
          document.exitFullscreen().then(() => { setTimeout(() => viewer.onWindowResize(), 100); });
      }
  });
  document.addEventListener('fullscreenchange', () => setTimeout(() => viewer.onWindowResize(), 100));

  // Switch between multiple panoramas
  document.getElementById("btn-switch-pano")?.addEventListener("click", () => {
      if (activePanoramas.length > 1) {
          currentPanoIndex = (currentPanoIndex + 1) % activePanoramas.length;
          viewer.setPanorama(activePanoramas[currentPanoIndex]);
      }
  });

  // Hard Reload (Memory Flush)
  document.getElementById("btn-reload-pano")?.addEventListener("click", () => {
      if (!currentRoomId) return;
      const room = dataMap[currentRoomId];
      if (!room || !room.pano_urls || room.pano_urls.length === 0) return;

      if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

      if (panoCache[currentRoomId]) {
          panoCache[currentRoomId].forEach(oldPano => {
              viewer.remove(oldPano);
              if (oldPano.material) {
                  if (oldPano.material.map) oldPano.material.map.dispose();
                  oldPano.material.dispose();
              }
              if (oldPano.geometry) oldPano.geometry.dispose();
          });
          delete panoCache[currentRoomId];
      }

      room.pano_urls = room.pano_urls.map(url => {
          let cleanUrl = url.split('?')[0]; 
          return cleanUrl + '?t=' + new Date().getTime(); 
      });

      loadRoom(currentRoomId);
  });

  // --- 6. Info Modal Logic (Mobile) ---
  const infoModal = document.getElementById("info-modal");
  document.getElementById("btn-info")?.addEventListener("click", () => {
      if (!currentRoomId) return;
      const room = dataMap[currentRoomId];
      if (!room) return;

      document.getElementById("info-modal-title").innerText = room.title;
      document.getElementById("info-modal-desc").innerText = room.description;
      document.getElementById("info-modal-cap").innerText = room.capacity;
      document.getElementById("info-modal-rate").innerText = room.rate;

      const modalAmenities = document.getElementById("info-modal-amenities");
      if (modalAmenities && amenitiesGrid) {
          modalAmenities.innerHTML = amenitiesGrid.innerHTML;
      }
      infoModal.classList.add("active");
  });

  document.getElementById("btn-close-info")?.addEventListener("click", () => infoModal.classList.remove("active"));
  window.addEventListener("click", (e) => { if (e.target === infoModal) infoModal.classList.remove("active"); });

  // --- 7. Main Room Loading Logic ---
  function loadRoom(roomId) {
    currentRoomId = roomId; 
    const room = dataMap[roomId];
    if (!room) return;

    // Update Text Data
    valTitle.textContent = room.title;
    valCategory.textContent = room.category;
    valCapacity.textContent = room.capacity;
    valStatus.textContent = room.status;
    valRate.textContent = room.rate;
    galleryTitle.textContent = room.title + " Gallery";
    if (valDesc) valDesc.textContent = room.description;
    if (topRoomLabel) topRoomLabel.textContent = room.title;

    // Dynamic Amenities Icons
    if (amenitiesGrid && room.amenities) {
        amenitiesGrid.innerHTML = ""; 
        const iconMap = {
            "free wi-fi": "fa-wifi", "wifi": "fa-wifi",
            "fully air-conditioned": "fa-snowflake", "ac ": "fa-snowflake", "air ": "fa-snowflake",
            "ample parking": "fa-square-parking", "park": "fa-square-parking",
            "wheelchair accessible": "fa-wheelchair", "pwd": "fa-wheelchair",
            "private pool": "fa-water-ladder", "pool": "fa-water-ladder", "swim": "fa-water-ladder",
            "smart tv": "fa-tv", "tv": "fa-tv",
            "mini-fridge": "fa-temperature-arrow-down", "fridge": "fa-temperature-arrow-down",
            "breakfast": "fa-utensils", "food": "fa-utensils",
            "gym": "fa-dumbbell", "fitness": "fa-dumbbell",
            "bed": "fa-bed",
            "bath": "fa-bath", "shower": "fa-bath"
        };

        room.amenities.forEach(item => {
            const cleanItem = item.trim();
            if (cleanItem === "") return; 
            
            const lowerText = cleanItem.toLowerCase();
            let iconClass = "fa-check"; 
            for (const [key, val] of Object.entries(iconMap)) {
                if (lowerText.includes(key)) { iconClass = val; break; }
            }
            
            const div = document.createElement("div");
            div.className = "amenity";
            div.innerHTML = `<i class="fa-solid ${iconClass}"></i> ${cleanItem}`;
            amenitiesGrid.appendChild(div);
        });
    }

    currentGallery = room.gallery || [];
    currentImageIndex = 0;

    // 360 Engine Routing
    const panoUrls = room.pano_urls || [];
    const btnSwitch = document.getElementById("btn-switch-pano");
    const btnInfo = document.getElementById("btn-info"); // We want to hide this if no 360 too

    if (panoUrls.length > 0) {
      // Show 3D Canvas
      no360Wrapper.style.display = "none";
      panoContainer.style.visibility = "visible";
      if (viewerControls) viewerControls.style.display = "flex"; 
      if (btnSwitch) btnSwitch.style.display = (panoUrls.length > 1) ? "block" : "none";
      if (btnInfo) btnInfo.style.display = "flex";

      if (!panoCache[roomId]) {
        valTitle.textContent = "Loading 360...";
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

        const panoramas = [];
        let loadedCount = 0;

        panoUrls.forEach((url, index) => {
            const pano = new PANOLENS.ImagePanorama(url);
            pano.addEventListener("load", function () {
                loadedCount++;
                if (index === 0 && panoLoadingOverlay) {
                    valTitle.textContent = room.title;
                    panoLoadingOverlay.style.display = "none";
                }
            });
            viewer.add(pano);
            panoramas.push(pano);
        });
        panoCache[roomId] = panoramas; 
      } else {
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
      }

      activePanoramas = panoCache[roomId];
      currentPanoIndex = 0;
      viewer.setPanorama(activePanoramas[currentPanoIndex]);

    } else {
      // Fallback: No 360 Uploaded
      const bgImg = currentGallery.length > 0 ? currentGallery[0] : "assets/img/placeholder.jpg";
      no360Wrapper.style.backgroundImage = `url('${bgImg}')`;
      no360Wrapper.style.display = "flex";
      
      panoContainer.style.visibility = "hidden";
      if (viewerControls) viewerControls.style.display = "none";
      if (btnSwitch) btnSwitch.style.display = "none";
      if (btnInfo) btnInfo.style.display = "none";
      valTitle.textContent = room.title;
    }

    // Photo Button Status
    if (currentGallery.length === 0) {
      btnViewPhotos.disabled = true;
      btnViewPhotos.innerText = "NO PHOTOS";
      currentSlideImg.src = "assets/img/placeholder.jpg";
      if (wrapper.classList.contains("mode-photos")) document.getElementById("btn-back-to-360").click();
    } else {
      btnViewPhotos.disabled = false;
      btnViewPhotos.innerText = "VIEW PHOTOS";
      currentSlideImg.src = currentGallery[0];
    }
  }

  // --- 8. Dropdown Pill Navigation Initialization ---
  const masterPills = document.querySelectorAll(".master-pill");
  const dropdownItems = document.querySelectorAll(".dropdown-item");

  // 1. Master Category Click (Toggle Menus)
  masterPills.forEach(master => {
      master.addEventListener("click", function(e) {
          e.stopPropagation(); // Stop click from hitting the window
          
          const wrapper = this.parentElement;
          const isOpen = wrapper.classList.contains("open");

          // Close all menus and flip all arrows down
          document.querySelectorAll(".pill-dropdown-wrapper").forEach(w => w.classList.remove("open"));
          masterPills.forEach(m => m.classList.remove("menu-open"));

          // If it wasn't open, open it!
          if (!isOpen) {
              wrapper.classList.add("open");
              this.classList.add("menu-open");
          }
      });
  });

  // Close menus if clicking anywhere else on the screen
  window.addEventListener("click", () => {
      document.querySelectorAll(".pill-dropdown-wrapper").forEach(w => w.classList.remove("open"));
      masterPills.forEach(m => m.classList.remove("menu-open"));
  });

  // 2. Specific Room Click Logic
  dropdownItems.forEach(item => {
      item.addEventListener("click", function() {
          // Remove active state from all items and master pills
          dropdownItems.forEach(i => i.classList.remove("active"));
          masterPills.forEach(m => m.classList.remove("active"));

          // Set this item to active
          this.classList.add("active");
          
          // Set its parent Master Pill to active
          const parentWrapper = this.closest(".pill-dropdown-wrapper");
          const parentMaster = parentWrapper.querySelector(".master-pill");
          if (parentMaster) parentMaster.classList.add("active");

          // Close the menu
          parentWrapper.classList.remove("open");
          parentMaster.classList.remove("menu-open");

          // Load the room!
          loadRoom(this.getAttribute("data-room"));
      });
  });

  // 3. Initialization & URL Hash Logic
  if (dropdownItems.length > 0) {
      let targetItem = dropdownItems[0]; 
      
      const hash = window.location.hash.replace('#', '');
      if (hash) {
          const hashItem = document.querySelector(`.dropdown-item[data-room="${hash}"]`);
          if (hashItem) targetItem = hashItem;
      }
      
      // We don't need to "click" it because PHP already set the classes to active on load!
      // We just need to load the 360 engine for it.
      loadRoom(targetItem.getAttribute("data-room"));
  }

  // --- 9. Photo Gallery Swap Mode ---
  const btnBackTo360 = document.getElementById("btn-back-to-360");
  btnViewPhotos.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "instant" });
    wrapper.classList.add("mode-photos");
    document.body.classList.add("no-scroll");
  });
  btnBackTo360.addEventListener("click", () => {
    wrapper.classList.remove("mode-photos");
    document.body.classList.remove("no-scroll");
  });

  // --- 10. Photo Slider Logic ---
  function updateGalleryUI(index) {
    if (currentGallery.length === 0) return;
    currentImageIndex = index;
    currentZoom = 1; panX = 0; panY = 0;
    
    currentSlideImg.style.transform = `translate(0px, 0px) scale(1)`;
    currentSlideImg.style.cursor = "default";
    currentSlideImg.classList.remove("slide-in-left", "slide-in-right");
    
    void currentSlideImg.offsetWidth; // Reflow for animation
    
    if (window.lastGalleryDirection === "prev") currentSlideImg.classList.add("slide-in-left");
    else currentSlideImg.classList.add("slide-in-right");

    currentSlideImg.src = currentGallery[currentImageIndex]; 
    document.getElementById("gallery-counter").innerText = `${currentImageIndex + 1} / ${currentGallery.length}`;
  }

  document.getElementById("slide-prev")?.addEventListener("click", () => {
    window.lastGalleryDirection = "prev"; 
    updateGalleryUI((currentImageIndex - 1 + currentGallery.length) % currentGallery.length);
  });

  document.getElementById("slide-next")?.addEventListener("click", () => {
    window.lastGalleryDirection = "next"; 
    updateGalleryUI((currentImageIndex + 1) % currentGallery.length);
  });

  // --- 11. Desktop Zoom & Pan ---
  currentSlideImg.addEventListener("wheel", (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      e.preventDefault();
      const oldZoom = currentZoom;
      currentZoom += (e.deltaY < 0) ? 0.2 : -0.2;
      currentZoom = Math.min(Math.max(currentZoom, 1), 5);

      if (currentZoom === 1) {
        panX = 0; panY = 0;
        currentSlideImg.style.cursor = "default"; 
      } else {
        const mouseX = e.clientX - window.innerWidth / 2;
        const mouseY = e.clientY - window.innerHeight / 2;
        const scaleRatio = currentZoom / oldZoom;
        panX = mouseX - (mouseX - panX) * scaleRatio;
        panY = mouseY - (mouseY - panY) * scaleRatio;
        currentSlideImg.style.cursor = "grab"; 
      }
      currentSlideImg.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
  }, { passive: false });

  currentSlideImg.addEventListener("mousedown", (e) => {
    if (currentZoom > 1) {
      e.preventDefault();
      isDragging = true;
      currentSlideImg.style.cursor = "grabbing"; 
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
      if (currentZoom > 1) currentSlideImg.style.cursor = "grab";
    }
  });

  // --- 12. Mobile Native Swipe Interceptor ---
  let touchStartX = 0;
  let touchEndX = 0;

  currentSlideImg.addEventListener('touchstart', (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchStartX = e.changedTouches[0].screenX;
  }, { passive: true });

  currentSlideImg.addEventListener('touchmove', (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      const touchCurrentX = e.changedTouches[0].screenX;
      if (Math.abs(touchStartX - touchCurrentX) > 10) e.preventDefault(); // Block browser "Back" swipe
  }, { passive: false });

  currentSlideImg.addEventListener('touchend', (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchEndX = e.changedTouches[0].screenX;
      const diff = touchEndX - touchStartX;
      if (diff < -50) document.getElementById("slide-next")?.click(); 
      else if (diff > 50) document.getElementById("slide-prev")?.click(); 
  }, { passive: true });

});