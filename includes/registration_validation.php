<?php

/**
 * Validate customer identity names without rewriting them. Names may contain
 * Unicode letters/combining marks and normal internal spaces, apostrophes or
 * hyphens; digits and other punctuation are rejected.
 */
function registration_name_is_valid(string $name): bool
{
    return preg_match('/\A\p{L}[\p{L}\p{M}]*(?:[ \'\x{2019}\x{2010}-\x{2015}-]\p{L}[\p{L}\p{M}]*)*\z/u', $name) === 1;
}
