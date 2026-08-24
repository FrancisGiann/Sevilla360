# Sevilla360

Sevilla360 is a web-based booking and virtual showroom platform developed for M.I. Sevilla Resort. It combines an interactive 360-degree tour with reservation features for guests and administrative tools for staff.

Status: work in progress.

## Overview

The application provides a digital resort experience that allows visitors to explore the property virtually, check availability, and submit bookings through a browser-based interface.

## Key Features

- Interactive 360-degree virtual showroom powered by Panolens.js.
- Clickable tags and hotspots for navigating resort areas.
- Online booking flow for guests.
- Separate user and admin interfaces.
- Responsive layouts for desktop and mobile devices.

## Technology Stack

- PHP
- MySQL
- HTML, CSS, JavaScript
- Panolens.js

## Requirements

- PHP 8.1+ environment with a web server such as Apache or Nginx.
- MySQL database.
- A browser for local testing.
- Composer 2 with the locked dependencies, including Dompdf for PDF receipts.

## Local Setup

1. Clone the repository into your web server directory.
2. Create a MySQL database named `sevilla360` or update the database name in `config/db_connect.php`.
3. Update the database credentials in `config/db_connect.php` to match your local environment.
4. Import the project database schema and seed data if available.
5. Open `index.php` in your browser through the configured local server.

For backups, set `BACKUP_DIR` to an absolute writable directory outside the actual web/document root (for example `/var/lib/sevilla360/backups`) and grant the PHP/cron account access. The application fails closed when this value is missing, too broad, or points inside the project/document root. Configure the daily job to run `php scripts/daily_backup.php` under the same account; backup files and logs must remain on private filesystem paths.

Eligible customer/staff receipts are generated server-side as inline A4 PDFs by
`print_receipt.php`. Dompdf runs with remote resources and PHP execution
disabled. The PDF contains authoritative booking/payment values and a
verification reference; it is not presented as tamper-proof, so support should
verify that reference against current server records.

On an Apache host using the `apache` account, provision the private backup directory outside the web root. For this project, `/var/lib/sevilla360/backups` is the recommended path:

```sh
sudo install -d -o francisgiann -g apache -m 2770 /var/lib/sevilla360/backups
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/sevilla360/backups(/.*)?'
sudo restorecon -Rv /var/lib/sevilla360/backups
```

Set `BACKUP_DIR=/var/lib/sevilla360/backups` in `.env`. If the exact SELinux rule already exists, update it with `semanage fcontext -m` instead of `-a`. Run cron as the `francisgiann` owner (or another account with access to the directory). Keep the directory setgid and private; do not use world-writable permissions such as `0777` or enable broad home-directory access for Apache.

## Project Structure

- `index.php` - main landing page.
- `booking.php` - booking interface.
- `showroom.php` - virtual showroom experience.
- `user_dashboard.php` - user dashboard.
- `admin_dashboard.php` - admin dashboard entry point.
- `actions/` - authentication and booking processing scripts.
- `includes/` - shared layout components and page sections.
- `assets/` - CSS and JavaScript assets.
- `config/` - database connection and configuration.

## Optional realtime notifications

The application always retains its authenticated short-polling notification
fallback. For deployment-grade immediate delivery, apply
`migrations/014_notification_outbox.sql`, install the dependencies declared in
`realtime/package.json`, provision Redis, and run `node realtime/gateway.mjs`
behind TLS. Set `REALTIME_ENABLED=1`, a random `REALTIME_SIGNING_KEY` of at
least 32 characters, the exact `REALTIME_WS_URL`, and an exact
`REALTIME_ALLOWED_ORIGINS` list. The PHP mutation transaction writes the
outbox; the gateway claims and publishes it through Redis Pub/Sub, authorizing
only `admin` or the authenticated customer's own `customer:<user_id>` channel.
The browser uses a short-lived signed token, bounded event deduplication, and
backoff reconnects. Keep WebSocket access logs from recording the first auth
frame or token values and use `wss://`; polling remains the source-of-truth
refresh path when Redis or the gateway is unavailable.

The local environment intentionally does not install Redis or realtime Node
packages. Run `node --test realtime/test/*.test.mjs` for deterministic token,
channel, dedupe, and backoff checks. A live Redis/WebSocket check is a
deployment prerequisite, not a local pass claim.

## Optional Google sign-in

Google customer sign-in is hidden unless `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET`, and an exact HTTPS `GOOGLE_REDIRECT_URI` are set and
`google/apiclient:^2.15` has been installed with Composer. Apply
`migrations/015_google_subject.sql` before enabling it. The flow uses Google's
official client library `verifyIdToken()` (including Google's signing-key
validation), then checks issuer, audience/authorized party, expiry, nonce,
subject, and `email_verified`. Google `sub` is linked before verified-email
fallback, and staff/admin roles can never be linked through this customer
button. No credentials are included in this repository.

Run `php scripts/test_google_oauth.php` for local claim-validation checks.

## Navigation and browser history evidence

The public header marks only explicitly supplied existing routes (`home` and
`showroom`); booking/support/auth pages do not default to Home as an active
link. There are no Book or Contact header destinations. Browser Back is not
replaced with a forced Home action: the remaining `replaceState` calls only
clean booking/payment query parameters after the result has been processed,
and the only hero-page scroll reset affects scroll position, not history.

## Notes

- The project is currently under active development.
- Some setup details may change as the system matures.

## Author

Created by Francis Giann Mendevil Empleo
