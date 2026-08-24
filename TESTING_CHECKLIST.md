# Sevilla360 end-to-end test checklist

Use this checklist on a **test/staging copy** of the system. Do not run payment, import, restore, delete, or staff-account tests against live production data.

For every failed item, record: test ID, date/time, browser/device, account used, exact steps, expected result, actual result, screenshot/video, and the browser Network/Console error (if any).

## 0. Test setup

- [ ] Confirm the site opens through the web server (not by opening `.php` files directly).
- [ ] Use a staging database with realistic venue, hotel room, villa, event-hall, media, and price data.
- [ ] Create separate accounts: unverified customer, verified customer, suspended customer, staff, and admin.
- [ ] Prepare an inbox for email testing and a PayMongo **test** account/webhook endpoint.
- [ ] Prepare dates that are: available, already booked, under blocking maintenance, past, today, and a multi-night range.
- [ ] Record the initial booking/payment/venue counts so test data can be identified and cleaned up safely.
- [ ] In browser developer tools, keep Console and Network open; verify there are no unexpected JavaScript errors or failed requests during every flow.
- [ ] If realtime is enabled in staging, verify Redis and the gateway are provisioned with TLS, exact `REALTIME_ALLOWED_ORIGINS`, and redacted WebSocket/auth logs; otherwise record realtime as infrastructure-blocked and verify polling fallback.

## 1. Public site and showroom

- [ ] Open the home page in a private/incognito window: page, logo, navigation, images, and footer render without broken links/images.
- [ ] Test every header/footer link, including Home, Booking, Showroom, Sign in/Register, and policy/contact links.
- [ ] Open the virtual showroom: panorama loads, can be dragged/zoomed, and does not freeze or show a blank screen.
- [ ] Test every showroom hotspot/tag: label is correct, opens the correct view/details, and can be closed or navigated back from.
- [ ] Verify all venue cards/images show the correct venue and lead to the intended booking/showroom path.
- [ ] Resize from desktop to tablet to phone widths: navigation, modals, forms, calendar, cards, and buttons remain usable with no clipped content or horizontal scrolling.
- [ ] Test keyboard-only navigation: visible focus, sensible tab order, Enter/Space activation, Escape closes dialogs, and no keyboard trap.
- [ ] Check common public error cases: unknown URL (404), offline/slow connection, browser refresh mid-navigation, and browser Back/Forward.

## 2. Registration, verification, login, and password recovery

- [ ] Register with valid customer details; account is created once, success feedback is clear, and no password is visible in a response or URL.
- [ ] Try required fields blank, malformed email, weak/mismatched password, and duplicate email; each is rejected clearly without creating an account.
- [ ] Verify the account using the emailed code; valid code succeeds and an invalid, expired, reused, or malformed code fails safely.
- [ ] Test resend-code: email arrives, rate limiting prevents repeated rapid sends, and the most recent valid code works.
- [ ] Log in as a verified customer; confirm redirect to the user dashboard and an authenticated session is established.
- [ ] When configured, test Google sign-in with state mismatch, expired state, nonce mismatch, unverified email, wrong audience/issuer, suspended/staff email, first-time verified customer, and returning account with the same Google `sub`; no privilege escalation or duplicate customer is allowed.
- [ ] Attempt customer login with wrong password, unknown email, unverified account, and suspended account; errors must not reveal unnecessary account details.
- [ ] Attempt to use the customer login as staff/admin and the admin login as customer; each is rejected or sent to its correct area.
- [ ] Log in as staff and admin; confirm each sees only permitted dashboard functions.
- [ ] Use Forgot Password with valid, unknown, and malformed emails; valid user receives a usable reset path without leaking whether arbitrary emails exist.
- [ ] Reset password with a valid token/code; login succeeds only with the new password. Expired, reused, invalid, and mismatched reset inputs fail.
- [ ] Log out, then use browser Back and direct dashboard URLs; protected content must not be accessible.

