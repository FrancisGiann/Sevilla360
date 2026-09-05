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

  // --- Public venue discovery: no-autoplay carousels and shared details modal ---
  const catalog = window.publicVenueCatalog || {};
  const modal = document.getElementById('idx-venue-modal');
  let activeVenue = null;
  let activeImageIndex = 0;
  let previousFocus = null;
  let modalCalendar = null;
  const money = value => {
    if (value === null || value === undefined || value === '') return 'Rate on request';
    const amount = Number(value);
    return Number.isFinite(amount) ? '₱' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Rate on request';
  };
  const tabFor = category => category === 'Event Hall' ? 'event-hall' : (category === 'Hotel Room' ? 'hotel-rooms' : 'resort-villa');
  const hasRate = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
  const hotelRateText = (venue, showStarting = true) => {
    if (!hasRate(venue.rate) || !hasRate(venue.max_nightly_rate)) return 'Rate on request';
    const minimum = money(venue.rate);
    const maximum = money(venue.max_nightly_rate);
    return Number(venue.rate) === Number(venue.max_nightly_rate)
      ? minimum + ' / night'
      : (showStarting ? 'From ' : '') + minimum + '–' + maximum + ' / night';
  };
  const ratingText = venue => Number(venue.rating_count || 0) > 0
    ? `${Number(venue.rating_average || 0).toFixed(1)} out of 5 · ${venue.rating_count} review${Number(venue.rating_count) === 1 ? '' : 's'}`
    : 'No ratings yet';
  const rateText = (venue, showStarting = true) => {
    if (venue.category === 'Resort Villa') return 'Day ' + money(venue.rate) + ' · Overnight ' + money(venue.overnight_rate);
    if (venue.category === 'Hotel Room') return hotelRateText(venue, showStarting);
    return hasRate(venue.rate)
      ? 'Base rate ' + money(venue.rate) + ' · final quote after consultation'
      : 'Rate on request · final quote after consultation';
  };
  const bookingUrl = (venue, dates) => {
    const params = new URLSearchParams({ tab: tabFor(venue.category), category: venue.category, venue_name: venue.venue_name });
    if (venue.venue_id) params.set('venue_id', venue.venue_id);
    if (venue.room_type) params.set('room_type', venue.room_type);
    if (dates && dates.startDate) params.set('start_date', dates.startDate);
    if (dates && dates.endDate) params.set('end_date', dates.endDate);
    return 'booking.php?' + params.toString();
  };
  const makeCard = venue => {
    const article = document.createElement('article');
    article.className = 'idx-catalog-card';
    const image = document.createElement('img');
    image.src = (venue.images && venue.images[0]) || 'assets/img/placeholder.jpg';
    image.alt = venue.venue_name || venue.room_type || 'Sevilla360 venue';
    image.loading = 'lazy';
    article.appendChild(image);
    const body = document.createElement('div'); body.className = 'idx-catalog-card-body';
    const title = document.createElement('h4'); title.textContent = venue.venue_name || venue.room_type || 'Venue';
    const kind = document.createElement('p'); kind.className = 'idx-catalog-card-category';
    kind.textContent = venue.room_type ? venue.category + ' · ' + venue.room_type : venue.category;
    const rate = document.createElement('p'); rate.className = 'idx-catalog-card-rate'; rate.textContent = rateText(venue);
    const rating = document.createElement('p'); rating.className = 'idx-catalog-card-rating'; rating.textContent = ratingText(venue);
    const facts = document.createElement('p'); facts.className = 'idx-catalog-card-facts'; facts.textContent = Object.values(venue.facts || {}).slice(0, 3).join(' · ');
    const actions = document.createElement('div'); actions.className = 'idx-catalog-card-actions';
    const details = document.createElement('button'); details.type = 'button'; details.className = 'idx-btn idx-btn-outline-dark'; details.textContent = 'View details';
    details.addEventListener('click', () => openVenueModal(venue, details));
    const book = document.createElement('a'); book.className = 'idx-btn idx-btn-gold'; book.href = bookingUrl(venue);
    book.textContent = venue.category === 'Event Hall' ? 'Start inquiry' : 'Choose dates';
    actions.append(details, book); body.append(title, kind, rate, rating, facts, actions); article.appendChild(body);
    return article;
  };
  const updateCarousel = (section, cards, index) => {
    const selected = (index + cards.length) % cards.length;
    const track = section.querySelector('.idx-catalog-track');
    if (track) track.style.transform = 'translateX(-' + (selected * 100) + '%)';
    const position = section.querySelector('.idx-carousel-position');
    if (position) position.textContent = (selected + 1) + ' of ' + cards.length;
    const controls = section.querySelector('.idx-carousel-controls');
    const shell = section.querySelector('.idx-catalog-shell');
    const isSingle = cards.length === 1;
    if (controls) controls.toggleAttribute('hidden', isSingle);
    if (shell) shell.classList.toggle('idx-catalog-shell-single', isSingle);
    section.dataset.carouselState = isSingle ? 'single' : 'multi';
    [section.querySelector('.idx-carousel-prev'), section.querySelector('.idx-carousel-next')].forEach(control => {
      if (control) control.disabled = cards.length <= 1;
    });
    cards.forEach((item, itemIndex) => {
      const isVisible = itemIndex === selected;
      item.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
      item.querySelectorAll('button, a[href]').forEach(control => {
        if (isVisible) {
          const priorTabIndex = control.dataset.carouselTabindex;
          if (priorTabIndex === '') control.removeAttribute('tabindex');
          else if (priorTabIndex !== undefined) control.setAttribute('tabindex', priorTabIndex);
        } else {
          if (control.dataset.carouselTabindex === undefined) control.dataset.carouselTabindex = control.getAttribute('tabindex') || '';
          control.setAttribute('tabindex', '-1');
        }
      });
    });
    section.dataset.carouselIndex = String(selected);
  };
  document.querySelectorAll('.idx-catalog-section').forEach(section => {
    const category = section.dataset.catalogCategory;
    const venues = Array.isArray(catalog[category]) ? catalog[category] : [];
    const track = section.querySelector('.idx-catalog-track');
    if (!track || !venues.length) {
      section.querySelector('.idx-catalog-shell')?.classList.add('idx-catalog-shell-empty');
      section.querySelector('.idx-catalog-empty')?.removeAttribute('hidden');
      section.querySelector('.idx-carousel-controls')?.setAttribute('hidden', '');
      return;
    }
    const cards = venues.map(venue => track.appendChild(makeCard(venue)));
    let index = 0;
    const move = delta => { index = (index + delta + cards.length) % cards.length; updateCarousel(section, cards, index); };
    section.querySelector('.idx-carousel-prev')?.addEventListener('click', () => move(-1));
    section.querySelector('.idx-carousel-next')?.addEventListener('click', () => move(1));
    track.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') { event.preventDefault(); move(-1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); move(1); }
    });
    let touchStart = null;
    track.addEventListener('touchstart', event => { touchStart = event.changedTouches[0]?.clientX || null; }, { passive: true });
    track.addEventListener('touchend', event => {
      if (touchStart === null) return;
      const delta = (event.changedTouches[0]?.clientX || touchStart) - touchStart;
      if (Math.abs(delta) > 40) move(delta < 0 ? 1 : -1);
      touchStart = null;
    }, { passive: true });
    updateCarousel(section, cards, index);
  });
  const dateValue = date => date ? date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0') : '';
  const modalDates = () => modalCalendar && modalCalendar.startDate ? { startDate: dateValue(modalCalendar.startDate), endDate: dateValue(modalCalendar.endDate) } : null;
  const updateContinue = () => {
    const link = modal?.querySelector('.idx-modal-continue');
    if (link && activeVenue) link.href = bookingUrl(activeVenue, modalDates());
  };
  const setModalImage = index => {
    if (!activeVenue) return;
    const images = activeVenue.images && activeVenue.images.length ? activeVenue.images : ['assets/img/placeholder.jpg'];
    activeImageIndex = (index + images.length) % images.length;
    const image = document.getElementById('idx-modal-image');
    if (image) { image.src = images[activeImageIndex]; image.alt = (activeVenue.venue_name || 'Venue') + ' image ' + (activeImageIndex + 1); }
    modal?.querySelectorAll('.idx-modal-thumbnail').forEach((thumb, i) => thumb.classList.toggle('is-active', i === activeImageIndex));
    modal?.querySelector('.idx-modal-thumbnail.is-active')?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
  };
  const openVenueModal = (venue, source) => {
    if (!modal) return;
    activeVenue = venue; previousFocus = source || document.activeElement; activeImageIndex = 0;
    const title = document.getElementById('idx-modal-title');
    const category = modal.querySelector('.idx-modal-category');
    const rate = modal.querySelector('.idx-modal-rate');
    const rating = document.getElementById('idx-modal-rating');
    const description = modal.querySelector('.idx-modal-description');
    const facts = modal.querySelector('.idx-modal-facts');
    const amenities = modal.querySelector('.idx-modal-amenities');
    const checkoutBoundaryLegend = modal.querySelector('[data-calendar-legend="checkout-boundary"]');
    if (title) title.textContent = venue.venue_name || venue.room_type || 'Venue details';
    if (category) category.textContent = venue.room_type ? venue.category + ' · ' + venue.room_type : venue.category;
    if (rate) rate.textContent = rateText(venue, false);
    if (rating) rating.textContent = ratingText(venue);
    const reviewsList = document.getElementById('idx-modal-reviews-list');
    if (reviewsList) {
      reviewsList.replaceChildren();
      const loading = document.createElement('p'); loading.textContent = 'Loading reviews…'; reviewsList.appendChild(loading);
      fetch('actions/public/get_venue_reviews.php?venue_key=' + encodeURIComponent(venue.review_key || venue.key), {headers: {'X-Sevilla-Background': 'true'}})
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Review request failed')))
        .then(data => {
          if (!activeVenue || activeVenue !== venue) return;
          if (rating && data && data.rating_count !== undefined) {
            rating.textContent = ratingText({...venue, rating_average: data.rating_average, rating_count: data.rating_count});
          }
          reviewsList.replaceChildren();
          if (!Array.isArray(data.reviews) || !data.reviews.length) { const empty = document.createElement('p'); empty.textContent = 'No ratings yet'; reviewsList.appendChild(empty); return; }
          data.reviews.forEach(review => {
            const item = document.createElement('article'); item.className = 'idx-modal-review';
            const author = document.createElement('strong'); author.textContent = `${review.reviewer} · ${Number(review.rating || 0).toFixed(1)} out of 5`;
            const text = document.createElement('p'); text.textContent = review.review_text || '';
            item.append(author, text); reviewsList.appendChild(item);
          });
        }).catch(() => {
          if (!activeVenue || activeVenue !== venue || !reviewsList) return;
          reviewsList.replaceChildren(); const empty = document.createElement('p'); empty.textContent = 'No ratings yet'; reviewsList.appendChild(empty);
        });
    }
    if (checkoutBoundaryLegend) checkoutBoundaryLegend.hidden = venue.category !== 'Hotel Room';
    if (description) description.textContent = venue.description || 'Details will be confirmed by the resort team.';
    if (facts) {
      facts.replaceChildren();
      Object.entries(venue.facts || {}).forEach(([label, value]) => {
        const item = document.createElement('div'); item.className = 'idx-modal-fact';
        const name = document.createElement('span'); name.textContent = label;
        const amount = document.createElement('strong'); amount.textContent = value; item.append(name, amount); facts.appendChild(item);
      });
    }
    if (amenities) {
      amenities.replaceChildren();
      const items = String(venue.amenities || '').split(/[;,\n]+/).map(item => item.trim()).filter(Boolean);
      (items.length ? items : ['No amenities listed.']).forEach(item => { const li = document.createElement('li'); li.textContent = item; amenities.appendChild(li); });
    }
    const thumbnails = modal.querySelector('.idx-modal-thumbnails');
    if (thumbnails) {
      thumbnails.replaceChildren();
      (venue.images && venue.images.length ? venue.images : ['assets/img/placeholder.jpg']).forEach((image, i) => {
        const thumb = document.createElement('button'); thumb.type = 'button'; thumb.className = 'idx-modal-thumbnail'; thumb.setAttribute('aria-label', 'Show image ' + (i + 1));
        const preview = document.createElement('img'); preview.src = image; preview.alt = ''; preview.loading = i === 0 ? 'eager' : 'lazy'; thumb.appendChild(preview);
        thumb.addEventListener('click', () => setModalImage(i)); thumbnails.appendChild(thumb);
      });
    }
    if (typeof SevillaCalendar !== 'undefined') {
      if (!modalCalendar) modalCalendar = new SevillaCalendar('idx-modal-calendar', { onRangeSelected: updateContinue });
      modalCalendar.clearSelection();
      modalCalendar.fixedDurationNights = venue.category === 'Resort Villa' ? 0 : null;
      modalCalendar.fixedDurationGuard = venue.category === 'Resort Villa';
      modalCalendar.requireHotelRules = venue.category === 'Hotel Room';
      modalCalendar.fetchBookedDates(venue.category === 'Hotel Room' ? venue.room_type : venue.category, venue.venue_name, venue.venue_id || null);
    }
    setModalImage(0); updateContinue();
    modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('idx-modal-open');
    modal.querySelector('.idx-modal-close')?.focus();
  };
  const closeVenueModal = () => {
    if (!modal) return;
    modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('idx-modal-open'); activeVenue = null;
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  };
  modal?.querySelector('.idx-modal-close')?.addEventListener('click', closeVenueModal);
  modal?.addEventListener('click', event => { if (event.target === modal) closeVenueModal(); });
  modal?.querySelector('.idx-modal-gallery-prev')?.addEventListener('click', () => setModalImage(activeImageIndex - 1));
  modal?.querySelector('.idx-modal-gallery-next')?.addEventListener('click', () => setModalImage(activeImageIndex + 1));
  modal?.querySelector('.idx-modal-calendar .cal-days-grid')?.addEventListener('click', () => window.setTimeout(updateContinue, 0));
  modal?.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); closeVenueModal(); return; }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll('button, a[href]')).filter(item => !item.disabled && item.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
});
