                        <?php if ($booking_pages > 1): ?>
                        <nav class="booking-pagination" aria-label="Booking history pages">
                            <span class="pagination-summary">
                                Showing <?php echo (($booking_page - 1) * $booking_limit) + 1; ?>-<?php echo min($booking_page * $booking_limit, $stat_total); ?> of <?php echo $stat_total; ?> bookings
                            </span>
                            <div class="pagination-controls">
                                <?php if ($booking_page > 1): ?>
                                <a class="pagination-link" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $booking_page - 1; ?>" aria-label="Previous page">&larr; Previous</a>
                                <?php endif; ?>
                                <?php for ($page_number = 1; $page_number <= $booking_pages; $page_number++): ?>
                                <a class="pagination-link <?php echo $page_number === $booking_page ? 'active' : ''; ?>" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $page_number; ?>" aria-current="<?php echo $page_number === $booking_page ? 'page' : 'false'; ?>"><?php echo $page_number; ?></a>
                                <?php endfor; ?>
                                <?php if ($booking_page < $booking_pages): ?>
                                <a class="pagination-link" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $booking_page + 1; ?>" aria-label="Next page">Next &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </nav>
                        <?php endif; ?>
