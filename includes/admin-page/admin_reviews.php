<?php
if (($_SESSION['role'] ?? '') !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>';
    return;
}
require_once 'config/db_connect.php';
$escapeReview = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$reviewFilter = $_GET['status'] ?? 'All';
if (!in_array($reviewFilter, ['All', 'Pending', 'Approved', 'Rejected'], true)) $reviewFilter = 'All';
$reviews = [];
$reviewSql = "SELECT vr.id, vr.rating, vr.review_text, vr.moderation_status, vr.admin_note, vr.created_at,
                     c.first_name, c.last_name, v.name AS venue_name, v.category, b.reference_no
              FROM venue_reviews vr
              INNER JOIN customers c ON c.id = vr.customer_id
              INNER JOIN venues v ON v.id = vr.venue_id
              INNER JOIN bookings b ON b.id = vr.booking_id";
if ($reviewFilter !== 'All') $reviewSql .= " WHERE vr.moderation_status = ?";
$reviewSql .= ' ORDER BY vr.created_at DESC, vr.id DESC';
$statement = $conn->prepare($reviewSql);
if ($reviewFilter !== 'All') $statement->bind_param('s', $reviewFilter);
if ($statement && $statement->execute()) $reviews = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
if ($statement) $statement->close();
?>
<div class="reviews-container">
    <div class="reviews-header">
        <div><h2>Venue Reviews</h2><p>Moderate customer feedback before it appears in the public venue catalogue.</p></div>
        <label for="review-status-filter">Status
            <select id="review-status-filter">
                <?php foreach (['All', 'Pending', 'Approved', 'Rejected'] as $status): ?>
                    <option value="<?= $escapeReview($status) ?>" <?= $reviewFilter === $status ? 'selected' : '' ?>><?= $escapeReview($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="reviews-list">
    <?php if (!$reviews): ?><p class="reviews-empty">No reviews in this view.</p><?php endif; ?>
    <?php foreach ($reviews as $review): ?>
        <article class="review-admin-row" data-review-id="<?= (int)$review['id'] ?>">
            <div class="review-admin-meta">
                <strong><?= $escapeReview($review['venue_name']) ?></strong>
                <span><?= $escapeReview($review['category']) ?> · <?= $escapeReview($review['reference_no']) ?></span>
                <span>By <?= $escapeReview(trim($review['first_name'] . ' ' . $review['last_name'])) ?> · <?= $escapeReview($review['created_at']) ?></span>
            </div>
            <div class="review-admin-rating" aria-label="<?= (int)$review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$review['rating']) . str_repeat('☆', 5 - (int)$review['rating']) ?></div>
            <p class="review-admin-text"><?= nl2br($escapeReview(strip_tags((string)($review['review_text'] ?? '')))) ?></p>
            <?php if ($review['admin_note']): ?><p class="review-admin-note">Admin note: <?= $escapeReview($review['admin_note']) ?></p><?php endif; ?>
            <div class="review-admin-actions">
                <span class="review-status review-status--<?= strtolower($escapeReview($review['moderation_status'])) ?>"><?= $escapeReview($review['moderation_status']) ?></span>
                <label class="review-note-field">Note <input type="text" maxlength="500" data-review-note aria-label="Optional moderation note" value="<?= $review['admin_note'] ? $escapeReview($review['admin_note']) : '' ?>"></label>
                <?php if ($review['moderation_status'] === 'Pending'): ?>
                    <button type="button" data-review-action="approve" data-review-id="<?= (int)$review['id'] ?>">Approve</button>
                    <button type="button" data-review-action="reject" data-review-id="<?= (int)$review['id'] ?>">Reject</button>
                <?php elseif ($review['moderation_status'] === 'Approved'): ?>
                    <button type="button" data-review-action="hide" data-review-id="<?= (int)$review['id'] ?>">Hide</button>
                <?php else: ?>
                    <button type="button" data-review-action="approve" data-review-id="<?= (int)$review['id'] ?>">Approve</button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</div>
