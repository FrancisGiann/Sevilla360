<?php
require_once __DIR__ . '/../../includes/session_init.php';
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'admin' || (int)($_SESSION['user_id'] ?? 0) < 1) {
    http_response_code(403);
    exit('Unauthorized access.');
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/sales_report.php';
try {
    $activeAdmin = sales_report_is_active_admin($conn, (int)$_SESSION['user_id']);
} catch (Throwable $accessError) {
    http_response_code(500);
    exit('Unable to verify admin access.');
}
if (!$activeAdmin) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$exportType = $_GET['type'] ?? '';
if (!is_string($exportType) || !in_array($exportType, ['transactions', 'venues', 'rooms'], true)) {
    http_response_code(400);
    exit('Choose a valid sales report export.');
}
if ($exportType === 'rooms') $exportType = 'venues';
$filters = sales_report_normalize_filters($_GET);
if ($filters['error'] !== '') {
    http_response_code(422);
    exit('Enter valid inclusive start and end dates.');
}
try {
    if ($filters['venue_id'] > 0) {
        $venues = sales_report_venue_options($conn);
        $resolvedVenueId = sales_report_resolve_venue_filter($conn, $filters['venue_id'], $venues);
        if ($resolvedVenueId === null) {
            http_response_code(422);
            exit('Choose an available venue group.');
        }
        $filters['venue_id'] = $resolvedVenueId;
    }

    $output = fopen('php://temp/maxmemory:2097152', 'w+b');
    if ($output === false) throw new RuntimeException('Unable to create the export.');

    if ($exportType === 'transactions') {
        sales_report_csv_line($output, ['Date', 'Type', 'Payment / refund method', 'Customer', 'Booking', 'Venue', 'Reference', 'Received (PHP)', 'Refunded (PHP)', 'Net (PHP)']);
        sales_report_build($conn, $filters, 1, 50, static function (array $transaction) use ($output): void {
            $received = $transaction['type'] === 'received' ? $transaction['amount_cents'] / 100 : 0;
            $refunded = $transaction['type'] === 'refunded' ? $transaction['amount_cents'] / 100 : 0;
            $net = $received - $refunded;
            sales_report_csv_line($output, [
                $transaction['date'],
                $transaction['type'] === 'received' ? 'Received' : 'Refunded',
                $transaction['method'],
                $transaction['customer_name'],
                $transaction['booking_reference'],
                $transaction['venue'],
                $transaction['reference'],
                number_format($received, 2, '.', ''),
                number_format($refunded, 2, '.', ''),
                number_format($net, 2, '.', ''),
            ], [7, 8, 9]);
        });
        $filename = 'sevilla360-sales-transactions-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
    } else {
        $report = sales_report_build($conn, $filters);
        sales_report_csv_line($output, ['Venue group', 'Received (PHP)', 'Refunded (PHP)', 'Net (PHP)']);
        foreach ($report['venue_totals'] as $venueTotal) {
            sales_report_csv_line($output, [
                $venueTotal['label'],
                number_format($venueTotal['received_cents'] / 100, 2, '.', ''),
                number_format($venueTotal['refunded_cents'] / 100, 2, '.', ''),
                number_format($venueTotal['net_cents'] / 100, 2, '.', ''),
            ], [1, 2, 3]);
        }
        $filename = 'sevilla360-sales-venues-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
    }

    rewind($output);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    fpassthru($output);
    fclose($output);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Unable to generate the sales report export.');
}
