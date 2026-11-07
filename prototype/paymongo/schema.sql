-- Disposable synthetic database only. Never apply this schema to an application DB.
CREATE TABLE venues (id INT PRIMARY KEY, name VARCHAR(100), category VARCHAR(40), status VARCHAR(40)) ENGINE=InnoDB;
CREATE TABLE bookings (
 id INT PRIMARY KEY, customer_id INT NOT NULL, reference_no VARCHAR(40), venue_id INT,
 start_date DATE, end_date DATE, total_amount DECIMAL(12,2), amount_paid DECIMAL(12,2) DEFAULT 0,
 payment_scheme VARCHAR(30), booking_status VARCHAR(30) DEFAULT 'Awaiting Proof',
 payment_status VARCHAR(30) DEFAULT 'Unpaid', source VARCHAR(20) DEFAULT 'Online',
 quote_version INT DEFAULT 0, quote_accepted TINYINT DEFAULT 0,
 payment_due_at DATETIME, payment_step_token CHAR(32), payment_step_expires_at DATETIME,
 payment_step_seen_at DATETIME, proof_first_submitted_at DATETIME,
 pending_change TINYINT DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE booking_rooms (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, venue_id INT, start_date DATE, end_date DATE) ENGINE=InnoDB;
CREATE TABLE booking_locks (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, session_id VARCHAR(100), source VARCHAR(30), start_date DATE, end_date DATE, expires_at DATETIME, INDEX(venue_id)) ENGINE=InnoDB;
CREATE TABLE maintenance (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, is_blocking INT, status VARCHAR(30), start_date DATE, end_date DATE) ENGINE=InnoDB;
CREATE TABLE seminars (id INT PRIMARY KEY, status VARCHAR(30)) ENGINE=InnoDB;
CREATE TABLE seminar_reservations (id INT AUTO_INCREMENT PRIMARY KEY, seminar_id INT, venue_id INT, resource_kind VARCHAR(20), start_date DATE, end_date DATE) ENGINE=InnoDB;
CREATE TABLE payment_step_exits (token CHAR(32) PRIMARY KEY, booking_id INT, exited_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE pm_attempts (
 id CHAR(32) PRIMARY KEY, booking_id INT NOT NULL, snapshot CHAR(64) NOT NULL,
 amount_cents BIGINT NOT NULL, base_paid_cents BIGINT NOT NULL, hold_token CHAR(32),
 expires_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'creating',
 provider_session_id VARCHAR(100) UNIQUE, checkout_url VARCHAR(2048), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(booking_id)
) ENGINE=InnoDB;
CREATE TABLE pm_events (id VARCHAR(100) PRIMARY KEY, digest CHAR(64) NOT NULL, outcome VARCHAR(80), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB;
CREATE TABLE pm_receipts (
 payment_id VARCHAR(100) PRIMARY KEY, attempt_id CHAR(32) NOT NULL,
 amount_cents BIGINT NOT NULL, currency VARCHAR(8) NOT NULL,
 disposition VARCHAR(80) NOT NULL, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(attempt_id)
) ENGINE=InnoDB;
-- Separate authoritative fake provider ledger: only the simulator writes here.
CREATE TABLE pm_sim_sessions (id VARCHAR(100) PRIMARY KEY, reference_no CHAR(32) UNIQUE NOT NULL, resource JSON NOT NULL) ENGINE=InnoDB;
INSERT INTO venues VALUES (1,'Garden room','Hotel Room','Available'),(2,'Pool villa','Resort Villa','Available'),(3,'Infinity hall','Event Hall','Available'),(4,'Hall guest room','Hotel Room','Available');
INSERT INTO bookings (id,customer_id,reference_no,venue_id,start_date,end_date,total_amount,payment_scheme,payment_due_at,quote_version,quote_accepted) VALUES
 (1,1,'DEMO-ROOM',1,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 32 DAY),6000,'50% Downpayment',DATE_ADD(NOW(),INTERVAL 1 DAY),0,0),
 (2,1,'DEMO-VILLA',2,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 31 DAY),12000,'20% Reservation',DATE_ADD(NOW(),INTERVAL 1 DAY),0,0),
 (3,1,'DEMO-QUOTED-HALL',3,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY),25000,'50% Downpayment',DATE_ADD(NOW(),INTERVAL 1 DAY),1,1),
 (4,1,'DEMO-INQUIRY',3,DATE_ADD(CURDATE(),INTERVAL 40 DAY),DATE_ADD(CURDATE(),INTERVAL 40 DAY),0,'50% Downpayment',DATE_ADD(NOW(),INTERVAL 1 DAY),0,0),
 (5,2,'DEMO-COMPETITOR',1,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 32 DAY),6000,'100% Full',DATE_ADD(NOW(),INTERVAL 1 DAY),0,0);
UPDATE bookings SET booking_status='Pending' WHERE id=4;
INSERT INTO booking_rooms (booking_id,venue_id,start_date,end_date) SELECT 3,4,start_date,DATE_ADD(end_date,INTERVAL 1 DAY) FROM bookings WHERE id=3;
CREATE TABLE pm_transport_log (id INT AUTO_INCREMENT PRIMARY KEY, method VARCHAR(8), path VARCHAR(200)) ENGINE=InnoDB;
