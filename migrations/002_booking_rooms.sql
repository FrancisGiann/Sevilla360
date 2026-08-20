-- ============================================================
-- Migration 002: Create booking_rooms table
-- Tracks individual physical room allocations per booking.
-- Enables real inventory management for hotel room add-ons.
-- ============================================================

CREATE TABLE IF NOT EXISTS booking_rooms (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT NOT NULL,
    venue_id     INT NOT NULL,            -- specific physical room (venues.id)
    nightly_rate DECIMAL(10,2) NOT NULL,  -- rate at time of booking (server-calculated)
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    nights       INT NOT NULL,
    line_total   DECIMAL(10,2) NOT NULL,  -- nightly_rate * nights (server-calculated)
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id)   REFERENCES venues(id)
) ENGINE=InnoDB;
