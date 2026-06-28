/**
 * SEVILLA360 - Admin User Management Scripts
 */

document.addEventListener("DOMContentLoaded", () => {
  // --- 1. Tab Switching Logic ---
  const tabs = document.querySelectorAll(".um-tab");
  const tables = document.querySelectorAll(".um-table");

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      // Remove active classes from all tabs and tables
      tabs.forEach((t) => t.classList.remove("active"));
      tables.forEach((tbl) => tbl.classList.remove("active"));

      // Add active class to clicked tab
      tab.classList.add("active");

      // Add active class to target table
      const targetTableId = tab.getAttribute("data-target");
      document.getElementById(targetTableId).classList.add("active");
    });
  });

  // --- 2. Modal Logic ---
  const staffModal = document.getElementById("staffModal");
  const historyModal = document.getElementById("historyModal");

  // Buttons to open modals
  const openStaffBtns = document.querySelectorAll(
    "#openAddStaffBtn, .btn-staff-modal",
  );
  const openHistoryBtns = document.querySelectorAll(".btn-history-modal");

  // Buttons to close modals
  const closeStaffBtn = document.querySelector(".close-staff-modal");
  const closeHistoryBtn = document.querySelector(".close-history-modal");

  // Open Staff Modal
  openStaffBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      staffModal.classList.add("active");
    });
  });

  // Close Staff Modal (Cancel button)
  if (closeStaffBtn) {
    closeStaffBtn.addEventListener("click", () => {
      staffModal.classList.remove("active");
    });
  }

  // Open History Modal
  openHistoryBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      historyModal.classList.add("active");
    });
  });

  // Close History Modal (Close button)
  if (closeHistoryBtn) {
    closeHistoryBtn.addEventListener("click", () => {
      historyModal.classList.remove("active");
    });
  }

  // Close Modals when clicking outside the content area (on the dark overlay)
  window.addEventListener("click", (e) => {
    if (e.target === staffModal) {
      staffModal.classList.remove("active");
    }
    if (e.target === historyModal) {
      historyModal.classList.remove("active");
    }
  });
});
