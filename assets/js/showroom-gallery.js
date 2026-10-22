(() => {
  const wrapper = document.getElementById("showroom-wrapper");
  const body = document.body;
  const viewPhotos = document.getElementById("btn-view-photos");
  const bootPhotos = document.getElementById("pano-loading-photos");
  const bootRetry = document.getElementById("pano-loading-retry");
  const slideImage = document.getElementById("current-slide-img");
  const title = document.getElementById("gallery-title");
  const counter = document.getElementById("gallery-counter");
  const thumbnails = document.getElementById("thumbnail-strip");
  const previous = document.getElementById("slide-prev");
  const next = document.getElementById("slide-next");
  const backButtons = [document.getElementById("btn-back-to-360"), document.getElementById("btn-back-to-360-gallery")].filter(Boolean);
  const data = window.showroomData || {};

  // Keep boot recovery available even if photo controls cannot initialize.
  if (bootRetry) bootRetry.onclick = () => window.location.reload();
  window.showroomShowBootError = dependency => {
    const overlay = document.getElementById("pano-loading-overlay");
    const status = document.getElementById("pano-loading-message");
    if (overlay) {
      overlay.style.display = "flex";
      overlay.classList.add("is-error");
    }
    if (status) status.textContent = `${dependency} did not load. Reload the showroom or browse the venue photos.`;
  };

  if (!wrapper || !slideImage || !viewPhotos) return;

  let roomId = null;
  let images = [];
  let index = 0;
  let imageRequest = 0;
  let zoom = 1;
  let panX = 0;
  let panY = 0;
  let dragging = false;
  let dragX = 0;
  let dragY = 0;
  let touchStartX = 0;

  const safeSource = path => {
    try {
      const url = new URL(String(path || ""), document.baseURI);
      return ["http:", "https:"].includes(url.protocol) ? url.href : "";
    } catch (_) {
      return "";
    }
  };

  const requestedRoomId = () => {
    const rooms = [...document.querySelectorAll(".dropdown-item[data-room]")];
    const requestedCategory = new URLSearchParams(window.location.search).get("cat");
    if (requestedCategory) {
      const category = [...document.querySelectorAll(".master-pill[data-category]")]
        .find(item => item.getAttribute("data-category") === requestedCategory);
      const firstInCategory = category?.closest(".pill-dropdown-wrapper")?.querySelector(".dropdown-item[data-room]");
      if (firstInCategory) return firstInCategory.getAttribute("data-room");
    }
    let requestedHash = window.location.hash.replace(/^#/, "");
    try { requestedHash = decodeURIComponent(requestedHash); } catch (_) { /* Ignore malformed hashes. */ }
    if (requestedHash && rooms.some(item => item.getAttribute("data-room") === requestedHash)) return requestedHash;
    return document.querySelector(".dropdown-item.active[data-room]")?.getAttribute("data-room")
      || rooms[0]?.getAttribute("data-room")
      || Object.keys(data)[0]
      || null;
  };

  const roomTitle = () => {
    const room = data[roomId];
    return String(room?.title || room?.venue_name || "Venue");
  };

  const setImage = (photoIndex, direction = "next") => {
    if (!images.length) return;
    index = ((photoIndex % images.length) + images.length) % images.length;
    zoom = 1;
    panX = 0;
    panY = 0;
    slideImage.style.transform = "translate(0px, 0px) scale(1)";
    slideImage.style.cursor = "default";
    slideImage.classList.remove("slide-in-left", "slide-in-right");
    void slideImage.offsetWidth;
    slideImage.classList.add(direction === "prev" ? "slide-in-left" : "slide-in-right");

    const request = ++imageRequest;
    const candidates = [images[index], ...images.filter((_, candidateIndex) => candidateIndex !== index), "assets/img/placeholder.jpg"]
      .map(safeSource).filter((source, sourceIndex, all) => source && all.indexOf(source) === sourceIndex);
    let candidateIndex = 0;
    const loadCandidate = () => {
      if (request !== imageRequest) return;
      const source = candidates[candidateIndex++];
      if (!source) return;
      slideImage.onload = () => {
        if (request === imageRequest) slideImage.classList.remove("fade-out");
      };
      slideImage.onerror = loadCandidate;
      slideImage.classList.add("fade-out");
      slideImage.src = source;
      slideImage.alt = `${roomTitle()}, photo ${index + 1} of ${images.length}`;
    };
    loadCandidate();

    if (counter) counter.textContent = `${index + 1} / ${images.length}`;
    thumbnails?.querySelectorAll(".photo-thumb").forEach((button, thumbnailIndex) => {
      const active = thumbnailIndex === index;
      button.classList.toggle("active", active);
      button.querySelector(".thumb-img")?.classList.toggle("active", active);
      button.setAttribute("aria-current", active ? "true" : "false");
    });
    window.dispatchEvent(new CustomEvent("SevillaShowroomPhotoChanged", {
      detail: { roomId, index, source: images[index] }
    }));
  };

  const renderThumbnails = () => {
    if (!thumbnails) return;
    thumbnails.replaceChildren();
    images.forEach((path, thumbnailIndex) => {
      const source = safeSource(path);
      if (!source) return;
      const button = document.createElement("button");
      button.type = "button";
      button.className = "photo-thumb";
      button.setAttribute("aria-label", `Show photo ${thumbnailIndex + 1} of ${images.length}`);
      const image = document.createElement("img");
      image.className = "thumb-img";
      image.alt = "";
      image.loading = "lazy";
      image.src = source;
      image.onerror = () => { button.hidden = true; };
      button.appendChild(image);
      button.addEventListener("click", () => setImage(thumbnailIndex));
      thumbnails.appendChild(button);
    });
  };

  const setGallery = (nextRoomId, nextImages, nextTitle) => {
    const sameRoom = roomId === nextRoomId;
    const normalizedImages = Array.isArray(nextImages)
      ? nextImages.filter(path => typeof path === "string" && path.trim()).map(safeSource).filter(Boolean)
      : [];
    const sameImages = sameRoom && images.length === normalizedImages.length
      && images.every((path, imageIndex) => path === normalizedImages[imageIndex]);
    roomId = nextRoomId;
    images = normalizedImages;
    index = sameImages ? Math.min(index, Math.max(0, images.length - 1)) : 0;
    if (title) title.textContent = `${String(nextTitle || roomTitle())} Gallery`;
    viewPhotos.disabled = images.length === 0;
    viewPhotos.textContent = images.length ? "VIEW PHOTOS" : "NO PHOTOS";
    if (bootPhotos) bootPhotos.hidden = images.length === 0;
    renderThumbnails();
    if (images.length) setImage(index);
    else {
      ++imageRequest;
      slideImage.src = "assets/img/placeholder.jpg";
      slideImage.alt = "No venue photos available";
      if (counter) counter.textContent = "";
      if (thumbnails) thumbnails.replaceChildren();
      if (wrapper.classList.contains("mode-photos")) closeGallery();
    }
  };

  const openGallery = () => {
    if (!images.length) return;
    wrapper.classList.add("mode-photos");
    body.classList.add("no-scroll");
    window.scrollTo({ top: 0, behavior: "instant" });
    setImage(index);
    document.getElementById("btn-back-to-360")?.focus({ preventScroll: true });
    window.dispatchEvent(new CustomEvent("SevillaShowroomPhotoModeOpened"));
  };

  function closeGallery() {
    if (!wrapper.classList.contains("mode-photos")) return;
    wrapper.classList.remove("mode-photos");
    body.classList.remove("no-scroll");
    viewPhotos.focus({ preventScroll: true });
    window.dispatchEvent(new CustomEvent("SevillaShowroomPhotoModeClosed"));
  }

  viewPhotos.addEventListener("click", openGallery);
  bootPhotos?.addEventListener("click", openGallery);
  previous?.addEventListener("click", () => setImage(index - 1, "prev"));
  next?.addEventListener("click", () => setImage(index + 1, "next"));
  backButtons.forEach(button => button.addEventListener("click", closeGallery));

  slideImage.addEventListener("wheel", event => {
    if (!wrapper.classList.contains("mode-photos")) return;
    event.preventDefault();
    const oldZoom = zoom;
    zoom = Math.min(Math.max(zoom + (event.deltaY < 0 ? 0.2 : -0.2), 1), 5);
    if (zoom === 1) {
      panX = 0;
      panY = 0;
      slideImage.style.cursor = "default";
    } else {
      const mouseX = event.clientX - window.innerWidth / 2;
      const mouseY = event.clientY - window.innerHeight / 2;
      const ratio = zoom / oldZoom;
      panX = mouseX - (mouseX - panX) * ratio;
      panY = mouseY - (mouseY - panY) * ratio;
      slideImage.style.cursor = "grab";
    }
    slideImage.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
  }, { passive: false });

  slideImage.addEventListener("mousedown", event => {
    if (zoom <= 1) return;
    event.preventDefault();
    dragging = true;
    slideImage.style.cursor = "grabbing";
    dragX = event.clientX - panX;
    dragY = event.clientY - panY;
  });
  window.addEventListener("mousemove", event => {
    if (!dragging) return;
    panX = event.clientX - dragX;
    panY = event.clientY - dragY;
    slideImage.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
  });
  window.addEventListener("mouseup", () => {
    if (!dragging) return;
    dragging = false;
    slideImage.style.cursor = zoom > 1 ? "grab" : "default";
  });
  slideImage.addEventListener("touchstart", event => {
    if (wrapper.classList.contains("mode-photos")) touchStartX = event.changedTouches[0].screenX;
  }, { passive: true });
  slideImage.addEventListener("touchmove", event => {
    if (wrapper.classList.contains("mode-photos") && Math.abs(touchStartX - event.changedTouches[0].screenX) > 10) event.preventDefault();
  }, { passive: false });
  slideImage.addEventListener("touchend", event => {
    if (!wrapper.classList.contains("mode-photos")) return;
    const delta = event.changedTouches[0].screenX - touchStartX;
    if (delta < -50) setImage(index + 1);
    else if (delta > 50) setImage(index - 1, "prev");
  }, { passive: true });

  const initialRoom = requestedRoomId();
  setGallery(initialRoom, data[initialRoom]?.gallery, data[initialRoom]?.title || data[initialRoom]?.venue_name);
  window.SevillaShowroomPhotoGallery = { setGallery, open: openGallery, close: closeGallery };
})();
