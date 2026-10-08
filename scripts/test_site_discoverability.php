<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/site_metadata.php';

$root = dirname(__DIR__);
$homeSource = (string)file_get_contents($root . '/index.php');
$homeJs = (string)file_get_contents($root . '/assets/js/index.js');
$robotsSource = (string)file_get_contents($root . '/robots.php');
$sitemapSource = (string)file_get_contents($root . '/sitemap.php');
$apacheRules = (string)file_get_contents($root . '/.htaccess');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(site_metadata_app_base_url('https://example.test') === 'https://example.test', 'domain-root base URL is retained');
$assert(site_metadata_absolute_url('/', 'https://example.test') === 'https://example.test/', 'home URL canonicalizes to the root slash');
$assert(site_metadata_absolute_url('showroom.php', 'https://example.test/Sevilla360/') === 'https://example.test/Sevilla360/showroom.php', 'subdirectory routes use the configured install path');
$assert(site_metadata_app_base_url('http://localhost:8080/Sevilla360') === 'http://localhost:8080/Sevilla360', 'local HTTP development URLs remain supported');
$assert(site_metadata_app_base_url('http://example.test/Sevilla360') === null, 'public HTTP URLs are rejected');
$assert(site_metadata_app_base_url('https://user@example.test/Sevilla360') === null, 'credentials in configured URLs are rejected');
$assert(site_metadata_app_base_url('https://example.test/../other') === null, 'dot traversal in configured paths is rejected');
$assert(site_metadata_app_base_url('https://example.test/Sevilla360?next=evil') === null, 'query strings in configured URLs are rejected');
$assert(site_metadata_absolute_url('../booking.php', 'https://example.test/Sevilla360') === null, 'relative traversal paths are rejected');
$assert(site_metadata_absolute_url('showroom.php?venue=1', 'https://example.test/Sevilla360') === null, 'query strings cannot enter canonical paths');

$assert(str_contains($homeSource, 'foreach (($public_venues[$category] ?? []) as $catalog_venue)')
    && str_contains($homeSource, '<article class="idx-catalog-card">')
    && str_contains($homeSource, 'href="<?php echo htmlspecialchars($catalog_booking_url'), 'public venue names and crawlable booking links render on the server');
$assert(str_contains($homeSource, "'thumbnails' =>") && str_contains($homeJs, 'Array.isArray(venue.thumbnails)')
    && str_contains($homeJs, 'image.loading = \'lazy\''), 'homepage enhancement reuses server-selected thumbnails and lazy loading');

$assetUrl = site_metadata_asset_url('assets/css/style.css');
$assert(preg_match('/\?v=[0-9]+\z/', $assetUrl) === 1, 'local CSS URLs use a stable file version');
$assert(site_metadata_asset_url('assets/css/../style.css') === '', 'asset traversal is rejected');
$assert(site_metadata_asset_url('assets/css/style.css?v=caller') === '', 'caller query strings are rejected');

$_ENV['APP_BASE_URL'] = 'https://example.test/Sevilla360';
$_SERVER['HTTP_HOST'] = 'attacker.invalid';
$_SERVER['REQUEST_URI'] = '/Sevilla360/showroom.php?utm_source=ignored';
$page_title = 'Virtual Tour &amp; Stay';
$page_description = 'Explore <script>alert(1)</script> resort spaces.';
$active_page = 'showroom';
ob_start();
include __DIR__ . '/../includes/header.php';
$showroomHtml = (string)ob_get_clean();
$assert(str_contains($showroomHtml, 'rel="canonical" href="https://example.test/Sevilla360/showroom.php"'), 'canonical drops query parameters and ignores the request Host header');
$assert(str_contains($showroomHtml, 'property="og:url" content="https://example.test/Sevilla360/showroom.php"'), 'social URL matches the canonical URL');
$assert(str_contains($showroomHtml, 'content="Explore &lt;script&gt;alert(1)&lt;/script&gt; resort spaces."'), 'page description is escaped');
$assert(!str_contains($showroomHtml, 'attacker.invalid'), 'untrusted request host is absent from metadata');

$_SERVER['REQUEST_URI'] = '/Sevilla360/?campaign=ignored';
$active_page = 'home';
ob_start();
include __DIR__ . '/../includes/header.php';
$homeHtml = (string)ob_get_clean();
$assert(str_contains($homeHtml, 'rel="canonical" href="https://example.test/Sevilla360/"'), 'subdirectory homepage canonical uses a single root form');

$_SERVER['REQUEST_URI'] = '/Sevilla360/booking.php?venue_id=42';
$active_page = 'booking';
unset($page_description);
ob_start();
include __DIR__ . '/../includes/header.php';
$bookingHtml = (string)ob_get_clean();
$assert(str_contains($bookingHtml, '<meta name="robots" content="noindex, nofollow">'), 'transactional booking page is noindex');
$assert(!str_contains($bookingHtml, 'rel="canonical"'), 'transactional booking page has no public canonical');

$captureEndpoint = static function (string $file): string {
    ob_start();
    include __DIR__ . '/../' . $file;
    return (string)ob_get_clean();
};
$robots = $captureEndpoint('robots.php');
$assert(str_contains($robots, 'Allow: /') && str_contains($robots, 'Sitemap: https://example.test/Sevilla360/sitemap.xml'), 'robots output allows crawlable assets and publishes the nested sitemap URL');
$assert(str_contains($robotsSource, "header('Content-Type: text/plain; charset=UTF-8')"), 'robots endpoint declares plain text without a session or database');
$sitemap = $captureEndpoint('sitemap.php');
preg_match_all('~<loc>(.*?)</loc>~', $sitemap, $matches);
$locations = array_map(static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches[1] ?? []);
$assert($locations === [
    'https://example.test/Sevilla360/',
    'https://example.test/Sevilla360/showroom.php',
    'https://example.test/Sevilla360/support.php',
], 'sitemap contains only the three canonical public pages');
$assert(str_contains($sitemapSource, "header('Content-Type: application/xml; charset=UTF-8')"), 'sitemap endpoint declares XML without a session or database');

$assert(str_contains($apacheRules, 'RewriteRule ^robots\\.txt$ robots.php [END]')
    && str_contains($apacheRules, 'RewriteRule ^sitemap\\.xml$ sitemap.php [END]')
    && str_contains($apacheRules, 'QUERY_STRING} (?:^|&)v=[0-9]+')
    && str_contains($apacheRules, 'max-age=2592000')
    && str_contains($apacheRules, 'AddOutputFilterByType DEFLATE text/css text/javascript application/javascript image/svg+xml')
    && !str_contains($apacheRules, 'text/html'), 'Apache exposes crawler endpoints and scopes caching/compression to versioned assets and static text');

$_ENV['APP_BASE_URL'] = 'http://example.test/Sevilla360';
$invalidRobots = $captureEndpoint('robots.php');
$assert(str_contains($invalidRobots, 'Allow: /') && !str_contains($invalidRobots, 'Sitemap:'), 'invalid public URL yields safe robots output without a poisoned URL');
$invalidSitemap = $captureEndpoint('sitemap.php');
$assert(str_contains($invalidSitemap, '<urlset ') && !str_contains($invalidSitemap, '<loc>'), 'invalid public URL yields an empty valid sitemap document');

echo "PASS|$checks site metadata, asset version, robots, sitemap, and noindex assertions\n";
