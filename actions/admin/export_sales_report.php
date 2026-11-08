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
        sales_report_csv_line($output, ['Date', 'Type', 'Payment / refund method', 'Customer / record', 'Booking / seminar', 'Venue', 'Reference', 'Gross received (PHP)', 'Customer refund (PHP)', 'PayMongo fee (PHP)', 'Net movement after fees/refunds (PHP)', 'Fee status']);
        $report = sales_report_build($conn, $filters, 1, 50, static function (array $transaction) use ($output): void {
            $received = $transaction['gross_received_cents'] / 100;
            $refunded = $transaction['customer_refund_cents'] / 100;
            $fee = $transaction['provider_fee_cents'];
            $net = $transaction['net_after_fees_refunds_cents'];
            sales_report_csv_line($output, [
                $transaction['date'],
                $transaction['source'] === 'seminar'
                    ? ($transaction['type'] === 'received' ? 'Seminar received' : 'Seminar refunded')
                    : ($transaction['type'] === 'received' ? 'Received' : 'Refunded'),
                $transaction['method'],
                $transaction['customer_name'],
                $transaction['booking_reference'],
                $transaction['venue'],
                $transaction['reference'],
                number_format($received, 2, '.', ''),
                number_format($refunded, 2, '.', ''),
                $fee === null ? '' : number_format($fee / 100, 2, '.', ''),
                $net === null ? '' : number_format($net / 100, 2, '.', ''),
                $transaction['fee_status'],
            ], [7, 8, 9, 10]);
        });
        sales_report_csv_line($output, [
            'PERIOD TOTAL', '', '', '', '', '', '',
            number_format($report['received_cents'] / 100, 2, '.', ''),
            number_format($report['refunded_cents'] / 100, 2, '.', ''),
            number_format($report['provider_fee_cents'] / 100, 2, '.', ''),
            $report['net_cents'] === null ? '' : number_format($report['net_cents'] / 100, 2, '.', ''),
            $report['net_cents'] === null ? 'unknown; see report notes' : 'complete',
        ], [7, 8, 9, 10]);
        $filename = 'sevilla360-sales-transactions-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
    } else {
        $report = sales_report_build($conn, $filters);
        sales_report_csv_line($output, ['Venue group', 'Gross received (PHP)', 'Customer refunds (PHP)', 'PayMongo fees (PHP)', 'Net after fees/refunds (PHP)', 'Fee status']);
        foreach ($report['venue_totals'] as $venueTotal) {
            sales_report_csv_line($output, [
                $venueTotal['label'],
                number_format($venueTotal['received_cents'] / 100, 2, '.', ''),
                number_format($venueTotal['refunded_cents'] / 100, 2, '.', ''),
                number_format($venueTotal['provider_fee_cents'] / 100, 2, '.', ''),
                $venueTotal['net_cents'] === null ? '' : number_format($venueTotal['net_cents'] / 100, 2, '.', ''),
                $venueTotal['unknown_fee_rows'] > 0 ? 'unknown fees' : ($venueTotal['net_cents'] === null ? 'unknown net' : 'complete'),
            ], [1, 2, 3, 4]);
        }
        sales_report_csv_line($output, [
            'PERIOD TOTAL',
            number_format($report['received_cents'] / 100, 2, '.', ''),
            number_format($report['refunded_cents'] / 100, 2, '.', ''),
            number_format($report['provider_fee_cents'] / 100, 2, '.', ''),
            $report['net_cents'] === null ? '' : number_format($report['net_cents'] / 100, 2, '.', ''),
            $report['net_cents'] === null ? 'unknown; see report notes' : 'complete',
        ], [1, 2, 3, 4]);
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
