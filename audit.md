# Sevilla360 — Independent Capstone Audit Report

- **Repository:** `/var/www/html/Sevilla360` (branch `main`, HEAD `c5cbc85`)
- **Audit date:** August 25, 2026
- **Method:** Static code review (all 7 passes), read-only runtime checks (`php -l`, `node --check`, `composer validate`, `git diff --check`, `git status`). No database mutations, no emails sent, no PayMongo calls, no browser execution, no repository modifications other than this file.
- **Stack:** PHP 8.1+, MySQL/MariaDB, vanilla HTML/CSS/JS, PayMongo checkout + webhooks, Google OIDC, PHPMailer, Dompdf, Redis/WebSocket Pub/Sub with polling fallback, Panolens.js 360 showroom, local Media CMS storage under `assets/uploads`.

---

## 1. Executive summary

**Overall risk: MODERATE** — a well-hardened core (authentication, payments, booking concurrency, realtime outbox) with one confirmed High stored-XSS chain and several Medium operational/accessibility gaps. No Critical issues found.

### Scope

~27k lines of code across 130+ PHP files, 40+ JS files, 15 SQL migrations, and a Node realtime gateway. All public entry points, AJAX actions, auth boundaries, payment flows, upload flows, exports, PDF generation, admin modules, and the realtime subsystem were reviewed.

### What was and was not runtime-tested

| Runtime-tested (read-only) | Not runtime-tested |
|---|---|
| `php -l` on every project PHP file — all clean | Live XSS exploitation (browser) |
| `node --check` on every project JS/MJS file — all clean | Concurrent race windows |
| `composer validate` — valid (license warning only) | Webhook delivery/replay against PayMongo |
| `git diff --check` — clean | Google OIDC round-trip |
| `git status` — user's vendor/autoload modifications + one untracked upload preserved untouched | Redis/WebSocket transport under load |
| — | PDF rendering in a real browser |

Items requiring runtime confirmation are flagged per finding below.

### Counts by severity

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 1 |
| Medium | 6 |
| Low | 9 |
| Informational | 3 |

---

## 2. Architecture and trust-boundary summary

| Boundary | Components |
|---|---|
| **Anonymous** | `index.php`, `showroom.php`, `support.php`, `auth.php`, `actions/bookings/fetch_dates.php`, `actions/bookings/get_room_availability.php`, `actions/bookings/paymongo_webhook.php` (HMAC-gated) |
| **Customer** | `booking.php`, `user_dashboard.php`, `actions/user/*`, `actions/bookings/{lock_dates,unlock_dates,submit_online}.php`, `print_receipt.php` (owner-or-staff authorization) |
| **Staff + Admin** | `admin_dashboard.php` (guarded by `includes/auth_guard.php`: per-request account-status revalidation, 30-min idle timeout), bookings / walk-in / maintenance / calendar / settings modules |
| **Admin-only** | User management, audit log, Media CMS, backup endpoints, `manage_staff`, `suspend_user`, `save_venue/hotspot/preferences/support_content` |
| **External services** | PayMongo (checkout sessions + signed webhook), Google OIDC (state + nonce), SMTP via PHPMailer, Redis pub/sub → WebSocket gateway (origin allowlist, JWT channel auth) with polling fallback |

Key data flows verified end-to-end:

- Registration → OTP email → verification → login
- Password reset (token issuance → reset form)
- Online booking: date locks → server-side pricing → PayMongo checkout → signed webhook → `credit_verified_payment()` (row-locked, idempotent)
- Admin walk-in booking with hold-proof re-verification
- Event Hall inquiry → admin finalize invoice (`reallocate_event_hall_addons()`)
- Customer cancel/refund (fee snapshotting), reschedule with add-on reallocation
- Receipt PDF generation (Dompdf, remote resources disabled)
- Notification outbox → Redis pub/sub → WebSocket/polling delivery
- Media CMS upload/delete; CSV booking export

---

## 3. Confirmed findings

### 3.1 Summary table (ordered by severity)

