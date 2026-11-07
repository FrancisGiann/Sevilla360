# Sevilla360 local PayMongo checkout

The isolated checkout now has a **real PayMongo v1 test-mode Hosted Checkout adapter**, a fake HTTP transport for that adapter, and the original simulator. Automatic receipt/inventory reconciliation is shared across all three. **One explicitly approved real test-mode checkout creation succeeded. No payment was completed and no follow-on provider request was made.**

## Start locally

```sh
cd /home/francisgiann/Documents/Codex/2026-11-05/task-5/sevilla360-paymongo
# Run the real adapter against its fake HTTP transport, without credentials or network:
SEVILLA_PM_DRIVER=adapter_fixture bash scripts/paymongo/local.sh serve
```

Open **http://127.0.0.1:8787**. It has fictional rooms, villa, quoted hall and an unquoted inquiry. Open checkout, simulate payment, withhold a webhook and recover, expire the hold, or deliver a late payment. Valid payments automatically confirm the reservation; exceptions retain money separately without claiming inventory. Deposits and balances are separate checkouts.

`bash scripts/paymongo/local.sh serve` selects the original simulator. The adapter-fixture driver serializes actual API requests and parses response bodies using `provider.php`, but intercepts every request in memory/database. Its hosted URL returns to the local fixture screen; it is not a real PayMongo page.

Requirements: Bash, PHP 8.1+ with mysqli (curl for future real HTTP), MariaDB executables, Python 3 for HTTP tests, Node for JavaScript/regression checks. No Composer install or existing `.env` is needed. Ctrl-C stops the disposable socket-only database and deletes fixture data. Port 8787 must be free.

## What is integrated, precisely

- `assets/js/paymongo_checkout.js` implements the existing `openBooking` interface. The booking and dashboard controllers select it directly only with the local-runtime meta marker and loopback hostname. No manual-payment client is needed in this mode.
- `booking.php` and `user_dashboard.php` omit the legacy payment script, CSS and proof modal in the isolated runtime. Outside it, the original manual module and behavior remain.
- The isolated router supplies that marker, serves the **actual original payment module**, and routes `/actions/user/pay_existing.php`, `/actions/user/sync_payment.php`, `/actions/user/close_paymongo_checkout.php`, and `/actions/bookings/paymongo_webhook.php` into the hosted flow. CSRF and fixture-customer ownership are checked before provider operations.
- The local screen calls the same client interface. A genuine hosted-checkout return containing an attempt ID triggers authoritative retrieval; neither success nor cancel query strings are payment proof.
- The real adapter creates/retrieves/expires sessions. It rejects live keys/resources, arbitrary API paths and hosted redirects. It uses HTTPS/basic auth, bounded timeouts/body size, no redirect following, no automatic POST retries, no customer billing data and no email receipts. Errors never echo provider response bodies or authorization headers.
- A signed webhook or authenticated server retrieval reconciles into the same transactional receipt engine. A verified webhook can recover the session binding when creation succeeded remotely but its response was lost locally.

### Original full-app workflow

The original PHP login, booking and dashboard now run in an isolated synthetic application runtime:

```sh
bash scripts/paymongo/local.sh full-app-serve
```

Open **http://127.0.0.1:8787**. Synthetic login: **guest@example.invalid** / **Synthetic1!password**. A second isolated customer is **other@example.invalid** with the same public fixture password. These accounts exist only in the disposable database. Choose Garden room or Pool villa in the original booking page, submit, then complete the local hosted-checkout substitute. The original dashboard and booking-details endpoint show confirmed/paid state and payment history. Ctrl-C discards this runtime and its fixture accounts.

This mode **always uses fake HTTP transport**, strips inherited PayMongo configuration, and cannot invoke the real provider. The actual original password authentication, CSRF/account guards, canonical room selection, pricing, booking writes, inventory helpers and dashboard/detail queries execute. The payment transaction additionally writes the original `payments` ledger with unique provider payment IDs and `Success` status. No manual proof endpoint is enabled in this local router.

The repository has no complete baseline schema: migrations 001–036 assume pre-existing users, customers, venues, bookings and other base tables. Existing integration tests supply narrow table subsets, not a full application dump. `full_app_schema.sql` therefore builds **synthetic base tables inferred from current queries**, then the launcher applies the repository's actual migrations **020, 023, 029 and 032**. It does not read/copy a real database, backup, dump or personal record. This is not a production migration or certification of every admin schema path.

The temporary runtime copies PHP/JS/CSS code only, substitutes synthetic `config/env.php` and a socket-only `config/db_connect.php`, and stubs outbound mail plus the unrelated backup write gate. Original login and booking endpoint bodies are not replaced. Notification rows remain local. A strict route allowlist exposes only selected original pages and actions (including staff quote, refund-recording and booking-history endpoints); a CSP blocks external assets/connections. The full-app router emits the opt-in marker and preserves manual defaults everywhere outside this explicit local runtime.

**Verified boundary:** original PHP HTTP journeys, not rendered browser end-to-end. Canonical hotel selection → submission → deposit → repeat/recovery → close/reentry → balance → original dashboard/payment history passed, as did villa reservation and customer isolation. Original staff password login, Event Hall quotation finalization, automatic hall deposit, unresolved-checkout requote rejection, administrative booking history and payment details now pass. The local quote flag means **staff-finalized**, not separate customer quote-signature evidence; the hosted payment button remains the explicit customer payment action. Quote versions invalidate stale snapshots.

