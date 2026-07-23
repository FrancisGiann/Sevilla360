/**
 * SEVILLA360 - Admin Dashboard Charts
 * Uses Chart.js and maps exactly to the master styling palette
 */

document.addEventListener("DOMContentLoaded", () => {
  // Theme Colors array referencing style.css & minimal palette
  const colors = {
    gold: "#d6a870",
    beige: "#fdf2e2",
    dark: "#2a2522",
    green: "#88a096", // Muted green
    red: "#c27c7c", // Muted red
    softBlue: "#8ea4b5", // Complementary cool tone
    grid: "rgba(42, 37, 34, 0.05)",
  };

  // Global Defaults for Typography
  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color = "#4a4440"; // var(--color-dark-light)

  /* =========================================
       1. Revenue Bar Chart
       ========================================= */
  const ctxRevenue = document.getElementById("revenueChart").getContext("2d");
  new Chart(ctxRevenue, {
    type: "bar",
    data: {
      labels: ["May", "Jun", "Jul", "Aug", "Sep", "Oct"],
      datasets: [
        {
          label: "Revenue ($)",
          data: [12500, 15000, 18200, 14000, 21000, 24500],
          backgroundColor: colors.gold,
          borderRadius: 4,
          barThickness: 30,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.dark,
          padding: 10,
          cornerRadius: 4,
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            color: colors.grid,
            drawBorder: false,
          },
          border: { display: false },
        },
        x: {
          grid: { display: false },
          border: { display: false },
        },
      },
    },
  });

  /* =========================================
       2. Booking Status Pie Chart
       ========================================= */
  const ctxStatus = document.getElementById("statusChart").getContext("2d");
  new Chart(ctxStatus, {
    type: "pie",
    data: {
      labels: ["Confirmed", "Pending", "Cancelled"],
      datasets: [
        {
          data: [65, 25, 10],
          backgroundColor: [colors.green, colors.gold, colors.red],
          borderWidth: 0,
          hoverOffset: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            padding: 20,
            usePointStyle: true,
            pointStyle: "circle",
          },
        },
      },
    },
  });

  /* =========================================
       3. Occupancy Donut Chart
       ========================================= */
  const ctxOccupancy = document
    .getElementById("occupancyChart")
    .getContext("2d");
  new Chart(ctxOccupancy, {
    type: "doughnut",
    data: {
      labels: ["Grand Hall", "Garden", "Studio A"],
      datasets: [
        {
          data: [50, 30, 20],
          backgroundColor: [colors.dark, colors.gold, colors.softBlue],
          borderWidth: 0,
          hoverOffset: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: "70%",
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            padding: 20,
            usePointStyle: true,
            pointStyle: "circle",
          },
        },
      },
    },
  });
});