## 3. Booking flow — availability and prices

- [ ] Start a booking while logged out; confirm the system prompts for login or preserves the intended booking path appropriately.
- [ ] Test every venue category: Hotel Room, Resort Villa, and Event Hall.
- [ ] Select an available date range: availability is shown correctly and the next step is enabled.
- [ ] Try a past date, invalid range (end before start), same-day range, one-night/day range, and multi-night range; the behavior matches resort policy and price calculation.
- [ ] Try a range overlapping an existing Pending, Confirmed, or Completed booking; it must not be bookable.
- [ ] Try a blocking-maintenance range; it must not be bookable. Verify non-blocking maintenance behaves as intended.
- [ ] Open the same availability in two separate browsers/accounts; lock a date in one and verify the other cannot complete a conflicting booking.
- [ ] Let a booking lock expire; verify the dates become available again and the expired session cannot submit unexpectedly.
- [ ] For a Hotel Room, verify nightly total, base capacity, extra-person rate, add-ons, and multi-night total against the admin-configured values.
- [ ] For a Villa, verify day-time versus overnight selection, overnight surcharge, base capacity, extra-person rate, add-ons, and multi-day total.
- [ ] For an Event Hall, verify inquiry behavior, event type/style/notes, room-group selection and allocation, the labeled estimate, and zero upfront payment while awaiting staff confirmation.
- [ ] Change dates, guests, stay type, room groups, and add-ons repeatedly; totals and UI must update correctly with no duplicate add-ons/rooms.
- [ ] Confirm the final review shows correct customer, venue, dates, guest count, notes, payment scheme, itemized price, and total.

## 4. Booking submission and payment

- [ ] Submit a valid booking once: exactly one booking/reference number is created and confirmation feedback is shown.
- [ ] Double-click Submit, refresh during submission, and use browser Back/Forward; no duplicate booking or payment session should be created.
- [ ] Submit after selecting conflicting dates in another browser; submission must fail cleanly and must not create a partial booking.
- [ ] Submit with altered client-side values (price, venue ID, guests, dates, add-on amount) using browser developer tools; the server must reject or recalculate values correctly.
- [ ] Verify booking status, payment status, amount paid, total, source, add-ons, event/villa details, and allocated rooms in the dashboard/database match the submitted booking.
- [ ] For each supported payment scheme, test staff-recorded manual payment references. Verify booking confirmation, payment record, receipt email, notification, and amount.
- [ ] For each supported payment scheme, test successful payment using PayMongo test mode. Verify redirect/return behavior, booking confirmation, payment record, receipt email, notification, and amount.
- [ ] Test failed, cancelled, abandoned, delayed, and partial/downpayment manual payments; status and amount must remain accurate and staff can record approved references for remaining balances where allowed.
- [ ] Test failed, cancelled, abandoned, delayed, and partial/downpayment PayMongo payments; status and amount must remain accurate and the customer must be able to pay the remaining balance where allowed.
- [ ] Submit the same manual payment reference twice and verify duplicate transaction protection.
- [ ] Deliver the same PayMongo webhook twice; payment must be recorded only once.
- [ ] Send a webhook with missing, invalid, or expired signature, malformed JSON, unknown reference, and non-payment event; it must not update a booking.
- [ ] Verify event-hall inquiry does not incorrectly require/record an online payment.
- [ ] Verify receipt content: recipient, reference, venue, payment status, amount, currency, and no incorrect guest data.
- [ ] Open an eligible customer/staff receipt and verify the response is an inline `application/pdf` document, the PDF text contains authoritative booking/payment details, allocated rooms, successful transaction IDs, totals, balance, and the verification reference, and no external fonts/assets are requested.
- [ ] Confirm owner mismatch, unknown IDs, Pending/Cancelled bookings, and `To Be Arranged` bookings cannot generate a receipt PDF.

## 5. Customer dashboard

