<?php
/**
 * Choose one authoritative priced representation for hotel room additions.
 * A finalized booking may have an aggregate line item plus its allocation
 * rows; allocation rows are then informational only.
 */
function receipt_is_room_aggregate_item(string $name): bool
{
    $normalized = preg_replace('/\s+/', ' ', trim($name));
    return strtolower((string)$normalized) === 'hotel add-on rooms';
}

function receipt_is_room_detail_item(string $name): bool
{
    return str_starts_with(strtolower(trim($name)), 'room add-on:');
}

/** @return array{line_items: array<int, array<string, mixed>>, allocations_are_priced: bool} */
function receipt_itemization_plan(array $line_items, array $room_allocations): array
{
    $has_aggregate = false;
    $has_room_detail = false;
    foreach ($line_items as $item) {
        $name = (string)($item['item_name'] ?? '');
        $has_aggregate = $has_aggregate || receipt_is_room_aggregate_item($name);
        $has_room_detail = $has_room_detail || receipt_is_room_detail_item($name);
    }

    $priced_line_items = [];
    foreach ($line_items as $item) {
        // The aggregate amount already represents these room allocations.
        if ($has_aggregate && receipt_is_room_detail_item((string)($item['item_name'] ?? ''))) continue;
        $priced_line_items[] = $item;
    }

    return [
        'line_items' => $priced_line_items,
        'allocations_are_priced' => count($room_allocations) > 0 && !$has_aggregate && !$has_room_detail,
    ];
}
