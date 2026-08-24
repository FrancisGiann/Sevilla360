<?php
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/receipt_itemization.php';

function receipt_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receipt_money(mixed $value): string
{
    return '₱' . number_format((float)$value, 2);
}

try {
    $booking_id = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    $session_user_id = (int)($_SESSION['user_id'] ?? 0);
    $session_role = (string)($_SESSION['role'] ?? '');
    if (empty($_SESSION['logged_in']) || $session_user_id < 1 || $booking_id === false || $booking_id === null) {
        throw new RuntimeException('Receipt unavailable.');
    }

    $stmt = $conn->prepare(
        "SELECT b.*, c.first_name, c.last_name, c.email, COALESCE(b.contact_phone, c.phone) AS phone,
                c.user_id AS owner_id, v.name AS venue_name, v.category AS venue_category
         FROM bookings b
         INNER JOIN customers c ON c.id = b.customer_id
         INNER JOIN venues v ON v.id = b.venue_id
         WHERE b.id = ?
         LIMIT 1"
    );
    if (!$stmt) throw new RuntimeException('Unable to load receipt.');
    $stmt->bind_param('i', $booking_id);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load receipt.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Receipt unavailable.');

    $is_staff = in_array($session_role, ['admin', 'staff'], true);
    $is_owner = (int)($booking['owner_id'] ?? 0) === $session_user_id;
    if (!$is_staff && !$is_owner) throw new RuntimeException('Receipt unavailable.');

    $booking_status = (string)($booking['booking_status'] ?? '');
    $payment_scheme = (string)($booking['payment_scheme'] ?? '');
    if ($booking_status === 'Pending' || $booking_status === 'Cancelled' || $payment_scheme === 'To Be Arranged') {
        throw new RuntimeException('Receipt unavailable for this booking.');
    }

    if ($booking['venue_category'] === 'Hotel Room') {
        $room_stmt = $conn->prepare('SELECT room_type, room_number FROM hotel_rooms WHERE venue_id = ? ORDER BY venue_id ASC LIMIT 1');
        if (!$room_stmt) throw new RuntimeException('Unable to load receipt.');
        $venue_id = (int)$booking['venue_id'];
        $room_stmt->bind_param('i', $venue_id);
        if (!$room_stmt->execute()) throw new RuntimeException('Unable to load receipt.');
        $room = $room_stmt->get_result()->fetch_assoc();
        if ($room) {
            $room_suffix = trim((string)($room['room_type'] ?? ''));
            if ((string)($room['room_number'] ?? '') !== '') $room_suffix .= ' - Room ' . $room['room_number'];
            if ($room_suffix !== '') $booking['venue_name'] .= ' - ' . $room_suffix;
        }
    }

    $biz = [
        'biz_name' => 'Sevilla360',
        'biz_tagline' => 'LUXURY RESORT & EVENTS',
        'biz_policies' => '',
        'biz_email' => '',
        'biz_phone' => '',
        'biz_address' => '',
    ];
    $biz_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'biz_%'");
    if ($biz_result) {
        while ($row = $biz_result->fetch_assoc()) {
            if (array_key_exists($row['setting_key'], $biz)) $biz[$row['setting_key']] = (string)$row['setting_value'];
        }
    }

    $stmt_li = $conn->prepare('SELECT item_name, amount FROM booking_line_items WHERE booking_id = ? ORDER BY id ASC');
    if (!$stmt_li) throw new RuntimeException('Unable to load receipt.');
    $stmt_li->bind_param('i', $booking_id);
    if (!$stmt_li->execute()) throw new RuntimeException('Unable to load receipt.');
    $line_items = $stmt_li->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt_ra = $conn->prepare(
        'SELECT v.name AS building_name, h.room_type, h.room_number, br.nights, br.line_total
         FROM booking_rooms br
         INNER JOIN venues v ON v.id = br.venue_id
         INNER JOIN hotel_rooms h ON h.venue_id = br.venue_id
         WHERE br.booking_id = ? ORDER BY br.id ASC'
    );
    if (!$stmt_ra) throw new RuntimeException('Unable to load receipt.');
    $stmt_ra->bind_param('i', $booking_id);
    if (!$stmt_ra->execute()) throw new RuntimeException('Unable to load receipt.');
    $room_allocations = $stmt_ra->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt_pay = $conn->prepare(
        "SELECT transaction_id, payment_method, amount, payment_date
         FROM payments
         WHERE booking_id = ? AND status = 'Success'
         ORDER BY payment_date ASC, id ASC"
    );
    if (!$stmt_pay) throw new RuntimeException('Unable to load receipt.');
    $stmt_pay->bind_param('i', $booking_id);
    if (!$stmt_pay->execute()) throw new RuntimeException('Unable to load receipt.');
    $payments = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);

    $ref_no = (string)$booking['reference_no'];
    $customer_name = trim((string)$booking['first_name'] . ' ' . (string)$booking['last_name']);
    $check_in = date('F j, Y', strtotime((string)$booking['start_date']));
    $check_out = date('F j, Y', strtotime((string)$booking['end_date']));
    $date_str = $check_in === $check_out ? $check_in : $check_in . ' - ' . $check_out;
    $base_amt = (float)$booking['base_amount'];
    $addons_amt = (float)$booking['addons_amount'];
    $extra_pax = (float)$booking['extra_pax_amount'];
    $total_amt = (float)$booking['total_amount'];
    $paid_amt = (float)$booking['amount_paid'];
    $balance = max(0, $total_amt - $paid_amt);
    $payment_status = (string)($booking['payment_status'] ?? '');
    $itemization = receipt_itemization_plan($line_items, $room_allocations);

    $item_rows = '<tr><td><strong>' . receipt_escape($booking['venue_name'] . ' (' . $booking['venue_category'] . ')') . '</strong><br><small>Dates: ' . receipt_escape($date_str) . ' | Guests: ' . (int)$booking['guests_count'] . '</small></td><td class="amount">' . receipt_money($base_amt) . '</td></tr>';
    if ($extra_pax > 0) {
        $item_rows .= '<tr><td><strong>Extra Pax Charge</strong></td><td class="amount">' . receipt_money($extra_pax) . '</td></tr>';
    }
    foreach ($itemization['line_items'] as $item) {
        $item_rows .= '<tr><td>' . receipt_escape($item['item_name']) . '</td><td class="amount">' . receipt_money($item['amount']) . '</td></tr>';
    }
    foreach ($room_allocations as $room) {
        $room_label = trim((string)$room['building_name'] . ' - ' . (string)$room['room_type']);
        $room_number = (string)($room['room_number'] ?? 'TBA');
        $allocation_amount = $itemization['allocations_are_priced'] ? receipt_money($room['line_total']) : 'Included';
        $allocation_note = $itemization['allocations_are_priced'] ? '' : 'Informational allocation detail; included in the aggregate room charge.';
        $item_rows .= '<tr><td><strong>Allocated room: ' . receipt_escape($room_label) . '</strong><br><small>Room ' . receipt_escape($room_number) . ' | ' . (int)$room['nights'] . ' night(s)' . ($allocation_note !== '' ? ' | ' . receipt_escape($allocation_note) : '') . '</small></td><td class="amount">' . $allocation_amount . '</td></tr>';
    }

    $payment_rows = '';
    foreach ($payments as $payment) {
        $payment_date = date('M j, Y', strtotime((string)$payment['payment_date']));
        $payment_rows .= '<tr><td>Payment on ' . receipt_escape($payment_date) . ' (' . receipt_escape($payment['payment_method']) . ')<br><small>Transaction ID: ' . receipt_escape($payment['transaction_id']) . '</small></td><td class="amount paid">- ' . receipt_money($payment['amount']) . '</td></tr>';
    }
    if ($payment_rows === '') $payment_rows = '<tr><td>Successful payments recorded</td><td class="amount paid">- ' . receipt_money(0) . '</td></tr>';

    $safe_reference = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ref_no) ?: 'booking';
    $policy_html = nl2br(receipt_escape($biz['biz_policies']));
    $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>Receipt ' . receipt_escape($ref_no) . '</title><style>
        @page { margin: 34px 38px; }
        body { font-family: DejaVu Sans, sans-serif; color: #2a2522; font-size: 10px; line-height: 1.45; }
        h1,h2,h3,p { margin: 0; } h1 { color: #b5884e; font-size: 22px; } h2 { font-size: 17px; letter-spacing: 1px; } h3 { color: #6b625c; font-size: 9px; letter-spacing: .8px; text-transform: uppercase; margin-bottom: 5px; }
        .header { border-bottom: 2px solid #d6a870; padding-bottom: 15px; margin-bottom: 20px; }
        .header-table, .info-table, .footer-table { width: 100%; border-collapse: collapse; } .header-table td:last-child, .info-table td:last-child, .footer-table td:last-child { text-align: right; }
        .tagline { color: #6b625c; font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; } .muted { color: #6b625c; }
        .info-table { margin-bottom: 20px; } .info-table td { width: 50%; vertical-align: top; padding: 4px 0; }
        .label { color: #6b625c; } .value { font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; } .items th { background: #f4f1ed; color: #6b625c; font-size: 8px; letter-spacing: .6px; text-transform: uppercase; text-align: left; padding: 8px; border-bottom: 1px solid #d9d2ca; } .items td { padding: 8px; border-bottom: 1px solid #e5dfd8; vertical-align: top; } .amount { text-align: right; white-space: nowrap; } small { color: #6b625c; font-size: 8px; }
        .totals { width: 48%; margin-left: auto; border-collapse: collapse; margin-bottom: 22px; } .totals td { padding: 5px 0; border-bottom: 1px solid #e5dfd8; } .totals tr.total td { border-bottom: 2px solid #2a2522; font-size: 13px; font-weight: bold; padding-top: 9px; } .totals tr.balance td { color: #a23d32; font-weight: bold; border-bottom: 0; } .paid { color: #1e7040; }
        .footer { border-top: 1px solid #d9d2ca; padding-top: 12px; color: #6b625c; font-size: 8px; } .footer-table td { width: 50%; vertical-align: top; } .verification { margin-top: 13px; padding: 8px; background: #faf7f2; border-left: 3px solid #d6a870; }
    </style></head><body>
        <div class="header"><table class="header-table"><tr><td><h1>' . receipt_escape($biz['biz_name']) . '</h1><div class="tagline">' . receipt_escape($biz['biz_tagline']) . '</div></td><td><h2>RECEIPT</h2><div class="muted">#' . receipt_escape($ref_no) . '</div></td></tr></table></div>
        <table class="info-table"><tr><td><h3>Billed to</h3><div class="value">' . receipt_escape($customer_name) . '</div><div>' . receipt_escape($booking['email']) . '</div><div>' . receipt_escape($booking['phone'] ?? '') . '</div></td><td><h3>Booking details</h3><div><span class="label">Issued:</span> ' . date('F j, Y') . '</div><div><span class="label">Booking status:</span> <span class="value">' . receipt_escape($booking_status) . '</span></div><div><span class="label">Payment status:</span> <span class="value">' . receipt_escape($payment_status) . '</span></div><div><span class="label">Payment scheme:</span> ' . receipt_escape($payment_scheme) . '</div></td></tr></table>
        <table class="items"><thead><tr><th>Description</th><th class="amount">Amount</th></tr></thead><tbody>' . $item_rows . '</tbody></table>
        <table class="totals"><tr><td>Base and extra charges</td><td class="amount">' . receipt_money($base_amt + $extra_pax) . '</td></tr><tr><td>Add-ons and extras</td><td class="amount">' . receipt_money($addons_amt) . '</td></tr><tr class="total"><td>Total amount</td><td class="amount">' . receipt_money($total_amt) . '</td></tr>' . $payment_rows . '<tr class="balance"><td>Balance due</td><td class="amount">' . receipt_money($balance) . '</td></tr></table>
        <div class="footer"><table class="footer-table"><tr><td><strong>Terms &amp; policies</strong><br>' . $policy_html . '</td><td><strong>' . receipt_escape($biz['biz_name']) . '</strong><br>' . receipt_escape($biz['biz_address']) . '<br>' . receipt_escape($biz['biz_phone']) . '<br>' . receipt_escape($biz['biz_email']) . '</td></tr></table><div class="verification"><strong>Verification note:</strong> This PDF was generated from authoritative Sevilla360 booking and successful-payment records at the time of printing. Verify the booking reference <strong>' . receipt_escape($ref_no) . '</strong> against server records for any correction or payment question.</div></div>
    </body></html>';

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Receipt service unavailable.');
    require_once $autoload;
    $options = new Dompdf\Options();
    $options->setDefaultFont('DejaVu Sans');
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setIsHtml5ParserEnabled(true);
    $options->setChroot(__DIR__);
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Sevilla360-Receipt-' . $safe_reference . '.pdf', ['Attachment' => false]);
    exit;
} catch (Throwable $error) {
    error_log('Receipt PDF generation failed: ' . get_class($error));
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Receipt unavailable.';
    exit;
}