| ID | Sev | Conf. | Category | Location | Title |
|---|---|---|---|---|---|
| F-01 | High | High | Stored XSS | `admin_bookings.js:234–246, 802–806`; `user_dashboard.js:526` | Customer-controlled fields rendered via `innerHTML` execute in staff/admin sessions |
| F-02 | Medium | High | Privilege logic | `actions/admin/suspend_user.php:27–28,45–46` | Suspension lacks target-role/self/status validation; last admin can be suspended |
| F-03 | Medium | High | Host-header injection | `actions/auth/forgot_password_process.php:71–75` | Reset links built from `HTTP_HOST` without allowlist |
| F-04 | Medium | High | Security headers | repo-wide | No CSP / X-Frame-Options / nosniff / Referrer-Policy / HSTS anywhere |
| F-05 | Medium | High | Performance | 51 occurrences in 10 PHP views | Per-second cache-busting (`?v=<?= time() ?>`) defeats all browser caching |
| F-06 | Medium | High | Accessibility | `auth.php`, `booking.php`, `global_modals.js`, CSS | Unassociated labels, no modal semantics/focus trap, gold-on-white contrast ≈ 2.2:1, reduced-motion missing |
| F-07 | Medium | High | Schema/Ops | migrations 001/003/006; base schema absent | Non-rerunnable migrations, unshipped base schema, missing FKs/indexes for hot paths |
| F-08 | Low | High | CSRF gap | `actions/admin/download_backup.php:4–30` | GET download authenticated by cookie alone, no CSRF token |
| F-09 | Low | Medium | Authz policy | `reconcile_payment.php:8`, `resend_receipt.php:7` vs `get_dashboard_stats.php:54` | Staff may credit payments/resend receipts while revenue is hidden from staff |
| F-10 | Low | High | Info disclosure | `config/db_connect.php:18–19` | `die("Connection failed: " . $conn->connect_error)` shown to clients |
| F-11 | Low | High | Token hygiene | `forgot_password_process.php:47–52`; `reset_password_process.php:60` | Reset tokens stored plaintext; password reset doesn't revoke other sessions |
| F-12 | Low | High | UX | `includes/header.php:8–14,130`; `index.js` | About/Events/Accommodations never get active state; booking page highlights nothing |
| F-13 | Low | Medium | Performance | `get_master_calendar.php:15–25`; `fetch_dates.php:101–166`; `export_bookings.php:46–53`; `get_audit_logs.php:23` | Unbounded reads / uncapped client-controlled limits grow forever |
| F-14 | Low | Medium | Concurrency perf | `update_booking_status.php:366–509`; `lock_dates.php:198–236` | Prepare-inside-loop while holding `FOR UPDATE` venue locks amplifies lock hold time |
| F-15 | Low | Medium | Attack surface | `get_room_availability.php:11`; `fetch_dates.php` | Public endpoints; availability endpoint performs unauthenticated DELETE side-effect |
| F-16 | Low | High | Availability | `index.php:29,95–119,136,164,172` | Homepage hard-depends on images.unsplash.com (3 images not CMS-overridable) |
| F-17 | Info | High | Crypto hygiene | `verify_process.php:40` | OTP compared with `===` not `hash_equals` (rate-limited; impractical to exploit) |
| F-18 | Info | High | Session hygiene | `actions/auth/logout.php:4–5` | No client-side cookie expiry, no CSRF on logout (forced-logout nuisance only) |
| F-19 | Info | High | Fragile escaping | `auth.php:329–331` | `addslashes` JS interpolation safe today (all `auth_alert` messages are static strings), fragile pattern |

### 3.2 Detailed evidence

---

#### F-01 — Stored XSS in admin/staff booking UI (High · Confidence: High)

- **Category:** Stored XSS
- **Files/lines (complete chain):**
  - Storage without HTML filtering:
    - `actions/bookings/submit_online.php:211–218` — `$custom_notes = trim($_POST['custom_notes'])`
    - `actions/auth/register_process.php:43` — `$first_name = trim(...)` stored raw
    - `actions/user/request_cancel.php:34–35` — reason length-bounded but not HTML-filtered
  - Raw JSON APIs:
    - `actions/admin/get_booking_details.php:42–45,106–116` (returns `custom_notes`, `admin_notes`)
    - `actions/user/get_my_booking_details.php:53–57`
    - `actions/admin/get_bookings_page.php` (raw customer names)
  - Sinks (unescaped template literals into `innerHTML`):
    - `assets/js/admin-page/admin_bookings.js:236–246` — `<td>${customerName}</td>` … `tbody.innerHTML = html`
    - `assets/js/admin-page/admin_bookings.js:217` — `data-reason="${b.cancel_reason}"` attribute breakout
    - `assets/js/admin-page/admin_bookings.js:802–806` — `specValue.innerHTML = ...${specifics.custom_notes}...`
    - `assets/js/user_dashboard.js:526` (self-XSS variant)
    - Also `admin_overview.js:151–155,184–191`, `admin_walkin.js:1490`
  - Grep confirms **zero** occurrences of `htmlspecialchars` / `strip_tags` / `htmlentities` anywhere under `actions/`.
- **Affected roles/workflow:** Any customer (registration name, event `custom_notes`/`event_type`, cancellation/reschedule reasons) → every staff/admin who opens Bookings list or the View Details modal.
- **Confirmed evidence:** The full store→serve→render chain is present in code. Escaping helpers exist in some files (`user_dashboard.js:89–91`, `admin_overview.js:9–12`) but were never applied to these sinks.
- **Root cause:** Client-side rendering trusts server JSON verbatim; server stores raw HTML.
- **Realistic impact:** Payload executes in an admin-origin page. Session cookie is HttpOnly, but the CSRF token lives in `<meta name="csrf-token">` and is readable from JS — enabling forged state-changing requests (booking edits, staff creation) in the admin's session.
- **Reproduction:** Register with first_name `<img src=x onerror=alert(document.cookie)>`; open Admin → Bookings; row HTML is built by template literal and assigned via `tbody.innerHTML` → payload executes.
- **Minimal fix:** Apply the existing `escapeHTML()` helper at every interpolation sink (and attribute-safe quoting for `data-*`); additionally sanitize on input (`strip_tags` + length caps for notes/names). Prefer both layers.
- **Regression test:** Submit a booking containing `<img src=x onerror=window.__pwned=1>` in custom_notes; assert `window.__pwned === undefined` after rendering the admin view (JSDOM or Playwright).
- **Runtime confirmation required:** Yes (browser execution).

---

#### F-02 — suspend_user.php validation gaps (Medium · Confidence: High)