Original customer refund requests and staff refund recording also pass, including blocking further checkout, rejecting duplicate recording, retaining original payment evidence and preventing old payment recovery from reviving a refunded booking. This records a fictional `SYNTHETIC-NO-FUNDS-001` reference; it does **not** invoke or verify a PayMongo refund. Refund automation remains unimplemented. The synthetic staff login is `staff@example.invalid` with the same public fixture password, but the full admin dashboard page is not in the route allowlist: tests exercise its original history/detail/action endpoints, not its rendered UI. PDFs, signup/OTP and other admin workflows remain unverified. External fonts/CDN assets are intentionally blocked and may affect browser appearance.

## Real provider configuration and approval boundary

Configuration-name inspection found `PAYMONGO_SECRET_KEY` and `PAYMONGO_WEBHOOK_SECRET` names in `/var/www/html/Sevilla360/.env`. After the user authorized use of the existing ENV, a metadata-only check confirmed the secret-key value has the `sk_test_` prefix and the webhook setting is nonempty. No values were emitted or copied. Prefix classification does not verify that the key is valid or that the webhook secret matches a registered endpoint. Neither variable is present in the current process environment. The clean source checkout has no `.env`.

Real mode is selected with `SEVILLA_PM_DRIVER=paymongo_test`. Runtime configuration supports (none of these real-provider switches were enabled during testing):

| Name | Meaning |
| --- | --- |
| `PAYMONGO_SECRET_KEY` | Process-injected key, or opt-in existing-file loader below; must begin `sk_test_` |
| `PAYMONGO_WEBHOOK_SECRET` | Process-injected secret for actual signed webhooks; no fixture fallback in real mode |
| `SEVILLA_PM_ALLOW_TEST_HTTP=1` | Explicit runtime opt-in to outgoing test HTTP; disabled by default |
| `SEVILLA_PM_USE_EXISTING_ENV=1` | Opt-in, read-only loading of the two allowlisted PayMongo names from `/var/www/html/Sevilla360/.env`; no shell evaluation, unrelated config loading, export, or persistent copy |
| `SEVILLA_PM_SOCKET` | Managed by launcher; only its disposable fixture socket is accepted |

After per-action user approval, the separate one-shot CLI loaded the existing test key and made exactly one create request. The runtime/server was never started with outgoing provider HTTP enabled. The loader also has public-fixture tests; no key value was printed or copied into configuration. Automated test commands remove inherited key/HTTP settings and cannot silently become real provider tests.

### Approved real API result

`provider_once.php` made **one** `POST https://api.paymongo.com/v1/checkout_sessions` after explicit user approval. It sent the existing test key as HTTP Basic username and requested one fictional **PHP 3,000 / 300000-centavo** Garden room deposit, quantity 1, card test method, no customer/billing details, email receipts disabled and localhost return URLs. PayMongo returned a test checkout with **`livemode: false`**, the exact requested line item and a hosted checkout URL.

The session ID and non-secret intent/outcome are recorded in `../paymongo-approved-create.json` (mode 0600). The hosted URL and key were not printed or stored in that journal. The journal's exclusive creation guard prevents repeating this approved action with that path. The one-shot CLI is not invoked by any test or startup command. **Do not rerun it.**

No payment was completed. No GET retrieval, expiry, retry or webhook registration followed. The provider checkout was not expired; cleanup requires a separately approved action. This is evidence of **real test API creation only**, not a completed gateway payment or real webhook end-to-end test. Further credential-bearing operations require fresh per-action approval: retrieval `GET /v1/checkout_sessions/{id}`, expiry `POST /v1/checkout_sessions/{id}/expire`, or another create. Existing permission to use ENV is not a blanket replacement for that approval.

Actual webhook delivery additionally requires an authorized reachable test endpoint/forwarder and its signing secret. No tunnel, webhook registration, account, credential or persistent access was created. Polling via retrieval can test reconciliation without a public webhook, but still requires approved authenticated API calls. The account must support hosted checkout test mode; sandbox acceptance of localhost return URLs, payment-method availability and actual response envelopes remain unverified.

Before stopping a future provider-backed test, reconcile/expire its known test sessions under the approved scope; otherwise the disposable local mapping is lost while provider sessions may remain active. This prototype intentionally does not silently make provider cleanup calls on shutdown.

## Reconciliation policy

- Persist a local attempt before the remote create. A booking lock and compare-and-set allow only one creation request. An uncertain result is never blindly retried. A verified webhook may recover its binding; otherwise stop for explicit session resolution.
- Exact signed raw bytes, HMAC-SHA256, constant-time comparison, `te` only, five-minute delivery timestamp tolerance, and explicit test mode are required. Both the legacy event envelope and newer documented `send.webhook` envelope are normalized after verification. The latter uses a body digest when no event ID exists; payment-ID uniqueness remains the accounting boundary.
- Event inbox, unique payment receipt, booking money and inventory changes commit together. Replays and different event IDs for the same payment cannot double credit. Transaction failure rolls back acknowledgement so delivery can be retried. DB failures return 503.
- Existing repository hold/claim/release helpers serialize physical venues and check overlapping bookings, add-on rooms, maintenance, seminars and other holds. Server DB time controls the 15-minute hold. Late or released-hold payments are retained for resolution, even if dates happen to be free. No cancelled booking is revived and no refund is automatically sent.
- Server-derived integer centavos and PHP currency must match the attempt exactly. Quotes, accepted quote version, dates, prices and resource allocation are snapshotted. Changed bookings, mismatched amounts and unresolved receipts block unsafe allocation or new checkout creation.
- 20% reservation, 50% deposit and full payment use separate installments; later checkout requests the exact remaining balance.
- Provider session expiry is separate from inventory expiry. Closing retrieves, asks the provider to expire, then retrieves again before releasing. A 400/ongoing-payment response keeps the attempt blocked. An expired session with only failed payment attempts can close; paid/pending/unknown receipts cannot be treated as unpaid.
- Recovery is explicit or triggered by hosted return; no background recurring provider worker is enabled. The user may also manually Recover. Production scheduling, exception resolution, provider refunds, outbound receipts/notifications, reports and untested staff mutations remain integration work.

