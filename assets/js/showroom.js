/**
 * ==========================================================================
 * SEVILLA360 - Virtual Showroom Controller
 * Handles 360 Panolens Viewer, Dynamic Galleries, and Mobile Touch Events
 * ==========================================================================
 */
document.addEventListener("DOMContentLoaded", () => {
  window.SevillaHotspotMaterial?.preload?.((error, url) => console.warn("Showroom hotspot asset unavailable", url, error));
  
  // --- 1. Global State Variables ---
  const dataMap = window.showroomData || {};
  let currentGallery = [];
  let currentImageIndex = 0;
  
  let panoCache = {};
  let currentRoomId = null; 
  let currentPanoIndex = 0; 
  let activePanoramas = []; 
  let roomLoadToken = 0;
  let venueActivationToken = 0;
  let lastFramedPanorama = null;
  let lastFramedActivationToken = -1;
  const panoramaControlReady = new WeakSet();

  let currentZoom = 1;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let startX = 0;
  let startY = 0;
  let hasSeenHint = false;
  let hintPending = false;
  let hintReadyRoomId = null;
  let autoRotateTimer = null;
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

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
  const bigViewerBox = document.querySelector('.big-viewer-box');
  const topRoomLabel = document.getElementById("top-room-label");
  const destinationsButton = document.getElementById('btn-showroom-destinations');
  const destinationsCount = document.getElementById('showroom-destinations-count');
  const destinationsList = document.getElementById('showroom-destinations-list');
  const destinationsControl = document.getElementById('showroom-destinations');
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
  const getUserName = () => {
    if (typeof window.userName === "string" && window.userName.trim()) {
      return window.userName.trim();
    }
    const profileEl = document.querySelector(".user-profile-name, [data-user-name], .user-profile .name");
    if (profileEl) {
      const text = profileEl.getAttribute("data-user-name") || profileEl.textContent;
      if (text && text.trim()) return text.trim();
    }
    const userBtn = document.getElementById("userMenuBtn");
    if (userBtn) {
      const text = userBtn.textContent.trim();
      if (text && text.toLowerCase() !== "account" && text.toLowerCase() !== "login") {
        return text;
      }
    }
    try {
      const stored = sessionStorage.getItem("userName");
      if (stored && stored.trim()) return stored.trim();
    } catch (e) {}
    return null;
  };
  const resolveReceptionistGreeting = () => {
    const name = getUserName();
    if (name) {
      return `Welcome back, ${name}. I'm your virtual receptionist.`;
    }
    return "Welcome to M.I. Sevilla Resort & Events Place. I’m your virtual receptionist. How may I help you today?";
  };
  let receptionistGreeting = resolveReceptionistGreeting();
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
  const showroomTour = window.SevillaGuideTour?.init({
      helpButton: document.getElementById('btn-showroom-help'),
      key: 'sevilla360-showroom-tour-v1',
      steps: [
          { title: 'Drag to explore', copy: 'Drag the panorama to look around. Your view stays inside this venue.', target: '#pano-container' },
          { title: 'Info and destinations', copy: 'Open the information marker for venue facts, or use Destinations and walk markers to move between views.', target: '#btn-info' },
          { title: 'Zoom and fullscreen', copy: 'Use the zoom controls for detail and fullscreen when you want a wider view.', target: '#btn-fullscreen' }
      ],
      ready: () => Boolean(currentRoomId && panoContainer?.style.visibility === 'visible' && !receptionistState.isOpen)
  });

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
      <div class="showroom-view-fallback">
          <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
              <circle cx="8.5" cy="8.5" r="1.5"></circle>
              <polyline points="21 15 16 10 5 21"></polyline>
          </svg>
          <h2 class="showroom-view-fallback-title">STANDARD VIEW</h2>
          <p class="showroom-view-fallback-message">No 360° tour available for this venue.</p>
          <button type="button" class="showroom-retry-button" hidden>Retry 360° view</button>
      </div>
  `;
  bigViewerBox?.appendChild(no360Wrapper);

  // --- 4. Initialize Panolens 360 Viewer ---
  if (!panoContainer) return;

  const viewer = new PANOLENS.Viewer({
    container: panoContainer,
    controlBar: false, 
    autoRotate: false,
    autoHideInfospot: false,
    autoRotateSpeed: 0.5,
    antialias: true, 
    cameraFov: 85    
  });
  
  viewer.renderer.setPixelRatio(window.devicePixelRatio);

  const pauseAutoRotation = () => {
      window.clearTimeout(autoRotateTimer);
      autoRotateTimer = null;
      if (typeof viewer.disableAutoRate === 'function') viewer.disableAutoRate();
  };
  const scheduleAutoRotation = () => {
      pauseAutoRotation();
      if (reducedMotionQuery.matches || document.hidden || wrapper.classList.contains('mode-photos') || !currentRoomId || activePanoramas.length === 0) return;
      autoRotateTimer = window.setTimeout(() => {
          if (!reducedMotionQuery.matches && !document.hidden && !wrapper.classList.contains('mode-photos') && activePanoramas.length > 0 && typeof viewer.enableAutoRate === 'function') {
              viewer.enableAutoRate();
          }
      }, 5000);
  };
  const viewerActivity = () => scheduleAutoRotation();

  bigViewerBox?.addEventListener('pointerdown', viewerActivity, { passive: true });
  bigViewerBox?.addEventListener('pointermove', viewerActivity, { passive: true });
  bigViewerBox?.addEventListener('touchstart', viewerActivity, { passive: true });
  bigViewerBox?.addEventListener('wheel', viewerActivity, { passive: true });
  bigViewerBox?.addEventListener('keydown', viewerActivity);
  document.addEventListener('visibilitychange', () => {
      if (document.hidden) pauseAutoRotation();
      else scheduleAutoRotation();
  });
  reducedMotionQuery.addEventListener?.('change', event => {
      if (event.matches) pauseAutoRotation();
      else scheduleAutoRotation();
  });

  window.addEventListener('resize', () => {
      if (viewer && panoContainer.style.visibility === "visible") {
          viewer.onWindowResize();
      }
  });

  // --- 5. Custom 360 UI Controls ---
  document.getElementById("btn-zoom-in")?.addEventListener("click", () => {
      viewerActivity();
      viewer.camera.fov = Math.max(30, viewer.camera.fov - 10); 
      viewer.camera.updateProjectionMatrix(); 
  });
  
  document.getElementById("btn-zoom-out")?.addEventListener("click", () => {
      viewerActivity();
      viewer.camera.fov = Math.min(100, viewer.camera.fov + 10); 
      viewer.camera.updateProjectionMatrix(); 
  });
  
  document.getElementById("btn-fullscreen")?.addEventListener("click", () => {
      viewerActivity();
      if (!document.fullscreenElement) {
          panoContainer.requestFullscreen().then(() => { setTimeout(() => viewer.onWindowResize(), 100); });
      } else {
          document.exitFullscreen().then(() => { setTimeout(() => viewer.onWindowResize(), 100); });
      }
  });
  document.getElementById('btn-info')?.addEventListener('click', viewerActivity);
  const resetViewButton = document.getElementById('btn-reset-view');
  if (resetViewButton) resetViewButton.disabled = true;
  document.addEventListener('fullscreenchange', () => setTimeout(() => viewer.onWindowResize(), 100));

  function closeDestinations(restoreFocus = false) {
      if (!destinationsList || !destinationsButton) return;
      destinationsList.hidden = true;
      destinationsButton.setAttribute('aria-expanded', 'false');
      if (restoreFocus) destinationsButton.focus();
  }

  function enterPanorama(pano, viewerRef = viewer) {
      if (!pano) return;
      panoramaControlReady.delete(pano);
      viewerRef.setPanorama(pano);
  }

  destinationsButton?.addEventListener('click', event => {
      event.stopPropagation();
      if (destinationsButton.disabled) return;
      const isOpen = destinationsButton.getAttribute('aria-expanded') === 'true';
      destinationsList.hidden = isOpen;
      destinationsButton.setAttribute('aria-expanded', String(!isOpen));
      viewerActivity();
      if (!isOpen) destinationsList.querySelector('button')?.focus();
  });

  destinationsList?.addEventListener('click', event => {
      const destinationButton = event.target.closest('[data-destination-index]');
      if (!destinationButton) return;
      const index = Number(destinationButton.dataset.destinationIndex);
      if (!Number.isInteger(index) || index < 0 || index >= activePanoramas.length || !activePanoramas[index]) return;
      closeDestinations();
      currentPanoIndex = index;
      viewerActivity();
      enterPanorama(activePanoramas[index]);
  });

  destinationsList?.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
          event.preventDefault();
          closeDestinations(true);
      }
  });
  document.addEventListener('click', event => {
      if (!event.target.closest('#showroom-destinations')) closeDestinations();
  });

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

  function resolveHotspotTargetIndex(hotspot, roomData) {
      const mediaIds = Array.isArray(roomData?.pano_media_ids) ? roomData.pano_media_ids.map(Number) : [];
      const targetMediaId = Number(hotspot?.target_media_id);
      if (Number.isInteger(targetMediaId) && targetMediaId > 0) return mediaIds.indexOf(targetMediaId);
      if (hotspot?.target_media_id !== null && hotspot?.target_media_id !== undefined && hotspot?.target_media_id !== '') return -1;
      const legacyIndex = Number(hotspot?.target_pano_index);
      const legacyMediaIds = Array.isArray(roomData?.legacy_pano_media_ids) ? roomData.legacy_pano_media_ids.map(Number) : [];
      const legacyMediaId = Number.isInteger(legacyIndex) && legacyIndex >= 0 ? legacyMediaIds[legacyIndex] : null;
      return Number.isInteger(legacyMediaId) ? mediaIds.indexOf(legacyMediaId) : -1;
  }

  function activeDestinations(roomData, viewIndex) {
      const hotspots = roomData?.hotspots_by_pano_index?.[viewIndex] || [];
      return hotspots.filter(hotspot => hotspot.type === 'nav').map(hotspot => ({
          hotspot,
          targetIndex: resolveHotspotTargetIndex(hotspot, roomData),
          label: String(hotspot.title || 'Destination').trim().slice(0, 48)
      })).filter(destination => destination.targetIndex >= 0 && destination.targetIndex < (roomData?.pano_urls?.length || 0) && destination.targetIndex !== viewIndex);
  }

  function updateDestinations(roomData, viewIndex) {
      if (!destinationsButton || !destinationsList) return [];
      const entries = activeDestinations(roomData, viewIndex);
      destinationsList.replaceChildren();
      entries.forEach(entry => {
          const button = document.createElement('button');
          button.type = 'button';
          button.dataset.destinationIndex = String(entry.targetIndex);
          button.setAttribute('aria-label', `Go to ${entry.label}`);
          button.textContent = entry.label;
          destinationsList.appendChild(button);
      });
      destinationsButton.disabled = entries.length === 0;
      destinationsButton.setAttribute('aria-label', entries.length ? `Destinations, ${entries.length} available` : 'No destinations from this view');
      destinationsButton.title = entries.length ? `${entries.length} destination${entries.length === 1 ? '' : 's'} available from this view` : 'No destinations from this view';
      if (destinationsCount) destinationsCount.textContent = String(entries.length);
      closeDestinations();
      return entries;
  }

  function applyPanoramaView(roomData, mediaIndex, duration) {
      const mediaId = Array.isArray(roomData?.pano_media_ids) ? Number(roomData.pano_media_ids[mediaIndex]) : 0;
      const saved = mediaId > 0 ? roomData?.showroom_views?.[String(mediaId)] : null;
      const view = saved && [saved.x, saved.y, saved.z, saved.fov].every(value => Number.isFinite(Number(value)))
          ? { x: Number(saved.x), y: Number(saved.y), z: Number(saved.z), fov: Number(saved.fov) }
          : { x: 0, y: 0, z: -1, fov: 85 };
      const fov = Math.min(100, Math.max(30, view.fov));
      if (typeof viewer.setCameraFov === 'function') viewer.setCameraFov(fov);
      else {
          viewer.camera.fov = fov;
          viewer.camera.updateProjectionMatrix();
      }
      // Keep the captured world-space vector unchanged for setControlCenter.
      // The 0.12.1 tween fallback pre-negates a clone because that API mirrors X.
      const center = new THREE.Vector3(view.x, view.y, view.z);
      window.PanoramaViewCompat?.applyControlCenter(viewer, center, reducedMotionQuery.matches ? 0 : duration);
  }

  function synchronizePanoramaState(roomId, room, index, pano, panoramasRef, activationToken = venueActivationToken, transitionReady = false) {
      if (activationToken !== venueActivationToken || currentRoomId !== roomId || room.panoFailed || viewer.panorama !== pano ||
          panoCache[roomId] !== panoramasRef || activePanoramas !== panoramasRef || panoramasRef[index] !== pano) return false;

      currentPanoIndex = index;
      updateDestinations(room, index);
      if (!transitionReady) return true;

      // Viewer.add() installs Panolens' setCameraControl handler first. Our
      // enter-fade-start listener runs afterward, once that internal target
      // reset is complete; only this confirmed path applies the saved framing.
      if (lastFramedPanorama === pano && lastFramedActivationToken === activationToken) return true;
      lastFramedPanorama = pano;
      lastFramedActivationToken = activationToken;
      applyPanoramaView(room, index, 650);
      window.dispatchEvent(new CustomEvent('SevillaShowroomPanoramaReady'));
      showroomTour?.maybeAutoShow();
      scheduleAutoRotation();
      return true;
  }

  function resetCurrentView() {
      if (!currentRoomId || !activePanoramas.length) return;
      const room = dataMap[currentRoomId];
      if (!room) return;
      applyPanoramaView(room, currentPanoIndex, reducedMotionQuery.matches ? 0 : 650);
      viewerActivity();
  }

  document.getElementById('btn-reset-view')?.addEventListener('click', resetCurrentView);

  function showStandardFallback(room) {
      const bgImg = currentGallery.length > 0 ? currentGallery[0] : "assets/img/placeholder.jpg";
      if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
      hintPending = false;
      hintReadyRoomId = null;
      clearInteractionHint();
      no360Wrapper.style.backgroundImage = `url('${bgImg}')`;
      no360Wrapper.style.display = "flex";
      const failed = Boolean(room.panoFailed);
      const fallbackTitle = no360Wrapper.querySelector('.showroom-view-fallback-title');
      const fallbackMessage = no360Wrapper.querySelector('.showroom-view-fallback-message');
      const retryButton = no360Wrapper.querySelector('.showroom-retry-button');
      if (fallbackTitle) fallbackTitle.textContent = failed ? '360° VIEW UNAVAILABLE' : 'STANDARD VIEW';
      if (fallbackMessage) fallbackMessage.textContent = failed
          ? 'This 360° tour could not be loaded. Retry the view or browse the venue photos.'
          : 'No 360° tour available for this venue.';
      if (retryButton) {
          retryButton.hidden = !failed;
          retryButton.onclick = () => {
              room.panoFailed = false;
              room.panoRetryCount = (Number(room.panoRetryCount) || 0) + 1;
              activateVenue(currentRoomId);
          };
      }
      panoContainer.style.visibility = "hidden";
      pauseAutoRotation();
      updateDestinations(room, -1);
      if (destinationsControl) destinationsControl.style.display = 'none';
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
      if (resetViewButton) resetViewButton.disabled = true;
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



  // Retry is only offered from the explicit error state; this control restores
  // the saved/default framing without reloading assets or mutating pano_urls.

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


  function attachHotspots(pano, hotspotsArray, viewerRef, panoramasRef, roomData, isCurrentView, currentViewIndex) {
      if (!hotspotsArray || hotspotsArray.length === 0) return;

      const escapeHotspotText = (value) => String(value ?? '').replace(/[&<>'"]/g, char => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
      }[char]));

      const spots = [];

      hotspotsArray.forEach(h => {
          const isNav = h.type === 'nav';
          const destinationLabel = String(h.title || 'Destination').trim().slice(0, 48);
          const targetIndex = isNav ? resolveHotspotTargetIndex(h, roomData) : -1;
          if (isNav && (targetIndex < 0 || targetIndex >= (roomData?.pano_urls?.length || 0) || targetIndex === currentViewIndex)) return;

          const position = new THREE.Vector3(parseFloat(h.position_x), parseFloat(h.position_y), parseFloat(h.position_z));
          const spot = window.SevillaHotspotMaterial?.create
              ? window.SevillaHotspotMaterial.create(isNav ? 'nav' : 'info', position, Number(h.arrow_rotation), (error, url) => console.warn('Showroom hotspot icon unavailable', url, error))
              : new PANOLENS.Infospot(350, isNav ? 'assets/img/hotspot-nav-v3.png' : 'assets/img/hotspot-info-v3.png');
          if (!window.SevillaHotspotMaterial?.create) {
              spot.position.copy(position);
              if (spot.material) {
                  spot.material.transparent = true;
                  spot.material.alphaTest = 0.08;
                  spot.material.depthWrite = false;
                  spot.material.depthTest = false;
                  spot.material.needsUpdate = true;
              }
              window.SevillaHotspotMaterial?.configure(spot, isNav ? 'nav' : 'info', Number(h.arrow_rotation));
          }

          if (isNav) {
              const rotation = Number(h.arrow_rotation);
              if (spot.material && Number.isInteger(rotation) && rotation >= 0 && rotation <= 359) {
                  spot.material.rotation = rotation * Math.PI / 180;
                  spot.material.needsUpdate = true;
              }
              spot.addHoverText(escapeHotspotText(destinationLabel));
              spot.addEventListener('click', () => {
                  if (typeof isCurrentView === 'function' && !isCurrentView()) return;
                  if (targetIndex >= 0 && targetIndex < panoramasRef.length && panoramasRef[targetIndex]) {
                      currentPanoIndex = targetIndex;
                      viewerActivity();
                      enterPanorama(panoramasRef[targetIndex], viewerRef);
                  }
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
    if (!room) return "booking.php";
    const params = new URLSearchParams({ category: String(room.category || "") });
    if (room.room_group_id) {
      params.set("room_group_id", String(room.room_group_id));
      params.set("room_type", String(room.room_type || ""));
      params.set("venue_name", String(room.building_name || room.venue_name || ""));
    } else if (room.venue_id !== undefined && room.venue_id !== null) {
      params.set("venue_id", String(room.venue_id));
      params.set("room_type", String(room.room_type || ""));
      params.set("venue_name", String(room.venue_name || ""));
    } else {
      return "booking.php";
    }
    const startDate = arguments[1] || null;
    const endDate = arguments[2] || null;
    if (startDate && endDate) {
      params.set(room.room_group_id ? "check_in" : "start_date", String(startDate));
      params.set(room.room_group_id ? "check_out" : "end_date", String(endDate));
    }
    if (room.room_group_id && arguments[3]) params.set("guest_range", String(arguments[3]));
    return `booking.php?${params.toString()}`;
  }

  function activateVenue(roomId) {
    clearInteractionHint();
    pauseAutoRotation();
    closeDestinations();
    hintPending = false;
    hintReadyRoomId = null;
    const loadToken = ++roomLoadToken;
    const activationToken = ++venueActivationToken;
    currentRoomId = roomId; 
    const room = dataMap[roomId];
    if (!room) return;

    // Keep every venue entry point on the same active state and renderer.
    document.querySelectorAll(".dropdown-item").forEach(item => {
        const isCurrent = item.getAttribute("data-room") === roomId;
        item.classList.toggle("active", isCurrent);
        if (isCurrent) item.setAttribute('aria-current', 'true');
        else item.removeAttribute('aria-current');
    });
    document.querySelectorAll(".master-pill").forEach(master => {
        master.classList.toggle("active", master.getAttribute("data-category") === room.category);
    });
    document.querySelectorAll(".pill-dropdown-wrapper").forEach(menu => {
        menu.classList.remove("open");
        menu.querySelector('.master-pill')?.setAttribute('aria-expanded', 'false');
    });
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
      if (destinationsControl) destinationsControl.style.display = 'block';
      panoContainer.style.visibility = "visible";
      if (viewerControls) viewerControls.style.display = "flex"; 
      if (btnInfo) {
          btnInfo.disabled = false;
          btnInfo.style.display = "flex";
      }
      if (resetViewButton) resetViewButton.disabled = false;
      if (btnSwitch) btnSwitch.disabled = false;

      if (!panoCache[roomId]) {
        valTitle.textContent = "Loading 360...";
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "flex";

        const panoramas = [];
        panoUrls.forEach((url, index) => {
            if (roomLoadToken !== loadToken || room.panoFailed) return;
            let pano;
            const retryCount = Number(room.panoRetryCount) || 0;
            const requestUrl = retryCount > 0 ? `${url}${String(url).includes('?') ? '&' : '?'}showroom_retry=${retryCount}` : url;
            try {
                pano = new PANOLENS.ImagePanorama(requestUrl);
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
                () => currentRoomId === roomId && !room.panoFailed,
                index
            );

            pano.addEventListener('enter', () => {
                synchronizePanoramaState(roomId, room, index, pano, panoramas);
            });

            pano.addEventListener("load", function () {
                if (failureHandled || roomLoadToken !== loadToken || room.panoFailed) return;
                if (index === 0 && panoLoadingOverlay) {
                    valTitle.textContent = room.title;
                    panoLoadingOverlay.style.display = "none";
                    
                    // Queue the hint until the guide has completed its exit
                    // transition. The queue also covers a guide opened while
                    // Panolens is still loading this room.
                    queueInteractionHint(room.id);
                }
            });
            
            viewer.add(pano);
            // Register after Viewer.add() so this runs after Panolens' own
            // enter-fade-start listener resets the orbit-control target.
            pano.addEventListener('enter-fade-start', () => {
                panoramaControlReady.add(pano);
                synchronizePanoramaState(roomId, room, index, pano, panoramas, venueActivationToken, true);
            });
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
            const initialPanorama = activePanoramas[currentPanoIndex];
            const alreadyCurrent = viewer.panorama === initialPanorama;
            const canApplyImmediately = alreadyCurrent && panoramaControlReady.has(initialPanorama) && initialPanorama.loaded === true && Boolean(initialPanorama.material?.map);
            if (!canApplyImmediately) enterPanorama(initialPanorama);
            synchronizePanoramaState(roomId, room, currentPanoIndex, initialPanorama, activePanoramas, activationToken, canApplyImmediately);
        }
      } else {
        if (panoLoadingOverlay) panoLoadingOverlay.style.display = "none";
        activePanoramas = panoCache[roomId];
        currentPanoIndex = 0;
        const initialPanorama = activePanoramas[currentPanoIndex];
        const alreadyCurrent = viewer.panorama === initialPanorama;
        const canApplyImmediately = alreadyCurrent && panoramaControlReady.has(initialPanorama) && initialPanorama.loaded === true && Boolean(initialPanorama.material?.map);
        if (!canApplyImmediately) enterPanorama(initialPanorama);
        synchronizePanoramaState(roomId, room, currentPanoIndex, initialPanorama, activePanoramas, activationToken, canApplyImmediately);
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
  const categoryPills = document.querySelector('.master-category-pills');
  const roomNavigation = document.querySelector('.room-navigation-wrapper');

  function getHotelMenuSafeTop(viewportHeight, padding) {
      const header = document.getElementById('siteHeader');
      if (!header || typeof window.getComputedStyle !== 'function') return padding;

      const headerStyle = window.getComputedStyle(header);
      if ((headerStyle.position !== 'fixed' && headerStyle.position !== 'sticky')
          || headerStyle.display === 'none'
          || headerStyle.visibility === 'hidden') return padding;

      const headerBounds = header.getBoundingClientRect();
      const visibleTop = Math.max(0, headerBounds.top);
      const visibleBottom = Math.min(viewportHeight, headerBounds.bottom);
      if (headerBounds.width <= 0 || headerBounds.height <= 0 || visibleBottom <= visibleTop) return padding;

      return Math.min(viewportHeight - padding, Math.max(padding, headerBounds.bottom + padding));
  }

  function positionHotelMenu(wrapper) {
      if (!wrapper?.classList.contains('open')) return;
      const button = wrapper.querySelector('.master-pill');
      const menu = wrapper.querySelector('.hotel-room-groups-menu');
      if (!button || !menu) return;

      const bounds = button.getBoundingClientRect();
      const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
      const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
      const padding = 12;
      const gap = 12;
      const safeTop = getHotelMenuSafeTop(viewportHeight, padding);
      const menuWidth = menu.getBoundingClientRect().width;
      const left = Math.max(padding, Math.min(
          bounds.left + (bounds.width - menuWidth) / 2,
          viewportWidth - menuWidth - padding
      ));
      const desiredHeight = Math.min(560, viewportHeight * .68, viewportHeight - safeTop - padding);
      const spaceAbove = Math.max(0, bounds.top - gap - safeTop);
      const belowTop = Math.max(safeTop, bounds.bottom + gap);
      const spaceBelow = Math.max(0, viewportHeight - belowTop - padding);
      const contentHeight = Math.min(menu.scrollHeight, desiredHeight);
      const placeAbove = spaceAbove >= contentHeight || spaceAbove >= spaceBelow;
      const availableHeight = Math.min(desiredHeight, placeAbove ? spaceAbove : spaceBelow);
      const visibleHeight = Math.min(menu.scrollHeight, availableHeight);
      const top = placeAbove
          ? Math.max(safeTop, bounds.top - gap - visibleHeight)
          : Math.min(viewportHeight - padding - visibleHeight, belowTop);

      menu.style.setProperty('--hotel-menu-left', `${Math.round(left)}px`);
      menu.style.setProperty('--hotel-menu-top', `${Math.round(top)}px`);
      menu.style.setProperty('--hotel-menu-max-height', `${Math.floor(availableHeight)}px`);
  }

  function repositionOpenHotelMenu() {
      const openHotelMenu = document.querySelector('.hotel-room-dropdown.open');
      if (openHotelMenu) positionHotelMenu(openHotelMenu);
  }

  // A. Master Category Click (Toggle Menus)
  masterPills.forEach(master => {
      // Listen for both Click and Touch to fix the Inspect Element mobile bug!
      master.addEventListener("click", function(e) {
          e.stopPropagation(); 
          
          const wrapper = this.parentElement;
          const isOpen = wrapper.classList.contains("open");

          // Close all menus and flip all arrows down
          document.querySelectorAll(".pill-dropdown-wrapper").forEach(w => {
              w.classList.remove("open");
              w.querySelector('.master-pill')?.setAttribute('aria-expanded', 'false');
          });
          masterPills.forEach(m => m.classList.remove("menu-open"));

          // If it wasn't open, open it!
          if (!isOpen) {
              wrapper.classList.add("open");
              this.classList.add("menu-open");
              this.setAttribute('aria-expanded', 'true');
              if (wrapper.classList.contains('hotel-room-dropdown')) positionHotelMenu(wrapper);
          }
      });
  });

  // Keep clicks and touches on group headings or menu whitespace inside the
  // navigation; the existing window handlers still close on true outside use.
  categoryPills?.addEventListener('click', event => event.stopPropagation());
  categoryPills?.addEventListener('touchstart', event => event.stopPropagation(), { passive: true });

  // Close menus if clicking or touching anywhere else on the screen
  const closeAllMenus = () => {
      document.querySelectorAll(".pill-dropdown-wrapper").forEach(w => w.classList.remove("open"));
      masterPills.forEach(m => m.classList.remove("menu-open"));
      masterPills.forEach(m => m.setAttribute('aria-expanded', 'false'));
  };
  window.addEventListener("click", closeAllMenus);
  window.addEventListener("touchstart", closeAllMenus, {passive: true});
  roomNavigation?.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      const openWrapper = document.querySelector('.pill-dropdown-wrapper.open');
      if (!openWrapper) return;
      event.preventDefault();
      const trigger = openWrapper.querySelector('.master-pill');
      closeAllMenus();
      trigger?.focus();
  });
  roomNavigation?.addEventListener('focusout', event => {
      if (event.relatedTarget && !roomNavigation.contains(event.relatedTarget)) closeAllMenus();
  });
  window.addEventListener('resize', repositionOpenHotelMenu);
  window.addEventListener('scroll', repositionOpenHotelMenu, true);

  // B. Specific Room Click Logic
  dropdownItems.forEach(item => {
      item.addEventListener("click", function(e) {
          e.stopPropagation(); // Stop mobile browsers from double-firing events

          // Close the menu
          closeAllMenus();

          const roomId = this.getAttribute("data-room");

          // Dropdowns and the receptionist share one venue activation path.
          activateVenue(roomId);
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
    const saveGuideContext = () => {
      try {
        const memory = {
          groupSize: guideContext.groupSize,
          preference: guideContext.preference
        };
        sessionStorage.setItem("guideContext", JSON.stringify(memory));
      } catch (e) {}
    };
    const restoreGuideContext = () => {
      try {
        const raw = sessionStorage.getItem("guideContext");
        if (!raw) return;
        const memory = JSON.parse(raw);
        if (memory && typeof memory === "object") {
          if (memory.groupSize !== undefined && memory.groupSize !== null) {
            guideContext.groupSize = memory.groupSize;
          }
          if (memory.preference !== undefined && memory.preference !== null) {
            guideContext.preference = memory.preference;
          }
        }
      } catch (e) {}
    };
    const guideState = {
      responseMode: "overview",
      returnStep: "greeting",
      shortlist: [],
      allVenues: [],
      hotelResults: [],
      hotelRecommendationState: "idle",
      hotelRecommendationReasonCode: "",
      hotelRecommendationMessage: "",
      allPage: 0,
      selectionOrigin: "shortlist",
      selectedAllPage: 0,
      includedAmenities: [],
      includedPage: 0,
      availabilityStatus: "idle",
      availabilityChecked: false,
      availabilityConfirmedIds: [],
      availabilityRequestToken: 0,
      dateDraft: null,
      dateMonth: null,
      dateFocus: null,
      dateQuestionCategory: null,
      dateQuestionStep: null,
      sharpImage: null,
      sharpImageFallbacks: [],
      entranceDuration: 0,
      dialogueChoicesRevealed: false,
      dialogueRevealComplete: false,
      dialogueRequiresContinue: false,
      dialogueRevealTimer: null,
      entranceOwnsDialogue: false,
      thinkingTimer: null
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
    const categoryCapacityMax = category => {
      const capacities = venuesForCategory(category)
        .map(room => numericFact(room.capacity_value))
        .filter(value => Number.isInteger(value) && value > 0);
      return capacities.length ? Math.max(...capacities) : null;
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
    const dateQuestionTitle = (category, step = "") => category === "Hotel Room"
      ? (step === "checkOutDate" ? "When would you check out?" : "When would you check in?")
      : category === "Event Hall" ? "When is your event?" : "When would you visit?";
    const dateQuestionMessage = category => category === "Hotel Room" ? "Choose both dates for your stay. I’ll check this complete range once." : "I’ll check the selected calendar date. Availability is advisory and no hold is created.";
    const dateAvailabilityLabel = (category, step = "") => category === "Hotel Room"
      ? (step === "checkOutDate" ? "Checkout date" : "Check-in date")
      : category === "Event Hall" ? "Event date" : "Visit date";
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
    const createReceptionContactLink = () => {
      const link = document.createElement("a");
      link.className = "receptionist-choice receptionist-choice-secondary receptionist-choice-link";
      link.href = "support.php#contact";
      link.textContent = "Contact reception";
      return link;
    };
    const createPhotoArrow = (label, dataAttribute, iconName) => {
      const button = createChoice("", "receptionist-photo-control receptionist-photo-arrow", { [dataAttribute]: "true", "aria-label": label });
      const icon = document.createElement("i");
      icon.className = `fa-solid ${iconName}`;
      icon.setAttribute("aria-hidden", "true");
      button.appendChild(icon);
      return button;
    };
    const createDateQuestion = (category, step = "") => {
      const wrap = document.createElement("section");
      wrap.className = "receptionist-date-question";
      wrap.setAttribute("aria-labelledby", "receptionist-date-label");
      const label = document.createElement("label");
      label.className = "receptionist-date-label";
      label.id = "receptionist-date-label";
      label.textContent = dateAvailabilityLabel(category, step);
      const calendar = document.createElement("div");
      calendar.className = "receptionist-calendar";
      calendar.setAttribute("aria-label", `${dateAvailabilityLabel(category, step)} calendar`);
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

      const selectedDate = category === "Hotel Room" && step === "checkOutDate"
        ? guideContext.endDate || guideState.dateDraft
        : guideContext.startDate || guideState.dateDraft;
      const selectedLocal = localDateFromCanonical(selectedDate);
      const currentMonth = monthStart(new Date());
      if (!guideState.dateMonth || guideState.dateQuestionCategory !== category || guideState.dateQuestionStep !== step) {
        guideState.dateMonth = monthStart(selectedLocal || new Date());
        guideState.dateQuestionCategory = category;
        guideState.dateQuestionStep = step;
      }
      if (guideState.dateMonth < currentMonth) guideState.dateMonth = currentMonth;
      if (category === "Hotel Room" && step === "checkOutDate" && guideContext.startDate) {
        const checkInMonth = monthStart(localDateFromCanonical(guideContext.startDate));
        if (guideState.dateMonth < checkInMonth) guideState.dateMonth = checkInMonth;
      }
      guideState.dateDraft = selectedDate || null;

      const isSelectable = value => isCanonicalDate(value)
        && !(category === "Hotel Room" && step === "checkOutDate" && guideContext.startDate && value <= guideContext.startDate);
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
        const minimumMonth = category === "Hotel Room" && step === "checkOutDate" && guideContext.startDate
          ? monthStart(localDateFromCanonical(guideContext.startDate))
          : currentMonth;
        previousMonth.disabled = month.getFullYear() === minimumMonth.getFullYear() && month.getMonth() === minimumMonth.getMonth();
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
          const unavailable = !isSelectable(value);
          dayButton.disabled = unavailable;
          dayButton.tabIndex = value === targetFocus && !unavailable ? 0 : -1;
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
          if (!unavailable) {
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
                if (guideState.dateMonth < minimumMonth) guideState.dateMonth = minimumMonth;
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
    let audioCtx = null;
    let droneNodes = null;
    const getAudioContext = () => {
      if (!audioCtx) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return null;
        audioCtx = new AudioContextClass();
      }
      if (audioCtx.state === "suspended") {
        audioCtx.resume().catch(() => {});
      }
      return audioCtx;
    };
    const startSpaDrone = () => {
      const ctx = getAudioContext();
      if (!ctx || droneNodes) return;
      const filter = ctx.createBiquadFilter();
      filter.type = "lowpass";
      filter.frequency.setValueAtTime(500, ctx.currentTime);
      filter.Q.setValueAtTime(1.0, ctx.currentTime);
      const lfoFilter = ctx.createOscillator();
      const lfoFilterGain = ctx.createGain();
      lfoFilter.frequency.setValueAtTime(0.12, ctx.currentTime); // Faster LFO for 'fun' breathing
      lfoFilterGain.gain.setValueAtTime(250, ctx.currentTime);
      lfoFilter.connect(lfoFilterGain);
      lfoFilterGain.connect(filter.frequency);
      lfoFilter.start();
      const droneGain = ctx.createGain();
      droneGain.gain.setValueAtTime(0.35, ctx.currentTime);
      // G Major 7 (G2, B2, D3, F#3) - Warm, mid-low, premium but happy/fun
      const voices = [
        { freq: 98.00, type: "sine", detune: 0, gain: 0.35 },    // G2
        { freq: 123.47, type: "sine", detune: -4, gain: 0.25 },  // B2
        { freq: 146.83, type: "triangle", detune: 3, gain: 0.2 },// D3
        { freq: 185.00, type: "sine", detune: -2, gain: 0.15 },  // F#3
        { freq: 196.00, type: "sine", detune: 5, gain: 0.1 }     // G3
      ];
      const oscs = voices.map(v => {
        const osc = ctx.createOscillator();
        const vGain = ctx.createGain();
        osc.type = v.type;
        osc.frequency.setValueAtTime(v.freq, ctx.currentTime);
        osc.detune.setValueAtTime(v.detune, ctx.currentTime);
        vGain.gain.setValueAtTime(v.gain, ctx.currentTime);
        osc.connect(vGain);
        vGain.connect(filter);
        osc.start();
        return osc;
      });
      const masterGain = ctx.createGain();
      masterGain.gain.setValueAtTime(0, ctx.currentTime);
      filter.connect(droneGain);
      droneGain.connect(masterGain);
      masterGain.connect(ctx.destination);
      droneNodes = { filter, lfoFilter, lfoFilterGain, droneGain, oscs, masterGain };
    };
    const playTickSound = (isClick = false) => {
      if (!receptionistState.soundEnabled) return;
      const ctx = getAudioContext();
      if (!ctx || ctx.state !== "running") return;
      const now = ctx.currentTime;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      const duration = isClick ? 0.05 : 0.03;
      const volume = isClick ? 0.6 : 0.25;
      osc.type = "sine";
      osc.frequency.setValueAtTime(isClick ? 120 : 160, now);
      osc.frequency.exponentialRampToValueAtTime(40, now + duration);
      gain.gain.setValueAtTime(volume, now);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now);
      osc.stop(now + duration);
    };
    const playCascadeChime = () => {
      if (!receptionistState.soundEnabled) return;
      const ctx = getAudioContext();
      if (!ctx || ctx.state !== "running") return;
      
      const now = ctx.currentTime;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      
      osc.type = "sine";
      osc.frequency.setValueAtTime(90, now);
      osc.frequency.exponentialRampToValueAtTime(30, now + 0.04);
      
      gain.gain.setValueAtTime(0, now);
      gain.gain.linearRampToValueAtTime(0.4, now + 0.005);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.08);
      
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now);
      osc.stop(now + 0.1);
    };
    const playSuccessChime = () => {
      if (!receptionistState.soundEnabled) return;
      const ctx = getAudioContext();
      if (!ctx || ctx.state !== "running") return;
      const now = ctx.currentTime;
      const freqs = [146.83, 110.00];
      freqs.forEach(freq => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const filter = ctx.createBiquadFilter();
        osc.type = "triangle";
        filter.type = "lowpass";
        filter.frequency.setValueAtTime(400, now);
        filter.frequency.exponentialRampToValueAtTime(100, now + 2.0);
        osc.frequency.setValueAtTime(freq, now);
        gain.gain.setValueAtTime(0, now);
        gain.gain.linearRampToValueAtTime(0.4, now + 0.05);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 3.0);
        osc.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);
        osc.start(now);
        osc.stop(now + 3.1);
      });
    };
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
      const ctx = getAudioContext();
      if (droneNodes && ctx) {
        const now = ctx.currentTime;
        const currentGain = droneNodes.masterGain.gain.value;
        droneNodes.masterGain.gain.cancelScheduledValues(now);
        droneNodes.masterGain.gain.setValueAtTime(currentGain, now);
        droneNodes.masterGain.gain.linearRampToValueAtTime(target, now + 0.35);
      }
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
        startSpaDrone();
        receptionistAmbience.volume = 0;
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
      const ctx = getAudioContext();
      if (document.hidden) {
        if (ctx && ctx.state === "running") ctx.suspend().catch(() => {});
        receptionistAmbience.pause();
      } else {
        if (ctx && ctx.state === "suspended") ctx.resume().catch(() => {});
        fadeAmbienceTo(0.14);
      }
    });
    let lastHoverChoice = null;
    receptionistChoices.addEventListener("pointerover", event => {
      const choice = event.target instanceof Element ? event.target.closest(".receptionist-choice") : null;
      if (choice && choice !== lastHoverChoice) {
        lastHoverChoice = choice;
        playTickSound(false);
      }
    });
    receptionistChoices.addEventListener("pointerout", event => {
      const choice = event.target instanceof Element ? event.target.closest(".receptionist-choice") : null;
      if (choice && choice === lastHoverChoice) {
        const related = event.relatedTarget;
        if (related instanceof Element && choice.contains(related)) return;
        lastHoverChoice = null;
      }
    });
    receptionistChoices.addEventListener("pointerdown", event => {
      const choice = event.target instanceof Element ? event.target.closest(".receptionist-choice") : null;
      if (choice) {
        playTickSound(true);
      }
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
      playCascadeChime();
      return true;
    };
    const setDialogue = (title, message, announce = true, options = {}) => {
      const entrance = Boolean(options.entrance);
      if (!entrance) {
        // Freeze the outgoing dialogue in its hidden state before replacing
        // its text. Otherwise the existing line-reveal transition first
        // animates the old, already-visible copy out of the scene.
        receptionistRoot.classList.add("is-line-priming");
        receptionistRoot.classList.remove("is-choices-revealed", "is-line-complete", "is-line-reveal");
        setChoicesGated(true);
      }
      receptionistTitle.textContent = title;
      receptionistMessage.textContent = message;
      // Keep the full dialogue available to assistive technology even while
      // the visual line mask and Continue cue are still resolving.
      receptionistLive.textContent = message;
      resetGuideScroll();
      if (!entrance) receptionistRoot.getBoundingClientRect();
      armDialogue(Boolean(options.requireContinue), entrance);
      if (!entrance) receptionistRoot.classList.remove("is-line-priming");
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
        : receptionistChoices.querySelector("input[data-receptionist-group-input], button, a[href]") || receptionistSkip;
      if (!first) return;
      try { first.focus({ preventScroll: true }); } catch (error) { first.focus(); }
    };
    const focusGuideDialog = () => {
      try { receptionistDialog.focus({ preventScroll: true }); } catch (error) { receptionistDialog.focus(); }
    };
    const setBackdrop = (room, photoIndex = 0) => {
      if (!receptionistBackdropImage) return;
      // Hotel group panoramas are tour media, not standard room photos.
      // Preserve the legacy gallery behavior for Event and Villa venues.
      const images = room.room_group_id
        ? (hasGallery(room) ? room.gallery.filter(Boolean) : [])
        : venueImages(room);
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
      window.clearTimeout(guideState.thinkingTimer);
      receptionistRoot.classList.remove("is-thinking");
      Object.keys(guideContext).forEach(key => { guideContext[key] = null; });
      restoreGuideContext();
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
      receptionistRoot.classList.remove("is-hotel-results");
      receptionistGreeting = resolveReceptionistGreeting();
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
      receptionistRoot.classList.remove("is-hotel-results");
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
        message = "Choose a guest range for your room search.";
        options = [["1–2 guests", "1-2"], ["3–4 guests", "3-4"], ["5–6 guests", "5-6"], ["7–8 guests", "7-8"], ["9–12 guests", "9-12"], ["13–16 guests", "13-16"]];
      } else if (category === "Hotel Room" && key === "preference") {
        title = "What matters most?";
        message = "Choose the room recommendation approach that matters most to you.";
        options = [["Lowest price", "save"], ["Best fit for my group", "best_fit"], ["Higher room category", "comfort"]];
      } else if (category === "Resort Villa" && key === "purpose") {
        title = "What brings you here?";
        message = "I’ll match the villa suggestions to the kind of stay you have in mind.";
        options = [["Relaxation", "relaxation"], ["Family gathering", "family"], ["Private stay", "private"]];
      } else if ((category === "Event Hall" || category === "Resort Villa") && key === "groupSize") {
        title = "How many guests?";
        const maxCapacity = categoryCapacityMax(category);
        message = maxCapacity
          ? `Enter the exact number of guests. Media-ready ${label}s support up to ${formatNumber(maxCapacity)} guests.`
          : `Enter the exact number of guests. Capacity is not currently listed for media-ready ${label}s, so confirm fit during booking.`;
        setDialogue(title, message);
        receptionistChoices.replaceChildren();
        const form = document.createElement("form");
        form.className = "receptionist-guest-count-form";
        form.noValidate = true;
        const inputLabel = document.createElement("label");
        inputLabel.textContent = "Guest count";
        inputLabel.htmlFor = "receptionist-group-size";
        const input = document.createElement("input");
        input.id = "receptionist-group-size";
        input.type = "number";
        input.inputMode = "numeric";
        input.min = "1";
        if (maxCapacity) input.max = String(maxCapacity);
        input.step = "1";
        input.required = true;
        input.dataset.receptionistGroupInput = "true";
        input.value = Number.isInteger(guideContext.groupSize) && guideContext.groupSize > 0 ? String(guideContext.groupSize) : "";
        input.setAttribute("aria-describedby", "receptionist-group-size-error");
        const error = document.createElement("p");
        error.id = "receptionist-group-size-error";
        error.className = "receptionist-inline-error";
        error.setAttribute("role", "alert");
        error.hidden = true;
        const continueButton = createChoice("Continue", "receptionist-choice-primary", { "data-receptionist-group-submit": "true" });
        form.append(inputLabel, input, error, continueButton);
        form.addEventListener("submit", event => { event.preventDefault(); continueButton.click(); });
        receptionistChoices.append(form, createBackChoice());
        window.setTimeout(() => input.focus(), 0);
        return;
      }
      if (key === "eventDate" || key === "checkInDate" || key === "checkOutDate" || key === "visitDate") {
        receptionistRoot.classList.add("is-date-state");
        receptionistRoot.classList.remove("is-venue-state", "is-venue-overview", "is-venue-dialogue");
        guideState.dateQuestionStep = key;
        setDialogue(dateQuestionTitle(category, key), dateQuestionMessage(category));
        receptionistChoices.replaceChildren(
          createDateQuestion(category, key),
          createChoice(category === "Hotel Room" ? "Choose dates later" : "Choose date later", "receptionist-choice-secondary", { "data-receptionist-date-later": "true" }),
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
        const vip = room.room_type_code === "vip_suite";
        return { room, index, capacity, rate, beds, exactFit, distance, vip };
      });
      venues.sort((left, right) => {
        if (left.exactFit !== right.exactFit) return left.exactFit ? -1 : 1;
        if (category === "Hotel Room" && preference === "save" && left.rate !== right.rate) return (left.rate ?? Number.MAX_SAFE_INTEGER) - (right.rate ?? Number.MAX_SAFE_INTEGER);
        if (category === "Hotel Room" && preference === "best_fit") {
          if (left.distance !== right.distance) return left.distance - right.distance;
          if (left.beds !== right.beds) return (right.beds ?? -1) - (left.beds ?? -1);
          if (left.rate !== right.rate) return (left.rate ?? Number.MAX_SAFE_INTEGER) - (right.rate ?? Number.MAX_SAFE_INTEGER);
        }
        if (category === "Hotel Room" && preference === "comfort") {
          const comfortRank = item => ({ standard_room: 1, dormitory_room: 2, family_room_superior: 3, deluxe: 4, vip_suite: 5 }[item.room.room_type_code] || 0);
          const rankDifference = comfortRank(right) - comfortRank(left);
          if (rankDifference) return rankDifference;
        }
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
      const matches = ranked.filter(item => item.exactFit).slice(0, 3);
      return { matches, hasExactFit: matches.length > 0 };
    };
    const explicitTierFor = room => {
      return room?.room_type_code === "vip_suite" ? "VIP Suite" : "";
    };
    const listedRateFor = (room, category) => {
      const listedRate = guideFact(room.rate, null);
      if (listedRate) return listedRate;
      const rate = numericFact(room.rate_value);
      if (rate === null) return "Not listed";
      const unit = category === "Hotel Room" ? " /night" : " /day";
      return `₱${formatNumber(rate)}${unit}`;
    };
    const listedBedsFor = room => {
      const listedBeds = guideFact(room.beds, null);
      if (listedBeds) return listedBeds;
      const beds = numericFact(room.beds_value);
      return beds === null ? "Not listed" : `${formatNumber(beds)} bed${beds === 1 ? "" : "s"}`;
    };
    const capacityComparisonFor = (capacity, requested) => {
      if (requested !== null && capacity !== null) {
        const surplus = capacity - requested;
        if (surplus === 0) return `Fit: accommodates your ${formatNumber(requested)} guests exactly`;
        if (surplus > 0) return `Fit: accommodates your ${formatNumber(requested)} guests with room for ${formatNumber(surplus)} more`;
        return `Fit: capacity up to ${formatNumber(capacity)}, below your ${formatNumber(requested)}-guest request`;
      }
      if (requested !== null) return `Fit: capacity not listed for your ${formatNumber(requested)}-guest request`;
      if (capacity !== null) return `Fit: capacity up to ${formatNumber(capacity)} guests`;
      return "Fit: capacity not listed · confirm guest fit during booking";
    };
    const ratePositionFor = (room, exactFitRooms) => {
      const pricedRooms = exactFitRooms.filter(item => item.rate !== null);
      const position = pricedRooms.findIndex(item => item.room.id === room.id);
      if (position < 0) return "Rate position unavailable; no starting rate is listed";
      const total = pricedRooms.length;
      return `Listed rate position: #${position + 1} of ${total} exact-fit room${total === 1 ? "" : "s"} with rates listed`;
    };
    const rationaleFor = (room, category, requestedCapacity, preference, occasionOrPurpose = null, exactFitRooms = null) => {
      const requested = numericFact(requestedCapacity);
      if (category === "Event Hall" || category === "Resort Villa") {
        const context = occasionOrPurpose ? `For your ${occasionOrPurpose} plans; ` : "";
        return `${context}${requested !== null ? "Capacity fit is the ranking basis; closest fit is shown first." : "Capacity data is shown; no guest count was requested."}`;
      }
      if (category === "Hotel Room" && preference === "save") {
        const matching = Array.isArray(exactFitRooms) ? exactFitRooms : rankVenues("Hotel Room", requested, "save").filter(item => item.exactFit);
        return `Lowest price · ${ratePositionFor(room, matching)}`;
      }
      if (category === "Hotel Room" && preference === "best_fit") {
        return `Best fit for my group · capacity fit, then listed bed count and price`;
      }
      if (category === "Hotel Room" && preference === "comfort") {
        return `Higher room category · ${room.room_type || explicitTierFor(room) || "canonical room type"}`;
      }
      return requested !== null ? "Capacity fit for your guest request" : "Capacity details are shown for comparison";
    };
    const shortlistFactsFor = (room, category, requestedCapacity) => {
      const requested = numericFact(requestedCapacity);
      const facts = [capacityComparisonFor(numericFact(room.capacity_value), requested), `Listed starting rate: ${listedRateFor(room, category)}`];
      if (category === "Hotel Room") facts.push(`Beds: ${listedBedsFor(room)}`);
      return facts.join(" · ");
    };
    const renderShortlist = (announce = true) => {
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const category = receptionistState.activeCategory;
      const ranked = guideState.shortlist;
      const requested = numericFact(guideContext.groupSize);
      const label = categoryLabels[category] || "venue";
      const dateSummary = availabilityDateSummary(category);
      const context = guideContext.occasion || guideContext.purpose;
      const shortlistOrder = category === "Hotel Room"
        ? `These room options are ranked for ${preferenceLabel(guideContext.preference).toLowerCase()}.`
        : `These ${label}s are ordered by capacity fit;${context ? ` your ${context} ${category === "Event Hall" ? "occasion" : "purpose"} is shown for context.` : " closest fit is shown first."}`;
      const exactFitRooms = category === "Hotel Room" && guideContext.preference === "save"
        ? rankVenues("Hotel Room", requested, "save").filter(item => item.exactFit)
        : null;
      setDialogue("Recommended for you", `${shortlistOrder}${dateSummary ? ` ${dateSummary}` : ""}`, announce);
      playSuccessChime();
      receptionistChoices.replaceChildren();
      ranked.slice(0, 3).forEach((selected, index) => {
        const choice = createChoice(selected.room.title || selected.room.venue_name || "Venue", "receptionist-choice-venue receptionist-shortlist-choice", {
          "data-receptionist-room": String(selected.room.id),
          "data-receptionist-list-origin": "shortlist",
          "data-receptionist-shortlist-rank": String(index + 1)
        });
        const note = document.createElement("span");
        note.className = "receptionist-choice-note receptionist-shortlist-reason";
        note.textContent = rationaleFor(selected.room, category, requested, guideContext.preference, context, exactFitRooms);
        const details = document.createElement("span");
        details.className = "receptionist-shortlist-details";
        details.addEventListener("click", e => {
          e.stopPropagation();
          if (details.hasAttribute("data-open")) details.removeAttribute("data-open");
          else details.setAttribute("data-open", "true");
        });
        const summary = document.createElement("span");
        summary.className = "receptionist-shortlist-summary";
        summary.textContent = "View details";
        const facts = document.createElement("span");
        facts.className = "receptionist-shortlist-facts";
        facts.textContent = shortlistFactsFor(selected.room, category, requested);
        details.append(summary, facts);
        choice.append(note, details);
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
    const showRecommendationThinking = (title, message) => {
      window.clearTimeout(guideState.thinkingTimer);
      receptionistRoot.classList.add("is-thinking");
      setChoicesGated(true);
      receptionistChoices.replaceChildren();
      setDialogue(title, message, false);
      const dots = document.createElement("span");
      dots.className = "receptionist-thinking-dots";
      dots.setAttribute("aria-hidden", "true");
      dots.append(document.createElement("span"), document.createElement("span"), document.createElement("span"));
      receptionistMessage.appendChild(dots);
    };
    const renderRecommendations = () => {
      if (receptionistState.activeCategory === "Hotel Room") {
        requestHotelRecommendations();
        return;
      }
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      const category = receptionistState.activeCategory;
      const selection = selectVenues(category, guideContext.groupSize, guideContext.preference);
      guideState.shortlist = selection.matches;
      guideState.allVenues = rankVenues(category, guideContext.groupSize, guideContext.preference);
      guideState.allPage = 0;
      guideState.selectionOrigin = "shortlist";
      guideState.selectedAllPage = 0;

      showRecommendationThinking("Checking availability...", "Checking availability...");

      guideState.thinkingTimer = window.setTimeout(() => {
        receptionistRoot.classList.remove("is-thinking");
        if (!receptionistState.isOpen) return;
        if (selection.hasExactFit) {
          renderShortlist();
          return;
        }
        const label = categoryLabels[category] || "venue";
        const requestedText = numericFact(guideContext.groupSize) === null ? "your group" : `${formatNumber(numericFact(guideContext.groupSize))} guests`;
        const changeIntentLabel = category === "Event Hall" ? "Change occasion" : category === "Resort Villa" ? "Change purpose" : null;
        setDialogue("No exact capacity match", `I couldn’t find a media-ready ${label} with known capacity for ${requestedText}. You can adjust the group size or view every media-ready option.`, true);
        receptionistChoices.replaceChildren(
          createChoice("Change guest count", "receptionist-choice-primary", { "data-receptionist-change-group": "true" }),
          ...(changeIntentLabel ? [createChoice(changeIntentLabel, "receptionist-choice-secondary", { "data-receptionist-change-primary": "true" })] : []),
          createChoice("View all anyway", "receptionist-choice-secondary", { "data-receptionist-view-all": "true" }),
          createChoice("Just look around", "receptionist-choice-secondary", { "data-receptionist-close": "true" }),
          createChoice("Start over", "receptionist-choice-secondary", { "data-receptionist-start-over": "true" })
        );
      }, 800);
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
    const renderHotelRecommendationList = (announce = true) => {
      receptionistRoot.classList.add("is-hotel-results");
      receptionistRoot.classList.remove("is-date-state", "is-venue-state", "is-venue-overview", "is-venue-dialogue");
      receptionistRoot.classList.remove("is-hotel-explanation-open");
      guideState.responseMode = "recommendations";
      const results = (guideState.hotelResults || []).slice(0, 3);
      const state = guideState.hotelRecommendationState;
      const availabilityChecked = guideState.availabilityChecked === true;
      const recommendationMessage = state === "no_match" && typeof guideState.hotelRecommendationMessage === "string"
        ? guideState.hotelRecommendationMessage.trim() : "";
      const heading = state === "no_match" ? "No room matches found" : state === "partial" ? "A few rooms fit" : "Recommended rooms";
      const lead = state === "no_match"
        ? recommendationMessage || (availabilityChecked
          ? "No room group with an active room is available for this guest range and stay. Change your dates or search details, or contact reception."
          : "No active room group matches this guest range and recommendation. Try a different guest range or priority, or contact reception.")
        : availabilityChecked
          ? `${results.length} available room option${results.length === 1 ? "" : "s"} for ${guideContext.groupSize} guests, checked ${formatCalendarDate(guideContext.startDate)} to ${formatCalendarDate(guideContext.endDate)}.`
          : `${results.length} room option${results.length === 1 ? "" : "s"} fit your ${guideContext.groupSize}-guest range. Availability is not checked; add dates to check.`;
      setDialogue(heading, lead, announce);
      const resultsGrid = document.createElement("div");
      resultsGrid.className = "receptionist-hotel-results-grid";
      resultsGrid.setAttribute("role", "group");
      resultsGrid.setAttribute("aria-label", "Recommended hotel rooms");
      resultsGrid.classList.toggle("is-empty", results.length === 0);

      const actionsGroup = document.createElement("div");
      actionsGroup.className = "receptionist-hotel-actions";
      actionsGroup.setAttribute("role", "group");
      actionsGroup.setAttribute("aria-label", "Hotel recommendation actions");
      results.forEach((room, index) => {
        dataMap[room.id] = room;
        const card = document.createElement("article");
        card.className = "receptionist-hotel-result";
        const choice = createChoice(room.title, "receptionist-choice-venue receptionist-shortlist-choice", {
          "data-receptionist-room": room.id,
          "data-receptionist-list-origin": "shortlist",
          "data-receptionist-shortlist-rank": String(index + 1)
        });
        const note = document.createElement("span");
        note.className = "receptionist-choice-note receptionist-shortlist-reason";
        const reasonText = room.reasons?.[0] ? `${room.reasons[0].label}: ${room.reasons[0].value}` : "Fits your selected guest range.";
        const availabilityText = room.availability_checked === true ? "Available for your stay." : "Dates are needed to check availability.";
        note.textContent = `${reasonText} · ${availabilityText}`;
        choice.appendChild(note);
        const details = document.createElement("details");
        details.className = "receptionist-shortlist-details receptionist-hotel-explanation";
        const summary = document.createElement("summary");
        summary.className = "receptionist-shortlist-summary";
        summary.textContent = "View details";
        const list = document.createElement("ul");
        list.className = "receptionist-shortlist-facts";
        (Array.isArray(room.reasons) ? room.reasons : []).forEach(reason => {
          const item = document.createElement("li");
          item.textContent = `${reason.label}: ${reason.value}`;
          list.appendChild(item);
        });
        details.append(summary, list);
        details.addEventListener("toggle", () => {
          if (details.open) {
            resultsGrid.querySelectorAll(".receptionist-hotel-explanation[open]").forEach(openDetails => {
              if (openDetails !== details) openDetails.open = false;
            });
          }
          const explanationOpen = receptionistChoices.querySelector(".receptionist-hotel-explanation[open]") !== null;
          receptionistRoot.classList.toggle("is-hotel-explanation-open", explanationOpen);
        });
        card.append(choice, details);
        resultsGrid.appendChild(card);
      });
      if (state === "no_match" && !recommendationMessage) {
        const empty = document.createElement("p");
        empty.className = "receptionist-empty";
        empty.textContent = availabilityChecked
          ? "Reception can help review other arrangements."
          : "Reception can help review other room options.";
        resultsGrid.appendChild(empty);
      }
      actionsGroup.append(
        createChoice(guideContext.startDate && guideContext.endDate ? "Change dates" : "Choose dates", "receptionist-choice-primary", { "data-receptionist-change-date": "true" }),
        createChoice("Change guests or priority", "receptionist-choice-secondary", { "data-receptionist-change-search": "true" }),
        createChoice("Browse the showroom", "receptionist-choice-secondary", { "data-receptionist-close": "true" }),
        ...(state === "no_match" ? [createReceptionContactLink()] : [])
      );
      receptionistChoices.replaceChildren(resultsGrid, actionsGroup);
    };
    const requestHotelRecommendations = async () => {
      const token = ++guideState.availabilityRequestToken;
      const thinkingStartedAt = Date.now();
      const finishThinkingDelay = () => new Promise(resolve => {
        window.setTimeout(resolve, Math.max(0, 800 - (Date.now() - thinkingStartedAt)));
      });
      const formData = new FormData();
      formData.append("guest_range", String(guideContext.groupSize || ""));
      formData.append("priority", String(guideContext.preference || ""));
      formData.append("check_in", String(guideContext.startDate || ""));
      formData.append("check_out", String(guideContext.endDate || ""));
      guideState.availabilityStatus = "loading";
      guideState.availabilityChecked = false;
      guideState.hotelRecommendationState = "loading";
      guideState.hotelRecommendationReasonCode = "";
      guideState.hotelRecommendationMessage = "";
      showRecommendationThinking("Checking room options…", guideContext.startDate && guideContext.endDate
        ? "I’m checking available rooms for your complete stay. This does not hold a room."
        : "I’m comparing room capacity and recorded details without checking availability. Dates are needed to check whether a room is available.");
      try {
        const response = await fetch("actions/bookings/recommend_hotel_rooms.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => null);
        await finishThinkingDelay();
        if (token !== guideState.availabilityRequestToken) return;
        receptionistRoot.classList.remove("is-thinking");
        if (!response.ok || !data?.success) throw new Error(data?.message || "Room recommendations are unavailable.");
        guideState.hotelResults = Array.isArray(data.results) ? data.results : [];
        guideState.hotelRecommendationState = data.state || (guideState.hotelResults.length ? "matched" : "no_match");
        guideState.hotelRecommendationReasonCode = typeof data.reason_code === "string" ? data.reason_code : "";
        guideState.hotelRecommendationMessage = typeof data.message === "string" ? data.message : "";
        guideState.availabilityChecked = data.availability_checked === true;
        guideState.availabilityStatus = guideState.availabilityChecked
          ? (guideState.hotelResults.length ? "confirmed" : "none")
          : "not_checked";
        guideState.hotelResults.forEach(room => { dataMap[room.id] = room; });
        if (guideState.hotelResults.length) playSuccessChime();
        renderHotelRecommendationList();
        focusFirstChoice();
      } catch (error) {
        await finishThinkingDelay();
        if (token !== guideState.availabilityRequestToken) return;
        receptionistRoot.classList.remove("is-thinking");
        guideState.availabilityStatus = "error";
        guideState.hotelRecommendationState = "error";
        setDialogue("Recommendations unavailable", guideContext.startDate && guideContext.endDate
          ? "I couldn’t refresh room recommendations or confirm availability just now. Your answers are saved; retry or change your search."
          : "I couldn’t load room recommendations just now. Your answers are saved; retry or add dates to check availability.");
        receptionistChoices.replaceChildren(
          createChoice("Try again", "receptionist-choice-primary", { "data-receptionist-hotel-retry": "true" }),
          createChoice(guideContext.startDate && guideContext.endDate ? "Change dates" : "Choose dates", "receptionist-choice-secondary", { "data-receptionist-change-date": "true" }),
          createChoice("Change guests or priority", "receptionist-choice-secondary", { "data-receptionist-change-search": "true" }),
          createChoice("Browse the showroom", "receptionist-choice-secondary", { "data-receptionist-close": "true" })
        );
        focusFirstChoice();
      }
    };
    const checkGuideAvailability = async () => {
      const startDate = guideContext.startDate;
      if (receptionistState.activeCategory === "Hotel Room") {
        if (!isCanonicalDate(startDate) || !isCanonicalDate(guideContext.endDate) || guideContext.endDate <= startDate) {
          renderQuestion("Hotel Room", !isCanonicalDate(startDate) ? "checkInDate" : "checkOutDate");
          return;
        }
        await requestHotelRecommendations();
        return;
      }
      if (!isCanonicalDate(startDate)) { renderQuestion(receptionistState.activeCategory, dateStepFor(receptionistState.activeCategory)); return; }
      guideContext.endDate = startDate;
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
    const preferenceLabel = preference => ({ save: "Lowest price", best_fit: "Best fit for my group", comfort: "Higher room category" }[preference] || "your selected");
    const groupLabel = requested => requested === null ? "your selected group" : `${formatNumber(requested)}-person group`;
    const renderVenueOverview = room => {
      receptionistRoot.classList.toggle("is-hotel-results", Boolean(room.room_group_id));
      receptionistRoot.classList.add("is-venue-state");
      receptionistRoot.classList.remove("is-date-state", "is-venue-dialogue");
      receptionistRoot.classList.add("is-venue-overview");
      guideState.responseMode = "overview";
      guideState.returnStep = "venueOverview";
      guideState.includedAmenities = [];
      guideState.includedPage = 0;
      receptionistState.activeRoomId = room.id;
      const category = receptionistState.activeCategory || room.category;
      activeRationale = room.room_group_id && room.reasons?.[0]
        ? `${room.reasons[0].label}: ${room.reasons[0].value}`
        : rationaleFor(room, category, guideContext.groupSize, guideContext.preference, guideContext.occasion || guideContext.purpose);
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
      if (room.room_group_id) {
        if (room.pricing_basis === "estimated_nightly") {
          appendFact(facts, "Estimated nightly amount", `₱${Number(room.estimated_nightly_amount || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} /night, including extra-pax charges`);
        } else {
          appendFact(facts, "Estimated stay total", `₱${Number(room.estimated_total || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
        }
        if (room.availability_checked !== true) appendFact(facts, "Availability", "Dates needed to check availability");
        appendFact(facts, "Check-in / checkout", `${room.check_in_time} / ${room.check_out_time}`);
      }
      overview.appendChild(facts);
      if (room.room_group_id && Array.isArray(room.reasons) && room.reasons.length) {
        const explanation = document.createElement("details");
        explanation.className = "receptionist-hotel-explanation receptionist-overview-explanation";
        const summary = document.createElement("summary");
        summary.textContent = "Why this room was recommended";
        const list = document.createElement("ul");
        room.reasons.forEach(reason => {
          const item = document.createElement("li");
          item.textContent = `${reason.label}: ${reason.value}`;
          list.appendChild(item);
        });
        explanation.append(summary, list);
        overview.appendChild(explanation);
      }
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

      // Hotel group panoramas are tour media, not standard room photos.
      // Keep the detail gallery truthful while leaving the panorama action intact.
      const images = room.room_group_id
        ? (hasGallery(room) ? room.gallery.filter(Boolean) : [])
        : venueImages(room);
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
        const placeholder = document.createElement("img");
        placeholder.src = "assets/img/placeholder.jpg";
        const panoramaAvailable = Boolean(room.room_group_id && hasPanorama(room));
        const roomLabel = room.title || room.venue_name || "this room";
        placeholder.alt = panoramaAvailable
          ? `Standard room photo unavailable for ${roomLabel}`
          : `No room photo provided for ${roomLabel}`;
        placeholder.loading = "lazy";
        sharp.appendChild(placeholder);
        const emptyPhoto = document.createElement("span");
        emptyPhoto.textContent = panoramaAvailable
          ? "A standard room photo is unavailable. A 360° room tour is available."
          : "No room photos are provided. The recommendation is based on the listed room facts.";
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
      bookLink.href = getBookingUrl(room, guideContext.startDate, guideContext.endDate, guideContext.groupSize);
      bookLink.textContent = room.room_group_id ? "Book this room" : guideContext.startDate ? "Check dates and book" : "Book this venue";
      bookLink.setAttribute("data-receptionist-book", "true");
      if (room.room_group_id) {
        if (hasPanorama(room) || hasGallery(room)) primaryActions.appendChild(tourAction);
        primaryActions.appendChild(bookLink);
        primaryActions.appendChild(createChoice("Back to recommendations", "receptionist-choice-secondary", { "data-receptionist-hotel-back": "true" }));
      } else {
        const askAction = createChoice("More details", "receptionist-choice-secondary", { "data-receptionist-menu": "true" });
        primaryActions.append(tourAction, bookLink, askAction);
      }
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
        if (room.category === "Hotel Room" && Array.isArray(room.reasons) && room.reasons.length) {
          room.reasons.forEach(reason => evidence.push(`${reason.label}: ${reason.value}`));
        } else if (room.category === "Hotel Room") {
          evidence.push(capacity === null ? "Capacity is not listed; confirm the group fit during booking." : `Capacity fit: up to ${formatNumber(capacity)} guests.`);
          const beds = numericFact(room.beds_value);
          evidence.push(beds === null ? "Bed count is not listed; confirm the room setup during booking." : `Bed count: ${formatNumber(beds)} listed.`);
        } else if (requested !== null && capacity !== null && capacity >= requested) {
          const context = guideContext.occasion || guideContext.purpose;
          const contextPhrase = context ? ` and ${context} plans` : "";
          const surplus = capacity - requested;
          evidence.push(`For your ${groupLabel(requested)}${contextPhrase}, this venue accommodates everyone${surplus ? ` with ${formatNumber(surplus)} extra guest place${surplus === 1 ? "" : "s"}` : " exactly"}.`);
        } else if (capacity === null) evidence.push(`For your ${groupLabel(requested)}, capacity is not listed; confirm the group fit during booking.`);
        else evidence.push(`For your ${groupLabel(requested)}, listed capacity is up to ${formatNumber(capacity)}; it does not meet the requested group.`);
        if (room.category !== "Hotel Room") {
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
      const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
      if (reducedMotion) {
        closeGuide(() => {
          if (!hasPanorama(room) && hasGallery(room) && btnViewPhotos) btnViewPhotos.click();
        });
        return;
      }
      receptionistRoot.classList.add("is-tour-handoff");
      window.setTimeout(() => {
        closeGuide(() => {
          receptionistRoot.classList.remove("is-tour-handoff");
          if (!hasPanorama(room) && hasGallery(room) && btnViewPhotos) btnViewPhotos.click();
        });
      }, 600);
    };
    const closeGuide = (afterClose = null) => {
      if (!receptionistState.isOpen) return;
      receptionistState.isOpen = false;
      receptionistState.entranceSequence += 1;
      window.clearTimeout(receptionistState.entranceTimer);
      window.clearTimeout(guideState.thinkingTimer);
      clearEntranceEnd();
      receptionistState.entranceSettled = false;
      receptionistRoot.classList.remove("is-entering", "is-priming", "is-ready", "is-reopening", "is-tour-handoff", "is-thinking", "is-settled");
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
        receptionistRoot.hidden = true; receptionistRoot.classList.remove("is-closing", "is-tour-handoff", "is-thinking"); receptionistReopen.hidden = false; document.documentElement.classList.remove("showroom-receptionist-open");
        restoreGuideFocus();
        showQueuedInteractionHint();
        if (typeof afterClose === "function") afterClose();
        showroomTour?.maybeAutoShow();
        const focusTarget = receptionistState.previousFocus instanceof HTMLElement && receptionistState.previousFocus.isConnected ? receptionistState.previousFocus : receptionistReopen;
        focusTarget.focus();
      }, 240);
    };
    const openGreeting = opener => {
      // The receptionist owns the first-use focus trap. Close a replayed
      // showroom guide before opening it so the two overlays never compete.
      showroomTour?.close(true);
      window.clearTimeout(receptionistState.closeTimer);
      window.clearTimeout(receptionistState.entranceTimer);
      clearEntranceEnd();
      restoreGuideContext();
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
        receptionistRoot.classList.add("is-settled");
        focusFirstChoice();
      };
      const revealEntrance = () => {
        if (!receptionistState.isOpen || entranceSequence !== receptionistState.entranceSequence) return;
        receptionistRoot.classList.remove("is-entering", "is-priming", "is-settled");
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
      const target = event.target instanceof Element ? event.target.closest("[data-receptionist-close], [data-receptionist-skip], [data-receptionist-back], [data-receptionist-overview], [data-receptionist-menu], [data-receptionist-intent], [data-receptionist-answer], [data-receptionist-group-submit], [data-receptionist-room], [data-receptionist-hotel-back], [data-receptionist-hotel-retry], [data-receptionist-tour], [data-receptionist-change], [data-receptionist-change-search], [data-receptionist-change-group], [data-receptionist-change-primary], [data-receptionist-change-date], [data-receptionist-start-over], [data-receptionist-view-all], [data-receptionist-date-submit], [data-receptionist-date-later], [data-receptionist-all-prev], [data-receptionist-all-next], [data-receptionist-compare], [data-receptionist-why], [data-receptionist-included], [data-receptionist-included-prev], [data-receptionist-included-next], [data-receptionist-photo-prev], [data-receptionist-photo-next], [data-receptionist-continue]") : null;
      if (!target) return;
      if (target.hasAttribute("data-receptionist-continue")) { revealDialogueChoices(); return; }
      if (target.hasAttribute("data-receptionist-close") || target.hasAttribute("data-receptionist-skip")) { closeGuide(); return; }
      if (target.hasAttribute("data-receptionist-start-over")) {
        try { sessionStorage.removeItem("guideContext"); } catch (e) {}
        renderGreeting({ announce: true, reset: true, requireContinue: true });
        focusFirstChoice();
        return;
      }
      if (target.hasAttribute("data-receptionist-back")) { renderGreeting({ announce: true, reset: true }); focusFirstChoice(); return; }
      if (target.hasAttribute("data-receptionist-overview")) { const room = dataMap[receptionistState.activeRoomId]; if (room) { renderVenueOverview(room); focusFirstChoice(); } return; }
      if (target.hasAttribute("data-receptionist-hotel-back")) { renderHotelRecommendationList(); focusFirstChoice(); return; }
      if (target.hasAttribute("data-receptionist-hotel-retry")) { requestHotelRecommendations(); return; }
      if (target.hasAttribute("data-receptionist-menu")) { const room = dataMap[receptionistState.activeRoomId]; if (room) { renderVenueMenu(room); focusFirstChoice(); } return; }
      if (target.hasAttribute("data-receptionist-date-later")) {
        guideContext.startDate = null;
        guideContext.endDate = null;
        guideState.dateDraft = null;
        guideState.dateFocus = null;
        guideState.dateMonth = null;
        guideState.dateQuestionCategory = null;
        guideState.availabilityStatus = "idle";
        guideState.availabilityChecked = false;
        guideState.availabilityConfirmedIds = [];
        guideState.availabilityRequestToken += 1;
        if (receptionistState.activeCategory === "Hotel Room") requestHotelRecommendations();
        else { renderRecommendations(); focusFirstChoice(); }
        return;
      }
      if (target.hasAttribute("data-receptionist-change-date")) {
        guideState.availabilityRequestToken += 1;
        guideState.availabilityStatus = "idle";
        guideState.availabilityChecked = false;
        guideState.availabilityConfirmedIds = [];
        if (receptionistState.activeCategory === "Hotel Room") {
          guideContext.startDate = null;
          guideContext.endDate = null;
          guideState.dateDraft = null;
          guideState.dateMonth = null;
          guideState.dateQuestionStep = null;
        }
        renderQuestion(receptionistState.activeCategory, dateStepFor(receptionistState.activeCategory)); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-change-search")) {
        const category = receptionistState.activeCategory;
        guideContext.occasion = null; guideContext.purpose = null; guideContext.groupSize = null; guideContext.preference = null; guideContext.startDate = null; guideContext.endDate = null;
        guideState.dateDraft = null; guideState.dateFocus = null; guideState.dateMonth = null; guideState.dateQuestionCategory = null;
        guideState.availabilityStatus = "idle"; guideState.availabilityChecked = false; guideState.availabilityConfirmedIds = []; guideState.availabilityRequestToken += 1;
        renderQuestion(category, category === "Event Hall" ? "occasion" : category === "Resort Villa" ? "purpose" : "groupSize"); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-date-submit")) {
        const input = receptionistRoot.querySelector("[data-receptionist-date-input]");
        const value = guideState.dateDraft || input?.value || (guideState.dateQuestionStep === "checkOutDate" ? guideContext.endDate : guideContext.startDate);
        if (!isCanonicalDate(value)) {
          setDialogue(dateQuestionTitle(receptionistState.activeCategory, guideState.dateQuestionStep), `Choose a valid ${dateAvailabilityLabel(receptionistState.activeCategory, guideState.dateQuestionStep).toLowerCase()} from today onward.`);
          focusFirstChoice();
          return;
        }
        if (receptionistState.activeCategory === "Hotel Room" && guideState.dateQuestionStep === "checkInDate") {
          guideContext.startDate = value;
          guideContext.endDate = null;
          guideState.dateDraft = null;
          renderQuestion("Hotel Room", "checkOutDate");
          focusFirstChoice();
          return;
        }
        if (receptionistState.activeCategory === "Hotel Room" && guideState.dateQuestionStep === "checkOutDate") {
          if (!isCanonicalDate(guideContext.startDate) || value <= guideContext.startDate) {
            setDialogue("Choose a later checkout", "Checkout must be after check-in so the stay includes at least one night.");
            focusFirstChoice();
            return;
          }
          guideContext.endDate = value;
        } else {
          guideContext.startDate = value;
        }
        checkGuideAvailability(); return;
      }
      if (target.hasAttribute("data-receptionist-change") || target.hasAttribute("data-receptionist-change-primary") || target.hasAttribute("data-receptionist-change-group")) {
        const category = receptionistState.activeCategory;
        const key = target.hasAttribute("data-receptionist-change-group") ? "groupSize" : category === "Hotel Room" ? "preference" : category === "Event Hall" ? "occasion" : "purpose";
        renderQuestion(category, key); focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-group-submit")) {
        const input = receptionistRoot.querySelector("[data-receptionist-group-input]");
        const error = receptionistRoot.querySelector("#receptionist-group-size-error");
        const raw = String(input?.value ?? "").trim();
        const maxCapacity = categoryCapacityMax(receptionistState.activeCategory);
        let message = "";
        if (!/^\d+$/.test(raw)) message = "Enter a whole number of guests, such as 4.";
        else {
          const count = Number(raw);
          if (!Number.isSafeInteger(count) || count < 1) message = "Enter at least 1 guest.";
          else if (maxCapacity !== null && count > maxCapacity) message = `Enter ${formatNumber(maxCapacity)} guests or fewer for this category.`;
          else {
            guideContext.groupSize = count;
            saveGuideContext();
            const category = receptionistState.activeCategory;
            if (category === "Event Hall" && !guideContext.startDate) renderQuestion(category, "eventDate");
            else if (category === "Resort Villa" && !guideContext.startDate) renderQuestion(category, "visitDate");
            else renderRecommendations();
            focusFirstChoice();
            return;
          }
        }
        if (error) { error.textContent = message; error.hidden = false; }
        input?.setAttribute("aria-invalid", "true");
        input?.focus();
        return;
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
        if (category === "Hotel Room") {
          if (!new Set(["1-2", "3-4", "5-6", "7-8", "9-12", "13-16"]).has(guideContext.groupSize)) guideContext.groupSize = null;
          if (!new Set(["save", "best_fit", "comfort"]).has(guideContext.preference)) guideContext.preference = null;
          if (guideContext.groupSize === null) renderQuestion(category, "groupSize");
          else if (guideContext.preference === null) renderQuestion(category, "preference");
          else renderQuestion(category, "checkInDate");
        } else {
          renderQuestion(category, category === "Event Hall" ? "occasion" : category === "Resort Villa" ? "purpose" : "groupSize");
        }
        focusFirstChoice(); return;
      }
      if (target.hasAttribute("data-receptionist-answer")) {
        const key = target.getAttribute("data-answer-key"); const value = target.getAttribute("data-receptionist-answer");
        guideContext[key] = key === "groupSize" && receptionistState.activeCategory !== "Hotel Room" ? Number(value) : value;
        saveGuideContext();
        const category = receptionistState.activeCategory;
        if (category === "Event Hall" && key === "occasion") {
          if (numericFact(guideContext.groupSize) === null) renderQuestion(category, "groupSize"); else renderRecommendations();
        }
        else if (category === "Event Hall" && key === "groupSize") {
          if (!guideContext.startDate) renderQuestion(category, "eventDate"); else renderRecommendations();
        }
        else if (category === "Hotel Room" && key === "groupSize") {
          if (guideContext.preference === null) renderQuestion(category, "preference"); else renderQuestion(category, "checkInDate");
        }
        else if (category === "Hotel Room" && key === "preference") {
          if (!guideContext.startDate || !guideContext.endDate) renderQuestion(category, "checkInDate"); else requestHotelRecommendations();
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
          activateVenue(room.id);
          renderVenue(room); focusFirstChoice();
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
    pauseAutoRotation();
    window.scrollTo({ top: 0, behavior: "instant" });
    wrapper.classList.add("mode-photos");
    document.body.classList.add("no-scroll");
  });
  const exitPhotoMode = () => {
    wrapper.classList.remove("mode-photos");
    document.body.classList.remove("no-scroll");
    scheduleAutoRotation();
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
