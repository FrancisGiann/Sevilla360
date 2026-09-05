document.addEventListener('DOMContentLoaded', () => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  document.getElementById('review-status-filter')?.addEventListener('change', (event) => {
    const status = event.target.value;
    window.location.href = `admin_dashboard.php?page=reviews&status=${encodeURIComponent(status)}`;
  });
  document.querySelectorAll('[data-review-action]').forEach((button) => {
    button.addEventListener('click', async () => {
      const action = button.dataset.reviewAction;
      const note = button.closest('[data-review-id]')?.querySelector('[data-review-note]')?.value || '';
      button.disabled = true;
      try {
        const response = await fetch('actions/admin/moderate_venue_review.php', {
          method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
          body: JSON.stringify({ review_id: button.dataset.reviewId, action, admin_note: note })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Moderation failed.');
        window.location.reload();
      } catch (error) {
        button.disabled = false;
        if (typeof window.showAlert === 'function') window.showAlert('Review update failed', error.message, 'error');
      }
    });
  });
});
