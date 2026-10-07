<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/seminar_payments.php';
require_once __DIR__ . '/../includes/sales_report.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Seminar payment contract failed: {$message}\n");
        exit(1);
    }
};
$throws = static function (callable $callable): bool {
    try { $callable(); return false; } catch (InvalidArgumentException) { return true; }
};

$assert(seminar_payment_amount_cents('0.01') === 1
    && seminar_payment_amount_cents('00012.3') === 1230
    && seminar_payment_amount_cents('9999999999.99') === 999999999999,
    'amount parsing uses exact integer cents, including the DECIMAL(12,2) limit');
$assert($throws(static fn() => seminar_payment_amount_cents('0'))
    && $throws(static fn() => seminar_payment_amount_cents('-1'))
    && $throws(static fn() => seminar_payment_amount_cents('1.001'))
    && $throws(static fn() => seminar_payment_amount_cents('1e3'))
    && $throws(static fn() => seminar_payment_amount_cents(1.25)),
    'payment amount rejects zero, negative, excess precision, scientific notation, and floats');
$assert($throws(static fn() => seminar_payment_assert_amount_within_balance(1001, 10000, 9000))
    && !$throws(static fn() => seminar_payment_assert_amount_within_balance(1000, 10000, 9000))
    && $throws(static fn() => seminar_payment_assert_receivable(['status' => 'cancelled', 'agreed_price' => '100.00']))
    && $throws(static fn() => seminar_payment_assert_receivable(['status' => 'draft', 'agreed_price' => null])),
    'balance and cancellation guards reject overpayments and closed or unpriced seminars');
$assert(seminar_payment_cents_from_db(null) === 0
    && seminar_payment_cents_from_db('0001.20') === 120
    && seminar_payment_money(120) === '1.20',
    'database money values and display strings remain exact to the cent');
$seminarTargets = sales_report_allocation_targets([
    'booking_id' => 0,
    'booking_total' => '1250.00',
    'primary_filter_id' => 501,
    'primary_venue' => 'Seminar · Event Hall · Grand Hall',
    'primary_category' => 'Seminar',
], []);
$assert($seminarTargets === [[
    'key' => 'venue:501',
    'label' => 'Seminar · Event Hall · Grand Hall',
    'filter_id' => 501,
    'weight' => 125000,
]], 'seminar receipts are attributed wholly to their hall without room-share allocation');

[$cashReference, $cashFingerprint] = seminar_payment_normalize_reference('', 'Cash');
[$cashNote, $cashNoteFingerprint] = seminar_payment_normalize_reference('Drawer 4', 'Cash');
$assert($cashReference === null && $cashFingerprint === null && $cashNote === 'Drawer 4' && $cashNoteFingerprint === null,
    'cash reference is optional and does not participate in transaction de-duplication');
[$formattedReference, $formattedFingerprint] = seminar_payment_normalize_reference(' ab-cd ', 'GCash');
[$equivalentReference, $equivalentFingerprint] = seminar_payment_normalize_reference('ABCD', 'GCash');
$assert($formattedReference === 'ab-cd' && $equivalentReference === 'ABCD' && $formattedFingerprint === $equivalentFingerprint,
    'non-cash reference fingerprints normalize case and punctuation');
$assert($throws(static fn() => seminar_payment_normalize_reference('', 'Maya'))
    && $throws(static fn() => seminar_payment_normalize_reference('bad!', 'Bank Transfer')),
    'non-cash references are required and restricted to supported characters');

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$migration = $read('migrations/031_seminar_payments.sql');
$endpoint = $read('actions/admin/seminar_payments.php');
$seminarApi = $read('actions/admin/seminars.php');
$seminarJs = $read('assets/js/admin-page/admin_seminars.js');
$seminarPage = $read('includes/admin-page/admin_seminars.php');
$historyApi = $read('actions/admin/get_bookings_page.php');
$historyUi = $read('assets/js/admin-page/admin_bookings.js');
$historyCsv = $read('actions/admin/export_bookings.php');
$sales = $read('includes/sales_report.php');
$salesUi = $read('includes/admin-page/admin_sales.php');
$salesCsv = $read('actions/admin/export_sales_report.php');

$assert(str_contains($migration, 'uq_seminar_payment_reference')
    && str_contains($migration, 'uq_seminar_payment_idempotency')
    && str_contains($migration, 'status ENUM(\'posted\',\'voided\')')
    && str_contains($migration, 'ON DELETE RESTRICT'),
    'migration keeps seminar receipts append-only with unique references and idempotency keys');
$assert(str_contains($endpoint, 'HTTP_X_CSRF_TOKEN')
    && str_contains($endpoint, 'FOR UPDATE')
    && str_contains($endpoint, 'seminar_payment_assert_amount_within_balance($amountCents, $priceCents, $paidCents)')
    && str_contains($endpoint, "operation === 'void'")
    && str_contains($endpoint, "operation === 'record'")
    && str_contains($endpoint, 'reference_fingerprint=NULL'),
    'authorized payment endpoint protects writes, locks seminar balances, and records/voids entries');
$assert(str_contains($seminarApi, 'seminar_payment_assert_price_floor')
    && str_contains($seminarApi, "SUM(p.amount)")
    && str_contains($seminarApi, 'seminar_payment_history'),
    'seminar edits cannot undercut posted payments and seminar reads return the ledger');
$assert(str_contains($seminarJs, 'Payment tracking')
    && str_contains($seminarJs, 'data-payment-void')
    && str_contains($seminarJs, 'requestPayment(\'record\'')
    && str_contains($seminarJs, 'requestPayment(\'void\'')
    && str_contains($seminarJs, "get('seminar_id')")
    && str_contains($seminarPage, 'does not return funds')
    && str_contains($seminarJs, 'Accounting correction'),
    'Seminars exposes payment totals/history, staff recording, corrections, and history deep links');
$assert(str_contains($historyApi, "'seminar'")
    && str_contains($historyApi, 'AS record_type')
    && str_contains($historyApi, 'utf8mb4_unicode_ci')
    && str_contains($historyApi, 'LIMIT ? OFFSET ?')
    && str_contains($historyApi, "case 'partial':")
    && str_contains($historyUi, 'Open seminar')
    && str_contains($historyUi, 'record_type === \'seminar\''),
    'booking history paginates seminars with shared filters and routes back to the seminar plan');
$assert(str_contains($historyCsv, "'Seminar' AS record_type")
    && str_contains($historyCsv, "'Record type'")
    && str_contains($historyCsv, 'seminarWhere'),
    'history CSV exports include clearly labeled seminar records');
$assert(str_contains($sales, "'seminar' AS record_source")
    && str_contains($sales, "'Seminar'")
    && str_contains($sales, 'utf8mb4_unicode_ci')
    && str_contains($sales, "sp.status='posted'")
    && str_contains($sales, 'FROM seminar_payments sp')
    && str_contains($salesUi, 'Seminar received')
    && str_contains($salesCsv, 'Seminar received'),
    'posted seminar receipts are labeled and attributed to their Event Hall in sales views/exports');
$assert(substr_count($sales, "FROM seminar_payments sp") >= 2,
    'monthly received metrics include posted seminar receipts');
$assert(str_contains($sales, 'ORDER BY occurred_at DESC, activity_type ASC, record_source ASC, activity_id DESC'),
    'sales activity pagination has a stable source tie-break for colliding payment IDs and timestamps');

echo "Seminar payment contract checks passed\n";
