# Live source snapshot

This checkout starts from the Hostinger deployment commit `5a1265d669a405eaa64f10811d53530653408053` and includes source files read from the live Hostinger File Manager on 2026-11-06.

Live-only files were copied into this checkout for the PayMongo checkout flow, payment recovery, and image-aware database backup/restore. The local checkout intentionally does not contain the production `.env`, database connection configuration, uploaded customer/media files, payment QR images, payment proofs, or private backup archives. Configure a separate local database and local-only credentials before running the app.

The existing `/var/www/html/Sevilla360` checkout was left intact while creating this live-aligned copy.

The database backup interface, admin action endpoints, setup scripts, and scheduled worker were retired on 2026-11-07. Existing private archives and the authority/maintenance helpers used by checkout remain intact.
