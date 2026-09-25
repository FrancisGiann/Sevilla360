<?php
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<div class="unauthorized-access"><i class="fa-solid fa-lock" aria-hidden="true"></i><h3>Unauthorized Access</h3></div>';
    return;
}

require_once __DIR__ . '/../../includes/sales_report.php';
try {
    if (!sales_report_is_active_admin($conn, (int)($_SESSION['user_id'] ?? 0))) {
        http_response_code(403);
        echo '<div class="unauthorized-access"><i class="fa-solid fa-lock" aria-hidden="true"></i><h3>Unauthorized Access</h3></div>';
        return;
    }
} catch (Throwable $salesAccessError) {
    http_response_code(503);
    echo '<div class="unauthorized-access"><h3>Sales report is temporarily unavailable.</h3></div>';
    return;
}
$salesEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$salesFilters = sales_report_normalize_filters($_GET);
$salesFilterError = $salesFilters['error'];
$salesRequestedPage = filter_var($_GET['sales_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
$salesPage = $salesRequestedPage === false ? 1 : (int)$salesRequestedPage;
$salesVenues = [];
$salesReport = [];
$salesMethods = [];
$salesReportFailed = false;
try {
    $salesVenues = sales_report_venue_options($conn);
    if ($salesFilters['venue_id'] > 0) {
        $resolvedVenueId = sales_report_resolve_venue_filter($conn, $salesFilters['venue_id'], $salesVenues);
        if ($resolvedVenueId === null) {
            $salesFilters['venue_id'] = 0;
            $salesFilterError = 'That venue group is no longer available. Showing all venues.';
        } else {
            $salesFilters['venue_id'] = $resolvedVenueId;
        }
    }
    $salesReport = sales_report_build($conn, $salesFilters, $salesPage, 50);
    if ($salesPage > $salesReport['page_count']) {
        $salesPage = $salesReport['page_count'];
        $salesReport = sales_report_build($conn, $salesFilters, $salesPage, 50);
    }
    $salesMethods = $salesReport['available_methods'];
    if ($salesFilters['method'] !== '' && !in_array($salesFilters['method'], $salesMethods, true)) {
        $salesMethods[] = $salesFilters['method'];
        sort($salesMethods, SORT_NATURAL | SORT_FLAG_CASE);
    }
} catch (Throwable $salesLoadError) {
    // Keep infrastructure details out of the admin page; the export endpoint
    // retains its own explicit 500 response for the same failure.
    $salesReportFailed = true;
}
$salesQuery = [
    'from' => $salesFilters['from'],
    'to' => $salesFilters['to'],
    'method' => $salesFilters['method'],
    'venue_id' => $salesFilters['venue_id'],
];
$salesRetryQuery = ['page' => 'sales'] + $salesQuery + ['sales_page' => $salesPage];
$salesExportLink = static fn(string $type): string => 'actions/admin/export_sales_report.php?' . http_build_query($salesQuery + ['type' => $type]);
$salesPaginationBase = ['page' => 'sales'] + $salesQuery;
$salesCurrency = static fn(int $cents): string => '₱' . number_format($cents / 100, 2);
$salesNetClass = static fn(int $cents): string => $cents < 0 ? 'is-negative' : ($cents > 0 ? 'is-positive' : '');
?>
<div class="sales-report-container">
    <div class="sales-report-heading">
        <div class="sales-report-heading-copy">
            <p>Recorded successful payments and processed refunds for the selected dates.</p>
        </div>
        <?php if (!$salesReportFailed): ?><div class="sales-report-exports" aria-label="Export report">
            <a class="sales-button sales-button-secondary" href="<?= $salesEscape($salesExportLink('transactions')) ?>">
                <i class="fa-solid fa-file-csv" aria-hidden="true"></i> Transaction CSV
            </a>
            <a class="sales-button sales-button-secondary" href="<?= $salesEscape($salesExportLink('venues')) ?>">
                <i class="fa-solid fa-file-csv" aria-hidden="true"></i> Venue CSV
            </a>
        </div><?php endif; ?>
    </div>

    <?php if ($salesReportFailed): ?>
        <section class="sales-unavailable" role="alert" aria-labelledby="sales-unavailable-title">
            <h3 id="sales-unavailable-title">Sales data is temporarily unavailable.</h3>
            <p>Your selected dates and filters are saved. Please retry in a moment.</p>
            <a class="sales-button sales-button-primary" href="admin_dashboard.php?<?= $salesEscape(http_build_query($salesRetryQuery)) ?>">Retry report</a>
        </section>
    <?php else: ?>
    <?php if ($salesFilterError !== ''): ?>
        <p class="sales-filter-message" role="status"><?= $salesEscape($salesFilterError) ?></p>
    <?php endif; ?>

    <form class="sales-filter-form" id="sales-filter-form" method="get" action="admin_dashboard.php" aria-label="Sales report filters">
        <input type="hidden" name="page" value="sales">
        <div class="sales-filter-field">
            <label for="sales-from">From</label>
            <input type="date" id="sales-from" name="from" value="<?= $salesEscape($salesFilters['from']) ?>" required>
        </div>
        <div class="sales-filter-field">
            <label for="sales-to">Through</label>
            <input type="date" id="sales-to" name="to" value="<?= $salesEscape($salesFilters['to']) ?>" required>
        </div>
        <div class="sales-filter-field">
            <label for="sales-method">Payment / refund method</label>
            <select id="sales-method" name="method">
                <option value="">All methods</option>
                <?php foreach ($salesMethods as $salesMethod): ?>
                    <option value="<?= $salesEscape($salesMethod) ?>" <?= strcasecmp($salesFilters['method'], $salesMethod) === 0 ? 'selected' : '' ?>><?= $salesEscape($salesMethod) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sales-filter-field">
            <label for="sales-venue">Venue group</label>
            <select id="sales-venue" name="venue_id">
                <option value="0">All venues</option>
                <?php foreach ($salesVenues as $salesVenue): ?>
                    <option value="<?= (int)$salesVenue['id'] ?>" <?= $salesFilters['venue_id'] === (int)$salesVenue['id'] ? 'selected' : '' ?>><?= $salesEscape($salesVenue['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sales-filter-actions">
            <button class="sales-button sales-button-primary" id="sales-apply-filters" type="submit">Apply filters</button>
            <a class="sales-reset-link" href="admin_dashboard.php?page=sales">Reset</a>
        </div>
    </form>

    <div class="sales-summary-grid" aria-label="Sales totals">
        <article class="sales-summary-item sales-summary-received">
            <h3>Received</h3>
            <p><?= $salesCurrency($salesReport['received_cents']) ?></p>
        </article>
        <article class="sales-summary-item sales-summary-refunded">
            <h3>Refunded</h3>
            <p><?= $salesCurrency($salesReport['refunded_cents']) ?></p>
        </article>
        <article class="sales-summary-item sales-summary-net">
            <h3>Net</h3>
            <p class="<?= $salesNetClass($salesReport['net_cents']) ?>"><?= $salesCurrency($salesReport['net_cents']) ?></p>
        </article>
    </div>

    <p class="sales-report-note">Venue shares estimate each payment and refund from the booking’s current pricing and venue assignments. Hotel rooms are grouped by building. Legacy refunds use the date recorded in cancellation history; refunds without a recorded destination method are excluded from totals.</p>

    <div class="sales-breakdown-grid">
        <section class="sales-section" aria-labelledby="sales-methods-title">
            <div class="sales-section-heading"><h3 id="sales-methods-title">Method breakdown</h3></div>
            <div class="sales-table-scroll">
                <table class="sales-table sales-compact-table">
                    <thead><tr><th scope="col">Method</th><th scope="col">Received</th><th scope="col">Refunded</th><th scope="col">Net</th></tr></thead>
                    <tbody>
                    <?php if (!$salesReport['method_totals']): ?>
                        <tr><td class="sales-empty-cell" colspan="4">No recorded activity for these filters.</td></tr>
                    <?php else: foreach ($salesReport['method_totals'] as $methodTotal): ?>
                        <tr>
                            <th scope="row" data-label="Method"><?= $salesEscape($methodTotal['method']) ?></th>
                            <td data-label="Received"><?= $salesCurrency($methodTotal['received_cents']) ?></td>
                            <td data-label="Refunded"><?= $salesCurrency($methodTotal['refunded_cents']) ?></td>
                            <td data-label="Net" class="<?= $salesNetClass($methodTotal['net_cents']) ?>"><?= $salesCurrency($methodTotal['net_cents']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sales-section" aria-labelledby="sales-venues-title">
            <div class="sales-section-heading"><h3 id="sales-venues-title">Revenue by venue</h3></div>
            <div class="sales-table-scroll">
                <table class="sales-table sales-compact-table">
                    <thead><tr><th scope="col">Venue group</th><th scope="col">Received</th><th scope="col">Refunded</th><th scope="col">Net</th></tr></thead>
                    <tbody>
                    <?php if (!$salesReport['venue_totals']): ?>
                        <tr><td class="sales-empty-cell" colspan="4">No venue revenue for these filters.</td></tr>
                    <?php else: foreach ($salesReport['venue_totals'] as $venueTotal): ?>
                        <tr>
                            <th scope="row" data-label="Venue group"><?= $salesEscape($venueTotal['label']) ?></th>
                            <td data-label="Received"><?= $salesCurrency($venueTotal['received_cents']) ?></td>
                            <td data-label="Refunded"><?= $salesCurrency($venueTotal['refunded_cents']) ?></td>
                            <td data-label="Net" class="<?= $salesNetClass($venueTotal['net_cents']) ?>"><?= $salesCurrency($venueTotal['net_cents']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="sales-section sales-ledger-section" aria-labelledby="sales-ledger-title">
        <div class="sales-section-heading sales-ledger-heading">
            <div><h3 id="sales-ledger-title">Transaction ledger</h3><p><?= number_format($salesReport['total_rows']) ?> transactions</p></div>
            <a class="sales-ledger-export" href="<?= $salesEscape($salesExportLink('transactions')) ?>">Download filtered transactions</a>
        </div>
        <div class="sales-table-scroll">
            <table class="sales-table sales-ledger-table">
                <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Method</th><th scope="col">Customer</th><th scope="col">Booking</th><th scope="col">Venue</th><th scope="col">Reference</th><th scope="col" class="sales-amount-cell">Amount</th></tr></thead>
                <tbody>
                <?php if (!$salesReport['transactions']): ?>
                    <tr><td class="sales-empty-cell" colspan="8">No transactions match this report. Try a wider date range or remove a filter.</td></tr>
                <?php else: foreach ($salesReport['transactions'] as $transaction): ?>
                    <tr>
                        <td data-label="Date"><?= $salesEscape(date('M j, Y · g:i A', strtotime($transaction['date']))) ?></td>
                        <td data-label="Type"><span class="sales-type-badge sales-type-<?= $salesEscape($transaction['type']) ?>"><?= $transaction['type'] === 'received' ? 'Received' : 'Refunded' ?></span></td>
                        <td data-label="Method"><?= $salesEscape($transaction['method']) ?></td>
                        <td data-label="Customer"><?= $salesEscape($transaction['customer_name']) ?></td>
                        <td data-label="Booking"><?= $salesEscape($transaction['booking_reference']) ?></td>
                        <td data-label="Venue"><?= $salesEscape($transaction['venue']) ?></td>
                        <td data-label="Reference"><?= $salesEscape($transaction['reference'] !== '' ? $transaction['reference'] : '—') ?></td>
                        <td data-label="Amount" class="sales-amount-cell <?= $transaction['type'] === 'received' ? 'is-positive' : 'is-negative' ?>"><?= $transaction['type'] === 'received' ? '+' : '−' ?><?= $salesCurrency($transaction['amount_cents']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($salesReport['page_count'] > 1): ?>
            <nav class="sales-pagination" aria-label="Transaction pages">
                <span>Page <?= (int)$salesPage ?> of <?= (int)$salesReport['page_count'] ?></span>
                <div>
                    <?php if ($salesPage > 1): ?>
                        <a href="admin_dashboard.php?<?= $salesEscape(http_build_query($salesPaginationBase + ['sales_page' => $salesPage - 1])) ?>" rel="prev">Previous</a>
                    <?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                    <?php if ($salesPage < $salesReport['page_count']): ?>
                        <a href="admin_dashboard.php?<?= $salesEscape(http_build_query($salesPaginationBase + ['sales_page' => $salesPage + 1])) ?>" rel="next">Next</a>
                    <?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
