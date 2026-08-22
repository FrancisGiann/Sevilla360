<?php
/**
 * Generate a short, human-friendly booking reference.
 * Existing references are left untouched; this applies to new bookings only.
 */
function generate_booking_reference(mysqli $conn): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $reference = '';
        for ($i = 0; $i < 6; $i++) {
            $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $check = $conn->prepare('SELECT id FROM bookings WHERE reference_no = ? LIMIT 1');
        if (!$check) {
            throw new RuntimeException('Unable to validate booking reference.');
        }
        $check->bind_param('s', $reference);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            return $reference;
        }
    }

    throw new RuntimeException('Unable to generate a unique booking reference. Please try again.');
}
