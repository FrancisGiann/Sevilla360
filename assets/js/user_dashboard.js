/**
 * SEVILLA360 - User Dashboard Logic
 * Handles Tabs, Filtering, Modals, and Settings Updates.
 */

document.addEventListener("DOMContentLoaded", () => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  // =========================================================
  // 1. MODAL BRIDGES to Global Modals
  // =========================================================

  window.currentViewBookingId = null;

  // --- Mobile Sidebar Drawer Toggle ---
  const mobileToggle = document.getElementById('btn-mobile-sidebar-toggle');
  const sidebarClose = document.getElementById('btn-close-sidebar');
  const sidebar = document.getElementById('customer-sidebar');
  const sidebarOverlay = document.getElementById('sidebar-overlay');
  let toggleDrawer = () => {};
  let drawerOpen = false;
  let drawerInvoker = null;
  let bodyOverflowBeforeDrawer = null;

  // --- Desktop Sidebar Icon Rail ---
  const sidebarCollapseToggle = document.getElementById('btn-sidebar-collapse');
  const dashboardLayout = sidebar?.closest('.dashboard-layout');
  const sidebarCollapseKey = 'sevilla360-customer-sidebar-collapsed';
  const desktopSidebarQuery = window.matchMedia('(min-width: 993px)');
  const readSidebarCollapsed = () => {
      try { return window.localStorage.getItem(sidebarCollapseKey) === '1'; }
      catch (error) { return false; }
  };
  const persistSidebarCollapsed = (collapsed) => {
      try { window.localStorage.setItem(sidebarCollapseKey, collapsed ? '1' : '0'); }
      catch (error) { /* Private browsing or restrictive storage is non-fatal. */ }
  };
  const setSidebarCollapsed = (collapsed, persist = true) => {
      if (!desktopSidebarQuery.matches) {
          document.documentElement.classList.remove('customer-sidebar-precollapsed');
          dashboardLayout?.classList.remove('customer-sidebar-collapsed');
          return;
      }
      document.documentElement.classList.toggle('customer-sidebar-precollapsed', collapsed);
      dashboardLayout?.classList.toggle('customer-sidebar-collapsed', collapsed);
      sidebar?.querySelectorAll('.sidebar-nav .nav-link, .sidebar-footer .nav-link').forEach(link => {
          const label = link.textContent.replace(/\s+/g, ' ').trim();
          if (collapsed) {
              link.setAttribute('title', label);
              link.setAttribute('aria-label', label);
          } else {
              link.removeAttribute('title');
              link.removeAttribute('aria-label');
          }
      });
      sidebarCollapseToggle?.setAttribute('aria-pressed', String(collapsed));
      sidebarCollapseToggle?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Minimize sidebar');
      sidebarCollapseToggle?.setAttribute('title', collapsed ? 'Expand sidebar' : 'Minimize sidebar');
      if (sidebarCollapseToggle) sidebarCollapseToggle.innerHTML = `<i class="fa-solid fa-chevron-${collapsed ? 'right' : 'left'}" aria-hidden="true"></i>`;
      if (persist) persistSidebarCollapsed(collapsed);
  };

  if (sidebarCollapseToggle) {
      setSidebarCollapsed(readSidebarCollapsed(), false);
      sidebarCollapseToggle.addEventListener('click', () => {
          setSidebarCollapsed(!dashboardLayout?.classList.contains('customer-sidebar-collapsed'));
      });
      desktopSidebarQuery.addEventListener?.('change', event => {
          setSidebarCollapsed(event.matches ? readSidebarCollapsed() : false, false);
      });
  }

  if (sidebar) {
      const drawerFocusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
      const setDrawerState = (open) => {
          drawerOpen = open;
          sidebar.classList.toggle('mobile-open', open);
          sidebar.inert = !open && !desktopSidebarQuery.matches;
          if (sidebarOverlay) {
              sidebarOverlay.classList.toggle('active', open);
              sidebarOverlay.setAttribute('aria-hidden', String(!open));
          }
          mobileToggle?.setAttribute('aria-expanded', String(open));
      };

      toggleDrawer = (open, restoreFocus = true) => {
          if (open && desktopSidebarQuery.matches) return;
          if (open) {
              if (drawerOpen) return;
              drawerInvoker = document.activeElement instanceof HTMLElement ? document.activeElement : mobileToggle;
              bodyOverflowBeforeDrawer = document.body.style.overflow;
              document.body.style.overflow = 'hidden';
              setDrawerState(true);
              window.requestAnimationFrame(() => {
                  if (!drawerOpen || desktopSidebarQuery.matches) return;
                  const firstFocusable = sidebar.querySelector(drawerFocusableSelector);
                  (firstFocusable || sidebar).focus({ preventScroll: true });
              });
              return;
          }

          const wasOpen = drawerOpen;
          setDrawerState(false);
          if (wasOpen && bodyOverflowBeforeDrawer !== null) {
              document.body.style.overflow = bodyOverflowBeforeDrawer;
              bodyOverflowBeforeDrawer = null;
          }
          if (wasOpen && restoreFocus) {
              const invoker = drawerInvoker?.isConnected ? drawerInvoker : mobileToggle;
              if (!desktopSidebarQuery.matches) invoker?.focus({ preventScroll: true });
          }
          if (wasOpen) drawerInvoker = null;
      };

      toggleDrawer(false, false);
      if (mobileToggle) mobileToggle.addEventListener('click', () => toggleDrawer(true));
      if (sidebarClose) sidebarClose.addEventListener('click', () => toggleDrawer(false));
      if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => toggleDrawer(false));

      sidebar.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
          link.addEventListener('click', () => toggleDrawer(false));
      });

      document.addEventListener('keydown', event => {
          if (!drawerOpen || desktopSidebarQuery.matches) return;
          if (event.key === 'Escape') {
              event.preventDefault();
              toggleDrawer(false);
              return;
          }
          if (event.key !== 'Tab') return;

          const focusable = Array.from(sidebar.querySelectorAll(drawerFocusableSelector))
              .filter(element => !element.hidden && element.getClientRects().length > 0);
          if (!focusable.length) {
              event.preventDefault();
              sidebar.focus({ preventScroll: true });
              return;
          }
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
              event.preventDefault();
              last.focus();
          } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
              event.preventDefault();
              first.focus();
          }
      });

      desktopSidebarQuery.addEventListener?.('change', event => {
          toggleDrawer(false, false);
          sidebar.inert = !event.matches;
          mobileToggle?.setAttribute('aria-expanded', 'false');
          if (sidebarOverlay) sidebarOverlay.setAttribute('aria-hidden', 'true');
      });
  }

  const btnPrintReceipt = document.getElementById('btn-print-receipt');
  if (btnPrintReceipt) {
      btnPrintReceipt.addEventListener('click', () => {
          if (window.currentViewBookingId) {
              window.open(`print_receipt.php?booking_id=${window.currentViewBookingId}`, '_blank');
          }
      });
  }



  // --- 0. Notification Bell ---
  const btnNotifs = document.getElementById('btn-notifications');
  const notifDropdown = document.getElementById('notif-dropdown');
  const btnMarkRead = document.getElementById('btn-mark-read');
  const notifFeedback = document.getElementById('notif-refresh-feedback');
  const notifFeedbackMessage = document.getElementById('notif-refresh-message');
  const notifRetry = document.getElementById('notif-retry');
  const notifLiveStatus = document.getElementById('notif-live-status');
  let notifBadge = document.getElementById('notif-badge');
  const notifListBody = document.querySelector('.notif-list-body');
  let notificationRefreshInFlight = false;
  let notificationHasLoaded = false;

  function announceNotificationChange(message) {
      if (notifLiveStatus) notifLiveStatus.textContent = message;
  }

  function setNotificationFeedback(message = '', { isError = false, canRetry = false, announce = false } = {}) {
      if (notifFeedback) {
          notifFeedback.hidden = !message;
          notifFeedback.classList.toggle('is-error', isError);
      }
      if (notifFeedbackMessage) notifFeedbackMessage.textContent = message;
      if (notifRetry) notifRetry.hidden = !canRetry;
      if (announce && message) announceNotificationChange(message);
  }

  function updateNotificationBadge(unreadCount) {
      if (!notifBadge && unreadCount > 0 && btnNotifs) {
          notifBadge = document.createElement('span');
          notifBadge.id = 'notif-badge';
          notifBadge.setAttribute('aria-hidden', 'true');
          btnNotifs.appendChild(notifBadge);
      }
      if (notifBadge) {
          notifBadge.textContent = String(unreadCount);
          notifBadge.style.display = unreadCount > 0 ? '' : 'none';
      }
      if (btnNotifs) btnNotifs.setAttribute('aria-label', unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications');
      if (btnMarkRead) btnMarkRead.hidden = unreadCount <= 0;
  }

  function setNotificationItemRead(item, isRead) {
      item.classList.toggle('unread', !isRead);
      item.dataset.read = isRead ? 'true' : 'false';
      const title = item.dataset.title || '';
      const message = item.dataset.message || '';
      item.setAttribute('aria-label', `${isRead ? 'Read' : 'Unread'} notification: ${title}. ${message}`);
      const readState = item.querySelector('.notif-item-read-state');
      if (readState) readState.textContent = isRead ? 'Read' : 'Unread';
  }

  function refreshNotifications() {
      if (!notifListBody || notificationRefreshInFlight) return Promise.resolve();
      notificationRefreshInFlight = true;
      notifListBody.setAttribute('aria-busy', 'true');
      if (!notificationHasLoaded) setNotificationFeedback('Checking for new notifications…');
      if (notifRetry) {
          notifRetry.disabled = true;
          notifRetry.textContent = 'Retrying…';
      }
      return fetch('actions/user/get_notifications.php', {
          headers: { 'Accept': 'application/json', 'X-Sevilla-Background': '1' }
      })
          .then(res => {
              if (!res.ok) throw new Error(`HTTP ${res.status}`);
              return res.json();
          })
          .then(data => {
              if (!data?.success || !Array.isArray(data.notifications)) throw new Error('Invalid notifications response');
              const unreadCount = Math.max(0, Number(data.unread_count) || 0);
              const focusedItemId = notifDropdown?.contains(document.activeElement) && document.activeElement.matches('.notif-item')
                  ? document.activeElement.dataset.id
                  : null;
              updateNotificationBadge(unreadCount);
              if (!data.notifications.length) {
                  notifListBody.innerHTML = '<div class="notif-empty-state">No notifications yet.</div>';
              } else {
                  notifListBody.innerHTML = data.notifications.map(item => {
                      const itemId = escapeHtml(item.id);
                      const title = escapeHtml(item.title);
                      const message = escapeHtml(item.message);
                      const createdAt = escapeHtml(item.created_at);
                      const isRead = Number(item.is_read) !== 0;
                      const readLabel = isRead ? 'Read' : 'Unread';
                      const accessibleLabel = escapeHtml(`${readLabel} notification: ${String(item.title ?? '')}. ${String(item.message ?? '')}`);
                      return '<button type="button" class="notif-item ' + (isRead ? '' : 'unread') + '" data-id="' + itemId + '" data-title="' + title + '" data-message="' + message + '" data-read="' + (isRead ? 'true' : 'false') + '" aria-label="' + accessibleLabel + '">' +
                          '<span class="notif-item-icon" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>' +
                          '<span class="notif-item-content"><span class="notif-item-title">' + title + '</span><span class="notif-item-msg">' + message + '</span><span class="notif-item-read-state">' + readLabel + '</span><span class="notif-item-time">' + createdAt + '</span></span></button>';
                  }).join('');
              }
              if (focusedItemId && !notifDropdown?.hidden) {
                  const refreshedFocusTarget = Array.from(notifListBody.querySelectorAll('.notif-item'))
                      .find(item => item.dataset.id === focusedItemId);
                  (refreshedFocusTarget || notifDropdown).focus({ preventScroll: true });
              }
              notificationHasLoaded = true;
              setNotificationFeedback('');
          })
          .catch(() => {
              setNotificationFeedback('Could not refresh notifications. Previously loaded notifications are still available.', { isError: true, canRetry: true, announce: true });
          })
          .finally(() => {
              notificationRefreshInFlight = false;
              notifListBody.removeAttribute('aria-busy');
              if (notifRetry) {
                  notifRetry.disabled = false;
                  notifRetry.textContent = 'Retry';
              }
          });
  }

  // Realtime delivery refreshes the same authorized notification endpoint;
  // it never opens a popup or trusts event payloads as display data.
  window.addEventListener('SevillaRealtimeEvent', event => {
      if (String(event.detail?.channel || '').startsWith('customer:')) refreshNotifications();
  });

  function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  }

  const setNotificationDropdownOpen = (open, restoreFocus = false) => {
      if (!btnNotifs || !notifDropdown) return;
      notifDropdown.hidden = !open;
      btnNotifs.setAttribute('aria-expanded', String(open));
          if (open) {
              window.requestAnimationFrame(() => {
                  if (notifDropdown.hidden) return;
                  const firstControl = notifDropdown.querySelector('button:not([hidden]):not([disabled])');
              (firstControl || notifDropdown).focus({ preventScroll: true });
          });
      } else if (restoreFocus) {
          btnNotifs.focus({ preventScroll: true });
      }
  };

  if (btnNotifs && notifDropdown) {
      btnNotifs.addEventListener('click', event => {
          event.stopPropagation();
          setNotificationDropdownOpen(notifDropdown.hidden);
      });
      document.addEventListener('click', event => {
          if (!btnNotifs.contains(event.target) && !notifDropdown.contains(event.target)) setNotificationDropdownOpen(false);
      });
      notifDropdown.addEventListener('keydown', event => {
          if (event.key === 'Escape') {
              event.preventDefault();
              setNotificationDropdownOpen(false, true);
          }
      });
  }
  notifRetry?.addEventListener('click', () => refreshNotifications());
  document.getElementById('overview-open-notifications')?.addEventListener('click', () => {
      if (btnNotifs) btnNotifs.click();
  });

  if (btnMarkRead) {
      btnMarkRead.addEventListener('click', async () => {
          btnMarkRead.disabled = true;
          try {
              const response = await fetch('actions/user/mark_notifications_read.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken } });
              const data = await response.json();
              if (!response.ok || !data.success) throw new Error(data.message || 'The request could not be completed.');
              notifListBody?.querySelectorAll('.notif-item.unread').forEach(item => setNotificationItemRead(item, true));
              updateNotificationBadge(0);
              announceNotificationChange('All notifications marked as read.');
              setNotificationFeedback('');
          } catch (error) {
              setNotificationFeedback('Could not mark all notifications as read. Your request was not submitted. Check your connection and try again.', { isError: true, canRetry: false, announce: true });
          } finally {
              btnMarkRead.disabled = false;
          }
      });
  }

  // Individual notification controls stay semantic after each refresh.
  notifListBody?.addEventListener('click', async event => {
      const item = event.target.closest('.notif-item');
      if (!item || !notifListBody.contains(item)) return;
      event.stopPropagation();
      const id = item.dataset.id || '';
      const title = item.dataset.title || 'Notification';
      const message = item.dataset.message || '';
      showAlert(title, message, 'info');
      setNotificationDropdownOpen(false);
      if (!item.classList.contains('unread')) return;

      item.disabled = true;
      try {
          const response = await fetch('actions/user/mark_notifications_read.php', {
              method: 'POST',
              headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/x-www-form-urlencoded' },
              body: `id=${encodeURIComponent(id)}`
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'The request could not be completed.');
          setNotificationItemRead(item, true);
          const currentCount = Number.parseInt(notifBadge?.textContent || '0', 10) || 0;
          updateNotificationBadge(Math.max(0, currentCount - 1));
          announceNotificationChange(`${title} marked as read.`);
          setNotificationFeedback('');
      } catch (error) {
          setNotificationFeedback('Could not mark this notification as read. Your request was not submitted. Check your connection and try again.', { isError: true, canRetry: false, announce: true });
      } finally {
          item.disabled = false;
      }
  });

  let notificationPollTimer = null;
  const scheduleNotificationPoll = () => {
      clearTimeout(notificationPollTimer);
      notificationPollTimer = setTimeout(() => {
          refreshNotifications();
          scheduleNotificationPoll();
      }, document.visibilityState === 'visible' ? 30000 : 120000);
  };
  document.addEventListener('visibilitychange', scheduleNotificationPoll);
  refreshNotifications();
  scheduleNotificationPoll();
  
  // --- 1. Section state and URL navigation ---
  const validSections = new Set(["overview", "bookings", "settings"]);
  const navItems = document.querySelectorAll(".nav-item[data-tab]");
  const tabPanes = document.querySelectorAll(".tab-pane");

  const requestedDashboardSection = () => {
    const querySection = new URLSearchParams(window.location.search).get("section");
    if (validSections.has(querySection)) return querySection;
    if (/^booking-\d+$/.test(window.location.hash.slice(1))) return "bookings";
    return "overview";
  };

  const setDashboardSection = (section, { push = false, focusBooking = false, hash = null } = {}) => {
    const targetSection = validSections.has(section) ? section : "overview";
    navItems.forEach((item) => {
      const active = item.dataset.tab === targetSection;
      item.classList.toggle("active", active);
      const link = item.querySelector(".nav-link");
      if (link) {
        if (active) link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
      }
    });
    tabPanes.forEach((pane) => pane.classList.toggle("active", pane.id === `tab-${targetSection}`));

    if (push) {
      const url = new URL(window.location.href);
      url.searchParams.set("section", targetSection);
      if (hash !== null) url.hash = hash;
      if (targetSection !== "bookings" && url.hash.startsWith("#booking-")) url.hash = "";
      window.history.pushState({ section: targetSection }, "", url);
    }

    if (focusBooking) {
      const bookingId = window.location.hash.match(/^#booking-(\d+)$/)?.[1];
      if (bookingId) {
        window.requestAnimationFrame(() => {
          const row = document.getElementById(`booking-${bookingId}`);
          if (row) {
            row.scrollIntoView({ block: "center" });
            row.setAttribute("tabindex", "-1");
            row.focus({ preventScroll: true });
          }
        });
      }
    }
  };

  navItems.forEach((item) => {
    const link = item.querySelector(".nav-link");
    if (!link) return;
    link.addEventListener("click", (event) => {
      event.preventDefault();
      setDashboardSection(item.dataset.tab, { push: true });
      if (sidebar) toggleDrawer(false);
    });
  });

  document.querySelectorAll("[data-dashboard-section]").forEach((link) => {
    link.addEventListener("click", (event) => {
      const section = link.dataset.dashboardSection;
      if (!validSections.has(section)) return;
      event.preventDefault();
      const linkedUrl = new URL(link.href, window.location.href);
      setDashboardSection(section, { push: true, focusBooking: Boolean(link.closest(".attention-list")), hash: linkedUrl.hash || null });
    });
  });

  window.addEventListener("popstate", () => setDashboardSection(requestedDashboardSection(), { focusBooking: true }));
  window.addEventListener("hashchange", () => setDashboardSection(requestedDashboardSection(), { focusBooking: true }));
  setDashboardSection(requestedDashboardSection(), { focusBooking: true });

  // --- 2. Table Filtering ---
  const statusFilter = document.getElementById("statusFilter");
  const tableRows = document.querySelectorAll("#bookingsTable tbody tr[data-status]");

  const applyBookingFilter = (filterValue) => {
    tableRows.forEach((row) => {
      const rowStatus = row.getAttribute("data-status");
      row.style.display = filterValue === "All" || rowStatus === filterValue ? "" : "none";
    });
  };

  if (statusFilter) {
    statusFilter.addEventListener("change", (e) => {
      const filterValue = e.target.value;
      applyBookingFilter(filterValue);
    });
  }

  // --- 3. Modal Logic ---
  const modals = {
    cancel: document.getElementById("modal-cancel"),
    reschedule: document.getElementById("modal-reschedule"),
    details: document.getElementById("modal-details"),
    "manual-payment": document.getElementById("modal-manual-payment"),
    review: document.getElementById("modal-review"),
    alert: document.getElementById("uniAlertModal"),
  };

  const focusableSelector = [
    'a[href]', 'area[href]', 'button:not([disabled])', 'input:not([disabled])',
    'select:not([disabled])', 'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');
  let activeModal = null;
  let activeInvoker = null;
  let globalAlertInvoker = null;
  let suspendedModal = null;
  let suspendedInvoker = null;

  const isVisible = (element) => Boolean(element && (element.classList.contains('active') || (
    window.getComputedStyle(element).visibility !== 'hidden' &&
    window.getComputedStyle(element).display !== 'none' &&
    window.getComputedStyle(element).opacity !== '0'
  )));

  const focusModal = (modal) => {
    if (!modal) return;
    const focusTarget = Array.from(modal.querySelectorAll(focusableSelector)).find(isVisible)
      || modal.querySelector('.modal-box') || modal;
    const isNativelyFocusable = /^(A|AREA|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(focusTarget.tagName)
      || focusTarget.isContentEditable;
    if (!isNativelyFocusable && !focusTarget.hasAttribute('tabindex')) focusTarget.setAttribute('tabindex', '-1');
    const attemptFocus = () => {
      if (!document.contains(focusTarget) || focusTarget.disabled || !isVisible(modal)) return false;
      try { focusTarget.focus({ preventScroll: true }); } catch (error) { focusTarget.focus(); }
      return document.activeElement === focusTarget;
    };
    if (!attemptFocus()) window.requestAnimationFrame(attemptFocus);
    else window.requestAnimationFrame(() => {
      if (document.activeElement !== focusTarget) attemptFocus();
    });
  };

  const restoreFocus = (invoker) => {
    const attemptFocus = () => {
      if (!invoker || !document.contains(invoker) || invoker.disabled) return false;
      const owningModal = invoker.closest('.modal-overlay, .global-admin-modal');
      if (owningModal && !isVisible(owningModal)) return false;
      try { invoker.focus({ preventScroll: true }); } catch (error) { invoker.focus(); }
      return document.activeElement === invoker;
    };
    if (!attemptFocus()) window.requestAnimationFrame(attemptFocus);
    else window.requestAnimationFrame(() => {
      if (document.activeElement !== invoker) attemptFocus();
    });
  };

  function openModal(modalId, invoker = document.activeElement) {
    const modal = modals[modalId];
    if (!modal) return;
    activeModal = modal;
    activeInvoker = invoker && !modal.contains(invoker) ? invoker : activeInvoker;
    modal.classList.add("active");
    document.body.style.overflow = "hidden";
    focusModal(modal);
  }

  function closeModal() {
    const wasReviewModal = activeModal === modals.review;
    Object.values(modals).forEach((modal) => {
        if (modal) modal.classList.remove("active");
    });
    activeModal = null;
    document.body.style.overflow = "";

    const checkboxGrp = document.getElementById("cancel-checkbox-group");
    const refundInfo = document.getElementById("cancel-refund-info-wrapper");
    if (checkboxGrp) checkboxGrp.style.display = "none";
    if (refundInfo) refundInfo.style.display = "none";

    document.querySelectorAll(".modal-box textarea, .modal-box input").forEach((input) => {
        if (input.type === "checkbox" || input.type === "radio") input.checked = false;
        else input.value = "";
    });
    document.querySelectorAll(".modal-box select").forEach((select) => { select.selectedIndex = 0; });
    const destinationFields = document.getElementById('cancel-refund-destination');
    const destinationError = document.getElementById('cancel-refund-destination-error');
    if (destinationFields) destinationFields.hidden = true;
    if (destinationError) { destinationError.hidden = true; destinationError.textContent = ''; }
    ['cancel-refund-method', 'cancel-refund-account-name', 'cancel-refund-account-identifier', 'cancel-refund-bank-name'].forEach((id) => {
      const field = document.getElementById(id);
      if (field) { field.required = false; field.setCustomValidity(''); }
    });
    const bankField = document.getElementById('cancel-refund-bank-field');
    if (bankField) bankField.hidden = true;
    if (wasReviewModal) {
      reviewBookingId = null;
      reviewRating = 0;
      paintReviewRating();
    }
    restoreFocus(activeInvoker);
    activeInvoker = null;
  }

  document.querySelectorAll(".close-modal").forEach((btn) => btn.addEventListener("click", closeModal));
  document.querySelectorAll("#uniAlertModal .btn-alert-ok").forEach((btn) => btn.addEventListener("click", closeModal));

  Object.values(modals).forEach((modal) => {
    if(modal) {
        modal.addEventListener("click", (e) => { if (e.target === modal) closeModal(); });
    }
  });

  const globalAlertModal = document.getElementById('globalAlertModal');
  const decorateGlobalAlert = (modal) => {
    if (!modal) return;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'ga-title');
    modal.setAttribute('aria-describedby', 'ga-message');
  };
  decorateGlobalAlert(globalAlertModal);

  // global_modals.js owns the alert's close/reload action. Wrap only its
  // invocation so focus is managed here without changing that behavior.
  if (typeof window.showAlert === 'function' && !window.showAlert.__dashboardFocusWrapper) {
    const originalShowAlert = window.showAlert;
    const wrappedShowAlert = function (...args) {
      globalAlertInvoker = document.activeElement;
      suspendedModal = activeModal;
      suspendedInvoker = activeInvoker;
      const result = originalShowAlert.apply(this, args);
      const alert = document.getElementById('globalAlertModal');
      decorateGlobalAlert(alert);
      activeModal = alert;
      activeInvoker = globalAlertInvoker;
      document.body.style.overflow = 'hidden';
      focusModal(alert);
      return result;
    };
    wrappedShowAlert.__dashboardFocusWrapper = true;
    window.showAlert = wrappedShowAlert;
  }

  document.addEventListener('click', (event) => {
    if (event.target.closest('#globalAlertModal #ga-btn-ok')) {
      window.setTimeout(() => {
        restoreFocus(globalAlertInvoker);
        globalAlertInvoker = null;
        if (suspendedModal && isVisible(suspendedModal)) {
          activeModal = suspendedModal;
          activeInvoker = suspendedInvoker;
        } else {
          activeModal = null;
          activeInvoker = null;
          document.body.style.overflow = '';
        }
        suspendedModal = null;
        suspendedInvoker = null;
      }, 0);
    }
  }, true);

  document.addEventListener('keydown', (event) => {
    const modal = activeModal || (isVisible(globalAlertModal) ? globalAlertModal : null);
    if (!modal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (modal === globalAlertModal) {
        modal.querySelector('#ga-btn-ok')?.click();
      } else {
        closeModal();
      }
      return;
    }
    if (event.key !== 'Tab') return;
    const focusables = Array.from(modal.querySelectorAll(focusableSelector)).filter((element) => isVisible(element));
    if (!focusables.length) {
      event.preventDefault();
      focusModal(modal);
      return;
    }
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (!modal.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const appendDetailLine = (container, label, value, supplemental = '') => {
    const row = document.createElement('p');
    row.style.cssText = 'border:none; padding:2px 0;';
    const labelEl = document.createElement('span');
    labelEl.textContent = `• ${String(label ?? '')}`;
    const valueEl = document.createElement('span');
    valueEl.style.color = 'var(--color-dark-light)';
    valueEl.textContent = `₱${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    row.append(labelEl, valueEl);
    if (supplemental) {
      const extra = document.createElement('small');
      extra.textContent = String(supplemental);
      labelEl.appendChild(document.createElement('br'));
      labelEl.appendChild(extra);
    }
    container.appendChild(row);
  };

  const bookingActionPanelOrigins = new WeakMap();

  const restoreBookingActionPanel = (panel) => {
    const origin = bookingActionPanelOrigins.get(panel);
    if (origin?.parent?.isConnected) {
      if (origin.nextSibling?.parentNode === origin.parent) {
        origin.parent.insertBefore(panel, origin.nextSibling);
      } else {
        origin.parent.appendChild(panel);
      }
    }
    bookingActionPanelOrigins.delete(panel);
  };

  const closeBookingActionDisclosure = (restoreFocus = false) => {
    document.querySelectorAll('.booking-more-toggle[aria-expanded="true"]').forEach((toggle) => {
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      toggle.setAttribute('aria-expanded', 'false');
      if (panel) {
        panel.hidden = true;
        panel.removeAttribute('style');
        restoreBookingActionPanel(panel);
      }
      if (restoreFocus && toggle.isConnected) toggle.focus();
    });
  };

  const positionBookingActionDisclosure = (toggle, panel) => {
    if (!bookingActionPanelOrigins.has(panel)) {
      bookingActionPanelOrigins.set(panel, {
        parent: panel.parentNode,
        nextSibling: panel.nextSibling,
      });
    }
    // Move the fixed panel outside the animated tab-pane so its viewport
    // coordinates are not offset by the tab's transform-containing block.
    document.body.appendChild(panel);
    panel.hidden = false;
    panel.style.position = 'fixed';
    panel.style.left = '0px';
    panel.style.top = '0px';
    panel.style.visibility = 'hidden';
    const viewportWidth = Math.max(0, window.visualViewport?.width || document.documentElement.clientWidth || window.innerWidth);
    const viewportGutter = Math.min(12, viewportWidth / 2);
    const maxWidth = Math.max(0, Math.min(228, viewportWidth - (viewportGutter * 2)));
    panel.style.width = `${maxWidth}px`;
    panel.style.maxWidth = `${maxWidth}px`;
    const buttonRect = toggle.getBoundingClientRect();
    const panelRect = panel.getBoundingClientRect();
    const maxLeft = Math.max(viewportGutter, viewportWidth - panelRect.width - viewportGutter);
    const left = Math.min(Math.max(viewportGutter, buttonRect.right - panelRect.width), maxLeft);
    const viewportHeight = Math.max(0, window.visualViewport?.height || window.innerHeight);
    const verticalGutter = Math.min(12, viewportHeight / 2);
    const maxTop = Math.max(verticalGutter, viewportHeight - panelRect.height - verticalGutter);
    const preferredTop = viewportHeight - buttonRect.bottom >= panelRect.height + 8
      ? buttonRect.bottom + 6
      : buttonRect.top - panelRect.height - 6;
    const top = Math.min(Math.max(verticalGutter, preferredTop), maxTop);
    panel.style.left = `${left}px`;
    panel.style.top = `${top}px`;
    panel.style.visibility = '';
  };

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('.booking-more-toggle');
    if (toggle) {
      const wasExpanded = toggle.getAttribute('aria-expanded') === 'true';
      closeBookingActionDisclosure(false);
      if (!wasExpanded) {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (panel) {
          toggle.setAttribute('aria-expanded', 'true');
          positionBookingActionDisclosure(toggle, panel);
          panel.querySelector('.action-menu-item')?.focus({ preventScroll: true });
        }
      }
      return;
    }
    if (event.target.closest('.booking-action-menu-panel .action-menu-item')) {
      closeBookingActionDisclosure(false);
      return;
    }
    if (!event.target.closest('.booking-row-more')) closeBookingActionDisclosure(false);
  }, true);

  document.addEventListener('keydown', (event) => {
    const panel = event.target.closest?.('.booking-action-menu-panel');
    if (panel && event.key === 'Tab') {
      const items = Array.from(panel.querySelectorAll('.action-menu-item'));
      const isTabEdge = event.shiftKey
        ? event.target === items[0]
        : event.target === items[items.length - 1];
      if (isTabEdge) closeBookingActionDisclosure(false);
    }
    if (event.key === 'Escape' && document.querySelector('.booking-more-toggle[aria-expanded="true"]')) {
      event.preventDefault();
      closeBookingActionDisclosure(true);
    }
  }, true);
  window.addEventListener('resize', () => closeBookingActionDisclosure(false));
  window.addEventListener('scroll', (event) => {
    if (event.target instanceof Element && event.target.closest('.booking-action-menu-panel')) return;
    closeBookingActionDisclosure(false);
  }, true);

  // --- 4. ACTION BUTTONS ---

  // A. Cancel Button
  document.querySelectorAll(".btn-cancel").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const bookingId = btn.getAttribute("data-id");
      const venue = btn.getAttribute("data-venue");
      const date = btn.getAttribute("data-date");
      const paidStr = btn.getAttribute("data-paid");
      const amountPaid = parseFloat(paidStr) || 0;

      const cancelVenueEl = document.getElementById("cancel-venue");
      const cancelDateEl = document.getElementById("cancel-date");
      if (cancelVenueEl) cancelVenueEl.textContent = venue;
      if (cancelDateEl) cancelDateEl.textContent = date;

      const refundInfoTop = document.getElementById("cancel-refund-info-wrapper");
      const refundInfoBottom = document.getElementById("cancel-refund-bottom");
      const unpaidInfo = document.getElementById("cancel-unpaid-info");
      const confirmBtn = document.querySelector("#modal-cancel .btn-confirm-red");
      const destinationFields = document.getElementById('cancel-refund-destination');
      const destinationMethod = document.getElementById('cancel-refund-method');
      const destinationName = document.getElementById('cancel-refund-account-name');
      const destinationIdentifier = document.getElementById('cancel-refund-account-identifier');
      const destinationBankField = document.getElementById('cancel-refund-bank-field');
      const destinationBank = document.getElementById('cancel-refund-bank-name');
      const destinationError = document.getElementById('cancel-refund-destination-error');
      const cancelTitle = document.getElementById('cancel-modal-title');

      if (amountPaid === 0) {
          if (refundInfoTop) refundInfoTop.style.display = "none";
          if (refundInfoBottom) refundInfoBottom.style.display = "none";
          if (unpaidInfo) unpaidInfo.style.display = "block";
          if (destinationFields) destinationFields.hidden = true;
          [destinationMethod, destinationName, destinationIdentifier, destinationBank].forEach((field) => { if (field) field.required = false; });
          if (destinationBankField) destinationBankField.hidden = true;
          if (cancelTitle) cancelTitle.textContent = 'Cancel Reservation?';
          if (confirmBtn) confirmBtn.textContent = 'Confirm Cancellation';
      } else {
          const refundAmt = Math.max(0, Math.round(amountPaid * 100) / 100);

          const cancelPaidEl = document.getElementById("cancel-paid");
          const cancelRefundTotalEl = document.getElementById("cancel-refund-total");
          if(cancelPaidEl) cancelPaidEl.textContent = `₱${amountPaid.toLocaleString()}`;
          const cancelFeeLabel = document.getElementById("cancel-fee-label");
          if(cancelFeeLabel) cancelFeeLabel.textContent = 'No fee deducted';
          if(cancelRefundTotalEl) cancelRefundTotalEl.textContent = `₱${refundAmt.toLocaleString()}`;

          if (refundInfoTop) refundInfoTop.style.display = "block";
          if (refundInfoBottom) refundInfoBottom.style.display = "block";
          if (unpaidInfo) unpaidInfo.style.display = "none";
          if (destinationFields) destinationFields.hidden = false;
          [destinationMethod, destinationName, destinationIdentifier].forEach((field) => { if (field) field.required = true; });
          if (destinationMethod) destinationMethod.value = '';
          if (destinationName) destinationName.value = '';
          if (destinationIdentifier) destinationIdentifier.value = '';
          if (destinationBank) { destinationBank.value = ''; destinationBank.required = false; }
          if (destinationBankField) destinationBankField.hidden = true;
          if (destinationError) { destinationError.hidden = true; destinationError.textContent = ''; }
          if (cancelTitle) cancelTitle.textContent = 'Request Cancellation & Refund';
          if (confirmBtn) confirmBtn.textContent = 'Submit Refund Request';
      }

      if (confirmBtn) confirmBtn.setAttribute("data-id", bookingId);
      openModal("cancel");
    });
  });

  const refundDestinationMethod = document.getElementById('cancel-refund-method');
  if (refundDestinationMethod) {
    refundDestinationMethod.addEventListener('change', () => {
      const bankField = document.getElementById('cancel-refund-bank-field');
      const bankName = document.getElementById('cancel-refund-bank-name');
      const isBankTransfer = refundDestinationMethod.value === 'Bank Transfer';
      if (bankField) bankField.hidden = !isBankTransfer;
      if (bankName) {
        bankName.required = isBankTransfer;
        if (!isBankTransfer) bankName.value = '';
      }
      const error = document.getElementById('cancel-refund-destination-error');
      if (error) { error.hidden = true; error.textContent = ''; }
    });
  }

  const btnConfirmCancel = document.querySelector("#modal-cancel .btn-confirm-red");
  if (btnConfirmCancel) {
    btnConfirmCancel.addEventListener("click", function () {
      const bookingId = this.getAttribute("data-id");
      const reasonInput = document.querySelector("#modal-cancel textarea");
      const reason = reasonInput ? reasonInput.value.trim() : "";
      
      const confirmFee = document.getElementById("confirm-fee");
      const isChecked = confirmFee ? confirmFee.checked : false;
      const destinationFields = document.getElementById('cancel-refund-destination');
      const destinationError = document.getElementById('cancel-refund-destination-error');
      const isRefundable = Boolean(destinationFields && !destinationFields.hidden);

      if (reason === "") return showAlert("Missing Info", "Please provide a reason for the cancellation.", "error");
      if (isRefundable && !isChecked) return showAlert("Required", "Please acknowledge the refund amount shown above.", "error");

      const destinationMethod = document.getElementById('cancel-refund-method');
      const destinationName = document.getElementById('cancel-refund-account-name');
      const destinationIdentifier = document.getElementById('cancel-refund-account-identifier');
      const destinationBank = document.getElementById('cancel-refund-bank-name');
      if (isRefundable) {
        const requiredDestinationFields = [destinationMethod, destinationName, destinationIdentifier, ...(destinationBank?.required ? [destinationBank] : [])].filter(Boolean);
        const invalidDestinationField = requiredDestinationFields.find((field) => !field.checkValidity());
        if (invalidDestinationField) {
          if (destinationError) {
            destinationError.textContent = invalidDestinationField.validationMessage || 'Complete the refund destination details.';
            destinationError.hidden = false;
          }
          invalidDestinationField.focus();
          invalidDestinationField.reportValidity();
          return;
        }
      }

      const originalText = this.innerText;
      this.innerText = "Processing...";
      this.disabled = true;

      fetch('actions/user/request_cancel.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify({
            booking_id: bookingId,
            reason: reason,
            ...(isRefundable ? {
              refund_destination: {
                method: destinationMethod.value,
                account_name: destinationName.value.trim(),
                account_identifier: destinationIdentifier.value.trim(),
                bank_name: destinationBank?.required ? destinationBank.value.trim() : ''
              }
            } : {})
          })
      })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) showAlert("Success", data.message, "success", true);
        else {
          const serverMessage = String(data.message || 'Unable to submit the refund request.');
          if (isRefundable && destinationError && /destination|mobile number|account holder|bank name|wallet|account number/i.test(serverMessage)) {
            destinationError.textContent = serverMessage;
            destinationError.hidden = false;
            const lowerMessage = serverMessage.toLowerCase();
            const invalidField = lowerMessage.includes('bank') ? destinationBank
              : lowerMessage.includes('account holder') ? destinationName
                : lowerMessage.includes('number') || lowerMessage.includes('mobile') || lowerMessage.includes('wallet') ? destinationIdentifier
                  : destinationMethod;
            invalidField?.focus();
          }
          showAlert("Error", data.message, "error");
          this.innerText = originalText;
          this.disabled = false;
        }
      })
      .catch((error) => {
        showAlert("Connection issue", "Your cancellation request was not submitted because of a connection issue. Check your connection and try again.", "error");
        this.innerText = originalText;
        this.disabled = false;
      });
    });
  }

  // B. Reschedule Button Logic
  let userReschedCalendar = null;
  if (typeof SevillaCalendar !== 'undefined' && document.getElementById("cal-ui-user-resched")) {
      userReschedCalendar = new SevillaCalendar("cal-ui-user-resched");
  }

  document.querySelectorAll(".btn-reschedule").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      const bookingId = btn.getAttribute("data-id");
      const venueName = btn.getAttribute("data-venue");
      const originalDate = btn.getAttribute("data-date");
      const venueType = btn.getAttribute("data-type") || "Hotel Room"; 

      const rv = document.getElementById("reschedule-venue");
      const rd = document.getElementById("reschedule-date");
      if(rv) rv.textContent = venueName;
      if(rd) rd.textContent = originalDate;
      
      const submitBtn = document.getElementById("btn-submit-resched");
      if (submitBtn) submitBtn.setAttribute("data-id", bookingId);

      const reasonInput = document.getElementById("reschedule-reason");
      const confirmCheck = document.getElementById("confirm-reschedule");
      if (reasonInput) reasonInput.value = "";
      if (confirmCheck) confirmCheck.checked = false;

      if (userReschedCalendar) {
          userReschedCalendar.clearSelection();
          
          const startDt = new Date(btn.getAttribute("data-start"));
          const endDt = new Date(btn.getAttribute("data-end"));
          const diffTime = Math.abs(endDt - startDt);
          let nights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
          
          if (venueType === 'Hotel Room' || venueType.includes('Room')) {
              if (nights < 1) nights = 1;
              userReschedCalendar.requireHotelRules = true;
          } else {
              userReschedCalendar.requireHotelRules = false;
          }
          userReschedCalendar.fixedDurationNights = nights;

          userReschedCalendar.fetchBookedDates(venueType, venueName);
          setTimeout(() => userReschedCalendar.render(), 100);
      }

      openModal("reschedule");
    });
  });

  const btnSubmitResched = document.getElementById("btn-submit-resched");
  if (btnSubmitResched) {
      btnSubmitResched.addEventListener("click", function() {
          const bookingId = this.getAttribute("data-id");
          const reason = document.getElementById("reschedule-reason")?.value.trim() || "";
          const isChecked = document.getElementById("confirm-reschedule")?.checked;

          if (!userReschedCalendar || !userReschedCalendar.startDate) return showAlert("Missing Data", "Please select your new dates on the calendar.", "error");
          if (reason === "") return showAlert("Missing Info", "Please provide a reason for rescheduling.", "error");
          if (!isChecked) return showAlert("Required", "Please acknowledge the reschedule policy by checking the box.", "error");

          const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
          const newStart = formatLocal(userReschedCalendar.startDate);
          const newEnd = userReschedCalendar.endDate ? formatLocal(userReschedCalendar.endDate) : newStart;

          const originalText = this.innerText;
          this.innerText = "Submitting...";
          this.disabled = true;

          fetch('actions/user/request_reschedule.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
              body: JSON.stringify({ booking_id: bookingId, new_start_date: newStart, new_end_date: newEnd, reason: reason })
          })
          .then(res => res.json())
          .then(data => {
              if (data.success) showAlert("Success", data.message, "success", true);
              else {
                  showAlert("Error", data.message, "error");
                  this.innerText = originalText;
                  this.disabled = false;
              }
          })
          .catch(err => {
              showAlert("Connection issue", "Your reschedule request was not submitted because of a connection issue. Check your connection and try again.", "error");
              this.innerText = originalText;
              this.disabled = false;
          });
      });
  }

  // C. View Details Button
  document.querySelectorAll(".btn-details").forEach((btn) => {
    btn.addEventListener("click", function(e) {
        const detailsTrigger = this;
        const bookingId = this.getAttribute('data-id');
        const originalHTML = this.innerHTML; 
        
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span class="vd-text" style="margin-left:5px;">Loading...</span>';
        this.disabled = true;

        fetch(`actions/user/get_my_booking_details.php?id=${bookingId}`)
        .then(async (response) => {
            if (!response.ok) throw new Error("HTTP " + response.status);
            const text = await response.text();
            try { return JSON.parse(text); } 
            catch (err) {
                console.error("CRITICAL PHP ERROR IN JSON:", text);
                throw new Error("Invalid Server Response.");
            }
        })
        .then(res => {
            this.innerHTML = originalHTML; 
            this.disabled = false;

            if (!res.success) return showAlert("Error", "Error loading details: " + res.message, "error");

            const data = res.data.booking;
            const specifics = res.data.specifics;
            const addons = res.data.addons;
            
            window.currentViewBookingId = data.id;

            const displayId = data.reference_no ? data.reference_no : '#' + data.id;
            const titleEl = document.getElementById('ud-title');
            if(titleEl) titleEl.innerText = `Booking ${displayId}`;
            
            const displayStatus = data.display_booking_status || data.booking_status;
            const badge = document.getElementById('ud-status-badge');
            if(badge) {
                badge.textContent = data.customer_status_label || displayStatus;
                badge.className = 'badge ' + (data.customer_status_class || 'badge-pending');
            }

            const nameEl = document.getElementById('ud-customer-name');
            const venueEl = document.getElementById('ud-venue');
            const guestsEl = document.getElementById('ud-guests');
            if(nameEl) nameEl.innerText = `${data.first_name} ${data.last_name}`;
            if(venueEl) venueEl.innerText = `${data.venue_name} (${data.venue_category})`;
            if(guestsEl) guestsEl.innerText = data.guests_count;

            const opts = { month: "short", day: "numeric", year: "numeric" };
            const sDate = new Date(data.start_date).toLocaleDateString("en-US", opts);
            const eDate = new Date(data.end_date).toLocaleDateString("en-US", opts);
            const datesEl = document.getElementById('ud-dates');
            if(datesEl) datesEl.innerText = (sDate === eDate) ? sDate : `${sDate} — ${eDate}`;

            const specRow = document.getElementById('ud-specific-row');
            const specLabel = document.getElementById('ud-specific-label');
            const specValue = document.getElementById('ud-specific-value');
            
            if (specifics && specRow) {
                specRow.style.display = 'flex';
                if (data.venue_category === 'Event Hall') {
                    specLabel.textContent = "Event Details:";
                    specValue.textContent = '';
                    const eventSummary = document.createElement('strong');
                    eventSummary.textContent = `${String(specifics.event_type ?? '')} (${String(specifics.event_style ?? '')})`;
                    const notes = document.createElement('span');
                    notes.style.cssText = 'color:#666; font-size:0.85rem; display:block; margin-top:5px;';
                    const notesLabel = document.createElement('strong');
                    notesLabel.textContent = 'Your Notes: ';
                    notes.append(notesLabel, document.createTextNode(String(specifics.custom_notes || 'None')));
                    specValue.append(eventSummary, document.createElement('br'), notes);
                } else if (data.venue_category === 'Resort Villa') {
                    specLabel.textContent = "Stay Type:";
                    specValue.textContent = String(specifics.stay_type ?? '');
                }
            } else if(specRow) {
                specRow.style.display = 'none';
            }

            const cancelRow = document.getElementById('ud-cancel-row');
            const cancelReasonEl = document.getElementById('ud-cancel-reason');
            const cancellation = res.data.cancellation;

            if (data.booking_status === 'Cancelled' && cancellation && cancellation.reason && cancelRow) {
                cancelReasonEl.innerText = cancellation.reason;
                cancelRow.style.display = 'flex';
            } else if(cancelRow) {
                cancelRow.style.display = 'none';
            }

            const refundSection = document.getElementById('ud-refund-request-section');
            const showRefundRequest = Number(data.amount_paid) > 0 && cancellation && ['Pending', 'Rejected', 'Processed'].includes(cancellation.status);
            if (refundSection) {
                refundSection.hidden = !showRefundRequest;
                if (showRefundRequest) {
                    const destination = cancellation.refund_destination || {};
                    const setRefundText = (id, value, fallback = 'Not provided') => {
                        const element = document.getElementById(id);
                        if (element) element.textContent = String(value || fallback);
                    };
                    setRefundText('ud-refund-request-status', cancellation.status);
                    setRefundText('ud-refund-request-reason', cancellation.reason);
                    setRefundText('ud-refund-request-reply', cancellation.admin_reply);
                    setRefundText('ud-refund-request-amount', `₱${Number(cancellation.refund_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
                    setRefundText('ud-refund-request-method', destination.method);
                    setRefundText('ud-refund-request-account-name', destination.account_name);
                    setRefundText('ud-refund-request-identifier', destination.masked_identifier);
                    const bankLabel = document.getElementById('ud-refund-request-bank-label');
                    const bankValue = document.getElementById('ud-refund-request-bank');
                    const hasBankName = destination.method === 'Bank Transfer' && Boolean(destination.bank_name);
                    if (bankLabel) bankLabel.hidden = !hasBankName;
                    if (bankValue) {
                        bankValue.hidden = !hasBankName;
                        bankValue.textContent = hasBankName ? String(destination.bank_name) : '';
                    }
                }
            }

            const lineItems = res.data.line_items;
            const rooms = res.data.rooms || [];
            const addonsContainer = document.getElementById('ud-addons-container');
            const addonsList = document.getElementById('ud-addons-list');
            if (addonsList) addonsList.replaceChildren();
            
            if (addons && addons.length > 0 && addonsContainer && addonsList) {
                addonsContainer.style.display = 'block';
                addons.forEach(addon => {
                    appendDetailLine(addonsList, `${String(addon.name ?? '')} (x${String(addon.quantity ?? 0)})`, addon.total_price);
                });
            } else if(addonsContainer) {
                addonsContainer.style.display = 'none';
            }

            const lineItemsContainer = document.getElementById('ud-line-items-container');
            const lineItemsList = document.getElementById('ud-line-items-list');
            if (lineItemsList) lineItemsList.replaceChildren();

            if (lineItems && lineItems.length > 0 && lineItemsContainer && lineItemsList) {
                lineItemsContainer.style.display = 'block';
                lineItems.forEach(item => {
                    if (rooms.length && String(item.item_name ?? '').startsWith('Room Add-on:')) return;
                    appendDetailLine(lineItemsList, item.item_name, item.amount);
                });
            } else if (lineItemsContainer) {
                lineItemsContainer.style.display = 'none';
            }

            if (rooms.length && lineItemsContainer && lineItemsList) {
                lineItemsContainer.style.display = 'block';
                rooms.forEach(room => {
                    const number = room.room_number ? ` - Room ${room.room_number}` : '';
                    appendDetailLine(lineItemsList, `${String(room.building_name ?? '')} — ${String(room.room_type ?? '')}${number}`, room.line_total, `${String(room.start_date ?? '')} to ${String(room.end_date ?? '')} (${String(room.nights ?? 0)} nights)`);
                });
            }

            const formatCash = (amt) => `₱${parseFloat(amt || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            const isPendingEvent = data.venue_category === 'Event Hall' && displayStatus === 'Pending';

            const extraPaxAmt = parseFloat(data.extra_pax_amount || 0);
            const extraPaxContainer = document.getElementById('ud-extrapax-container');
            if (extraPaxContainer) {
                extraPaxContainer.style.display = (extraPaxAmt > 0) ? 'block' : 'none';
                if (document.getElementById('ud-extrapax-amt')) document.getElementById('ud-extrapax-amt').innerText = formatCash(extraPaxAmt);
            }

            const baseAmt = parseFloat(data.base_amount || 0);
            const subtotal = baseAmt + extraPaxAmt;
            const totalAmt = parseFloat(data.total_amount || 0);
            const paidAmt = parseFloat(data.amount_paid || 0);
            const balance = Math.max(0, totalAmt - paidAmt);

            if (isPendingEvent) {
                if(document.getElementById('ud-base-amt')) document.getElementById('ud-base-amt').innerText = "TBA";
                if(document.getElementById('ud-subtotal-amt')) document.getElementById('ud-subtotal-amt').innerText = "TBA";
                if(document.getElementById('ud-total-amt')) document.getElementById('ud-total-amt').innerText = "To Be Arranged";
                if(document.getElementById('ud-scheme')) document.getElementById('ud-scheme').innerText = "To Be Arranged";
            } else {
                if(document.getElementById('ud-base-amt')) document.getElementById('ud-base-amt').innerText = formatCash(baseAmt);
                if(document.getElementById('ud-subtotal-amt')) document.getElementById('ud-subtotal-amt').innerText = formatCash(subtotal);
                if(document.getElementById('ud-total-amt')) document.getElementById('ud-total-amt').innerText = formatCash(totalAmt);
                
                let schemeText = data.payment_scheme;
                if (data.payment_scheme !== '100% Full' && data.payment_status === 'Paid') {
                    schemeText = `${data.payment_scheme} (Balance Settled)`;
                }
                if(document.getElementById('ud-scheme')) document.getElementById('ud-scheme').innerText = schemeText;
            }
            
            if(document.getElementById('ud-paid-amt')) document.getElementById('ud-paid-amt').innerText = formatCash(paidAmt);
            if(document.getElementById('ud-balance-amt')) document.getElementById('ud-balance-amt').innerText = isPendingEvent ? "TBA" : formatCash(balance);

            const paymentHistoryList = document.getElementById('ud-payment-history-list');
            if (paymentHistoryList) {
                paymentHistoryList.replaceChildren();
                const payments = Array.isArray(res.data.payments) ? res.data.payments : [];
                if (!payments.length) {
                    const empty = document.createElement('p');
                    empty.className = 'payment-history-empty';
                    empty.textContent = 'No successful payments recorded.';
                    paymentHistoryList.appendChild(empty);
                } else {
                    payments.forEach((payment) => {
                        const entry = document.createElement('article');
                        entry.className = 'payment-history-entry';
                        const fields = [
                            ['Payment method', payment?.payment_method || 'N/A'],
                            ['Transaction/reference ID', payment?.transaction_reference || 'N/A'],
                            ['Amount', formatCash(payment?.amount)],
                            ['Date', payment?.payment_date || 'N/A']
                        ];
                        fields.forEach(([label, value]) => {
                            const row = document.createElement('p');
                            const labelEl = document.createElement('span');
                            const valueEl = document.createElement('strong');
                            labelEl.textContent = label;
                            valueEl.textContent = String(value);
                            row.append(labelEl, valueEl);
                            entry.appendChild(row);
                        });
                        paymentHistoryList.appendChild(entry);
                    });
                }
            }

            const manualSubmissionContainer = document.getElementById('ud-manual-submission-container');
            const manualSubmissionStatus = String(data.manual_submission_status || '').toLowerCase();
            if (manualSubmissionContainer && ['pending', 'rejected'].includes(manualSubmissionStatus)) {
                const proofStatusLabels = {
                    pending: 'Awaiting payment verification',
                    rejected: 'Proof rejected'
                };
                const referenceEl = document.getElementById('ud-submitted-payment-reference');
                const proofStatusEl = document.getElementById('ud-proof-review-status');
                const submittedAtEl = document.getElementById('ud-proof-submitted-at');
                const reviewNoteRow = document.getElementById('ud-proof-review-note-row');
                const reviewNoteEl = document.getElementById('ud-proof-review-note');
                const submittedAt = String(data.manual_submission_submitted_at || '').trim();

                manualSubmissionContainer.style.display = 'block';
                if (referenceEl) referenceEl.textContent = String(data.manual_submission_reference || '');
                if (proofStatusEl) proofStatusEl.textContent = proofStatusLabels[manualSubmissionStatus] || 'Submitted';
                if (submittedAtEl) submittedAtEl.textContent = submittedAt;
                if (reviewNoteRow && reviewNoteEl) {
                    const rejectionReason = manualSubmissionStatus === 'rejected'
                        ? String(data.manual_rejection_reason || '').trim()
                        : '';
                    reviewNoteRow.style.display = rejectionReason ? 'block' : 'none';
                    reviewNoteEl.textContent = rejectionReason;
                }
            } else if (manualSubmissionContainer) {
                manualSubmissionContainer.style.display = 'none';
            }

            const btnPrint = document.getElementById('btn-print-receipt');
            if (btnPrint) {
                const cannotPrint = data.booking_status === 'Pending' || data.booking_status === 'Cancelled' || data.payment_scheme === 'To Be Arranged' || isPendingEvent;
                btnPrint.style.display = cannotPrint ? 'none' : 'inline-flex';
            }

            openModal("details", detailsTrigger);
        })
        .catch(err => {
            showAlert("Connection issue", "Booking details could not be loaded. No booking changes were submitted. Check your connection and try again.", "error");
            this.innerHTML = originalHTML; 
            this.disabled = false;
        });
    });
  });

  // --- 6. User Settings Logic ---
  function updateSettings(payload, buttonElement) {
      const originalText = buttonElement.innerText;
      buttonElement.innerText = "Saving...";
      buttonElement.disabled = true;

      fetch("actions/user/save_settings.php", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify(payload),
      })
      .then(res => res.json())
      .then(data => {
          if (data.success) {
              showAlert("Success", data.message, "success", payload.action === 'update_profile');
              if (payload.action === 'update_password') {
                  document.getElementById('set-old-pass').value = '';
                  document.getElementById('set-new-pass').value = '';
                  document.getElementById('set-confirm-pass').value = '';
              }
          } else {
              showAlert("Error", data.message, "error");
          }
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
      })
      .catch(err => {
          const changeStatus = payload.action === 'update_profile' ? 'Your profile changes were not submitted' : payload.action === 'update_prefs' ? 'Your preference changes were not submitted' : 'Your password change was not submitted';
          showAlert("Connection issue", `${changeStatus} because of a connection issue. Check your connection and try again.`, "error");
          buttonElement.innerText = originalText;
          buttonElement.disabled = false;
      });
  }

  document.getElementById('btn-save-profile')?.addEventListener('click', function() {
      updateSettings({
          action: 'update_profile',
          fname: document.getElementById('set-fname').value,
          lname: document.getElementById('set-lname').value,
          phone: document.getElementById('set-phone').value
      }, this);
  });

  document.getElementById('btn-save-prefs')?.addEventListener('click', function() {
      updateSettings({
          action: 'update_prefs',
          prefs: document.getElementById('set-prefs').value
      }, this);
  });

  document.getElementById('btn-update-password')?.addEventListener('click', function() {
      const newPassword = document.getElementById('set-new-pass').value;
      const confirmPassword = document.getElementById('set-confirm-pass').value;
      if (newPassword !== confirmPassword) {
          showAlert("Error", "New password and confirmation do not match.", "error");
          return;
      }
      const passwordPolicy = window.SevillaPasswordPolicy?.validate(newPassword);
      if (!passwordPolicy || !passwordPolicy.valid) {
          showAlert("Error", passwordPolicy?.message || "Password does not meet the required policy.", "error");
          return;
      }
      updateSettings({
          action: 'update_password',
          old_pass: document.getElementById('set-old-pass').value,
          new_pass: newPassword,
          confirm_pass: confirmPassword
      }, this);
  });

  // --- 7. Shared customer manual-payment component ---
  window.manualPaymentDialog = window.ManualPayment?.create({ csrfToken, openModal, closeModal }) || null;

  // --- 8. Completed-stay venue reviews ---
  let reviewBookingId = null;
  let reviewRating = 0;
  const reviewModal = modals.review;
  const reviewStatus = document.getElementById('review-form-status');
  const setReviewStatus = (message, isError = true) => { if (!reviewStatus) return; reviewStatus.textContent = message; reviewStatus.hidden = !message; reviewStatus.dataset.error = isError ? 'true' : 'false'; };
  const reviewInputs = document.querySelectorAll('.review-rating-input');
  const normalizeReviewRating = (value) => {
    const numeric = Number(value);
    return Number.isInteger(numeric) && numeric >= 0 && numeric <= 5 ? numeric : 0;
  };
  const paintReviewRating = () => reviewInputs.forEach((input) => {
    const value = Number(input.value);
    const option = input.closest('.review-rating-option');
    input.checked = value === reviewRating;
    option?.classList.toggle('is-filled', Number.isInteger(value) && value > 0 && value <= reviewRating);
  });
  reviewInputs.forEach((input) => input.addEventListener('change', () => { reviewRating = normalizeReviewRating(input.value); paintReviewRating(); }));
  document.querySelectorAll('.btn-review-open').forEach((button) => button.addEventListener('click', () => {
    reviewBookingId = button.dataset.id; reviewRating = normalizeReviewRating(button.dataset.rating || 0); paintReviewRating();
    document.getElementById('review-modal-title').textContent = button.textContent.trim() === 'View/edit review' ? 'View or edit review' : 'Rate venue';
    document.getElementById('review-modal-venue').textContent = button.dataset.venue || '';
    document.getElementById('review-text').value = button.dataset.review || '';
    setReviewStatus(''); openModal('review', button);
  }));
  document.getElementById('review-submit')?.addEventListener('click', async () => {
    if (!reviewBookingId || reviewRating < 1 || reviewRating > 5) { setReviewStatus('Choose a rating from 1 to 5.'); return; }
    const submit = document.getElementById('review-submit'); submit.disabled = true; submit.classList.add('is-submitting'); submit.setAttribute('aria-busy', 'true'); setReviewStatus('');
    try {
      const response = await fetch('actions/user/save_venue_review.php', { method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken}, body: JSON.stringify({booking_id: reviewBookingId, rating: reviewRating, review_text: document.getElementById('review-text').value}) });
      const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message || 'Review could not be saved.');
      closeModal(); showAlert('Review submitted', data.message, 'success', true);
    } catch (error) {
      const networkFailure = error instanceof TypeError || error.message === 'Failed to fetch';
      setReviewStatus(networkFailure
        ? 'Your review was not submitted because of a connection issue. Check your connection and try again.'
        : `Your review could not be submitted. ${error.message} Please review it and try again.`);
    } finally { submit.disabled = false; submit.classList.remove('is-submitting'); submit.removeAttribute('aria-busy'); }
  });

  let paymentProofSubmitted = false;
  try {
    paymentProofSubmitted = window.sessionStorage.getItem('manual-payment-proof-submitted') === '1';
    window.sessionStorage.removeItem('manual-payment-proof-submitted');
  } catch (error) { /* Private browsing may disable storage; the booking remains visible below. */ }
  if (paymentProofSubmitted) {
    window.setTimeout(() => showAlert('Proof submitted', 'Your payment proof is awaiting verification. You can follow its status under My Bookings.', 'success'), 0);
  }

});
