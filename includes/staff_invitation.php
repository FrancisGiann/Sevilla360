<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/site_metadata.php';

const STAFF_INVITATION_PURPOSE = 'staff_setup';
const STAFF_INVITATION_TTL_HOURS = 24;

/** Return a high-entropy raw token and its persisted SHA-256 digest. */
function staff_invitation_new_token(): array
{
    $token = bin2hex(random_bytes(32));
    return [$token, hash('sha256', $token)];
}

/** Build the invite URL from the configured public origin, never request headers. */
function staff_invitation_link(string $token): ?string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    $base = site_metadata_absolute_url('staff_invite.php');
    return $base === null ? null : $base . '?token=' . rawurlencode($token);
}

/** Send a staff setup invite through the application's configured mail transport. */
function staff_invitation_send_email(string $email, string $name, string $link): void
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = $escape($name);
    $safeLink = $escape($link);
    $business = get_biz_info();
    $content = '<div style="font-family:Arial,sans-serif;color:#2a2522;line-height:1.6">'
        . '<h2>Set up your Sevilla360 staff account</h2>'
        . '<p>Hello <strong>' . $safeName . '</strong>,</p>'
        . '<p>An administrator created a staff account for this work email. Use the button below within 24 hours to choose your own password.</p>'
        . '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 20px;background:#d6a870;color:#2a2522;text-decoration:none;font-weight:bold">Set up account</a></p>'
        . '<p>If you did not expect this invitation, you can ignore this email. The account remains inactive until setup is completed.</p>'
        . '<p style="font-size:12px;color:#777">This single-use link expires in 24 hours. No password is included in this email.</p>'
        . '</div>';
    resend_mail_send($email, $name, $business['biz_name'] . ': Set up your staff account', email_layout_document($content), $business['biz_name'] . ' Accounts');
}

/** Look up a currently usable invitation by its raw token; return only safe account fields. */
function staff_invitation_lookup(mysqli $conn, string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    $tokenHash = hash('sha256', $token);
    $statement = $conn->prepare("SELECT i.id, i.user_id, i.expires_at, u.email, s.full_name
                                 FROM staff_invitations i
                                 INNER JOIN users u ON u.id = i.user_id
                                 INNER JOIN staff s ON s.user_id = u.id
                                 WHERE i.token_hash = ? AND i.purpose = 'staff_setup'
                                   AND i.consumed_at IS NULL AND i.expires_at > NOW()
                                   AND u.role = 'staff' AND u.is_verified = 0
                                   AND s.status = 'inactive'
                                 LIMIT 1");
    if (!$statement) throw new RuntimeException('Unable to load staff invitation.');
    $statement->bind_param('s', $tokenHash);
    if (!$statement->execute()) throw new RuntimeException('Unable to load staff invitation.');
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : null;
}