- [ ] Verify dashboard counts, booking cards, status labels, filters, and booking-details modal match actual test records.
- [ ] Test every booking state: Pending, Confirmed/Unpaid, Confirmed/Partial, Confirmed/Paid, Pending Refund, Cancelled, and Completed (where applicable).
- [ ] Have staff record an approved manual payment reference for an existing unpaid/partial booking; verify remaining balance, payment status, receipt, notification, and duplicate-reference protection.
- [ ] Pay an existing unpaid/partial eligible booking; verify remaining balance, payment status, receipt, notification, and duplicate-payment protection.
- [ ] Request a cancellation for an unpaid booking and a paid/partial booking; verify reason capture, policy/refund messaging, status, staff visibility, and notifications.
- [ ] Request a reschedule: test available and unavailable dates, then confirm the request appears for staff/admin without changing the original booking prematurely.
- [ ] Update profile/settings with valid values, blank values, malformed email/phone, and password changes if offered; verify only intended fields change.
- [ ] Mark notifications read; check it persists after refresh and that one customer cannot access another customer's booking details by changing an ID in the request.
- [ ] Submit a customer booking and confirm the admin notification list refreshes immediately through WebSocket when enabled, or within the documented polling interval otherwise; no homepage toast/popup appears.
- [ ] Change booking status, payment, cancellation, and reschedule state as staff/admin and confirm only the affected customer's notification list refreshes; replay one event and verify bounded client deduplication.

## 6. Staff and admin booking operations

- [ ] As staff, open Overview, Bookings, Walk-in, Calendar, Maintenance, and Settings. All permitted pages/actions load and save correctly.
- [ ] As admin, also test Users, Audit Log, CMS, and authorized backup endpoints/scripts.
- [ ] Confirm a staff account cannot access admin-only pages or call admin-only action URLs directly.
- [ ] Search, filter, paginate, and open booking details; results must be correct and no records should be skipped or duplicated.
- [ ] Change booking status through each permitted transition; verify customer dashboard, calendar, counts, audit log, and notifications update consistently.
- [ ] Test approve/reject cancellation and reschedule requests, including refund/adjustment amounts. Verify date conflicts are checked again on approval.
- [ ] Create walk-in bookings for every venue type; totals, dates, capacity, allocated rooms, status, payment, and source must be correct.
- [ ] Create a walk-in booking that conflicts with an online booking, maintenance, or another room allocation; it must be refused.
- [ ] Resend a receipt for eligible and ineligible bookings; correct email is sent only when allowed.
- [ ] Confirm master calendar displays bookings and maintenance on correct inclusive/exclusive dates, with correct labels and no cancelled items blocking availability.

## 7. Maintenance and venue settings

- [ ] Add blocking maintenance to an open venue/date range; it appears in the calendar and prevents online and walk-in booking.
- [ ] Attempt maintenance over existing bookings/allocated rooms or another maintenance record; expected conflict handling is clear and no partial records are created.
- [ ] Add non-blocking maintenance; verify intended visibility and booking behavior.
- [ ] Complete and delete/cancel maintenance; verify calendar, venue availability, related maintenance booking (if used), audit log, and availability update correctly.
- [ ] Edit venue status, capacity, rates, and other settings; verify changes appear in booking calculation and public display where intended.
- [ ] Enable and disable site maintenance mode; public behavior and staff/admin access match the intended policy.

## 8. User, CMS, media, and hotspot management

