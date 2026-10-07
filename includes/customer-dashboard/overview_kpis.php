                    <section id="customer-overview-kpis" class="dashboard-summary-grid overview-kpis" aria-label="Booking overview">
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Upcoming</span>
                            <strong><?php echo $upcoming_count; ?></strong>
                            <small><?php echo $upcoming_count ? 'Confirmed booking' . ($upcoming_count === 1 ? '' : 's') . ' ahead' : 'No upcoming bookings'; ?></small>
                        </div>
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Outstanding balance</span>
                            <strong>₱<?php echo number_format($balance_due, 2); ?></strong>
                            <small><?php echo $balance_due > 0 ? 'Payment action may be needed' : 'You are all caught up'; ?></small>
                            <?php if ($balance_due > 0): ?>
                            <a class="balance-review-link" href="user_dashboard.php?section=bookings" data-dashboard-section="bookings">Review bookings</a>
                            <?php endif; ?>
                        </div>
                    </section>
