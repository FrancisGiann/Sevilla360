<?php
/**
 * SEVILLA360 - Master Pricing Engine
 * Calculates the exact base amount and total amount for a booking.
 * Dynamically fetches the latest prices set by the Admin in Settings.
 */
function calculate_booking_price($conn, $venue_id, $venue_category, $start_date, $end_date, $guests, $stay_type = 'Day Time Stay') {
    // Calculate Nights/Days
    $start_dt = new DateTime($start_date);
    $end_dt = new DateTime($end_date);
    $nights = $start_dt->diff($end_dt)->days;
    if ($nights === 0) $nights = 1; 

    $base_amount = 0;
    $true_total = 0;

    if ($venue_category === 'Hotel Room') {
        $stmt = $conn->prepare("SELECT nightly_rate, base_capacity, max_capacity, extra_pax_rate FROM hotel_rooms WHERE venue_id = ?");
        $stmt->bind_param("i", $venue_id);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();

        $base_amount = floatval($room['nightly_rate']);
        $true_total = $base_amount * $nights;
        
        if ($guests > $room['base_capacity']) {
            $extra_pax = $guests - $room['base_capacity'];
            $true_total += ($extra_pax * floatval($room['extra_pax_rate']) * $nights);
        }
    } 
    elseif ($venue_category === 'Resort Villa') {
        $stmt = $conn->prepare("SELECT day_rate, overnight_rate, base_capacity, max_capacity, extra_pax_rate FROM villas WHERE venue_id = ?");
        $stmt->bind_param("i", $venue_id);
        $stmt->execute();
        $villa = $stmt->get_result()->fetch_assoc();

        $base_amount = floatval($villa['day_rate']);
        
        $stay_upgrade = ($stay_type === 'Overnight') ? (floatval($villa['overnight_rate']) * $nights) : 0;
        $true_total = ($base_amount * $nights) + $stay_upgrade;

        if ($guests > $villa['base_capacity']) {
            $extra_pax = $guests - $villa['base_capacity'];
            $true_total += ($extra_pax * floatval($villa['extra_pax_rate']) * $nights);
        }
    } 
    elseif ($venue_category === 'Event Hall') {
        $stmt = $conn->prepare("SELECT base_rate, max_capacity FROM event_halls WHERE venue_id = ?");
        $stmt->bind_param("i", $venue_id);
        $stmt->execute();
        $hall = $stmt->get_result()->fetch_assoc();

        $base_amount = floatval($hall['base_rate']);
        $true_total = $base_amount * $nights; 
    }

    $max_capacity = isset($room) ? intval($room['max_capacity']) : (isset($villa) ? intval($villa['max_capacity']) : (isset($hall) ? intval($hall['max_capacity']) : 0));

    return [
        'base_amount' => $base_amount,
        'true_total' => $true_total,
        'max_capacity' => $max_capacity
    ];
}
?>
