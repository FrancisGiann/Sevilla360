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

Eligible customer/staff receipts are generated server-side as inline A4 PDFs by
`print_receipt.php`. Dompdf runs with remote resources and PHP execution
disabled. The PDF contains authoritative booking/payment values and a
verification reference; it is not presented as tamper-proof, so support should
verify that reference against current server records.

## Manual payment expiry

Apply `migrations/020_manual_payment_submissions.sql` before accepting customer
payment proof. Configure at least one GCash, Maya, or bank-transfer method and
its real account/QR details in Admin Settings. Run the CLI-only expiry command
every five minutes as the application account so unpaid online holds release
promptly:

```cron
*/5 * * * * cd /var/www/html/Sevilla360 && /usr/bin/php scripts/expire_unpaid_bookings.php
```

After a payable Hotel or Villa booking is saved, the booking page opens the
payment-proof step immediately. If no method is enabled, the booking details
and server-calculated amount/deadline still load; proof submission stays disabled
and the customer is directed to contact the resort or return later. Event Hall
inquiries remain payment-free until staff finalizes a quote.

The command cancels only wholly unpaid online bookings past their deadline;
pending receipt review, partial payments, and paid bookings are exempt. Set
`PAYMENT_PROOF_DIR` in `.env` to an absolute path outside the project and
document root (for example `/var/lib/sevilla360/payment-proofs`). The resolver
fails closed for missing, broad, or web-root paths. Provision the directory to
the PHP runtime account (shown as `apache` below) with private permissions:

```sh
sudo install -d -o apache -g apache -m 0700 /var/lib/sevilla360/payment-proofs
```

If PHP-FPM or the scheduled job runs as a different account, use a private
shared group for those service accounts and grant group-only access instead.
Do not make proof storage world-readable or world-writable. Verify from the
deployed host that requesting the configured filesystem path through the web
server is impossible; no proof files are placed in the served project tree.

## Manual payment proof retention

Pending proofs are retained. Approved and rejected proof images are no longer
available through the authenticated proof endpoint once `reviewed_at` is older
than one year; scheduled cleanup only removes the private image file and keeps
submission metadata and audit history. The CLI cleanup is idempotent and
processes at most 100 eligible files per run, so an old backlog drains over
successive runs.

On Hostinger hPanel, add a PHP cron job to run once daily at 03:15 UTC. Set the
command to the full script path shown by File Manager (replace the account and
domain segments with the deployed values):

```text
/home/ACCOUNT/domains/DOMAIN/public_html/scripts/cleanup_payment_proofs.php
```

Hostinger's PHP cron job type expects the PHP file path in the command field;
do not add shell redirection there. For a standard crontab instead, use:

```cron
15 3 * * * cd /var/www/html/Sevilla360 && /usr/bin/php scripts/cleanup_payment_proofs.php
```

Do not use a URL-based cron job: the script intentionally runs only under the
PHP CLI and the configured `PAYMENT_PROOF_DIR` must remain private.

## Refund destination details

Before deploying the customer refund-destination workflow, apply the additive
MariaDB migration. The application does not mutate the database schema
automatically:

```sh
mariadb -u USER -p DATABASE < migrations/022_refund_destination_details.sql
```

The separate rollback artifact is
`migrations/rollback/022_refund_destination_details.sql`. Running it permanently
deletes refund destination details collected after migration 022; export those
details before rollback.

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
