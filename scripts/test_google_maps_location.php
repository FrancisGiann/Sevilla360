<?php
/** Focused tests for Google Maps embed normalization and homepage directions. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/google_maps.php';

$root = dirname(__DIR__);
$indexSource = (string)file_get_contents($root . '/index.php');
$settingsSource = (string)file_get_contents($root . '/includes/admin-page/admin_settings.php');
$saveSource = (string)file_get_contents($root . '/actions/admin/save_preferences.php');
$checks = [];

$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[$label] = $passed;
};

$embedUrl = 'https://www.google.com/maps/embed?pb=abc&amp;z=15';
$normalizedUrl = 'https://www.google.com/maps/embed?pb=abc&z=15';
$check(
    'complete Google iframe extracts only src and decodes HTML entities',
    google_maps_normalize_embed('<iframe width="600" src="' . $embedUrl . '" height="450" allowfullscreen=""></iframe>') === $normalizedUrl
);
$check(
    'URL-only embed is accepted',
    google_maps_normalize_embed('  ' . $normalizedUrl . '  ') === $normalizedUrl
);
$check(
    'single-quoted and unquoted iframe src attributes are accepted',
    google_maps_normalize_embed("<iframe src='https://www.google.com/maps/embed?pb=single&amp;z=14'></iframe>")
        === 'https://www.google.com/maps/embed?pb=single&z=14'
        && google_maps_normalize_embed('<iframe src=https://www.google.com/maps/embed?pb=plain&amp;z=13 width=600></iframe>')
        === 'https://www.google.com/maps/embed?pb=plain&z=13'
);
$legacySavedValue = 'https://www.google.com/maps/embed?pb=!1m28!1m12!1m3!1d599.3681930743351!2d121.58695110016006!3d13.966471810171663!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!4m13!3e6!4m5!1s0x33bd4cf4ccfa4e6b%3A0xe2048afdba1dd013!2sM.I.%20Sevilla\'s%20Resort%2C%20Lucena%20City%2C%204301%20Quezon!3m2!1d13.9663065!2d121.58730969999999!4m5!1s0x33bd4cf4ccfa4e6b%3A0xe2048afdba1dd013!2sM.I.%20Sevilla\'s%20Resort%2C%20Lucena%20City%2C%204301%20Quezon!3m2!1d13.9663065!2d121.58730969999999!5e1!3m2!1sen!2sph!4v1789219114389!5m2!1sen!2sph" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin';
$legacyCleanUrl = 'https://www.google.com/maps/embed?pb=!1m28!1m12!1m3!1d599.3681930743351!2d121.58695110016006!3d13.966471810171663!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!4m13!3e6!4m5!1s0x33bd4cf4ccfa4e6b%3A0xe2048afdba1dd013!2sM.I.%20Sevilla\'s%20Resort%2C%20Lucena%20City%2C%204301%20Quezon!3m2!1d13.9663065!2d121.58730969999999!4m5!1s0x33bd4cf4ccfa4e6b%3A0xe2048afdba1dd013!2sM.I.%20Sevilla\'s%20Resort%2C%20Lucena%20City%2C%204301%20Quezon!3m2!1d13.9663065!2d121.58730969999999!5e1!3m2!1sen!2sph!4v1789219114389!5m2!1sen!2sph';
$check(
    'exact malformed legacy database value normalizes to its clean Google embed URL',
    google_maps_normalize_embed($legacySavedValue) === $legacyCleanUrl
);
$check(
    'truncated iframe opener and closing tag recover only the validated src',
    google_maps_normalize_embed('<iframe src="' . $normalizedUrl . '" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin')
        === $normalizedUrl
);
$check('empty input remains allowed', google_maps_normalize_embed('  ') === '');
$check('non-string input is rejected', google_maps_normalize_embed(['not', 'a', 'string']) === null);
$check(
    'malformed or ambiguous iframe input is rejected',
    google_maps_normalize_embed('<iframe src="https://www.google.com/maps/embed?pb=x">') === null
        && google_maps_normalize_embed('<iframe src="https://www.google.com/maps/embed?pb=x" src="https://www.google.com/maps/embed?pb=y"></iframe>') === null
);
$check(
    'legacy URL-prefix recovery rejects lookalikes, extra src values, scripts, and arbitrary tails',
    google_maps_normalize_embed('https://www.google.com.evil.example/maps/embed?pb=x" width="600"') === null
        && google_maps_normalize_embed('https://evil.example/maps/embed?pb=x" width="600"') === null
        && google_maps_normalize_embed($normalizedUrl . '" src="https://evil.example/maps/embed?pb=x') === null
        && google_maps_normalize_embed($normalizedUrl . '" onload="alert(1)') === null
        && google_maps_normalize_embed($normalizedUrl . '" <script>alert(1)</script>') === null
        && google_maps_normalize_embed($normalizedUrl . '" width="600" arbitrary trailing text') === null
);
$check(
    'hostile schemes, hosts, and non-embed Google routes are rejected',
    google_maps_normalize_embed('<iframe src="javascript:alert(1)"></iframe>') === null
        && google_maps_normalize_embed('https://www.google.com.evil.example/maps/embed?pb=x') === null
        && google_maps_normalize_embed('https://evil.example/maps/embed?pb=x') === null
        && google_maps_normalize_embed('http://www.google.com/maps/embed?pb=x') === null
        && google_maps_normalize_embed('https://www.google.com/search?q=x') === null
);
$check(
    'homepage has no standalone directions button or generated directions URL',
    !str_contains($indexSource, 'idx-location-directions')
        && !str_contains($indexSource, 'google_maps_directions_url')
        && !str_contains($indexSource, '/maps/dir/')
);
$check(
    'homepage renders the normalized embed or its map fallback and settings reuses normalized legacy values',
    str_contains($indexSource, 'google_maps_normalize_embed($biz_info[\'biz_map_embed\'] ?? \'\')')
        && str_contains($indexSource, '$map_src = $normalized_map_embed !== null && $normalized_map_embed !== \'\'')
        && str_contains($indexSource, 'htmlspecialchars($map_src, ENT_QUOTES, \'UTF-8\')')
        && str_contains($indexSource, "urlencode('M.I. Sevilla Resort ' . \$biz_info['biz_address'])")
        && str_contains($settingsSource, '<textarea id="biz-map-embed" name="biz_map_embed"')
        && str_contains($settingsSource, 'google_maps_normalize_embed($current_settings[\'biz_map_embed\'] ?? \'\')')
        && str_contains($settingsSource, 'the embedded map provides its own directions control')
        && !str_contains($settingsSource, 'Get Directions uses that map location')
);
$check(
    'save validates the map before its first upsert and stores only the normalized URL',
    strpos($saveSource, '$map_embed_url = google_maps_normalize_embed($map_embed_input);') !== false
        && strpos($saveSource, '$map_embed_url = google_maps_normalize_embed($map_embed_input);') < strpos($saveSource, 'INSERT INTO system_settings')
        && str_contains($saveSource, "'biz_map_embed' => \$map_embed_url")
        && str_contains($saveSource, 'Error|Paste a complete Google Maps iframe')
);

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "|$label\n";
    if (!$passed) {
        $failed++;
    }
}
exit($failed === 0 ? 0 : 1);
