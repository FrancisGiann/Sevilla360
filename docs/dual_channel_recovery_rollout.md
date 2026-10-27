# Dual channel password recovery

This branch extends signup commit `3bf2474`; it does not activate or deploy recovery. Apply migration 034 first, then 035. Keep `PASSWORD_SMS_RECOVERY_ENABLED=0` until the combined signup and recovery rollout is approved. SMS also requires the existing signup and UniSMS gates and a positive shared daily limit.

Customers who completed phone signup can request a code only at the phone in `customer_verified_phones` with a non-null verification timestamp. They cannot enter a new phone during recovery. They can request an email link only after separately verifying `recovery_email` in settings. Established verified customers and active staff retain email reset through their existing verified account email. The request form accepts the account login email as its identifier and sends to the independently stored destination.

Recovery challenges are separate from signup challenges. Codes last five minutes, have five attempts, are HMAC protected with `APP_KEY`, and are consumed to create a ten minute reset-only session. Password reset increments `auth_version`; protected routes reject earlier signed-in sessions. Existing signed-in sessions without a version continue if the account version is still zero; password changes or resets revoke them. A reset-only grant is also bound to the verified phone. Changing a verified phone increments the account version, invalidates earlier grants and email reset links, and consumes outstanding recovery SMS challenges. Email tokens remain random 256-bit, hashed at rest, one hour, and one-use. Recovery checks share the SMS daily reservation key with signup, including rejected provider requests. Queued provider status is not proof of delivery. Explicit 4xx rejection and uncertain/timeout outcomes are recorded separately; neither can verify a code.

The SMS text names Sevilla360, identifies password reset, states the actual five minute validity, and says not to share the code. The sender ID remains the assigned UniSMS sender; this change does not claim a custom sender. The form uses generic account and delivery language to avoid exposing account existence or contact details.

## Rollout gates

1. Merge and review signup and recovery together, particularly `contact_verification.php`, `auth.php`, `forgot_password_process.php`, and migrations 034/035. Confirm the signup owner’s final commit has no conflicting edits.
2. Review and apply migrations to disposable staging, then approved production with backups. Existing sessions continue while their account version remains zero.
3. Verify route behavior with disposable accounts and mock transports: verified phone, unverified phone, optional verified email, legacy customer, and staff. Test concurrent code consumption, expiry, limits, and changed phone. No live sending is part of this patch.
4. Obtain explicit approval for any funded SMS test, sender coverage, production flag change, credential setup, and deployment. Keep the feature flag off until those gates pass.

There is no claim that a queued SMS was delivered. Provider failure and unknown account requests receive the same generic public response; a disabled SMS channel is reported as unavailable to everyone. Operators must inspect sanitized provider status for delivery issues.
