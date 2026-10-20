/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    function cmsLightboxSource(image) {
        if (!image || image.dataset?.sourceAvailable === 'false') return null;
        return image.dataset?.originalSrc || image.src || null;
    }

    const cmsPreviewContexts = {
        grid: '(max-width: 767px) calc(100vw - 2rem), (max-width: 1100px) calc(50vw - 2rem), 400px',
        hotel: '(max-width: 700px) calc(84vw - 2rem), (max-width: 1000px) calc(50vw - 4rem), 430px',
        manager: '(max-width: 700px) calc(100vw - 3rem), (max-width: 1000px) 50vw, 600px'
    };
    const cmsPreviewPromises = new Map();
    const cmsPreviewQueue = [];
    let activeCmsPreviewRequests = 0;
    function queueCmsPreviewRequest(run, prioritize = false) {
        let job;
        const promise = new Promise((resolve, reject) => {
            job = { run, resolve, reject, started: false, priority: prioritize ? 1 : 0 };
            enqueueCmsPreviewJob(job);
            drainCmsPreviewQueue();
        });
        return {
            promise,
            promote: () => {
                if (!job || job.started || job.priority > 0) return;
                const index = cmsPreviewQueue.indexOf(job);
                if (index < 0) return;
                job.priority = 1;
                cmsPreviewQueue.splice(index, 1);
                enqueueCmsPreviewJob(job);
            }
        };
    }
    function enqueueCmsPreviewJob(job) {
        const index = cmsPreviewQueue.findIndex(queued => queued.priority < job.priority);
        if (index < 0) cmsPreviewQueue.push(job);
        else cmsPreviewQueue.splice(index, 0, job);
    }
    function drainCmsPreviewQueue() {
        while (activeCmsPreviewRequests < 2 && cmsPreviewQueue.length) {
            const job = cmsPreviewQueue.shift();
            job.started = true;
            activeCmsPreviewRequests++;
            Promise.resolve().then(job.run).then(job.resolve, job.reject).finally(() => {
                activeCmsPreviewRequests--;
                drainCmsPreviewQueue();
            });
        }
    }
    const cmsPreviewObserver = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver(entries => entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            cmsPreviewObserver.unobserve(entry.target);
            loadCmsResponsivePreview(entry.target);
        }), { rootMargin: '180px 0px' })
        : null;

    function safeCmsPreviewSourceSet(src, srcset) {
        if (!/^assets\/uploads\/\.cms-card\/[a-f0-9]{64}-(?:480|800|1320)\.webp\?v=\d+$/.test(String(src || ''))) return false;
        const candidates = String(srcset || '').split(',').map(candidate => candidate.trim());
        if (!candidates.length) return false;
        const urls = [];
        let previousWidth = 0;
        for (const candidate of candidates) {
            const match = candidate.match(/^(assets\/uploads\/\.cms-card\/[a-f0-9]{64}-(480|800|1320)\.webp\?v=\d+)\s+([1-9]\d{0,3})w$/);
            if (!match) return false;
            const encodedWidth = Number(match[2]);
            const actualWidth = Number(match[3]);
            if (actualWidth > encodedWidth || actualWidth > 1320 || actualWidth <= previousWidth) return false;
            urls.push(match[1]);
            previousWidth = actualWidth;
        }
        return urls.includes(String(src));
    }

    function cachedCmsPreview(mediaId) {
        for (const photos of Object.values(window.galleryData || {})) {
            if (!Array.isArray(photos)) continue;
            const photo = photos.find(item => Number(item?.id) === mediaId && item?.source_available !== false);
            if (photo && safeCmsPreviewSourceSet(photo.card_preview_src, photo.card_preview_srcset)) {
                return {
                    src: photo.card_preview_src,
                    srcset: photo.card_preview_srcset,
                    width: Number(photo.card_preview_width) || 480,
                    height: Number(photo.card_preview_height) || 270
                };
            }
        }
        return null;
    }

    function applyCmsPreview(mediaId, preview) {
        Object.values(window.galleryData || {}).forEach(photos => {
            if (!Array.isArray(photos)) return;
            photos.forEach(photo => {
                if (Number(photo?.id) !== mediaId) return;
                photo.card_preview_src = preview.src;
                photo.card_preview_srcset = preview.srcset;
                photo.card_preview_sizes = cmsPreviewContexts.hotel;
                photo.card_preview_width = preview.width;
                photo.card_preview_height = preview.height;
            });
        });
        document.querySelectorAll(`img[data-cms-preview-id="${mediaId}"]`).forEach(image => {
            if (image.dataset.sourceAvailable === 'false') return;
            const context = cmsPreviewContexts[image.dataset.cmsPreviewContext] ? image.dataset.cmsPreviewContext : 'grid';
            image.src = preview.src;
            image.srcset = preview.srcset;
            image.sizes = cmsPreviewContexts[context];
            if (Number(preview.width) > 0) image.width = Number(preview.width);
            if (Number(preview.height) > 0) image.height = Number(preview.height);
            image.dataset.cmsPreviewReady = 'true';
        });
    }

    function loadCmsResponsivePreview(image) {
        const mediaId = Number(image?.dataset.cmsPreviewId);
        if (!Number.isSafeInteger(mediaId) || mediaId < 1 || image.dataset.cmsPreviewReady === 'true'
            || image.dataset.sourceAvailable === 'false') return;
        const cached = cachedCmsPreview(mediaId);
        if (cached) {
            applyCmsPreview(mediaId, cached);
            return;
        }
        if (!csrfToken) return;
        const context = cmsPreviewContexts[image.dataset.cmsPreviewContext] ? image.dataset.cmsPreviewContext : 'grid';
        const prioritize = context === 'hotel' || context === 'manager';
        let requestEntry = cmsPreviewPromises.get(mediaId);
        if (!requestEntry) {
            const queued = queueCmsPreviewRequest(async () => {
                const response = await fetch('actions/admin/media_preview.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ id: mediaId, context })
                });
                const data = await response.json();
                if (!response.ok || data?.success !== true || Number(data.id) !== mediaId
                    || !safeCmsPreviewSourceSet(data.src, data.srcset)
                    || !Number.isSafeInteger(Number(data.width)) || !Number.isSafeInteger(Number(data.height))) {
                    throw new Error('Bounded CMS preview is unavailable.');
                }
                return { src: data.src, srcset: data.srcset, width: Number(data.width), height: Number(data.height) };
            }, prioritize);
            const request = queued.promise.finally(() => {
                if (cmsPreviewPromises.get(mediaId)?.promise === request) cmsPreviewPromises.delete(mediaId);
            });
            requestEntry = { promise: request, promote: queued.promote };
            cmsPreviewPromises.set(mediaId, requestEntry);
        } else if (prioritize) {
            requestEntry.promote();
        }
        image.dataset.cmsPreviewReady = 'pending';
        requestEntry.promise.then(preview => applyCmsPreview(mediaId, preview)).catch(() => {
            image.dataset.cmsPreviewReady = 'failed';
        });
    }

    function observeCmsPreviewImage(image, context = 'grid') {
        if (!(image instanceof HTMLImageElement) || !/^\d+$/.test(String(image.dataset.cmsPreviewId || ''))) return;
        image.dataset.cmsPreviewContext = cmsPreviewContexts[context] ? context : 'grid';
        if (image.dataset.sourceAvailable === 'false' || image.dataset.cmsPreviewReady === 'true') return;
        if (image.srcset && safeCmsPreviewSourceSet(image.getAttribute('src'), image.srcset)) {
            image.sizes = cmsPreviewContexts[image.dataset.cmsPreviewContext];
            image.dataset.cmsPreviewReady = 'true';
            return;
        }
        if (cmsPreviewObserver) cmsPreviewObserver.observe(image);
        else loadCmsResponsivePreview(image);
    }

  const cmsCategoryFilters = new Set(['venues', 'hotel-room', 'event-hall', 'villa', 'website']);
  const cmsMediaFilters = new Set(['all', '360', 'standard']);
  const cmsFilterStorage = (() => {
    try {
      return window.sessionStorage;
    } catch (error) {
      return null;
    }
  })();

  function readCMSFilter(key, validValues, fallback) {
    try {
      const storedValue = cmsFilterStorage?.getItem(key);
      return validValues.has(storedValue) ? storedValue : fallback;
    } catch (error) {
      return fallback;
    }
  }

  let activeCMSCategoryFilter = readCMSFilter('activeCMSCategoryFilter', cmsCategoryFilters, 'venues');
  let activeCMSMediaFilter = readCMSFilter('activeCMSFilter', cmsMediaFilters, 'all');

  function persistCMSFilterState() {
    try {
      cmsFilterStorage?.setItem('activeCMSCategoryFilter', activeCMSCategoryFilter);
      cmsFilterStorage?.setItem('activeCMSFilter', activeCMSMediaFilter);
    } catch (error) {
      // Filtering remains available when session storage is disabled or full.
    }
  }

  // =========================================================
  // 1. MODAL BRIDGES to Global Modals
  // =========================================================
  // =========================================================
  // 2. DOM Elements
  // =========================================================
  const uploadModal = document.getElementById('uploadModal');
  const btnOpenUpload = document.getElementById('btnOpenUpload');
  const btnCloseModal = document.getElementById('btnCloseModal');
  const replaceBtns = document.querySelectorAll('.btn-cms-modal');
  
  const typeDropdown = document.getElementById('modal-media-type');
  const slotDropdown = document.getElementById('modal-website-slot');
  const slotOptions = slotDropdown ? slotDropdown.querySelectorAll('option[data-type]') : [];
  
  const dragDropArea = document.getElementById('dragDropArea');
  const fileInputEl = document.getElementById('fileInput');
  const uploadForm = document.getElementById("cms-upload-form");

  let uploadReturnFocus = null;
  let uploadPreviousOverflow = '';
  const openUploadModal = () => {
    if (!uploadModal.classList.contains('active')) {
      uploadReturnFocus = document.activeElement;
      uploadPreviousOverflow = document.body.style.overflow;
    }
    uploadModal.classList.add('active');
    document.body.style.overflow = 'hidden';
    window.requestAnimationFrame(() => {
      if (uploadModal.classList.contains('active')) typeDropdown?.focus();
    });
  };
  const closeUploadModal = () => {
    uploadModal.classList.remove('active');
    document.body.style.overflow = uploadPreviousOverflow;
    if (uploadReturnFocus?.isConnected) uploadReturnFocus.focus({ preventScroll: true });
  };
  uploadModal?.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      closeUploadModal();
    } else if (event.key === 'Tab') {
      const controls = [...uploadModal.querySelectorAll('button:not(:disabled), select:not(:disabled), [tabindex="0"]')]
        .filter(control => control.getClientRects().length > 0);
      if (event.shiftKey && document.activeElement === controls[0]) {
        event.preventDefault(); controls.at(-1)?.focus();
      } else if (!event.shiftKey && document.activeElement === controls.at(-1)) {
        event.preventDefault(); controls[0]?.focus();
      }
    }
  });
  dragDropArea?.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault(); fileInputEl?.click();
    }
  });

  // Progress Bar Elements
  const progContainer = document.getElementById('upload-progress-container');
  const progBar = document.getElementById('upload-progress-bar');
  const progText = document.getElementById('upload-progress-text');
  const progLabel = document.getElementById('upload-progress-label');


  // =========================================================
  // 3. Modal Open/Close Logic
  // =========================================================
  if (btnOpenUpload) {
    btnOpenUpload.addEventListener('click', () => {
      uploadForm.reset();
      slotOptions.forEach(opt => opt.style.display = 'none');
      const dropText = document.querySelector('.drop-text');
      if (dropText) dropText.innerHTML = `<strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to browse</span>`;
      if (progContainer) progContainer.style.display = 'none';
      openUploadModal();
    });
  }

  replaceBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      const mediaType = this.getAttribute('data-type');
      const targetSlot = this.getAttribute('data-slot');

      if (typeDropdown && slotDropdown) {
          typeDropdown.value = mediaType;
          slotOptions.forEach(opt => {
              if (opt.getAttribute('data-type') === mediaType || opt.value === 'gallery') opt.style.display = 'block'; 
              else opt.style.display = 'none';  
          });
          slotDropdown.value = targetSlot;
      }
      if (progContainer) progContainer.style.display = 'none';
      openUploadModal();
    });
  });

  if (btnCloseModal) btnCloseModal.addEventListener('click', () => closeUploadModal());
  window.addEventListener('click', (e) => { if (e.target === uploadModal) closeUploadModal(); });

  // Cascading Dropdown
  if (typeDropdown && slotDropdown) {
      typeDropdown.addEventListener('change', function(e) {
          const selectedType = this.value; 
          if (e.isTrusted) slotDropdown.value = ""; 
          slotOptions.forEach(opt => {
              if (opt.getAttribute('data-type') === selectedType || opt.value === 'gallery') opt.style.display = 'block'; 
              else opt.style.display = 'none';  
          });
      });
  }


  // =========================================================
  // 4. Drag & Drop Visuals
  // =========================================================
  if (dragDropArea && fileInputEl) {
    dragDropArea.addEventListener('click', () => fileInputEl.click());
    dragDropArea.addEventListener('dragover', (e) => { e.preventDefault(); dragDropArea.classList.add('dragover'); });
    dragDropArea.addEventListener('dragleave', (e) => { e.preventDefault(); dragDropArea.classList.remove('dragover'); });
    dragDropArea.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDropArea.classList.remove('dragover');
      if (e.dataTransfer.files.length > 0) {
        fileInputEl.files = e.dataTransfer.files;
        updateDropText(e.dataTransfer.files);
      }
    });
    fileInputEl.addEventListener('change', function() { if (this.files.length > 0) updateDropText(this.files); });

    function updateDropText(files) {
      const dropText = dragDropArea.querySelector('.drop-text');
      if (files.length === 1) dropText.innerHTML = `<strong>Selected File:</strong><br><span class="highlight">${files[0].name}</span>`;
      else dropText.innerHTML = `<strong>Selected Files:</strong><br><span class="highlight">${files.length} files selected</span>`;
    }
  }


  // =========================================================
  // 5. Handle File Upload Submission (WITH PROGRESS BAR)
  // =========================================================
  if (uploadForm) {
      uploadForm.addEventListener("submit", function(e) {
          e.preventDefault();

          if (!fileInputEl.files || fileInputEl.files.length === 0) {
              showAlert("Error", "Please select a file to upload.", "error", false);
              return;
          }

          const slotDropdown = document.getElementById('modal-website-slot');
          const isStrictSlot = slotDropdown.value.startsWith('home-');

          if (isStrictSlot && fileInputEl.files.length > 1) {
              showAlert("Error", "You can only upload ONE image at a time for homepage image slots.", "error", false);
              return;
          }

          const formData = new FormData(this);
          formData.delete("fileInput"); 
          for (let i = 0; i < fileInputEl.files.length; i++) {
              formData.append("fileInput[]", fileInputEl.files[i]);
          }

          const submitBtn = this.querySelector('button[type="submit"]');
          if (!submitBtn) {
              showAlert("Error", "The upload form is unavailable. Please refresh and try again.", "error", false);
              return;
          }
          const originalText = submitBtn.innerText;
          const resetUploadUi = () => {
              submitBtn.innerText = originalText;
              submitBtn.disabled = false;
              if (progContainer) progContainer.style.display = 'none';
              if (progLabel) progLabel.innerText = 'Uploading images…';
              if (progBar) {
                  progBar.style.transform = 'scaleX(0)';
                  progBar.setAttribute('aria-valuenow', '0');
                  progBar.setAttribute('aria-valuetext', 'Waiting to upload.');
              }
              if (progText) progText.innerText = '0% sent';
          };
          const showUploadError = (title, message) => {
              resetUploadUi();
              showAlert(title, message, "error", false);
          };
          const uploadOutcomeUnknown = 'Wait a moment, then check the gallery before retrying; the images may already be saved.';
          let uploadBodySent = false;

          const uploadDescription = fileInputEl.files.length === 1
              ? '1 image'
              : `${fileInputEl.files.length} images`;
          submitBtn.innerText = "Uploading…";
          submitBtn.disabled = true;

          // Show Progress Bar
          if (progContainer) {
              progContainer.style.display = 'block';
              if (progLabel) progLabel.innerText = `Uploading ${uploadDescription}…`;
              if (progBar) {
                  progBar.style.transform = 'scaleX(0)';
                  progBar.setAttribute('aria-valuenow', '0');
                  progBar.setAttribute('aria-valuetext', 'File transfer in progress.');
              }
              if (progText) progText.innerText = '0% sent';
          }

          // Use XMLHttpRequest for actual progress tracking!
          const xhr = new XMLHttpRequest();
          try {
              xhr.open("POST", "actions/admin/upload_media.php", true);
              xhr.setRequestHeader("X-CSRF-Token", csrfToken);

              // Track Upload Progress
              xhr.upload.addEventListener("progress", (event) => {
                  if (event.lengthComputable) {
                      const percentComplete = event.total > 0
                          ? Math.round((event.loaded / event.total) * 100)
                          : 0;
                      if (percentComplete >= 100) uploadBodySent = true;
                      const progressRatio = Math.min(1, Math.max(0, percentComplete / 100));
                      if (progBar) {
                          progBar.style.transform = `scaleX(${progressRatio})`;
                          progBar.setAttribute('aria-valuenow', String(percentComplete));
                          progBar.setAttribute('aria-valuetext', `${percentComplete}% of image data sent.`);
                      }
                      if (progText) progText.innerText = percentComplete + '% sent';
                  }
              });

              // The upload body's transfer can finish well before the server has
              // generated CMS thumbnails and public derivatives. Keep that work
              // distinct from network progress so 100% does not look stalled.
              xhr.upload.addEventListener("load", () => {
                  uploadBodySent = true;
                  if (progLabel) progLabel.innerText = `Upload received. Processing ${uploadDescription} on the server…`;
                  submitBtn.innerText = 'Processing images…';
                  if (progBar) {
                      progBar.style.transform = 'scaleX(1)';
                      progBar.setAttribute('aria-valuenow', '100');
                      progBar.setAttribute('aria-valuetext', 'Upload sent; server is processing images.');
                  }
                  if (progText) progText.innerText = 'Sent';
              });

              // Handle Completion
              xhr.onload = function() {
                  let data = null;
                  try {
                      data = JSON.parse(xhr.responseText);
                  } catch (e) {
                      // Fall back to the HTTP status below when the server did not
                      // return a JSON response.
                  }

                  const serverMessage = data && typeof data.message === 'string' ? data.message.trim() : '';
                  if (xhr.status === 504 || xhr.status >= 500) {
                      showUploadError("Upload result unconfirmed", uploadOutcomeUnknown);
                      return;
                  }
                  if (xhr.status >= 200 && xhr.status < 300 && data && data.success === true) {
                      resetUploadUi();
                      closeUploadModal();
                      persistCMSFilterState();
                      showAlert("Success", serverMessage || "Media uploaded successfully.", "success", true);
                  } else {
                      showUploadError("Error", serverMessage || "Server Error: " + xhr.status);
                  }
              };

              xhr.onerror = function() {
                  showUploadError(uploadBodySent ? "Upload result unconfirmed" : "Network Error",
                      uploadBodySent ? uploadOutcomeUnknown : "A network error occurred during upload.");
              };

              xhr.onabort = function() {
                  showUploadError(uploadBodySent ? "Upload result unconfirmed" : "Upload Cancelled",
                      uploadBodySent ? uploadOutcomeUnknown : "The upload was cancelled before it finished.");
              };

              xhr.ontimeout = function() {
                  showUploadError(uploadBodySent ? "Upload result unconfirmed" : "Network Error",
                      uploadBodySent ? uploadOutcomeUnknown : "The upload timed out before all files were sent. Please try again.");
              };

              xhr.send(formData);
          } catch (error) {
              showUploadError("Network Error", "The upload could not be started. Please try again.");
          }
      });
  }


  // =========================================================
  // 6. Manage Gallery, Lightbox, Primary & Bulk Delete Logic
  // =========================================================
  const manageGalleryModal = document.getElementById('manageGalleryModal');
  const mgGrid = document.getElementById('mg-grid');
  const mgEmptyState = document.getElementById('mg-empty-state');
  const btnMgAdd = document.getElementById('btn-mg-add');
  let currentManageSlot = null;
  let currentManageType = null;
  let currentManageHotelGallery = null;

  const lightbox = document.getElementById('cms-lightbox');
  const lightboxImg = document.getElementById('cms-lightbox-img');

  // Bulk Elements
  const bulkControls = document.getElementById('mg-bulk-controls');
  const selectAllCheck = document.getElementById('mg-select-all');
  const btnBulkDelete = document.getElementById('btn-mg-bulk-delete');
  const mgSelCount = document.getElementById('mg-sel-count');

  function updateBulkDeleteState() {
      const checks = mgGrid.querySelectorAll('.mg-bulk-check');
      let checkedCount = 0;
      checks.forEach(c => { if(c.checked) checkedCount++; });
      
      if (mgSelCount) mgSelCount.innerText = checkedCount;
      
      if (checkedCount > 0) {
          btnBulkDelete.style.opacity = '1';
          btnBulkDelete.disabled = false;
      } else {
          btnBulkDelete.style.opacity = '0.5';
          btnBulkDelete.disabled = true;
      }

      if (selectAllCheck && checks.length > 0) {
          const selectableChecks = Array.from(checks).filter(check => !check.disabled);
          selectAllCheck.checked = selectableChecks.length > 0 && checkedCount === selectableChecks.length;
          selectAllCheck.indeterminate = checkedCount > 0 && checkedCount < selectableChecks.length;
      }
  }

  async function removeMissingMediaRecord(mediaId, button) {
      const numericId = Number(mediaId);
      if (!Number.isSafeInteger(numericId) || numericId < 1 || button.disabled) return;
      button.disabled = true;
      try {
          const previewResponse = await fetch('actions/admin/delete_media.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
              body: JSON.stringify({ action: 'preview_missing', id: numericId })
          });
          const preview = await previewResponse.json();
          if (!previewResponse.ok || preview?.success !== true || preview?.can_remove !== true) {
              throw new Error(typeof preview?.message === 'string' ? preview.message : 'The missing record could not be checked.');
          }
          const own = Number(preview.plan?.source_hotspots) || 0;
          const incoming = Number(preview.plan?.incoming_hotspots) || 0;
          const dependents = own + incoming;
          const dependencyCopy = dependents === 0
              ? 'No saved tour hotspots refer to this panorama.'
              : `${own} hotspot${own === 1 ? '' : 's'} on this panorama and ${incoming} incoming navigation link${incoming === 1 ? '' : 's'} will also be removed.`;
          const confirmed = await showConfirm(
              'Remove missing media record?',
              `The original file is missing. This removes the saved media record. ${dependencyCopy} No files or cached images will be deleted.`
          );
          if (!confirmed) return;

          const deleteResponse = await fetch('actions/admin/delete_media.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
              body: JSON.stringify({ action: 'remove_missing', id: numericId, expected: preview.plan })
          });
          const result = await deleteResponse.json();
          if (!deleteResponse.ok || result?.success !== true) {
              throw new Error(typeof result?.message === 'string' ? result.message : 'The missing record could not be removed.');
          }
          button.closest('.mg-photo-card')?.remove();
          window.needsCmsRefresh = true;
          updateBulkDeleteState();
          if (mgGrid.children.length === 0) document.getElementById('btnCloseGalleryModal')?.click();
      } catch (error) {
          showAlert('Missing media was not removed', error instanceof Error ? error.message : 'Please retry after refreshing the gallery.', 'error', false);
      } finally {
          if (button.isConnected) button.disabled = false;
      }
  }

  // Open Manage Gallery
  document.querySelectorAll('.btn-manage-gallery').forEach(btn => {
      btn.addEventListener('click', function() {
          currentManageSlot = this.getAttribute('data-slot');
          const galleryCard = this.closest('.cms-card');
          currentManageType = this.dataset.mediaType || galleryCard?.getAttribute('data-type') || null;
          const buildingName = galleryCard?.dataset.hotelBuilding || '';
          const roomTypeCode = galleryCard?.dataset.hotelRoomTypeCode || '';
          const roomType = galleryCard?.dataset.hotelRoomType || '';
          const canonicalSlot = galleryCard?.dataset.hotelCanonicalSlot || '';
          const hotelEditorType = canonicalSlot
              ? (window.hotelGalleryEditorData || []).find(type => type.canonical_slot === canonicalSlot)
              : null;
          const optionSlotMatch = /^hotel_option_([1-9][0-9]*)(_360)?$/.exec(String(currentManageSlot || ''));
          const hotelOption = optionSlotMatch && hotelEditorType
              ? (hotelEditorType.options || []).find(option => Number(option.room_group_id) === Number(optionSlotMatch[1]))
              : null;
          const optionMediaKind = optionSlotMatch?.[2] === '_360' ? '360' : 'standard';
          currentManageHotelGallery = this.dataset.hotelShared === 'true'
              && currentManageType === 'standard'
              && /^hotel_type_[a-f0-9]{40}$/.test(String(currentManageSlot || ''))
              && buildingName && roomType
              ? { canonicalSlot: currentManageSlot, buildingName, roomTypeCode, roomType }
              : null;
          const photos = window.galleryData?.[currentManageSlot] || [];
          
          document.getElementById('mg-title').innerText = hotelOption
              ? (optionMediaKind === '360' ? 'Manage room option tour' : 'Manage room option photos')
              : 'Manage Photos';
          const managementHint = document.getElementById('mg-hint');
          const isSharedHotelStandardGallery = currentManageHotelGallery !== null;
          if (managementHint) {
              managementHint.hidden = !isSharedHotelStandardGallery && !hotelOption;
              managementHint.textContent = isSharedHotelStandardGallery
                  ? 'Choose the image that should appear as the cover for this shared room gallery.'
                  : hotelOption
                      ? `${hotelEditorType.building_name} · ${hotelEditorType.room_type} · ${hotelOption.label}`
                      : '';
          }
          mgGrid.innerHTML = '';
          if (mgEmptyState) {
              const isEmptyOptionGallery = Boolean(hotelOption) && photos.length === 0;
              mgEmptyState.hidden = !isEmptyOptionGallery;
              mgEmptyState.textContent = isEmptyOptionGallery
                  ? (optionMediaKind === '360'
                      ? 'No tour scenes yet. Add panoramas for this room option.'
                      : 'No photos yet. Add photos for this room option.')
                  : '';
          }
          
          photos.forEach((photo) => {
              const isPrimary = Number(photo.is_primary) === 1;
              const sourceAvailable = photo.source_available !== false;
              const starColor = isPrimary ? "var(--color-gold)" : "#ccc";
              const primaryLabel = isPrimary ? "Primary image" : "Set as primary image";
              const thumbnailPath = /^assets\/uploads\/\.cms-thumbnails\/[a-f0-9]{64}\.webp$/.test(String(photo.thumbnail_path || ''))
                  ? photo.thumbnail_path
                  : 'assets/img/placeholder.jpg';
              const thumbnailVersion = Number(photo.thumbnail_version);
              const thumbnailUrl = `${thumbnailPath}${thumbnailVersion > 0 ? `?v=${thumbnailVersion}` : ''}`;
              const hasCardPreview = safeCmsPreviewSourceSet(photo.card_preview_src, photo.card_preview_srcset);
              const previewSource = hasCardPreview ? photo.card_preview_src : thumbnailUrl;
              const previewSrcset = hasCardPreview ? photo.card_preview_srcset : '';
              const originalPath = sourceAvailable && /^assets\/uploads\/[A-Za-z0-9_.-]+$/.test(String(photo.file_path || ''))
                  ? photo.file_path
                  : '';
              const fileName = escapeHTML(photo.file_name);
              const sourceWarning = sourceAvailable ? '' : `<p class="mg-source-warning" role="status">${photo.source_status === 'missing' ? 'Original file is missing from this server.' : 'Original file is unavailable or its path is unsafe.'}</p>`;
              const deleteControl = photo.source_status === 'missing'
                  ? `<button type="button" class="btn-remove-missing-media" data-id="${photo.id}" aria-label="Remove missing media record for ${fileName}" title="Remove missing record"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>`
                  : sourceAvailable
                      ? `<button type="button" class="btn-delete-media" data-id="${photo.id}" aria-label="Delete ${fileName}" title="Delete"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>`
                      : '<button type="button" class="btn-delete-media" disabled aria-label="File path cannot be safely removed" title="Restore or inspect this file path first"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>';
              
              mgGrid.innerHTML += `
                  <div class="mg-photo-card" data-id="${photo.id}" style="position: relative; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                      
                      <!-- Bulk Checkbox -->
                      <div style="position: absolute; top: 8px; left: 8px; z-index: 10; background: white; border-radius: 4px; padding: 3px 6px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">
                          <input type="checkbox" class="mg-bulk-check" value="${photo.id}" ${sourceAvailable ? '' : 'disabled'} aria-label="Select ${fileName} for deletion" style="cursor: ${sourceAvailable ? 'pointer' : 'not-allowed'}; transform: scale(1.2); margin: 0;">
                      </div>

                      <img src="${escapeHTML(previewSource)}" ${previewSrcset ? `srcset="${escapeHTML(previewSrcset)}" sizes="${escapeHTML(cmsPreviewContexts.manager)}"` : ''} data-cms-preview-id="${photo.id}" data-cms-preview-context="manager" data-original-src="${escapeHTML(originalPath)}" data-source-available="${sourceAvailable ? 'true' : 'false'}" alt="${sourceAvailable ? fileName : `Unavailable source: ${fileName}`}" width="${Number(photo.card_preview_width) || Number(photo.thumbnail_width) || 480}" height="${Number(photo.card_preview_height) || Number(photo.thumbnail_height) || 270}" loading="lazy" decoding="async" class="mg-thumb" style="width: 100%; height: 150px; object-fit: cover; display: block; cursor: ${sourceAvailable ? 'zoom-in' : 'default'};">
                      ${sourceWarning}
                      <div style="padding: 10px; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                          
                          ${currentManageType === '360'
                              ? '<span class="mg-primary-managed">Starting scene is set in Tour Setup</span>'
                              : `<button class="btn-primary-media" data-id="${photo.id}" data-slot="${escapeHTML(photo.slot_assignment || currentManageSlot)}" aria-label="${sourceAvailable ? primaryLabel : 'Original image file is missing'}" aria-pressed="${isPrimary ? 'true' : 'false'}" title="${sourceAvailable ? primaryLabel : 'Original image file is missing'}" ${sourceAvailable ? '' : 'disabled'} style="background: none; border: none; color: ${starColor}; cursor: ${sourceAvailable ? 'pointer' : 'not-allowed'}; padding: 5px; font-size: 1.2rem; transition: 0.3s;">
                                  <i class="fa-solid fa-star" aria-hidden="true"></i>
                              </button>`}
                          
                          <span style="font-size: 0.75rem; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80px;">${fileName}</span>
                          
                          ${deleteControl}
                      </div>
                  </div>
              `;
          });

          // Reset bulk states when modal opens
          if (selectAllCheck) selectAllCheck.checked = false;
          updateBulkDeleteState();
          if (bulkControls) bulkControls.style.display = photos.some(photo => photo.source_available !== false) ? 'flex' : 'none';
          mgGrid.querySelectorAll('.mg-thumb[data-cms-preview-id]').forEach(image => observeCmsPreviewImage(image, 'manager'));

          manageGalleryModal.classList.add('active');
      });
  });

  // Bulk Checkbox Listeners
  if (selectAllCheck) {
      selectAllCheck.addEventListener('change', function() {
          const checks = mgGrid.querySelectorAll('.mg-bulk-check');
          checks.forEach(c => c.checked = !c.disabled && selectAllCheck.checked);
          updateBulkDeleteState();
      });
  }

  // Grid Actions (Lightbox, Star, Single Delete, Checkboxes)
  if (mgGrid) {
      function updatePrimaryButtonState(button, isPrimary) {
          const label = isPrimary ? "Primary image" : "Set as primary image";
          button.style.color = isPrimary ? "var(--color-gold)" : "#ccc";
          button.setAttribute('aria-pressed', isPrimary ? 'true' : 'false');
          button.setAttribute('aria-label', label);
          button.setAttribute('title', label);
      }

      // Listen for checkbox changes dynamically
      mgGrid.addEventListener('change', function(e) {
          if (e.target.classList.contains('mg-bulk-check')) {
              updateBulkDeleteState();
          }
      });

      mgGrid.addEventListener('click', function(e) {
          
          // LIGHTBOX
          if (e.target.classList.contains('mg-thumb')) {
              const lightboxSource = cmsLightboxSource(e.target);
              if (!lightboxSource) return;
              lightboxImg.src = lightboxSource;
              lightbox.style.display = 'flex';
              return;
          }

          // SET PRIMARY (STAR)
          const primaryBtn = e.target.closest('.btn-primary-media');
          if (primaryBtn) {
              const mediaId = primaryBtn.getAttribute('data-id');
              const slot = primaryBtn.getAttribute('data-slot');
              const sharedGallery = currentManageHotelGallery ? { ...currentManageHotelGallery } : null;
              const sharedGallerySlot = sharedGallery?.canonicalSlot || '';

              if (primaryBtn.disabled || primaryBtn.dataset.pending === 'true') return;
              const mediaIdNumber = Number(mediaId);
              if (!Number.isSafeInteger(mediaIdNumber) || mediaIdNumber < 1 || !slot) {
                  showAlert("Error", "The selected image is invalid. Please refresh and try again.", "error", false);
                  return;
              }

              const sharedGalleryMediaIds = sharedGallery
                  ? new Set((window.galleryData?.[sharedGallerySlot] || []).map(photo => String(photo.id)))
                  : null;
              const primaryButtons = Array.from(mgGrid.querySelectorAll('.btn-primary-media'))
                  .filter(btn => sharedGallery || btn.getAttribute('data-slot') === slot);
              primaryButtons.forEach(btn => {
                  btn.disabled = true;
                  btn.dataset.pending = 'true';
              });

              const requestBody = { id: mediaIdNumber, slot_assignment: slot };
              if (sharedGallery) {
                  requestBody.canonical_gallery_slot = sharedGallery.canonicalSlot;
                  requestBody.building_name = sharedGallery.buildingName;
                  requestBody.room_type_code = sharedGallery.roomTypeCode;
                  requestBody.room_type = sharedGallery.roomType;
              }

              fetch("actions/admin/set_primary_media.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                  body: JSON.stringify(requestBody)
              })
              .then(async res => {
                  let data;
                  try {
                      data = await res.json();
                  } catch (error) {
                      throw new Error("The server returned an invalid response. Please try again.");
                  }
                  const serverMessage = data && typeof data.message === 'string' ? data.message.trim() : '';
                  if (!res.ok || !data || data.success !== true) {
                      throw new Error(serverMessage || `Unable to update the primary image (HTTP ${res.status}).`);
                  }
                  return data;
              })
              .then(data => {
                  const confirmedPrimaryId = Number(data.primary_id);
                  const primaryId = Number.isSafeInteger(confirmedPrimaryId) && confirmedPrimaryId > 0
                      ? confirmedPrimaryId
                      : mediaIdNumber;
                  Object.values(window.galleryData || {}).forEach(photos => {
                      if (!Array.isArray(photos)) return;
                      photos.forEach(photo => {
                          if (sharedGalleryMediaIds?.has(String(photo.id))) {
                              photo.is_primary = Number(photo.id) === primaryId ? 1 : 0;
                          } else if (!sharedGallery && String(photo.slot_assignment || '') === slot) {
                              photo.is_primary = Number(photo.id) === primaryId ? 1 : 0;
                          }
                      });
                  });

                  primaryButtons.forEach(btn => {
                      updatePrimaryButtonState(btn, Number(btn.getAttribute('data-id')) === primaryId);
                  });
                  showAlert("Success", "Primary image updated successfully!", "success", false);
                  window.needsCmsRefresh = true;
              })
              .catch(error => {
                  const message = error instanceof Error && error.message
                      ? error.message
                      : "The primary image could not be updated. Please try again.";
                  showAlert("Error", message, "error", false);
              })
              .finally(() => {
                  primaryButtons.forEach(btn => {
                      btn.disabled = false;
                      delete btn.dataset.pending;
                  });
              });
              return;
          }

          // SINGLE DELETE CLICK
          const deleteBtn = e.target.closest('.btn-delete-media');
          if (deleteBtn) {
              showConfirm("Confirm Action", "Are you sure you want to permanently delete this image?").then(c => {
                  if (!c) return;
                  const mediaId = deleteBtn.getAttribute('data-id');
                  const card = deleteBtn.closest('.mg-photo-card'); 
                  
                  deleteBtn.innerHTML = "...";
                  deleteBtn.disabled = true;

                   fetch("actions/admin/delete_media.php", {
                      method: "POST",
                      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                      body: JSON.stringify({ id: mediaId })
                  })
                  .then(res => res.json())
                  .then(data => {
                      if (data.success) {
                          card.remove(); 
                          window.needsCmsRefresh = true; 
                          updateBulkDeleteState(); // update counts just in case
                          
                          if (mgGrid.children.length === 0) {
                              document.getElementById('btnCloseGalleryModal').click();
                          }
                      } else {
                          showAlert("Error", data.message, "error", false);
                          deleteBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
                          deleteBtn.disabled = false;
                      }
                  });
              });
          }

          const removeMissingBtn = e.target.closest('.btn-remove-missing-media');
          if (removeMissingBtn) removeMissingMediaRecord(removeMissingBtn.getAttribute('data-id'), removeMissingBtn);
      });
  }

  // BULK DELETE CLICK
  if (btnBulkDelete) {
      btnBulkDelete.addEventListener('click', () => {
          const checks = mgGrid.querySelectorAll('.mg-bulk-check:checked');
          if (checks.length === 0) return;

          const ids = Array.from(checks).map(c => c.value);

          showConfirm("Confirm Action", `Are you sure you want to permanently delete ${ids.length} selected image(s)?`).then(c => {
              if (!c) return;
              const originalHTML = btnBulkDelete.innerHTML;
              btnBulkDelete.innerHTML = "Deleting...";
              btnBulkDelete.disabled = true;

              fetch("actions/admin/delete_media.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                  body: JSON.stringify({ ids: ids })
              })
              .then(res => res.json())
              .then(data => {
                  if (data.success) {
                      ids.forEach(id => {
                          const card = document.querySelector(`.mg-photo-card[data-id="${id}"]`);
                          if (card) card.remove();
                      });
                      window.needsCmsRefresh = true; 
                      updateBulkDeleteState();
                      
                      if (mgGrid.children.length === 0) {
                          document.getElementById('btnCloseGalleryModal').click();
                      }
                  } else {
                      showAlert("Error", data.message, "error", false);
                  }
              })
              .catch(err => {
                  showAlert("Error", "Network error.", "error", false);
              })
              .finally(() => {
                  btnBulkDelete.innerHTML = originalHTML;
                  updateBulkDeleteState();
              });
          });
      });
  }

  // Close the modal
  document.getElementById('btnCloseGalleryModal')?.addEventListener('click', () => {
      manageGalleryModal.classList.remove('active');
      if (window.needsCmsRefresh) {
          persistCMSFilterState();
          window.location.reload();
      }
  });

  if (btnMgAdd) {
      btnMgAdd.addEventListener('click', () => {
          manageGalleryModal.classList.remove('active');
          if (typeDropdown && slotDropdown) {
              typeDropdown.value = currentManageType;
              slotOptions.forEach(opt => {
                  if (opt.getAttribute('data-type') === currentManageType || opt.value === 'gallery') opt.style.display = 'block'; 
                  else opt.style.display = 'none';  
              });
              slotDropdown.value = currentManageSlot;
          }
          openUploadModal();
      });
  }

  // Lightbox close listener
  if (lightbox) {
      lightbox.addEventListener('click', () => lightbox.style.display = 'none');
  }


  // =========================================================
  // 7. Filter Pills (WITH SESSION STORAGE)
  // =========================================================
  const categoryFilterPills = document.querySelectorAll('.cms-category-pill');
  const mediaFilterPills = document.querySelectorAll('.cms-pill');
  const cmsCards = document.querySelectorAll('.cms-card');
  const cmsEmptyState = document.getElementById('cms-empty-state');

  function applyHotelCardMediaContext(card) {
      if (!card.classList.contains('cms-hotel-type-card')) return;
      const kind = activeCMSMediaFilter === '360' ? '360' : 'standard';
      card.dataset.visibleMediaKind = kind;
      card.querySelectorAll('[data-hotel-preview-kind]').forEach(panel => {
          const selected = panel.dataset.hotelPreviewKind === kind;
          panel.hidden = !selected;
          if (!selected) return;
          panel.querySelectorAll('img[data-cms-preview-id]').forEach(image => observeCmsPreviewImage(image, 'grid'));
      });
      card.querySelectorAll('[data-hotel-summary-kind]').forEach(summary => {
          summary.hidden = activeCMSMediaFilter !== 'all' && summary.dataset.hotelSummaryKind !== kind;
      });
  }

  function applyCMSFilters() {
      categoryFilterPills.forEach(pill => {
          const isActive = pill.getAttribute('data-category') === activeCMSCategoryFilter;
          pill.classList.toggle('active', isActive);
          pill.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });

      mediaFilterPills.forEach(pill => {
          const isActive = pill.getAttribute('data-filter') === activeCMSMediaFilter;
          pill.classList.toggle('active', isActive);
          pill.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });

      let visibleCardCount = 0;
      cmsCards.forEach(card => {
          const cardCategory = card.getAttribute('data-category');
          const matchesCategory = activeCMSCategoryFilter === 'venues'
              ? cardCategory !== 'website'
              : cardCategory === activeCMSCategoryFilter;
          const cardMediaTypes = String(card.getAttribute('data-types') || card.getAttribute('data-type') || '').split(/\s+/).filter(Boolean);
          const matchesMedia = activeCMSMediaFilter === 'all' || cardMediaTypes.includes(activeCMSMediaFilter);
          const isVisible = matchesCategory && matchesMedia;

          card.style.display = isVisible ? 'flex' : 'none';
          applyHotelCardMediaContext(card);
          if (isVisible) visibleCardCount++;
      });

      if (cmsEmptyState) cmsEmptyState.hidden = visibleCardCount > 0;
  }

  categoryFilterPills.forEach(pill => {
    pill.addEventListener('click', function() {
      const filter = this.getAttribute('data-category');
      if (!cmsCategoryFilters.has(filter)) return;
      activeCMSCategoryFilter = filter;
      applyCMSFilters();
      persistCMSFilterState();
    });
  });

  mediaFilterPills.forEach(pill => {
    pill.addEventListener('click', function() {
      const filter = this.getAttribute('data-filter');
      if (!cmsMediaFilters.has(filter)) return;
      activeCMSMediaFilter = filter;
      applyCMSFilters();
      persistCMSFilterState();
    });
  });

  // =========================================================
  // 8. Shared hotel gallery editor and per-option overrides
  // =========================================================
  const hotelEditorOverlay = document.getElementById('hotelGalleryEditor');
  const hotelEditorDialog = hotelEditorOverlay?.querySelector('[role="dialog"]');
  const hotelGalleryScope = document.getElementById('hotel-gallery-scope');
  const hotelScopeHelp = document.getElementById('hotel-gallery-scope-help');
  const hotelMigrationNotice = document.getElementById('hotel-options-migration-notice');
  let activeHotelEditorType = null;
  let activeHotelScope = 'shared';
  let activeHotelTab = 'photos';
  let hotelEditorPreviousFocus = null;

  function updateHotelScopeHelp() {
      if (!hotelScopeHelp) return;
      const option = activeHotelScope === 'shared'
          ? null
          : (activeHotelEditorType?.options || []).find(item => String(item.room_group_id) === String(activeHotelScope)) || null;
      if (!option) {
          hotelScopeHelp.textContent = 'Shared galleries are available to every room option as a fallback.';
          return;
      }
      const isTour = activeHotelTab === 'tour';
      const ownEnabled = Boolean(isTour ? option.tour_override_enabled : option.standard_override_enabled);
      hotelScopeHelp.textContent = ownEnabled
          ? `This option’s own ${isTour ? 'tour' : 'photos'} are selected; shared media fills in until you add them.`
          : `This option uses shared ${isTour ? 'tour' : 'photos'}. Choose “Use own gallery” to select its own.`;
  }

  const cmsPreviewPath = photo => {
      const path = String(photo?.thumbnail_path || '');
      return /^assets\/uploads\/\.cms-thumbnails\/[a-f0-9]{64}\.webp$/.test(path) ? path : 'assets/img/placeholder.jpg';
  };
  const hotelPreviewSizes = '(max-width: 700px) calc(84vw - 2rem), (max-width: 1000px) calc(50vw - 4rem), 430px';
  const cmsCardPreviewSources = (photo, sizes = hotelPreviewSizes) => {
      const src = String(photo?.card_preview_src || '');
      const srcset = String(photo?.card_preview_srcset || '');
      if (!safeCmsPreviewSourceSet(src, srcset)
          || photo?.card_preview_sizes !== hotelPreviewSizes) return null;
      return {
          src,
          srcset,
          sizes,
          width: Number(photo.card_preview_width) || 480,
          height: Number(photo.card_preview_height) || 270
      };
  };
  const optionPhotoSlot = option => String(option?.standard_slot || '');
  const optionTourSlot = option => String(option?.tour_slot || '');

  function hotelEditorControl(slot, className) {
      const card = document.querySelector(`.cms-hotel-type-card[data-hotel-canonical-slot="${CSS.escape(String(activeHotelEditorType?.canonical_slot || ''))}"]`);
      if (!card) return null;
      return Array.from(card.querySelectorAll(`.${className}`)).find(button => button.getAttribute('data-slot') === slot) || null;
  }

  function renderHotelPreview(slot, parent, emptyCopy, kind) {
      parent.replaceChildren();
      const photos = (window.galleryData?.[slot] || []).filter(photo => photo?.source_available !== false);
      if (!photos.length) {
          const empty = document.createElement('p');
          empty.className = 'hotel-gallery-empty';
          empty.textContent = emptyCopy;
          parent.appendChild(empty);
          return photos;
      }
      const strip = document.createElement('div');
      const isTour = kind === 'tour';
      strip.className = `hotel-gallery-preview-strip hotel-gallery-preview-strip--${isTour ? 'tour' : 'photos'}`;
      strip.tabIndex = 0;
      strip.setAttribute('role', 'region');
      strip.setAttribute('aria-label', isTour ? `Tour scene previews, ${photos.length} scenes` : `Photo previews, ${photos.length} photos`);
      photos.slice(0, 6).forEach((photo, index) => {
          const image = document.createElement('img');
          const responsiveSource = cmsCardPreviewSources(photo, hotelPreviewSizes);
          image.src = responsiveSource?.src || cmsPreviewPath(photo);
          if (responsiveSource) {
              image.srcset = responsiveSource.srcset;
              image.sizes = responsiveSource.sizes;
              image.width = responsiveSource.width;
              image.height = responsiveSource.height;
          }
          image.dataset.cmsPreviewId = String(photo.id);
          image.dataset.cmsPreviewContext = 'hotel';
          image.dataset.sourceAvailable = 'true';
          image.alt = Number(photo.is_primary) === 1 ? 'Current gallery cover' : `Gallery image ${index + 1}`;
          image.loading = 'lazy';
          image.decoding = 'async';
          strip.appendChild(image);
      });
      parent.appendChild(strip);
      strip.querySelectorAll('img[data-cms-preview-id]').forEach(image => observeCmsPreviewImage(image, 'hotel'));
      return photos;
  }

  function makeHotelAction(label, action, option = null, kind = null, secondary = false) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = secondary ? 'btn cms-btn-outline hotel-editor-action' : 'btn btn-primary hotel-editor-action';
      button.dataset.hotelAction = action;
      if (option) button.dataset.roomGroupId = String(option.room_group_id);
      if (kind) button.dataset.mediaKind = kind;
      button.textContent = label;
      return button;
  }

  function switchHotelEditorTab(tabName, focus = false) {
      if (!hotelEditorOverlay) return;
      activeHotelTab = tabName === 'tour' ? 'tour' : 'photos';
      hotelEditorOverlay.querySelectorAll('[data-hotel-tab]').forEach(tab => {
          const selected = tab.dataset.hotelTab === activeHotelTab;
          tab.setAttribute('aria-selected', selected ? 'true' : 'false');
          tab.tabIndex = selected ? 0 : -1;
          if (focus && selected) tab.focus();
      });
      hotelEditorOverlay.querySelectorAll('[data-hotel-panel]').forEach(panel => {
          panel.hidden = panel.dataset.hotelPanel !== activeHotelTab;
      });
      updateHotelScopeHelp();
  }

  function renderHotelGalleryWorkspace(type, scopeId, kind) {
      const isTour = kind === 'tour';
      const panel = document.getElementById(isTour ? 'hotel-gallery-tour-panel' : 'hotel-gallery-photos-panel');
      if (!panel) return;
      const option = scopeId === 'shared'
          ? null
          : (type.options || []).find(item => String(item.room_group_id) === String(scopeId)) || null;
      const sharedSlot = isTour ? String(type.shared_tour_slot || `${type.canonical_slot}_360`) : type.canonical_slot;
      const optionSlot = option ? (isTour ? optionTourSlot(option) : optionPhotoSlot(option)) : '';
      const ownRecords = option ? (window.galleryData?.[optionSlot] || []) : [];
      const sharedRecords = window.galleryData?.[sharedSlot] || [];
      const ownPhotos = ownRecords.filter(photo => photo?.source_available !== false);
      const sharedPhotos = sharedRecords.filter(photo => photo?.source_available !== false);
      const missingOwnCount = ownRecords.length - ownPhotos.length;
      const missingSharedCount = sharedRecords.length - sharedPhotos.length;
      const overrideEnabled = option ? Boolean(isTour ? option.tour_override_enabled : option.standard_override_enabled) : false;
      const useOwnPhotos = Boolean(option && overrideEnabled && ownPhotos.length);
      const activeSlot = useOwnPhotos ? optionSlot : sharedSlot;
      const activePhotos = useOwnPhotos ? ownPhotos : sharedPhotos;
      const mediaLabel = isTour ? '360 tour' : 'photos';
      const mediaTitle = isTour ? '360 Tour' : 'Photos';

      let status = '';
      if (!option) {
          status = `${sharedPhotos.length} available ${isTour ? `scene${sharedPhotos.length === 1 ? '' : 's'}` : `photo${sharedPhotos.length === 1 ? '' : 's'}`} · shared with room options as a fallback`;
          if (missingSharedCount) status += ` · ${missingSharedCount} saved source ${missingSharedCount === 1 ? 'file is' : 'files are'} missing`;
      } else if (useOwnPhotos) {
          status = isTour ? `Own 360 tour · ${activePhotos.length} scenes` : `Own photos · ${activePhotos.length} photos`;
      } else if (overrideEnabled) {
          status = sharedPhotos.length
              ? `Shared ${isTour ? 'tour' : 'photos'} shown until option media is available`
              : `No available ${mediaLabel}`;
          if (missingOwnCount) status += ` · ${missingOwnCount} saved own source ${missingOwnCount === 1 ? 'file is' : 'files are'} missing`;
      } else if (ownRecords.length) {
          status = `Uses shared ${isTour ? 'tour' : 'photos'}`;
          if (ownPhotos.length) status += ` · ${ownPhotos.length} saved own ${isTour ? 'scene' : 'photo'}${ownPhotos.length === 1 ? '' : 's'} retained`;
          if (missingOwnCount) status += ` · ${missingOwnCount} saved own source ${missingOwnCount === 1 ? 'file is' : 'files are'} missing`;
      } else {
          status = `Uses shared ${isTour ? 'tour' : 'photos'}`;
      }
      if (option && !useOwnPhotos && missingSharedCount) {
          status += ` · ${missingSharedCount} shared source ${missingSharedCount === 1 ? 'file is' : 'files are'} missing`;
      }

      const workspace = document.createElement('div');
      workspace.className = 'hotel-gallery-workspace';
      const heading = document.createElement('div');
      heading.className = 'hotel-gallery-workspace-heading';
      const title = document.createElement('h3');
      title.textContent = mediaTitle;
      const summary = document.createElement('p');
      summary.textContent = status;
      heading.append(title, summary);
      workspace.appendChild(heading);

      const preview = document.createElement('div');
      preview.className = 'hotel-gallery-workspace-preview';
      if (activePhotos.length) {
          renderHotelPreview(activeSlot, preview, '', kind);
      } else {
          const empty = document.createElement('p');
          empty.className = 'hotel-gallery-empty';
          if (!option) {
              empty.textContent = missingSharedCount
                  ? `No available shared ${mediaLabel}. ${missingSharedCount} saved source ${missingSharedCount === 1 ? 'file is' : 'files are'} missing from this server.`
                  : isTour ? 'No shared tour yet. Add a panorama to start this tour.' : 'No shared photos yet. Add a room photo gallery.';
          } else if (sharedPhotos.length) {
              empty.textContent = isTour ? 'No option tour yet. Shared scenes remain available as the fallback.' : 'No option photos yet. Shared photos remain available as the fallback.';
          } else if (ownPhotos.length && !overrideEnabled) {
              empty.textContent = isTour ? 'No shared tour yet. Saved option scenes are available when you choose its own gallery.' : 'No shared photos yet. Saved option photos are available when you choose its own gallery.';
          } else if (missingOwnCount) {
              empty.textContent = `No available own ${mediaLabel}. ${missingOwnCount} saved source ${missingOwnCount === 1 ? 'file is' : 'files are'} missing from this server.`;
          } else {
              empty.textContent = isTour ? 'No own tour yet. Add a panorama for this option.' : 'No own photos yet. Add photos for this option.';
          }
          preview.appendChild(empty);
      }
      workspace.appendChild(preview);

      const actions = document.createElement('div');
      actions.className = 'hotel-gallery-workspace-actions';
      actions.appendChild(makeHotelAction(isTour ? 'Add panorama' : 'Add photos', option ? 'upload-option-gallery' : (isTour ? 'upload-shared-tour' : 'upload-shared-photos'), option, isTour ? '360' : 'standard'));
      const manage = makeHotelAction(option ? 'Manage' : 'Manage', option ? 'manage-option-gallery' : (isTour ? 'manage-shared-tour' : 'manage-shared-photos'), option, isTour ? '360' : 'standard', true);
      manage.setAttribute('aria-label', `Manage ${option ? 'own' : 'shared'} ${mediaLabel}`);
      actions.appendChild(manage);

      if (option) {
          const modeAction = makeHotelAction(overrideEnabled ? 'Use shared gallery' : 'Use own gallery', overrideEnabled ? 'share-option-gallery' : 'enable-option-gallery', option, isTour ? '360' : 'standard', true);
          actions.appendChild(modeAction);
      }

      if (isTour && activePhotos.length) {
          const card = document.querySelector(`.cms-hotel-type-card[data-hotel-canonical-slot="${CSS.escape(String(type.canonical_slot))}"]`);
          const hotspotControl = useOwnPhotos
              ? Array.from(card?.querySelectorAll('.btn-place-hotspots') || []).find(button => button.dataset.slot === optionSlot)
              : card?.querySelector('.btn-place-hotspots[data-gallery-sources]');
          if (hotspotControl) {
              const hotspotLabel = option && !useOwnPhotos ? 'Edit shared hotspots' : 'Edit hotspots';
              const hotspotAction = makeHotelAction(hotspotLabel, 'edit-hotel-hotspots', option, '360', true);
              hotspotAction.dataset.hotspotScope = useOwnPhotos ? 'option' : 'shared';
              actions.appendChild(hotspotAction);
          }
      }
      workspace.appendChild(actions);
      panel.replaceChildren(workspace);
  }

  function renderHotelEditor(type, scopeId = 'shared') {
      if (!hotelEditorOverlay || !type) return;
      activeHotelEditorType = type;
      const title = `${type.building_name} — ${type.room_type}`;
      document.getElementById('hotel-editor-title').textContent = title;
      const overridesReady = window.hotelGalleryOverridesAvailable === true;
      document.getElementById('hotel-editor-subtitle').textContent = 'Choose a room option or edit the shared default.';
      hotelMigrationNotice.hidden = overridesReady;
      hotelGalleryScope.replaceChildren();
      const sharedScope = document.createElement('option');
      sharedScope.value = 'shared';
      sharedScope.textContent = 'Shared default';
      hotelGalleryScope.appendChild(sharedScope);
      if (overridesReady) {
          (type.options || []).forEach(option => {
              const scope = document.createElement('option');
              scope.value = String(option.room_group_id);
              scope.textContent = option.label || 'Room option';
              hotelGalleryScope.appendChild(scope);
          });
      }
      activeHotelScope = overridesReady && (type.options || []).some(option => String(option.room_group_id) === String(scopeId))
          ? String(scopeId)
          : 'shared';
      hotelGalleryScope.value = activeHotelScope;
      updateHotelScopeHelp();
      renderHotelGalleryWorkspace(type, activeHotelScope, 'photos');
      renderHotelGalleryWorkspace(type, activeHotelScope, 'tour');
  }

  function showHotelEditor(type, tabName, scopeId = 'shared') {
      if (!hotelEditorOverlay || !type) return;
      hotelEditorPreviousFocus = document.activeElement;
      renderHotelEditor(type, scopeId);
      hotelEditorOverlay.hidden = false;
      hotelEditorOverlay.classList.add('active');
      switchHotelEditorTab(tabName || 'photos');
      hotelEditorDialog?.focus();
  }

  function closeHotelEditor() {
      if (!hotelEditorOverlay) return;
      hotelEditorOverlay.classList.remove('active');
      hotelEditorOverlay.hidden = true;
      activeHotelEditorType = null;
      if (hotelEditorPreviousFocus?.isConnected) hotelEditorPreviousFocus.focus();
  }

  document.querySelectorAll('.cms-open-hotel-editor').forEach(button => button.addEventListener('click', () => {
      const card = button.closest('.cms-hotel-type-card');
      const type = (window.hotelGalleryEditorData || []).find(item => item.canonical_slot === card?.dataset.hotelCanonicalSlot);
      const selectedFilter = document.querySelector('.cms-pill.active')?.dataset.filter;
      const defaultTab = button.dataset.defaultTab === 'tour' ? 'tour' : 'photos';
      const tab = selectedFilter === '360' ? 'tour' : selectedFilter === 'standard' ? 'photos' : defaultTab;
      showHotelEditor(type, tab);
  }));
  document.querySelectorAll('.cms-hotel-add-shared').forEach(button => button.addEventListener('click', () => {
      const card = button.closest('.cms-hotel-type-card');
      const type = (window.hotelGalleryEditorData || []).find(item => item.canonical_slot === card?.dataset.hotelCanonicalSlot);
      if (!type) return;
      const kind = button.dataset.mediaKind === '360' ? '360' : 'standard';
      const tab = kind === '360' ? 'tour' : 'photos';
      showHotelEditor(type, tab, 'shared');
      const action = kind === '360' ? 'upload-shared-tour' : 'upload-shared-photos';
      hotelEditorOverlay?.querySelector(`[data-hotel-action="${action}"]`)?.click();
  }));
  hotelGalleryScope?.addEventListener('change', () => {
      if (!activeHotelEditorType) return;
      activeHotelScope = hotelGalleryScope.value;
      updateHotelScopeHelp();
      renderHotelGalleryWorkspace(activeHotelEditorType, activeHotelScope, 'photos');
      renderHotelGalleryWorkspace(activeHotelEditorType, activeHotelScope, 'tour');
  });
  hotelEditorOverlay?.querySelectorAll('.hotel-editor-close').forEach(button => button.addEventListener('click', closeHotelEditor));
  hotelEditorOverlay?.querySelectorAll('[data-hotel-tab]').forEach(tab => tab.addEventListener('click', () => switchHotelEditorTab(tab.dataset.hotelTab)));
  hotelEditorOverlay?.querySelector('.hotel-editor-tabs')?.addEventListener('keydown', event => {
      if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const tabs = Array.from(hotelEditorOverlay.querySelectorAll('[data-hotel-tab]'));
      const current = tabs.indexOf(event.target.closest('[data-hotel-tab]'));
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
          : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      switchHotelEditorTab(tabs[next]?.dataset.hotelTab, true);
  });
  hotelEditorOverlay?.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); closeHotelEditor(); return; }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(hotelEditorDialog?.querySelectorAll('button:not(:disabled):not([hidden]), [href], input:not(:disabled), select:not(:disabled), [tabindex]:not([tabindex="-1"])') || [])
          .filter(item => item.offsetParent !== null);
      if (!focusable.length) return;
      if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
      else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
  });

  async function setHotelGalleryMode(option, kind, enabled) {
      const response = await fetch('actions/admin/set_hotel_media_mode.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify({ room_group_id: Number(option.room_group_id), kind, enabled: enabled ? 1 : 0 })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.success) throw new Error(data?.message || 'The room-option gallery could not be updated.');
      if (kind === '360') option.tour_override_enabled = Boolean(enabled);
      else option.standard_override_enabled = Boolean(enabled);
  }

  hotelEditorOverlay?.addEventListener('click', async event => {
      const button = event.target.closest('[data-hotel-action]');
      if (!button || button.disabled) return;
      const action = button.dataset.hotelAction;
      const kind = button.dataset.mediaKind;
      const option = activeHotelEditorType?.options?.find(item => Number(item.room_group_id) === Number(button.dataset.roomGroupId));
      const control = (slot, className) => hotelEditorControl(slot, className);
      const clickControl = (slot, className) => {
          const target = control(slot, className);
          if (!target) throw new Error('The gallery control is unavailable. Refresh the page and try again.');
          target.click();
      };
      try {
          if (action === 'upload-shared-photos') clickControl(activeHotelEditorType.canonical_slot, 'btn-cms-modal');
          else if (action === 'manage-shared-photos') clickControl(activeHotelEditorType.canonical_slot, 'btn-manage-gallery');
          else if (action === 'upload-shared-tour') clickControl(activeHotelEditorType.shared_tour_slot, 'btn-cms-modal');
          else if (action === 'manage-shared-tour') clickControl(activeHotelEditorType.shared_tour_slot, 'btn-manage-gallery');
          else if (action === 'upload-option-gallery' && option) clickControl(kind === '360' ? optionTourSlot(option) : optionPhotoSlot(option), 'btn-cms-modal');
          else if (action === 'manage-option-gallery' && option) {
              const isEnabled = kind === '360' ? option.tour_override_enabled : option.standard_override_enabled;
              if (!isEnabled) await setHotelGalleryMode(option, kind, true);
              renderHotelEditor(activeHotelEditorType, activeHotelScope);
              clickControl(kind === '360' ? optionTourSlot(option) : optionPhotoSlot(option), 'btn-manage-gallery');
          } else if (action === 'enable-option-gallery' && option) {
              await setHotelGalleryMode(option, kind, true);
              renderHotelEditor(activeHotelEditorType, activeHotelScope);
          } else if (action === 'share-option-gallery' && option) {
              await setHotelGalleryMode(option, kind, false);
              renderHotelEditor(activeHotelEditorType, activeHotelScope);
          } else if (action === 'edit-hotel-hotspots') {
              if (button.dataset.hotspotScope === 'option' && option) {
                  clickControl(optionTourSlot(option), 'btn-place-hotspots');
              } else {
                  const card = document.querySelector(`.cms-hotel-type-card[data-hotel-canonical-slot="${CSS.escape(String(activeHotelEditorType?.canonical_slot || ''))}"]`);
                  const target = card?.querySelector('.btn-place-hotspots[data-gallery-sources]');
                  if (!target) throw new Error('The shared tour has no editable panoramas yet.');
                  target.click();
              }
          }
      } catch (error) {
          if (typeof window.showAlert === 'function') window.showAlert('Gallery update failed', error instanceof Error ? error.message : 'Please try again.', 'error');
      }
  });

  applyCMSFilters();
  document.querySelectorAll('img[data-cms-preview-id]').forEach(image => {
      if (image.closest('.mg-photo-card')) return;
      observeCmsPreviewImage(image, 'grid');
  });
  persistCMSFilterState();

});
