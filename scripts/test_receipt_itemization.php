<?php
require_once __DIR__ . '/../includes/receipt_itemization.php';

$allocation = [['item_name' => 'unused', 'line_total' => 1200.00]];
$aggregate_plan = receipt_itemization_plan([
    ['item_name' => 'Hotel Add-on Rooms', 'amount' => 1200.00],
    ['item_name' => 'Room Add-on: Building A - Deluxe', 'amount' => 1200.00],
], $allocation);
if (count($aggregate_plan['line_items']) !== 1 || $aggregate_plan['allocations_are_priced']) {
    exit("Aggregate room charge must be priced once and allocations informational.\n");
}

$allocations_only_plan = receipt_itemization_plan([], $allocation);
if (!$allocations_only_plan['allocations_are_priced']) exit("Allocations-only booking must display priced allocations.\n");

$no_allocations_plan = receipt_itemization_plan([['item_name' => 'Catering', 'amount' => 500.00]], []);
if (count($no_allocations_plan['line_items']) !== 1 || $no_allocations_plan['allocations_are_priced']) {
    exit("Bookings without allocations must retain their line items.\n");
}

echo "Receipt itemization checks passed\n";