The fake signing string and fake `sk_test_PUBLIC_*` strings are **public fixture data, not credentials**. The simulator deliberately permits late settlement after expiry as an adversarial case; this is not a claim about PayMongo's documented expiry guarantee.

## Tests and evidence

```sh
bash scripts/paymongo/check.sh
```

The final aggregate suite covers:

- 74 transaction/inventory tests using disposable MariaDB, including concurrent PHP deliveries and rollback/retry.
- 35 adapter checks using fake HTTP only: request/auth serialization, exact amounts, URL validation, mode rejection, sanitized errors, no create retry, binding recovery, retrieval, expiry refusal, installment flow and both webhook envelopes.
- 27 original simulator HTTP checks.
- Hosted-adapter HTTP integration through application route names and actual original client scripts, covering create/reuse, delayed payment, retrieval, balance, expiry and reentry.
- A DOM-stub test of the hosted client without a manual component: CSRF, repeated click suppression, redirect rejection, error restoration, no premature completion callback, and default manual fallback.
- Full-app HTTP checks through original login, booking, dashboard and booking-details PHP, actual catalogue migrations, both installment ledger entries, CSRF and cross-customer isolation, villa payment and unquoted event rejection.
- Nine existing payment/booking regression scripts, PHP/JS/shell syntax and `git diff --check`.

Final full-app run: **113 HTTP checks passed**. Output: `../paymongo-final-checks.log`. No failing check remained in the completed aggregate run. Browser inventory was empty, so rendered desktop/mobile, keyboard interaction and real PayMongo end-to-end verification are **unrun**, not passed. HTTP/DOM-stub checks do not replace those checks.

## Repository provenance and changed files

Worktree `/home/francisgiann/Documents/Codex/2026-11-05/task-5/sevilla360-paymongo`, branch `codex/paymongo-local-prototype`, based on verified clean `e1ca286ca5bfbec472ff6598dbbfe59a00abfed5` from `/tmp/sevilla360-phone-signup`. The registered `/var/www/html/Sevilla360` was at `cbedad5b32028a1a9c5b2bafcf1dcdbd0b0c350b` with uncommitted work; it was not modified. No push, PR, commit, deployment or real payment occurred. One approved test checkout was created at PayMongo; it has not been paid or expired.

Tracked application changes: `booking.php`, `user_dashboard.php`, `assets/js/booking.js`, `assets/js/user_dashboard.js`, `includes/customer_booking_status.php`, hotel/villa booking partials, `actions/bookings/submit_online.php`, `actions/user/get_my_booking_details.php`, `actions/user/request_cancel.php`, and administrative booking detail/status actions. All behavior changes select the explicit local opt-in; the original `assets/js/manual_payment.js` is unchanged. New client: `assets/js/paymongo_checkout.js`. New isolated runtime/service/adapter/fixture files are under `prototype/paymongo/`; launcher and tests under `scripts/paymongo/`. No application DB config, production migration or secret file changed.

At this shallow base the old PayMongo adapter is retired; its four entrypoints return 410. Migration 009 contains its old session-table structure, but the historical implementation was not available to audit. The current 15-minute inventory behavior was inspected in the actual helpers and `docs/payment_step_hold_behavior.md`.

## Official contracts consulted (2026-11-05)

The adapter deliberately pins v1, for which all three operations are documented. PayMongo recommends v2 for new integrations; changing versions requires separate account testing rather than assuming identical behavior.

