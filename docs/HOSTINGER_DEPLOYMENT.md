# Hostinger Web Hosting deployment

This guide covers the PHP website flow in Hostinger hPanel for the confirmed domain `misevillas.com`. Replace `ACCOUNT` in server paths with the hosting account name shown in hPanel.

## Before connecting Git

The GitHub deployment contains only committed files from the selected branch. Review the release contents before publishing, and ensure `assets/uploads/home-hero-expanded-pool.png` is included because `index.php` uses it as the homepage fallback when no CMS hero image is assigned.

Hostinger supports deploying a new site through **Websites → Import website → Deploy from GitHub**, or connecting GitHub from an existing site's **Dashboard → Advanced → Git** page. Select `FrancisGiann/Sevilla360`, branch `main`, and `public_html` as the root directory. Keep automatic deployment disabled until configuration and smoke checks pass. A redeploy or repository change can overwrite files in the target directory, so make a separate copy of uploads before deployment. See [Hostinger's Git deployment guide](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/).

Before adding any database, SMTP, or API credentials, verify that the deployed web server enforces `.htaccess`. In `public_html`, create a temporary `.env.deploy-check` containing only a harmless marker, request `https://misevillas.com/.env.deploy-check` and discard the response body, then confirm the status is 403 or 404. Also check `https://misevillas.com/composer.lock`, `/config/db_connect.php`, `/migrations/001_room_number.sql`, and `/.git/config`, discarding each response body. Delete the marker afterward. If any protected path returns 200, stop setup and have the hosting access rules corrected before adding real credentials, proof files, or database data. Never open or print the response body from a real `.env` URL.

## PHP and Composer

The application requires PHP 8.1 or newer, `mysqli`, sessions, and `fileinfo`. The locked Composer dependencies also require `ctype`, `dom`, `filter`, `hash`, `iconv`, and `mbstring`. The optional XLSX import needs `zip` and `xmlreader`. Confirm needed extensions in the selected PHP version in hPanel. The project uses Composer 2 and `composer.lock`; do not run `composer update` for deployment.

After Git deployment, use SSH if it is enabled for the hosting plan. Check the Composer 2 alias and install the locked production dependencies from the deployed project directory:

```sh
composer2 --version
cd ~/domains/misevillas.com/public_html
composer2 install --no-dev --optimize-autoloader
```

Hostinger's [Composer guide](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/) documents its Composer 2 command. See [Hostinger SSH access](https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/) if SSH is not configured.

## Database and server configuration

Create the destination MySQL database and database user in hPanel. The repository has incremental SQL migrations in `migrations/`, but it has no fresh-install schema dump or migration runner. Do not import those migrations into an empty database as if they were a baseline, and do not replay them blindly on a database dump that already includes them.

Export the current application's full schema and data from its source database, then import that dump into the new database. A phpMyAdmin export/import is also suitable. For a CLI transfer, `-p` prompts for each database password. Use a private working directory outside `public_html`; for InnoDB tables, `--single-transaction` provides a consistent snapshot and `--no-tablespaces` avoids a tablespace privilege. Pause writes during export if any source tables use a non-transactional engine:

```sh
# Run on the source database host; keep this directory outside its web root.
umask 077
mkdir -p /private/path/.sevilla360
chmod 700 /private/path/.sevilla360
mysqldump --single-transaction --no-tablespaces --routines --events --triggers -h SOURCE_HOST -u SOURCE_USER -p SOURCE_DB > /private/path/.sevilla360/sevilla360.sql
# On the target, create the private transfer directory before using SFTP:
mkdir -p /home/ACCOUNT/.sevilla360
chmod 700 /home/ACCOUNT/.sevilla360
# Transfer the private file to /home/ACCOUNT/.sevilla360/sevilla360.sql on the target.
# Run on the target after transfer:
mysql -h TARGET_HOST -u TARGET_USER -p TARGET_DB < /home/ACCOUNT/.sevilla360/sevilla360.sql
rm /home/ACCOUNT/.sevilla360/sevilla360.sql
# After verifying the import, also remove the source copy:
rm /private/path/.sevilla360/sevilla360.sql
```

Transfer the private dump using SFTP or another private channel; never place it in `public_html`. Compare the imported schema against the migrations before applying any missing migration, and apply only migrations confirmed absent, in order. If a full current database export is unavailable, database setup is blocked until the original schema and data can be recovered.

Create a server-only `.env` in the deployed project root. The app loads it from that location; `.env` is ignored by Git and the root `.htaccess` denies web requests for environment files. Set at least `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, and `APP_BASE_URL=https://misevillas.com` using hPanel's database details and the confirmed HTTPS domain. Configure `SMTP_EMAIL` and `SMTP_PASSWORD` if the deployed workflows must send email. Do not copy credentials from a developer machine or commit `.env`; set the file owner to the PHP account and mode to `0600`:

