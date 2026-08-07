/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {

  // =========================================================
  // 1. UNIVERSAL MODAL UTILITIES
  // =========================================================
  const uniConfirmModal = document.getElementById("uniConfirmModal");
  const uniAlertModal = document.getElementById("uniAlertModal");
  let pendingCallback = null;

  function showConfirmModal(message, callback) {
      document.getElementById("uc-message").innerText = message;
      pendingCallback = callback;
      uniConfirmModal.classList.add("active");
  }

  document.getElementById("uc-btn-no")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      pendingCallback = null; 
  });

  document.getElementById("uc-btn-yes")?.addEventListener("click", () => {
      uniConfirmModal.classList.remove("active");
      if (pendingCallback) {
          pendingCallback(); 
          pendingCallback = null; 
      }
  });

  function showAlertModal(title, message, type = "info", reloadOnClose = false) {
      document.getElementById("ua-title").innerText = title;
      document.getElementById("ua-message").innerText = message;
      
      const icon = document.getElementById("ua-icon");
      if (type === "success") {
          icon.className = "fa-solid fa-circle-check"; icon.style.color = "#4ade80"; 
      } else if (type === "error") {
          icon.className = "fa-solid fa-triangle-exclamation"; icon.style.color = "#e06666"; 
      } else {
          icon.className = "fa-solid fa-circle-info"; icon.style.color = "var(--color-gold)"; 
      }

      uniAlertModal.classList.add("active");

      const okBtn = document.getElementById("ua-btn-ok");
      const newOkBtn = okBtn.cloneNode(true); 
      okBtn.parentNode.replaceChild(newOkBtn, okBtn);

      newOkBtn.addEventListener("click", () => {
          if (reloadOnClose) window.location.reload();
          else uniAlertModal.classList.remove("active");
      });
  }


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
              showAlertModal("Error", "Please select a file to upload.", "error", false);
              return;
          }

          const slotDropdown = document.getElementById('modal-website-slot');
          const isStrictSlot = slotDropdown.value.startsWith('home-');

          if (isStrictSlot && fileInputEl.files.length > 1) {
              showAlertModal("Error", "You can only upload ONE image at a time for Homepage Previews.", "error", false);
              return;
          }

          const formData = new FormData(this);
          formData.delete("fileInput"); 
          for (let i = 0; i < fileInputEl.files.length; i++) {
              formData.append("fileInput[]", fileInputEl.files[i]);
          }

          const submitBtn = this.querySelector('button[type="submit"]');
          const originalText = submitBtn.innerText;
          submitBtn.innerText = "Uploading...";
          submitBtn.disabled = true;

          // Show Progress Bar
          if (progContainer) {
              progContainer.style.display = 'block';
              progBar.style.width = '0%';
              progText.innerText = '0%';
          }

          // Use XMLHttpRequest for actual progress tracking!
          const xhr = new XMLHttpRequest();
          xhr.open("POST", "actions/admin/upload_media.php", true);

          // Track Upload Progress
          xhr.upload.addEventListener("progress", (event) => {
              if (event.lengthComputable) {
                  let percentComplete = Math.round((event.loaded / event.total) * 100);
                  progBar.style.width = percentComplete + '%';
                  progText.innerText = percentComplete + '%';
              }
          });

          // Handle Completion
          xhr.onload = function() {
              if (xhr.status === 200) {
                  try {
                      const data = JSON.parse(xhr.responseText);
                      if (data.success) {
                          uploadModal.classList.remove('active');
                          showAlertModal("Success", data.message, "success", true);
                      } else {
                          showAlertModal("Error", data.message, "error", false);
                          submitBtn.innerText = originalText;
                          submitBtn.disabled = false;
                      }
                  } catch(e) {
                      showAlertModal("Error", "Server returned an invalid response.", "error", false);
                      submitBtn.innerText = originalText;
                      submitBtn.disabled = false;
                  }
              } else {
                  showAlertModal("Error", "Server Error: " + xhr.status, "error", false);
                  submitBtn.innerText = originalText;
                  submitBtn.disabled = false;
              }
          };

          xhr.onerror = function() {
              showAlertModal("Network Error", "A network error occurred during upload.", "error", false);
              submitBtn.innerText = originalText;
              submitBtn.disabled = false;
          };

          xhr.send(formData);
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
          
          photos.forEach((photo, index) => {
              const isPrimary = index === 0;
              const starColor = isPrimary ? "var(--color-gold)" : "#ccc";
              
              mgGrid.innerHTML += `
                  <div class="mg-photo-card" data-id="${photo.id}" style="position: relative; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                      
                      <!-- Bulk Checkbox -->
                      <div style="position: absolute; top: 8px; left: 8px; z-index: 10; background: white; border-radius: 4px; padding: 3px 6px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">
                          <input type="checkbox" class="mg-bulk-check" value="${photo.id}" style="cursor: pointer; transform: scale(1.2); margin: 0;">
                      </div>

                      <img src="${photo.file_path}?v=${Date.now()}" class="mg-thumb" style="width: 100%; height: 150px; object-fit: cover; display: block; cursor: zoom-in;">
                      <div style="padding: 10px; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                          
                          <button class="btn-primary-media" data-id="${photo.id}" data-slot="${currentManageSlot}" title="Set as Main Photo" style="background: none; border: none; color: ${starColor}; cursor: pointer; padding: 5px; font-size: 1.2rem; transition: 0.3s;">
                              <i class="fa-solid fa-star"></i>
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
              
              mgGrid.querySelectorAll('.btn-primary-media').forEach(btn => btn.style.color = '#ccc');
              primaryBtn.style.color = "var(--color-gold)";

              fetch("actions/admin/set_primary_media.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({ id: mediaId, slot_assignment: slot })
              })
              .then(res => res.json())
              .then(data => {
                  if (data.success) {
                      showAlertModal("Success", "Primary image updated successfully!", "success", false);
                      window.needsCmsRefresh = true;
                  } else {
                      showAlertModal("Error", data.message, "error", false);
                  }
              });
              return;
          }

          // SINGLE DELETE CLICK
          const deleteBtn = e.target.closest('.btn-delete-media');
          if (deleteBtn) {
              showConfirmModal("Are you sure you want to permanently delete this image?", () => {
                  const mediaId = deleteBtn.getAttribute('data-id');
                  const card = deleteBtn.closest('.mg-photo-card'); 
                  
                  deleteBtn.innerHTML = "...";
                  deleteBtn.disabled = true;

                  fetch("actions/admin/delete_media.php", {
                      method: "POST",
                      headers: { "Content-Type": "application/json" },
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
                          showAlertModal("Error", data.message, "error", false);
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

          showConfirmModal(`Are you sure you want to permanently delete ${ids.length} selected image(s)?`, () => {
              const originalHTML = btnBulkDelete.innerHTML;
              btnBulkDelete.innerHTML = "Deleting...";
              btnBulkDelete.disabled = true;

              fetch("actions/admin/delete_media.php", {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
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
                      showAlertModal("Error", data.message, "error", false);
                  }
              })
              .catch(err => {
                  showAlertModal("Error", "Network error.", "error", false);
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