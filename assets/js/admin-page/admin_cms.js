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
      // Reset the form
      uploadForm.reset();
      
      // Hide all specific slot options until a type is selected
      slotOptions.forEach(opt => opt.style.display = 'none');
      
      // Reset drag-and-drop text
      const dropText = document.querySelector('.drop-text');
      if (dropText) {
          dropText.innerHTML = `<strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to browse</span>`;
      }

      uploadModal.classList.add('active');
    });
  }

  // B. Open Modal (Replace Buttons on Cards)
  replaceBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      // Pre-select the media type and trigger the cascade event
      if (typeDropdown) {
          typeDropdown.value = this.getAttribute('data-type');
          typeDropdown.dispatchEvent(new Event('change')); 
      }
      // Pre-select the specific slot
      if (slotDropdown) {
          slotDropdown.value = this.getAttribute('data-slot');
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


  // --- 3. Cascading Dropdown Logic ---
  if (typeDropdown && slotDropdown) {
      typeDropdown.addEventListener('change', function() {
          const selectedType = this.value; 
          
          slotDropdown.value = ""; // Reset selection
          
          slotOptions.forEach(opt => {
              if (opt.getAttribute('data-type') === selectedType) {
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
        updateDropText(e.dataTransfer.files[0].name);
      }
    });

    fileInputEl.addEventListener('change', function() {
      if (this.files.length > 0) {
        updateDropText(this.files[0].name);
      }
    });

    function updateDropText(fileName) {
      const dropText = dragDropArea.querySelector('.drop-text');
      dropText.innerHTML = `<strong>Selected File:</strong><br><span class="highlight">${fileName}</span>`;
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

          const formData = new FormData(this);
          formData.append("fileInput", fileInputEl.files[0]);

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