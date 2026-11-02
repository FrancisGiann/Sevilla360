# Editable catering content

Local implementation based on `origin/main` at `7cf97fa7a96c4235be4be894fc43c307eb14a3aa`.

## Content source

The supplied reference (`libfile_4fb8b984f488819190d92c544fc7f43f`) was downloaded and visually inspected. It contains three packages:

| Package | Reference price per person | Menu |
| --- | --- | --- |
| Package 1 | PHP 550.00 | Beef Tenderloin with Mushroom Sauce; Chicken Cordon Bleu; Pork Shanghai Roll; Canton Sotanghon; Soup; Rice; Coffee Jelly; Iced Tea |
| Package 2 | PHP 600.00 | Beef with Broccoli; Sevilla's Chicken; Pork Asado; Fish Fillet w/ tartar dip; Mixed Vegetables with Quail Egg; Soup; Rice; Buko Pandan; Cucumber Lemonade |
| Package 3 | PHP 650.00 | Beef Caldereta; Pandan Chicken; Pork Hamonado; Sweet & Sour Fish; Calamares; Chopsuey; Soup; Rice; Cathedral Window; Lemon Jelly Juice |

No minimum headcount, tax, delivery, dates, or booking terms appear in the reference. Existing booking rules remain in place.

## Release procedure

The initial implementation and verification were local only. The user subsequently approved committing, publishing, and deploying the catering changes and setting the three live packages to the supplied menus at PHP 550/600/650 per person. No schema migration or changes to other site settings, existing bookings, or payment amounts are included.

Deploy through the existing project process. Back up only the nine catering settings before the approved content update, preserving whether each key previously existed. Existing saved prices remain authoritative until explicitly updated to 550.00, 600.00, and 650.00. Update only the catering keys and read them back; do not use a stale whole-preferences form to overwrite other settings. Record publication, deployment, and content-update outcomes separately; source publication alone does not confirm the live prices were changed.

Confirm the public Event Hall inquiry cards and staff walk-in cards show the saved names, prices, and complete menu lists. Existing reservations retain their already stored line items; this work does not rewrite historical quotations or payments.

## Data and rollback

Package content reuses `system_settings`; no schema migration is required. Existing catering rate keys (`catering_silver`, `catering_gold`, `catering_platinum`) remain compatible with prior code. Each now has a corresponding `_name` and `_menu_json` setting. The administrator edits individual menu rows and uses Add menu item, Remove, Up, and Down controls. The editor serializes the ordered rows into the existing menu payload; the server validates and stores the list as JSON.

The editor separates each package's display name and price from its menu. It announces row operations and save status, keeps keyboard focus with edited rows, and preserves unsaved edits after a failed request or a save in another Settings panel. If another edit is made while a save is in progress, that edit remains marked unsaved after the earlier request succeeds.

Valid existing prices, including zero and up to four fractional digits, are preserved. Missing or malformed content uses the reference defaults. New edits require names of 1–60 characters, prices from PHP 0 to PHP 1,000,000 with at most four fractional digits, and 1–30 menu items of at most 120 characters each (3,000 menu characters total). Public text is escaped. The existing admin role, active-account, and CSRF checks apply; settings and the audit entry save in one transaction.

Before changing production content, export the affected settings through the established backup process. Code can be rolled back independently; restore the reviewed settings snapshot if production content must also be reverted. Retain old rate values when reverting to old menus.

## Local verification

- PHP lint and JavaScript syntax checks passed for changed application files; `git diff --check` passed.
- `test_catering_packages_contract.php`, `test_admin_settings_contract.php`, and `test_receptionist_ai_contract.php` passed.
- Booking resume, payment-reopen, and summary-visibility JavaScript tests passed. Resume coverage includes matching package IDs after price changes, duplicate prices, and legacy drafts.
- The public booking contract reports 90 passes and two existing failures: “showroom receptionist preserves answer navigation and real response states” and “showroom receptionist uses progressive venue actions and compact response back.” Both depend exclusively on showroom source files unchanged from the fetched base.
- A disposable local MariaDB database with synthetic accounts exercised the real preferences save endpoint. Valid names, fractional prices, and multiline menus persisted and reloaded. Negative/excessive prices, empty menus, missing/invalid CSRF, and unauthorized staff saves were rejected without overwriting the last valid package or business settings.
- Background browser checks at 1440×900 and 390×844 used an extracted actual admin form and the shared public partial. No horizontal overflow was observed; mobile cards stacked; keyboard navigation selected the next package; empty-menu validation focused its field. Script-like package names and markup-like menu items rendered as literal text after save/reload.
- The refined row editor passed browser add/edit/remove/reorder checks, including Enter-key reorder, focus tracking, boundary-button disabling, and whitespace-only validation. A saved synthetic menu reloaded in the same order and rendered literal markup safely in the public partial. Offline save failure preserved edits, displayed retry guidance, and re-enabled Save; an online retry persisted the edited menu.
- The final layout check loaded the real base, overview, and Settings styles and simulated a 280px desktop sidebar. At 1440×900 and 390×844 there was no horizontal overflow; mobile action buttons wrapped, and the sticky save footer had an opaque white background at both sizes.

Browser verification covered the catering surfaces through local fixtures, not the full dashboard or a completed booking. No real booking, email, SMS, or payment was created. No browser screenshots were saved.
