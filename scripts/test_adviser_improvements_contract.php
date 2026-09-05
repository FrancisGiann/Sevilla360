<?php
/** Static contracts for adviser-improvements security, reviews, beds, and sales reporting. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) exit("Adviser improvements contract failed: {$message}\n");
};

$reviewMigration = $read('migrations/018_venue_reviews.sql');
$reviewSave = $read('actions/user/save_venue_review.php');
$reviewPublic = $read('actions/public/get_venue_reviews.php');
$reviewModerate = $read('actions/admin/moderate_venue_review.php');
$reviewAdminPage = $read('includes/admin-page/admin_reviews.php');
$dashboard = $read('user_dashboard.php');
$dashboardJs = $read('assets/js/user_dashboard.js');
$dashboardCss = $read('assets/css/user_dashboard.css');
$index = $read('index.php');
$indexJs = $read('assets/js/index.js');
$salesEndpoint = $read('actions/admin/get_sales_report.php');
$salesPage = $read('includes/admin-page/admin_sales.php');
$salesJs = $read('assets/js/admin-page/admin_sales.js');
$overview = $read('includes/admin-page/admin_overview.php');
$overviewJs = $read('assets/js/admin-page/admin_overview.js');
$resetMigration = $read('migrations/019_password_reset_security.sql');
$resetHelper = $read('includes/password_reset_security.php');
$forgot = $read('actions/auth/forgot_password_process.php');
$reset = $read('actions/auth/reset_password_process.php');
$auth = $read('auth.php');
$authJs = $read('assets/js/auth.js');

$assert(str_contains($reviewMigration, 'UNIQUE KEY uq_venue_reviews_booking (booking_id)'), 'one review per booking is database-enforced');
$assert(str_contains($reviewMigration, 'rating TINYINT UNSIGNED NOT NULL') && str_contains($reviewMigration, 'review_text VARCHAR(1000)'), 'review fields have bounded schema');
$assert(str_contains($reviewMigration, "moderation_status ENUM('Pending', 'Approved', 'Rejected')"), 'review moderation states are constrained');
$assert(!preg_match('/\bDROP\s+TABLE\b|\bDELETE\s+FROM\b/i', $reviewMigration), 'review migration is non-destructive');
$assert(str_contains($reviewSave, 'booking_completion_sql') && str_contains($reviewSave, 'c.user_id = ?'), 'reviews require canonical completion and ownership');
$assert(str_contains($reviewSave, "v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa')") && str_contains($reviewSave, "b.source <> 'Maintenance'"), 'reviews exclude add-on/maintenance and unsupported bookings');
$assert(str_contains($reviewSave, "moderation_status = 'Pending'") && str_contains($reviewSave, 'admin_note = NULL'), 'customer edits reset moderation data');
$assert(str_contains($reviewPublic, "moderation_status = 'Approved'") && str_contains($reviewPublic, 'LIMIT 3'), 'public API exposes approved latest three reviews only');
$assert(str_contains($reviewPublic, 'first_name') && str_contains($reviewPublic, 'lastName') && str_contains($reviewPublic, 'strip_tags'), 'public reviewer privacy and text sanitization are applied');
$assert(str_contains($reviewModerate, "'approve' => 'Approved'") && str_contains($reviewModerate, "'reject', 'hide' => 'Rejected'") && str_contains($reviewModerate, 'audit_logs'), 'admin moderation is constrained and audited');
$assert(str_contains($reviewAdminPage, "'Pending'") && str_contains($reviewAdminPage, 'data-review-action="reject"') && str_contains($reviewAdminPage, 'data-review-action="hide"'), 'moderation UI exposes pending reject and approved hide actions');
$assert(str_contains($dashboard, 'btn-review-open') && str_contains($dashboard, 'Review pending') && str_contains($dashboard, 'View/edit review'), 'customer dashboard review states are present');
$assert(str_contains($dashboard, '<fieldset class="review-rating-fieldset"') && str_contains($dashboard, 'type="radio"') && str_contains($dashboard, 'name="review_rating"') && !str_contains($dashboard, 'class="review-star"'), 'customer rating uses native radio controls');
$assert(str_contains($dashboardJs, 'review-rating-input') && str_contains($dashboardJs, 'input.checked') && str_contains($dashboardJs, 'reviewRating'), 'customer rating radios preselect and validate through keyboard-friendly inputs');
$assert(str_contains($dashboardCss, '.review-rating-fieldset') && str_contains($dashboardCss, ':focus-within'), 'customer rating controls retain polished focus styling');
$assert(str_contains($index, 'rating_average') && str_contains($index, 'rating_count') && str_contains($index, "booking_status <> 'Cancelled'") && str_contains($index, "'Refunded'") && str_contains($index, 'MIN(h.bed_count)') && str_contains($index, 'MAX(h.bed_count)'), 'catalog includes eligible ratings and hotel bed ranges');
$assert(str_contains($indexJs, 'No ratings yet') && str_contains($indexJs, 'out of 5') && str_contains($indexJs, 'textContent'), 'catalog has textual rating state and safe review rendering');

$assert(str_contains($salesEndpoint, "p.status = 'Success'") && str_contains($salesEndpoint, "b.booking_status <> 'Cancelled'"), 'sales sums successful non-cancelled payments');
$assert(str_contains($salesEndpoint, "'Asia/Manila'") && str_contains($salesEndpoint, 'daysRequested > 366') && str_contains($salesEndpoint, "\\A\\d{4}-\\d{2}-\\d{2}\\z"), 'sales validates Manila dates and maximum range');
$assert(str_contains($salesEndpoint, "'payment_count'") && str_contains($salesEndpoint, "'average'") && str_contains($salesEndpoint, "'days'"), 'sales response includes totals and daily series');
$assert(str_contains($salesPage, 'Recorded successful payments') && str_contains($salesPage, 'not accounting or net revenue') && str_contains($salesPage, 'Last 7 Days'), 'sales page explains metric and presets');
$assert(str_contains($salesJs, 'Asia/Manila') && str_contains($salesJs, 'const now = manilaToday()') && !str_contains($salesJs, 'const now = new Date()') && str_contains($salesJs, 'sales-empty'), 'sales client uses Manila calendar for every preset and empty state');
$assert(str_contains($overview, 'Monthly Sales') && !str_contains($overview, 'Revenue Trend'), 'overview uses Monthly Sales without revenue trend');
$assert(str_contains($overviewJs, 'monthlySales') && !str_contains($overviewJs, 'revenueTrend'), 'overview client uses monthlySales only');

$assert(str_contains($resetMigration, 'reset_token_hash CHAR(64)') && str_contains($resetMigration, 'idx_users_reset_token_hash'), 'password reset hash migration is present');
$assert(!preg_match('/\bDROP\s+COLUMN\b|\bDELETE\s+FROM\b/i', $resetMigration), 'password reset migration is non-destructive');
$assert(str_contains($resetHelper, 'APP_BASE_URL') && str_contains($resetHelper, 'password_reset_base_url') && str_contains($resetHelper, 'https'), 'base URL validation is centralized');
$assert(str_contains($forgot, "hash('sha256'") && str_contains($forgot, 'reset_token_hash') && str_contains($forgot, 'error_log'), 'forgot password stores only a hash and logs configuration failures');
$assert(str_contains($reset, "hash('sha256'") && str_contains($reset, 'reset_token_hash'), 'reset consumes hashed single-use tokens');
$assert(str_contains($auth, 'data-origin="admin"') && str_contains($auth, 'data-origin="customer"'), 'both portals expose origin-preserving forgot links');
$assert(str_contains($authJs, "if (forgotOrigin === 'admin') switchView(viewAdmin)"), 'admin-origin auth pages open the admin login view');

echo "Adviser improvements contract checks passed\n";
