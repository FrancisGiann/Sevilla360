# Phone verification rollout

This change is staged only. It creates no SMS traffic until `PHONE_SIGNUP_ENABLED=1`, `UNISMS_SMS_ENABLED=1`, a positive `UNISMS_DAILY_SMS_LIMIT`, an assigned `UNISMS_SENDER_ID`, and `UNISMS_SECRET_KEY` are present. Keep both flags at `0` in production pending a funded account and deployment approval.

## Behavior

- New customers supply email, password, and Philippine mobile number. Registration stores an inactive account, records the pending phone, and submits one five minute code. The provider's queued response is recorded but does not activate the account. A successful, single-use code verification activates it. Later sign ins use email and password.
- Existing customers and staff keep their previous authentication path. `phone_signup_required` defaults to `0` for all existing rows.
- Recovery email ownership is separate from phone ownership. New customers can sign in after phone confirmation while recovery email remains unconfirmed. Password reset requests for these accounts are suppressed until the recovery inbox is confirmed. Notifications continue using the existing login email. Confirming a new recovery address does not change the login email.
- A failed SMS request leaves the account pending. The customer can sign in with their password to change an unreachable pending number and request a fresh code. A verified phone change preserves the old number until the new code succeeds.
- SMS text is ASCII, branded as Sevilla360, purpose specific for signup and phone change, and states the five minute expiry and that the code must not be shared. Email reset remains available through an independently verified recovery inbox; optional SMS reset is separately gated and uses distinct challenges. The assigned sender controls carrier support; there is no carrier-prefix inference. A rejected or unavailable send is shown as a failed request with another-number and support guidance.

## Preparation and rollout

1. Public main already contains `033_proof_submission_claims.sql`; the phone migration is numbered 034 to avoid colliding with that production-applied change. There is no automatic migration runner in this repository. Confirm the target schema and manual application receipt, review backups, then apply `migrations/034_customer_contact_verification.sql` to staging first, then production before code rollout. The new tables use InnoDB and require the existing `users` table primary key to be `INT`.
2. Deploy code with both flags `0`. Check legacy customer and staff sign in, registration, password reset, and profile settings.
3. In server-side `.env` only, paste the user's **secret** into `UNISMS_SECRET_KEY=`. Set `UNISMS_SENDER_ID=` to the sender ID actually assigned to the signed-in UniSMS account (default testing sender currently `Unisoft`). Set `UNISMS_DAILY_SMS_LIMIT=` to a reviewed positive daily spend cap. Never put the key in a browser, repository, log, or chat.
4. Test with disposable staging accounts and an eligible test number only after separate approval and credits exist. Enable `PHONE_SIGNUP_ENABLED=1` and `UNISMS_SMS_ENABLED=1` together only after signoff. No live send is part of this change.

## Limits and operations

An SMS submit consumes the global daily allowance before the provider call, plus per IP and number budgets and a one minute number cooldown. These conservative reservations also count failed provider requests, avoiding unbounded spend. The HTTP 201 response means queued, not delivered. The stored provider reference/status support later operations review, while only the customer's code proves phone control. Code verification allows at most five attempts per challenge and five attempts per email per 15 minutes. No secret or code is logged.

Two separately approved local staging signup messages used one credit each. The second arrived at the user's handset, but its code expired unused, so activation remains untested. Confirm the current balance and sender coverage before any new, separately approved bounded live test; the default sender cannot reach every Philippine network.

## Rollback

Set `PHONE_SIGNUP_ENABLED=0` and `UNISMS_SMS_ENABLED=0` immediately to stop new SMS signup and sending. Keep migration 034 and the new authentication/reset gates in place while any `phone_signup_required=1` rows exist. Pending customers can retain their account and contact support; active customers continue email/password login, but reset remains gated on independently verified recovery email. Do not simply revert the reset gate or drop verification tables after activation, since that would let an unconfirmed inbox receive reset links or lose phone ownership evidence. A complete code/schema rollback needs a reviewed migration of each affected account.

## Remaining policy

Confirm whether one verified phone may belong to only one active new customer; this implementation enforces one phone per new account and uniqueness across verified new accounts. Existing accounts are not backfilled or subject to this rule.
