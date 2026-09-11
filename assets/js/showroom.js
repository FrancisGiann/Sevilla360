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
  let roomLoadToken = 0;

  let currentZoom = 1;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let startX = 0;
  let startY = 0;
  let hasSeenHint = false;
  let hintPending = false;
  let hintReadyRoomId = null;

  // --- 2. DOM Elements ---
  const panoContainer = document.getElementById("pano-container");
  const viewerControls = document.getElementById("viewer-controls");
  const panoLoadingOverlay = document.getElementById("pano-loading-overlay");
  
  const valTitle = document.getElementById("val-title");
  const valCategory = document.getElementById("val-category");
  const valCapacity = document.getElementById("val-capacity");
  const valBeds = document.getElementById("val-beds");
  const valStatus = document.getElementById("val-status");
  const valRate = document.getElementById("val-rate");
  const valRating = document.getElementById("val-rating");
  const valDesc = document.getElementById("val-desc");
  const amenitiesGrid = document.querySelector(".amenities-grid");
  const galleryTitle = document.getElementById("gallery-title");
  const btnViewPhotos = document.getElementById("btn-view-photos");
  const btnSwitch = document.getElementById("btn-switch-mode");
  const currentSlideImg = document.getElementById("current-slide-img");
  const wrapper = document.getElementById("showroom-wrapper");
  const topRoomLabel = document.getElementById("top-room-label");
  const interactionHint = document.getElementById("interaction-hint");
  let hintTimeout;

  const receptionistRoot = document.getElementById("showroom-receptionist");
  const receptionistDialog = receptionistRoot?.querySelector(".showroom-receptionist-dialog");
  const receptionistTitle = document.getElementById("receptionist-title");
  const receptionistMessage = document.getElementById("receptionist-message");
  const receptionistLive = document.getElementById("receptionist-live");
  const receptionistChoices = document.getElementById("receptionist-choices");
  const receptionistContinue = document.getElementById("receptionist-continue");
  const receptionistSoundButtons = Array.from(document.querySelectorAll("[data-receptionist-sound-toggle]"));
  const receptionistAmbience = document.getElementById("receptionist-ambience");
  const receptionistReopen = document.getElementById("receptionist-reopen");
  const receptionistBackdropImage = document.getElementById("receptionist-backdrop-image");
  const receptionistGreeting = "Welcome to M.I. Sevilla Resort & Events Place. I’m your virtual receptionist. How may I help you today?";
  const receptionistState = {
      isOpen: false,
      hasOpened: false,
      previousFocus: null,
      activeCategory: null,
      activeRoomId: null,
      closeTimer: null,
      entranceTimer: null,
      entranceEndTarget: null,
      entranceEndHandler: null,
      entranceSettled: false,
      entranceSequence: 0,
      inertElements: [],
      dialogFocusState: [],
      soundEnabled: false,
      soundUnavailable: false,
      soundFadeTimer: null
  };

  const transitionImage = (image, source, fallbackSources = [], transitionClass = "") => {
      if (!image) return;
      const candidates = [source, ...fallbackSources, "assets/img/placeholder.jpg"]
          .map(value => String(value ?? "").trim())
          .filter(Boolean)
          .filter((value, index, values) => values.indexOf(value) === index);
      if (!candidates.length) {
          if (transitionClass) image.classList.remove(transitionClass);
          return;
      }
      if (transitionClass) image.classList.remove(transitionClass);
      if (image.src && image.src.endsWith(candidates[0])) return;
      let candidateIndex = 0;
      const applyCandidate = () => {
          const candidate = candidates[candidateIndex];
          if (transitionClass) image.classList.add(transitionClass);
          const onLoad = () => { if (transitionClass) image.classList.remove(transitionClass); };
          const onError = () => {
              if (transitionClass) image.classList.remove(transitionClass);
              if (candidateIndex + 1 < candidates.length) {
                  candidateIndex += 1;
                  applyCandidate();
              }
          };
          image.addEventListener("load", onLoad, { once: true });
          image.addEventListener("error", onError, { once: true });
          image.src = candidate;
      };
      applyCandidate();
  };

  const reviewCache = new Map();
  const reviewRequests = new Map();
  const reviewTargets = () => [
      { aggregate: valRating, list: document.getElementById("showroom-reviews-list") },
      { aggregate: document.getElementById("info-modal-rating"), list: document.getElementById("info-modal-reviews-list") }
  ].filter(target => target.aggregate || target.list);
  const renderReviewMessage = (list, message, className = "showroom-review-state") => {
      if (!list) return;
      const state = document.createElement("p");
      state.className = className;
      state.textContent = message;
      list.replaceChildren(state);
  };
  const renderReviews = (data) => {
      const count = Number(data?.rating_count || 0);
      const average = Number(data?.rating_average || 0);
      const aggregateText = count > 0
          ? `${average.toFixed(1)} out of 5 · ${count} review${count === 1 ? "" : "s"}`
          : "No ratings yet";
      reviewTargets().forEach(({ aggregate, list }) => {
          if (aggregate) aggregate.textContent = aggregateText;
          if (!list) return;
          list.replaceChildren();
          const reviews = Array.isArray(data?.reviews) ? data.reviews.slice(0, 3) : [];
          if (!reviews.length) {
              renderReviewMessage(list, "No ratings yet");
              return;
          }
          reviews.forEach(review => {
              const item = document.createElement("article");
              item.className = "showroom-review";
              const author = document.createElement("strong");
              const rating = Math.min(5, Math.max(1, Number(review.rating || 0)));
              author.textContent = `${String(review.reviewer || "Guest")} · ${rating} out of 5`;
              const text = document.createElement("p");
              text.textContent = String(review.review_text || "");
              item.append(author, text);
              list.appendChild(item);
          });
      });
  };
  const setReviewsLoading = () => reviewTargets().forEach(({ aggregate, list }) => {
      if (aggregate) aggregate.textContent = "Loading ratings…";
      renderReviewMessage(list, "Loading reviews…");
  });
  const setReviewsError = () => reviewTargets().forEach(({ aggregate, list }) => {
      if (aggregate) aggregate.textContent = "Ratings unavailable";
      renderReviewMessage(list, "Reviews are unavailable right now. Please try again later.", "showroom-review-state showroom-review-state-error");
  });
  const loadVenueReviews = (room) => {
      const key = String(room?.review_key || "");
      if (!key) { setReviewsError(); return Promise.resolve(null); }
      if (reviewCache.has(key)) {
          const cached = reviewCache.get(key);
          renderReviews(cached);
          return Promise.resolve(cached);
      }
      if (reviewRequests.has(key)) return reviewRequests.get(key);
      setReviewsLoading();
      const request = fetch(`actions/public/get_venue_reviews.php?venue_key=${encodeURIComponent(key)}`, {
          headers: { "X-Sevilla-Background": "true" }
      }).then(async response => {
          const data = await response.json().catch(() => null);
          if (!response.ok || !data?.success) throw new Error(data?.message || "Review request failed");
          reviewCache.set(key, data);
          if (currentRoomId === room.id) renderReviews(data);
          return data;
      }).catch(error => {
          if (currentRoomId === room.id) setReviewsError();
          return null;
      }).finally(() => reviewRequests.delete(key));
      reviewRequests.set(key, request);
      return request;
  };

  // Hint state is centralized so opening the guide, loading a panorama, and
  // leaving the guide cannot race a four-second timer into view.
  const clearInteractionHint = () => {
      if (interactionHint) interactionHint.classList.remove("hint-visible");
      window.clearTimeout(hintTimeout);
  };
  const canShowInteractionHint = () => Boolean(
      hintPending && !hasSeenHint && !receptionistState.isOpen && currentRoomId &&
      currentRoomId === hintReadyRoomId && panoContainer?.style.visibility === "visible" &&
      !dataMap[currentRoomId]?.panoFailed && Array.isArray(dataMap[currentRoomId]?.pano_urls) &&
      dataMap[currentRoomId].pano_urls.length > 0
  );
  const showQueuedInteractionHint = () => {
      if (!canShowInteractionHint()) return;
      hintPending = false;
      interactionHint.classList.add("hint-visible");
      window.clearTimeout(hintTimeout);
      hintTimeout = window.setTimeout(clearInteractionHint, 4000);
  };
  const queueInteractionHint = roomId => {
      const room = dataMap[roomId || currentRoomId];
      if (!room || room.panoFailed || !Array.isArray(room.pano_urls) || room.pano_urls.length === 0 || hasSeenHint) return;
      hintReadyRoomId = room.id;
      hintPending = true;
      clearInteractionHint();
      if (!receptionistState.isOpen) window.setTimeout(showQueuedInteractionHint, 240);
  };

  // Hide hint instantly on user interaction and never replay it on this visit.
  const disableHintForever = () => {
      clearInteractionHint();
      hintPending = false;
      hasSeenHint = true; 
  };
  panoContainer.addEventListener("mousedown", disableHintForever);
  panoContainer.addEventListener("touchstart", disableHintForever, {passive: true});

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

  function disposePanorama(pano) {
      if (!pano) return;
      try { viewer.remove(pano); } catch (e) { /* already removed */ }
      try {
          if (pano.material) {
              if (pano.material.map) pano.material.map.dispose();
              pano.material.dispose();
          }
          if (pano.geometry) pano.geometry.dispose();
      } catch (e) { /* texture/geometry may already be disposed */ }
  }

  function showStandardFallback(room) {
      const bgImg = currentGallery.length > 0 ? currentGallery[0] : "assets/img/placeholder.jpg";
      if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
      hintPending = false;
      hintReadyRoomId = null;
      clearInteractionHint();
      no360Wrapper.style.backgroundImage = `url('${bgImg}')`;
      no360Wrapper.style.display = "flex";
      panoContainer.style.visibility = "hidden";
      if (viewerControls) viewerControls.style.display = "none";
      if (btnSwitch) {
          btnSwitch.disabled = true;
          btnSwitch.style.display = "none";
      }
      const btnInfo = document.getElementById("btn-info");
      if (btnInfo) {
          btnInfo.disabled = true;
          btnInfo.style.display = "none";
      }
      valTitle.textContent = room.title;
  }

  function handlePanoLoadFailure(roomId, loadToken, failedPano, panoramasRef) {
      const room = dataMap[roomId];
      if (!room) return;

      const isCurrentLoad = currentRoomId === roomId && roomLoadToken === loadToken;
      const cachedPanoramas = panoCache[roomId];
      const ownsCache = cachedPanoramas === panoramasRef;
      if (ownsCache) delete panoCache[roomId];

      // Dispose every panorama from this load, but never tear down a newer
      // load for the same room after a stale callback races with switching.
      const panoramasToDispose = ownsCache
          ? panoramasRef.slice()
          : (isCurrentLoad ? panoramasRef.slice() : [failedPano]);
      panoramasToDispose.forEach(disposePanorama);
      if (!isCurrentLoad) return;

      room.panoFailed = true;
      roomLoadToken++;
      activePanoramas = [];
      currentPanoIndex = 0;
      showStandardFallback(room);
  }



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

      activateVenue(currentRoomId);
  });

  // --- 6. Info Modal Logic (Mobile) ---
  const infoModal = document.getElementById("info-modal");
  document.getElementById("btn-info")?.addEventListener("click", () => {
      if (!currentRoomId) return;
      const room = dataMap[currentRoomId];
      if (!room) return;

      document.getElementById("info-modal-title").textContent = room.title;
      document.getElementById("info-modal-desc").textContent = room.description;
      document.getElementById("info-modal-cap").textContent = room.capacity;
      document.getElementById("info-modal-beds").textContent = room.beds || 'Not applicable';
      document.getElementById("info-modal-rate").textContent = room.rate;
      setReviewsLoading();
      loadVenueReviews(room);

      const modalAmenities = document.getElementById("info-modal-amenities");
      if (modalAmenities && amenitiesGrid) {
          modalAmenities.replaceChildren(...Array.from(amenitiesGrid.children, item => item.cloneNode(true)));
      }
      infoModal.classList.add("active");
  });

  document.getElementById("btn-close-info")?.addEventListener("click", () => infoModal.classList.remove("active"));
  window.addEventListener("click", (e) => { if (e.target === infoModal) infoModal.classList.remove("active"); });


  function attachHotspots(pano, hotspotsArray, viewerRef, panoramasRef, roomData, isCurrentView) {
      if (!hotspotsArray || hotspotsArray.length === 0) return;

      const escapeHotspotText = (value) => String(value ?? '').replace(/[&<>'"]/g, char => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
      }[char]));

      const spots = [];

      hotspotsArray.forEach(h => {
          const isNav = h.type === 'nav';
          const iconUrl = isNav ? 'assets/img/hotspot-arrow.png' : 'assets/img/hotspot-info.png';

          const spot = new PANOLENS.Infospot(350, iconUrl);
          spot.position.set(parseFloat(h.position_x), parseFloat(h.position_y), parseFloat(h.position_z));

          if (isNav) {
              spot.addEventListener('click', () => {
                  if (typeof isCurrentView === 'function' && !isCurrentView()) return;
                  const targetMediaIds = Array.isArray(roomData?.pano_media_ids) ? roomData.pano_media_ids.map(Number) : [];
                  const targetMediaId = Number(h.target_media_id);
                  let idx = Number.isInteger(targetMediaId) && targetMediaId > 0
                      ? targetMediaIds.indexOf(targetMediaId)
                      : -1;
                  if (idx < 0) {
                      const legacyIndex = Number(h.target_pano_index);
                      const legacyMediaIds = Array.isArray(roomData?.legacy_pano_media_ids)
                          ? roomData.legacy_pano_media_ids.map(Number)
                          : [];
                      const legacyMediaId = Number.isInteger(legacyIndex) && legacyIndex >= 0
                          ? legacyMediaIds[legacyIndex]
                          : null;
                      idx = Number.isInteger(legacyMediaId) ? targetMediaIds.indexOf(legacyMediaId) : -1;
                  }
                  if (idx >= 0 && idx < panoramasRef.length && panoramasRef[idx]) viewerRef.setPanorama(panoramasRef[idx]);
              });
          } else {
              spot.addHoverText(escapeHotspotText(h.title));
              spot.addEventListener('click', () => {
                  if (typeof isCurrentView === 'function' && !isCurrentView()) return;
                  showHotspotInfoModal(h.title, h.description);
              });
          }

          pano.add(spot);
          spots.push(spot);
      });

      pano.addEventListener('enter', () => {
          spots.forEach(s => s.show());
      });

      pano.addEventListener('leave', () => {
          spots.forEach(s => s.hide());
      });
  }

  function showHotspotInfoModal(title, description) {
      let modal = document.getElementById('hotspot-info-modal');
      if (!modal) {
          modal = document.createElement('div');
          modal.id = 'hotspot-info-modal';
          modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:99999;';
          modal.innerHTML = `
              <div style="background:#fff; padding:30px; border-radius:8px; max-width:400px; width:90%;">
                  <h3 id="hs-modal-title" style="margin-top:0; color:var(--color-gold); font-family:var(--font-heading);"></h3>
                  <p id="hs-modal-desc" style="color:#555; line-height:1.5;"></p>
                  <button id="hs-modal-close" style="margin-top:15px; padding:10px 20px; background:var(--color-dark); color:#fff; border:none; border-radius:4px; cursor:pointer;">Close</button>
              </div>`;
          document.body.appendChild(modal);
          modal.querySelector('#hs-modal-close').addEventListener('click', () => modal.remove());
          modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
      }
      modal.querySelector('#hs-modal-title').textContent = title;
      modal.querySelector('#hs-modal-desc').textContent = description || '';
  }

  // --- 7. Main Room Loading Logic ---
  function getBookingUrl(room) {
    if (!room || room.venue_id === undefined || room.venue_id === null) return "booking.php";
    const params = new URLSearchParams({
      venue_id: String(room.venue_id),
      category: String(room.category || ""),
      room_type: String(room.room_type || ""),
      venue_name: String(room.venue_name || "")
    });
    const startDate = arguments[1] || null;
    const endDate = arguments[2] || null;
    if (startDate && endDate) {
      params.set("start_date", String(startDate));
      params.set("end_date", String(endDate));
    }
    return `booking.php?${params.toString()}`;
  }

  function activateVenue(roomId) {
    clearInteractionHint();
    hintPending = false;
    hintReadyRoomId = null;
    const loadToken = ++roomLoadToken;
    currentRoomId = roomId; 
    const room = dataMap[roomId];
    if (!room) return;

    // Keep every venue entry point on the same active state and renderer.
    document.querySelectorAll(".dropdown-item").forEach(item => {
        item.classList.toggle("active", item.getAttribute("data-room") === roomId);
    });
    document.querySelectorAll(".master-pill").forEach(master => {
        master.classList.toggle("active", master.getAttribute("data-category") === room.category);
    });
    document.querySelectorAll(".pill-dropdown-wrapper").forEach(menu => menu.classList.remove("open"));
    document.querySelectorAll(".master-pill").forEach(master => master.classList.remove("menu-open"));

    // Update Text Data
    valTitle.textContent = room.title;
    valCategory.textContent = room.category;
    valCapacity.textContent = room.capacity;
    if (valBeds) valBeds.textContent = room.beds || 'Not applicable';
    valStatus.textContent = room.status;
    valRate.textContent = room.rate;
    setReviewsLoading();
    loadVenueReviews(room);
    galleryTitle.textContent = room.title + " Gallery";
    if (valDesc) valDesc.textContent = room.description;
    if (topRoomLabel) topRoomLabel.textContent = room.title;

    // Update Book Button URL
    const bookBtn = document.querySelector('.btn-book');
    if (bookBtn) bookBtn.href = getBookingUrl(room);

    // Dynamic Amenities Icons
    if (amenitiesGrid && room.amenities) {
        amenitiesGrid.replaceChildren();
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
            const icon = document.createElement("i");
            icon.className = `fa-solid ${iconClass}`;
            icon.setAttribute("aria-hidden", "true");
            div.append(icon, document.createTextNode(` ${cleanItem}`));
            amenitiesGrid.appendChild(div);
        });
    }

    currentGallery = room.gallery || [];
    currentImageIndex = 0;

    // 360 Engine Routing
    const panoUrls = room.panoFailed ? [] : (room.pano_urls || []);
    const btnInfo = document.getElementById("btn-info"); 

    if (panoUrls.length > 0) {
      // Show 3D Canvas
      no360Wrapper.style.display = "none";
      panoContainer.style.visibility = "visible";
      if (viewerControls) viewerControls.style.display = "flex"; 
      if (btnInfo) {
          btnInfo.disabled = false;
          btnInfo.style.display = "flex";
      }
      if (btnSwitch) btnSwitch.disabled = false;

      if (!panoCache[roomId]) {
        valTitle.textContent = "Loading 360...";
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

        const panoramas = [];
        let loadedCount = 0;

        panoUrls.forEach((url, index) => {
            if (roomLoadToken !== loadToken || room.panoFailed) return;
            let pano;
            try {
                pano = new PANOLENS.ImagePanorama(url);
            } catch (e) {
                handlePanoLoadFailure(roomId, loadToken, null, panoramas);
                return;
            }
            let failureHandled = false;
            const handleError = () => {
                if (failureHandled) return;
                failureHandled = true;
                handlePanoLoadFailure(roomId, loadToken, pano, panoramas);
            };
            // Panolens forwards Three/ImageLoader texture failures as an
            // `error` event on the panorama.  Keep this listener separate from
            // `load` so a 404 cannot leave the loading overlay indefinitely.
            pano.addEventListener("error", handleError);
            const bindTextureError = () => {
                const textureImage = pano.image || (pano.material && pano.material.map && pano.material.map.image);
                if (textureImage && typeof textureImage.addEventListener === "function") {
                    textureImage.addEventListener("error", handleError, { once: true });
                }
            };
            bindTextureError();
            if (typeof queueMicrotask === "function") queueMicrotask(bindTextureError);

            const hotspotsForThisPano = (room.hotspots_by_pano_index && room.hotspots_by_pano_index[index]) || [];
            attachHotspots(
                pano,
                hotspotsForThisPano,
                viewer,
                panoramas,
                room,
                // A cached room may be revisited under a new load token. The
                // room identity guards cross-room callbacks without disabling
                // valid hotspot navigation on cached panoramas.
                () => currentRoomId === roomId && !room.panoFailed
            );

            pano.addEventListener("load", function () {
                if (failureHandled || roomLoadToken !== loadToken || room.panoFailed) return;
                loadedCount++;
                if (index === 0 && panoLoadingOverlay) {
                    
                    // --- AUTO-RELOAD HACK ---
                    // If this is the very first time this room is loaded, we auto-trigger 
                    // the reload button. Since the images are now cached, the second load 
                    // happens instantly and perfectly bypasses all Panolens timing bugs!
                    if (!room.hasAutoReloaded) {
                        room.hasAutoReloaded = true;
                        const btn = document.getElementById("btn-reload-pano");
                        if (btn) {
                            btn.click();
                            return; // Stop here, don't hide the overlay! Let the reload take over.
                        }
                    }

                    valTitle.textContent = room.title;
                    panoLoadingOverlay.style.display = "none";
                    
                    // Queue the hint until the guide has completed its exit
                    // transition. The queue also covers a guide opened while
                    // Panolens is still loading this room.
                    queueInteractionHint(room.id);
                }
            });
            
            viewer.add(pano);
            panoramas.push(pano);
        });
        if (roomLoadToken !== loadToken) return;
        if (room.panoFailed) {
            if (panoCache[roomId] === panoramas) delete panoCache[roomId];
            activePanoramas = [];
        } else {
            panoCache[roomId] = panoramas;
            activePanoramas = panoCache[roomId];
            currentPanoIndex = 0;
            viewer.setPanorama(activePanoramas[currentPanoIndex]);
        }
      } else {
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
        activePanoramas = panoCache[roomId];
        currentPanoIndex = 0;
        viewer.setPanorama(activePanoramas[currentPanoIndex]);
        queueInteractionHint(room.id);
      }

    } else {
      // Fallback: no 360 uploaded or a previous panorama load failed.
      showStandardFallback(room);
    }

    // Photo Button Status
    if (currentGallery.length === 0) {
      btnViewPhotos.disabled = true;
      btnViewPhotos.textContent = "NO PHOTOS";
      transitionImage(currentSlideImg, "assets/img/placeholder.jpg", [], "fade-out");
      if (wrapper.classList.contains("mode-photos")) document.getElementById("btn-back-to-360").click();
    } else {
      btnViewPhotos.disabled = false;
      btnViewPhotos.textContent = "VIEW PHOTOS";
      transitionImage(currentSlideImg, currentGallery[0], currentGallery.slice(1), "fade-out");
      const galleryCounter = document.getElementById("gallery-counter");
      if (galleryCounter) galleryCounter.textContent = `1 / ${currentGallery.length}`;
    }
  }

  // --- 8. Dropdown Pill Navigation Initialization ---
  const masterPills = document.querySelectorAll(".master-pill");
  const dropdownItems = document.querySelectorAll(".dropdown-item");

  // A. Master Category Click (Toggle Menus)
  masterPills.forEach(master => {
      // Listen for both Click and Touch to fix the Inspect Element mobile bug!
      master.addEventListener("click", function(e) {
          e.stopPropagation(); 
          
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

  // Close menus if clicking or touching anywhere else on the screen
  const closeAllMenus = () => {
      document.querySelectorAll(".pill-dropdown-wrapper").forEach(w => w.classList.remove("open"));
      masterPills.forEach(m => m.classList.remove("menu-open"));
  };
  window.addEventListener("click", closeAllMenus);
  window.addEventListener("touchstart", closeAllMenus, {passive: true});

  // B. Specific Room Click Logic
  dropdownItems.forEach(item => {
      item.addEventListener("click", function(e) {
          e.stopPropagation(); // Stop mobile browsers from double-firing events

          // Close the menu
          closeAllMenus();

          // Dropdowns and the receptionist share one venue activation path.
          activateVenue(this.getAttribute("data-room"));
      });
  });

  // C. Initialization & URL Hash/Query Logic (Fixes Landing Page Routing!)
  if (dropdownItems.length > 0) {
      let targetItem = dropdownItems[0]; // Default to first item (Event Hall)
      
      // 1. Check for URL Parameters (e.g. showroom.php?cat=Resort Villa)
      const urlParams = new URLSearchParams(window.location.search);
      const catParam = urlParams.get('cat');

      if (catParam) {
          // Find the Master Pill that matches this category
          const targetMaster = Array.from(masterPills).find(m => m.getAttribute('data-category') === catParam);
          if (targetMaster) {
              // Find the first specific room inside that Master Category's dropdown
              const wrapper = targetMaster.closest('.pill-dropdown-wrapper');
              const firstRoom = wrapper.querySelector('.dropdown-item');
              if (firstRoom) targetItem = firstRoom;
          }
      } 
      // 2. Check for exact Room Hash (e.g. showroom.php#venue_villa)
      else {
          const hash = window.location.hash.replace('#', '');
          if (hash) {
              const hashItem = document.querySelector(`.dropdown-item[data-room="${hash}"]`);
              if (hashItem) targetItem = hashItem;
          }
      }
      
      // Activate the initial venue directly so the guide never depends on a
      // synthetic browser click to synchronize showroom state.
      activateVenue(targetItem.getAttribute("data-room"));
  }


  // --- 9. Virtual Receptionist Guide -------------------------------------
  // The guide is an enhancement only. If this block cannot initialize, the
  // existing showroom remains usable without a hidden or inert overlay.
  if (receptionistRoot && receptionistDialog && receptionistChoices && receptionistReopen) {
    const showroomContainer = document.querySelector(".showroom-container");
    const backgroundElements = [
      document.querySelector(".s-header"),
      showroomContainer,
      document.querySelector(".idx-footer")
    ].filter(Boolean);
    const receptionistSkip = receptionistRoot.querySelector("[data-receptionist-skip]");
    const receptionistPanel = receptionistRoot.querySelector(".receptionist-panel");
    const categoryLabels = { "Event Hall": "event", "Hotel Room": "hotel", "Resort Villa": "villa" };
    const guideContext = { intent: null, occasion: null, purpose: null, groupSize: null, preference: null, startDate: null, endDate: null };
    const guideState = {
      responseMode: "overview",
      returnStep: "greeting",
      shortlist: [],
      allVenues: [],
      allPage: 0,
      selectionOrigin: "shortlist",
      selectedAllPage: 0,
      includedAmenities: [],
      includedPage: 0,
      availabilityStatus: "idle",
      availabilityConfirmedIds: [],
      availabilityRequestToken: 0,
      dateDraft: null,
      dateMonth: null,
      dateFocus: null,
      dateQuestionCategory: null,
      sharpImage: null,
      sharpImageFallbacks: [],
      entranceDuration: 0,
      dialogueChoicesRevealed: false,
      dialogueRevealComplete: false,
      dialogueRequiresContinue: false,
      dialogueRevealTimer: null,
      entranceOwnsDialogue: false
    };
    let activeRationale = "";
    let receptionistPhotoIndex = 0;
    let receptionistPhotoCounter = null;

    const panoramaImages = room => {
      if (!room || room.panoFailed) return [];
      if (Array.isArray(room.pano_urls) && room.pano_urls.length) return room.pano_urls.filter(Boolean);
      return String(room.pano_url || "").trim() ? [String(room.pano_url).trim()] : [];
    };
    const hasPanorama = room => panoramaImages(room).length > 0;
    const hasGallery = room => Boolean(room && Array.isArray(room.gallery) && room.gallery.length);
    const venueImages = room => hasGallery(room) ? room.gallery.filter(Boolean) : panoramaImages(room);
    const mediaVenues = () => Object.values(dataMap).filter(room => room && (hasPanorama(room) || hasGallery(room)));
    const venuesForCategory = category => mediaVenues().filter(room => room.category === category);
    const guideFact = (value, fallback) => {
      const normalized = String(value ?? "").trim();
      return normalized && normalized.toLowerCase() !== "n/a" ? normalized : fallback;
    };
    const numericFact = (value, fallback = null) => {
      if (value === null || value === undefined) return fallback;
      if (typeof value !== "number" && typeof value !== "string") return fallback;
      if (typeof value === "string" && value.trim() === "") return fallback;
      const number = Number(value);
      return Number.isFinite(number) && number >= 0 ? number : fallback;
    };
    const formatNumber = value => Number(value).toLocaleString("en-PH");
    const localDateString = date => {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      return `${year}-${month}-${day}`;
    };
    const todayLocal = () => localDateString(new Date());
    const isCanonicalDate = value => {
      if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
      const [year, month, day] = value.split("-").map(Number);
      const date = new Date(year, month - 1, day);
      return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day && value >= todayLocal();
    };
    const addLocalDays = (value, days) => {
      const [year, month, day] = value.split("-").map(Number);
      const date = new Date(year, month - 1, day);
      date.setDate(date.getDate() + days);
      return localDateString(date);
    };
    const dateStepFor = category => category === "Hotel Room" ? "checkInDate" : category === "Event Hall" ? "eventDate" : "visitDate";
    const dateQuestionTitle = category => category === "Hotel Room" ? "When would you check in?" : category === "Event Hall" ? "When is your event?" : "When would you visit?";
    const dateQuestionMessage = category => category === "Hotel Room" ? "I’ll check a one-night stay from check-in through the next calendar day." : "I’ll check the selected calendar date. Availability is advisory and no hold is created.";
    const dateAvailabilityLabel = category => category === "Hotel Room" ? "Check-in date" : category === "Event Hall" ? "Event date" : "Visit date";
    const formatLocalDate = value => {
      if (!isCanonicalDate(value)) return "the selected date";
      const [year, month, day] = value.split("-").map(Number);
      const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
      return `${monthNames[month - 1]} ${day}, ${year}`;
    };
    const formatCalendarDate = value => {
      const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ""));
      if (!match) return "the selected date";
      const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
      return `${monthNames[Number(match[2]) - 1]} ${Number(match[3])}, ${match[1]}`;
    };
    const localDateFromCanonical = value => {
      const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ""));
      if (!match) return null;
      return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    };
    const canonicalFromLocalDate = date => date ? localDateString(date) : "";
    const monthStart = date => new Date(date.getFullYear(), date.getMonth(), 1);
    const monthDays = date => new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
    const calendarMonthLabel = date => date.toLocaleDateString("en-US", { month: "long", year: "numeric" });
    const availabilityDateSummary = category => {
      if (guideState.availabilityStatus !== "confirmed" || !guideContext.startDate) return "";
      const start = formatLocalDate(guideContext.startDate);
      const span = category === "Hotel Room" && guideContext.endDate
        ? `${start} through ${formatLocalDate(guideContext.endDate)}`
        : start;
      return `Availability checked for ${span}; this is advisory and no hold was created.`;
    };
    const createChoice = (label, className = "", attributes = {}) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = `receptionist-choice${className ? ` ${className}` : ""}`;
      button.textContent = label;
      Object.entries(attributes).forEach(([name, value]) => button.setAttribute(name, String(value)));
      return button;
    };
    const createPhotoArrow = (label, dataAttribute, iconName) => {
      const button = createChoice("", "receptionist-photo-control receptionist-photo-arrow", { [dataAttribute]: "true", "aria-label": label });
      const icon = document.createElement("i");
      icon.className = `fa-solid ${iconName}`;
      icon.setAttribute("aria-hidden", "true");
      button.appendChild(icon);
      return button;
    };
    const createDateQuestion = category => {
      const wrap = document.createElement("section");
      wrap.className = "receptionist-date-question";
      wrap.setAttribute("aria-labelledby", "receptionist-date-label");
      const label = document.createElement("label");
      label.className = "receptionist-date-label";
      label.id = "receptionist-date-label";
      label.textContent = dateAvailabilityLabel(category);
      const calendar = document.createElement("div");
      calendar.className = "receptionist-calendar";
      calendar.setAttribute("aria-label", `${dateAvailabilityLabel(category)} calendar`);
      const navigation = document.createElement("div");
      navigation.className = "receptionist-calendar-navigation";
      const previousMonth = document.createElement("button");
      previousMonth.type = "button";
      previousMonth.className = "receptionist-calendar-nav";
      previousMonth.setAttribute("aria-label", "Previous month");
      const previousIcon = document.createElement("i");
      previousIcon.className = "fa-solid fa-chevron-left";
      previousIcon.setAttribute("aria-hidden", "true");
      previousMonth.appendChild(previousIcon);
      const monthYear = document.createElement("span");
      monthYear.className = "receptionist-calendar-month";
      monthYear.setAttribute("aria-live", "polite");
      const nextMonth = document.createElement("button");
      nextMonth.type = "button";
      nextMonth.className = "receptionist-calendar-nav";
      nextMonth.setAttribute("aria-label", "Next month");
      const nextIcon = document.createElement("i");
      nextIcon.className = "fa-solid fa-chevron-right";
      nextIcon.setAttribute("aria-hidden", "true");
      nextMonth.appendChild(nextIcon);
      navigation.append(previousMonth, monthYear, nextMonth);
      const weekdayRow = document.createElement("div");
      weekdayRow.className = "receptionist-calendar-weekdays";
      ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].forEach(day => {
        const cell = document.createElement("span");
        cell.textContent = day;
        weekdayRow.appendChild(cell);
      });
      const grid = document.createElement("div");
      grid.className = "receptionist-calendar-grid";
      const selectedText = document.createElement("p");
      selectedText.className = "receptionist-calendar-selected";
      const input = document.createElement("input");
      input.type = "hidden";
      input.id = "receptionist-date-input";
      input.setAttribute("data-receptionist-date-input", "true");
      const check = createChoice("Check this date", "receptionist-choice-primary", { "data-receptionist-date-submit": "true" });
      calendar.append(navigation, weekdayRow, grid, selectedText, input, check);
      wrap.append(label, calendar);

      const selectedDate = guideContext.startDate || guideState.dateDraft;
      const selectedLocal = localDateFromCanonical(selectedDate);
      const currentMonth = monthStart(new Date());
      if (!guideState.dateMonth || guideState.dateQuestionCategory !== category) {
        guideState.dateMonth = monthStart(selectedLocal || new Date());
        guideState.dateQuestionCategory = category;
      }
      if (guideState.dateMonth < currentMonth) guideState.dateMonth = currentMonth;
      guideState.dateDraft = selectedDate || null;

      const isSelectable = value => isCanonicalDate(value);
      const firstSelectableInMonth = () => {
        const total = monthDays(guideState.dateMonth);
        for (let day = 1; day <= total; day += 1) {
          const value = canonicalFromLocalDate(new Date(guideState.dateMonth.getFullYear(), guideState.dateMonth.getMonth(), day));
          if (isSelectable(value)) return value;
        }
        return "";
      };
      const focusDay = value => {
        const target = value ? grid.querySelector(`[data-calendar-date="${value}"]`) : null;
        const fallback = target && !target.disabled ? target : grid.querySelector(".receptionist-calendar-day:not([disabled])");
        if (fallback) {
          grid.querySelectorAll(".receptionist-calendar-day").forEach(day => day.tabIndex = day === fallback ? 0 : -1);
          try { fallback.focus({ preventScroll: true }); } catch (error) { fallback.focus(); }
        }
      };
      const renderCalendar = (focusValue = null) => {
        const month = guideState.dateMonth;
        monthYear.textContent = calendarMonthLabel(month);
        previousMonth.disabled = month.getFullYear() === currentMonth.getFullYear() && month.getMonth() === currentMonth.getMonth();
        grid.replaceChildren();
        const firstDay = new Date(month.getFullYear(), month.getMonth(), 1).getDay();
        for (let index = 0; index < firstDay; index += 1) {
          const blank = document.createElement("span");
          blank.className = "receptionist-calendar-blank";
          blank.setAttribute("aria-hidden", "true");
          grid.appendChild(blank);
        }
        const selected = guideState.dateDraft;
        const today = todayLocal();
        const total = monthDays(month);
        const targetFocus = focusValue || guideState.dateFocus || selected || firstSelectableInMonth();
        for (let day = 1; day <= total; day += 1) {
          const value = canonicalFromLocalDate(new Date(month.getFullYear(), month.getMonth(), day));
          const dayButton = document.createElement("button");
          dayButton.type = "button";
          dayButton.className = "receptionist-calendar-day";
          dayButton.setAttribute("data-calendar-date", value);
          dayButton.setAttribute("aria-label", formatCalendarDate(value));
          dayButton.textContent = String(day);
          const past = value < today;
          dayButton.disabled = past;
          dayButton.tabIndex = value === targetFocus && !past ? 0 : -1;
          if (value === today) {
            dayButton.classList.add("is-today");
            dayButton.setAttribute("aria-current", "date");
          }
          if (value === selected) {
            dayButton.classList.add("is-selected");
            dayButton.setAttribute("aria-selected", "true");
          } else {
            dayButton.setAttribute("aria-selected", "false");
          }
          if (!past) {
            dayButton.addEventListener("click", () => {
              guideState.dateDraft = value;
              guideState.dateFocus = value;
              input.value = value;
              selectedText.textContent = `Selected: ${formatCalendarDate(value)}`;
              check.disabled = !isSelectable(value);
              renderCalendar(value);
              focusDay(value);
            });
            dayButton.addEventListener("keydown", event => {
              const current = localDateFromCanonical(value);
              let target = null;
              let step = 0;
              if (event.key === "ArrowLeft") step = -1;
              if (event.key === "ArrowRight") step = 1;
              if (event.key === "ArrowUp") step = -7;
              if (event.key === "ArrowDown") step = 7;
              if (event.key === "Home") step = -current.getDay();
              if (event.key === "End") step = 6 - current.getDay();
              if (step) target = new Date(current.getFullYear(), current.getMonth(), current.getDate() + step);
              if (event.key === "PageUp" || event.key === "PageDown") {
                event.preventDefault();
                const monthDelta = event.key === "PageUp" ? -1 : 1;
                guideState.dateMonth = new Date(current.getFullYear(), current.getMonth() + monthDelta, 1);
                if (guideState.dateMonth < currentMonth) guideState.dateMonth = currentMonth;
                const targetDay = Math.min(current.getDate(), monthDays(guideState.dateMonth));
                const pageValue = canonicalFromLocalDate(new Date(guideState.dateMonth.getFullYear(), guideState.dateMonth.getMonth(), targetDay));
                const safePageValue = isSelectable(pageValue) ? pageValue : firstSelectableInMonth();
                guideState.dateFocus = safePageValue;
                renderCalendar(safePageValue);
                requestAnimationFrame(() => focusDay(safePageValue));
                return;
              }
              if (!target) return;
              event.preventDefault();
              const targetValue = canonicalFromLocalDate(target);
              if (!isSelectable(targetValue)) {
                if (targetValue < todayLocal()) return;
                guideState.dateMonth = monthStart(target);
                const nextValue = isSelectable(targetValue) ? targetValue : firstSelectableInMonth();
                guideState.dateFocus = nextValue;
                renderCalendar(nextValue);
                requestAnimationFrame(() => focusDay(nextValue));
                return;
              }
              if (target.getMonth() !== guideState.dateMonth.getMonth() || target.getFullYear() !== guideState.dateMonth.getFullYear()) {
                guideState.dateMonth = monthStart(target);
                renderCalendar(targetValue);
                requestAnimationFrame(() => focusDay(targetValue));
                return;
              }
              guideState.dateFocus = targetValue;
              focusDay(targetValue);
            });
          }
          grid.appendChild(dayButton);
        }
        input.value = guideState.dateDraft || "";
        selectedText.textContent = guideState.dateDraft ? `Selected: ${formatCalendarDate(guideState.dateDraft)}` : "Choose a date from the calendar.";
        check.disabled = !isSelectable(guideState.dateDraft);
      };
      previousMonth.addEventListener("click", () => {
        if (previousMonth.disabled) return;
        guideState.dateMonth = new Date(guideState.dateMonth.getFullYear(), guideState.dateMonth.getMonth() - 1, 1);
        const target = firstSelectableInMonth();
        guideState.dateFocus = target;
        renderCalendar(target);
        requestAnimationFrame(() => focusDay(target));
      });
      nextMonth.addEventListener("click", () => {
        guideState.dateMonth = new Date(guideState.dateMonth.getFullYear(), guideState.dateMonth.getMonth() + 1, 1);
        const target = firstSelectableInMonth();
        guideState.dateFocus = target;
        renderCalendar(target);
        requestAnimationFrame(() => focusDay(target));
      });
      renderCalendar(guideState.dateDraft || null);
      return wrap;
    };
    const createBackChoice = () => createChoice("Back to welcome", "receptionist-choice-secondary", { "data-receptionist-back": "true" });
    const updateSoundControls = () => {
      receptionistSoundButtons.forEach(button => {
        button.setAttribute("aria-pressed", receptionistState.soundEnabled ? "true" : "false");
        button.setAttribute("aria-label", receptionistState.soundUnavailable
          ? "Sound unavailable"
          : receptionistState.soundEnabled ? "Turn off resort ambience" : "Turn on resort ambience");
        button.textContent = receptionistState.soundUnavailable ? "Sound unavailable" : receptionistState.soundEnabled ? "Sound on" : "Sound off";
        button.disabled = receptionistState.soundUnavailable;
      });
    };
    const fadeAmbienceTo = (target, onComplete = null) => {
      if (!receptionistAmbience) return;
      window.clearTimeout(receptionistState.soundFadeTimer);
      const start = Number(receptionistAmbience.volume) || 0;
      const startedAt = Date.now();
      const duration = 260;
      const tick = () => {
        const progress = Math.min(1, (Date.now() - startedAt) / duration);
        receptionistAmbience.volume = Math.max(0, Math.min(1, start + (target - start) * progress));
        if (progress < 1) receptionistState.soundFadeTimer = window.setTimeout(tick, 32);
        else if (typeof onComplete === "function") onComplete();
      };
      tick();
    };
    const stopAmbience = () => {
      if (!receptionistAmbience) return;
      fadeAmbienceTo(0, () => {
        receptionistAmbience.pause();
        try { receptionistAmbience.currentTime = 0; } catch (error) { /* media may be unavailable */ }
      });
    };
    const toggleAmbience = async () => {
      if (!receptionistAmbience || receptionistState.soundUnavailable) return;
      if (receptionistState.soundEnabled) {
        receptionistState.soundEnabled = false;
        stopAmbience();
        updateSoundControls();
        return;
      }
      try {
        window.clearTimeout(receptionistState.soundFadeTimer);
        receptionistAmbience.volume = 0;
        await receptionistAmbience.play();
        receptionistState.soundEnabled = true;
        // Keep the optional ambience clearly below dialogue and viewer audio.
        fadeAmbienceTo(0.14);
        updateSoundControls();
      } catch (error) {
        receptionistState.soundUnavailable = true;
        receptionistState.soundEnabled = false;
        stopAmbience();
        updateSoundControls();
      }
    };
    updateSoundControls();
    receptionistSoundButtons.forEach(button => button.addEventListener("click", toggleAmbience));
    receptionistAmbience?.addEventListener("error", () => {
      receptionistState.soundUnavailable = true;
      receptionistState.soundEnabled = false;
      updateSoundControls();
    });
    document.addEventListener("visibilitychange", () => {
      if (!receptionistAmbience || !receptionistState.soundEnabled) return;
      if (document.hidden) receptionistAmbience.pause();
      else receptionistAmbience.play().catch(() => {
        receptionistState.soundUnavailable = true;
        receptionistState.soundEnabled = false;
        updateSoundControls();
      });
    });
    const resetGuideScroll = () => {
      if (receptionistPanel) receptionistPanel.scrollTop = 0;
      if (receptionistChoices) receptionistChoices.scrollTop = 0;
    };
    const setChoicesGated = gated => {
      guideState.dialogueChoicesRevealed = !gated;
      receptionistChoices.classList.toggle("is-gated", gated);
      if (gated) receptionistChoices.setAttribute("data-dialogue-pending", "true");
      else receptionistChoices.removeAttribute("data-dialogue-pending");
      receptionistChoices.setAttribute("aria-hidden", gated ? "true" : "false");
      if ("inert" in receptionistChoices) receptionistChoices.inert = gated;
      receptionistChoices.querySelectorAll("button, a[href]").forEach(element => {
        if (gated) {
          if (!element.hasAttribute("data-dialogue-tabindex")) element.setAttribute("data-dialogue-tabindex", element.getAttribute("tabindex") ?? "");
          element.setAttribute("tabindex", "-1");
        } else if (element.hasAttribute("data-dialogue-tabindex")) {
          const original = element.getAttribute("data-dialogue-tabindex");
          if (original === "") element.removeAttribute("tabindex"); else element.setAttribute("tabindex", original);
          element.removeAttribute("data-dialogue-tabindex");
        }
      });
    };
    const completeDialogueReveal = () => {
      window.clearTimeout(guideState.dialogueRevealTimer);
      guideState.dialogueRevealTimer = null;
      guideState.dialogueRevealComplete = true;
      receptionistRoot.classList.remove("is-line-reveal");
      receptionistRoot.classList.add("is-line-complete");
    };
    const armDialogue = (requireContinue = false, entrance = false) => {
      window.clearTimeout(guideState.dialogueRevealTimer);
      guideState.dialogueChoicesRevealed = false;
      guideState.dialogueRequiresContinue = Boolean(requireContinue);
      guideState.entranceOwnsDialogue = Boolean(entrance);
      guideState.dialogueRevealComplete = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches || false;
      if (receptionistContinue) {
        receptionistContinue.hidden = !guideState.dialogueRequiresContinue;
        receptionistContinue.disabled = false;
        receptionistContinue.textContent = "Continue";
      }
      receptionistRoot.classList.remove("is-choices-revealed", "is-line-complete", "is-line-reveal");
      setChoicesGated(true);
      if (entrance) {
        // The staged entrance owns the first reveal. Keeping the line classes
        // off prevents the normal dialogue animation from replaying over it.
        guideState.dialogueRevealComplete = true;
        return;
      }
      receptionistRoot.classList.add("is-line-reveal");
      if (guideState.dialogueRevealComplete) {
        receptionistRoot.classList.add("is-line-complete");
        guideState.entranceOwnsDialogue = false;
        if (!guideState.dialogueRequiresContinue) revealDialogueChoices();
      } else {
        guideState.dialogueRevealTimer = window.setTimeout(() => {
          completeDialogueReveal();
          if (!guideState.dialogueRequiresContinue) revealDialogueChoices();
        }, 420);
      }
    };
    const revealDialogueChoices = () => {
      if (!guideState.dialogueRevealComplete) {
        completeDialogueReveal();
        return false;
      }
      if (guideState.dialogueChoicesRevealed) return true;
      guideState.dialogueChoicesRevealed = true;
      receptionistRoot.classList.remove("is-line-reveal", "is-line-complete");
      receptionistRoot.classList.add("is-choices-revealed");
      setChoicesGated(false);
      if (receptionistContinue) receptionistContinue.hidden = true;
      if (receptionistState.entranceSettled && !receptionistRoot.classList.contains("is-entering")) focusFirstChoice();
      return true;
    };
    const setDialogue = (title, message, announce = true, options = {}) => {
      receptionistTitle.textContent = title;
      receptionistMessage.textContent = message;
      // Keep the full dialogue available to assistive technology even while
      // the visual line mask and Continue cue are still resolving.
      receptionistLive.textContent = message;
      resetGuideScroll();
      armDialogue(Boolean(options.requireContinue), Boolean(options.entrance));
    };
    const dialogueChoicesObserver = new MutationObserver(() => {
      if (!guideState.dialogueChoicesRevealed) setChoicesGated(true);
    });
    dialogueChoicesObserver.observe(receptionistChoices, { childList: true, subtree: true });
    const focusFirstChoice = () => {
      if (!guideState.dialogueChoicesRevealed && receptionistContinue && !receptionistContinue.hidden) {
        if (!receptionistState.entranceSettled || receptionistRoot.classList.contains("is-entering")) return;
        try { receptionistContinue.focus({ preventScroll: true }); } catch (error) { receptionistContinue.focus(); }
        return;
      }
      if (!guideState.dialogueChoicesRevealed || !receptionistState.entranceSettled || receptionistRoot.classList.contains("is-entering")) return;
      const first = receptionistRoot.classList.contains("is-date-state")
        ? (receptionistChoices.querySelector(".receptionist-calendar-day[tabindex='0']") || receptionistChoices.querySelector("[data-receptionist-date-submit]"))
        : receptionistChoices.querySelector("button, a[href]") || receptionistSkip;
      if (!first) return;
      try { first.focus({ preventScroll: true }); } catch (error) { first.focus(); }
    };
    const focusGuideDialog = () => {
      try { receptionistDialog.focus({ preventScroll: true }); } catch (error) { receptionistDialog.focus(); }
    };
    const setBackdrop = (room, photoIndex = 0) => {
      if (!receptionistBackdropImage) return;
      const images = venueImages(room);
      const galleryIndex = images.length ? Math.min(Math.max(Number(photoIndex) || 0, 0), images.length - 1) : 0;
      const source = room && images.length ? images[galleryIndex] : "assets/img/placeholder.jpg";
      receptionistBackdropImage.alt = room ? `${room.title || room.venue_name || "Venue"} preview` : "";
      const nextSource = source || "assets/img/placeholder.jpg";
      const fallbackSources = images.filter((_, index) => index !== galleryIndex);
      transitionImage(receptionistBackdropImage, nextSource, fallbackSources, "is-transitioning");
    };
    const updateReceptionistPhoto = (room, photoIndex) => {
      const images = venueImages(room);
      if (images.length < 2) return;
      receptionistPhotoIndex = (photoIndex + images.length) % images.length;
      setBackdrop(room, receptionistPhotoIndex);
      if (guideState.sharpImage) {
        const source = images[receptionistPhotoIndex];
        const fallbacks = images.filter((_, index) => index !== receptionistPhotoIndex);
        guideState.sharpImage.alt = `${room.title || room.venue_name || "Venue"} photo ${receptionistPhotoIndex + 1}`;
        transitionImage(guideState.sharpImage, source, fallbacks, "is-transitioning");
      }
      if (receptionistPhotoCounter) receptionistPhotoCounter.textContent = `${receptionistPhotoIndex + 1} / ${images.length}`;
    };
    const resetContext = () => {
      Object.keys(guideContext).forEach(key => { guideContext[key] = null; });
      receptionistRoot.classList.remove("is-venue-state", "is-venue-overview", "is-venue-dialogue");
      receptionistRoot.classList.remove("is-date-state");
      guideState.responseMode = "overview";
      guideState.returnStep = "greeting";
      guideState.shortlist = [];
      guideState.allVenues = [];
      guideState.allPage = 0;
      guideState.selectionOrigin = "shortlist";
      guideState.selectedAllPage = 0;
      guideState.includedAmenities = [];
      guideState.includedPage = 0;
      guideState.availabilityStatus = "idle";
      guideState.availabilityConfirmedIds = [];
      guideState.availabilityRequestToken += 1;
      guideState.dateDraft = null;
      guideState.dateMonth = null;
      guideState.dateFocus = null;
      guideState.dateQuestionCategory = null;
      guideState.sharpImage = null;
      guideState.sharpImageFallbacks = [];
    };
    const renderGreeting = ({ announce = true, requireContinue = false, entrance = false } = {}) => {
      resetContext();
      receptionistState.activeCategory = null;
      receptionistState.activeRoomId = null;
      activeRationale = "";
      receptionistPhotoIndex = 0;
      receptionistPhotoCounter = null;
      setBackdrop(dataMap[currentRoomId]);
      setDialogue("Welcome", receptionistGreeting, announce, { requireContinue, entrance });
      receptionistChoices.replaceChildren(
        createChoice("Event", "", { "data-receptionist-intent": "Event Hall" }),
        createChoice("Hotel", "", { "data-receptionist-intent": "Hotel Room" }),
        createChoice("Villa", "", { "data-receptionist-intent": "Resort Villa" }),
        createChoice("Just look around", "receptionist-choice-secondary", { "data-receptionist-close": "true" })
      );
    };
    const renderQuestion = (category, key) => {
      receptionistState.activeCategory = category;
      receptionistRoot.classList.remove("is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const label = categoryLabels[category] || "venue";
      let title = "A little more detail";
      let message = "";
      let options = [];
      if (category === "Event Hall" && key === "occasion") {
        title = "What are you celebrating?";
        message = "I’ll use the occasion to frame the most relevant event spaces.";
        options = [["Wedding", "wedding"], ["Celebration", "celebration"], ["Corporate", "corporate"], ["Other", "other"]];
      } else if (category === "Hotel Room" && key === "groupSize") {
        title = "How many guests?";
        message = "A guest count helps me keep the room suggestions practical.";
        options = [["1–2 guests", 2], ["3–4 guests", 4], ["5–6 guests", 6], ["7+ guests", 7]];
      } else if (category === "Hotel Room" && key === "preference") {
        title = "What matters most?";
        message = "Choose the trade-off you want me to prioritize.";
        options = [["Best value", "value"], ["Extra space", "space"], ["Premium / VIP", "premium"]];
      } else if (category === "Resort Villa" && key === "purpose") {
        title = "What brings you here?";
        message = "I’ll match the villa suggestions to the kind of stay you have in mind.";
        options = [["Relaxation", "relaxation"], ["Family gathering", "family"], ["Private stay", "private"]];
      } else if (category === "Event Hall" && key === "groupSize" || category === "Resort Villa" && key === "groupSize") {
        title = "How many guests?";
        message = `That lets me prioritize a ${label} that can welcome your group.`;
        options = [["1–10 guests", 10], ["11–30 guests", 30], ["31–60 guests", 60], ["61+ guests", 61]];
      }
      if (key === "eventDate" || key === "checkInDate" || key === "visitDate") {
        receptionistRoot.classList.add("is-date-state");
        receptionistRoot.classList.remove("is-venue-state", "is-venue-overview", "is-venue-dialogue");
        setDialogue(dateQuestionTitle(category), dateQuestionMessage(category));
        receptionistChoices.replaceChildren(
          createDateQuestion(category),
          createChoice("Choose date later", "receptionist-choice-secondary", { "data-receptionist-date-later": "true" }),
          createBackChoice()
        );
        return;
      }
      receptionistRoot.classList.remove("is-date-state");
      setDialogue(title, message);
      receptionistChoices.replaceChildren();
      options.forEach(([labelText, value]) => receptionistChoices.appendChild(createChoice(labelText, "", {
        "data-receptionist-answer": String(value), "data-answer-key": key
      })));
      receptionistChoices.appendChild(createBackChoice());
    };
    const rankVenues = (category, requestedCapacity, preference) => {
      const requested = numericFact(requestedCapacity);
      const candidateRooms = guideState.availabilityStatus === "confirmed"
        ? venuesForCategory(category).filter(room => guideState.availabilityConfirmedIds.includes(room.id))
        : venuesForCategory(category);
      const venues = candidateRooms.map((room, index) => {
        const capacity = numericFact(room.capacity_value);
        const rate = numericFact(room.rate_value);
        const beds = numericFact(room.beds_value);
        const exactFit = requested === null || (capacity !== null && capacity >= requested);
        const distance = requested === null || capacity === null ? Number.MAX_SAFE_INTEGER : Math.abs(capacity - requested);
        const vip = /vip|premium/i.test(`${room.room_type || ""} ${room.title || ""}`);
        return { room, index, capacity, rate, beds, exactFit, distance, vip };
      });
      const hasExplicitPremium = category === "Hotel Room" && venues.some(item => item.vip);
      venues.sort((left, right) => {
        if (left.exactFit !== right.exactFit) return left.exactFit ? -1 : 1;
        if (category === "Hotel Room" && preference === "value" && left.rate !== right.rate) return (left.rate ?? Number.MAX_SAFE_INTEGER) - (right.rate ?? Number.MAX_SAFE_INTEGER);
        if (category === "Hotel Room" && preference === "space") {
          if (left.capacity !== right.capacity) return (right.capacity ?? -1) - (left.capacity ?? -1);
          if (left.beds !== right.beds) return (right.beds ?? -1) - (left.beds ?? -1);
        }
        if (category === "Hotel Room" && preference === "premium" && hasExplicitPremium && left.vip !== right.vip) return left.vip ? -1 : 1;
        if (left.distance !== right.distance) return left.distance - right.distance;
        const leftTitle = String(left.room.title || left.room.venue_name || "").trim().toLowerCase();
        const rightTitle = String(right.room.title || right.room.venue_name || "").trim().toLowerCase();
        if (leftTitle !== rightTitle) return leftTitle < rightTitle ? -1 : 1;
        const leftId = String(left.room.id || "").trim().toLowerCase();
        const rightId = String(right.room.id || "").trim().toLowerCase();
        if (leftId !== rightId) return leftId < rightId ? -1 : 1;
        const leftVenueId = String(left.room.venue_id ?? "");
        const rightVenueId = String(right.room.venue_id ?? "");
        if (leftVenueId !== rightVenueId) return leftVenueId < rightVenueId ? -1 : 1;
        const leftRoomType = String(left.room.room_type || "").trim().toLowerCase();
        const rightRoomType = String(right.room.room_type || "").trim().toLowerCase();
        if (leftRoomType !== rightRoomType) return leftRoomType < rightRoomType ? -1 : 1;
        return left.index - right.index;
      });
      return venues;
    };
    const selectVenues = (category, requestedCapacity, preference) => {
      const ranked = rankVenues(category, requestedCapacity, preference);
      const matches = ranked.filter(item => item.exactFit && (preference !== "premium" || item.vip)).slice(0, 3);
      return { matches, hasExactFit: matches.length > 0 };
    };
    const rationaleFor = (room, category, requestedCapacity, preference, occasionOrPurpose = null) => {
      const capacity = numericFact(room.capacity_value);
      const requested = numericFact(requestedCapacity);
      if (requested !== null && capacity !== null && capacity >= requested) {
        if (category === "Event Hall" && occasionOrPurpose) return `A media-ready match for your ${occasionOrPurpose} plans, with capacity for up to ${formatNumber(capacity)} guests.`;
        if (category === "Resort Villa" && occasionOrPurpose) return `A media-ready villa to explore for your ${occasionOrPurpose} stay, with capacity for up to ${formatNumber(capacity)} guests.`;
        if (category === "Hotel Room" && preference === "value" && numericFact(room.rate_value) !== null) return "A lower listed rate among rooms that fit your group.";
        if (category === "Hotel Room" && preference === "value") return "Capacity fits your group; a comparable rate is not listed.";
        if (category === "Hotel Room" && preference === "space" && numericFact(room.capacity_value) !== null && numericFact(room.beds_value) !== null) return "Strong listed capacity and bed count among the rooms that fit.";
        if (category === "Hotel Room" && preference === "space") return "Capacity fits your group; room space or bed details are incomplete.";
        if (category === "Hotel Room" && preference === "premium" && /vip|premium/i.test(`${room.room_type || ""} ${room.title || ""}`)) return "A premium or VIP room type that fits your group.";
        return `Capacity for up to ${formatNumber(capacity)} guests, matching your group.`;
      }
      if (capacity !== null && requested !== null) return `Nearest available capacity: up to ${formatNumber(capacity)} guests.`;
      return "A media-ready venue to explore; confirm capacity with the team when booking.";
    };
    const renderShortlist = (announce = true) => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const category = receptionistState.activeCategory;
      const ranked = guideState.shortlist;
      const requested = numericFact(guideContext.groupSize);
      const label = categoryLabels[category] || "venue";
      const dateSummary = availabilityDateSummary(category);
      setDialogue("A considered shortlist", `These ${label}s are ordered around your group and preferences.${dateSummary ? ` ${dateSummary}` : ""}`, announce);
      receptionistChoices.replaceChildren();
      ranked.slice(0, 3).forEach((selected, index) => {
        const choice = createChoice(selected.room.title || selected.room.venue_name || "Venue", "receptionist-choice-venue receptionist-shortlist-choice", {
          "data-receptionist-room": String(selected.room.id),
          "data-receptionist-list-origin": "shortlist",
          "data-receptionist-shortlist-rank": String(index + 1)
        });
        const note = document.createElement("span");
        note.className = "receptionist-choice-note";
        note.textContent = rationaleFor(selected.room, category, requested, guideContext.preference, guideContext.occasion || guideContext.purpose);
        choice.appendChild(note);
        receptionistChoices.appendChild(choice);
      });
      if (!ranked.length) {
        const empty = document.createElement("p");
        empty.className = "receptionist-empty";
        empty.textContent = `There are no ${label}s with tour or gallery media available right now.`;
        receptionistChoices.appendChild(empty);
      }
      receptionistChoices.append(
        createChoice("Change search", "receptionist-choice-secondary", { "data-receptionist-change-search": "true" }),
        ...(guideContext.startDate ? [createChoice("Change date", "receptionist-choice-secondary", { "data-receptionist-change-date": "true" })] : []),
        createChoice("Start over", "receptionist-choice-secondary", { "data-receptionist-start-over": "true" })
      );
    };
    const renderAllPage = (announce = true) => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const category = receptionistState.activeCategory;
      const requested = numericFact(guideContext.groupSize);
      const all = guideState.allVenues;
      const pageSize = 3;
      const pageCount = Math.max(1, Math.ceil(all.length / pageSize));
      guideState.allPage = Math.min(Math.max(guideState.allPage, 0), pageCount - 1);
      const page = all.slice(guideState.allPage * pageSize, guideState.allPage * pageSize + pageSize);
      const label = categoryLabels[category] || "venue";
      const dateSummary = availabilityDateSummary(category);
      setDialogue("View all media-ready options", `Showing ${label}s outside the exact-fit shortlist. Capacity notes are factual; confirm details during booking.${dateSummary ? ` ${dateSummary}` : ""}`, announce);
      receptionistChoices.replaceChildren();
      page.forEach(({ room, capacity }) => {
        const choice = createChoice(room.title || room.venue_name || "Venue", "receptionist-choice-venue", { "data-receptionist-room": String(room.id), "data-receptionist-list-origin": "all" });
        const note = document.createElement("span");
        note.className = "receptionist-choice-note";
        note.textContent = capacity === null
          ? "Capacity is not listed; confirm it during booking."
          : requested !== null && capacity < requested
            ? `Capacity up to ${formatNumber(capacity)}; below your requested ${formatNumber(requested)} guests.`
            : `Capacity up to ${formatNumber(capacity)} guests.`;
        choice.appendChild(note);
        receptionistChoices.appendChild(choice);
      });
      if (!page.length) {
        const empty = document.createElement("p");
        empty.className = "receptionist-empty";
        empty.textContent = `There are no other media-ready ${label}s to show.`;
        receptionistChoices.appendChild(empty);
      }
      const pager = document.createElement("div");
      pager.className = "receptionist-pager";
      const previous = createChoice("Previous", "receptionist-choice-secondary", { "data-receptionist-all-prev": "true" });
      const counter = document.createElement("span");
      counter.className = "receptionist-photo-counter";
      counter.setAttribute("aria-live", "polite");
      counter.textContent = `${guideState.allPage + 1} / ${pageCount}`;
      const next = createChoice("Next", "receptionist-choice-secondary", { "data-receptionist-all-next": "true" });
      previous.disabled = guideState.allPage === 0;
      next.disabled = guideState.allPage >= pageCount - 1;
      pager.append(previous, counter, next);
      receptionistChoices.appendChild(pager);
      receptionistChoices.append(
        createChoice("Change search", "receptionist-choice-secondary", { "data-receptionist-change-search": "true" }),
        ...(guideContext.startDate ? [createChoice("Change date", "receptionist-choice-secondary", { "data-receptionist-change-date": "true" })] : []),
        createChoice("Start over", "receptionist-choice-secondary", { "data-receptionist-start-over": "true" })
      );
    };
    const renderRecommendations = () => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const category = receptionistState.activeCategory;
      const selection = selectVenues(category, guideContext.groupSize, guideContext.preference);
      guideState.shortlist = selection.matches;
      guideState.allVenues = rankVenues(category, guideContext.groupSize, guideContext.preference);
      guideState.allPage = 0;
      guideState.selectionOrigin = "shortlist";
      guideState.selectedAllPage = 0;
      if (selection.hasExactFit) {
        renderShortlist();
        return;
      }
      const label = categoryLabels[category] || "venue";
      const requestedText = numericFact(guideContext.groupSize) === null ? "your group" : `${formatNumber(numericFact(guideContext.groupSize))} guests`;
      const premiumGap = category === "Hotel Room" && guideContext.preference === "premium";
      const changeIntentLabel = category === "Event Hall" ? "Change occasion" : category === "Resort Villa" ? "Change purpose" : null;
      setDialogue(premiumGap ? "No Premium / VIP match" : "No exact capacity match", premiumGap
        ? `I couldn’t find a media-ready room that both fits ${requestedText} and is explicitly labeled Premium or VIP. You can change preference, adjust the guest count, or view every media-ready option.`
        : `I couldn’t find a media-ready ${label} with known capacity for ${requestedText}. You can adjust the group size or view every media-ready option.`, true);
      receptionistChoices.replaceChildren(
        createChoice("Change guest count", "receptionist-choice-primary", { "data-receptionist-change-group": "true" }),
        ...(changeIntentLabel ? [createChoice(changeIntentLabel, "receptionist-choice-secondary", { "data-receptionist-change-primary": "true" })] : []),
        ...(premiumGap ? [createChoice("Change preference", "receptionist-choice-secondary", { "data-receptionist-change-primary": "true" })] : []),
        createChoice("View all anyway", "receptionist-choice-secondary", { "data-receptionist-view-all": "true" }),
        createChoice("Just look around", "receptionist-choice-secondary", { "data-receptionist-close": "true" }),
        createChoice("Start over", "receptionist-choice-secondary", { "data-receptionist-start-over": "true" })
      );
    };
    const renderAvailabilityError = () => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      setDialogue("Date check unavailable", "The current calendar could not be checked, so I won’t guess at availability. No hold was created.");
      receptionistChoices.replaceChildren(
        createChoice("Retry", "receptionist-choice-primary", { "data-receptionist-date-submit": "true" }),
        createChoice("Choose date later", "receptionist-choice-secondary", { "data-receptionist-date-later": "true" }),
        createChoice("Just look around", "receptionist-choice-secondary", { "data-receptionist-close": "true" })
      );
    };
    const renderNoAvailability = () => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      setDialogue("Nothing available on that date", "The current calendar shows no available media-ready options for that date. No hold was created.");
      receptionistChoices.replaceChildren(
        createChoice("Change date", "receptionist-choice-primary", { "data-receptionist-change-date": "true" }),
        createChoice("View without date", "receptionist-choice-secondary", { "data-receptionist-date-later": "true" }),
        createChoice("Just look around", "receptionist-choice-secondary", { "data-receptionist-close": "true" })
      );
    };
    const requestVenueAvailability = async (room, startDate, endDate) => {
      const isHotel = room.category === "Hotel Room";
      const params = new URLSearchParams({
        building_name: String(room.venue_name || ""),
        room_name: String(room.venue_name || ""),
        room_type: String(isHotel ? room.room_type || "" : room.category || ""),
        start_date: startDate,
        end_date: endDate
      });
      const endpoint = isHotel ? "actions/bookings/get_room_availability.php" : "actions/bookings/fetch_dates.php";
      const url = isHotel ? `${endpoint}?${params.toString()}` : endpoint;
      const response = await fetch(url, { method: "POST", body: params, headers: { "X-Sevilla-Background": "true" } });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.success) throw new Error(data?.message || "Availability check failed");
      if (isHotel) return Number(data.available) > 0;
      const booked = new Set([...(Array.isArray(data.booked_dates) ? data.booked_dates : []), ...(Array.isArray(data.hard_blocked_dates) ? data.hard_blocked_dates : [])].map(String));
      return !booked.has(startDate);
    };
    const checkGuideAvailability = async () => {
      const startDate = guideContext.startDate;
      if (!isCanonicalDate(startDate)) { renderQuestion(receptionistState.activeCategory, dateStepFor(receptionistState.activeCategory)); return; }
      guideContext.endDate = receptionistState.activeCategory === "Hotel Room" ? addLocalDays(startDate, 1) : startDate;
      const token = ++guideState.availabilityRequestToken;
      guideState.availabilityStatus = "loading";
      setDialogue("Checking the current calendar", "I’m checking the selected date across the media-ready options. This creates no hold.");
      const candidates = venuesForCategory(receptionistState.activeCategory);
      receptionistChoices.replaceChildren(createChoice("Checking…", "receptionist-choice-secondary", { disabled: "true" }));
      const results = await Promise.allSettled(candidates.map(room => requestVenueAvailability(room, startDate, guideContext.endDate).then(available => ({ room, available }))));
      if (token !== guideState.availabilityRequestToken) return;
      if (results.some(result => result.status === "rejected")) {
        guideState.availabilityStatus = "error";
        guideState.availabilityConfirmedIds = [];
        renderAvailabilityError();
        focusFirstChoice();
        return;
      }
      guideState.availabilityConfirmedIds = results.filter(result => result.value.available).map(result => result.value.room.id);
      guideState.availabilityStatus = guideState.availabilityConfirmedIds.length ? "confirmed" : "none";
      if (guideState.availabilityStatus === "none") { renderNoAvailability(); focusFirstChoice(); return; }
      renderRecommendations();
      focusFirstChoice();
    };
    const appendFact = (facts, label, value) => {
      if (!value) return;
      const row = document.createElement("div");
      row.className = "receptionist-fact";
      const term = document.createElement("dt"); term.textContent = label;
      const detail = document.createElement("dd"); detail.textContent = value;
      row.append(term, detail); facts.appendChild(row);
    };
    const focusResponse = heading => {
      if (!heading) return;
      if (!guideState.dialogueChoicesRevealed && receptionistContinue && !receptionistContinue.hidden) {
        try { receptionistContinue.focus({ preventScroll: true }); } catch (error) { receptionistContinue.focus(); }
        return;
      }
      heading.setAttribute("tabindex", "-1");
      try { heading.focus({ preventScroll: true }); } catch (error) { heading.focus(); }
    };
    const matchingHotelRoomsByRate = () => {
      const requested = numericFact(guideContext.groupSize);
      return rankVenues("Hotel Room", requested, "value").filter(item => item.exactFit);
    };
    const preferenceLabel = preference => ({ value: "Best value", space: "Extra space", premium: "Premium / VIP" }[preference] || "your selected");
    const groupLabel = requested => requested === null ? "your selected group" : `${formatNumber(requested)}-person group`;
    const renderVenueOverview = room => {
      receptionistRoot.classList.add("is-venue-state");
      receptionistRoot.classList.remove("is-date-state", "is-venue-dialogue");
      receptionistRoot.classList.add("is-venue-overview");
      guideState.responseMode = "overview";
      guideState.returnStep = "venueOverview";
      guideState.includedAmenities = [];
      guideState.includedPage = 0;
      receptionistState.activeRoomId = room.id;
      const category = receptionistState.activeCategory || room.category;
      activeRationale = rationaleFor(room, category, guideContext.groupSize, guideContext.preference, guideContext.occasion || guideContext.purpose);
      const dateSummary = availabilityDateSummary(category);
      setDialogue(room.title || room.venue_name || "Venue details", dateSummary);
      receptionistChoices.replaceChildren();
      const showcase = document.createElement("div");
      showcase.className = "receptionist-showcase";
      const overview = document.createElement("div");
      overview.className = "receptionist-showcase-copy";
      const description = document.createElement("p");
      description.className = "receptionist-description";
      description.textContent = guideFact(room.description, `Explore this ${categoryLabels[room.category] || "venue"} in the Sevilla360 showroom.`);
      overview.appendChild(description);
      const rationale = document.createElement("p");
      rationale.className = "receptionist-rationale";
      rationale.textContent = activeRationale;
      overview.appendChild(rationale);
      const facts = document.createElement("dl");
      facts.className = "receptionist-facts";
      appendFact(facts, "Capacity", guideFact(room.capacity, "Not listed"));
      appendFact(facts, "Starting rate", guideFact(room.rate, "Not listed"));
      if (room.category === "Hotel Room") appendFact(facts, "Beds", guideFact(room.beds, "Not listed"));
      overview.appendChild(facts);
      const amenities = document.createElement("ul");
      amenities.className = "receptionist-amenities";
      (Array.isArray(room.amenities) ? room.amenities : []).map(item => String(item).trim()).filter(Boolean).slice(0, 4).forEach(item => {
        const li = document.createElement("li");
        li.textContent = item;
        amenities.appendChild(li);
      });
      if (amenities.children.length) overview.appendChild(amenities);
      const rating = document.createElement("p");
      rating.className = "receptionist-rating";
      rating.textContent = "Ratings loading…";
      overview.appendChild(rating);
      showcase.appendChild(overview);

      const images = venueImages(room);
      const photoCount = images.length;
      const sharp = document.createElement("figure");
      sharp.className = "receptionist-sharp-image";
      if (photoCount) {
        const sharpImage = document.createElement("img");
        sharpImage.alt = `${room.title || room.venue_name || "Venue"} photo ${receptionistPhotoIndex + 1}`;
        sharpImage.decoding = "async";
        sharpImage.loading = "eager";
        guideState.sharpImage = sharpImage;
        guideState.sharpImageFallbacks = images.filter((_, index) => index !== receptionistPhotoIndex);
        sharp.appendChild(sharpImage);
        transitionImage(sharpImage, images[receptionistPhotoIndex] || images[0], guideState.sharpImageFallbacks, "is-transitioning");
        const photoControls = document.createElement("div");
        photoControls.className = "receptionist-photo-controls";
        const photoCounter = document.createElement("span");
        photoCounter.className = "receptionist-photo-counter";
        photoCounter.setAttribute("aria-live", "polite");
        photoCounter.textContent = `${receptionistPhotoIndex + 1} / ${photoCount}`;
        receptionistPhotoCounter = photoCounter;
        if (photoCount > 1) {
          const previousPhoto = createPhotoArrow("Previous venue image", "data-receptionist-photo-prev", "fa-chevron-left");
          const nextPhoto = createPhotoArrow("Next venue image", "data-receptionist-photo-next", "fa-chevron-right");
          photoControls.append(previousPhoto, photoCounter, nextPhoto);
        } else {
          photoControls.classList.add("is-single");
          photoControls.appendChild(photoCounter);
        }
        sharp.appendChild(photoControls);
      } else {
        sharp.classList.add("is-empty");
        const emptyPhoto = document.createElement("span");
        emptyPhoto.textContent = "No gallery photos are listed for this venue.";
        sharp.appendChild(emptyPhoto);
        receptionistPhotoCounter = null;
        guideState.sharpImage = null;
        guideState.sharpImageFallbacks = [];
      }
      showcase.appendChild(sharp);
      receptionistChoices.appendChild(showcase);
      loadVenueReviews(room).then(data => {
        if (receptionistState.activeRoomId !== room.id) return;
        const count = Number(data?.rating_count || 0); const average = Number(data?.rating_average || 0);
        rating.textContent = count > 0 ? `${average.toFixed(1)} out of 5 · ${count} review${count === 1 ? "" : "s"}` : "No ratings yet";
      });
      const primaryActions = document.createElement("div");
      primaryActions.className = "receptionist-primary-actions";
      const tourAction = createChoice(hasPanorama(room) ? "Start 360° tour" : "Explore photos", "receptionist-choice-primary", { "data-receptionist-tour": "true" });
      const bookLink = document.createElement("a");
      bookLink.className = "receptionist-choice receptionist-choice-primary receptionist-choice-link";
      bookLink.href = getBookingUrl(room, guideContext.startDate, guideContext.endDate);
      bookLink.textContent = guideContext.startDate ? "Check dates and book" : "Book this venue";
      bookLink.setAttribute("data-receptionist-book", "true");
      const askAction = createChoice("More details", "receptionist-choice-secondary", { "data-receptionist-menu": "true" });
      primaryActions.append(tourAction, bookLink, askAction);
      receptionistChoices.appendChild(primaryActions);
    };
    const renderVenue = room => {
      const images = venueImages(room);
      receptionistPhotoIndex = Math.max(0, Math.min(receptionistPhotoIndex, images.length ? images.length - 1 : 0));
      setBackdrop(room, receptionistPhotoIndex);
      renderVenueOverview(room);
    };
    const renderVenueMenu = room => {
      receptionistRoot.classList.add("is-venue-state");
      receptionistRoot.classList.remove("is-date-state", "is-venue-overview");
      receptionistRoot.classList.add("is-venue-dialogue");
      guideState.responseMode = "menu";
      guideState.returnStep = "venueOverview";
      setDialogue("More about this venue", "Choose one next step and I’ll keep the current venue, answers, date, and photo in place.");
      const category = receptionistState.activeCategory || room.category;
      const compareAction = window.matchMedia?.("(min-width: 701px)").matches
        ? [createChoice(category === "Hotel Room" ? "Compare rooms" : "Compare venues", "receptionist-choice-secondary", { "data-receptionist-compare": "true" })]
        : [];
      receptionistChoices.replaceChildren(
        createChoice("Why this fits", "receptionist-choice-secondary", { "data-receptionist-why": "true" }),
        createChoice("What’s included", "receptionist-choice-secondary", { "data-receptionist-included": "true" }),
        ...compareAction,
        createChoice("Change search", "receptionist-choice-secondary", { "data-receptionist-change-search": "true" }),
        ...(guideContext.startDate ? [createChoice("Change date", "receptionist-choice-secondary", { "data-receptionist-change-date": "true" })] : []),
        createChoice("Start over", "receptionist-choice-secondary", { "data-receptionist-start-over": "true" })
      );
    };
    const renderResponse = (room, mode) => {
      receptionistRoot.classList.add("is-venue-state");
      receptionistRoot.classList.remove("is-date-state", "is-venue-overview");
      receptionistRoot.classList.add("is-venue-dialogue");
      guideState.responseMode = mode;
      guideState.returnStep = "venueOverview";
      const title = mode === "why" ? "Why this fits" : "What’s included";
      const heading = document.createElement("h3");
      heading.className = "receptionist-response-heading";
      heading.textContent = title;
      const response = document.createElement("div");
      response.className = "receptionist-response";
      response.appendChild(heading);
      const list = document.createElement("ul");
      list.className = "receptionist-response-list";
      if (mode === "why") {
        const requested = numericFact(guideContext.groupSize);
        const capacity = numericFact(room.capacity_value);
        const evidence = [];
        if (room.category === "Hotel Room") {
          const group = groupLabel(requested);
          const preference = preferenceLabel(guideContext.preference);
          if (requested !== null && capacity !== null && capacity >= requested) {
            const surplus = capacity - requested;
            evidence.push(`For your ${group} and ${preference} preference, this room accommodates everyone${surplus ? ` with ${formatNumber(surplus)} extra guest place${surplus === 1 ? "" : "s"}` : " exactly"}.`);
          } else if (capacity === null) {
            evidence.push(`For your ${group} and ${preference} preference, capacity is not listed; confirm the group fit during booking.`);
          } else {
            evidence.push(`For your ${group} and ${preference} preference, listed capacity is up to ${formatNumber(capacity)}; it does not meet the requested group.`);
          }
        } else if (requested !== null && capacity !== null && capacity >= requested) {
          const context = guideContext.occasion || guideContext.purpose;
          const contextPhrase = context ? ` and ${context} plans` : "";
          const surplus = capacity - requested;
          evidence.push(`For your ${groupLabel(requested)}${contextPhrase}, this venue accommodates everyone${surplus ? ` with ${formatNumber(surplus)} extra guest place${surplus === 1 ? "" : "s"}` : " exactly"}.`);
        } else if (capacity === null) evidence.push(`For your ${groupLabel(requested)}, capacity is not listed; confirm the group fit during booking.`);
        else evidence.push(`For your ${groupLabel(requested)}, listed capacity is up to ${formatNumber(capacity)}; it does not meet the requested group.`);
        if (room.category === "Hotel Room") {
          const beds = numericFact(room.beds_value);
          if (guideContext.preference === "value") {
            const matching = matchingHotelRoomsByRate();
            const rank = matching.findIndex(item => item.room.id === room.id);
            if (numericFact(room.rate_value) !== null && rank >= 0) evidence.push(`Its listed rate ranks ${rank + 1} among the ${matching.length} exact-fit room${matching.length === 1 ? "" : "s"} when ordered by rate.`);
            else evidence.push("A comparable rate is not listed; confirm the total during booking.");
          } else if (guideContext.preference === "space") {
            evidence.push(capacity === null ? "Capacity is not listed; confirm the room size during booking." : `Listed capacity is up to ${formatNumber(capacity)} guests.`);
            evidence.push(beds === null ? "Bed count is not listed; confirm the room setup during booking." : `${formatNumber(beds)} bed${beds === 1 ? "" : "s"} listed.`);
          } else {
            evidence.push(/vip|premium/i.test(`${room.room_type || ""} ${room.title || ""}`) ? "The room name or type explicitly identifies Premium/VIP." : "No explicit Premium/VIP designation is listed.");
            evidence.push(capacity === null ? "Capacity is not listed; confirm it during booking." : `Listed capacity is up to ${formatNumber(capacity)} guests.`);
            evidence.push(beds === null ? "Bed count is not listed; confirm the room setup during booking." : `${formatNumber(beds)} bed${beds === 1 ? "" : "s"} listed.`);
          }
        } else {
          evidence.push(guideContext.occasion || guideContext.purpose ? `Shown for your ${guideContext.occasion || guideContext.purpose} plans.` : "Selected from the matching media-ready venues.");
        }
        evidence.forEach(item => { const li = document.createElement("li"); li.textContent = item; list.appendChild(li); });
        setDialogue(title, "Here is the evidence behind this recommendation. Confirm missing details with the team during booking.");
      } else {
        const amenities = Array.isArray(room.amenities) ? room.amenities.map(item => String(item).trim()).filter(Boolean) : [];
        guideState.includedAmenities = amenities;
        const pageSize = 6;
        const pageCount = Math.max(1, Math.ceil(amenities.length / pageSize));
        guideState.includedPage = Math.min(Math.max(guideState.includedPage, 0), pageCount - 1);
        if (!amenities.length) {
          const empty = document.createElement("p"); empty.className = "receptionist-empty"; empty.textContent = "No amenities are listed for this venue. Confirm inclusions during booking."; response.appendChild(empty);
        } else {
          amenities.slice(guideState.includedPage * pageSize, guideState.includedPage * pageSize + pageSize).forEach(item => { const li = document.createElement("li"); li.textContent = item; list.appendChild(li); });
          response.appendChild(list);
          if (pageCount > 1) {
            const pager = document.createElement("div");
            pager.className = "receptionist-pager receptionist-included-pager";
            const previous = createChoice("Previous", "receptionist-choice-secondary", { "data-receptionist-included-prev": "true", "aria-label": "Previous inclusions page" });
            const counter = document.createElement("span");
            counter.className = "receptionist-photo-counter";
            counter.setAttribute("aria-live", "polite");
            counter.textContent = `Inclusions ${guideState.includedPage + 1} / ${pageCount}`;
            const next = createChoice("Next", "receptionist-choice-secondary", { "data-receptionist-included-next": "true", "aria-label": "Next inclusions page" });
            previous.disabled = guideState.includedPage === 0;
            next.disabled = guideState.includedPage >= pageCount - 1;
            pager.append(previous, counter, next);
            response.appendChild(pager);
          }
        }
        const source = document.createElement("p"); source.className = "receptionist-response-note"; source.textContent = "These inclusions are listed for this venue and can be confirmed during booking."; response.appendChild(source);
        setDialogue(title, "These are the inclusions currently listed for this venue. Use the page controls to review the complete list.");
      }
      if (list.children.length && !response.contains(list)) response.appendChild(list);
      const back = createChoice("Back to venue", "receptionist-back-text", { "data-receptionist-overview": "true" });
      receptionistChoices.replaceChildren(response, back);
      focusResponse(heading);
    };
    const restoreBackgroundInert = () => {
      receptionistState.inertElements.forEach(({ element, wasInert, wasAriaHidden }) => {
        if ("inert" in element) element.inert = wasInert;
        if (wasAriaHidden === null) element.removeAttribute("aria-hidden"); else element.setAttribute("aria-hidden", wasAriaHidden);
      });
      receptionistState.inertElements = [];
    };
    const setBackgroundInert = isInert => {
      if (!isInert) { restoreBackgroundInert(); return; }
      receptionistState.inertElements = backgroundElements.map(element => ({ element, wasInert: Boolean(element.inert), wasAriaHidden: element.getAttribute("aria-hidden") }));
      receptionistState.inertElements.forEach(({ element }) => { if ("inert" in element) element.inert = true; element.setAttribute("aria-hidden", "true"); });
    };
    const disableGuideFocus = () => {
      receptionistState.dialogFocusState = Array.from(receptionistDialog.querySelectorAll("button, a[href], [tabindex]"))
        .map(element => ({ element, tabindex: element.getAttribute("tabindex") }));
      if ("inert" in receptionistDialog) receptionistDialog.inert = true;
      receptionistDialog.setAttribute("aria-hidden", "true");
      receptionistState.dialogFocusState.forEach(({ element }) => element.setAttribute("tabindex", "-1"));
    };
    const restoreGuideFocus = () => {
      if ("inert" in receptionistDialog) receptionistDialog.inert = false;
      receptionistDialog.removeAttribute("aria-hidden");
      receptionistState.dialogFocusState.forEach(({ element, tabindex }) => {
        if (!element.isConnected) return;
        if (tabindex === null) element.removeAttribute("tabindex"); else element.setAttribute("tabindex", tabindex);
      });
      receptionistState.dialogFocusState = [];
    };
    const clearEntranceEnd = () => {
      if (receptionistState.entranceEndTarget && receptionistState.entranceEndHandler) {
        receptionistState.entranceEndTarget.removeEventListener("transitionend", receptionistState.entranceEndHandler);
      }
      receptionistState.entranceEndTarget = null;
      receptionistState.entranceEndHandler = null;
    };
    const openVenueMedia = room => {
      if (!room) { closeGuide(); return; }
      // The venue overview image is a gallery/panorama preview, not a shared
      // element. Close the guide and let the already-active viewer state own
      // the transition; its loading overlay remains visible until the
      // panorama reports ready, while gallery-only venues enter the existing
      // photo mode.
      closeGuide(() => {
        if (!hasPanorama(room) && hasGallery(room) && btnViewPhotos) btnViewPhotos.click();
      });
    };
    const closeGuide = (afterClose = null) => {
      if (!receptionistState.isOpen) return;
      receptionistState.isOpen = false;
      receptionistState.entranceSequence += 1;
      window.clearTimeout(receptionistState.entranceTimer);
      clearEntranceEnd();
      receptionistState.entranceSettled = false;
      receptionistRoot.classList.remove("is-entering", "is-priming", "is-ready", "is-reopening");
      if (receptionistDialog.contains(document.activeElement)) document.activeElement.blur();
      disableGuideFocus();
      receptionistRoot.classList.remove("is-open"); receptionistRoot.classList.add("is-closing");
      receptionistSoundButtons.filter(button => button !== receptionistRoot.querySelector("[data-receptionist-sound-toggle]")).forEach(button => {
        button.hidden = receptionistState.soundUnavailable;
      });
      document.body.classList.remove("showroom-receptionist-open");
      restoreBackgroundInert();
      if (currentRoomId) queueInteractionHint(currentRoomId);
      window.clearTimeout(receptionistState.closeTimer);
      receptionistState.closeTimer = window.setTimeout(() => {
        receptionistRoot.hidden = true; receptionistRoot.classList.remove("is-closing"); receptionistReopen.hidden = false; document.documentElement.classList.remove("showroom-receptionist-open");
        restoreGuideFocus();
        showQueuedInteractionHint();
        if (typeof afterClose === "function") afterClose();
        const focusTarget = receptionistState.previousFocus instanceof HTMLElement && receptionistState.previousFocus.isConnected ? receptionistState.previousFocus : receptionistReopen;
        focusTarget.focus();
      }, 240);
    };
    const openGreeting = opener => {
      window.clearTimeout(receptionistState.closeTimer);
      window.clearTimeout(receptionistState.entranceTimer);
      clearEntranceEnd();
      const entranceSequence = ++receptionistState.entranceSequence;
      receptionistState.previousFocus = opener instanceof HTMLElement ? opener : receptionistReopen;
      const isInitialGreeting = !receptionistState.hasOpened;
      receptionistState.isOpen = true; receptionistState.hasOpened = true; receptionistState.entranceSettled = false; receptionistReopen.hidden = true;
      receptionistSoundButtons.filter(button => button !== receptionistRoot.querySelector("[data-receptionist-sound-toggle]")).forEach(button => { button.hidden = true; });
      restoreGuideFocus();
      clearInteractionHint();
      receptionistRoot.classList.remove("is-closing", "is-priming", "is-ready", "is-reopening");
      receptionistRoot.classList.add("is-entering", "is-priming");
      if (!isInitialGreeting) receptionistRoot.classList.add("is-reopening");
      // Build the greeting under the hidden root with its entrance state
      // already active, so stale content never paints before the reveal.
      renderGreeting({ announce: !isInitialGreeting, requireContinue: isInitialGreeting, entrance: true });
      receptionistRoot.hidden = false;
      document.body.classList.add("showroom-receptionist-open"); document.documentElement.classList.add("showroom-receptionist-open"); setBackgroundInert(true);
      focusGuideDialog();
      const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
      guideState.entranceDuration = reducedMotion ? 0 : (isInitialGreeting ? 1100 : 480);
      const settleEntrance = () => {
        if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
        window.clearTimeout(receptionistState.entranceTimer);
        clearEntranceEnd();
        receptionistState.entranceSettled = true;
        focusFirstChoice();
      };
      const revealEntrance = () => {
        if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
        receptionistRoot.classList.remove("is-entering", "is-priming");
        receptionistRoot.classList.add("is-open", "is-ready");
        if (!guideState.dialogueRequiresContinue) revealDialogueChoices();
        if (reducedMotion) {
          settleEntrance();
          return;
        }
        const firstChoice = receptionistChoices.querySelector("button:not([disabled]), a[href]");
        if (!firstChoice) { settleEntrance(); return; }
        const onEnd = event => {
          if (event.target === firstChoice && event.propertyName === "opacity") settleEntrance();
        };
        receptionistState.entranceEndTarget = firstChoice;
        receptionistState.entranceEndHandler = onEnd;
        firstChoice.addEventListener("transitionend", onEnd);
        receptionistState.entranceTimer = window.setTimeout(settleEntrance, guideState.entranceDuration + 350);
      };
      if (reducedMotion) {
        revealEntrance();
        return;
      }
      window.requestAnimationFrame(() => {
        if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
        // Prime the already-rendered entering frame before any transition can
        // run. The next frame removes priming, and only the following frame
        // switches to the ready state, preventing a static-dialog flash.
        receptionistRoot.getBoundingClientRect();
        window.requestAnimationFrame(() => {
          if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
          receptionistRoot.classList.remove("is-priming");
          receptionistRoot.getBoundingClientRect();
          window.requestAnimationFrame(() => {
            if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
            revealEntrance();
          });
        });
      });
    };
    const focusableInGuide = () => {
      const focusable = Array.from(receptionistDialog.querySelectorAll("button:not([disabled]), a[href], [tabindex]:not([tabindex='-1'])")).filter(element => element.tabIndex >= 0 && !element.hidden && element.getClientRects().length > 0);
      return (receptionistRoot.classList.contains("is-entering") || !receptionistState.entranceSettled)
        ? focusable.filter(element => element === receptionistSkip)
        : focusable;
    };
    receptionistRoot.addEventListener("click", event => {
      const target = event.target instanceof Element ? event.target.closest("[data-receptionist-close], [data-receptionist-skip], [data-receptionist-back], [data-receptionist-overview], [data-receptionist-menu], [data-receptionist-intent], [data-receptionist-answer], [data-receptionist-room], [data-receptionist-tour], [data-receptionist-change], [data-receptionist-change-search], [data-receptionist-change-group], [data-receptionist-change-primary], [data-receptionist-change-date], [data-receptionist-start-over], [data-receptionist-view-all], [data-receptionist-date-submit], [data-receptionist-date-later], [data-receptionist-all-prev], [data-receptionist-all-next], [data-receptionist-compare], [data-receptionist-why], [data-receptionist-included], [data-receptionist-included-prev], [data-receptionist-included-next], [data-receptionist-photo-prev], [data-receptionist-photo-next], [data-receptionist-continue]") : null;
      if (!target) return;
      if (target.hasAttribute("data-receptionist-continue")) { revealDialogueChoices(); return; }
      if (target.hasAttribute("data-receptionist-close") || target.hasAttribute("data-receptionist-skip")) { closeGuide(); return; }
      if (target.hasAttribute("data-receptionist-start-over")) { renderGreeting({ announce: true, reset: true, requireContinue: true }); focusFirstChoice(); return; }
      if (target.hasAttribute("data-receptionist-back")) { renderGreeting({ announce: true, reset: true }); focusFirstChoice(); return; }
      if (target.hasAttribute("data-receptionist-overview")) { const room = dataMap[receptionistState.activeRoomId]; if (room) { renderVenueOverview(room); focusFirstChoice(); } return; }
      if (target.hasAttribute("data-receptionist-menu")) { const room = dataMap[receptionistState.activeRoomId]; if (room) { renderVenueMenu(room); focusFirstChoice(); } return; }
      if (target.hasAttribute("data-receptionist-date-later")) {
        guideContext.startDate = null;
        guideContext.endDate = null;
        guideState.dateDraft = null;
        guideState.dateFocus = null;
        guideState.dateMonth = null;
        guideState.dateQuestionCategory = null;
        guideState.availabilityStatus = "idle";
        guideState.availabilityConfirmedIds = [];
        guideState.availabilityRequestToken += 1;
        renderRecommendations(); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-change-date")) {
        guideState.availabilityRequestToken += 1;
        guideState.availabilityStatus = "idle";
        guideState.availabilityConfirmedIds = [];
        renderQuestion(receptionistState.activeCategory, dateStepFor(receptionistState.activeCategory)); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-change-search")) {
        const category = receptionistState.activeCategory;
        guideContext.occasion = null; guideContext.purpose = null; guideContext.groupSize = null; guideContext.preference = null; guideContext.startDate = null; guideContext.endDate = null;
        guideState.dateDraft = null; guideState.dateFocus = null; guideState.dateMonth = null; guideState.dateQuestionCategory = null;
        guideState.availabilityStatus = "idle"; guideState.availabilityConfirmedIds = []; guideState.availabilityRequestToken += 1;
        renderQuestion(category, category === "Event Hall" ? "occasion" : category === "Resort Villa" ? "purpose" : "groupSize"); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-date-submit")) {
        const input = receptionistRoot.querySelector("[data-receptionist-date-input]");
        const value = guideState.dateDraft || input?.value || guideContext.startDate;
        if (!isCanonicalDate(value)) {
          setDialogue(dateQuestionTitle(receptionistState.activeCategory), `Choose a valid ${dateAvailabilityLabel(receptionistState.activeCategory).toLowerCase()} from today onward.`);
          focusFirstChoice();
          return;
        }
        guideContext.startDate = value;
        checkGuideAvailability(); return;
      }
      if (target.hasAttribute("data-receptionist-change") || target.hasAttribute("data-receptionist-change-primary") || target.hasAttribute("data-receptionist-change-group")) {
        const category = receptionistState.activeCategory;
        const key = target.hasAttribute("data-receptionist-change-group") ? "groupSize" : category === "Hotel Room" ? "preference" : category === "Event Hall" ? "occasion" : "purpose";
        renderQuestion(category, key); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-compare")) {
        if (guideState.selectionOrigin === "all") {
          guideState.allPage = guideState.selectedAllPage;
          renderAllPage();
        } else {
          renderShortlist();
        }
        focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-view-all")) { guideState.allPage = 0; renderAllPage(); focusFirstChoice(); return; }
      if (target.hasAttribute("data-receptionist-all-prev") || target.hasAttribute("data-receptionist-all-next")) {
        guideState.allPage += target.hasAttribute("data-receptionist-all-next") ? 1 : -1;
        renderAllPage(); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-intent")) {
        const category = target.getAttribute("data-receptionist-intent"); resetContext(); receptionistState.activeCategory = category; guideContext.intent = category;
        renderQuestion(category, category === "Event Hall" ? "occasion" : category === "Resort Villa" ? "purpose" : "groupSize"); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-answer")) {
        const key = target.getAttribute("data-answer-key"); const value = target.getAttribute("data-receptionist-answer");
        guideContext[key] = key === "groupSize" ? Number(value) : value;
        const category = receptionistState.activeCategory;
        if (category === "Event Hall" && key === "occasion") {
          if (numericFact(guideContext.groupSize) === null) renderQuestion(category, "groupSize"); else renderRecommendations();
        }
        else if (category === "Event Hall" && key === "groupSize") {
          if (!guideContext.startDate) renderQuestion(category, "eventDate"); else renderRecommendations();
        }
        else if (category === "Hotel Room" && key === "groupSize") {
          if (guideContext.preference === null) renderQuestion(category, "preference"); else renderRecommendations();
        }
        else if (category === "Hotel Room" && key === "preference") {
          if (!guideContext.startDate) renderQuestion(category, "checkInDate"); else renderRecommendations();
        }
        else if (category === "Resort Villa" && key === "purpose") {
          if (numericFact(guideContext.groupSize) === null) renderQuestion(category, "groupSize"); else renderRecommendations();
        } else if (category === "Resort Villa" && key === "groupSize") {
          if (!guideContext.startDate) renderQuestion(category, "visitDate"); else renderRecommendations();
        } else renderRecommendations();
        focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-room")) {
        const room = dataMap[target.getAttribute("data-receptionist-room")];
        if (room) {
          const origin = target.getAttribute("data-receptionist-list-origin");
          guideState.selectionOrigin = origin === "all" ? "all" : "shortlist";
          guideState.selectedAllPage = guideState.allPage;
          activateVenue(room.id); renderVenue(room); focusFirstChoice();
        }
        return;
      }
      if (target.hasAttribute("data-receptionist-why")) { const room = dataMap[receptionistState.activeRoomId]; if (room) renderResponse(room, "why"); return; }
      if (target.hasAttribute("data-receptionist-included")) { const room = dataMap[receptionistState.activeRoomId]; if (room) renderResponse(room, "included"); return; }
      if (target.hasAttribute("data-receptionist-included-prev") || target.hasAttribute("data-receptionist-included-next")) {
        const room = dataMap[receptionistState.activeRoomId];
        if (room) {
          guideState.includedPage += target.hasAttribute("data-receptionist-included-next") ? 1 : -1;
          renderResponse(room, "included");
        }
        return;
      }
      if (target.hasAttribute("data-receptionist-photo-prev") || target.hasAttribute("data-receptionist-photo-next")) {
        const room = dataMap[receptionistState.activeRoomId];
        if (room && venueImages(room).length > 1) {
          const direction = target.hasAttribute("data-receptionist-photo-next") ? 1 : -1;
          updateReceptionistPhoto(room, receptionistPhotoIndex + direction);
        }
        return;
      }
      if (target.hasAttribute("data-receptionist-tour")) { const room = dataMap[receptionistState.activeRoomId]; openVenueMedia(room); return; }
    });
    receptionistReopen.addEventListener("click", () => openGreeting(receptionistReopen));
    document.addEventListener("keydown", event => {
      if (!receptionistState.isOpen) return;
      if (event.key === "Escape") { event.preventDefault(); closeGuide(); return; }
      if (event.key !== "Tab") return;
      const focusable = focusableInGuide(); if (!focusable.length) return;
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    receptionistState.hasOpened = false;
    window.setTimeout(() => { if (!receptionistState.hasOpened) openGreeting(); }, 0);
  }

  // --- 10. Photo Gallery Swap Mode ---
  btnViewPhotos.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "instant" });
    wrapper.classList.add("mode-photos");
    document.body.classList.add("no-scroll");
  });
  const exitPhotoMode = () => {
    wrapper.classList.remove("mode-photos");
    document.body.classList.remove("no-scroll");
  };
  // Delegate so both static controls continue to work even if the gallery
  // markup is replaced by a partial or browser interaction targets a child.
  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element
      ? event.target.closest("#btn-back-to-360, #btn-back-to-360-gallery")
      : null;
    if (target) exitPhotoMode();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const target = event.target instanceof Element
      ? event.target.closest("#btn-back-to-360, #btn-back-to-360-gallery")
      : null;
    if (!target) return;
    event.preventDefault();
    exitPhotoMode();
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

    const gallerySource = currentGallery[currentImageIndex];
    const galleryFallbacks = currentGallery.filter((_, galleryIndex) => galleryIndex !== currentImageIndex);
    transitionImage(currentSlideImg, gallerySource, galleryFallbacks, "fade-out");
    if (receptionistBackdropImage) transitionImage(receptionistBackdropImage, gallerySource, galleryFallbacks, "is-transitioning");
    document.getElementById("gallery-counter").textContent = `${currentImageIndex + 1} / ${currentGallery.length}`;
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
