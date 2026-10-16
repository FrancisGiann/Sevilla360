/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

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

  // Progress Bar Elements
  const progContainer = document.getElementById('upload-progress-container');
  const progBar = document.getElementById('upload-progress-bar');
  const progText = document.getElementById('upload-progress-text');


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
      uploadModal.classList.add('active');
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
      uploadModal.classList.add('active');
    });
  });

  if (btnCloseModal) btnCloseModal.addEventListener('click', () => uploadModal.classList.remove('active'));
  window.addEventListener('click', (e) => { if (e.target === uploadModal) uploadModal.classList.remove('active'); });

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
              if (progBar) progBar.style.transform = 'scaleX(0)';
              if (progText) progText.innerText = '0%';
          };
          const showUploadError = (title, message) => {
              resetUploadUi();
              showAlert(title, message, "error", false);
          };

          submitBtn.innerText = "Uploading...";
          submitBtn.disabled = true;

          // Show Progress Bar
          if (progContainer) {
              progContainer.style.display = 'block';
              progBar.style.transform = 'scaleX(0)';
              progText.innerText = '0%';
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
                      const progressRatio = Math.min(1, Math.max(0, percentComplete / 100));
                      progBar.style.transform = `scaleX(${progressRatio})`;
                      progText.innerText = percentComplete + '%';
                  }
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
                  if (xhr.status >= 200 && xhr.status < 300 && data && data.success === true) {
                      resetUploadUi();
                      uploadModal.classList.remove('active');
                      persistCMSFilterState();
                      showAlert("Success", serverMessage || "Media uploaded successfully.", "success", true);
                  } else {
                      showUploadError("Error", serverMessage || "Server Error: " + xhr.status);
                  }
              };

              xhr.onerror = function() {
                  showUploadError("Network Error", "A network error occurred during upload.");
              };

              xhr.onabort = function() {
                  showUploadError("Upload Cancelled", "The upload was cancelled before it finished.");
              };

              xhr.ontimeout = function() {
                  showUploadError("Network Error", "The upload timed out. Please try again.");
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
          selectAllCheck.checked = (checkedCount === checks.length);
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
              const starColor = isPrimary ? "var(--color-gold)" : "#ccc";
              const primaryLabel = isPrimary ? "Primary image" : "Set as primary image";
              const thumbnailPath = /^assets\/uploads\/\.cms-thumbnails\/[a-f0-9]{64}\.webp$/.test(String(photo.thumbnail_path || ''))
                  ? photo.thumbnail_path
                  : 'assets/img/placeholder.jpg';
              const thumbnailVersion = Number(photo.thumbnail_version);
              const thumbnailUrl = `${thumbnailPath}${thumbnailVersion > 0 ? `?v=${thumbnailVersion}` : ''}`;
              const originalPath = /^assets\/uploads\/[A-Za-z0-9_.-]+$/.test(String(photo.file_path || ''))
                  ? photo.file_path
                  : '';
              const fileName = escapeHTML(photo.file_name);
              
              mgGrid.innerHTML += `
                  <div class="mg-photo-card" data-id="${photo.id}" style="position: relative; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                      
                      <!-- Bulk Checkbox -->
                      <div style="position: absolute; top: 8px; left: 8px; z-index: 10; background: white; border-radius: 4px; padding: 3px 6px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">
                          <input type="checkbox" class="mg-bulk-check" value="${photo.id}" style="cursor: pointer; transform: scale(1.2); margin: 0;">
                      </div>

                      <img src="${escapeHTML(thumbnailUrl)}" data-original-src="${escapeHTML(originalPath)}" alt="${fileName}" width="${Number(photo.thumbnail_width) || 480}" height="${Number(photo.thumbnail_height) || 270}" loading="lazy" decoding="async" class="mg-thumb" style="width: 100%; height: 150px; object-fit: cover; display: block; cursor: zoom-in;">
                      <div style="padding: 10px; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                          
                          ${currentManageType === '360'
                              ? '<span class="mg-primary-managed">Starting scene is set in Tour Setup</span>'
                              : `<button class="btn-primary-media" data-id="${photo.id}" data-slot="${escapeHTML(photo.slot_assignment || currentManageSlot)}" aria-label="${primaryLabel}" aria-pressed="${isPrimary ? 'true' : 'false'}" title="${primaryLabel}" style="background: none; border: none; color: ${starColor}; cursor: pointer; padding: 5px; font-size: 1.2rem; transition: 0.3s;">
                                  <i class="fa-solid fa-star" aria-hidden="true"></i>
                              </button>`}
                          
                          <span style="font-size: 0.75rem; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80px;">${fileName}</span>
                          
                          <button class="btn-delete-media" data-id="${photo.id}" style="background: none; border: none; color: #c75c5c; cursor: pointer; padding: 5px;">
                              <i class="fa-solid fa-trash"></i>
                          </button>
                      </div>
                  </div>
              `;
          });

          // Reset bulk states when modal opens
          if (selectAllCheck) selectAllCheck.checked = false;
          updateBulkDeleteState();
          if (bulkControls) bulkControls.style.display = photos.length > 0 ? 'flex' : 'none';

          manageGalleryModal.classList.add('active');
      });
  });

  // Bulk Checkbox Listeners
  if (selectAllCheck) {
      selectAllCheck.addEventListener('change', function() {
          const checks = mgGrid.querySelectorAll('.mg-bulk-check');
          checks.forEach(c => c.checked = selectAllCheck.checked);
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
              lightboxImg.src = e.target.dataset.originalSrc || e.target.src;
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
          uploadModal.classList.add('active');
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
  const optionPhotoSlot = option => String(option?.standard_slot || '');
  const optionTourSlot = option => String(option?.tour_slot || '');

  function hotelEditorControl(slot, className) {
      const card = document.querySelector(`.cms-hotel-type-card[data-hotel-canonical-slot="${CSS.escape(String(activeHotelEditorType?.canonical_slot || ''))}"]`);
      if (!card) return null;
      return Array.from(card.querySelectorAll(`.${className}`)).find(button => button.getAttribute('data-slot') === slot) || null;
  }

  function renderHotelPreview(slot, parent, emptyCopy) {
      parent.replaceChildren();
      const photos = window.galleryData?.[slot] || [];
      if (!photos.length) {
          const empty = document.createElement('p');
          empty.className = 'hotel-gallery-empty';
          empty.textContent = emptyCopy;
          parent.appendChild(empty);
          return photos;
      }
      const strip = document.createElement('div');
      strip.className = 'hotel-gallery-preview-strip';
      photos.slice(0, 6).forEach((photo, index) => {
          const image = document.createElement('img');
          image.src = cmsPreviewPath(photo);
          image.alt = index === 0 && Number(photo.is_primary) === 1 ? 'Current gallery cover' : `Gallery image ${index + 1}`;
          image.loading = 'lazy';
          image.decoding = 'async';
          strip.appendChild(image);
      });
      parent.appendChild(strip);
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
      const ownPhotos = option ? (window.galleryData?.[optionSlot] || []) : [];
      const sharedPhotos = window.galleryData?.[sharedSlot] || [];
      const overrideEnabled = option ? Boolean(isTour ? option.tour_override_enabled : option.standard_override_enabled) : false;
      const useOwnPhotos = Boolean(option && overrideEnabled && ownPhotos.length);
      const activeSlot = useOwnPhotos ? optionSlot : sharedSlot;
      const activePhotos = useOwnPhotos ? ownPhotos : sharedPhotos;
      const mediaLabel = isTour ? '360 tour' : 'photos';
      const mediaTitle = isTour ? '360 Tour' : 'Photos';

      let status = '';
      if (!option) {
          status = `${sharedPhotos.length} ${isTour ? `scene${sharedPhotos.length === 1 ? '' : 's'}` : `photo${sharedPhotos.length === 1 ? '' : 's'}`} · shared with room options as a fallback`;
      } else if (useOwnPhotos) {
          status = isTour ? `Own 360 tour · ${activePhotos.length} scenes` : `Own photos · ${activePhotos.length} photos`;
      } else if (overrideEnabled) {
          status = sharedPhotos.length
              ? `Own ${mediaLabel} selected · shared ${isTour ? 'tour' : 'photos'} shown until you add option media`
              : `Own ${mediaLabel} selected · no media yet`;
      } else if (ownPhotos.length) {
          status = `Uses shared ${isTour ? 'tour' : 'photos'} · ${ownPhotos.length} saved own ${isTour ? 'scene' : 'photo'}${ownPhotos.length === 1 ? '' : 's'} retained`;
      } else {
          status = `Uses shared ${isTour ? 'tour' : 'photos'}`;
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
          renderHotelPreview(activeSlot, preview, '');
      } else {
          const empty = document.createElement('p');
          empty.className = 'hotel-gallery-empty';
          if (!option) {
              empty.textContent = isTour ? 'No shared tour yet. Add a panorama to start this tour.' : 'No shared photos yet. Add a room photo gallery.';
          } else if (sharedPhotos.length) {
              empty.textContent = isTour ? 'No option tour yet. Shared scenes remain available as the fallback.' : 'No option photos yet. Shared photos remain available as the fallback.';
          } else if (ownPhotos.length && !overrideEnabled) {
              empty.textContent = isTour ? 'No shared tour yet. Saved option scenes are available when you choose its own gallery.' : 'No shared photos yet. Saved option photos are available when you choose its own gallery.';
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
      showHotelEditor(type, activeCMSMediaFilter === '360' ? 'tour' : 'photos');
  }));
  document.querySelectorAll('.cms-hotel-add-shared').forEach(button => button.addEventListener('click', () => {
      const card = button.closest('.cms-hotel-type-card');
      const type = (window.hotelGalleryEditorData || []).find(item => item.canonical_slot === card?.dataset.hotelCanonicalSlot);
      if (!type) return;
      showHotelEditor(type, 'photos', 'shared');
      hotelEditorOverlay?.querySelector('[data-hotel-action="upload-shared-photos"]')?.click();
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
  persistCMSFilterState();

});
