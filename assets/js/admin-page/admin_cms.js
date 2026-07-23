/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {

  // --- 1. DOM Elements ---
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

  // --- 2. Modal Open/Close Logic ---
  
  // A. Open Modal (Main Upload Button)
  if (btnOpenUpload) {
    btnOpenUpload.addEventListener('click', () => {
      uploadForm.reset();
      slotOptions.forEach(opt => opt.style.display = 'none');
      
      const dropText = document.querySelector('.drop-text');
      if (dropText) dropText.innerHTML = `<strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to browse</span>`;

      uploadModal.classList.add('active');
    });
  }

  // B. Open Modal (Replace Buttons on Cards)
  replaceBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      const mediaType = this.getAttribute('data-type');
      const targetSlot = this.getAttribute('data-slot');

      if (typeDropdown && slotDropdown) {
          // 1. Set the first dropdown
          typeDropdown.value = mediaType;
          
          // 2. Hide/Show the correct options manually (bypassing the change event)
          slotOptions.forEach(opt => {
              if (opt.getAttribute('data-type') === mediaType || opt.value === 'gallery') {
                  opt.style.display = 'block'; 
              } else {
                  opt.style.display = 'none';  
              }
          });

          // 3. Safely set the second dropdown!
          slotDropdown.value = targetSlot;
      }
      uploadModal.classList.add('active');
    });
  });

  // C. Close Modal Logic
  if (btnCloseModal) {
    btnCloseModal.addEventListener('click', () => uploadModal.classList.remove('active'));
  }
  window.addEventListener('click', (e) => {
    if (e.target === uploadModal) uploadModal.classList.remove('active');
  });


  // --- 3. Cascading Dropdown Logic (For Human Clicks Only) ---
  if (typeDropdown && slotDropdown) {
      typeDropdown.addEventListener('change', function(e) {
          const selectedType = this.value; 
          
          // ONLY reset the second dropdown if a real human clicked it!
          if (e.isTrusted) {
              slotDropdown.value = ""; 
          }
          
          slotOptions.forEach(opt => {
              if (opt.getAttribute('data-type') === selectedType || opt.value === 'gallery') {
                  opt.style.display = 'block'; 
              } else {
                  opt.style.display = 'none';  
              }
          });
      });
  }


  // --- 4. Drag & Drop Visuals ---
  if (dragDropArea && fileInputEl) {
    dragDropArea.addEventListener('click', () => fileInputEl.click());

    dragDropArea.addEventListener('dragover', (e) => {
      e.preventDefault();
      dragDropArea.classList.add('dragover');
    });

    dragDropArea.addEventListener('dragleave', (e) => {
      e.preventDefault();
      dragDropArea.classList.remove('dragover');
    });

    dragDropArea.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDropArea.classList.remove('dragover');
      
      if (e.dataTransfer.files.length > 0) {
        fileInputEl.files = e.dataTransfer.files;
        updateDropText(e.dataTransfer.files);
      }
    });

    fileInputEl.addEventListener('change', function() {
      if (this.files.length > 0) {
        updateDropText(this.files);
      }
    });

    function updateDropText(files) {
      const dropText = dragDropArea.querySelector('.drop-text');
      if (files.length === 1) {
          dropText.innerHTML = `<strong>Selected File:</strong><br><span class="highlight">${files[0].name}</span>`;
      } else {
          dropText.innerHTML = `<strong>Selected Files:</strong><br><span class="highlight">${files.length} files selected</span>`;
      }
    }
  }


  // --- 5. Handle File Upload Submission ---
  if (uploadForm) {
      uploadForm.addEventListener("submit", function(e) {
          e.preventDefault();

          if (!fileInputEl.files || fileInputEl.files.length === 0) {
              alert("Please select a file to upload.");
              return;
          }

          const slotDropdown = document.getElementById('modal-website-slot');
          const isStrictSlot = slotDropdown.value === 'home-hero' || slotDropdown.value.includes('_360');

          // Guard: Prevent user from uploading 5 images to a 360 panorama slot!
          if (isStrictSlot && fileInputEl.files.length > 1) {
              alert("You can only upload ONE image at a time for Hero Banners and 360 Panoramas.");
              return;
          }

          // Use fileInput[] array syntax so PHP can read multiple files
          const formData = new FormData(this);
          formData.delete("fileInput"); // Remove default binding
          for (let i = 0; i < fileInputEl.files.length; i++) {
              formData.append("fileInput[]", fileInputEl.files[i]);
          }

          const submitBtn = this.querySelector('button[type="submit"]');
          const originalText = submitBtn.innerText;
          submitBtn.innerText = "Uploading...";
          submitBtn.disabled = true;

          fetch("actions/admin/upload_media.php", {
              method: "POST",
              body: formData
          })
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  alert(data.message);
                  window.location.reload();
              } else {
                  alert("Error: " + data.message);
                  submitBtn.innerText = originalText;
                  submitBtn.disabled = false;
              }
          })
          .catch(error => {
              console.error("Upload error:", error);
              alert("A network error occurred during upload.");
              submitBtn.innerText = originalText;
              submitBtn.disabled = false;
          });
      });
  }


  // --- 6. Delete Media Buttons ---
  document.querySelectorAll('.btn-delete-media').forEach(btn => {
      btn.addEventListener('click', function() {
          if (!confirm("Are you sure you want to permanently delete this image?")) return;

          const mediaId = this.getAttribute('data-id');
          const originalText = this.innerText;
          this.innerText = "Deleting...";

          fetch("actions/admin/delete_media.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ id: mediaId })
          })
          .then(res => res.json())
          .then(data => {
              if (data.success) {
                  this.closest('.cms-card').remove(); 
              } else {
                  alert("Error: " + data.message);
                  this.innerText = originalText;
              }
          })
          .catch(err => {
              console.error(err);
              alert("Network error.");
              this.innerText = originalText;
          });
      });
  });


  // --- 7. Filter Pills ---
  const filterPills = document.querySelectorAll('.cms-pill');
  const cmsCards = document.querySelectorAll('.cms-card');

  filterPills.forEach(pill => {
    pill.addEventListener('click', function() {
      filterPills.forEach(p => p.classList.remove('active'));
      this.classList.add('active');
      
      const filter = this.getAttribute('data-filter');
      
      cmsCards.forEach(card => {
          if (filter === 'all' || card.getAttribute('data-type') === filter) {
              card.style.display = 'flex';
          } else {
              card.style.display = 'none';
          }
      });
    });
  });

});