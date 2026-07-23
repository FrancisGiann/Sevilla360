document.addEventListener("DOMContentLoaded", () => {
  // 1. Tab Switching Logic
  const tabLinks = document.querySelectorAll(".tab-link");
  const settingsPanels = document.querySelectorAll(".settings-panel");

  tabLinks.forEach((link) => {
    link.addEventListener("click", () => {
      // Remove active classes from all tabs and panels
      tabLinks.forEach((t) => t.classList.remove("active"));
      settingsPanels.forEach((p) => p.classList.remove("active"));

      // Add active class to clicked tab
      link.classList.add("active");

      // Find target panel and activate it
      const targetId = link.getAttribute("data-target");
      const targetPanel = document.getElementById(targetId);

      if (targetPanel) {
        targetPanel.classList.add("active");
      }
    });
  });

  // 2. Save Buttons & Toast Notification Logic
  const saveButtons = document.querySelectorAll(".save-btn");
  const toast = document.getElementById("settings-toast");
  let toastTimeout;

  saveButtons.forEach((button) => {
    button.addEventListener("click", () => {
      // Add loading effect to button (Optional aesthetic)
      const originalText = button.innerHTML;
      button.innerHTML = "Saving...";
      button.style.opacity = "0.8";
      button.style.pointerEvents = "none";

      // Simulate a brief API call delay
      setTimeout(() => {
        // Restore button
        button.innerHTML = originalText;
        button.style.opacity = "1";
        button.style.pointerEvents = "auto";

        // Show Toast Notification
        showToast();
      }, 600);
    });
  });

  function showToast() {
    // Clear any existing timeouts to prevent overlapping fades
    clearTimeout(toastTimeout);

    // Add 'show' class to trigger CSS transition
    toast.classList.add("show");

    // Hide after 3 seconds
    toastTimeout = setTimeout(() => {
      toast.classList.remove("show");
    }, 3000);
  }
  // =========================================================
  //                  Save System Preferences
  // =========================================================
  const btnSavePrefs = document.getElementById("btn-save-prefs");
  const formPrefs = document.getElementById("form-prefs");

  // check if the form has unsaved changes
  let isFormDirty = false;

  if (btnSavePrefs && formPrefs) {
    // listen for changes in the form to set the dirty flag
    formPrefs.addEventListener("change", () => {
      isFormDirty = true;
    });

    btnSavePrefs.addEventListener("click", () => {
      const originalText = btnSavePrefs.innerHTML;
      btnSavePrefs.innerHTML = "Saving...";
      btnSavePrefs.style.opacity = "0.8";
      btnSavePrefs.style.pointerEvents = "none";

      const formData = new FormData(formPrefs);

      fetch("/sevilla360/actions/admin/save_preferences.php", {
        method: "POST",
        body: formData,
      })
        .then((response) => response.text())
        .then((data) => {
          btnSavePrefs.innerHTML = originalText;
          btnSavePrefs.style.opacity = "1";
          btnSavePrefs.style.pointerEvents = "auto";

          if (data.trim() === "Success") {
            showToast();

            // reset the dirty flag since changes have been saved
            isFormDirty = false;
          } else {
            alert(data);
          }
        })
        .catch((error) => {
          alert("System error. Could not save settings.");
          console.error(error);
        });
    });
  }

  // warning before leaving the page if there are unsaved changes
  window.addEventListener("beforeunload", function (e) {
    if (isFormDirty) {
      const confirmationMessage =
        "You have unsaved changes. Are you sure you want to leave?";
      e.returnValue = confirmationMessage; 
      return confirmationMessage; 
    }
  });

  // =========================================================
  // CUSTOM UNSAVED MODAL LOGIC
  // =========================================================
  const unsavedModal = document.getElementById('unsaved-modal');
  const btnStaySave = document.getElementById('btn-stay-save');
  const btnDiscardLeave = document.getElementById('btn-discard-leave');
  let pendingUrl = ''; 

  document.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', function(e) {
          const href = this.getAttribute('href');
          
          // Ignore links that just change tabs (href="#") or javascript links
          if (href && href !== '#' && !href.startsWith('javascript')) {
              
              if (isFormDirty) {
                  e.preventDefault(); 
                  pendingUrl = href;  
                  
                  unsavedModal.classList.add('active'); 
              }
          }
      });
  });

  // stay -> Close modal and let them continue editing
  if (btnStaySave) {
      btnStaySave.addEventListener('click', () => {
          unsavedModal.classList.remove('active');
      });
  }

  // discard and leave -> Close modal, go to the pending URL
  if (btnDiscardLeave) {
      btnDiscardLeave.addEventListener('click', () => {
          isFormDirty = false; // Turn off the dirtiness so the browser bouncer doesn't trigger
          window.location.href = pendingUrl; // Send them to the page they clicked earlier!
      });
  }
});
