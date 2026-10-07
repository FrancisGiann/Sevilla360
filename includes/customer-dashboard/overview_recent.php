                    <div id="customer-overview-recent" class="overview-lower-grid overview-recent-only">
                        <section class="overview-card recent-bookings-card" aria-labelledby="recent-bookings-title">
                            <div class="overview-card-heading"><div><h2 id="recent-bookings-title">Recent bookings</h2></div><a href="user_dashboard.php?section=bookings" data-dashboard-section="bookings">View all</a></div>
                            <?php if (empty($overview_recent)): ?>
                            <div class="overview-empty-state compact"><i class="fa-regular fa-calendar-xmark"></i><p>No bookings yet. Use Book a Venue above to start your first reservation.</p></div>
                            <?php else: ?>
                            <div class="recent-bookings-list">
                                <?php foreach ($overview_recent as $recent): [$recent_status_text, $recent_status_class] = $dashboard_status($recent); ?>
                                <article class="recent-booking-row" id="overview-booking-<?php echo (int)$recent['id']; ?>">
                                    <div><strong><?php echo htmlspecialchars($recent['venue_name']); ?></strong><small><?php echo htmlspecialchars($format_dashboard_date($recent['start_date'], $recent['end_date'])); ?></small></div>
                                    <span class="badge <?php echo $recent_status_class; ?>"><?php echo htmlspecialchars($recent_status_text); ?></span>
                                    <button type="button" class="btn-icon-link btn-details" data-id="<?php echo (int)$recent['id']; ?>" aria-label="View details for <?php echo htmlspecialchars($recent['venue_name']); ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>
                                </article>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </section>

                    </div>
