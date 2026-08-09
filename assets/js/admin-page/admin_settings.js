/**
 * SEVILLA360 - Admin Settings Controller
 */
document.addEventListener("DOMContentLoaded", () => {
    
  // =========================================================
  // 1. TAB SWITCHING LOGIC
  // =========================================================
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const tabLinks = document.querySelectorAll(".tab-link");
  const settingsPanels = document.querySelectorAll(".settings-panel");

  tabLinks.forEach((link) => {
    link.addEventListener("click", () => {
      tabLinks.forEach((t) => t.classList.remove("active"));
      settingsPanels.forEach((p) => p.classList.remove("active"));

      link.classList.add("active");
      const targetPanel = document.getElementById(link.getAttribute("data-target"));
      if (targetPanel) targetPanel.classList.add("active");
    });
  });

  // =========================================================
  // 2. TOAST NOTIFICATION UTILITY
  // =========================================================
  const toast = document.getElementById("settings-toast");
  let toastTimeout;

  function showToast() {
    clearTimeout(toastTimeout);
    toast.classList.add("show");
    toastTimeout = setTimeout(() => toast.classList.remove("show"), 3000);
  }

  // Visual simulation for static profile save button
  document.querySelectorAll("#panel-profile .save-btn").forEach((button) => {
    button.addEventListener("click", () => {
      const originalText = button.innerHTML;
      button.innerHTML = "Saving...";
      button.style.opacity = "0.8";
      button.style.pointerEvents = "none";

      setTimeout(() => {
        button.innerHTML = originalText;
        button.style.opacity = "1";
        button.style.pointerEvents = "auto";
        showToast();
      }, 600);
    });
  });

  // =========================================================
  // 3. SYSTEM PREFERENCES (AJAX SAVE)
  // =========================================================
  const btnSavePrefs = document.getElementById("btn-save-prefs");
  const formPrefs = document.getElementById("form-prefs");
  let isFormDirty = false;

  if (btnSavePrefs && formPrefs) {
    formPrefs.addEventListener("change", () => isFormDirty = true);

    btnSavePrefs.addEventListener("click", () => {
      const originalText = btnSavePrefs.innerHTML;
      btnSavePrefs.innerHTML = "Saving...";
      btnSavePrefs.style.opacity = "0.8";
      btnSavePrefs.style.pointerEvents = "none";

      const formData = new FormData(formPrefs);

      fetch("actions/admin/save_preferences.php", {
        method: "POST",
        headers: { "X-CSRF-Token": csrfToken },
        body: formData,
      })
      .then(res => res.text())
      .then(data => {
          btnSavePrefs.innerHTML = originalText;
          btnSavePrefs.style.opacity = "1";
          btnSavePrefs.style.pointerEvents = "auto";

          if (data.trim() === "Success") {
            showToast();
            isFormDirty = false;
          } else {
            alert(data);
          }
      })
      .catch(error => {
          alert("System error. Could not save settings.");
          console.error(error);
      });
    });
  }

  // =========================================================
  // 4. UNSAVED CHANGES MODAL PROTECTION
  // =========================================================
  const unsavedModal = document.getElementById("unsaved-modal");
  let pendingUrl = "";

  window.addEventListener("beforeunload", function (e) {
    if (isFormDirty) {
      const msg = "You have unsaved changes. Are you sure you want to leave?";
      e.returnValue = msg;
      return msg;
    }
  });

  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href && href !== "#" && !href.startsWith("javascript") && isFormDirty) {
          e.preventDefault();
          pendingUrl = href;
          unsavedModal.classList.add("active");
      }
    });
  });

  document.getElementById("btn-stay-save")?.addEventListener("click", () => unsavedModal.classList.remove("active"));
  document.getElementById("btn-discard-leave")?.addEventListener("click", () => {
      isFormDirty = false; 
      window.location.href = pendingUrl;
  });

  // =========================================================
  // 5. MANAGE VENUES MODAL & AJAX
  // =========================================================
  const venueModal = document.getElementById("venueModal");
  const formVenue = document.getElementById("form-venue");
  const catSelect = document.getElementById("vm-category");

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

      if (category !== "Event Hall") {
        const extraPax = document.getElementById("vm-extra-pax");
        extraPax.parentElement.style.display = "block";
        extraPax.setAttribute("required", "true");
      }
    }
  }

  if (catSelect) {
    catSelect.addEventListener("change", function () { toggleDynamicFields(this.value); });
  }

  document.getElementById("btn-add-venue")?.addEventListener("click", () => {
    formVenue.reset();
    document.getElementById("vm-id").value = "";
    document.getElementById("vm-title").innerText = "Add New Venue";
    catSelect.disabled = false; 
    toggleDynamicFields("");
    venueModal.classList.add("active");
  });

  document.querySelectorAll(".btn-edit-venue").forEach((btn) => {
    btn.addEventListener("click", function () {
      const venueData = window.allVenuesData.find((v) => v.id === this.getAttribute("data-id"));
      if (!venueData) return alert("Error loading venue data.");

      document.getElementById("vm-title").innerText = "Edit Venue";
      document.getElementById("vm-id").value = venueData.id;
      document.getElementById("vm-name").value = venueData.name;
      document.getElementById("vm-status").value = venueData.status;
      document.getElementById("vm-desc").value = venueData.description || "";
      document.getElementById("vm-amenities").value = venueData.amenities || "";

      catSelect.value = venueData.category;
      catSelect.disabled = true; // Protects DB relational integrity
      toggleDynamicFields(venueData.category);

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

      venueModal.classList.add("active");
    });
  });

  document.getElementById("btn-close-vmodal")?.addEventListener("click", () => venueModal.classList.remove("active"));
  window.addEventListener('click', (e) => { if (e.target === venueModal) venueModal.classList.remove('active'); });

  if (formVenue) {
    formVenue.addEventListener("submit", function (e) {
      e.preventDefault();
      const submitBtn = document.getElementById("btn-save-venue");
      const originalText = submitBtn.innerText;
      submitBtn.innerText = "Saving...";
      submitBtn.disabled = true;

      const formData = new FormData(this);
      formData.append("category", catSelect.value); // Re-append because disabled selects aren't sent

      fetch("actions/admin/save_venue.php", { 
          method: "POST", 
          headers: { "X-CSRF-Token": csrfToken },
          body: formData 
      })
      .then(res => res.json())
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
      .catch(err => {
          console.error(err);
          alert("Network error.");
          submitBtn.innerText = originalText;
          submitBtn.disabled = false;
      });
    });
  }

  // =========================================================
  // 6. VENUE TABLE FILTERING LOGIC
  // =========================================================
  const venueFilters = document.querySelectorAll('#venueFilters .venue-filter-btn');
  const venueRows = document.querySelectorAll('.venue-row');

  if (venueFilters.length > 0) {
      venueFilters.forEach(btn => {
          btn.addEventListener('click', () => {
              venueFilters.forEach(f => f.classList.remove('active'));
              btn.classList.add('active');
              
              const filter = btn.getAttribute('data-filter');
              
              venueRows.forEach(row => {
                  if (filter === 'all' || row.getAttribute('data-category') === filter) {
                      row.style.display = ''; 
                  } else {
                      row.style.display = 'none'; 
                  }
              });
          });
      });
  }
});