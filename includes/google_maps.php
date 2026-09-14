<?php

/** Maximum accepted iframe/URL input size; Google embed URLs are normally far smaller. */
const GOOGLE_MAPS_EMBED_MAX_LENGTH = 8192;

/**
 * Accept a Google Maps embed URL or iframe markup copied from Google Maps,
 * returning only a validated HTTPS Maps URL. Invalid input returns null; empty
 * input remains an allowed empty string.
 */
function google_maps_normalize_embed($input): ?string
{
    if (!is_string($input)) {
        return null;
    }

    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (strlen($input) > GOOGLE_MAPS_EMBED_MAX_LENGTH) {
        return null;
    }

    $source = google_maps_extract_iframe_src($input);
    if ($source === null) {
        return null;
    }

    // Google supplies HTML-escaped query separators inside copied iframe code.
    $source = trim(html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($source === '' || strlen($source) > GOOGLE_MAPS_EMBED_MAX_LENGTH
        || preg_match('/[\x00-\x20\x7f\\\\<>"`]/', $source)
        || preg_match('//u', $source) !== 1) {
        return null;
    }

    $parts = parse_url($source);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($host, ['www.google.com', 'maps.google.com'], true)) {
        return null;
    }

    $path = rtrim((string)($parts['path'] ?? ''), '/');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $pathIsEmbed = in_array($path, ['/maps/embed', '/maps/d/embed'], true);
    $legacyEmbed = $path === '/maps'
        && is_string($query['output'] ?? null)
        && strtolower($query['output']) === 'embed';

    return ($pathIsEmbed || $legacyEmbed) ? $source : null;
}

/** Extract one validated iframe src, including the narrowly recognized legacy partial paste. */
function google_maps_extract_iframe_src(string $input): ?string
{
    if (preg_match('/\A<iframe\b/i', $input)) {
        $completeSource = google_maps_extract_complete_iframe_src($input);
        if ($completeSource !== null) {
            return $completeSource;
        }

        // Some older settings contain the iframe opener and src, but not its closing tag.
        if (preg_match('~\A<iframe\b\s+src\s*=\s*"(https://[^"\s<>`]+)"(.*)\z~is', $input, $matches)) {
            return google_maps_is_legacy_iframe_attribute_tail($matches[2]) ? $matches[1] : null;
        }
        if (preg_match('~\A<iframe\b\s+src\s*=\s*(["\'])(https://[^"\'\s<>`]+)\1(.*)\z~is', $input, $matches)) {
            return google_maps_is_legacy_iframe_attribute_tail($matches[3]) ? $matches[2] : null;
        }

        return null;
    }

    // A legacy database value may begin at the URL and retain only the double-quoted iframe tail.
    // Apostrophes are valid literal query text in some Google embed URLs.
    if (preg_match('~\A(https://[^"\s<>`]+)"(.*)\z~is', $input, $matches)) {
        return google_maps_is_legacy_iframe_attribute_tail($matches[2]) ? $matches[1] : null;
    }

    return $input;
}

/** Extract a src only from a syntactically complete iframe. */
function google_maps_extract_complete_iframe_src(string $input): ?string
{

    $quote = null;
    $tagEnd = null;
    for ($index = 7, $length = strlen($input); $index < $length; $index++) {
        $character = $input[$index];
        if ($quote !== null) {
            if ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '>') {
            $tagEnd = $index;
            break;
        }
    }

    if ($tagEnd === null || $quote !== null
        || !preg_match('/\A\s*<\/iframe\s*>\z/i', substr($input, $tagEnd + 1))) {
        return null;
    }

    $attributes = substr($input, 7, $tagEnd - 7);
    $attributeLength = strlen($attributes);
    $position = 0;
    $sources = [];

    while ($position < $attributeLength) {
        while ($position < $attributeLength && ctype_space($attributes[$position])) {
            $position++;
        }
        if ($position >= $attributeLength) {
            break;
        }

        $nameStart = $position;
        while ($position < $attributeLength
            && !ctype_space($attributes[$position])
            && $attributes[$position] !== '='
            && $attributes[$position] !== '/') {
            $position++;
        }
        if ($position === $nameStart) {
            return null;
        }

        $name = strtolower(substr($attributes, $nameStart, $position - $nameStart));
        while ($position < $attributeLength && ctype_space($attributes[$position])) {
            $position++;
        }

        $value = null;
        if ($position < $attributeLength && $attributes[$position] === '=') {
            $position++;
            while ($position < $attributeLength && ctype_space($attributes[$position])) {
                $position++;
            }
            if ($position >= $attributeLength) {
                return null;
            }

            $delimiter = $attributes[$position];
            if ($delimiter === '"' || $delimiter === "'") {
                $position++;
                $valueStart = $position;
                while ($position < $attributeLength && $attributes[$position] !== $delimiter) {
                    $position++;
                }
                if ($position >= $attributeLength) {
                    return null;
                }
                $value = substr($attributes, $valueStart, $position - $valueStart);
                $position++;
            } else {
                $valueStart = $position;
                while ($position < $attributeLength
                    && !ctype_space($attributes[$position])
                    && !str_contains('"\'<>`', $attributes[$position])) {
                    $position++;
                }
                if ($position === $valueStart) {
                    return null;
                }
                $value = substr($attributes, $valueStart, $position - $valueStart);
            }
        }

        if ($name === 'src') {
            if ($value === null) {
                return null;
            }
            $sources[] = $value;
        }
    }

    return count($sources) === 1 ? $sources[0] : null;
}

