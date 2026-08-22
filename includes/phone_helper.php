<?php
function normalize_contact_phone(string $value): string
{
    $compact = preg_replace('/[\s().-]+/', '', trim($value));
    if (!$compact) throw new InvalidArgumentException('A contact number is required.');
    if (strpos($compact, '00') === 0) $compact = '+' . substr($compact, 2);
    if (strpos($compact, '09') === 0) $compact = '+63' . substr($compact, 1);
    elseif (strpos($compact, '639') === 0) $compact = '+' . $compact;
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $compact)) throw new InvalidArgumentException('Enter a valid international contact number.');
    return $compact;
}