- **Category:** Privilege logic / data integrity
- **Files/lines:** `actions/admin/suspend_user.php:28` (`$new_status = $data['action']; // Will be 'active' or 'suspended'` — comment only), `:45–46` (`UPDATE users SET status = ? WHERE id = ?` unconditional).
- **Affected roles/workflow:** Admin → any user account.
- **Confirmed evidence:** No target-role restriction (an admin can suspend any admin including themselves), no self-suspension guard, no status allowlist (arbitrary strings persisted and echoed back at line 57). Mitigating: `auth_guard.php:34` fails closed unless status is exactly `active`, so no privilege gain — only lockout/corruption.
- **Root cause:** Endpoint predates role-model hardening elsewhere.
- **Realistic impact:** An admin can suspend the last remaining admin (lockout recoverable only via direct DB access); junk status values enter `users.status`.
- **Reproduction:** POST `{user_id: <own admin id>, action: "suspended"}` → succeeds.
- **Minimal fix:** Restrict targets to `role='customer'`, block self-targeting, whitelist `['active','suspended']`.
- **Regression test:** POST suspend against an admin ID and own ID → both rejected.
- **Runtime confirmation required:** No (code path fully static).

---

#### F-03 — Host-header injection into password-reset links (Medium · Confidence: High)

- **Category:** Host-header injection / account takeover prerequisite
- **Files/lines:** `actions/auth/forgot_password_process.php:71–75`:
  ```php
  $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
  $script_path = dirname($_SERVER['SCRIPT_NAME'], 3);
  $reset_link = $base_url . $script_path . "/reset_password.php?token=" . urlencode($token);
  ```
  Same pattern for success/cancel URLs: `pay_existing.php:78–79`, `submit_online.php:398–401`, `update_booking_status.php:241–242`. No host allowlist exists anywhere.
- **Affected roles/workflow:** Anonymous attacker → any registered user's password reset email.
- **Confirmed evidence:** Link construction uses client-controllable `Host` header with no validation.
- **Root cause:** Base URL derived from request rather than trusted configuration.
- **Realistic impact:** Attacker triggers forgot-password for a victim with `Host: evil.example`; victim receives a "reset" link pointing to the attacker's site carrying the valid token in the URL → account takeover if clicked. Requires victim interaction and a web-server vhost that accepts arbitrary Host headers (default on many Apache setups).
- **Reproduction:** Send `POST /Sevilla360/actions/auth/forgot_password_process.php` with `Host: evil.example` and victim's email; inspect received mail link domain (runtime test).
- **Minimal fix:** Introduce `APP_BASE_URL` env config; build all absolute URLs from it; optionally reject mismatched Host at entry.
- **Regression test:** Unit test link generation asserts configured host regardless of Host header.
- **Runtime confirmation required:** Yes (depends on live vhost behavior).

---

#### F-04 — No security headers sitewide (Medium · Confidence: High)

- **Category:** Security headers / clickjacking / MIME sniffing
- **Files/lines:** Grep for `X-Frame-Options|Content-Security-Policy|X-Content-Type-Options|Referrer-Policy|Strict-Transport-Security` across all PHP/JS/.htaccess (excluding vendor) = **0 hits**. Root `.htaccess` does not exist (only `assets/uploads/.htaccess`).
- **Affected roles/workflow:** All users, especially staff/admin pages.
- **Confirmed evidence:** No header emission anywhere; sessions already set conditional-Secure cookies correctly (`session_init.php:14–20`) but transport-level protections are absent.
- **Root cause:** Headers never implemented; deployment assumes Apache defaults.
- **Realistic impact:** Admin/staff pages framable (UI redress); response sniffing; referrer leakage. Partially mitigated because state-changing endpoints are POST+CSRF.
- **Minimal fix:** Emit in `includes/header.php` or root .htaccess: `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`; HSTS once HTTPS guaranteed. CSP feasible incrementally (inline scripts plus jsdelivr/cdnjs/fonts hosts would need allowances).
- **Regression test:** HTTP integration test asserting presence of each header on page responses.
- **Runtime confirmation required:** No.

---

#### F-05 — Per-second cache-busting on ~51 asset URLs (Medium · Confidence: High)

- **Category:** Performance / caching
- **Files/lines (counts):** `admin_dashboard.php` ×25, `user_dashboard.php` ×6, `includes/footer.php` ×3, `auth.php` ×3, `includes/header.php` ×2, `includes/admin-page/admin_cms.php` ×4, `index.php`, `reset_password.php`, `includes/admin-page/admin_backups.php` ×1 each. Only settings assets use correct `filemtime()` versioning (`admin_dashboard.php:49,245`).
- **Affected roles/workflow:** Every visitor, every page.
- **Confirmed evidence:** `?v=<?= time() ?>` changes the URL every second, so browsers treat assets as permanently fresh-missing.
- **Root cause:** Cache-busting placeholder left in during development.
- **Realistic impact:** CSS/JS re-downloaded on every visit — slower loads and worse Lighthouse scores exactly when the capstone demo needs speed.
- **Minimal fix:** Replace `time()` with `filemtime(__DIR__ . '/<asset>')` deployment-wide (pattern already proven at `admin_dashboard.php:49`).
- **Regression test:** Assert two consecutive page loads emit identical asset URLs.
- **Runtime confirmation required:** No.

---

#### F-06 — Accessibility aggregate (Medium · Confidence: High)

