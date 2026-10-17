<?php
declare(strict_types=1);

/** Parse a bounded, unique set of CMS media IDs without coercing malformed values. */
function media_cms_parse_delete_ids(array $request, int $maximum = 100): array
{
    if ($maximum < 1 || $maximum > 100) throw new InvalidArgumentException('The media ID limit is invalid.');
    if (array_key_exists('id', $request) && array_key_exists('ids', $request)) {
        throw new InvalidArgumentException('Provide either one media ID or a media ID list.');
    }
    if (array_key_exists('ids', $request)) {
        if (!is_array($request['ids']) || !array_is_list($request['ids'])) {
            throw new InvalidArgumentException('Media IDs must be a list.');
        }
        $values = $request['ids'];
    } elseif (array_key_exists('id', $request)) {
        $values = [$request['id']];
    } else {
        throw new InvalidArgumentException('No Media IDs provided.');
    }

    if ($values === [] || count($values) > $maximum) {
        throw new InvalidArgumentException('Choose between 1 and ' . $maximum . ' media records at a time.');
    }
    $ids = [];
    foreach ($values as $value) {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1) {
            $id = (int)$value;
        } else {
            throw new InvalidArgumentException('Every media ID must be a positive integer.');
        }
        if ($id < 1 || $id > 2147483647 || isset($ids[$id])) {
            throw new InvalidArgumentException('Media IDs must be positive and unique.');
        }
        $ids[$id] = $id;
    }
    return array_values($ids);
}

/** Verify a locked result set is complete and, for cleanup, truly missing. */
function media_cms_validate_delete_rows(array $rows, array $ids, bool $missingOnly): void
{
    if (count($rows) !== count($ids)) throw new RuntimeException('One or more media records were not found. Refresh the gallery and try again.');
    $found = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1 || isset($found[$id]) || !in_array($id, $ids, true)) {
            throw new RuntimeException('The selected media records changed. Refresh the gallery and try again.');
        }
        $found[$id] = true;
        if (($row['slot_assignment'] ?? '') === 'home-hero') {
            throw new RuntimeException('The Homepage Hero Banner cannot be deleted, it can only be replaced.');
        }
        if ($missingOnly && ($row['source_status'] ?? '') !== 'missing') {
            throw new RuntimeException('A selected source is present or cannot be safely checked. Refresh the gallery and choose only files that are missing.');
        }
    }
}

/** Count safe-to-remove dependencies and block positional navigation that could retarget. */
function media_cms_missing_cleanup_plan(array $ids, array $rows, array $hotspots, bool $hasStableTargetIds): array
{
    $selectedIds = array_fill_keys(array_map('intval', $ids), true);
    $selectedSlots = [];
    foreach ($rows as $row) {
        if (($row['media_type'] ?? '') === '360' && (string)($row['slot_assignment'] ?? '') !== '') {
            $selectedSlots[(string)$row['slot_assignment']] = true;
        }
    }

    $sourceHotspots = 0;
    $incomingHotspots = 0;
    $legacyReferences = 0;
    $fingerprintRows = [];
    foreach ($hotspots as $hotspot) {
        $sourceId = (int)($hotspot['media_id'] ?? 0);
        $targetId = isset($hotspot['target_media_id']) ? (int)$hotspot['target_media_id'] : 0;
        $isSelectedSource = isset($selectedIds[$sourceId]);
        if ($isSelectedSource) $sourceHotspots++;
        if ($hasStableTargetIds && ($hotspot['type'] ?? '') === 'nav'
            && $targetId > 0 && isset($selectedIds[$targetId]) && !$isSelectedSource) {
            $incomingHotspots++;
        }
        $isLegacy = !$hasStableTargetIds || !array_key_exists('target_media_id', $hotspot) || $hotspot['target_media_id'] === null;
        if (!$isSelectedSource && ($hotspot['type'] ?? '') === 'nav' && $isLegacy
            && isset($selectedSlots[(string)($hotspot['source_slot'] ?? '')])) {
            $legacyReferences++;
        }
        if ($isSelectedSource
            || ($hasStableTargetIds && ($hotspot['type'] ?? '') === 'nav' && $targetId > 0 && isset($selectedIds[$targetId]))
            || (!$isSelectedSource && ($hotspot['type'] ?? '') === 'nav' && $isLegacy && isset($selectedSlots[(string)($hotspot['source_slot'] ?? '')]))) {
            $fingerprintRows[] = [
                'id' => (int)($hotspot['id'] ?? 0),
                'media_id' => $sourceId,
                'type' => (string)($hotspot['type'] ?? ''),
                'target_media_id' => $targetId > 0 ? $targetId : null,
                'target_pano_index' => isset($hotspot['target_pano_index']) ? (int)$hotspot['target_pano_index'] : null,
                'source_slot' => (string)($hotspot['source_slot'] ?? ''),
            ];
        }
    }
    usort($fingerprintRows, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
    $fingerprint = hash('sha256', json_encode([
        'media_ids' => array_values(array_map('intval', $ids)),
        'media_slots' => array_keys($selectedSlots),
        'hotspots' => $fingerprintRows,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return [
        'source_hotspots' => $sourceHotspots,
        'incoming_hotspots' => $incomingHotspots,
        'legacy_references' => $legacyReferences,
        'stable_target_ids' => $hasStableTargetIds,
        'can_remove' => $legacyReferences === 0,
        'fingerprint' => $fingerprint,
    ];
}
