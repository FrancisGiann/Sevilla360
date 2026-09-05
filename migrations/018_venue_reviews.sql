-- Customer venue reviews.  One review belongs to one canonical completed
-- booking and can be moderated without rewriting or deleting the record.
CREATE TABLE IF NOT EXISTS venue_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    customer_id INT NOT NULL,
    venue_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text VARCHAR(1000) NULL,
    moderation_status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    admin_note VARCHAR(500) NULL,
    moderated_by INT NULL,
    moderated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venue_reviews_booking (booking_id),
    KEY idx_venue_reviews_venue_status (venue_id, moderation_status, created_at),
    KEY idx_venue_reviews_customer (customer_id, created_at),
    CONSTRAINT fk_venue_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),
    CONSTRAINT fk_venue_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_venue_reviews_venue FOREIGN KEY (venue_id) REFERENCES venues(id),
    CONSTRAINT fk_venue_reviews_moderator FOREIGN KEY (moderated_by) REFERENCES users(id),
    CONSTRAINT chk_venue_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT chk_venue_reviews_text CHECK (review_text IS NULL OR CHAR_LENGTH(review_text) <= 1000),
    CONSTRAINT chk_venue_reviews_admin_note CHECK (admin_note IS NULL OR CHAR_LENGTH(admin_note) <= 500)
) ENGINE=InnoDB;
