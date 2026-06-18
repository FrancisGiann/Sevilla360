/* ==========================================================================
   SEVILLA360 - Admin JavaScript Logic
   ========================================================================== */

document.addEventListener("DOMContentLoaded", () => {
  // 1. Sidebar Navigation Logic (SPA View Switching)
  const navItems = document.querySelectorAll(
    ".sidebar-nav .nav-item[data-target]",
  );
  const viewSections = document.querySelectorAll(".view-section");
  const pageTitle = document.getElementById("page-title");

  navItems.forEach((item) => {
    item.addEventListener("click", (e) => {
      e.preventDefault();

      // Update Active Link
      navItems.forEach((nav) => nav.classList.remove("active"));
      item.classList.add("active");

      // Switch View
      const targetId = item.getAttribute("data-target");
      viewSections.forEach((section) => {
        section.classList.remove("active");
        if (section.id === targetId) {
          section.classList.add("active");
        }
      });

      // Update Page Title
      pageTitle.textContent = item.textContent;

      // Trigger Chart Resize if Overview is clicked
      if (targetId === "view-overview") {
        setTimeout(() => {
          window.dispatchEvent(new Event("resize"));
        }, 100);
      }
    });
  });

  // 2. Chart.js Initialization
  const colorGold = "#D6A870";
  const colorDark = "#2A2522";
  const colorBeige = "#FDF2E2";
  const colorGreen = "#2ecc71";
  const colorRed = "#e06666";

  // Revenue Bar Chart
  const ctxRevenue = document.getElementById("revenueChart").getContext("2d");
  new Chart(ctxRevenue, {
    type: "bar",
    data: {
      labels: ["May", "Jun", "Jul", "Aug", "Sep", "Oct"],
      datasets: [
        {
          label: "Revenue (PHP)",
          data: [120000, 190000, 150000, 220000, 310000, 450000],
          backgroundColor: colorGold,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, grid: { color: "rgba(0,0,0,0.05)" } },
        x: { grid: { display: false } },
      },
    },
  });

  // Booking Status Pie Chart
  const ctxStatus = document.getElementById("statusChart").getContext("2d");
  new Chart(ctxStatus, {
    type: "pie",
    data: {
      labels: ["Paid", "Pending", "Cancelled"],
      datasets: [
        {
          data: [65, 20, 15],
          backgroundColor: [colorGreen, colorGold, colorRed],
          borderWidth: 0,
        },
      ],
    },
    options: { responsive: true, plugins: { legend: { position: "bottom" } } },
  });

  // Occupancy Donut Chart
  const ctxOccupancy = document
    .getElementById("occupancyChart")
    .getContext("2d");
  new Chart(ctxOccupancy, {
    type: "doughnut",
    data: {
      labels: ["Event Hall", "Hotel", "Villa"],
      datasets: [
        {
          data: [40, 35, 25],
          backgroundColor: [colorDark, colorGold, "#888888"],
          borderWidth: 0,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { position: "bottom" }, cutout: "70%" },
    },
  });

  // 3. Walk-in Entry Logic

  // Walk-in Tabs
  const walkinTabs = document.querySelectorAll(".admin-tab-btn");
  const walkinContents = document.querySelectorAll("#view-walkin .tab-content");

  walkinTabs.forEach((btn) => {
    btn.addEventListener("click", () => {
      walkinTabs.forEach((t) => t.classList.remove("active"));
      btn.classList.add("active");

      const target = btn.getAttribute("data-admintab");
      walkinContents.forEach((content) => {
        content.classList.remove("active");
        if (content.id === target) content.classList.add("active");
      });
    });
  });

  // Payment Method Selection
  const payBtns = document.querySelectorAll(".pay-btn");
  const refNoGroup = document.getElementById("ref-no-group");

  payBtns.forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      payBtns.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");

      // Show Ref No field if not CASH
      if (btn.textContent.trim() !== "CASH") {
        refNoGroup.style.display = "block";
      } else {
        refNoGroup.style.display = "none";
      }
    });
  });

  // 4. Maintenance Tabs Logic
  const maintTabs = document.querySelectorAll(".m-tab");
  maintTabs.forEach((btn) => {
    btn.addEventListener("click", () => {
      maintTabs.forEach((t) => t.classList.remove("active"));
      btn.classList.add("active");
      // Logic to reload calendar data based on area goes here
    });
  });

  // 5. Dummy Calendar Injector (For Visuals in Admin Modals & Maintenance)
  function injectDummyCalendar(gridId, days) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = "";
    for (let i = 1; i <= days; i++) {
      const cell = document.createElement("button");
      cell.className = "cal-day-cell";
      cell.textContent = i;
      // Randomly block some dates
      if (Math.random() > 0.8) cell.classList.add("booked");
      grid.appendChild(cell);
    }
  }

  // Inject into Reschedule Modal
  injectDummyCalendar("resched-cal-grid", 30);
  // Inject into Maintenance
  injectDummyCalendar("maint-cal-grid", 31);
});

// Modal Global Functions
function openModal(modalId) {
  document.getElementById(modalId).classList.add("active");
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.remove("active");
}