```sh
chmod 600 ~/domains/misevillas.com/public_html/.env
```

Manual payment proof upload requires `PAYMENT_PROOF_DIR` to point to a writable absolute directory outside both `public_html` and the application tree, for example `/home/ACCOUNT/.sevilla360/payment-proofs`. Provision it for the PHP account with private permissions (`0700`):

```sh
mkdir -p /home/ACCOUNT/.sevilla360/payment-proofs
chmod 700 /home/ACCOUNT/.sevilla360 /home/ACCOUNT/.sevilla360/payment-proofs
```

The resolver rejects paths inside the web root and fails closed if the private directory is unavailable.

If the database backup feature is included in the published branch, configure a separate private `BACKUP_DIR`, a deployment-specific `APP_KEY` of at least 32 characters, and the documented backup limits. Create its directory with mode `0700`:

```sh
mkdir -p /home/ACCOUNT/.sevilla360/backups
chmod 700 /home/ACCOUNT/.sevilla360/backups
```

Backup and restore also depend on `proc_open`, `mysqldump` or `mariadb-dump`, and `mysql` or `mariadb` being available to PHP. Restore additionally requires a dedicated disposable staging database; never use production as staging. Keep proof files, database archives, job state, and SQL exports outside `public_html`.

## Media and scheduled jobs

The database stores media paths, while the image files live separately under `assets/uploads`. Copy the complete source uploads directory, including files added through the CMS, and preserve those files across every redeploy. Confirm the intended release contains `assets/uploads/home-hero-expanded-pool.png` and every media path referenced by the imported database.

For the virtual showroom, keep Hostinger's **Smart image optimisation** disabled for this website. It can resize an 8704×4352 panorama response to 1600×800 even when the origin file is intact, which makes the 360° view visibly soft. CDN/WebP delivery can remain enabled. After redeploying or replacing media, flush the site's CDN cache and verify a panorama URL is served at its original dimensions before investigating viewer code.

Add the PHP cron jobs in **Websites → Dashboard → Cron Jobs**. Hostinger's PHP cron type takes an absolute PHP file path; its schedule uses UTC+0. The scripts are CLI-only, so do not call them through a browser URL:

| Schedule | PHP file path | Purpose |
| --- | --- | --- |
| Every 5 minutes | `/home/ACCOUNT/domains/misevillas.com/public_html/scripts/expire_unpaid_bookings.php` | Release expired, wholly unpaid booking holds. |
| Daily at 03:15 UTC | `/home/ACCOUNT/domains/misevillas.com/public_html/scripts/cleanup_payment_proofs.php` | Remove eligible reviewed proofs older than one year. |
| Every 5 minutes, only if the backup feature is deployed | `/home/ACCOUNT/domains/misevillas.com/public_html/scripts/database_backup_worker.php` | Process backup/restore jobs and retention. |

03:15 UTC is 11:15 in the Philippines. The backup worker requires its private directory and database tools to be available. See [Hostinger's cron job guide](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

Realtime notifications are optional for initial hosting; the authenticated short-polling fallback remains available. Enabling the gateway later also requires its migration, Node dependencies, Redis, TLS WebSockets, and the signing/origin settings documented in the project README.

## First-deploy checks

Before enabling automatic deployment or announcing the site:

1. Confirm HTTPS works at `https://misevillas.com` and `APP_BASE_URL` uses that HTTPS URL.
2. Open the home, booking, login, and showroom pages; check that static assets and CMS images load.
3. Confirm database-backed pages work and test booking/payment workflows only with safe test data.
4. Check the HTTP status only for `https://misevillas.com/.env`, `/composer.lock`, `/config/db_connect.php`, `/migrations/001_room_number.sql`, `/scripts/cleanup_payment_proofs.php`, and `/.git/config`; each must return 403 or 404. Discard response bodies, especially for `.env`. The `.well-known` path remains available for Hostinger certificate validation.
5. Confirm private payment proofs and backup files cannot be fetched through a URL, then verify scheduled jobs run from hPanel.

The local Apache vhost used during preparation sets `AllowOverride None` for `/var/www/html` and returned HTTP 200 for protected paths, so it does not prove that `.htaccess` is enforced. An isolated Apache config with `AllowOverride All` passed the checks. Treat the harmless-marker check above as a deployment gate: do not add real credentials or database data until protected paths return 403 or 404. If they return 200, stop and have the hosting configuration corrected before proceeding.

For later code releases, use hPanel's Redeploy action for the connected repository and branch. Before each redeploy, preserve any CMS-uploaded files that are not in Git. This preparation does not commit, push, or deploy code.