/**
 * Verify the limited attribute tail found in damaged legacy iframe values.
 * The original attributes are discarded; this only prevents arbitrary trailing
 * content, duplicate src attributes, or event handlers from being accepted.
 */
function google_maps_is_legacy_iframe_attribute_tail(string $tail): bool
{
    $tail = trim($tail);
    if (preg_match('~</iframe\s*>\z~i', $tail, $matches, PREG_OFFSET_CAPTURE)) {
        $tail = rtrim(substr($tail, 0, $matches[0][1]));
    }
    if (str_ends_with($tail, '>')) {
        $tail = rtrim(substr($tail, 0, -1));
    }
    if ($tail === '' || strpbrk($tail, '<>') !== false) {
        return false;
    }

    $allowedAttributes = [
        'width', 'height', 'style', 'allowfullscreen', 'loading',
        'referrerpolicy', 'title', 'frameborder', 'scrolling',
    ];
    $seen = [];
    $position = 0;
    $length = strlen($tail);

    while ($position < $length) {
        while ($position < $length && ctype_space($tail[$position])) {
            $position++;
        }
        if ($position >= $length) {
            break;
        }

        $nameStart = $position;
        while ($position < $length
            && (ctype_alpha($tail[$position]) || ctype_digit($tail[$position]) || $tail[$position] === '-')) {
            $position++;
        }
        if ($position === $nameStart) {
            return false;
        }

        $name = strtolower(substr($tail, $nameStart, $position - $nameStart));
        if (!in_array($name, $allowedAttributes, true) || isset($seen[$name])) {
            return false;
        }
        $seen[$name] = true;

        while ($position < $length && ctype_space($tail[$position])) {
            $position++;
        }

        $value = null;
        if ($position < $length && $tail[$position] === '=') {
            $position++;
            while ($position < $length && ctype_space($tail[$position])) {
                $position++;
            }
            if ($position >= $length) {
                return false;
            }

            $delimiter = $tail[$position];
            if ($delimiter === '"' || $delimiter === "'") {
                $position++;
                $valueStart = $position;
                while ($position < $length && $tail[$position] !== $delimiter) {
                    $position++;
                }
                $value = substr($tail, $valueStart, $position - $valueStart);
                // The real legacy value is truncated inside its final referrerpolicy quote.
                if ($position < $length) {
                    $position++;
                } elseif ($name !== 'referrerpolicy') {
                    return false;
                }
            } else {
                $valueStart = $position;
                while ($position < $length && !ctype_space($tail[$position])) {
                    $position++;
                }
                $value = substr($tail, $valueStart, $position - $valueStart);
            }
        }

        if (!google_maps_is_safe_legacy_iframe_attribute($name, $value)) {
            return false;
        }
    }

    return count($seen) > 0;
}

/** Validate only common inert iframe attributes; none are copied to the page. */
function google_maps_is_safe_legacy_iframe_attribute(string $name, ?string $value): bool
{
    if ($name === 'allowfullscreen') {
        return $value === null || $value === '';
    }
    if ($value === null) {
        return false;
    }

    switch ($name) {
        case 'width':
        case 'height':
            return preg_match('/\A(?:[1-9]\d{0,3}|100%)\z/', $value) === 1;
        case 'style':
            return preg_match('/\Aborder\s*:\s*0\s*;?\z/i', $value) === 1;
        case 'loading':
            return in_array(strtolower($value), ['lazy', 'eager'], true);
        case 'referrerpolicy':
            return in_array(strtolower($value), [
                'no-referrer', 'no-referrer-when-downgrade', 'origin',
                'origin-when-cross-origin', 'same-origin', 'strict-origin',
                'strict-origin-when-cross-origin', 'unsafe-url',
            ], true);
        case 'title':
            return strlen($value) <= 256 && preg_match('/[\x00-\x1f\x7f<>]/', $value) !== 1;
        case 'frameborder':
            return in_array($value, ['0', '1'], true);
        case 'scrolling':
            return in_array(strtolower($value), ['yes', 'no', 'auto'], true);
        default:
            return false;
    }
}