- [Create v1 checkout](https://docs.paymongo.com/reference/create-a-checkout)
- [Retrieve checkout](https://docs.paymongo.com/reference/get_checkout_sessions)
- [Expire checkout](https://docs.paymongo.com/reference/create_checkout_sessions_id_expire)
- [Checkout resource](https://docs.paymongo.com/reference/checkout-session-resource)
- [Hosted Checkout and webhook envelopes](https://docs.paymongo.com/docs/payment-channels-hosted-checkout)
- [Webhook signatures](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management)

Code has been checked against these published contracts and synthetic responses. One actual create request was accepted in test mode with the exact amount. Payment completion, authenticated retrieval/expiry and real webhook delivery/envelopes remain unverified.

### Next genuine provider payment verification

No further provider requests were made during expanded full-app QA. The prior single approved create succeeded in test mode for PHP 3,000; it did not verify a payment. Its hosted URL was deliberately not retained. The next proposed external action would be one separately approved authenticated `GET https://api.paymongo.com/v1/checkout_sessions/{recorded_session_id}` to retrieve that checkout URL/state using the existing test key. If that checkout cannot be reused, a new create requires its own approval. The user would then complete the hosted **test-card** payment. Proving server-side reconciliation additionally requires an approved authenticated retrieval after payment, or an explicitly authorized reachable webhook endpoint/forwarder and test webhook registration/secret setup. Localhost alone cannot receive PayMongo webhooks. None of those actions, credential transmissions, registrations, tunnels or payment completions has been performed here.

## Automatic-versus-manual audit

Observed and fixed in the isolated runtime:
- Hidden proof-upload HTML and its JS/CSS were still included on booking/dashboard pages. They are now omitted, including proof-review fields and hotel/villa manual-instruction copy. Both local entrypoints use the hosted client directly.
- The original administrative action endpoint allowed `confirm` and `add_payment`. The local router now accepts only quotation finalization and refund accounting actions, rejecting payment approval/manual ledger writes with 403. Separate proof upload/details/image/review/settings/reconcile/release endpoints remain outside the allowlist (404); the manual client asset is also unavailable.
- Event quotation finalization previously set `Confirmed` before payment. An unpaid local quote now enters the internal awaiting-payment state, with staff seeing **Payment due**. Staff quoting establishes the authoritative price; it is not payment verification. Only reconciliation can confirm and credit payment.
- Dashboard proof-status queries and customer detail proof joins are bypassed in local mode. Administrative details skip proof-history processing, and both detail ledgers use the provider transaction directly. Refund accounting skips legacy pending-proof cleanup.
- Checkout close no longer depends on `proof_first_submitted_at`; every applied installment rechecks inventory. Exceptions are visible in customer/staff details and block another checkout.

**What remains:** the repository still contains all original manual endpoints, modal, client, proof image validation/storage, review functions and settings for the unchanged default deployment. They are unreachable in local PayMongo mode. Shared functions in `includes/manual_payment.php` are still reused for database time, amount-history formatting, deadline configuration, and transactional inventory holds/claims/releases; these calls do not inspect or approve uploaded proof. The legacy database enum string `Awaiting Proof` is retained as an internal schema compatibility state and displayed as Payment due. Manual table/column definitions remain in the disposable schema, and the original staff history query still selects read-only legacy proof metadata columns (empty in this fresh synthetic runtime). Those schema/query compatibility remnants are not a proof submission or staff approval workflow and cannot credit payment. Full admin dashboard rendering remains outside the harness allowlist.

Verification: 113 full-app checks cover hidden UI removal, blocked legacy routes/staff bypasses, unchanged unpaid state on checkout/return, staff quoting without premature confirmation, signed fake-event reconciliation, deposits/balances, refunds, and late receipt after a competing reservation. Core tests cover invalid signatures, replay/out-of-order events, conflicts, expired holds, quote/amount changes and atomicity. These use fake provider transport; no genuine webhook/payment end-to-end claim is made. Browser QA remains unavailable.

The saved approved-create journal was checked without accessing credentials: it contains a session identifier and `checkout_url_present=true`, but no hosted URL. The one-shot process exited and deliberately discarded that URL; no URL has been reconstructed or invented.

## Production readiness review — 2026-11-05

**NO-GO for live deployment.** The user intends eventual production PayMongo deployment, but this worktree is still a test-only prototype. This review made no provider request, inspected no customer database, changed no credential/access configuration and performed no deployment. Existing successful test-key checkout creation does not establish merchant activation or payment completion. Existing configuration metadata established a test key and a nonempty webhook setting; it does not establish endpoint registration, signing-secret correctness, live credentials, merchant capability or account ownership/readiness.

| Blocker and observed evidence | Required implementation or evidence before launch |
| --- | --- |
| Account readiness unverified. User previously reported lacking business requirements. | Account owner must confirm PayMongo activation, applicable identity/business verification, permitted merchant entity/business, chosen methods shown Active, and settlement destination readiness. Do not infer these from test-key access. Submit required information through PayMongo, not chat. |
| Production entrypoints retired: `actions/user/pay_existing.php`, `actions/user/sync_payment.php`, `actions/bookings/paymongo_webhook.php` return 410. | Build reviewed real application routes with existing auth/ownership/CSRF guards, an explicit server payment-mode flag and a signed webhook ingress. The local router's aliases are not deployable replacements. |
| Local-only activation: loopback/meta-marker client selection, `SEVILLA_PM_FULL_APP`, socket-marker DB, `PayMongoTestProvider`, fake full-app transport, public synthetic signing fixture. | Separate production integration from harness; retain fail-closed test/live isolation. Never remove guards to expose the simulator or fixture controls. Exclude local tooling/seeds from the deployed web surface. Use secure operator-configured credentials and restricted DB permissions only after approval. |
| Adapter rejects live keys/resources; verification accepts only `te` and `livemode=false`; return URLs are hardcoded localhost, card-only synthetic item. | Implement separately tested mode-aware key/resource/signature validation (`li` in live mode), configured canonical HTTPS return URLs, real booking descriptions and only activated methods. Do not simply change key prefix or disable mode checks. |
| No public webhook setup verified. Normal webhook retired; standalone webhook behind localhost guard; full-app harness delivers signed fake events internally. | Confirm deployment origin (repository guide names `misevillas.com`, not independently verified live), HTTPS route/TLS/proxy/raw-body behavior, separately registered test/live endpoints, matching endpoint secrets and chosen event subscriptions. Registration, hosting/access changes and secret-bearing requests require explicit authorization. Add durable ingress/worker, bounded acknowledgements, retry/replay tests and recovery on worker failure. |
| No production migration: `pm_attempts`, `pm_events`, `pm_receipts`, quote fields exist only in synthetic schema. Old migration 009 creates a different `booking_checkout_sessions` model. Full-app schema deletes fixture rows and is not safe for an existing app DB. | Write additive versioned migrations against an approved schema-only inventory; reconcile old checkout/payment IDs, monetary precision, unique constraints/indexes and table permissions. Stage with synthetic data and the real supported SQL version. Never run prototype schema files on production. |
| Legacy `Awaiting Proof` internal state and migration-era manual routes/cron remain. Staff bypass prevention currently belongs to the local router. | Define explicit Awaiting Payment/exception states or a documented compatibility mapping across all customer/staff/report/PDF/cron paths. Enforce payment-mode restrictions server-side on every legacy mutation, not only in JS/router. Define how pre-cutover manual submissions/bookings are completed without duplicate payment. |
| Local receipts/inbox and database locks prevent duplicate credit; uncertain creation deliberately blocks retry. No provider POST idempotency header, scheduled reconciliation or operator resolution tool. | Confirm supported idempotency contract for the selected Checkout API version and implement persisted operation keys where supported; verify actual timeout/retry behavior. Add reconciliation/expiry worker, bounded retry/dead-letter strategy and safe unknown-session resolution. Never blindly recreate an uncertain checkout. |
| 15-minute expiry and late/conflicting receipt rejection are tested synthetically, but old unpaid-expiry cron is manual-oriented. | Coordinate provider expiry and database hold lifecycle; handle ongoing-payment/expiry refusal without issuing a new checkout. Retain receipt evidence and prevent double booking. Provide monitored exception resolution/customer communication; rehearse concurrent admin rescheduling/cancellation, late settlement and failed recovery. |
| Refund tests only record a fictional reference using existing application accounting. No provider refund/dispute integration. | Choose a documented operator-assisted or automated provider refund process. Link refunds to verified provider payment IDs; reconcile pending/processing/succeeded/failed, partial/refund limits and duplicates. Do not mark funds returned solely from a typed reference. Define dispute and settlement reconciliation ownership. |
| No complete provider/browser E2E: one test checkout creation only; 113 full-app checks use fake HTTP transport. | Authorized test-mode hosted payment by user, verified signed delivery + authenticated recovery, replay/failure/expiry and each offered payment method; rendered desktop/mobile/keyboard/back/reload checks. Prior hosted URL was discarded; recovering it requires separately approved authenticated retrieval. Any eventual small live payment/refund test requires specific transaction approval after activation. |
| No production cutover/rollback/monitoring rehearsal; emails are stubbed locally. | Review release contents against the then-current main, approved schema-only baseline, additive migration staging, backup/restore evidence and a cutover plan. Stop new checkouts on rollback while keeping reconciliation and receipt records available for outstanding sessions; never roll back money evidence. Assign alerts for invalid signatures, delivery lag, unknown/duplicate events, amount/inventory exceptions, held/uncertain attempts and refund failures. Validate sanitized logs, access controls, retention, real notifications and truthful booking/refund/privacy text. Business owner must approve terms/privacy/receipt obligations; legal compliance is not established by this code review. |

Official documentation checked during this review:
- [PayMongo launch milestones](https://docs.paymongo.com/docs/get-started-go-live-checklist): activation/KYC/KYB, method capability, payouts, key separation and eventual controlled live verification.
- [Hosted Checkout launch checklist](https://docs.paymongo.com/docs/payment-channels-go-live-checklist): complete test journey, return URLs, session identity and expiry refusal handling.
- [Developer launch checklist](https://docs.paymongo.com/docs/developer-tools-go-live-checklist): idempotency, public HTTPS ingress, separate environments, signature validation, asynchronous delivery handling, replay and monitoring.
- [Webhook signature contract](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management): raw timestamp/body HMAC and mode-specific `te`/`li` signatures.
- [Refund resource](https://docs.paymongo.com/reference/refund-resource): original-payment refunds and asynchronous refund states.

These requirements are deployment gates, not authorization to perform external setup or transactions. No blocked operation was attempted or denied in this review. All external activation/configuration/testing/deployment steps remain unperformed; merchant readiness remains unknown. The last aggregate validation remains 74 core, 35 adapter, 27 standalone HTTP and 113 full-app checks plus client/default regression suites; this readiness review changed documentation only, so those code checks were not needlessly rerun.

## Current requested target: PUBLIC TEST DEMO (supersedes live-payment interpretation)

The user clarified that public deployment should offer **test checkout only**, not live merchant payments. Merchant **live activation is not a gate for this demonstration**. The earlier production review remains a future live-money checklist, not a reason to block a properly isolated public test demo.

Prepared code: `paymongo-demo/index.php` (disabled by default), `public_bootstrap.php`, `public_runtime.php`, `public_client.js`, and `public_schema.sql` in this prototype directory. `scripts/paymongo/build_public_demo.py` builds a **self-contained** package with private copies of only the inventory helpers it needs. It includes no application DB config, customer login, real data, credentials, legacy payment endpoints or fixture-control routes from the full application. The original live site's checkout is not changed by deploying this package alone.

Built reviewable package: `../paymongo-demo-package/paymongo-demo/` relative to the worktree's parent task directory (absolute: `/home/francisgiann/Documents/Codex/2026-11-05/task-5/paymongo-demo-package/paymongo-demo/`). Its sibling `MANIFEST.txt` lists every file. Build a fresh package with:

```sh
python3 scripts/paymongo/build_public_demo.py /tmp/my-new-paymongo-demo-package
```

Local preview and tests:

```sh
bash scripts/paymongo/local.sh public-demo-serve
# Open http://127.0.0.1:8787/paymongo-demo/index.php
bash scripts/paymongo/local.sh public-demo-test
```

This local preview uses the **packaged runtime with a simulator**, not provider HTTP. It creates and discards a fresh socket-only database. The deployable bootstrap itself requires explicit opt-in and HTTPS; the local test wrapper is not packaged or deployed.

### Isolation and behavior

- Every page prominently says **TEST PAYMENTS ONLY — No real money. No real reservation.** It warns against entering real card/billing/personal details and identifies local simulation versus PayMongo test mode. Fictional amount: PHP 6,000 total, PHP 3,000 test deposit and remaining PHP 3,000 test balance.
- A separate `SEVILLA_PAYMONGO_DEMO` cookie/session has Secure (HTTPS), HttpOnly, SameSite=Lax, strict cookies and `/paymongo-demo/` scope. No production login/session is used. Each visitor gets a distinct fictional booking and venue. Submitted booking IDs and prices are ignored; state and mutations are owned by the current demo session.
- The bootstrap **never loads application config or an existing .env**. Database names must end in `sevilla_pm_demo`, runtime user names in `pm_demo`, with a required `pm_demo_identity` marker. Deployment additionally requires proof the runtime user has grants only on this new demo DB. Names/marker are fail-closed checks, not substitutes for correct operator provisioning.
- Separate environment names prevent accidental use of the old application's key. Test-only key/resource/event guards remain in force. The public test webhook uses only `PAYMONGO_DEMO_WEBHOOK_SECRET`; simulator mode has no external webhook route. Test mode exposes no simulate-success action.
- Mutations require CSRF and ownership; capped request bodies, separate visitors, daily global caps (200 new visitors, 100 mutation actions), no-cache/noindex/CSP/referrer headers and fixed HTTPS return origin bound the demo. Budget exhaustion fails closed. These are modest demo limits, not a load-test or abuse-protection certification.
- Returned success URLs do not credit payment. Authenticated provider retrieval or signed test webhook reconciliation controls credit to fictional rows only. The local simulation invokes that same signed-event engine using its public fixture signing string and is clearly labeled. No real reservation is made regardless of stored fictional status.
- Package `_private/` is denied through two `.htaccess` rules. The hosting server must prove enforcement before enablement. Runtime DB/schema and all provider credentials remain private. Public CSS/JS are served by the guarded entrypoint; there is no generic filesystem fallback.

### Proposed hosting target and exact remaining approvals

The repository's `docs/HOSTINGER_DEPLOYMENT.md` identifies Hostinger PHP hosting for `misevillas.com`. A compatible **candidate**, not remotely verified/provisioned, is:

`https://misevillas.com/paymongo-demo/index.php`

Deploy only the package's `paymongo-demo/` directory under the existing `public_html`, without overwriting existing application paths. The packaged entrypoint uses private copied helpers, not whichever version the live application's includes happen to contain. No hPanel account, DNS, TLS, filesystem existence, runtime grants or remote environment was inspected in this task; those checks still require authorized access. No deployment is approved/performed merely by naming this target.

Required operator actions, each still **unperformed**:
1. Confirm target/path is unused, PHP supports mysqli/cURL and required language features, HTTPS reports the canonical host correctly, `.htaccess` denies `_private/`, and demo session storage is private. Approve deploying the reviewed directory only; no push or root application deployment is implied.
2. Approve creating a **new empty** database such as `ACCOUNT_sevilla_pm_demo` and user `ACCOUNT_pm_demo` with grants **only** on it. An administrative setup identity initializes `schema.sql` then `public_schema.sql`; runtime needs SELECT/INSERT/UPDATE/DELETE, not production access or DDL. These are new demo schema initialization actions, never migrations against the real application's DB. Verify grants and marker before enabling the site. Database credentials are configured privately, never pasted into chat or source.
3. Configure explicit demo-only environment: `SEVILLA_PM_PUBLIC_DEMO=1`, `PAYMONGO_DEMO_BASE_URL=https://misevillas.com/paymongo-demo/index.php`, `PAYMONGO_DEMO_DB_HOST`, `PAYMONGO_DEMO_DB_NAME`, `PAYMONGO_DEMO_DB_USER`, `PAYMONGO_DEMO_DB_PASSWORD`. Default `PAYMONGO_DEMO_DRIVER=simulator` moves no money and sends no provider requests. Approve these persistent settings before making them.
4. For actual PayMongo **test** checkout, separately approve secure provisioning of `PAYMONGO_DEMO_TEST_SECRET_KEY`, `PAYMONGO_DEMO_DRIVER=paymongo_test` and `SEVILLA_PM_ALLOW_TEST_HTTP=1`, including the intended demo API operations and limits. The runtime does not copy the old key automatically. Live keys are rejected. This step enables secret-bearing test API calls by the application and must not be done silently.
5. For asynchronous verification, approve a test webhook registration for `https://misevillas.com/paymongo-demo/index.php?action=webhook`, event `checkout_session.payment.paid`, and provision its matching `PAYMONGO_DEMO_WEBHOOK_SECRET`. Existing secret presence does not prove it belongs to this endpoint. No tunnel is needed if the approved HTTPS path is reachable. Registration and persistent credential configuration remain approval boundaries.
6. Agree a short demo lifetime, a cleanup/retention owner and logging/usage monitoring. Disable new checkouts before removing the demo; reconcile/expire outstanding test sessions only through approved actions, then remove the isolated directory/database under a separate cleanup approval. Do not touch real reservations or payment evidence.

### Genuine provider E2E still pending

The previous single authorized POST created a test PHP 3,000 card checkout. Its nonsecret session ID is retained in the action journal, but the hosted URL was discarded and cannot be reconstructed. The owner may locate it in their PayMongo test dashboard; alternatively retrieving it requires a separately approved authenticated `GET /v1/checkout_sessions/{recorded_session_id}`. That session has localhost return URLs and an old standalone reference, so it **cannot demonstrate this public demo's end-to-end booking binding** or be reused to credit a new visitor's demo booking.

For this public demo's genuine E2E: after approved isolated setup, create **one new** fictional PHP 3,000 deposit checkout through the demo with card test method, no billing/customer details or email receipts, and its configured HTTPS returns. This is a new authenticated POST to `https://api.paymongo.com/v1/checkout_sessions` requiring approval. The user then follows the returned hosted link and completes a **test-card** payment. Verify its signed test webhook arrives and credits only that visitor's demo record; if using recovery instead, approve an authenticated GET for that new session. Checkout close/expiry and further requests are separate external operations, not implicit permission from the earlier one-shot approval. Test replay/late delivery, balance and browser return behavior before claiming provider E2E success.

### Validation status

Passed: 34 HTTP checks against the packaged public runtime (simulation), dedicated public return/configuration tests with injected fake HTTP, disabled entrypoint smoke check, and the aggregate existing suites: 74 reconciliation, 35 adapter, 27 standalone HTTP, 113 full-app HTTP, client and default-application regressions. Final packaging-only rerun also passed 34 checks. Logs: `../paymongo-final-checks.log` and `../paymongo-public-demo-package-checks.log` in the parent task directory. Browser rendering, Hostinger deployment/TLS/access-rule/grant validation, actual provider test payment, real webhook delivery and provider refund E2E remain **unrun**. No provider request, key transmission/configuration, webhook registration, tunnel, push, production migration or deployment occurred during this preparation.

## FINAL HANDOFF: normal capstone UX, PayMongo test mode (latest user instruction)

This section supersedes earlier proposed public-demo banners and live-business assumptions. The user states the site is for their capstone defense, is not used by the resort, and should behave like a normal booking website. **No custom test-site banner, disclaimer or demo warning is required or added to the original booking/dashboard UX.** PayMongo's own test indicators remain untouched. We have not viewed the hosted screen and do not assert exact “authorized” wording. Fake-transport tools remain labeled internally so test evidence is not misrepresented as real provider settlement.

The realistic original full-app harness is the relevant candidate: normal booking/date/pricing, login/dashboard, staff quoting, automated receipt reconciliation, deposit and balance. User-visible copy now says “Pay now” and automatic verification, with no custom capstone badge. Test keys/live-event rejection, CSRF/ownership and synthetic database isolation remain. The earlier compact `paymongo-demo/` package is an **alternative experiment, NOT the selected defense-site release**; do not deploy that mini-demo or its banners for the combined release.

Verified public context: an unauthenticated HTTPS HEAD to `https://misevillas.com/` returned HTTP 200 with successful TLS verification. No response body, cookies, customer records or credentials were read. This proves reachability only, not a team restriction, schema, data separation, hosting permissions or a pre-existing isolated environment. Repository metadata names Hostinger and this domain; only the local disposable database is technically verified as isolated. The user's capstone-only purpose is trusted user context, distinct from remote configuration evidence.

Recommended deployment shape: keep the original website UX in a dedicated capstone application/database, use normal separate team customer/staff accounts and PayMongo TEST Hosted Checkout unchanged. Reuse the current site if the release owner verifies it is that isolated capstone environment; otherwise provision a separate site/DB through approval. A host-level access gate or team account allowlist can restrict the intended audience without banners, but neither is configured or imposed here. Webhook ingress must remain separately reachable and authenticated by provider signature; a browser login wall must not block it. No production access expansion is required or authorized.

### Payment methods

Repository history in the original local repo confirms `c544bb0^` requested `['card','gcash','paymaya']` in both `actions/bookings/submit_online.php:417` and `actions/user/pay_existing.php:97`; the webhook recognized Maya too. This agrees with the user's report that GCash/Maya worked before. The isolated branch is shallow, so the history check was read-only against `/var/www/html/Sevilla360`; nothing there was changed.

The adapter now restores **card, GCash and Maya** by default. Method configuration is server-owned (`PAYMONGO_TEST_PAYMENT_METHODS`; alternative mini-demo uses `PAYMONGO_DEMO_PAYMENT_METHODS`). Invalid aliases/duplicates fail before HTTP. No automatic method substitution or new POST retry occurs when PayMongo rejects a configured method. Historical support is not claimed as fresh account-capability verification. The only real provider operation performed remains the earlier approved **card-only** creation; its one-shot helper still explicitly fixes `['card']` to preserve that authorization scope.

Current official Checkout Sessions schema lists `card`, `gcash`, `paymaya` and bank choices `dob`, `dob_ubp`, `brankas_bdo`, `brankas_landbank`, `brankas_metrobank` among its accepted values. They are allowlisted optional configuration, not all enabled by default. **Bank transfer means PayMongo Direct Online Banking/Brankas here, not manual bank deposit plus proof upload.** The bank-specific checkout identifiers differ from the generic Payment Method `dob`/`brankas` objects with bank_code; do not substitute `bank_transfer` or bare `brankas` in Checkout Sessions. Official direct-banking guidance currently marks BDO/Metrobank temporarily unavailable, so do not promise those choices. The intended bank and current test-account capabilities need confirmation before enabling bank options. No authenticated merchant-capability request was made and no stored configuration evidence establishes those capabilities.

Sources checked: [Checkout create schema](https://docs.paymongo.com/reference/create-a-checkout) (public Markdown schema exposes the method enum), [E-wallet types](https://docs.paymongo.com/docs/payment-acceptance-e-wallets), [Direct online banking](https://docs.paymongo.com/docs/payment-acceptance-direct-online-banking). The provider currently recommends v2 for new integrations, but this candidate retains its tested v1 adapter; no silent API-version migration was made.

### Release-owner integration dependencies — NOT YET A DEPLOYABLE FULL-APP PAYMENT SWITCH

- Worktree `/home/francisgiann/Documents/Codex/2026-11-05/task-5/sevilla360-paymongo`, branch `codex/paymongo-local-prototype`, base `e1ca286ca5bfbec472ff6598dbbfe59a00abfed5`. No commits/push/deployment. Do not alter `/tmp/sevilla360-weekly-integration`; release owner handles overlap against its **updated**, not old a47f71b, artifact.
- Candidate contains tested UI/core/adapter and local router integration. Normal PHP routes `pay_existing.php`, `sync_payment.php`, and `paymongo_webhook.php` still return 410 outside the harness; JS selection still requires loopback/local marker. **Applying this patch alone does not activate deployed automatic payments.** Release owner must implement/configure the real capstone route adapter and a server-owned opt-in against the verified isolated capstone DB; do not remove localhost guards and expose fixture controls.
- New schema dependencies are `pm_attempts`, `pm_events`, `pm_receipts`, quote version/finalization fields and existing hold tables. No production-numbered payment migration is supplied. Synthetic schemas must not be run against an existing database. Obtain schema-only evidence and create additive migration(s); reserve a number with the release owner because **037 belongs to account_sessions**.
- Use the combined current session bootstrap for customer/staff endpoints. Webhook must use provider authentication, not an account session, and must return retryable failure while maintenance/restore authority blocks mutation. Reconcile locks and receipt/inbox idempotency with restore-generation invalidation; cross-feature maintenance/restore tests are unrun because the combined release is owned elsewhere.
- Tracked overlaps include `user_dashboard.php`, `booking.php`, booking/dashboard JS, booking submission, administrative booking detail/status actions, user booking detail/cancellation actions, customer status helper and hotel/villa partials. No edits to shared header/session bootstrap were made here; current full-app harness copies its own baseline versions and must not overwrite the owner's newer bootstrap.
- Keep prototype/simulator/full-app fixture tooling and one-shot provider scripts out of exposed production routes. Deployment access, a restorable backup, runtime environment and schema/grants remain unverified. These are technical integration/setup dependencies; live merchant activation and custom banners are **not** blockers for a test-mode defense.

Next external test still needs approved setup and request scope: new test Checkout Session for a synthetic capstone booking with requested card/GCash/Maya methods, native hosted payment by the user, and signed webhook/recovery verification. The old card-only session's URL was discarded and its local reference/return cannot validate a new deployed booking. No additional provider request, credential setup, security configuration, access grant, database migration or deployment was performed.

## Native capstone integration — replacement final candidate

The earlier “routes still 410” handoff is superseded. Native `actions/user/pay_existing.php`, `sync_payment.php`, new `close_paymongo_checkout.php`, and `actions/bookings/paymongo_webhook.php` now execute the reconciliation engine directly through `includes/paymongo_native.php`. The full-app test router dispatches these files unchanged; it injects only fake provider transport and synthetic DB/mail/maintenance configuration. It no longer substitutes checkout/recovery/close handlers. Provider ingress executes its native file without a browser session.

Enablement is explicit and server-owned: `PAYMONGO_CAPSTONE_MODE=test`, `PAYMONGO_CAPSTONE_DB_NAME` equal to the actual isolated capstone database, `APP_BASE_URL` set to its canonical HTTPS origin, `PAYMONGO_SECRET_KEY` test key, `PAYMONGO_WEBHOOK_SECRET` matching that endpoint, and `SEVILLA_PM_ALLOW_TEST_HTTP=1` only after authorization to enable external test API requests. Optional `PAYMONGO_TEST_PAYMENT_METHODS` defaults to `card,gcash,paymaya`. Live keys, live resources and live signatures remain rejected. No custom test banner is added. Disabled mode leaves manual workflows as the default; legacy proof mutations and staff confirm/add_payment are rejected server-side in enabled mode.

**Migration 038_paymongo_test_checkout.sql**, with **037 reserved for account_sessions**, adds only quote fields, dedicated capstone identity and receipt/attempt/inbox tables. It does not delete bookings, proofs, payments or existing checkout history. Apply only to the confirmed isolated capstone DB after inspecting its schema and backing it up. Never apply fixture schemas. The application checks both configured DB name and migration marker before payment mutations. Existing event quotes need staff finalization to establish the new quote version; quoting does not confirm an unpaid booking. No old booking deletion was implemented or authorized.

Libraries required at deployment: `includes/paymongo_mode.php`, `includes/paymongo_native.php`, `prototype/paymongo/{provider,hosted_flow,service}.php` and existing inventory helpers. Keep `prototype/paymongo/.htaccess` denying direct requests. No simulator is loaded by native routes. Do not enable the earlier mini-demo. Native customer actions use current session bootstrap, account guard, ownership and CSRF. Native webhook verifies provider raw-body HMAC separately and calls `database_backup_enforce_web_write_gate()` with its exact no-argument contract. Invalid signature is 400; unprocessed DB/config failures are 503; maintenance helper retains its shutdown lock/retry behavior. The combined release owner must retain their updated generation/account-session implementation and merge our added mode include, rather than overwriting those files with this baseline.

Upgrade verification applies 038 twice over a synthetic existing application schema and confirms a pre-existing cancelled/partially-paid booking retains its ID/reference/paid value. Native tests cover checkout/repeat/recovery/deposit/balance, quote/late exceptions, signed webhook with no browser login, signature rejection, replay, maintenance 503, missing-marker 503 and native proof-mutation guards. The maintenance tests inject the gate boundary; actual combined restore-generation interaction still requires the release owner's merged checks. No provider HTTP request or deployed schema change was performed.

Deployment configuration, migration execution, credentials, webhook registration and hosted test payment still need the previously described explicit approvals. Genuine PayMongo payment/webhook E2E and browser rendering remain unverified. Source-level Hostinger/TLS assumptions are not remote configuration evidence.
