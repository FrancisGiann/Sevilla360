CREATE TABLE IF NOT EXISTS seminars (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL,
    status ENUM('draft','finalized','cancelled') NOT NULL DEFAULT 'draft',
    hall_venue_id INT NOT NULL,
    hall_start_date DATE NOT NULL,
    hall_end_date DATE NOT NULL,
    hotel_check_in DATE NOT NULL,
    hotel_check_out DATE NOT NULL,
    created_by INT NOT NULL,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_seminars_status_dates (status, hall_start_date, hall_end_date),
    CONSTRAINT fk_seminars_hall FOREIGN KEY (hall_venue_id) REFERENCES venues(id) ON DELETE RESTRICT,
    CONSTRAINT fk_seminars_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seminar_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seminar_id BIGINT UNSIGNED NOT NULL,
    venue_id INT NOT NULL,
    resource_kind ENUM('hall','room') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    floor_label VARCHAR(80) NULL,
    allow_mixed_gender TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seminar_resource (seminar_id, venue_id),
    KEY idx_seminar_reservation_venue_dates (venue_id, start_date, end_date),
    CONSTRAINT fk_seminar_reservation_seminar FOREIGN KEY (seminar_id) REFERENCES seminars(id) ON DELETE CASCADE,
    CONSTRAINT fk_seminar_reservation_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seminar_attendees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seminar_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    gender VARCHAR(24) NOT NULL DEFAULT 'unknown',
    location VARCHAR(180) NOT NULL,
    contact VARCHAR(100) NULL,
    assigned_venue_id INT NULL,
    solo_flag TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_seminar_attendee_roster (seminar_id, full_name),
    KEY idx_seminar_attendee_room (seminar_id, assigned_venue_id),
    CONSTRAINT fk_seminar_attendee_seminar FOREIGN KEY (seminar_id) REFERENCES seminars(id) ON DELETE CASCADE,
    CONSTRAINT fk_seminar_attendee_room FOREIGN KEY (assigned_venue_id) REFERENCES venues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