- **Category:** Accessibility
- **Files/lines:**
  - Labels not associated: `auth.php` has 15 `<label>` elements without `for=`/`id` pairing (e.g., :81–82); bare labels in `booking.php:191–206`.
  - Modals lack semantics: `includes/partials/booking_modals.php:2–65` (no `role="dialog"`, `aria-modal`, `aria-labelledby`).
  - No focus management: `global_modals.js:8–39` injects dialogs with no focus trap, no Escape-to-close, no focus restore.
  - Focus styles: zero `:focus` rules in `style.css`; outline suppressed in `booking.css:145–150`.
  - Contrast: gold `#d6a870` text on white/cream fails WCAG AA (~2.2:1) — `booking.css:95`, `:325`, `:560`, `user_dashboard.css:502`.
  - `prefers-reduced-motion` present **only** in `index.css:632` despite sitewide transitions/reveal animations.
  - Landmarks: `booking.php` and `auth.php` lack `<main>`.
- **Affected roles/workflow:** Keyboard and screen-reader users across auth, booking, dashboard flows.
- **Root cause:** Accessibility pass applied selectively (header/nav/dashboard do have ARIA attributes).
- **Realistic impact:** Blocked keyboard paths through core booking flow; rubric-visible contrast failures.
- **Minimal fix:** Associate all labels; add dialog semantics + focus trap/Escape/restore to `global_modals.js`; darken gold used as text on light surfaces (e.g., `#8b5e2c`); add a global reduced-motion media query.
- **Regression test:** axe-core scan of auth/booking/dashboard pages must show zero critical violations.
- **Runtime confirmation required:** Yes (screen-reader behavior).

---

#### F-07 — Migration rerunnability & schema gaps (Medium · Confidence: High)

- **Category:** Database / operational readiness
- **Files/lines:**
  - Non-rerunnable: `migrations/001_room_number.sql:8` (plain `ADD COLUMN`), `003_unique_booking_reference.sql` (unguarded `ADD CONSTRAINT`), `006_booking_contact_phone.sql:2` (plain `ADD COLUMN`).
  - MariaDB-only DDL (`IF NOT EXISTS` syntax fails on vanilla MySQL): 005, 007, 010, 015.
  - Base schema (users/bookings/payments/venues/customers/staff/…) ships nowhere; `README.md:38` says "Import the project database schema … if available".
  - Only FKs defined anywhere: `booking_rooms.booking_id → bookings ON DELETE CASCADE` (002:17), `booking_rooms.venue_id` (002:18), `booking_checkout_sessions.booking_id → bookings CASCADE` (009:23). Tables with plain KEYs only (orphan risk): `payments`, `cancellations`, `cancellation_history` (012:20–21), `booking_line_items`, `booking_addons`, `booking_event_details`, `booking_villa_details`, `booking_locks`, `user_notifications`, `notification_outbox`, `reschedule_requests`, `rate_limits`, `audit_logs`, `media_cms`, `showroom_hotspots`. Code confirms: `update_booking_status.php:85–86` ("a schema-level exclusion constraint is still unavailable").
  - Missing indexes vs proven hot paths: overlap predicates `(venue_id, start_date, end_date[, booking_status])` used throughout lock/submit/update flows; `booking_locks(expires_at)` (hygiene DELETE runs per availability request — `lock_dates.php:102`); `audit_logs(created_at)` (`get_audit_logs.php:86–87`, plus non-sargable `DATE(a.created_at)=?` at :48); `user_notifications(user_id, is_read, created_at)` (`header.php:26,34`). Uniqueness enforced only app-side: `users.email` / `customers.email` (register_process.php:74,112 — race window).
  - Money columns visible in migrations are correct: `DECIMAL(10,2)` rates/totals (002), `DECIMAL(12,2)` checkout amounts (009), `DECIMAL(6,3)` fee percents (012); `bookings.*` amounts unverifiable (schema unshipped) though all binds use type `d`.
- **Affected roles/workflow:** Deployment/maintenance.
- **Root cause:** Incremental migrations layered over an out-of-repo dump.
- **Realistic impact:** Fresh deployments depend on an external SQL file; drift between environments likely; query plans degrade with data growth; deletes can orphan payment/notification rows.
- **Minimal fix:** Commit canonical schema; add information_schema guards to 001/003/006 (pattern exists in 008); add the four indexes above; add FKs where application delete paths permit.
- **Regression test:** CI job running all migrations twice against a fresh DB expecting success.
- **Runtime confirmation required:** Yes (needs a disposable DB).

---

#### F-08 — download_backup.php lacks CSRF (Low · Confidence: High)

- **Category:** CSRF
- **Files/lines:** `actions/admin/download_backup.php:4–6` (session role check only), `:12` (`$_GET['file']`), no token check. Path safety itself is solid: filename whitelisted against `backups` table (:19–20), then `basename()` + `backupFilePath()` regex `\A[A-Za-z0-9._-]+\.sql\z` (`backup_helper.php:67`) — traversal impossible.
- **Impact:** Forced-download CSRF only; `SameSite=Lax` cookies still authenticate top-level navigations. Filenames embed random hex, limiting guessing. Exfiltration requires local access to the downloaded file.
- **Fix:** Accept a CSRF query parameter validated with `hash_equals`, or convert to POST form submission.
- **Regression test:** Request without valid token → 403.
- **Runtime confirmation required:** No.

---

#### F-09 — Staff payment/refund authority asymmetry (Low · Confidence: Medium)

- **Category:** Authorization policy consistency
- **Files/lines:** `reconcile_payment.php:8` and `resend_receipt.php:7` admit `staff`; `get_dashboard_stats.php:54–55` deliberately hides revenue from staff; `reject_refund` is admin-only (`update_booking_status.php:608`) while refund processing is staff-allowed.
- **Impact:** Consistency problem, not a bypass — either document staff payment-handling as intended or tighten to admin-only. Receipts expose payment amounts.
- **Fix:** Decide and enforce one policy; document in README.
- **Runtime confirmation required:** No.

