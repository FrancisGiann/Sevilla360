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
      observer.unobserve(entry.target); 
    });
  }, revealOptions);

  reveals.forEach((reveal) => {
    revealOnScroll.observe(reveal);
  });

  // --- 2. Mobile Hamburger Menu Logic ---
  const hamburger = document.getElementById("hamburger");
  const navLinks = document.getElementById("nav-links");

  if (hamburger && navLinks) {
    // Toggle main menu
    hamburger.addEventListener("click", function () {
      this.classList.toggle("active");
      navLinks.classList.toggle("active");

      // Prevent background scrolling
      if (navLinks.classList.contains("active")) {
        document.body.style.overflow = "hidden";
      } else {
        document.body.style.overflow = "auto";
      }
    });

    // Close menu when clicking standard links
    const navItems = navLinks.querySelectorAll("a");
    navItems.forEach((item) => {
      item.addEventListener("click", () => {
        hamburger.classList.remove("active");
        navLinks.classList.remove("active");
        document.body.style.overflow = "auto";
      });
    });
  }

  // --- 3. Mobile Dropdown Toggle (For the new My Account button) ---
  const dropdownBtn = document.querySelector(".btn-user-menu");
  const dropdownMenu = document.querySelector(".nav-dropdown-menu");

  if (dropdownBtn && dropdownMenu) {
    dropdownBtn.addEventListener("click", function (e) {
      // Only run this on mobile/tablet view
      if (window.innerWidth <= 992) {
        e.preventDefault(); // Stop default button behavior
        dropdownMenu.classList.toggle("active-mobile");
        
        // Flip the arrow icon
        const arrow = this.querySelector(".dropdown-arrow");
        if(arrow) {
          arrow.style.transform = dropdownMenu.classList.contains("active-mobile") ? "rotate(180deg)" : "rotate(0deg)";
        }
      }
    });
  }

});