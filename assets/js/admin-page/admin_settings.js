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
  const unsavedModal = document.getElementById("unsaved-modal");
  const btnStaySave = document.getElementById("btn-stay-save");
  const btnDiscardLeave = document.getElementById("btn-discard-leave");
  let pendingUrl = "";

  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const href = this.getAttribute("href");

      // Ignore links that just change tabs (href="#") or javascript links
      if (href && href !== "#" && !href.startsWith("javascript")) {
        if (isFormDirty) {
          e.preventDefault();
          pendingUrl = href;

          unsavedModal.classList.add("active");
        }
      }
    });
  });

  // stay -> Close modal and let them continue editing
  if (btnStaySave) {
    btnStaySave.addEventListener("click", () => {
      unsavedModal.classList.remove("active");
    });
  }

  // discard and leave -> Close modal, go to the pending URL
  if (btnDiscardLeave) {
    btnDiscardLeave.addEventListener("click", () => {
      isFormDirty = false; // Turn off the dirtiness so the browser bouncer doesn't trigger
      window.location.href = pendingUrl; // Send them to the page they clicked earlier!
    });
  }

  // =========================================================
  // MANAGE VENUES MODAL LOGIC
  // =========================================================
  const venueModal = document.getElementById("venueModal");
  const formVenue = document.getElementById("form-venue");
  const catSelect = document.getElementById("vm-category");

  // Helper to hide/show specific fields based on category
  function toggleDynamicFields(category) {
    document.querySelectorAll(".vm-dynamic").forEach((el) => {
      el.style.display = "none";
      el.querySelector("input").removeAttribute("required");
    });

    let targetClass = "";
    if (category === "Event Hall") targetClass = ".vm-event";
    else if (category === "Hotel Room") targetClass = ".vm-hotel";
    else if (category === "Resort Villa") targetClass = ".vm-villa";

    if (targetClass) {
      document.querySelectorAll(targetClass).forEach((el) => {
        el.style.display = "block";
        el.querySelector("input").setAttribute("required", "true");
      });

      // Extra pax applies to both hotel and villa
      if (category !== "Event Hall") {
        const extraPax = document.getElementById("vm-extra-pax");
        extraPax.parentElement.style.display = "block";
        extraPax.setAttribute("required", "true");
      }
    }
  }

  // Listen to Category dropdown changes
  if (catSelect) {
    catSelect.addEventListener("change", function () {
      toggleDynamicFields(this.value);
    });
  }

  // Open "Add New Venue" Modal
  document.getElementById("btn-add-venue")?.addEventListener("click", () => {
    formVenue.reset();
    document.getElementById("vm-id").value = "";
    document.getElementById("vm-title").innerText = "Add New Venue";
    catSelect.disabled = false; 
    toggleDynamicFields("");

    venueModal.classList.add("active"); // FIX: Use standard active class
  });

  // Open "Edit Venue" Modal
  document.querySelectorAll(".btn-edit-venue").forEach((btn) => {
    btn.addEventListener("click", function () {
      const venueId = this.getAttribute("data-id");
      const venueData = window.allVenuesData.find((v) => v.id === venueId);

      if (!venueData) return alert("Error loading venue data.");

      document.getElementById("vm-title").innerText = "Edit Venue";
      document.getElementById("vm-id").value = venueData.id;
      document.getElementById("vm-name").value = venueData.name;
      document.getElementById("vm-status").value = venueData.status;
      document.getElementById("vm-desc").value = venueData.description || "";
      document.getElementById("vm-amenities").value = venueData.amenities || "";

      catSelect.value = venueData.category;
      catSelect.disabled = true;
      toggleDynamicFields(venueData.category);

      // Populate specific data
      if (venueData.category === "Event Hall") {
        document.getElementById("vm-base-cap").value = venueData.eh_base;
        document.getElementById("vm-max-cap").value = venueData.eh_max;
        document.getElementById("vm-eh-rate").value = venueData.base_rate;
      } else if (venueData.category === "Hotel Room") {
        document.getElementById("vm-base-cap").value = venueData.hr_base;
        document.getElementById("vm-max-cap").value = venueData.hr_max;
        document.getElementById("vm-hr-type").value = venueData.room_type;
        document.getElementById("vm-hr-rate").value = venueData.nightly_rate;
        document.getElementById("vm-extra-pax").value = venueData.hr_extra;
      } else if (venueData.category === "Resort Villa") {
        document.getElementById("vm-base-cap").value = venueData.vi_base;
        document.getElementById("vm-max-cap").value = venueData.vi_max;
        document.getElementById("vm-vi-day").value = venueData.day_rate;
        document.getElementById("vm-vi-night").value = venueData.overnight_rate;
        document.getElementById("vm-extra-pax").value = venueData.vi_extra;
      }

      venueModal.classList.add("active"); // FIX: Use standard active class
    });
  });

  // Close Modal
  document.getElementById("btn-close-vmodal")?.addEventListener("click", () => {
    venueModal.classList.remove("active"); // FIX: Use standard active class
  });
  
  // Close on background click
  window.addEventListener('click', (e) => {
    if (e.target === venueModal) venueModal.classList.remove('active');
  });

  // Handle Save Submission
  if (formVenue) {
    formVenue.addEventListener("submit", function (e) {
      e.preventDefault();

      const submitBtn = document.getElementById("btn-save-venue");
      const originalText = submitBtn.innerText;
      submitBtn.innerText = "Saving...";
      submitBtn.disabled = true;

      const formData = new FormData(this);
      // Because catSelect is disabled on edit, FormData skips it. We must manually append it.
      formData.append("category", catSelect.value);

      fetch("actions/admin/save_venue.php", {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.success) {
            alert(data.message);
            window.location.reload();
          } else {
            alert("Error: " + data.message);
            submitBtn.innerText = originalText;
            submitBtn.disabled = false;
          }
        })
        .catch((err) => {
          console.error(err);
          alert("Network error.");
          submitBtn.innerText = originalText;
          submitBtn.disabled = false;
        });
    });
  }
});