---

#### F-10 — Verbose DB connection failure (Low · Confidence: High)

- **Category:** Information disclosure
- **Files/lines:** `config/db_connect.php:18–19` — `die("Connection failed: " . $conn->connect_error);` returns driver error text (host/user hints) in an HTTP 200 body.
- **Fix:** Generic message to client + `error_log($conn->connect_error)`.
- **Regression test:** Stop DB, hit page, assert generic message.
- **Runtime confirmation required:** No.

---

#### F-11 — Plaintext reset tokens & no session revocation (Low · Confidence: High)

- **Category:** Token hygiene
- **Files/lines:** `forgot_password_process.php:47–52` stores `bin2hex(random_bytes(32))` directly in `users.reset_token`; `reset_password_process.php:45` compares via SQL equality; after reset (:60–67) existing sessions remain valid; no audit-log row for password resets.
- **Impact:** A database leak exposes usable reset tokens within the 1-hour TTL; stolen sessions survive a legitimate password reset.
- **Fix:** Store `hash('sha256', $token)`; on reset, bump a `sessions_invalid_before` timestamp checked in `auth_guard.php`; write an audit-log entry. (OTP codes share plaintext storage but are 15-min TTL and rate-limited.)
- **Runtime confirmation required:** No.

---

#### F-12 — Navbar active-state never highlights section anchors (Low · Confidence: High)

- **Category:** UX
- **Files/lines:** `includes/header.php:8–14` maps only `index.php→home` and `showroom.php→showroom`; keys `about/events/accommodations` are unreachable; `booking.php:5` sets `'booking'` which matches no nav key. No scrollspy exists (`index.js:22–32` IntersectionObserver drives reveal animations only).
- **Impact:** Adviser item 17 FAIL — About/Events/Accommodations never highlight; nothing highlights on the booking page.
- **Fix:** Add scrollspy (IntersectionObserver toggling `.active` per section) or map anchor routes.
- **Runtime confirmation required:** Yes (visual).

---

#### F-13 — Unbounded reads (Low · Confidence: Medium)

- **Files/lines:** `get_master_calendar.php:15–25` fetches every non-maintenance booking ever (no date floor) on every admin overview/calendar poll; `fetch_dates.php:101–166` expands history day-by-day (O(total booked nights)); `export_bookings.php:46–53` has no LIMIT (streamed row-by-row, memory-safe); `get_audit_logs.php:23` / `get_bookings_page.php:23` accept client `limit` uncapped; `get_dashboard_stats.php:172` calls notification helper with no limit every 30 s.
- **Impact:** Latency growth over time; deep-pagination degradation. Bounded today: `user_dashboard.php:77–78`, `get_notifications.php:12`.
- **Fix:** Clamp limits server-side; add date-range parameters to calendar endpoints.
- **Runtime confirmation required:** No.

---

#### F-14 — Lock-hold amplification from prepare-in-loop (Low · Confidence: High)

- **Files/lines:** `update_booking_status.php` reschedule: main-room loop prepares 4 statements per candidate (:371–389), add-on loop up to 5 per candidate per addon row (:453–490), while holding `FOR UPDATE` on candidate venues (:354–359) — worst realistic ≈500 statements inside one transaction. `lock_dates.php:198–236` prepares 4 per candidate room. Contrast: `reallocate_event_hall_addons` already fixed (prepares once outside loops, rebinding — `booking_rules.php:102–106`).
- **Impact:** Extended row-lock holds block concurrent lock/submit flows under load; negligible at current dataset size (<50 rooms).
- **Fix:** Hoist prepares like the fixed helper.
- **Runtime confirmation required:** No.

---

#### F-15 — Public availability endpoints mutate (Low · Confidence: High)

- **Files/lines:** `get_room_availability.php:11` runs `DELETE FROM booking_locks WHERE expires_at <= NOW()` unauthenticated (expired rows only; functionally safe but is an anonymous write that can be hammered). `fetch_dates.php` exposes full occupancy anonymously with no session code at all.
- **Impact:** Minor DoS surface + business decision to document (public availability disclosure may be intended for the booking UI).
- **Fix:** Move expired-lock cleanup into lock-creation paths (already duplicated there) or a cron job.
- **Runtime confirmation required:** No.

---

#### F-16 — Hardcoded Unsplash homepage imagery (Low · Confidence: High)

- **Files/lines:** `index.php:95,107,119` — Experiences cards fully hardcoded (not CMS-overridable); plus five CMS fallback URLs (:29,54,136,164,172).
- **Impact:** Six distinct remote URLs render if CMS slots are empty; homepage visual depends on images.unsplash.com availability; licensing/brand risk.
- **Fix:** Bundle default imagery under `assets/img`.
- **Runtime confirmation required:** No.

---

#### F-17 — OTP compared with `===` (Informational · Confidence: High)

- **Files/lines:** `verify_process.php:40` — `$code === $user['verification_code']` instead of `hash_equals`. Exploitation would require timing resolution impractical for a 6-digit code behind a 5-attempts/15-minute rate limit keyed on IP+email-hash (`rate_limit.php` atomic upsert, `verify_process.php:19,130`). Defense-in-depth only.

