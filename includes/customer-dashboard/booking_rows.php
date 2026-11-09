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
                                        $isCheckoutUnavailable = $display_status === 'Cancelled' && !empty($b['checkout_unavailable_at']);
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
                                        $quote_review_action = !$payment_action && $can_review_event_quote($b);
                                        $checkout_recovery_action = $localPayMongo && !empty($b['payment_attempt_id'])
                                            && in_array($b['payment_attempt_status'] ?? '', ['creating', 'open', 'uncertain', 'review'], true)
                                            && in_array($b['payment_status'], ['Unpaid', 'Partial'], true);
                                        $checkout_cancel_action = $localPayMongo && !empty($b['payment_attempt_id'])
                                            && in_array($b['payment_attempt_status'] ?? '', ['open', 'uncertain', 'expired'], true)
                                            && in_array($b['payment_status'], ['Unpaid', 'Partial'], true);
                                        $can_cancel_booking = !$is_completed && $display_status !== 'Cancelled' && $b['cancel_status'] !== 'Pending'
                                            && (!$localPayMongo || empty($b['payment_attempt_id']));
                                        $can_reschedule_booking = $can_cancel_booking && $display_status === 'Confirmed';
                                        $can_review_booking = $is_completed && $b['booking_status'] !== 'Cancelled' && $b['payment_status'] !== 'Refunded';
                                        $has_more_actions = $payment_action || $checkout_recovery_action || $can_reschedule_booking || $can_cancel_booking || $can_review_booking;
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
                                            class="<?php echo ($display_status === 'Cancelled' && !$isCheckoutUnavailable) ? 'text-muted' : ''; ?>">
                                            <?php echo $display_amount; ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php
                                            $paymentTimerRemaining = null;
                                            $paymentTimerIsEventHall = $b['venue_type'] === 'Event Hall';
                                            $paymentTimerIsCheckoutHold = false;
                                            $paymentTimerIsEventQuote = false;
                                            $paymentTimerIsPayMongo = defined('SEVILLA_PM_FULL_APP') && SEVILLA_PM_FULL_APP;
                                            $paymentTimerEligible = !$is_completed && $display_status !== 'Cancelled'
                                                && ($paymentTimerIsPayMongo
                                                    ? ($paymentTimerIsEventHall
                                                        ? $b['payment_status'] === 'Unpaid'
                                                        : in_array($b['payment_status'], ['Unpaid', 'Partial'], true))
                                                    : ($amount_paid == 0 && $b['payment_status'] === 'Unpaid'
                                                        && $paymentTimerIsEventHall && $display_status === 'Confirmed' && empty($b['manual_payment_pending'])));
                                            $checkoutHoldDeadline = $b['payment_step_expires_at'] ?: ($b['payment_attempt_expires_at'] ?? null);
                                            if ($paymentTimerEligible && $paymentTimerIsPayMongo) {
                                                $hasActiveCheckout = in_array($b['payment_attempt_status'] ?? '', ['creating', 'open', 'uncertain', 'review'], true);
                                                if ($paymentTimerIsEventHall && !$hasActiveCheckout) {
                                                    $paymentTimerDeadline = $b['payment_due_at'] ?? null;
                                                    $paymentTimerIsEventQuote = !empty($paymentTimerDeadline);
                                                } elseif ($checkoutHoldDeadline) {
                                                    $paymentTimerDeadline = $checkoutHoldDeadline;
                                                    $paymentTimerIsCheckoutHold = true;
                                                } else {
                                                    $paymentTimerDeadline = $paymentTimerIsEventHall && $b['payment_status'] === 'Unpaid' ? ($b['payment_due_at'] ?? null) : null;
                                                    $paymentTimerIsEventQuote = $paymentTimerIsEventHall && !empty($paymentTimerDeadline);
                                                }
                                            } else {
                                                $paymentTimerDeadline = $paymentTimerEligible ? ($b['payment_due_at'] ?? null) : null;
                                                $paymentTimerIsEventQuote = $paymentTimerIsEventHall && !empty($paymentTimerDeadline);
                                            }
                                            if ($paymentTimerDeadline) {
                                                $timerZone = new DateTimeZone('Asia/Manila');
                                                $paymentTimerRemaining = max(0, (new DateTimeImmutable($paymentTimerDeadline, $timerZone))->getTimestamp() - (new DateTimeImmutable($payment_timer_now, $timerZone))->getTimestamp());
                                            }
                                            $paymentTimerHours = $paymentTimerRemaining === null ? 0 : intdiv($paymentTimerRemaining, 3600);
                                            $paymentTimerDisplay = $paymentTimerHours < 100
                                                ? sprintf('%02d:%02d:%02d', $paymentTimerHours, intdiv(($paymentTimerRemaining ?? 0) % 3600, 60), ($paymentTimerRemaining ?? 0) % 60)
                                                : sprintf('%d:%02d:%02d:%02d', intdiv($paymentTimerHours, 24), $paymentTimerHours % 24, intdiv(($paymentTimerRemaining ?? 0) % 3600, 60), ($paymentTimerRemaining ?? 0) % 60);
                                            ?>
                                            <div class="booking-status-details">
                                            <?php if ($paymentTimerRemaining !== null): ?>
                                            <div class="booking-payment-timer<?php echo ($paymentTimerRemaining > 0 && $paymentTimerRemaining <= 60) ? ' is-urgent' : ($paymentTimerRemaining === 0 ? ' is-expired' : ''); ?>" data-payment-seconds="<?php echo (int)$paymentTimerRemaining; ?>" data-payment-title="<?php echo htmlspecialchars((string)$status_text, ENT_QUOTES, 'UTF-8'); ?>" data-payment-event-quote="<?php echo $paymentTimerIsEventQuote ? '1' : '0'; ?>" data-payment-event-checkout="<?php echo $paymentTimerIsEventHall && $paymentTimerIsCheckoutHold ? '1' : '0'; ?>" data-payment-checkout-hold="<?php echo $paymentTimerIsCheckoutHold ? '1' : '0'; ?>" role="group" aria-label="Payment deadline for <?php echo htmlspecialchars((string)$status_text, ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="booking-payment-timer__label" data-payment-label><?php echo $paymentTimerIsEventQuote ? 'Event Hall payment deadline' : ($paymentTimerIsCheckoutHold ? 'Checkout hold' : htmlspecialchars((string)$status_text, ENT_QUOTES, 'UTF-8')); ?></span>
                                                <span class="booking-payment-timer__value" data-payment-countdown role="timer" aria-live="off" aria-atomic="true"><?php echo $paymentTimerDisplay . ($paymentTimerRemaining > 0 ? ' remaining' : ''); ?></span>
                                                <span class="booking-payment-timer__note" data-payment-note><?php echo $paymentTimerIsEventQuote
                                                    ? ((defined('SEVILLA_PM_FULL_APP') && SEVILLA_PM_FULL_APP)
                                                        ? 'Pay within 24 hours after quote finalization. Unpaid bookings are cancelled automatically; uncertain payments are verified first.'
                                                        : 'Pay within 24 hours after quote finalization. A proof under review pauses the timer; unpaid bookings are cancelled automatically after the deadline.')
                                                    : ($paymentTimerRemaining > 0 ? 'If time runs out, we’ll verify payment before releasing this hold.' : 'Checking payment before releasing this hold.'); ?></span>
                                            </div>
                                            <?php else: ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($isCheckoutUnavailable): ?>
                                            <small class="booking-unavailable-note">These dates were unavailable when checkout started. This booking is closed and can’t be paid. Choose different dates or check availability again.</small>
                                            <?php endif; ?>
                                            <?php if (!$is_completed && !empty($b['has_rescheduled']) && $display_status === 'Confirmed' && $b['cancel_status'] !== 'Pending'): ?>
                                            <span class="badge badge-reschedule">Rescheduled &amp; Confirmed</span>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cell<?php echo $has_more_actions ? ' has-more-actions' : ''; ?>">
                                            <?php if ($checkout_recovery_action): ?>
                                                <button type="button" class="btn-action btn-outline-action booking-row-primary btn-check-payment-status"
                                                    data-id="<?php echo (int)$b['id']; ?>"
                                                    data-attempt="<?php echo htmlspecialchars((string)$b['payment_attempt_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Check the existing checkout before trying another payment">Check payment status</button>
                                            <?php endif; ?>
                                            <?php if (!$is_completed && $can_submit_manual_payment($b)): ?>
                                                <button type="button" class="btn-action btn-pay btn-submit-payment booking-row-primary"
                                                    data-id="<?php echo (int)$b['id']; ?>"
                                                    data-policy-version="<?php echo htmlspecialchars((string)($b['policy_version'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($manual_payment_action_label($b), ENT_QUOTES, 'UTF-8'); ?></button>
                                                <?php endif; ?>
                                                <?php if (!$payment_action && !$checkout_recovery_action): ?>
                                                <button type="button" class="btn-action btn-outline-action btn-details booking-row-primary"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-date="<?php echo $date_str; ?>"
                                                    data-paid="<?php echo $amount_paid; ?>"
                                                    data-status="<?php echo $status_text; ?>"
                                                    title="<?php echo $quote_review_action ? 'Review Event Hall quotation' : 'View Booking Invoice'; ?>">
                                                    <i class="fa-solid fa-file-invoice"></i>
                                                    <span class="vd-text"><?php echo $quote_review_action ? 'Review quote' : 'View Details'; ?></span>
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
                                                        <?php if ($payment_action || $checkout_recovery_action): ?>
                                                        <button type="button" class="btn-details action-menu-item" data-id="<?php echo (int)$b['id']; ?>"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i><span>View details</span></button>
                                                        <?php endif; ?>
                                                        <?php if ($checkout_cancel_action): ?>
                                                        <button type="button" class="btn-cancel-checkout action-menu-item action-menu-item--destructive"
                                                            data-id="<?php echo (int)$b['id']; ?>"
                                                            data-attempt="<?php echo htmlspecialchars((string)$b['payment_attempt_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <i class="fa-solid fa-ban" aria-hidden="true"></i><span>Cancel checkout</span>
                                                        </button>
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
                                                            data-paid="<?php echo htmlspecialchars((string)$amount_paid, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-paymongo="<?php echo !empty($b['paymongo_paid']) ? '1' : '0'; ?>">
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
