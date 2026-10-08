<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site_metadata.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$sitemapUrl = site_metadata_absolute_url('sitemap.xml');
echo "User-agent: *\nAllow: /\n";
if ($sitemapUrl !== null) {
    echo 'Sitemap: ' . $sitemapUrl . "\n";
}
