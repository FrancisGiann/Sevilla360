/* ==========================================================================
   SEVILLA360 - Main JavaScript
   ========================================================================== */

document.addEventListener("DOMContentLoaded", function () {
  // --- 1. Organic Scroll Reveal Animation ---
  const reveals = document.querySelectorAll(".reveal");

  const revealOptions = {
    threshold: 0.15,
    rootMargin: "0px 0px -50px 0px",
  };

  const revealOnScroll = new IntersectionObserver(function (entries, observer) {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("active");
      observer.unobserve(entry.target); // Animates only once
    });
  }, revealOptions);

  reveals.forEach((reveal) => {
    revealOnScroll.observe(reveal);
  });

  // --- 2. Mobile Hamburger Menu Logic ---
  const hamburger = document.getElementById("hamburger");
  const navLinks = document.getElementById("nav-links");

  if (hamburger && navLinks) {
    const navItems = navLinks.querySelectorAll("a");

    // Toggle menu on hamburger click
    hamburger.addEventListener("click", () => {
      hamburger.classList.toggle("active");
      navLinks.classList.toggle("active");

      // Prevent background scrolling when menu is open
      if (navLinks.classList.contains("active")) {
        document.body.style.overflow = "hidden";
      } else {
        document.body.style.overflow = "auto";
      }
    });

    // Close menu when a link is clicked
    navItems.forEach((item) => {
      item.addEventListener("click", () => {
        hamburger.classList.remove("active");
        navLinks.classList.remove("active");
        document.body.style.overflow = "auto";
      });
    });
  }
});
