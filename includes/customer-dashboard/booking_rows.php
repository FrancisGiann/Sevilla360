                                    <?php if (empty($bookings)): ?>
                                    <tr>
                                        <td colspan="6" class="empty-table-cell">You don’t have any bookings yet. Select “New Booking” above to explore venues and start a reservation.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($bookings as $b):
                                        $start = new DateTime($b['start_date']);
                                        $end = new DateTime($b['end_date']);
                                        $date_str = ($b['start_date'] === $b['end_date']) ? $start->format('M j, Y') : $start->format('M j') . ' - ' . $end->format('M j, Y');
                                        $display_status = $b['display_booking_status'] ?? $b['booking_status'];
                                        $is_completed = ($display_status === 'Completed');

                                        $total_amt = floatval($b['total_amount']);
                                        $amount_paid = floatval($b['amount_paid']);
                                        $actual_room_type = ($b['venue_type'] === 'Hotel Room') ? $b['hotel_room_type'] : $b['venue_type'];
                                        $is_pending_inquiry = ($b['venue_type'] === 'Event Hall' && $display_status === 'Pending');

                                        $display_amount = '₱' . number_format($total_amt, 2);
                                        if ($is_pending_inquiry) {
                                            $display_amount = '<span class="text-tba">To Be Arranged</span>';
                                        }

                                        [$status_text, $badge_class] = $dashboard_status($b);
                                        // Preserve the existing filter buckets based on lifecycle/payment state.
                                        $filter_data = 'Pending';
                                        if ($is_completed) {
                                            $filter_data = 'Completed';
                                        } elseif ($display_status === 'Cancelled') {
                                            $filter_data = 'Cancelled';
                                        } elseif ($display_status === 'Confirmed' && $b['payment_status'] === 'Paid') {
                                            $filter_data = 'Paid';
                                        } elseif ($display_status === 'Confirmed' && $b['payment_status'] === 'Partial') {
                                            $filter_data = 'Partially Paid';
                                        }

                                        $raw_booking_reference = !empty($b['reference_no']) ? (string)$b['reference_no'] : '#' . (int)$b['id'];
                                        $display_id = htmlspecialchars($raw_booking_reference, ENT_QUOTES, 'UTF-8');
                                        $booking_review = $reviewsByBooking[(int)$b['id']] ?? null;
                                        $payment_action = !$is_completed && $can_submit_manual_payment($b);
                                        $can_cancel_booking = !$is_completed && $display_status !== 'Cancelled' && $b['cancel_status'] !== 'Pending';
                                        $can_reschedule_booking = $can_cancel_booking && $display_status === 'Confirmed';
                                        $can_review_booking = $is_completed && $b['booking_status'] !== 'Cancelled' && $b['payment_status'] !== 'Refunded';
                                        $has_more_actions = $payment_action || $can_reschedule_booking || $can_cancel_booking || $can_review_booking;
                                        $action_menu_id = 'booking-actions-' . (int)$b['id'];
                                    ?>
                                    <tr id="booking-<?php echo (int)$b['id']; ?>" data-status="<?php echo htmlspecialchars($filter_data, ENT_QUOTES, 'UTF-8'); ?>">

                                        <td class="booking-ref-id" data-label="Booking ID">
                                            <?php echo $display_id; ?>
                                        </td>
                                        <td data-label="Venue"><?php echo htmlspecialchars($b['venue_name']); ?></td>
                                        <td data-label="Date"><?php echo $date_str; ?></td>
                                        <td
                                            data-label="Amount"
                                            class="<?php echo ($display_status === 'Cancelled') ? 'text-muted' : ''; ?>">
                                            <?php echo $display_amount; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                            <?php if (!$is_completed && !empty($b['has_rescheduled']) && $display_status === 'Confirmed' && $b['cancel_status'] !== 'Pending'): ?>
                                            <span class="badge badge-reschedule">Rescheduled &amp; Confirmed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cell<?php echo $has_more_actions ? ' has-more-actions' : ''; ?>">
                                            <?php if (!$is_completed && $can_submit_manual_payment($b)): ?>
                                                <button type="button" class="btn-action btn-pay btn-submit-payment booking-row-primary"
                                                    data-id="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($manual_payment_action_label($b), ENT_QUOTES, 'UTF-8'); ?></button>
                                                <?php endif; ?>
                                                <?php if (!$payment_action): ?>
                                                <button type="button" class="btn-action btn-outline-action btn-details booking-row-primary"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-date="<?php echo $date_str; ?>"
                                                    data-paid="<?php echo $amount_paid; ?>"
                                                    data-status="<?php echo $status_text; ?>"
                                                    title="View Booking Invoice">
                                                    <i class="fa-solid fa-file-invoice"></i>
                                                    <span class="vd-text">View Details</span>
                                                </button>
                                                <?php endif; ?>

                                                <?php if ($has_more_actions): ?>
                                                <div class="booking-row-more">
                                                    <button type="button" class="btn-action booking-more-toggle"
                                                        aria-label="More actions for booking <?php echo htmlspecialchars($raw_booking_reference, ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="More actions for booking <?php echo htmlspecialchars($raw_booking_reference, ENT_QUOTES, 'UTF-8'); ?>"
                                                        aria-expanded="false" aria-controls="<?php echo htmlspecialchars($action_menu_id, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                    </button>
                                                    <div class="booking-action-menu-panel" id="<?php echo htmlspecialchars($action_menu_id, ENT_QUOTES, 'UTF-8'); ?>" role="group" aria-label="More actions for booking <?php echo htmlspecialchars($raw_booking_reference, ENT_QUOTES, 'UTF-8'); ?>" hidden>
                                                        <?php if ($payment_action): ?>
                                                        <button type="button" class="btn-details action-menu-item" data-id="<?php echo (int)$b['id']; ?>"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i><span>View details</span></button>
                                                        <?php endif; ?>
                                                        <?php if ($can_reschedule_booking): ?>
                                                        <button type="button" class="btn-reschedule action-menu-item"
                                                            data-id="<?php echo (int)$b['id']; ?>"
                                                            data-venue="<?php echo htmlspecialchars($b['venue_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-type="<?php echo htmlspecialchars($actual_room_type, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-start="<?php echo htmlspecialchars($b['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-end="<?php echo htmlspecialchars($b['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-date="<?php echo htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>Reschedule</span></button>
                                                        <?php endif; ?>
                                                        <?php if ($can_review_booking && !$booking_review): ?>
                                                        <button type="button" class="btn-review btn-review-open action-menu-item" data-id="<?php echo (int)$b['id']; ?>" data-venue="<?php echo htmlspecialchars($b['venue_name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-star" aria-hidden="true"></i><span>Rate venue</span></button>
                                                        <?php elseif ($can_review_booking && $booking_review['moderation_status'] === 'Pending'): ?>
                                                        <button type="button" class="btn-review-open action-menu-item" data-id="<?php echo (int)$b['id']; ?>" data-venue="<?php echo htmlspecialchars($b['venue_name'], ENT_QUOTES, 'UTF-8'); ?>" data-rating="<?php echo (int)$booking_review['rating']; ?>" data-review="<?php echo htmlspecialchars((string)($booking_review['review_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i><span>Review pending</span></button>
                                                        <?php elseif ($can_review_booking): ?>
                                                        <button type="button" class="btn-review-open action-menu-item" data-id="<?php echo (int)$b['id']; ?>" data-venue="<?php echo htmlspecialchars($b['venue_name'], ENT_QUOTES, 'UTF-8'); ?>" data-rating="<?php echo (int)$booking_review['rating']; ?>" data-review="<?php echo htmlspecialchars((string)($booking_review['review_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span>View/edit review</span></button>
                                                        <?php endif; ?>
                                                        <?php if ($can_cancel_booking): ?>
                                                        <button type="button" class="btn-cancel action-menu-item action-menu-item--destructive"
                                                            data-id="<?php echo (int)$b['id']; ?>"
                                                            data-venue="<?php echo htmlspecialchars($b['venue_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-date="<?php echo htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-paid="<?php echo htmlspecialchars((string)$amount_paid, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <i class="fa-solid <?php echo ($amount_paid > 0) ? 'fa-arrow-rotate-left' : 'fa-ban'; ?>" aria-hidden="true"></i>
                                                            <span><?php echo ($amount_paid > 0) ? 'Request refund' : 'Cancel booking'; ?></span>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
