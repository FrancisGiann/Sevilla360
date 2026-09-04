/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

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
  const btnMgAdd = document.getElementById('btn-mg-add');
  let currentManageSlot = null;
  let currentManageType = null;

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
          currentManageType = this.closest('.cms-card').getAttribute('data-type');
          const photos = window.galleryData[currentManageSlot] || [];
          
          document.getElementById('mg-title').innerText = `Manage Photos`;
          mgGrid.innerHTML = '';
          
          photos.forEach((photo) => {
              const isPrimary = Number(photo.is_primary) === 1;
              const starColor = isPrimary ? "var(--color-gold)" : "#ccc";
              const primaryLabel = isPrimary ? "Primary image" : "Set as primary image";
              
              mgGrid.innerHTML += `
                  <div class="mg-photo-card" data-id="${photo.id}" style="position: relative; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                      
                      <!-- Bulk Checkbox -->
                      <div style="position: absolute; top: 8px; left: 8px; z-index: 10; background: white; border-radius: 4px; padding: 3px 6px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">
                          <input type="checkbox" class="mg-bulk-check" value="${photo.id}" style="cursor: pointer; transform: scale(1.2); margin: 0;">
                      </div>

                      <img src="${photo.file_path}?v=${Date.now()}" class="mg-thumb" style="width: 100%; height: 150px; object-fit: cover; display: block; cursor: zoom-in;">
                      <div style="padding: 10px; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                          
                          <button class="btn-primary-media" data-id="${photo.id}" data-slot="${currentManageSlot}" aria-label="${primaryLabel}" aria-pressed="${isPrimary ? 'true' : 'false'}" title="${primaryLabel}" style="background: none; border: none; color: ${starColor}; cursor: pointer; padding: 5px; font-size: 1.2rem; transition: 0.3s;">
                              <i class="fa-solid fa-star" aria-hidden="true"></i>
                          </button>
                          
                          <span style="font-size: 0.75rem; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80px;">${photo.file_name}</span>
                          
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
              lightboxImg.src = e.target.src;
              lightbox.style.display = 'flex';
              return;
          }

          // SET PRIMARY (STAR)
          const primaryBtn = e.target.closest('.btn-primary-media');
          if (primaryBtn) {
              const mediaId = primaryBtn.getAttribute('data-id');
              const slot = primaryBtn.getAttribute('data-slot');

              if (primaryBtn.disabled || primaryBtn.dataset.pending === 'true') return;
              const mediaIdNumber = Number(mediaId);
              if (!Number.isSafeInteger(mediaIdNumber) || mediaIdNumber < 1 || !slot) {
                  showAlert("Error", "The selected image is invalid. Please refresh and try again.", "error", false);
                  return;
              }

              const slotButtons = Array.from(mgGrid.querySelectorAll('.btn-primary-media'))
                  .filter(btn => btn.getAttribute('data-slot') === slot);
              slotButtons.forEach(btn => {
                  btn.disabled = true;
                  btn.dataset.pending = 'true';
              });

              fetch("actions/admin/set_primary_media.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                  body: JSON.stringify({ id: mediaIdNumber, slot_assignment: slot })
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
                  const photosForSlot = window.galleryData && Array.isArray(window.galleryData[slot])
                      ? window.galleryData[slot]
                      : [];
                  photosForSlot.forEach(photo => {
                      photo.is_primary = Number(photo.id) === primaryId ? 1 : 0;
                  });

                  mgGrid.querySelectorAll('.btn-primary-media').forEach(btn => {
                      if (btn.getAttribute('data-slot') === slot) {
                          updatePrimaryButtonState(btn, Number(btn.getAttribute('data-id')) === primaryId);
                      }
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
                  slotButtons.forEach(btn => {
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
          sessionStorage.setItem('activeCMSFilter', document.querySelector('.cms-pill.active').getAttribute('data-filter'));
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
  const filterPills = document.querySelectorAll('.cms-pill');
  const cmsCards = document.querySelectorAll('.cms-card');

  function applyFilter(filterValue) {
      filterPills.forEach(p => {
          if (p.getAttribute('data-filter') === filterValue) p.classList.add('active');
          else p.classList.remove('active');
      });
      
      cmsCards.forEach(card => {
          if (filterValue === 'all' || card.getAttribute('data-type') === filterValue) card.style.display = 'flex';
          else card.style.display = 'none';
      });
  }

  // Click listener
  filterPills.forEach(pill => {
    pill.addEventListener('click', function() {
      const filter = this.getAttribute('data-filter');
      sessionStorage.setItem('activeCMSFilter', filter); // Save state
      applyFilter(filter);
    });
  });

  // On Load, check if we saved a tab previously!
  const savedFilter = sessionStorage.getItem('activeCMSFilter');
  if (savedFilter) {
      applyFilter(savedFilter);
  }

});
