<?php
/**
 * Minimal Resend HTTP transport for application email.
 * The endpoint is intentionally fixed so application configuration cannot
 * redirect bearer credentials to another host.
 */
class ResendMailException extends Exception {}

function resend_mail_config(string $name): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return is_string($value) ? trim($value) : '';
}

function resend_mail_validate_header_value(string $value, string $field): string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new ResendMailException("Email delivery configuration or {$field} is invalid.");
    }

    if (preg_match('//u', $value) !== 1) {
        throw new ResendMailException("Email delivery configuration or {$field} is invalid.");
    }

    return $value;
}

function resend_mail_validate_address(string $email, string $field): string
{
    $email = trim($email);
    if ($email === '' || preg_match('/[\x00-\x20\x7F]/', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new ResendMailException("Email {$field} address is invalid.");
    }

    return $email;
}

function resend_mailbox(string $displayName, string $email): string
{
    if ($displayName === '') {
        return $email;
    }
    resend_mail_validate_header_value($displayName, 'display name');
    // Quote the display name as an RFC mailbox phrase; escaping here prevents
    // punctuation in a configured business name from changing the address.
    $quotedName = '"' . addcslashes($displayName, "\\\"") . '"';
    return $quotedName . ' <' . $email . '>';
}

/**
 * Sends one email through Resend. This function deliberately does not retry:
 * a network failure can happen after the provider accepted the message.
 *
 * @return string Provider email identifier.
 */
function resend_mail_send(string $toEmail, string $toName, string $subject, string $html, string $senderRole): string
{
    $apiKey = resend_mail_config('RESEND_API_KEY');
    if ($apiKey === '' || preg_match('/[\x00-\x20\x7F]/', $apiKey)) {
        throw new ResendMailException('Email delivery is not configured.');
    }

    $fromEmail = resend_mail_validate_address(resend_mail_config('RESEND_FROM_EMAIL'), 'sender');
    $toEmail = resend_mail_validate_address($toEmail, 'recipient');
    $replyTo = resend_mail_config('RESEND_REPLY_TO');
    if ($replyTo !== '') {
        $replyTo = resend_mail_validate_address($replyTo, 'reply-to');
    }
    $toName = resend_mail_validate_header_value($toName, 'recipient name');
    $subject = resend_mail_validate_header_value($subject, 'subject');
    resend_mail_validate_header_value($senderRole, 'sender name');
    if (trim($subject) === '') {
        throw new ResendMailException('Email subject is invalid.');
    }
    if (preg_match('//u', $html) !== 1) {
        throw new ResendMailException('Email content is invalid.');
    }

    if (!function_exists('curl_init') || !function_exists('curl_setopt_array') || !function_exists('curl_exec')) {
        throw new ResendMailException('Email delivery is unavailable because cURL is not installed.');
    }

    try {
        $message = [
            'from' => resend_mailbox($senderRole, $fromEmail),
            'to' => [resend_mailbox($toName, $toEmail)],
            'subject' => $subject,
            'html' => $html,
        ];
        if ($replyTo !== '') {
            $message['reply_to'] = $replyTo;
        }
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ResendMailException('Email content could not be encoded.');
    }

    try {
        $handle = curl_init('https://api.resend.com/emails');
    } catch (Throwable $e) {
        throw new ResendMailException('Email delivery could not be initialized.');
    }
    if ($handle === false) {
        throw new ResendMailException('Email delivery could not be initialized.');
    }

    $options = [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOSIGNAL => true,
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'https';
    } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
        $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
    } elseif (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    try {
        if (!curl_setopt_array($handle, $options)) {
            throw new ResendMailException('Email delivery could not be configured.');
        }

        $response = curl_exec($handle);
        if (!is_string($response)) {
            throw new ResendMailException('Email delivery transport failed.');
        }

        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            throw new ResendMailException("Email delivery failed (HTTP {$status}).");
        }

        try {
            $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ResendMailException('Email provider returned an invalid response.');
        }

        if (!is_array($decoded) || !isset($decoded['id']) || !is_string($decoded['id']) || trim($decoded['id']) === '') {
            throw new ResendMailException('Email provider returned an invalid response.');
        }

        return $decoded['id'];
    } catch (ResendMailException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new ResendMailException('Email delivery failed.');
    } finally {
        curl_close($handle);
    }
}
