<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site_metadata.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$baseUrl = site_metadata_app_base_url();
if ($baseUrl === null) {
    http_response_code(503);
}

$paths = $baseUrl === null ? [] : ['/', 'showroom.php', 'support.php'];
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($paths as $path) {
    $url = site_metadata_absolute_url($path, $baseUrl);
    if ($url === null) continue;
    echo '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</loc></url>';
}
echo '</urlset>';
