document.addEventListener('DOMContentLoaded', () => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const reviewList = document.querySelector('.reviews-list');
  const filter = document.getElementById('review-status-filter');

  filter?.addEventListener('change', (event) => {
    const status = event.target.value;
    window.location.href = `admin_dashboard.php?page=reviews&status=${encodeURIComponent(status)}`;
  });

  if (!reviewList) return;

  const ensureFeedbackSlot = (row) => {
    let feedback = row.querySelector('.review-admin-feedback');
    if (!feedback) {
      feedback = document.createElement('p');
      feedback.className = 'review-admin-feedback';
      feedback.setAttribute('role', 'status');
      feedback.setAttribute('aria-atomic', 'true');
      feedback.dataset.error = 'false';
      row.append(feedback);
    }
    return feedback;
  };

  reviewList.querySelectorAll('.review-admin-row').forEach(ensureFeedbackSlot);

  const showMessage = (row, message, isError = false) => {
    const feedback = ensureFeedbackSlot(row);
    feedback.dataset.error = isError ? 'true' : 'false';
    feedback.textContent = message;
  };

  const updateReviewState = (row, status) => {
    const badge = row.querySelector('.review-status');
    const buttons = row.querySelector('.review-action-buttons');
    if (!badge || !buttons || !['Pending', 'Approved', 'Rejected'].includes(status)) return false;

    const normalizedStatus = status.toLowerCase();
    badge.className = `review-status review-status--${normalizedStatus}`;
    badge.textContent = status;
    row.dataset.reviewStatus = status;
    buttons.replaceChildren();

    const actions = status === 'Pending'
      ? [['approve', 'Approve'], ['reject', 'Reject']]
      : status === 'Approved'
        ? [['hide', 'Hide']]
        : [['approve', 'Approve']];
    const reviewId = row.dataset.reviewId;
    actions.forEach(([action, label]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.reviewAction = action;
      button.dataset.reviewId = reviewId;
      button.textContent = label;
      buttons.append(button);
    });
    return true;
  };

  const showEmptyList = () => {
    if (!reviewList.querySelector('.review-admin-row') && !reviewList.querySelector('.reviews-empty')) {
      const empty = document.createElement('p');
      empty.className = 'reviews-empty';
      empty.textContent = 'No reviews in this view.';
      reviewList.append(empty);
    }
  };

  reviewList.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-review-action]');
    if (!button || !reviewList.contains(button)) return;
    const row = button.closest('[data-review-id]');
    const action = button.dataset.reviewAction;
    const reviewId = row?.dataset.reviewId;
    if (!row || !reviewId || row.dataset.moderationPending === 'true') return;

    const rowButtons = [...row.querySelectorAll('[data-review-action]')];
    row.dataset.moderationPending = 'true';
    rowButtons.forEach((actionButton) => { actionButton.disabled = true; });
    showMessage(row, 'Saving review decision…');

    try {
      const response = await fetch('actions/admin/moderate_venue_review.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ review_id: reviewId, action })
      });
      const body = await response.text();
      let data = null;
      try {
        data = body ? JSON.parse(body) : null;
      } catch {
        data = null;
      }

      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        const message = response.ok
          ? 'The server response was invalid, so the review status could not be confirmed. Reload to check its current status.'
          : `Review update failed (HTTP ${response.status}).`;
        throw new Error(message);
      }
      if (!response.ok || data.success !== true) {
        const serverMessage = typeof data?.message === 'string' ? data.message : '';
        throw new Error(serverMessage || `Review update failed (HTTP ${response.status}).`);
      }

      const expectedStatus = action === 'approve' ? 'Approved' : 'Rejected';
      if (data.status !== expectedStatus) {
        showMessage(row, 'The server response did not confirm the expected status. Reload to check the saved decision.', true);
        window.location.reload();
        return;
      }

      if (!updateReviewState(row, expectedStatus)) {
        showMessage(row, 'Review decision saved. Refreshing its current status…');
        window.location.reload();
        return;
      }
      if (filter?.value && filter.value !== 'All' && filter.value !== data.status) {
        row.remove();
        showEmptyList();
        return;
      }
      showMessage(row, data.duplicate ? 'This review was already updated. Current status refreshed.' : data.message || 'Review status updated.');
    } catch (error) {
      rowButtons.forEach((actionButton) => { actionButton.disabled = false; });
      const message = error instanceof TypeError
        ? 'The request could not be confirmed. You can retry safely or reload to check the current status.'
        : error.message || 'The review could not be updated. Please try again.';
      showMessage(row, message, true);
      if (typeof window.showAlert === 'function') window.showAlert('Review update failed', message, 'error');
    } finally {
      delete row.dataset.moderationPending;
    }
  });
});