- [ ] Create, edit, suspend, reactivate, and delete/deactivate staff accounts as admin. Check login permissions after every change.
- [ ] Verify the last remaining admin cannot be demoted or deleted.
- [ ] Verify customer suspension blocks login/booking but does not corrupt prior bookings or payments.
- [ ] Check audit-log entries for login-sensitive and administrative actions: actor, action, timestamp, and IP/value details are sensible.
- [ ] Upload valid supported images; verify preview, orientation, size, public rendering, primary-media selection, and persistence after refresh.
- [ ] Try oversized, unsupported, corrupt, renamed executable/script, and duplicate files; uploads must fail safely with no orphaned file/DB record.
- [ ] Delete media that is unused and media currently marked primary/in use; UI and public pages must not retain broken images.
- [ ] Create, edit, and delete showroom hotspots; verify position, target/description, ordering, and appearance in the public showroom.
- [ ] Enter special characters and HTML/JavaScript-like text in venue names, notes, hotspot labels, profile fields, and CMS content; it must display as text and never execute.

## 9. Backup and recovery (staging only)

- [ ] Create a backup; it appears in the list, has a plausible non-zero size, is downloadable, and is recorded in audit logs.
- [ ] Download a backup; it has the expected file type/content and an unauthorized user cannot download it.
- [ ] Import a known-good backup; it is accepted, listed, and can be restored.
- [ ] Attempt import of corrupt, tampered, wrong-format, oversized, and duplicate files; each fails safely without replacing data.
- [ ] Before restoring, create a uniquely identifiable test record; restore a selected backup and verify the data returns exactly to that snapshot (including expected loss of the later test record).
- [ ] Delete a backup; confirm only the selected backup is removed from the list/storage and the action is audited.

## 10. Security and resilience checks

- [ ] Open every protected page and action URL while logged out, as customer, as staff, and as admin; authorization is enforced server-side, not just by hidden buttons.
- [ ] For every state-changing form/request, omit or alter the CSRF token; request must be rejected without changing data.
- [ ] Test URL/query/body IDs with another user's booking, venue, backup, media, maintenance, and staff IDs; cross-account access/modification must be denied.
- [ ] Test SQL-injection-like input (`' OR 1=1 --`) in login, search, booking, and settings fields; no data is exposed or altered.
- [ ] Test stored and reflected XSS-like input (`<script>alert(1)</script>`) in all user-entered fields; script must never execute.
- [ ] Check sensitive responses, redirects, HTML, browser storage, logs, and error messages for passwords, database credentials, API keys, webhook secrets, or full payment data.
- [ ] Confirm production uses HTTPS, secure cookies, appropriate session expiry, and logout invalidates the session.
- [ ] Trigger invalid requests/server-side errors; users receive safe messages and the app does not expose stack traces or SQL errors.
- [ ] Run `node --test realtime/test/*.test.mjs` and `php scripts/test_google_oauth.php`; record live Redis/WebSocket and Google credential checks separately because they require deployment infrastructure/configuration.
- [ ] Verify rate limiting for login, registration/verification resend, password reset, and payment-related actions behaves as intended without locking legitimate users permanently.

## 11. Compatibility, accessibility, and final release pass

- [ ] Repeat the critical path (home → register/login → booking → payment → receipt → dashboard) in current Chrome, Firefox, Edge, and Safari; also test Android Chrome and iPhone Safari if supported.
- [ ] Test at 320px mobile width, common tablet width, and desktop width. Check touch targets, date picker, modal scrolling, forms, and virtual showroom controls.
- [ ] Test with slow 3G/network interruption. Loading states are clear, retries do not duplicate records, and errors are understandable.
- [ ] Check page titles, language/spelling, currency/date format, required-field labels, error messages, contrast, image alt text, and form error announcement.
- [ ] Run a final end-to-end booking as a clean customer account and reconcile it in the admin dashboard, calendar, payments, email inbox, notifications, and audit log.
- [ ] Review unresolved failures. Release only when all critical items (authentication, availability, booking duplication, payment/webhook, authorization, backups) pass or have an accepted documented mitigation.

## Suggested test evidence table

| Test ID | Result | Browser/device | Evidence / defect link | Tester / date |
|---|---|---|---|---|
| Example: 4.8 | Pass / Fail / Blocked | Chrome / Windows | Screenshot or ticket | Name / YYYY-MM-DD |