#### F-18 — Logout quality (Informational · Confidence: High)

- **Files/lines:** `logout.php:4–5` — `session_unset(); session_destroy();` Server-side destruction is effective (`use_strict_mode=1`, `session_init.php:13`). Missing: client-side cookie expiry and CSRF check (forced-logout nuisance only; SameSite=Lax permits top-level GET).

#### F-19 — `addslashes` JS interpolation pattern (Informational · Confidence: High)

- **Files/lines:** `auth.php:329–331` — alert messages injected into a script block via `addslashes`. Safe today because every `auth_alert['message']` is a compile-time constant string (verified across login/register/verify/forgot/google handlers); `addslashes` would not stop a `</script>` breakout were that ever to change. Prefer `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP)` for JS contexts.

---

## 4. Adviser audit verification matrix

| # | Requirement | Status | Evidence | Remaining work |
|---|---|---|---|---|
| 1 | Registration notification | **PASS** | Welcome notification + welcome email issued transactionally at verification (`verify_process.php:83–93,114–121`); Google path `google_callback.php:90` | Runtime email test |
| 2 | Amenities info on booking page | **PASS** | `addons_section.php` included in Event Hall tab (`tab_event_hall.php:127`); amenities rendered from DB attrs (`tab_event_hall.php:59` → `booking.js:584–602`) | None |
| 3 | Receipt cannot be altered to change authoritative values | **PASS** | `print_receipt.php` renders PDF exclusively from DB (:26–180); Dompdf `IsRemoteEnabled(false)`, `IsPhpEnabled(false)`, chroot (:170–175) | Runtime PDF check |
| 4 | Receipt opens as authenticated PDF | **PASS** | `print_receipt.php:22–43` (session + ownership + status guards), :180 streams `application/pdf` inline; openers `user_dashboard.js:36–43`, `admin_bookings.js:27–34` | Confirm inline viewer on target browsers |
| 5 | Email/contact placement in footer/support area | **PASS** | `footer.php:35–58` — Support column (Contact Us) + Connect column (Email Us mailto, validated) | None |
| 6 | No unintended Book/Contact header links | **PASS** | `header.php:89–95` nav = Home/About/Events/Accommodations/Virtual Showroom + single Login/Register CTA | None |
| 7 | Dashboard useful beyond booking history | **PASS** | Stats grid `user_dashboard.php:250–301`; notifications center :196–242; settings/profile/password :500–569; cost-breakdown & refund-calculator modals | None |
| 8 | Admin overview mini calendar | **PASS** | `admin_overview.php:48–54`; `renderMiniCalendar()` `admin_overview.js:14–51` | None |
| 9 | Separate maintenance module | **PASS** | Dedicated page/nav/CSS/JS (`admin_dashboard.php:102–105,205`); schedule/delete/complete wired (`admin_maintenance.js:201–317`) | None |
| 10 | Walk-in guest count cannot exceed capacity/input limit | **PASS** | Server-side capacity checks `submit_walkin.php:196–207` (max_capacity & seating-style capacity); guests ≥ 1 (:52) | Concurrency test |
| 11 | Notifications do not pop up on public homepage | **PASS** | Header bell is click-to-open (`header.php:256–265`); `index.js` contains no auto-alerts; no auto-show code found | Browser smoke test |
| 12 | Browser Back does not force Home | **PASS** | Zero `pushState/go/location.replace('index')` hijacks; only benign `replaceState` URL cleanups (`booking.js:498–499`, `user_dashboard.php:799`) | Manual back-nav walk-through |
| 13 | UI consistency | **PARTIAL** | Tokens exist (`style.css:10–29`) but radius 0 vs 8px mixes, three variable names for beige, inline-styled cards (`addons_section.php:120–133`), cool-gray vs warm-white bodies (`user_dashboard.css:8` vs `admin_overview.css:8`) | Token consolidation pass |
| 14 | Checkout-boundary vs booked colors distinct | **PASS** (customer/walk-in calendars) | Legend + classes `booking_calendar.php:15–22`, `style.css:753–763`, `calendar.js:188–197`; master admin calendar has no checkout concept (N/A by design) | None |
| 15 | Google login secure & configuration-gated | **PASS** | Config gating incl. https/localhost redirect validation (`google_oauth.php:9–34`); full claim validation iss/aud/azp/exp/iat/sub-shape/nonce/email_verified (:49–71); sub-first linking, role/status gates, transactional (`google_callback.php:39–102`); button hidden when unconfigured (`auth.php:95–98`) | Live OIDC round-trip |
| 16 | Backup & Recovery absent from admin nav/page | **PASS** | `allowed_pages` excludes backups (`admin_dashboard.php:12`); `admin_backups.php` include orphaned (referenced only by its own JS tag); endpoints remain admin-hardened | Delete dead include or restore feature deliberately |
| 17 | Navbar active-state indication correct | **FAIL** | See finding F-12 | Scrollspy or per-section active mapping |
| 18 | Genuine Pub/Sub outbox architecture with polling fallback | **PASS** | Transactional outbox (`realtime.php:67–106`, migration 014 unique event_id), gateway claim lease + publish (`gateway.mjs:43–66`), origin allowlist :70–72, 5s auth timeout :74, JWT channel auth (`lib.mjs` `canSubscribe`), maxPayload 4096 :69, exp closes socket :84–85; visibility-aware 30s/120s polling with exponential backoff capped at 30s (`realtime_notifications.js:21,28`) | Load/reconnect soak test |
| 19 | User booking notifies admin; admin changes notify correct user | **PASS** | `submit_online.php:430–435` enqueues `admin` `booking.created`; `update_booking_status.php:707–710` enqueues `customer:<id>` `booking.updated` + `create_user_notification` per action | End-to-end two-browser test |
| 20 | Event inquiry still displays estimated total | **PASS** (pre-submission) | Estimate card `booking.php:132–136`, updated `booking.js:1054–1055`; post-submission intentionally "To Be Arranged" until quotation (`user_dashboard.php:352–357`) | Decide whether estimate should persist post-submit |
| 21 | Admin export exists & authorized | **PASS** | `export_bookings.php:5–8` staff/admin gate; formula-injection guard `csv_safe_value` :67–89 | Large-dataset timing |
| 22 | Responsive behavior | **PASS** | Breakpoints 992/900/768/640/576/480 (admin down to 420); tables collapse to cards (`admin_bookings.css:162–166,865–868`); no fixed widths > 375px found | Physical 320px device spot-check |
| 23 | PayMongo remains intact and safe | **PASS** | HMAC verify `hash_equals` both te/li (`paymongo_webhook.php:27–60`); amount/currency cross-checks (:92–100); single locked/idempotent crediting path with checkout-session binding (`payment_service.php:8–87`); provider-ID regex (`paymongo.php:95`); reconciliation reference check `hash_equals` (`payment_service.php:107`); snapshotted refund fee% (migration 012, `refund_helper.php`) | Webhook replay integration test |
| 24 | No receptionist module added | **PASS** | Grep "receptionist" across php/js/css = 0 hits; roles remain admin/staff/customer | None |
| 25 | No manual-booking-time feature added | **PASS** | Grep patterns = 0 hits | None |
| 26 | Local Media CMS uploads still function safely | **PASS** | finfo MIME sniffing + forced extension (`upload_media.php:160–214`), byte/pixel/decompression limits (:171–208), random filenames, transactional replace w/ post-commit unlink (:218–255); delete guarded by realpath containment (`delete_media.php:115–127`); hotspot-reference deletion guards; `assets/uploads/.htaccess` kills PHP execution & non-image serving | nginx-equivalent config if not Apache |

