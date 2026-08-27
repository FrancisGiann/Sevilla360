# Destructive-state finish review

Visual recapture was not run because the isolated preview fixtures were no longer running and protected modal controls require an authenticated session. The state contract was verified from the final source instead.

## Shared cascade

- `assets/css/ui-refinement.css:68-81` now applies the gold primary treatment only to explicit variants: `.idx-btn-gold`, `.btn-primary`, `.btn-primary-dash`, `.btn-save`, `.save-btn`, non-outline `.btn-quick-action`, `.btn-gold`, `.btn-gold-save`, `.btn-paymongo`, `.btn-confirm-walkin`, `.btn-export-csv`, and `.btn-modal-primary`.
- Generic `.btn` and `.idx-btn` remain in the structural/transition rules only; neither appears in the primary background declarations.
- `assets/css/ui-refinement.css:86-117` explicitly preserves red danger states for admin `.btn-danger`, `.btn-cancel`, `.btn-refund`, `.btn-modal-danger`, and `.btn-modal-reject-refund`; booking `.btn-cancel` and customer `.btn-danger-outline` remain red outlines; `.btn-modal-cancel`, `.btn-cancel-outline`, and `.btn-outline-action` remain neutral outlines.

## Affected behavior hooks

- Booking cancel: `booking.php:229-230` (`.btn-paymongo`, `.btn-cancel`).
- Booking switch confirmation: `includes/partials/booking_modals.php:61-62` (`#btn-cancel-switch`, `#btn-confirm-switch`, `.btn-modal-danger`).
- Admin decline/force-cancel: `includes/admin-page/admin_bookings.php:269-270,343-344` and dynamic action buttons in `assets/js/admin-page/admin_bookings.js:206-226` (`.btn-cancel`, `.btn-refund`, `.open-decline`, `.open-force-cancel`).
- Admin settings remove controls: `includes/admin-page/admin_settings.php:271,379` and dynamic rows in `assets/js/admin-page/admin_settings.js:108,173` (`.btn-danger`, `.btn-remove-social`, `.btn-remove-support-faq`).

No markup, JavaScript behavior, data hooks, routes, or backend code changed.
