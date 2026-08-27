/* ==========================================================================
   SEVILLA360 - Main JavaScript
   ========================================================================== */

// reset scroll position on page load to avoid browser remembering previous scroll position
if (document.querySelector('.hero')) {
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
    window.scrollTo(0, 0);
}

document.addEventListener("DOMContentLoaded", function () {
  
  // --- 1. Organic Scroll Reveal Animation ---
  const homePage = document.querySelector(".idx-page");
  const reveals = document.querySelectorAll(".reveal");
  if (homePage && homePage.dataset.revealInitialized !== "true") {
    homePage.dataset.revealInitialized = "true";

    // The default CSS state is fully visible. Gate the authored homepage
    // treatment only after its observer is ready, so a failed script or an
    // older browser never leaves content hidden.
    if (reveals.length && "IntersectionObserver" in window) {
      const revealOptions = {
        threshold: 0.15,
        rootMargin: "0px 0px -50px 0px",
      };

      const revealOnScroll = new IntersectionObserver(function (entries, observer) {
        entries.forEach((entry) => {
          if (!entry.isIntersecting || entry.target.dataset.revealSeen === "true") return;
          entry.target.dataset.revealSeen = "true";
          entry.target.classList.add("active");
          observer.unobserve(entry.target);
        });
      }, revealOptions);

      const isInInitialViewport = (element) => {
        const rect = element.getBoundingClientRect();
        const rootBottom = Math.max(0, window.innerHeight - 50);
        const visibleHeight = Math.min(rect.bottom, rootBottom) - Math.max(rect.top, 0);
        return visibleHeight > 0 && visibleHeight / Math.max(rect.height, 1) >= 0.15;
      };

      reveals.forEach((reveal) => {
        revealOnScroll.observe(reveal);
        // Anchor restoration can place a section in view before the observer's
        // first async callback. Mark it now to avoid a one-frame hidden flash.
        if (isInInitialViewport(reveal)) {
          reveal.dataset.revealSeen = "true";
          reveal.classList.add("active");
          revealOnScroll.unobserve(reveal);
        }
      });

      homePage.classList.add("reveal-ready");
    } else {
      // Keep the existing active contract when IntersectionObserver is not
      // available, without relying on animation for content visibility.
      reveals.forEach((reveal) => reveal.classList.add("active"));
    }
  }

  // --- 2. Homepage section-aware navigation ---
  // Header markup is shared by every route; only the homepage owns a
  // scroll-spy. Real pages retain their server-rendered active route.
  const navLinks = Array.from(document.querySelectorAll("[data-nav-target]"));
  const sectionKeys = ["about", "experiences", "accommodations"];

  if (homePage && navLinks.length) {
    const sections = sectionKeys
      .map((key) => document.getElementById(key))
      .filter(Boolean);
    let requestedSection = null;
    let scrollFrame = null;

    const setActiveNav = (target) => {
      const activeTarget = target === "showroom" ? "showroom" : target || "home";
      navLinks.forEach((link) => {
        const active = link.dataset.navTarget === activeTarget;
        link.classList.toggle("active", active);
        if (active) link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
      });
    };

    const sectionFromUrl = () => {
      const hash = window.location.hash.replace(/^#/, "");
      return sectionKeys.includes(hash) ? hash : "home";
    };

    const sectionAtViewport = () => {
      // A section becomes active once it reaches the visual reading line
      // below the fixed header. This remains stable for short sections.
      const readingLine = Math.min(window.innerHeight * 0.34, 300);
      let current = "home";
      sections.forEach((section) => {
        if (section.getBoundingClientRect().top <= readingLine) current = section.id;
      });
      return current;
    };

    const updateFromScroll = () => {
      if (requestedSection) return;
      setActiveNav(sectionAtViewport());
    };

    const scheduleScrollUpdate = () => {
      if (scrollFrame) return;
      scrollFrame = window.requestAnimationFrame(() => {
        scrollFrame = null;
        updateFromScroll();
      });
    };

    const clearRequestedSection = () => {
      requestedSection = null;
      scheduleScrollUpdate();
    };

    const handleHashChange = () => {
      const target = sectionFromUrl();
      requestedSection = target === "home" && !window.location.hash ? null : target;
      setActiveNav(target);
      // Allow the browser's anchor positioning to finish before handing
      // control back to the scroll-spy.
      window.setTimeout(clearRequestedSection, 450);
    };

    navLinks.forEach((link) => {
      link.addEventListener("click", () => {
        const target = link.dataset.navTarget;
        if (target && target !== "showroom") {
          requestedSection = target;
          setActiveNav(target);
          window.setTimeout(clearRequestedSection, 500);
        }
      });
    });

    window.addEventListener("scroll", scheduleScrollUpdate, { passive: true });
    window.addEventListener("resize", scheduleScrollUpdate, { passive: true });
    window.addEventListener("hashchange", handleHashChange);
    window.addEventListener("popstate", handleHashChange);

    // Hash navigation can be restored after DOMContentLoaded, so make the
    // first state explicit and then let the scroll-spy take over.
    handleHashChange();
  }

});
