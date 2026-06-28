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
});