---

## 5. Test coverage gaps

Existing tests are minimal: `scripts/test_receipt_itemization.php`, `scripts/test_verification_integrity.php`, `scripts/test_google_oauth.php` (static/dry), `realtime/test/` (Node). Missing coverage, prioritized:

1. **Booking concurrency** — simultaneous submits on same villa/hotel unit; lock-vs-submit expiry race; walk-in hold-proof expiring mid-submission.
2. **Webhook replay/idempotency** — duplicate `checkout_session.payment.paid`; replayed signature with same transaction_id; payment arriving after cancellation (must reject per `payment_service.php:50`).
3. **Late PayMongo payment** — after lock expiry/window; reconciliation when checkout exceeds balance (`payment_service.php:126–130`).
4. **OTP concurrency** — parallel verifies on the same code (the conditional-update predicate at `verify_process.php:50–69` deserves a real race test).
5. **Google OIDC claims** — bad issuer; `aud` array without `azp`; iat skew; `sub` re-linking; suspended-user callback.
6. **Receipt itemization** — priced vs informational room allocations (`receipt_itemization_plan` branches); balance rounding.
7. **Realtime authorization** — customer subscribing to `admin` or another `customer:<id>` channel (must refuse); reconnect backoff; outbox exactly-once across gateway restarts (30s claim lease).
8. **Admin guest limits** — `finalize_event_invoice` over seating-style capacity; walk-in over max capacity.
9. **Event totals** — bundle discount applied exactly once (`submit_walkin.php:383–400`).
10. **Maintenance conflicts** — booking overlapping blocking maintenance; maintenance scheduled over confirmed booking.
11. **Media** — traversal payloads on delete/upload slot regexes; oversized pixel-bomb images.
12. **Export injection** — cell beginning `=SUM(...)` after leading tab/newline/control characters.
13. **Responsive/browser** — Playwright smoke at 320/375/768/1024/1440 incl. modal overflow.
14. **XSS regression (per F-01)** — payload in custom_notes/customer name must not execute.

---

## 6. Deployment-readiness checklist

### Required before production

1. Fix F-01 (escape all innerHTML sinks; sanitize inputs).
2. Fix F-02, F-03, F-04, F-10.
3. Commit canonical DB schema + guarded migrations (F-07); apply recommended indexes.
4. Enforce HTTPS everywhere + HSTS; set `TRUSTED_PROXIES` correctly behind reverse proxy (`request_context.php` already supports it).
5. Schedule cron: `scripts/daily_backup.php` + expired-lock cleanup; offsite backup copy; log rotation for `error_log`.
6. Verify `.env` secrets exist only in production (confirmed not committed to git); rotate any secret ever shared during development.
7. nginx note: replicate `assets/uploads/.htaccess` protections (PHP off, image-only serving) in server config.
8. Health checks: endpoint probing DB + Redis; monitor outbox backlog (unpublished `notification_outbox` count).

### Required before capstone defense

1. Replace `?v=time()` with `filemtime()` (instant performance win on the demo machine) — F-05.
2. Quick accessibility fixes: label associations, modal focus trap/Escape, gold-text contrast — F-06 (rubric-visible).
3. Navbar scrollspy — F-12.
4. Rehearse the two-browser realtime demo (customer books → admin bell; admin confirms → customer toast) and the offline-fallback story (stop Redis → polling continues).
5. Pin Chart.js and flatpickr versions (currently unpinned jsdelivr URLs — supply-chain surprise risk mid-demo).

