document.addEventListener("DOMContentLoaded", () => {
  const dataMap = window.showroomData || {};
  let currentGallery = [];
  let currentImageIndex = 0;
  let panoCache = {};
  let currentRoomId = null;

  // Tracking multiple 360s
  let currentPanoIndex = 0;
  let activePanoramas = [];

  // Variables for Zooming & Dragging Gallery
  let currentZoom = 1;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let startX = 0;
  let startY = 0;

  // --- 1. Init Panolens 360 Viewer ---
  const panoContainer = document.getElementById("pano-container");
  const viewerControls = document.getElementById("viewer-controls");
  const panoLoadingOverlay = document.getElementById("pano-loading-overlay");
  const no360Wrapper = document.querySelector(
    ".ui-360:not(.viewer-controls):not(.viewer-label):not(#pano-container)",
  ); // Grabs the dynamic no-360 div we inject

  const viewer = new PANOLENS.Viewer({
    container: panoContainer,
    controlBar: false,
    autoRotate: true,
    autoRotateSpeed: 0.5,
    antialias: true,
    cameraFov: 85,
  });

  viewer.renderer.setPixelRatio(window.devicePixelRatio);

  window.addEventListener("resize", () => {
    if (viewer && panoContainer.style.visibility === "visible") {
      viewer.onWindowResize();
    }
  });

  // --- 2. Custom 360 Controls ---
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
      panoContainer.requestFullscreen().then(() => {
        setTimeout(() => viewer.onWindowResize(), 100);
      });
    } else {
      document.exitFullscreen().then(() => {
        setTimeout(() => viewer.onWindowResize(), 100);
      });
    }
  });
  document.addEventListener("fullscreenchange", () =>
    setTimeout(() => viewer.onWindowResize(), 100),
  );

  // NEW FIX: Switch between multiple panoramas
  document.getElementById("btn-switch-pano")?.addEventListener("click", () => {
    if (activePanoramas.length > 1) {
      currentPanoIndex = (currentPanoIndex + 1) % activePanoramas.length;
      viewer.setPanorama(activePanoramas[currentPanoIndex]);
    }
  });

  // NEW FIX: Hard Reload for arrays!
  document.getElementById("btn-reload-pano")?.addEventListener("click", () => {
    if (!currentRoomId) return;
    const room = dataMap[currentRoomId];
    // Fixed: Checks for pano_urls array instead of single url
    if (!room || !room.pano_urls || room.pano_urls.length === 0) return;

    if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

    // Safely destroy ALL old panoramas for this room
    if (panoCache[currentRoomId]) {
      panoCache[currentRoomId].forEach((oldPano) => {
        viewer.remove(oldPano);
        if (oldPano.material) {
          if (oldPano.material.map) oldPano.material.map.dispose();
          oldPano.material.dispose();
        }
        if (oldPano.geometry) oldPano.geometry.dispose();
      });
      delete panoCache[currentRoomId];
    }

    // Cache Busting: Force download fresh copies for the array
    room.pano_urls = room.pano_urls.map((url) => {
      let cleanUrl = url.split("?")[0];
      return cleanUrl + "?t=" + new Date().getTime();
    });

    loadRoom(currentRoomId);
  });

  // --- 3. Info Modal Logic ---
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
    const amenitiesGrid = document.querySelector(".amenities-grid");
    if (modalAmenities && amenitiesGrid) {
      modalAmenities.innerHTML = amenitiesGrid.innerHTML;
    }
    infoModal.classList.add("active");
  });

  document
    .getElementById("btn-close-info")
    ?.addEventListener("click", () => infoModal.classList.remove("active"));
  window.addEventListener("click", (e) => {
    if (e.target === infoModal) infoModal.classList.remove("active");
  });

  // --- 4. Load Room Logic ---
  const valTitle = document.getElementById("val-title");
  const valCategory = document.getElementById("val-category");
  const valCapacity = document.getElementById("val-capacity");
  const valStatus = document.getElementById("val-status");
  const valRate = document.getElementById("val-rate");
  const valDesc = document.getElementById("val-desc");
  const amenitiesGrid = document.querySelector(".amenities-grid");
  const galleryTitle = document.getElementById("gallery-title");
  const btnViewPhotos = document.getElementById("btn-view-photos");

  function loadRoom(roomId) {
    currentRoomId = roomId;
    const room = dataMap[roomId];
    if (!room) return;

    valTitle.textContent = room.title;
    valCategory.textContent = room.category;
    valCapacity.textContent = room.capacity;
    valStatus.textContent = room.status;
    valRate.textContent = room.rate;
    galleryTitle.textContent = room.title + " Gallery";

    if (valDesc) valDesc.textContent = room.description;

    if (amenitiesGrid && room.amenities) {
      amenitiesGrid.innerHTML = "";
      const iconMap = {
        "free wi-fi": "fa-wifi",
        wifi: "fa-wifi",
        "fully air-conditioned": "fa-snowflake",
        "ac ": "fa-snowflake",
        "air ": "fa-snowflake",
        "ample parking": "fa-square-parking",
        park: "fa-square-parking",
        "wheelchair accessible": "fa-wheelchair",
        pwd: "fa-wheelchair",
        "private pool": "fa-water-ladder",
        pool: "fa-water-ladder",
        swim: "fa-water-ladder",
        "smart tv": "fa-tv",
        tv: "fa-tv",
        "mini-fridge": "fa-temperature-arrow-down",
        fridge: "fa-temperature-arrow-down",
        breakfast: "fa-utensils",
        food: "fa-utensils",
        gym: "fa-dumbbell",
        fitness: "fa-dumbbell",
        bed: "fa-bed",
        bath: "fa-bath",
        shower: "fa-bath",
      };

      room.amenities.forEach((item) => {
        const cleanItem = item.trim();
        if (cleanItem === "") return;

        const lowerText = cleanItem.toLowerCase();
        let iconClass = "fa-check";
        for (const [key, val] of Object.entries(iconMap)) {
          if (lowerText.includes(key)) {
            iconClass = val;
            break;
          }
        }

        const div = document.createElement("div");
        div.className = "amenity";
        div.innerHTML = `<i class="fa-solid ${iconClass}"></i> ${cleanItem}`;
        amenitiesGrid.appendChild(div);
      });
    }

    currentGallery = room.gallery || [];
    currentImageIndex = 0;

    // Handle 360 Panoramas
    const panoUrls = room.pano_urls || [];
    const btnSwitch = document.getElementById("btn-switch-pano");

    if (panoUrls.length > 0) {
      if (no360Wrapper) no360Wrapper.style.display = "none";
      panoContainer.style.visibility = "visible";
      if (viewerControls) viewerControls.style.display = "flex";
      if (btnSwitch)
        btnSwitch.style.display = panoUrls.length > 1 ? "block" : "none";

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
      const bgImg =
        currentGallery.length > 0
          ? currentGallery[0]
          : "assets/img/placeholder.jpg";
      if (no360Wrapper) {
        no360Wrapper.style.backgroundImage = `url('${bgImg}')`;
        no360Wrapper.style.display = "flex";
      }
      panoContainer.style.visibility = "hidden";
      if (viewerControls) viewerControls.style.display = "none";
      if (btnSwitch) btnSwitch.style.display = "none";
      valTitle.textContent = room.title;
    }

    if (currentGallery.length === 0) {
      btnViewPhotos.disabled = true;
      btnViewPhotos.innerText = "NO PHOTOS";
      document.getElementById("current-slide-img").src =
        "assets/img/placeholder.jpg";
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
      document.getElementById("current-slide-img").src = currentGallery[0];
    }
  }

  // --- 5. Pill Click Listeners & Initialization ---
  const pills = document.querySelectorAll(".pill");
  pills.forEach((pill) => {
    pill.addEventListener("click", function () {
      pills.forEach((p) => p.classList.remove("active"));
      this.classList.add("active");
      loadRoom(this.getAttribute("data-room"));
    });
  });

  if (pills.length > 0) {
    let targetRoomId = pills[0].getAttribute("data-room");
    const hash = window.location.hash.replace("#", "");
    if (hash && document.querySelector(`.pill[data-room="${hash}"]`)) {
      targetRoomId = hash;
    }
    const targetPill = document.querySelector(
      `.pill[data-room="${targetRoomId}"]`,
    );
    if (targetPill) targetPill.click();
  }

  // --- 6. Gallery Mode Swap Logic ---
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

  // --- 7. Gallery Slider Logic ---
  const currentSlideImg = document.getElementById("current-slide-img");

  function updateGalleryUI(index) {
    if (currentGallery.length === 0) return;
    currentImageIndex = index;
    currentZoom = 1;
    panX = 0;
    panY = 0;
    currentSlideImg.style.transform = `translate(0px, 0px) scale(1)`;
    currentSlideImg.style.cursor = "default";

    currentSlideImg.classList.remove("slide-in-left", "slide-in-right");
    void currentSlideImg.offsetWidth;

    if (window.lastGalleryDirection === "prev")
      currentSlideImg.classList.add("slide-in-left");
    else currentSlideImg.classList.add("slide-in-right");

    currentSlideImg.src = currentGallery[currentImageIndex];
    document.getElementById("gallery-counter").innerText =
      `• ${currentImageIndex + 1} / ${currentGallery.length}`;
  }

  document.getElementById("slide-prev")?.addEventListener("click", () => {
    window.lastGalleryDirection = "prev";
    updateGalleryUI(
      (currentImageIndex - 1 + currentGallery.length) % currentGallery.length,
    );
  });

  document.getElementById("slide-next")?.addEventListener("click", () => {
    window.lastGalleryDirection = "next";
    updateGalleryUI((currentImageIndex + 1) % currentGallery.length);
  });

  // --- 8. Zoom & Pan Logic for Photos ---
  currentSlideImg.addEventListener(
    "wheel",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      e.preventDefault();
      const oldZoom = currentZoom;
      currentZoom += e.deltaY < 0 ? 0.2 : -0.2;
      currentZoom = Math.min(Math.max(currentZoom, 1), 5);

      if (currentZoom === 1) {
        panX = 0;
        panY = 0;
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
    },
    { passive: false },
  );

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

  // --- 9. MOBILE SWIPE LOGIC FOR GALLERY ---
  let touchStartX = 0;
  let touchEndX = 0;

  currentSlideImg.addEventListener(
    "touchstart",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchStartX = e.changedTouches[0].screenX;
    },
    { passive: true },
  );

  currentSlideImg.addEventListener(
    "touchmove",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      const touchCurrentX = e.changedTouches[0].screenX;
      if (Math.abs(touchStartX - touchCurrentX) > 10) e.preventDefault();
    },
    { passive: false },
  );

  currentSlideImg.addEventListener(
    "touchend",
    (e) => {
      if (!wrapper.classList.contains("mode-photos")) return;
      touchEndX = e.changedTouches[0].screenX;
      const diff = touchEndX - touchStartX;
      if (diff < -50) document.getElementById("slide-next")?.click();
      else if (diff > 50) document.getElementById("slide-prev")?.click();
    },
    { passive: true },
  );
});
