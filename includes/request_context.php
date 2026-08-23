<?php
/**
 * Return the actual client address. Forwarded headers are accepted only when
 * the immediate peer is explicitly trusted in TRUSTED_PROXIES.
 */
function request_ip_in_cidr(string $ip, string $cidr): bool {
    [$network, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
    $network = trim((string)$network);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($network, FILTER_VALIDATE_IP)) return false;
    if ($bits === null) return inet_pton($ip) === inet_pton($network);
    $bits = (int)$bits;
    $ip_bin = inet_pton($ip); $net_bin = inet_pton($network);
    if ($ip_bin === false || $net_bin === false || strlen($ip_bin) !== strlen($net_bin)) return false;
    $max = strlen($ip_bin) * 8;
    if ($bits < 0 || $bits > $max) return false;
    $full = intdiv($bits, 8); $rem = $bits % 8;
    if ($full > 0 && substr($ip_bin, 0, $full) !== substr($net_bin, 0, $full)) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return (ord($ip_bin[$full]) & ord($mask)) === (ord($net_bin[$full]) & ord($mask));
}

function request_peer_is_trusted(string $peer): bool {
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return false;
    $raw = trim((string)($_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: ''));
    if ($raw === '') return false;
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        if (request_ip_in_cidr($peer, $entry)) return true;
    }
    return false;
}

function request_client_ip(): string {
    $peer = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return '0.0.0.0';
    if (!request_peer_is_trusted($peer)) return $peer;
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $chain = [];
    foreach (explode(',', $forwarded) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) $chain[] = $candidate;
    }
    $chain[] = $peer;
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!request_peer_is_trusted($chain[$i])) return $chain[$i];
    }
    return $chain[0] ?? $peer;
}
?>
