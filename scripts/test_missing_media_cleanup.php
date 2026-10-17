<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/media_helper.php';
require_once __DIR__ . '/../includes/media_cleanup.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$assert(media_cms_parse_delete_ids(['id' => '42']) === [42], 'single string media ID is accepted without coercion');
$assert(media_cms_parse_delete_ids(['ids' => ['12', 13]]) === [12, 13], 'bounded numeric ID list is accepted');
$assert(media_cms_parse_delete_ids(['id' => '2147483647']) === [2147483647], 'largest signed database media ID is accepted');
$throws(static fn() => media_cms_parse_delete_ids(['id' => '1.5']), 'decimal media ID is rejected');
$throws(static fn() => media_cms_parse_delete_ids(['id' => '2147483648']), 'media ID outside the signed database range is rejected');
$throws(static fn() => media_cms_parse_delete_ids(['id' => 2147483648]), 'integer media ID outside the signed database range is rejected');
$throws(static fn() => media_cms_parse_delete_ids(['ids' => [3, '3']]), 'duplicate IDs are rejected');
$throws(static fn() => media_cms_parse_delete_ids(['ids' => range(1, 101)]), 'oversized ID list is rejected');
$throws(static fn() => media_cms_parse_delete_ids(['id' => 1, 'ids' => [1]]), 'ambiguous single and bulk IDs are rejected');
$throws(static fn() => media_cms_parse_delete_ids(['ids' => ['0']]), 'zero media ID is rejected');

$missingRow = ['id' => 10, 'slot_assignment' => 'venue_test_hall_360', 'media_type' => '360', 'source_status' => 'missing'];
media_cms_validate_delete_rows([$missingRow], [10], true);
$assert(true, 'safely missing source row is eligible for missing-only cleanup');
$throws(static fn() => media_cms_validate_delete_rows([array_replace($missingRow, ['source_status' => 'available'])], [10], true), 'reappeared source is rejected');
$throws(static fn() => media_cms_validate_delete_rows([array_replace($missingRow, ['source_status' => 'unavailable'])], [10], true), 'unreadable source is not classified as missing');
$throws(static fn() => media_cms_validate_delete_rows([$missingRow], [10, 11], true), 'partially missing DB result set is rejected');

$stablePlan = media_cms_missing_cleanup_plan([10, 11], [
    $missingRow,
    ['id' => 11, 'slot_assignment' => 'venue_test_hall_360', 'media_type' => '360', 'source_status' => 'missing'],
], [
    ['id' => 1, 'media_id' => 10, 'type' => 'info', 'target_media_id' => null, 'source_slot' => 'venue_test_hall_360'],
    ['id' => 2, 'media_id' => 12, 'type' => 'nav', 'target_media_id' => 10, 'source_slot' => 'venue_test_hall_360'],
    ['id' => 3, 'media_id' => 11, 'type' => 'nav', 'target_media_id' => 10, 'source_slot' => 'venue_test_hall_360'],
], true);
$assert(array_intersect_key($stablePlan, array_flip(['source_hotspots', 'incoming_hotspots', 'legacy_references', 'stable_target_ids', 'can_remove'])) === [
    'source_hotspots' => 2,
    'incoming_hotspots' => 1,
    'legacy_references' => 0,
    'stable_target_ids' => true,
    'can_remove' => true,
], 'stable references are counted exactly and selected-source links are not double-counted');
$changedSameCountPlan = media_cms_missing_cleanup_plan([10, 11], [
    $missingRow,
    ['id' => 11, 'slot_assignment' => 'venue_test_hall_360', 'media_type' => '360', 'source_status' => 'missing'],
], [
    ['id' => 1, 'media_id' => 10, 'type' => 'info', 'target_media_id' => null, 'source_slot' => 'venue_test_hall_360'],
    ['id' => 2, 'media_id' => 12, 'type' => 'nav', 'target_media_id' => 11, 'source_slot' => 'venue_test_hall_360'],
    ['id' => 3, 'media_id' => 11, 'type' => 'nav', 'target_media_id' => 10, 'source_slot' => 'venue_test_hall_360'],
], true);
$assert($stablePlan['fingerprint'] !== $changedSameCountPlan['fingerprint'], 'dependency fingerprint catches a changed target when dependent row counts stay the same');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $stablePlan['fingerprint']) === 1, 'cleanup dependency fingerprint is a fixed-length SHA-256 digest');

$legacyPlan = media_cms_missing_cleanup_plan([10], [$missingRow], [
    ['id' => 4, 'media_id' => 12, 'type' => 'nav', 'target_media_id' => null, 'target_pano_index' => 0, 'source_slot' => 'venue_test_hall_360'],
], true);
$assert(!$legacyPlan['can_remove'] && $legacyPlan['legacy_references'] === 1, 'external legacy positional nav is blocked to prevent target retargeting');

$selectedLegacyPlan = media_cms_missing_cleanup_plan([10], [$missingRow], [
    ['id' => 5, 'media_id' => 10, 'type' => 'nav', 'target_media_id' => null, 'target_pano_index' => 1, 'source_slot' => 'venue_test_hall_360'],
], true);
$assert($selectedLegacyPlan['can_remove'] && $selectedLegacyPlan['source_hotspots'] === 1, 'legacy hotspot owned by removed source is deleted with that source');

$preMigrationPlan = media_cms_missing_cleanup_plan([10], [$missingRow], [
    ['id' => 6, 'media_id' => 12, 'type' => 'nav', 'target_pano_index' => 0, 'source_slot' => 'venue_test_hall_360'],
], false);
$assert(!$preMigrationPlan['can_remove'] && $preMigrationPlan['legacy_references'] === 1, 'pre-stable-ID schema blocks external positional navigation');

$unrelatedSlotPlan = media_cms_missing_cleanup_plan([10], [$missingRow], [
    ['id' => 7, 'media_id' => 12, 'type' => 'nav', 'target_media_id' => null, 'source_slot' => 'venue_other_hall_360'],
], true);
$assert($unrelatedSlotPlan['can_remove'] && $unrelatedSlotPlan['legacy_references'] === 0, 'unrelated source-gallery navigation does not block cleanup');

$uploadRoot = media_cms_upload_root();
$fixtureName = '.cms-missing-status-' . bin2hex(random_bytes(8)) . '.jpg';
$fixturePath = $uploadRoot . DIRECTORY_SEPARATOR . $fixtureName;
$symlinkName = '.cms-invalid-status-' . bin2hex(random_bytes(8)) . '.jpg';
$symlinkPath = $uploadRoot . DIRECTORY_SEPARATOR . $symlinkName;
try {
    $assert(file_put_contents($fixturePath, 'fixture') !== false, 'temporary media status fixture is created');
    $assert(media_cms_source_file_status('assets/uploads/' . $fixtureName) === 'available', 'existing readable source is classified as available');
    if (@symlink('/etc/passwd', $symlinkPath)) {
        $assert(media_cms_source_file_status('assets/uploads/' . $symlinkName) === 'invalid', 'symlink source is not misclassified as missing');
    }
    @unlink($fixturePath);
    clearstatcache(true, $fixturePath);
    $assert(media_cms_source_file_status('assets/uploads/' . $fixtureName) === 'missing', 'absent safe source path is classified as missing');
} finally {
    @unlink($fixturePath);
    @unlink($symlinkPath);
}

echo "PASS|$checks missing-media cleanup and source-state checks\n";
