# Sevilla360 staged phone signup and recovery handoff

This checkout is an isolated review branch. Nothing here authorizes production deployment, a real SMS, account creation, credential entry, or a flag change. Public booking, walkthrough, and pending help-icon work is untouched.

## Safe deployment order

1. Confirm a database backup, the target schema, and the manual migration receipt for production-applied `033_proof_submission_claims.sql`. This repository has no migration runner. On a disposable staging database, apply `034_customer_contact_verification.sql` and then `035_dual_channel_recovery.sql` once; compare columns, indexes, foreign keys, and existing-account defaults. Repeat that review for production before any approved schema change.
2. Deploy the code with `PHONE_SIGNUP_ENABLED=0`, `PASSWORD_SMS_RECOVERY_ENABLED=0`, `UNISMS_SMS_ENABLED=0`, and `UNISMS_DAILY_SMS_LIMIT=0`. Existing customers and staff retain email/password sign-in and their established email reset behavior. Confirm login, forgot password, profile settings, and admin access. The new database columns are required by the integrated code even while flags are off.
3. Run staging browser QA at desktop and mobile widths. Check the site's actual typography, gold/dark palette, focus visibility, keyboard order, modal focus, browser back, duplicate requests, and the recovery code and password forms. A browser surface was unavailable in the local executor; local HTTP and CSS checks do not replace this step.
4. Only after approval for one controlled staging SMS: use a synthetic account and one expressly approved recipient. Set a staging daily limit of **1**, enable `PHONE_SIGNUP_ENABLED=1` and `UNISMS_SMS_ENABLED=1`, keep `PASSWORD_SMS_RECOVERY_ENABLED=0`, and submit one signup code. Observe whether UniSMS accepted it, whether it arrived, and whether a correct code activates the account. Acceptance/queued status is not delivery. If the request fails or times out, do not automatically retry or send to another number without a fresh decision. Return both staging send flags to `0` afterward.
5. Recovery SMS needs its own later approved test and flag change. Keep all production flags off until rollout approval and a verified recovery policy.

## Server-side fields and access

The user copies the UniSMS secret from the signed-in UniSMS dashboard and pastes it **only** into `UNISMS_SECRET_KEY=` in the server's protected `.env` through an authorized SSH/SFTP or Hostinger file-management session. It is the Basic-auth username for the server POST; the Basic-auth password is empty. Never paste the key into chat, client JavaScript, a form, a commit, a screenshot, a log, or a URL. The helper performing this step needs write access to the deployed server's protected `.env`, while migration work needs separately authorized database administration access. Neither access nor the secret is present in this checkout.

The same server file must specify the account-assigned `UNISMS_SENDER_ID=` (currently verified as `Unisoft`), a reviewed `UNISMS_DAILY_SMS_LIMIT=`, and the three flags above. Existing `APP_KEY=` must be a stable random key of at least 32 characters to HMAC OTPs, and `APP_BASE_URL=` must be valid for email reset links. Email delivery still uses existing `RESEND_API_KEY=` and `RESEND_FROM_EMAIL=` server settings. Do not rotate `APP_KEY` casually: outstanding verification codes depend on it. The user's reported 10 UniSMS credits are a planning input, not permission to spend them.

## Local evidence and limits

The committed branch contains no test-mode switch. Local HTTP QA ran from a temporary copy with a loopback-only server, a disposable MariaDB database, synthetic accounts, and replacement email/SMS functions that wrote to local private logs. That copy is separate from the review branch and must never be deployed. It passed 20 signup/recovery HTTP checks, 8 interruption/expiry checks, and 8 legacy-email/pending-phone HTTP checks. Database tests passed 22 signup and 29 combined recovery checks; provider mocks passed 18 UniSMS and 17 recovery checks. The local executor exposed no browser surface, so it produced no screenshots or Library image IDs and could not confirm rendered mobile/desktop layout or keyboard focus behavior.

## Rollback

Turning all three feature flags off and the daily limit to zero stops new SMS operations. Keep migrations 034/035 and their login, reset, and ownership gates while affected accounts exist. Pending customers can sign in with their password to request a code or contact support; active customers can still sign in with email and password. Reverting the gates or dropping tables would lose ownership evidence and could allow reset to an unverified inbox. A full rollback requires a reviewed account-by-account migration.