### Optional post-capstone improvements

- Local replacements for Unsplash imagery (F-16); incremental CSP rollout.
- Persistent DB connections or pooling; remove orphaned `admin_backups` include or finish the module.
- PHPUnit harness covering Section 5.

---

## 7. Prioritized remediation roadmap

### Immediate (this week)

1. **F-01** stored XSS — escape sinks + input sanitization (highest risk-to-effort ratio).
2. **F-02** suspend_user guards; **F-03** APP_BASE_URL for reset links; **F-10** connection-error message.
3. **F-05** cache-busting swap (~51 mechanical edits).

### This week → next

4. **F-04** security headers; **F-08** download_backup CSRF; **F-11** hashed reset tokens.
5. **F-12** scrollspy; **F-06** accessibility batch 1 (labels, modal focus, contrast).

### Before October deployment

6. **F-07** schema/migration hardening + indexes; **F-13** server-side limits; **F-14** prepare-hoisting in reschedule/lock loops; **F-15** move lock GC out of the public endpoint.
7. Build the concurrency/webhook test suite (Section 5 items 1–3, 7).

### Post-capstone

8. **F-09** role-policy alignment decision; **F-16** local imagery; **F-17** `hash_equals` for OTP; **F-18** logout cookie expiry; **F-19** centralize JS alert interpolation; CSP rollout.

---

## 8. Positive verified controls

Genuinely implemented and verified in code:

- **Sessions:** strict mode; HttpOnly/SameSite=Lax/conditional-Secure cookies; `session_regenerate_id(true)` on every privilege change (`login_process.php:104`, verify/Google callbacks); 30-min idle timeout; per-request account-status revalidation that immediately kills suspended accounts (`auth_guard.php:19–46`).
- **CSRF:** consistent `hash_equals` session-token comparison on every audited state-changing endpoint (both form-field and `X-CSRF-TOKEN` header variants); token rotated after authentication transitions.
- **Rate limiting:** atomic single-statement upsert closing the check-then-increment race (`rate_limit.php:32–43`); applied to login, register, OTP verify/resend, password reset, payment sync.
- **SQL:** uniformly prepared statements; the only dynamic SQL fragments are whitelisted constants (overlap predicates from `booking_rules.php`) — no injection path found.
- **Payments:** webhook HMAC verification with constant-time compare and dual test/live secrets; amount AND currency AND provider-reference AND checkout-session-binding verification; one shared row-locked idempotent crediting path for webhook/sync/admin reconciliation backed by `UNIQUE(transaction_id)` (migration 008); overpayment guard; terminal-state refusal; post-commit email/notification dispatch so provider state never rolls back.
- **Booking integrity:** venue-row `FOR UPDATE` serialization; session-scoped date holds with hold-proof re-verification; category-correct overlap semantics (event halls inclusive; overnight checkout-exclusive); maintenance blocks always inclusive; server-authoritative pricing and guest capacities; deterministic lock ordering documented at call sites; outbox events written inside business transactions.
- **Backups:** HMAC-signed dumps failing closed without strong `APP_KEY`; preflight restore into isolated schema; protected filenames; pre-restore safety snapshot.
- **Uploads/media:** content-sniffed MIME → forced safe extension; byte/pixel bomb limits; randomized filenames; realpath containment on delete; hotspot-reference deletion guards; Apache hardening in the uploads directory.
- **Exports:** spreadsheet-formula neutralization including control-character/Unicode-separator prefixes (`csv_safe_value`).
- **Realtime:** origin allowlist; authentication timeout; expiring socket timers; per-channel authorization derived entirely server-side; event-ID dedupe ring; bounded payloads; graceful degradation to polling everywhere.
- **Google OIDC:** nonce + state + 600-second window; issuer/audience/azp/exp/iat/sub-shape/verified-email validation; stable-`sub` linking before email matching; customer-role-only gating; all within one transaction.
- **PDF receipts:** authenticated, owner-checked, DB-authoritative, remote-resource and PHP-tag disabled, chrooted Dompdf.

---

## 9. Audit limitations

- **No browser runtime:** XSS execution (F-01), responsive rendering, focus behavior, and inline PDF display were traced statically, not executed.
- **No disposable database:** concurrency races, migration re-runs, and webhook replays were reasoned from code/DDL, not exercised; the base `sevilla360` schema dump was not in the repo, so column types/FKs for core tables could not be confirmed (see F-07).
- **No external calls:** PayMongo sandbox, Google OIDC round-trip, SMTP delivery, and Redis/WebSocket transport were not contacted, per audit rules.
- **Worktree preservation:** user modifications (vendor/composer autoload files, one new upload image `assets/uploads/venue_rafael_down_vip_suite_360_fed1f77b1e07bca7.jpg`) were observed via `git status` and left untouched; findings exclude vendor internals.
- **Secrets:** `.env` contents were never opened; only `.env.example` variable names were listed. `.env` is confirmed untracked in git (`git ls-files` = 0 matches).
- **Coverage depth:** delegated agent passes covered the endpoint authz matrix and UX checklist; their key negative results (no missing auth guards beyond those reported) were spot-verified by direct reads of representative files, but not every one of the 80+ endpoints was independently re-read line-by-line.

*End of report.*
