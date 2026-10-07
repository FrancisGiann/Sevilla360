                    <div id="customer-overview-main" class="overview-main-grid<?php echo empty($attention_items) ? ' overview-main-grid-single' : ''; ?>">
                        <section class="overview-card next-booking-card" aria-labelledby="next-booking-title">
                            <div class="overview-card-heading">
                                <div>
                                    <h2 id="next-booking-title">Your upcoming stay</h2>
                                </div>
                                <?php if ($upcoming_booking) { [$next_status_text, $next_status_class] = $dashboard_status($upcoming_booking); } ?>
                                <span class="badge <?php echo $upcoming_booking ? $next_status_class : 'badge-pending'; ?>"><?php echo htmlspecialchars($upcoming_booking ? $next_status_text : 'None yet'); ?></span>
                            </div>
                            <?php if ($upcoming_booking): ?>
                            <div class="next-booking-details">
                                <strong><?php echo htmlspecialchars($upcoming_booking['venue_name']); ?></strong>
                                <span><i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars($format_dashboard_date($upcoming_booking['start_date'], $upcoming_booking['end_date'])); ?></span>
                                <span><i class="fa-solid fa-receipt"></i> <?php echo $upcoming_booking['total_amount'] > 0 ? '₱' . number_format((float)$upcoming_booking['total_amount'], 2) : 'Amount to be arranged'; ?> · <?php echo htmlspecialchars($upcoming_booking['payment_status']); ?></span>
                            </div>
                            <div class="overview-card-actions">
                                <button type="button" class="btn-outline-dash btn-details" data-id="<?php echo (int)$upcoming_booking['id']; ?>"><i class="fa-solid fa-file-invoice"></i> View details</button>
                                <?php if ($can_submit_manual_payment($upcoming_booking)): ?>
                                <button type="button" class="btn-primary-dash btn-submit-payment" data-id="<?php echo (int)$upcoming_booking['id']; ?>"><?php echo htmlspecialchars($manual_payment_action_label($upcoming_booking), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="overview-empty-state"><i class="fa-regular fa-calendar"></i><p>No upcoming booking yet. Your next reservation will appear here.</p></div>
                            <?php endif; ?>
                        </section>

                        <?php if (!empty($attention_items)): ?><section class="overview-card attention-card" aria-labelledby="attention-title">
                            <div class="overview-card-heading">
                                <div>
                                    <h2 id="attention-title">Needs attention</h2>
                                </div>
                            </div>
                            <ul class="attention-list">
                                <?php foreach ($attention_items as $attention): [$attention_text, $attention_class] = $dashboard_status($attention); ?>
                                <li>
                                    <div><span class="badge <?php echo $attention_class; ?>"><?php echo htmlspecialchars($attention_text); ?></span><strong><?php echo htmlspecialchars($attention['venue_name']); ?></strong><small><?php echo htmlspecialchars($format_dashboard_date($attention['start_date'], $attention['end_date'])); ?></small></div>
                                    <a href="user_dashboard.php?section=bookings#booking-<?php echo (int)$attention['id']; ?>" data-dashboard-section="bookings" aria-label="Open booking <?php echo htmlspecialchars($attention['reference_no'] ?: (string)$attention['id']); ?>">View</a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                        <?php endif; ?>
                    </div>
