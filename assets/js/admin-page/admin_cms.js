/**
 * SEVILLA360 - Admin Media CMS Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
  
  // --- 1. Filter Pills Visual Toggle Logic ---
  const filterPills = document.querySelectorAll('.cms-pill');

  filterPills.forEach(pill => {
    pill.addEventListener('click', () => {
      // Remove active class from all pills
      filterPills.forEach(p => p.classList.remove('active'));
      
      // Add active class to the clicked pill
      pill.classList.add('active');
      
      // Note: Actual filtering logic (hiding/showing cards) goes here later!
    });
  });

  // --- 2. Modal Open/Close Logic ---
  const uploadModal = document.getElementById('uploadModal');
  const btnOpenUpload = document.getElementById('btnOpenUpload');
  const btnCloseModal = document.getElementById('btnCloseModal');
  const replaceBtns = document.querySelectorAll('.btn-cms-modal');

  // Open Modal (Upload Button)
  if (btnOpenUpload) {
    btnOpenUpload.addEventListener('click', () => {
      uploadModal.classList.add('active');
    });
  }

  // Open Modal (Replace Buttons on Cards)
  replaceBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      uploadModal.classList.add('active');
    });
  });

  // Close Modal (Cancel Button)
  if (btnCloseModal) {
    btnCloseModal.addEventListener('click', () => {
      uploadModal.classList.remove('active');
    });
  }

  // Close Modal (Clicking Outside the white box onto the overlay)
  window.addEventListener('click', (e) => {
    if (e.target === uploadModal) {
      uploadModal.classList.remove('active');
    }
  });


  // --- 3. Drag & Drop Visuals (Optional bonus for better UI) ---
  const dragDropArea = document.getElementById('dragDropArea');
  const fileInput = document.getElementById('fileInput');

  if (dragDropArea && fileInput) {
    // Open file selector when clicking the drop zone
    dragDropArea.addEventListener('click', () => fileInput.click());

    // Highlight area when dragging over
    dragDropArea.addEventListener('dragover', (e) => {
      e.preventDefault();
      dragDropArea.classList.add('dragover');
    });

    // Remove highlight when dragging leaves
    dragDropArea.addEventListener('dragleave', (e) => {
      e.preventDefault();
      dragDropArea.classList.remove('dragover');
    });

    // Handle Drop
    dragDropArea.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDropArea.classList.remove('dragover');
      
      if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        updateDropText(e.dataTransfer.files[0].name);
      }
    });

    // Handle manual browse selection
    fileInput.addEventListener('change', function() {
      if (this.files.length > 0) {
        updateDropText(this.files[0].name);
      }
    });

    function updateDropText(fileName) {
      const dropText = dragDropArea.querySelector('.drop-text');
      dropText.innerHTML = `<strong>Selected File:</strong><br><span class="highlight">${fileName}</span>`;
    }
  }
// --- 4. Handle File Upload Submission ---
  const uploadForm = document.getElementById("cms-upload-form");
  const fileInputEl = document.getElementById("fileInput");

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
});